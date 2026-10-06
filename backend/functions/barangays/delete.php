<?php
// ============================================================================
// Function: Barangays - Delete Barangay
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/barangays.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

if (!$id) {
    redirectWithFlash($returnUrl, 'error', 'Invalid barangay ID.');
}

$stmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
$stmt->execute([$id]);
$bName = $stmt->fetchColumn();

if (!$bName) {
    redirectWithFlash($returnUrl, 'error', 'Barangay not found.');
}

try {
    $del = $db->prepare("DELETE FROM barangays WHERE id = ?");
    $del->execute([$id]);

    logSystemEvent('DELETE_BARANGAY', 'Barangays', "Deleted Barangay {$bName} (ID #{$id})");

    redirectWithFlash($returnUrl, 'success', "Barangay '{$bName}' has been permanently deleted.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to delete barangay: ' . $e->getMessage());
}
