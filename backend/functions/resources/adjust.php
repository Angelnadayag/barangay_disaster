<?php
// ============================================================================
// Function: Resources - Adjust Inventory
// Supports both ICDRRMO (Central Depot) and Barangay Head (Local Stockroom)
// Fully Dynamic with JSON Response for AJAX
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (
    $role === 'barangay_head' ? BASE_URL . '/views/barangay/inventory.php' : BASE_URL . '/views/icdrrmo/inventory.php'
));

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized action.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$resId = (int)($_POST['resource_id'] ?? 0);
$type = trim($_POST['transaction_type'] ?? 'Adjusted');
$qty = (int)($_POST['quantity'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');

// Map common terms to valid enum ('Restock','Allocation','Dispatched','Returned','Damaged','Adjusted','New Product')
if ($type === 'Distributed' || $type === 'Issued') {
    $type = 'Dispatched';
}

$validTypes = ['Restock', 'Allocation', 'Dispatched', 'Returned', 'Damaged', 'Adjusted', 'New Product'];
if (!in_array($type, $validTypes, true)) {
    $type = 'Adjusted';
}

if (!$resId || $qty <= 0) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid resource or quantity.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid resource or quantity.');
}

// Check resource existence and jurisdiction
$chkStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
$chkStmt->execute([$resId]);
$resource = $chkStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource not found.');
}

if ($role === 'barangay_head' && (int)$resource['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This item does not belong to your barangay stockroom.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify resources outside your barangay.');
}

// Validate available stock if deducting
if (in_array($type, ['Damaged', 'Dispatched'], true)) {
    if ($qty > (int)$resource['available_quantity']) {
        $msg = "Insufficient available stock. Current available: {$resource['available_quantity']} {$resource['unit']}.";
        if ($isAjax) jsonResponse(['success' => false, 'message' => $msg], 400);
        redirectWithFlash($returnUrl, 'error', $msg);
    }
}

$db->beginTransaction();
try {
    if ($type === 'New Product' || $type === 'Restock') {
        $stmt = $db->prepare("
            UPDATE resources SET
                total_quantity = total_quantity + ?,
                available_quantity = available_quantity + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$qty, $qty, $resId]);
    } elseif ($type === 'Damaged') {
        $stmt = $db->prepare("
            UPDATE resources SET
                available_quantity = GREATEST(0, available_quantity - ?),
                damaged_quantity = damaged_quantity + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$qty, $qty, $resId]);
    } elseif ($type === 'Returned') {
        $stmt = $db->prepare("
            UPDATE resources SET
                available_quantity = available_quantity + ?,
                in_use_quantity = GREATEST(0, in_use_quantity - ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$qty, $qty, $resId]);
    } elseif ($type === 'Dispatched') {
        $stmt = $db->prepare("
            UPDATE resources SET
                available_quantity = GREATEST(0, available_quantity - ?),
                in_use_quantity = in_use_quantity + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$qty, $qty, $resId]);
    } else { // Adjusted
        $stmt = $db->prepare("
            UPDATE resources SET
                total_quantity = GREATEST(0, total_quantity + ?),
                available_quantity = GREATEST(0, available_quantity + ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$qty, $qty, $resId]);
    }

    $trx = $db->prepare("
        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, remarks, performed_by, created_at)
        VALUES (?, ?, ?, 'manual', ?, ?, NOW())
    ");
    $trx->execute([$resId, $type, $qty, $remarks, $user['id']]);
    $trxId = (int)$db->lastInsertId();

    logSystemEvent('INVENTORY_ADJUSTMENT', 'Inventory', "Adjusted resource #$resId ($type $qty units)");
    $db->commit();

    // Fetch updated record
    $upStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
    $upStmt->execute([$resId]);
    $updatedResource = $upStmt->fetch(PDO::FETCH_ASSOC);

    // Compute updated metrics
    $scopedBrgy = ($role === 'barangay_head') ? (int)$user['barangay_id'] : null;
    $whereScope = $scopedBrgy ? "WHERE barangay_id = {$scopedBrgy} AND status = 'active'" : "WHERE barangay_id IS NULL AND status = 'active'";
    
    $totalStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources $whereScope")->fetchColumn();
    $lowStockCount = (int)$db->query("SELECT COUNT(*) FROM resources $whereScope AND available_quantity <= min_threshold")->fetchColumn();

    $trxRecord = [
        'id' => $trxId,
        'created_at' => date('Y-m-d H:i:s'),
        'transaction_type' => $type,
        'quantity' => $qty,
        'unit' => $updatedResource['unit'],
        'remarks' => $remarks,
        'performed_by_name' => $user['full_name'] ?? ($user['username'] ?? 'User')
    ];

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Successfully recorded $type of " . number_format($qty) . " " . clean($updatedResource['unit']) . " for " . clean($updatedResource['name']) . ".",
            'resource' => $updatedResource,
            'transaction' => $trxRecord,
            'metrics' => [
                'total_stock' => $totalStock,
                'low_stock_count' => $lowStockCount
            ]
        ]);
    }

    redirectWithFlash($returnUrl, 'success', 'Inventory adjusted successfully.');
} catch (Exception $e) {
    $db->rollBack();
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Adjustment error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
