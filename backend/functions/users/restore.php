<?php
// ============================================================================
// Function: Users - Restore Account
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/can_manage.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/users.php'));

$targetUserId = (int)($_POST['user_id'] ?? ($_GET['user_id'] ?? 0));
if (!canUserManageTarget($user, $targetUserId, $db)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
$stmt->execute([$targetUserId]);

logSystemEvent('RESTORE_USER', 'Users', "Restored user account #$targetUserId to active status");
redirectWithFlash($returnUrl, 'success', 'Account restored successfully.');
