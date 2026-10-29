<?php
// ============================================================================
// Function: Preparedness Activities - Archive Event
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to archive events outside your barangay.');
}

try {
    // Ensure is_archived column exists in events
    $cols = $db->query("SHOW COLUMNS FROM events LIKE 'is_archived'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE events ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $upd = $db->prepare("UPDATE events SET is_archived = 1 WHERE id = ?");
    $upd->execute([$id]);

    // Ensure is_archived column exists in event_attendees and archive all attendee families/members
    $colsAtt = $db->query("SHOW COLUMNS FROM event_attendees LIKE 'is_archived'")->fetchAll();
    if (empty($colsAtt)) {
        $db->exec("ALTER TABLE event_attendees ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
    }

    $updAtt = $db->prepare("UPDATE event_attendees SET is_archived = 1 WHERE activity_id = ?");
    $updAtt->execute([$id]);
    $archivedAttendeesCount = $updAtt->rowCount();
    $brgyName = 'City-Wide';
    if (!empty($act['barangay_id'])) {
        $bStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
        $bStmt->execute([(int)$act['barangay_id']]);
        $brgyName = $bStmt->fetchColumn() ?: 'Barangay #' . $act['barangay_id'];
    }

    $actorRole = formatRoleName($user['role'] ?? '');
    logSystemEvent(
        'ARCHIVE_ACTIVITY',
        'Preparedness',
        "{$user['full_name']} ({$actorRole}) archived event #$id: '{$act['title']}' (Barangay {$brgyName}) along with {$archivedAttendeesCount} registered participant(s)",
        $user['id'],
        $user['full_name'],
        $user['role']
    );

    $msg = "Event '{$act['title']}' and its registered participant families/members moved to Archives.";
    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'id' => $id,
            'archived_attendees_count' => $archivedAttendeesCount
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to archive event: ' . $e->getMessage());
}
