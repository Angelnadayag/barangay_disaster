<?php
// ============================================================================
// Function: Resources - Restock Quantity
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
$qty = (int)($_POST['quantity'] ?? 0);
$remarks = trim($_POST['remarks'] ?? 'Shipment restock from OCD / DSWD');

if (!$resId || $qty <= 0) {
    redirectWithFlash($returnUrl, 'error', 'Invalid quantity.');
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

    logSystemEvent('RESTOCK_RESOURCE', 'Inventory', "Restocked resource #$resId with $qty units. $remarks");
    $db->commit();
    redirectWithFlash($returnUrl, 'success', "Successfully restocked $qty units.");
} catch (Exception $e) {
    $db->rollBack();
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
