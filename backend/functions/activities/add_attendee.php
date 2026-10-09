<?php
// ============================================================================
// Function: Preparedness Activities - Add Attendee (Resident or Walk-in)
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/SmsService.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/events.php'));

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$actId = (int)($_POST['activity_id'] ?? 0);
$residentName = trim($_POST['resident_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
$attended = isset($_POST['attended']) ? (int)$_POST['attended'] : 1;

if (!$actId || empty($residentName)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Activity ID and Participant Name are required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Activity ID and Participant Name are required.');
}

// Fetch activity
$stmt = $db->prepare("SELECT * FROM preparedness_activities WHERE id = ?");
$stmt->execute([$actId]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Event not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Event not found.');
}

// Jurisdiction check
if ($role === 'barangay_head' && (int)$act['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized for this barangay jurisdiction.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

// If user_id is provided, confirm resident exists
if ($userId) {
    $uStmt = $db->prepare("SELECT id, full_name, phone FROM residents WHERE id = ?");
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
        if (empty($phone)) $phone = $uRow['phone'] ?? '';
        if (empty($residentName)) $residentName = $uRow['full_name'] ?? '';
    } else {
        $userId = null;
    }
}

// Default phone if blank
if (empty($phone)) {
    $phone = 'Walk-in / No phone';
}

// Self-heal table schema if necessary
$colsAtt = $db->query("SHOW COLUMNS FROM activity_attendees LIKE 'attended'")->fetchAll();
if (empty($colsAtt)) {
    $db->exec("ALTER TABLE activity_attendees ADD COLUMN attended TINYINT(1) NOT NULL DEFAULT 1 AFTER resident_name");
}

// Check for duplicate / double submission within recent window
if ($userId) {
    $dupCheck = $db->prepare("SELECT * FROM activity_attendees WHERE activity_id = ? AND user_id = ? AND registered_at >= (NOW() - INTERVAL 10 SECOND)");
    $dupCheck->execute([$actId, $userId]);
} else {
    $dupCheck = $db->prepare("SELECT * FROM activity_attendees WHERE activity_id = ? AND LOWER(TRIM(resident_name)) = LOWER(TRIM(?)) AND registered_at >= (NOW() - INTERVAL 10 SECOND)");
    $dupCheck->execute([$actId, $residentName]);
}
$existingAttendee = $dupCheck->fetch(PDO::FETCH_ASSOC);
if ($existingAttendee) {
    // Already inserted within last 10 seconds - return existing record gracefully
    $cntStmt = $db->prepare("SELECT COUNT(*) AS total_reg, SUM(CASE WHEN attended = 1 THEN 1 ELSE 0 END) AS total_att FROM activity_attendees WHERE activity_id = ?");
    $cntStmt->execute([$actId]);
    $counts = $cntStmt->fetch(PDO::FETCH_ASSOC);
    $totalReg = (int)($counts['total_reg'] ?? 1);
    $totalAtt = (int)($counts['total_att'] ?? 1);
    $currentActual = (int)($act['actual_participants'] ?? 0);

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => 'Participant added successfully.',
            'attendee' => $existingAttendee,
            'total_registered' => $totalReg,
            'total_attended' => $totalAtt,
            'actual_participants' => $currentActual
        ]);
    }
    redirectWithFlash($returnUrl, 'success', 'Participant added successfully.');
}

// Insert attendee
$ins = $db->prepare("
    INSERT INTO activity_attendees (
        activity_id, user_id, resident_name, attended, phone, registered_at, sms_status
    ) VALUES (?, ?, ?, ?, ?, NOW(), 'sent')
");
$ins->execute([$actId, $userId, $residentName, $attended, $phone]);
$attendeeId = $db->lastInsertId();

// Count updated attendees
$cntStmt = $db->prepare("SELECT COUNT(*) AS total_reg, SUM(CASE WHEN attended = 1 THEN 1 ELSE 0 END) AS total_att FROM activity_attendees WHERE activity_id = ?");
$cntStmt->execute([$actId]);
$counts = $cntStmt->fetch(PDO::FETCH_ASSOC);
$totalReg = (int)($counts['total_reg'] ?? 1);
$totalAtt = (int)($counts['total_att'] ?? 1);

// Update actual_participants on activity if actual is lower than total_att
$currentActual = (int)($act['actual_participants'] ?? 0);
$newActual = max($currentActual, $totalAtt);
if ($newActual > $currentActual) {
    $updAct = $db->prepare("UPDATE preparedness_activities SET actual_participants = ? WHERE id = ?");
    $updAct->execute([$newActual, $actId]);
}

$newAttendee = [
    'id' => $attendeeId,
    'activity_id' => $actId,
    'user_id' => $userId,
    'resident_name' => $residentName,
    'phone' => $phone,
    'attended' => $attended,
    'registered_at' => date('Y-m-d H:i:s'),
    'sms_status' => 'sent'
];

if ($isAjax) {
    jsonResponse([
        'success' => true,
        'message' => 'Participant successfully added.',
        'attendee' => $newAttendee,
        'total_registered' => $totalReg,
        'total_attended' => $totalAtt,
        'actual_participants' => $newActual
    ]);
}

redirectWithFlash($returnUrl, 'success', 'Participant successfully added.');
