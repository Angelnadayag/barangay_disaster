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
$userType = trim($_POST['user_type'] ?? ($_GET['user_type'] ?? ''));

$isResident = ($userType === 'resident');
if (!$isResident) {
    $chkR = $db->prepare("SELECT id FROM residents WHERE id = ?");
    $chkR->execute([$targetUserId]);
    if ($chkR->fetch()) {
        $isResident = true;
    }
}

if (!canUserManageTarget($user, $targetUserId, $db, $isResident ? 'resident' : 'user')) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

if ($isResident) {
    $stmt = $db->prepare("UPDATE residents SET status = 'active' WHERE id = ?");
    $stmt->execute([$targetUserId]);
    logSystemEvent('RESTORE_RESIDENT', 'Residents', "Restored resident account #$targetUserId to active status");
    redirectWithFlash($returnUrl, 'success', 'Resident record has been restored to active status.');
} else {
    $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $stmt->execute([$targetUserId]);
    logSystemEvent('RESTORE_USER', 'Users', "Restored user account #$targetUserId to active status");
    redirectWithFlash($returnUrl, 'success', 'Account restored successfully.');
}
