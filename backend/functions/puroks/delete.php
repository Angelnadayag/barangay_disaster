<?php
// ============================================================================
// Function: Puroks - Delete Purok
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/puroks.php'));

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

$chk = $db->prepare("SELECT name, barangay_id FROM puroks WHERE id = ?");
$chk->execute([$id]);
$purok = $chk->fetch();

if (!$purok) {
    redirectWithFlash($returnUrl, 'error', 'Purok not found.');
}

if ($role === 'barangay_head' && (int)$purok['barangay_id'] !== (int)$user['barangay_id']) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

try {
    $del = $db->prepare("DELETE FROM puroks WHERE id = ?");
    $del->execute([$id]);

    $barangayId = (int)$purok['barangay_id'];
    $db->prepare("
        UPDATE barangays SET
            population = (SELECT COALESCE(SUM(population), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)),
            total_households = (SELECT COALESCE(SUM(households), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL))
        WHERE id = ?
    ")->execute([$barangayId, $barangayId, $barangayId]);

    logSystemEvent('DELETE_PUROK', 'Puroks', "Deleted Purok '{$purok['name']}' (#$id)");

    redirectWithFlash($returnUrl, 'success', "Purok '{$purok['name']}' deleted successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to delete purok: ' . $e->getMessage());
}
