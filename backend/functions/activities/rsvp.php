<?php
// ============================================================================
// Function: Activities - Self-Service RSVP / Attendance Toggle
// Allows logged-in residents and responders to register or unregister for an event
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$eventId = (int)($_POST['event_id'] ?? ($_POST['activity_id'] ?? 0));
$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/' . getRoleFolder($user['role']) . '/events.php'));

if (!$eventId) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Event ID is required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid event selected.');
}

// Check event exists
$stmt = $db->prepare("SELECT id, title, status, actual_participants, target_participants FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Event not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Event not found.');
}

if ($event['status'] === 'Cancelled' || $event['status'] === 'Archived') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'This event is no longer accepting registrations.'], 400);
    redirectWithFlash($returnUrl, 'error', 'This event is no longer active.');
}

$userId = (int)$user['id'];
$fullName = trim($user['full_name'] ?? 'Participant');
$phone = trim($user['phone'] ?? '');

// Check if already registered
$checkStmt = $db->prepare("SELECT id FROM event_attendees WHERE activity_id = ? AND (user_id = ? OR (phone != '' AND phone = ?)) LIMIT 1");
$checkStmt->execute([$eventId, $userId, $phone]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

try {
    $db->beginTransaction();

    if ($existing) {
        // Toggle: unregister
        $delStmt = $db->prepare("DELETE FROM event_attendees WHERE id = ?");
        $delStmt->execute([$existing['id']]);

        // Decrement participant count
        $db->prepare("UPDATE events SET actual_participants = GREATEST(0, actual_participants - 1) WHERE id = ?")->execute([$eventId]);

        $db->commit();

        $newCount = max(0, (int)$event['actual_participants'] - 1);
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'is_attending' => false,
                'message' => 'Your registration for "' . $event['title'] . '" has been cancelled.',
                'actual_participants' => $newCount
            ]);
        }
        redirectWithFlash($returnUrl, 'info', 'Your registration has been cancelled.');
    } else {
        // Register attendee
        $insStmt = $db->prepare("
            INSERT INTO event_attendees (activity_id, user_id, resident_name, attended, phone, registered_at, sms_status)
            VALUES (?, ?, ?, 1, ?, NOW(), 'sent')
        ");
        $insStmt->execute([$eventId, $userId, $fullName, $phone]);

        // Increment participant count
        $db->prepare("UPDATE events SET actual_participants = actual_participants + 1 WHERE id = ?")->execute([$eventId]);

        $db->commit();

        $newCount = (int)$event['actual_participants'] + 1;
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'is_attending' => true,
                'message' => 'Successfully registered for "' . $event['title'] . '"! We look forward to your participation.',
                'actual_participants' => $newCount
            ]);
        }
        redirectWithFlash($returnUrl, 'success', 'Successfully registered for this event!');
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Registration error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Unable to process registration.');
}
