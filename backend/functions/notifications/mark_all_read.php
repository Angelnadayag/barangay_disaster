<?php
// ============================================================================
// Function: Notifications - Mark All Notifications As Read
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/notifications.php'));

$stmt = $db->prepare("
    INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
    SELECT n.id, ?, NOW()
    FROM notifications n
    WHERE (
        n.target_role = 'all'
        OR n.target_role = ?
        OR (n.target_barangay_id IS NOT NULL AND n.target_barangay_id = ?)
        OR n.target_user_id = ?
    )
");
$stmt->execute([
    $user['id'],
    $user['role'],
    $user['barangay_id'],
    $user['id']
]);

redirectWithFlash($returnUrl, 'success', 'All notifications marked as read.');
