<?php
// ============================================================================
// Function: Notifications - Mark Single Notification As Read
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/notifications.php'));

$notifId = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($notifId > 0) {
    $stmt = $db->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at) VALUES (?, ?, NOW())");
    $stmt->execute([$notifId, $user['id']]);
}

redirectWithFlash($returnUrl, 'success', 'Notification marked as read.');
