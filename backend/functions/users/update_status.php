<?php
// ============================================================================
// Function: Users - Update Account Status (Active / Deactivated)
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
$status = trim($_POST['status'] ?? ($_GET['status'] ?? 'active'));

if (!canUserManageTarget($user, $targetUserId, $db)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

if ($targetUserId === (int)$user['id']) {
    redirectWithFlash($returnUrl, 'error', 'You cannot modify your own active account status.');
}

$stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
$stmt->execute([$status, $targetUserId]);

logSystemEvent('UPDATE_USER_STATUS', 'Users', "Changed user #$targetUserId status to $status");
redirectWithFlash($returnUrl, 'success', 'Account status updated successfully.');
