<?php
// ============================================================================
// Function: Preparedness Activities - Toggle Attendee Attendance (Attended / Void)
// Supports both existing attendee IDs and unlisted household families (inserts on mark)
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1')
    || (isset($_GET['is_ajax']) && $_GET['is_ajax'] == '1');

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/events.php'));

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$attendeeId = (int)($_POST['attendee_id'] ?? ($_POST['id'] ?? ($_GET['attendee_id'] ?? ($_GET['id'] ?? 0))));
$actId = (int)($_POST['activity_id'] ?? ($_GET['activity_id'] ?? 0));
$residentName = trim($_POST['resident_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Ensure 'attended' and 'is_archived' columns exist in event_attendees
$colsAtt = $db->query("SHOW COLUMNS FROM event_attendees LIKE 'attended'")->fetchAll();
if (empty($colsAtt)) {
    $db->exec("ALTER TABLE event_attendees ADD COLUMN attended TINYINT(1) NOT NULL DEFAULT 1 AFTER resident_name");
}
$colsArch = $db->query("SHOW COLUMNS FROM event_attendees LIKE 'is_archived'")->fetchAll();
if (empty($colsArch)) {
    $db->exec("ALTER TABLE event_attendees ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
}

// Case 1: Attendee ID provided
if ($attendeeId > 0) {
    $stmt = $db->prepare("SELECT * FROM event_attendees WHERE id = ?");
    $stmt->execute([$attendeeId]);
    $attendee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendee) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Participant record not found.'], 404);
        redirectWithFlash($returnUrl, 'error', 'Participant record not found.');
    }

    $resolvedActId = $actId ?: (int)$attendee['activity_id'];
} else {
    // Case 2: Household family not yet in event_attendees table
    if (!$actId || empty($residentName)) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Valid activity ID and participant name are required.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Valid activity ID and participant name are required.');
    }

    $resolvedActId = $actId;

    // Check if an attendee record already exists for this event matching the resident name or phone
    $checkSql = "SELECT * FROM event_attendees WHERE activity_id = ? AND (resident_name = ? OR (phone != '' AND phone = ?)) LIMIT 1";
    $chkStmt = $db->prepare($checkSql);
    $chkStmt->execute([$resolvedActId, $residentName, $phone]);
    $attendee = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($attendee) {
        $attendeeId = (int)$attendee['id'];
    }
}

// Fetch event to verify permissions
$actStmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$actStmt->execute([$resolvedActId]);
$act = $actStmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Associated event not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Associated event not found.');
}

if ($role === 'barangay_head' && (int)$act['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized for this barangay jurisdiction.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

// Determine target attendance state (1 = Attended, 0 = Void)
if (isset($_POST['attended']) && $_POST['attended'] !== '') {
    $targetAttended = (int)$_POST['attended'] === 1 ? 1 : 0;
} else {
    $currentAtt = $attendee ? (int)($attendee['attended'] ?? 0) : 0;
    $targetAttended = $currentAtt === 1 ? 0 : 1;
}

if ($attendeeId > 0) {
    // Update existing attendee record
    $updStmt = $db->prepare("UPDATE event_attendees SET attended = ? WHERE id = ?");
    $updStmt->execute([$targetAttended, $attendeeId]);
} else {
    // Insert new attendee record for this household family
    $insStmt = $db->prepare("
        INSERT INTO event_attendees (activity_id, resident_name, phone, attended, registered_at, is_archived)
        VALUES (?, ?, ?, ?, NOW(), 0)
    ");
    $insStmt->execute([$resolvedActId, $residentName, $phone, $targetAttended]);
    $attendeeId = (int)$db->lastInsertId();
}

// Recalculate remaining attendees and attendance count
$cntStmt = $db->prepare("SELECT COUNT(*) AS total_reg, COALESCE(SUM(CASE WHEN attended = 1 THEN 1 ELSE 0 END), 0) AS total_att FROM event_attendees WHERE activity_id = ?");
$cntStmt->execute([$resolvedActId]);
$counts = $cntStmt->fetch(PDO::FETCH_ASSOC);
$totalReg = (int)($counts['total_reg'] ?? 0);
$totalAtt = (int)($counts['total_att'] ?? 0);

// Update actual_participants on activity
$updAct = $db->prepare("UPDATE events SET actual_participants = ? WHERE id = ?");
$updAct->execute([$totalAtt, $resolvedActId]);

if ($isAjax) {
    jsonResponse([
        'success' => true,
        'message' => $targetAttended === 1 ? 'Marked as Attended.' : 'Attendance voided.',
        'attendee_id' => $attendeeId,
        'activity_id' => $resolvedActId,
        'attended' => $targetAttended,
        'status_label' => $targetAttended === 1 ? 'Attended' : 'Void',
        'total_registered' => $totalReg,
        'total_attended' => $totalAtt,
        'actual_participants' => $totalAtt
    ]);
}

redirectWithFlash($returnUrl, 'success', 'Attendance status updated successfully.');
