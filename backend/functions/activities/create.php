<?php
// ============================================================================
// Function: Preparedness Activities - Schedule New Activity
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/SmsService.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/preparedness.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to schedule activities.');
}

$title = trim($_POST['title'] ?? '');
$type = trim($_POST['activity_type'] ?? 'Disaster Drill');
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
$venue = trim($_POST['venue'] ?? '');
$startDate = trim($_POST['start_datetime'] ?? '');
$endDate = trim($_POST['end_datetime'] ?? '');
$personnel = trim($_POST['assigned_personnel'] ?? '');
$target = max(10, (int)($_POST['target_participants'] ?? 50));
$description = trim($_POST['description'] ?? '');

if (empty($title) || empty($venue) || empty($startDate) || empty($endDate)) {
    redirectWithFlash($returnUrl, 'error', 'Please provide all required fields.');
}

try {
    $stmt = $db->prepare("
        INSERT INTO preparedness_activities (
            title, activity_type, barangay_id, venue, start_datetime, end_datetime,
            assigned_personnel, target_participants, actual_participants, description,
            status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'Scheduled', ?, NOW())
    ");
    $stmt->execute([
        $title, $type, $barangayId, $venue, $startDate, $endDate,
        $personnel, $target, $description, $user['id']
    ]);
    $actId = $db->lastInsertId();

    // Broadcast in-app notification for this activity
    $notif = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at)
        VALUES ('all', ?, ?, ?, 'informational', 'preparedness', ?, ?, NOW())
    ");
    $notifMsg = "New community preparedness activity scheduled: $title at $venue on $startDate.";
    $notif->execute([$barangayId, "Scheduled: $title", $notifMsg, $actId, $user['id']]);

    // Send SMS alerts to residents of this barangay
    $smsSentCount = 0;
    if ($barangayId) {
        $brgyStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
        $brgyStmt->execute([$barangayId]);
        $brgyRow = $brgyStmt->fetch(PDO::FETCH_ASSOC);
        $brgyName = !empty($brgyRow['name']) ? ucwords(strtolower(trim($brgyRow['name']))) : '';
        $committeeHeader = $brgyName 
            ? "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE ($brgyName)"
            : "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE";

        $resStmt = $db->prepare("
            SELECT id, first_name, last_name, full_name, phone 
            FROM users 
            WHERE role = 'resident' AND barangay_id = ? AND phone IS NOT NULL AND TRIM(phone) != '' AND status != 'archived'
        ");
        $resStmt->execute([$barangayId]);
        $residents = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $fStart = date('M d, Y h:i A', strtotime($startDate));
        $fEnd = date('M d, Y h:i A', strtotime($endDate));
        $lead = $personnel ?: 'Barangay Disaster Team';

        foreach ($residents as $r) {
            $rName = clean($r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name']));
            $smsText = $committeeHeader . "\n"
                . "Hi $rName!\n"
                . "A new event has been scheduled in your barangay:\n"
                . "Event: $title\n"
                . "Venue: $venue\n"
                . "Start: $fStart\n"
                . "End: $fEnd\n"
                . "Lead Facilitator: $lead\n"
                . "Details: " . ($description ?: 'Community preparedness activity.') . "\n"
                . "Please be guided accordingly. Thank you!";

            SmsService::send($r['phone'], $smsText, $rName, 'event_created', $actId);
            $smsSentCount++;
        }
    }

    logSystemEvent('SCHEDULE_ACTIVITY', 'Preparedness', "Scheduled activity $title ($type) and sent SMS notices to $smsSentCount residents.");

    $flashMsg = "Preparedness activity scheduled successfully." . ($smsSentCount > 0 ? " SMS alerts sent to $smsSentCount resident(s)." : "");
    redirectWithFlash($returnUrl, 'success', $flashMsg);
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
