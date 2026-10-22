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

if ($user['role'] === 'resident') {
    $userPurok = trim($user['purok'] ?? '');
    $stmt = $db->prepare("
        SELECT n.*, nr.id AS is_read
        FROM notifications n
        LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
        LEFT JOIN users u ON n.created_by = u.id
        LEFT JOIN events e ON (n.related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND n.related_id = e.id)
        WHERE (
            (n.target_user_id IS NOT NULL AND n.target_user_id = ?)
            OR
            (n.target_user_id IS NULL AND (n.target_barangay_id = ? OR n.target_barangay_id IS NULL) AND n.target_role IN ('all', 'resident'))
        )
        AND (u.role = 'barangay_head' OR n.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
        AND (
            (n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid', 'resources'))
            OR (e.id IS NOT NULL AND (e.is_archived = 0 OR e.is_archived IS NULL))
            OR (n.related_module = 'financial_aid' AND n.target_user_id IS NOT NULL)
        )
        AND (
            n.target_user_id IS NOT NULL
            OR COALESCE(n.target_puroks, e.target_puroks) IS NULL
            OR TRIM(COALESCE(n.target_puroks, e.target_puroks)) = ''
            OR COALESCE(n.target_puroks, e.target_puroks) IN ('All Puroks', 'All')
            OR FIND_IN_SET(?, REPLACE(COALESCE(n.target_puroks, e.target_puroks), ', ', ',')) > 0
        )
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([
        (int)$user['id'],
        (int)$user['id'],
        (int)($user['barangay_id'] ?? 0),
        (int)($user['barangay_id'] ?? 0),
        $userPurok
    ]);
} elseif ($user['role'] === 'responder') {
    $responderBrgyId = (int)($user['barangay_id'] ?? 0);
    if ($responderBrgyId === 0) {
        $rBrgyStmt = $db->prepare("SELECT barangay_id FROM users WHERE id = ?");
        $rBrgyStmt->execute([(int)$user['id']]);
        $responderBrgyId = (int)$rBrgyStmt->fetchColumn();
    }
    $stmt = $db->prepare("
        SELECT n.*, nr.id AS is_read
        FROM notifications n
        LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
        WHERE n.target_barangay_id = ?
          AND (n.target_role IN ('all', 'responder') OR n.target_user_id = ?)
          AND n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid', 'resources')
          AND n.title NOT LIKE '%Event%'
          AND n.title NOT LIKE '%Drill%'
          AND n.title NOT LIKE '%Training%'
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([
        $user['id'],
        $responderBrgyId,
        $user['id']
    ]);
} else {
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
}
$list = $stmt->fetchAll();

foreach ($list as &$item) {
    $item['time_ago'] = timeAgo($item['created_at']);
}

jsonResponse(['success' => true, 'notifications' => $list]);
