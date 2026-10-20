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

$defaultReturn = ($user['role'] === 'barangay_head') ? (BASE_URL . '/views/barangay/events.php') : (BASE_URL . '/views/icdrrmo/events.php');
$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? $defaultReturn);

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to schedule activities.');
}

$title = trim($_POST['title'] ?? '');
$type = trim($_POST['activity_type'] ?? 'Health and Medical');
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
if (empty($barangayId) && !empty($user['barangay_id'])) {
    $barangayId = (int)$user['barangay_id'];
}

// Venue handling from select or custom text input
$venue = trim($_POST['venue'] ?? ($_POST['venue_select'] ?? ''));
if ($venue === '__custom__' || empty($venue)) {
    $venue = trim($_POST['venue_custom'] ?? '');
}

$startDate = trim($_POST['start_datetime'] ?? '');
$endDate = trim($_POST['end_datetime'] ?? '');
$personnel = trim($_POST['assigned_personnel'] ?? '');
$target = max(10, (int)($_POST['target_participants'] ?? 50));
$description = trim($_POST['description'] ?? '');

// Process expected activities list as initial content execution
$contentExecutionInput = $_POST['content_execution'] ?? ($_POST['expected_activities'] ?? []);
if (is_array($contentExecutionInput)) {
    $contentExecutionJson = json_encode(array_values(array_filter($contentExecutionInput)), JSON_UNESCAPED_UNICODE);
} else {
    $contentExecutionJson = trim((string)$contentExecutionInput);
}

if (empty($title) || empty($venue) || empty($startDate) || empty($endDate)) {
    redirectWithFlash($returnUrl, 'error', 'Please provide all required fields including venue.');
}

try {
    // Self-heal content_execution & expected_activities columns if missing
    $colsCe = $db->query("SHOW COLUMNS FROM events LIKE 'content_execution'")->fetchAll();
    if (empty($colsCe)) {
        $db->exec("ALTER TABLE events ADD COLUMN content_execution TEXT NULL AFTER evaluation_summary");
    }
    $colsEa = $db->query("SHOW COLUMNS FROM events LIKE 'expected_activities'")->fetchAll();
    if (empty($colsEa)) {
        $db->exec("ALTER TABLE events ADD COLUMN expected_activities TEXT NULL AFTER content_execution");
    }

    // Look up coordinates from venue match in puroks or barangays
    $eventLat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $eventLng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    if (!$eventLat || !$eventLng) {
        $pCoordStmt = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM puroks WHERE barangay_id = ? AND ? LIKE CONCAT('%', name, '%') LIMIT 1");
        $pCoordStmt->execute([$barangayId, $venue]);
        $pCoord = $pCoordStmt->fetch(PDO::FETCH_ASSOC);
        if ($pCoord) {
            $eventLat = $pCoord['coordinates_lat'];
            $eventLng = $pCoord['coordinates_lng'];
        } else {
            $bCoordStmt = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM barangays WHERE id = ?");
            $bCoordStmt->execute([$barangayId]);
            $bCoord = $bCoordStmt->fetch(PDO::FETCH_ASSOC);
            if ($bCoord) {
                $eventLat = $bCoord['coordinates_lat'];
                $eventLng = $bCoord['coordinates_lng'];
            }
        }
    }

    $stmt = $db->prepare("
        INSERT INTO events (
            title, activity_type, barangay_id, venue, latitude, longitude, start_datetime, end_datetime,
            assigned_personnel, target_participants, actual_participants, description,
            status, content_execution, expected_activities, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'Scheduled', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $title, $type, $barangayId, $venue, $eventLat, $eventLng, $startDate, $endDate,
        $personnel, $target, $description, $contentExecutionJson, $contentExecutionJson, $user['id']
    ]);
    $actId = $db->lastInsertId();

    // Broadcast in-app notification for this activity to barangay residents and responders
    $notif = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at)
        VALUES ('all', ?, ?, ?, 'informational', 'events', ?, ?, NOW())
    ");
    $notifTitle = "Community Event: $title";
    $notifMsg = "Barangay event scheduled: $title ($type) at $venue on " . date('M d, Y h:i A', strtotime($startDate)) . ". Residents and responders are invited to participate.";
    $notif->execute([$barangayId, $notifTitle, $notifMsg, $actId, $user['id']]);

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
            FROM residents 
            WHERE barangay_id = ? AND phone IS NOT NULL AND TRIM(phone) != '' AND status != 'archived'
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
