<?php
// ============================================================================
// Function: Preparedness Activities - Toggle Attendee Attendance (Attended / Void)
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

if (!$attendeeId) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Attendee ID is required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Attendee ID is required.');
}

// Fetch attendee record
$stmt = $db->prepare("SELECT * FROM event_attendees WHERE id = ?");
$stmt->execute([$attendeeId]);
$attendee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$attendee) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Participant record not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Participant record not found.');
}

$resolvedActId = $actId ?: (int)$attendee['activity_id'];

// Fetch activity to check jurisdiction
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

// Ensure 'attended' column exists
$colsAtt = $db->query("SHOW COLUMNS FROM event_attendees LIKE 'attended'")->fetchAll();
if (empty($colsAtt)) {
    $db->exec("ALTER TABLE event_attendees ADD COLUMN attended TINYINT(1) NOT NULL DEFAULT 1 AFTER resident_name");
}

// Determine target attendance state:
if (isset($_POST['attended']) && $_POST['attended'] !== '') {
    $targetAttended = (int)$_POST['attended'] === 1 ? 1 : 0;
} else {
    $currentAtt = (int)($attendee['attended'] ?? 0);
    $targetAttended = $currentAtt === 1 ? 0 : 1;
}

// Update attendee record
$updStmt = $db->prepare("UPDATE event_attendees SET attended = ? WHERE id = ?");
$updStmt->execute([$targetAttended, $attendeeId]);

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
