<?php
// ============================================================================
// Function: Barangays - Archive Barangay
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
    $up = $db->prepare("UPDATE barangays SET status = 'archived' WHERE id = ?");
    $up->execute([$id]);

    logSystemEvent('ARCHIVE_BARANGAY', 'Barangays', "Archived Barangay {$bName} (ID #{$id})");

    redirectWithFlash($returnUrl, 'success', "Barangay '{$bName}' has been moved to Archived Barangays.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to archive barangay: ' . $e->getMessage());
}
