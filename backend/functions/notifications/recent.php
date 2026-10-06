<?php
// ============================================================================
// Function: Notifications - Fetch Recent Notifications JSON
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }
}

$stmt = $db->prepare("
    SELECT n.*, nr.id AS is_read
    FROM notifications n
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE (
        n.target_role = 'all'
        OR n.target_role = ?
        OR (n.target_barangay_id IS NOT NULL AND n.target_barangay_id = ?)
        OR n.target_user_id = ?
    )
    ORDER BY n.created_at DESC
    LIMIT 10
");
$stmt->execute([
    $user['id'],
    $user['role'],
    $user['barangay_id'],
    $user['id']
]);
$list = $stmt->fetchAll();

foreach ($list as &$item) {
    $item['time_ago'] = timeAgo($item['created_at']);
}

jsonResponse(['success' => true, 'notifications' => $list]);
