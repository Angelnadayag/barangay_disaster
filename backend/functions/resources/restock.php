<?php
// ============================================================================
// Function: Resources - Restock Quantity
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
$qty = (int)($_POST['quantity'] ?? 0);
$remarks = trim($_POST['remarks'] ?? ($role === 'barangay_head' ? 'Local restock / donor replenishment' : 'Shipment restock from OCD / DSWD'));

if (!$resId || $qty <= 0) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid resource ID or restock quantity.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid quantity.');
}

// Verify resource exists and check jurisdiction
$chkStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
$chkStmt->execute([$resId]);
$resource = $chkStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource record not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource record not found.');
}

if ($role === 'barangay_head' && (int)$resource['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This item does not belong to your barangay stockroom.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to restock resources outside your barangay.');
}

$db->beginTransaction();
try {
    $stmt = $db->prepare("
        UPDATE resources SET
            total_quantity = total_quantity + ?,
            available_quantity = available_quantity + ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$qty, $qty, $resId]);

    $trx = $db->prepare("
        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, remarks, performed_by, created_at)
        VALUES (?, 'Restock', ?, 'manual', ?, ?, NOW())
    ");
    $trx->execute([$resId, $qty, $remarks, $user['id']]);
    $trxId = (int)$db->lastInsertId();

    logSystemEvent('RESTOCK_RESOURCE', 'Inventory', "Restocked resource #$resId with $qty units. $remarks");
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
        'transaction_type' => 'Restock',
        'quantity' => $qty,
        'unit' => $updatedResource['unit'],
        'remarks' => $remarks,
        'performed_by_name' => $user['full_name'] ?? ($user['username'] ?? 'User')
    ];

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Successfully restocked " . number_format($qty) . " " . clean($updatedResource['unit']) . " of " . clean($updatedResource['name']) . ".",
            'resource' => $updatedResource,
            'transaction' => $trxRecord,
            'metrics' => [
                'total_stock' => $totalStock,
                'low_stock_count' => $lowStockCount
            ]
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Successfully restocked $qty units.");
} catch (Exception $e) {
    $db->rollBack();
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Restock error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
