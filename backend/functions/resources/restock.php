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

// Check agency context
$userAgency = getAgencyForUser($user);
$agencyId = $userAgency ? (int)$userAgency['id'] : null;

// Determine if $resId refers to agency_resources.id or resources.id
$agencyResource = null;
if ($agencyId) {
    $arStmt = $db->prepare("
        SELECT ar.*, r.id AS catalog_resource_id, r.code, r.name, r.category, r.unit, r.brand 
        FROM agency_resources ar 
        JOIN resources r ON ar.resource_id = r.id 
        WHERE ar.id = ? AND ar.agency_id = ?
    ");
    $arStmt->execute([$resId, $agencyId]);
    $agencyResource = $arStmt->fetch(PDO::FETCH_ASSOC);

    if (!$agencyResource) {
        $arStmt2 = $db->prepare("
            SELECT ar.*, r.id AS catalog_resource_id, r.code, r.name, r.category, r.unit, r.brand 
            FROM agency_resources ar 
            JOIN resources r ON ar.resource_id = r.id 
            WHERE ar.resource_id = ? AND ar.agency_id = ?
        ");
        $arStmt2->execute([$resId, $agencyId]);
        $agencyResource = $arStmt2->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch catalog resource
$catalogId = $agencyResource ? (int)$agencyResource['catalog_resource_id'] : $resId;
$chkStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
$chkStmt->execute([$catalogId]);
$resource = $chkStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource && !$agencyResource) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource record not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource record not found.');
}

if (!$resource) {
    $resource = [
        'id' => $agencyResource['catalog_resource_id'],
        'name' => $agencyResource['name'],
        'unit' => $agencyResource['unit'],
        'code' => $agencyResource['code'],
        'category' => $agencyResource['category'],
        'barangay_id' => null,
        'available_quantity' => $agencyResource['available_quantity'],
        'total_quantity' => $agencyResource['total_quantity'],
        'min_threshold' => $agencyResource['min_threshold']
    ];
}

// Verify jurisdiction
if ($role === 'barangay_head') {
    if (!$agencyResource && (int)($resource['barangay_id'] ?? 0) !== (int)$user['barangay_id']) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This item does not belong to your barangay stockroom.'], 403);
        redirectWithFlash($returnUrl, 'error', 'Unauthorized to restock resources outside your barangay.');
    }
}

$db->beginTransaction();
try {
    // 1. Update agency_resources
    if ($agencyResource) {
        $db->prepare("
            UPDATE agency_resources SET
                total_quantity = total_quantity + ?,
                available_quantity = available_quantity + ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$qty, $qty, (int)$agencyResource['id']]);
    }

    // 2. If ICDRRMO or if direct resources table record exists, also update resources table
    if ($role === 'icdrrmo' || (!empty($resource['id']) && (int)($resource['barangay_id'] ?? 0) === (int)($user['barangay_id'] ?? 0))) {
        $db->prepare("
            UPDATE resources SET
                total_quantity = total_quantity + ?,
                available_quantity = available_quantity + ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$qty, $qty, (int)$resource['id']]);
    }

    $trxResourceCatId = (int)($resource['id'] ?? $catalogId);
    $trx = $db->prepare("
        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, remarks, performed_by, created_at)
        VALUES (?, 'Restock', ?, 'manual', ?, ?, NOW())
    ");
    $trx->execute([$trxResourceCatId, $qty, $remarks, $user['id']]);
    $trxId = (int)$db->lastInsertId();

    logSystemEvent('RESTOCK_RESOURCE', 'Inventory', "Restocked resource #{$trxResourceCatId} with $qty units. $remarks");
    $db->commit();

    // Fetch updated record representation
    if ($agencyResource) {
        $upArStmt = $db->prepare("
            SELECT ar.*, r.code, r.name, r.category, r.unit, r.brand 
            FROM agency_resources ar 
            JOIN resources r ON ar.resource_id = r.id 
            WHERE ar.id = ?
        ");
        $upArStmt->execute([(int)$agencyResource['id']]);
        $upAr = $upArStmt->fetch(PDO::FETCH_ASSOC);
        $updatedResource = [
            'id' => $resId,
            'code' => $upAr['code'],
            'name' => $upAr['name'],
            'category' => $upAr['category'],
            'unit' => $upAr['unit'],
            'brand' => $upAr['brand'],
            'available_quantity' => (int)$upAr['available_quantity'],
            'total_quantity' => (int)$upAr['total_quantity'],
            'in_use_quantity' => (int)$upAr['in_use_quantity'],
            'damaged_quantity' => (int)$upAr['damaged_quantity'],
            'min_threshold' => (int)$upAr['min_threshold'],
            'status' => $upAr['status']
        ];
    } else {
        $upStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
        $upStmt->execute([(int)$resource['id']]);
        $updatedResource = $upStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Compute updated metrics
    if ($agencyId) {
        $totalStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM agency_resources WHERE agency_id = {$agencyId} AND (status != 'archived' OR status IS NULL)")->fetchColumn();
        $lowStockCount = (int)$db->query("SELECT COUNT(*) FROM agency_resources WHERE agency_id = {$agencyId} AND (status != 'archived' OR status IS NULL) AND available_quantity <= min_threshold")->fetchColumn();
    } else {
        $totalStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active'")->fetchColumn();
        $lowStockCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE status = 'active' AND available_quantity <= min_threshold")->fetchColumn();
    }

    $trxRecord = [
        'id' => $trxId,
        'created_at' => date('Y-m-d H:i:s'),
        'transaction_type' => 'Restock',
        'quantity' => $qty,
        'unit' => $updatedResource['unit'] ?? '',
        'remarks' => $remarks,
        'performed_by_name' => $user['full_name'] ?? ($user['username'] ?? 'User')
    ];

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Successfully restocked " . number_format($qty) . " " . clean($updatedResource['unit'] ?? '') . " of " . clean($updatedResource['name'] ?? '') . ".",
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
