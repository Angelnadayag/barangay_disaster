<?php
// ============================================================================
// Function: Users - Permanently Purge Account
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/users.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$targetUserId = (int)($_POST['user_id'] ?? ($_GET['user_id'] ?? 0));
if ($targetUserId === (int)$user['id']) {
    redirectWithFlash($returnUrl, 'error', 'You cannot purge your own account.');
}

try {
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    logSystemEvent('PURGE_USER', 'Users', "Permanently purged user account #$targetUserId");
    redirectWithFlash($returnUrl, 'success', 'User account permanently purged.');
} catch (PDOException $e) {
    redirectWithFlash($returnUrl, 'error', 'Account cannot be purged due to linked records.');
}
