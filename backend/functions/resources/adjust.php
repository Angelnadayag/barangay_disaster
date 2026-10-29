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
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource not found.');
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
        'in_use_quantity' => $agencyResource['in_use_quantity'],
        'damaged_quantity' => $agencyResource['damaged_quantity'],
        'min_threshold' => $agencyResource['min_threshold']
    ];
}

// Verify jurisdiction
if ($role === 'barangay_head') {
    if (!$agencyResource && (int)($resource['barangay_id'] ?? 0) !== (int)$user['barangay_id']) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This item does not belong to your barangay stockroom.'], 403);
        redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify resources outside your barangay.');
    }
}

// Determine active stock pool
$currentAvail = $agencyResource ? (int)$agencyResource['available_quantity'] : (int)$resource['available_quantity'];
$unitName = $agencyResource ? $agencyResource['unit'] : $resource['unit'];
$itemName = $agencyResource ? $agencyResource['name'] : $resource['name'];

// Validate available stock if deducting
if (in_array($type, ['Damaged', 'Dispatched'], true)) {
    if ($qty > $currentAvail) {
        $msg = "Insufficient available stock. Current available: {$currentAvail} {$unitName}.";
        if ($isAjax) jsonResponse(['success' => false, 'message' => $msg], 400);
        redirectWithFlash($returnUrl, 'error', $msg);
    }
}

$db->beginTransaction();
try {
    // 1. Update agency_resources if found
    if ($agencyResource) {
        $arId = (int)$agencyResource['id'];
        if ($type === 'New Product' || $type === 'Restock') {
            $db->prepare("
                UPDATE agency_resources SET
                    total_quantity = total_quantity + ?,
                    available_quantity = available_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $arId]);
        } elseif ($type === 'Damaged') {
            $db->prepare("
                UPDATE agency_resources SET
                    available_quantity = GREATEST(0, available_quantity - ?),
                    damaged_quantity = damaged_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $arId]);
        } elseif ($type === 'Returned') {
            $db->prepare("
                UPDATE agency_resources SET
                    available_quantity = available_quantity + ?,
                    in_use_quantity = GREATEST(0, in_use_quantity - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $arId]);
        } elseif ($type === 'Dispatched') {
            $db->prepare("
                UPDATE agency_resources SET
                    available_quantity = GREATEST(0, available_quantity - ?),
                    in_use_quantity = in_use_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $arId]);
        } else { // Adjusted
            $db->prepare("
                UPDATE agency_resources SET
                    total_quantity = GREATEST(0, total_quantity + ?),
                    available_quantity = GREATEST(0, available_quantity + ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $arId]);
        }
    }

    // 2. If ICDRRMO or if direct resources table record exists, also update resources table
    if ($role === 'icdrrmo' || (!empty($resource['id']) && (int)($resource['barangay_id'] ?? 0) === (int)($user['barangay_id'] ?? 0))) {
        $targetResId = (int)$resource['id'];
        if ($type === 'New Product' || $type === 'Restock') {
            $db->prepare("
                UPDATE resources SET
                    total_quantity = total_quantity + ?,
                    available_quantity = available_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $targetResId]);
        } elseif ($type === 'Damaged') {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = GREATEST(0, available_quantity - ?),
                    damaged_quantity = damaged_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $targetResId]);
        } elseif ($type === 'Returned') {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = available_quantity + ?,
                    in_use_quantity = GREATEST(0, in_use_quantity - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $targetResId]);
        } elseif ($type === 'Dispatched') {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = GREATEST(0, available_quantity - ?),
                    in_use_quantity = in_use_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $targetResId]);
        } else { // Adjusted
            $db->prepare("
                UPDATE resources SET
                    total_quantity = GREATEST(0, total_quantity + ?),
                    available_quantity = GREATEST(0, available_quantity + ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $targetResId]);
        }
    }

    // Record transaction
    $trxResourceCatId = (int)($resource['id'] ?? $catalogId);
    $trx = $db->prepare("
        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, remarks, performed_by, created_at)
        VALUES (?, ?, ?, 'manual', ?, ?, NOW())
    ");
    $trx->execute([$trxResourceCatId, $type, $qty, $remarks, $user['id']]);
    $trxId = (int)$db->lastInsertId();

    logSystemEvent('INVENTORY_ADJUSTMENT', 'Supplies', "Adjusted supply #{$trxResourceCatId} ({$type} {$qty} units). Remarks: {$remarks}");
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
        'transaction_type' => $type,
        'quantity' => $qty,
        'unit' => $updatedResource['unit'] ?? '',
        'remarks' => $remarks,
        'performed_by_name' => $user['full_name'] ?? ($user['username'] ?? 'User')
    ];

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Successfully recorded $type of " . number_format($qty) . " " . clean($updatedResource['unit'] ?? '') . " for " . clean($updatedResource['name'] ?? '') . ".",
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
