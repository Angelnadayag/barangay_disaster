<?php
// ============================================================================
// Function: Barangays - Restore Barangay to Active
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
    $up = $db->prepare("UPDATE barangays SET status = 'active' WHERE id = ?");
    $up->execute([$id]);

    logSystemEvent('RESTORE_BARANGAY', 'Barangays', "Restored Barangay {$bName} (ID #{$id}) to active list");

    redirectWithFlash($returnUrl, 'success', "Barangay '{$bName}' has been restored to Active status.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to restore barangay: ' . $e->getMessage());
}
