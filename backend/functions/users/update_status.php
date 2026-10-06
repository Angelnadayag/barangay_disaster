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
$userType = trim($_POST['user_type'] ?? ($_GET['user_type'] ?? ''));
$status = trim($_POST['status'] ?? ($_GET['status'] ?? 'active'));

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

if (!$isResident && $targetUserId === (int)$user['id']) {
    redirectWithFlash($returnUrl, 'error', 'You cannot modify your own active account status.');
}

if ($isResident) {
    $stmt = $db->prepare("UPDATE residents SET status = ? WHERE id = ?");
    $stmt->execute([$status, $targetUserId]);
    logSystemEvent('UPDATE_RESIDENT_STATUS', 'Residents', "Changed resident #$targetUserId status to $status");
} else {
    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$status, $targetUserId]);
    logSystemEvent('UPDATE_USER_STATUS', 'Users', "Changed user #$targetUserId status to $status");
}

redirectWithFlash($returnUrl, 'success', 'Account status updated successfully.');
