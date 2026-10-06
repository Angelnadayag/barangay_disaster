<?php
// ============================================================================
// Function: Puroks - Restore Purok to Active
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/puroks.php?tab=archived'));

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

if (!$id) {
    redirectWithFlash($returnUrl, 'error', 'Invalid purok ID.');
}

$chk = $db->prepare("SELECT name, barangay_id FROM puroks WHERE id = ?");
$chk->execute([$id]);
$purok = $chk->fetch();

if (!$purok) {
    redirectWithFlash($returnUrl, 'error', 'Purok not found.');
}

if ($role === 'barangay_head' && (int)$purok['barangay_id'] !== (int)$user['barangay_id']) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to restore this purok.');
}

try {
    $stmt = $db->prepare("UPDATE puroks SET status = 'active' WHERE id = ?");
    $stmt->execute([$id]);

    $barangayId = (int)$purok['barangay_id'];
    $db->prepare("
        UPDATE barangays SET
            population = (SELECT COALESCE(SUM(population), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)),
            total_households = (SELECT COALESCE(SUM(households), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL))
        WHERE id = ?
    ")->execute([$barangayId, $barangayId, $barangayId]);

    logSystemEvent('RESTORE_PUROK', 'Puroks', "Restored Purok '{$purok['name']}' (#$id) to active status");

    redirectWithFlash($returnUrl, 'success', "Purok '{$purok['name']}' has been restored to Active status.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to restore purok: ' . $e->getMessage());
}
