<?php
// ============================================================================
// Function: Preparedness Activities - Permanently Delete Event
// Deletes event record along with all associated attendee rosters, families, and notifications
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

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

$id = (int)($_POST['activity_id'] ?? ($_POST['id'] ?? ($_GET['id'] ?? 0)));
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid activity ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid activity ID.');
}

$stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$id]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Event not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Event not found.');
}

// Check barangay jurisdiction
if ($role === 'barangay_head' && (int)$act['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized for this barangay jurisdiction.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to delete events outside your barangay.');
}

try {
    $db->beginTransaction();

    // 1. Count registered attendees / families before deletion
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM event_attendees WHERE activity_id = ?");
    $cntStmt->execute([$id]);
    $attendeeCount = (int)$cntStmt->fetchColumn();

    // 2. Permanently delete all attendees / participants registered for this event
    $delAtt = $db->prepare("DELETE FROM event_attendees WHERE activity_id = ?");
    $delAtt->execute([$id]);

    // 3. Delete related event notifications
    $delNotif = $db->prepare("DELETE FROM notifications WHERE related_module = 'events' AND related_id = ?");
    $delNotif->execute([$id]);

    // 4. Clean up any linked financial aid allocations, distributions, and audits
    $allocsStmt = $db->prepare("SELECT id FROM financial_aid_allocations WHERE event_id = ?");
    $allocsStmt->execute([$id]);
    $linkedAllocIds = $allocsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($linkedAllocIds)) {
        $inAllocs = implode(',', array_fill(0, count($linkedAllocIds), '?'));
        $db->prepare("DELETE FROM financial_aid_audits WHERE allocation_id IN ($inAllocs)")->execute($linkedAllocIds);
        $db->prepare("DELETE FROM financial_aid_distributions WHERE allocation_id IN ($inAllocs)")->execute($linkedAllocIds);
        $db->prepare("DELETE FROM financial_aid_allocations WHERE id IN ($inAllocs)")->execute($linkedAllocIds);
    }
    $db->prepare("DELETE FROM financial_aid_distributions WHERE event_id = ?")->execute([$id]);

    // 5. Permanently delete the event record
    $delAct = $db->prepare("DELETE FROM events WHERE id = ?");
    $delAct->execute([$id]);

    $db->commit();

    logSystemEvent('DELETE_ACTIVITY', 'Preparedness', "Permanently deleted event #$id: '{$act['title']}' along with $attendeeCount registered attendee(s).");

    $msg = "Event '{$act['title']}' and its registered participant families/members have been permanently deleted.";
    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'id' => $id,
            'deleted_attendees_count' => $attendeeCount
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to delete event: ' . $e->getMessage());
}
