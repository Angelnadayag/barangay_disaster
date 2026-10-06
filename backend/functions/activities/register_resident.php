<?php
// ============================================================================
// Function: Preparedness Activities - Register Resident by Mobile & Send SMS
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/SmsService.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

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
$phone = trim($_POST['phone'] ?? '');
$residentName = trim($_POST['resident_name'] ?? '');
$userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

if (!$actId || empty($phone)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Activity ID and Mobile Number are required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Activity ID and Mobile Number are required.');
}

// Fetch record
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to register residents for events outside your barangay.');
}

// Verify event has started
$isStarted = (time() >= strtotime($act['start_datetime']));
if (!$isStarted) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resident registration is available only after the event has started.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Resident registration is available only after the event has started.');
}

$normalizedPhone = SmsService::normalizePhone($phone);
if (strlen($normalizedPhone) < 10) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Please provide a valid Philippine mobile number (e.g. 09XXXXXXXXX).'], 400);
    redirectWithFlash($returnUrl, 'error', 'Please provide a valid Philippine mobile number (e.g. 09XXXXXXXXX).');
}

// Look up user if user_id not provided
if (!$userId) {
    $uStmt = $db->prepare("SELECT id, full_name, first_name, last_name FROM users WHERE (phone = ? OR phone = ?) AND role = 'resident' LIMIT 1");
    $uStmt->execute([$phone, $normalizedPhone]);
    $uMatch = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uMatch) {
        $userId = (int)$uMatch['id'];
        if (empty($residentName)) {
            $residentName = $uMatch['full_name'] ?: ($uMatch['first_name'] . ' ' . $uMatch['last_name']);
        }
    }
}

if (empty($residentName)) {
    $residentName = 'Resident';
}

// Check for existing registration
$dupCheck = $db->prepare("SELECT id FROM activity_attendees WHERE activity_id = ? AND phone = ?");
$dupCheck->execute([$actId, $normalizedPhone]);
$existingReg = $dupCheck->fetch(PDO::FETCH_ASSOC);

// Determine assigned barangay name for SMS header
$brgyName = '';
$bId = !empty($act['barangay_id']) ? (int)$act['barangay_id'] : (!empty($user['barangay_id']) ? (int)$user['barangay_id'] : 0);
if ($bId) {
    $brgyStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
    $brgyStmt->execute([$bId]);
    $brgyRow = $brgyStmt->fetch(PDO::FETCH_ASSOC);
    if ($brgyRow && !empty($brgyRow['name'])) {
        $brgyName = ucwords(strtolower(trim($brgyRow['name'])));
    }
}
$committeeHeader = $brgyName 
    ? "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE ($brgyName)"
    : "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE";

// Prepare SMS Message with all requested event details
$formattedStart = date('M d, Y h:i A', strtotime($act['start_datetime']));
$formattedEnd = date('M d, Y h:i A', strtotime($act['end_datetime']));
$leadFacilitator = $act['assigned_personnel'] ?: 'Barangay Disaster Team';

$smsMessage = $committeeHeader . "\n"
    . "Hi " . clean($residentName) . "!\n"
    . "You are registered for: " . $act['title'] . "\n"
    . "Venue: " . $act['venue'] . "\n"
    . "Start: " . $formattedStart . "\n"
    . "End: " . $formattedEnd . "\n"
    . "Lead Facilitator: " . $leadFacilitator . "\n"
    . "Details: " . ($act['description'] ?: 'Community disaster preparedness activity.') . "\n"
    . "Thank you for participating!";

// Send SMS via SmsService
$smsResult = SmsService::send($normalizedPhone, $smsMessage, $residentName, 'event', $actId);

try {
    if ($existingReg) {
        // Update existing registration with new SMS status
        $regId = $existingReg['id'];
        $upd = $db->prepare("UPDATE activity_attendees SET sms_status = ?, sms_message = ?, registered_at = NOW() WHERE id = ?");
        $upd->execute([$smsResult['status'], $smsMessage, $regId]);
    } else {
        // Insert new attendee record
        $ins = $db->prepare("
            INSERT INTO activity_attendees (
                activity_id, user_id, resident_name, phone, registered_at, sms_status, sms_message
            ) VALUES (?, ?, ?, ?, NOW(), ?, ?)
        ");
        $ins->execute([
            $actId,
            $userId,
            $residentName,
            $normalizedPhone,
            $smsResult['status'],
            $smsMessage
        ]);
        $regId = $db->lastInsertId();

        // Increment actual_participants count in preparedness_activities
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM activity_attendees WHERE activity_id = ?");
        $cntStmt->execute([$actId]);
        $totalAttended = (int)$cntStmt->fetchColumn();

        $updAct = $db->prepare("UPDATE preparedness_activities SET actual_participants = ? WHERE id = ?");
        $updAct->execute([$totalAttended, $actId]);
    }

    // In-app notification for the resident if registered user exists
    if ($userId) {
        $notifStmt = $db->prepare("
            INSERT INTO notifications (target_role, target_user_id, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at)
            VALUES ('resident', ?, ?, ?, ?, 'informational', 'preparedness', ?, ?, NOW())
        ");
        $notifStmt->execute([
            $userId,
            $act['barangay_id'],
            "Event Registration: {$act['title']}",
            $smsMessage,
            $actId,
            $user['id']
        ]);
    }

    logSystemEvent('REGISTER_RESIDENT_EVENT', 'Preparedness', "Registered resident $residentName ($normalizedPhone) for event #$actId and dispatched SMS.");

    $successMsg = "Resident $residentName registered successfully! SMS text sent to $normalizedPhone with event details, schedule ($formattedStart - $formattedEnd), and Lead Facilitator: $leadFacilitator.";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $successMsg,
            'activity_id' => $actId,
            'resident_name' => $residentName,
            'phone' => $normalizedPhone,
            'sms_message' => $smsMessage,
            'sms_status' => $smsResult['status']
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $successMsg);
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
