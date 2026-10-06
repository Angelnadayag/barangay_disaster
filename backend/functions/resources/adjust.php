<?php
// ============================================================================
// Function: Resources - Adjust Inventory
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/resources.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$resId = (int)($_POST['resource_id'] ?? 0);
$type = trim($_POST['transaction_type'] ?? 'Adjusted');
$qty = (int)($_POST['quantity'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');

if (!$resId || $qty <= 0) {
    redirectWithFlash($returnUrl, 'error', 'Invalid resource or quantity.');
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
    } else {
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

    logSystemEvent('INVENTORY_ADJUSTMENT', 'Inventory', "Adjusted resource #$resId ($type $qty units)");
    $db->commit();
    redirectWithFlash($returnUrl, 'success', 'Inventory adjusted successfully.');
} catch (Exception $e) {
    $db->rollBack();
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
