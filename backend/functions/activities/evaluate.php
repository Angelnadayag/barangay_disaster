<?php
// ============================================================================
// Function: Preparedness Activities - Record Evaluation
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/events.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized to evaluate activities.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to evaluate activities.');
}

$actId = (int)($_POST['activity_id'] ?? 0);
if (!$actId) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid activity ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid activity ID.');
}

// Verify jurisdiction for barangay head
if ($user['role'] === 'barangay_head') {
    $chkStmt = $db->prepare("SELECT barangay_id FROM preparedness_activities WHERE id = ?");
    $chkStmt->execute([$actId]);
    $actOwner = $chkStmt->fetch();
    if ($actOwner && (int)$actOwner['barangay_id'] !== (int)$user['barangay_id']) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized for this barangay event.'], 403);
        redirectWithFlash($returnUrl, 'error', 'Unauthorized for this event.');
    }
}

$status = trim($_POST['status'] ?? 'Completed');
$summary = trim($_POST['evaluation_summary'] ?? '');

// Self-heal table schema if necessary
$colsAct = $db->query("SHOW COLUMNS FROM preparedness_activities LIKE 'content_execution'")->fetchAll();
if (empty($colsAct)) {
    $db->exec("ALTER TABLE preparedness_activities ADD COLUMN content_execution TEXT NULL AFTER evaluation_summary");
}

$colsAtt = $db->query("SHOW COLUMNS FROM activity_attendees LIKE 'attended'")->fetchAll();
if (empty($colsAtt)) {
    $db->exec("ALTER TABLE activity_attendees ADD COLUMN attended TINYINT(1) NOT NULL DEFAULT 1 AFTER resident_name");
}

$colsEa = $db->query("SHOW COLUMNS FROM preparedness_activities LIKE 'expected_activities'")->fetchAll();
if (empty($colsEa)) {
    $db->exec("ALTER TABLE preparedness_activities ADD COLUMN expected_activities TEXT NULL AFTER content_execution");
}

// Process participants attended roster
$attendedCount = 0;
if (isset($_POST['has_attendee_roster']) && $_POST['has_attendee_roster'] == '1') {
    $attendedIds = isset($_POST['attended_ids']) && is_array($_POST['attended_ids']) 
        ? array_filter(array_map('intval', $_POST['attended_ids'])) 
        : [];
    
    // Reset all registered attendees for this activity to unattended
    $resetStmt = $db->prepare("UPDATE activity_attendees SET attended = 0 WHERE activity_id = ?");
    $resetStmt->execute([$actId]);

    // Mark checked participants as attended
    if (!empty($attendedIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($attendedIds), '?'));
        $markStmt = $db->prepare("UPDATE activity_attendees SET attended = 1 WHERE activity_id = ? AND id IN ($inPlaceholders)");
        $markStmt->execute(array_merge([$actId], $attendedIds));
        $attendedCount = count($attendedIds);
    }
}

$inputActual = max(0, (int)($_POST['actual_participants'] ?? 0));
$actual = max($inputActual, $attendedCount);

// Process content execution checklist (items that were checked off as executed)
$contentExecutionInput = $_POST['content_execution'] ?? [];
if (is_array($contentExecutionInput)) {
    $contentExecutionJson = json_encode(array_values(array_filter($contentExecutionInput)), JSON_UNESCAPED_UNICODE);
} else {
    $contentExecutionJson = trim((string)$contentExecutionInput);
}

// Process expected activities list (all expected items evaluated)
$expectedActivitiesInput = $_POST['expected_activities'] ?? null;
$expectedActivitiesJson = null;
if ($expectedActivitiesInput !== null) {
    if (is_array($expectedActivitiesInput)) {
        $expectedActivitiesJson = json_encode(array_values(array_filter($expectedActivitiesInput)), JSON_UNESCAPED_UNICODE);
    } else {
        $expectedActivitiesJson = trim((string)$expectedActivitiesInput);
    }
}

try {
    if ($expectedActivitiesJson !== null && $expectedActivitiesJson !== '') {
        $stmt = $db->prepare("
            UPDATE preparedness_activities SET
                status = ?,
                actual_participants = ?,
                evaluation_summary = ?,
                content_execution = ?,
                expected_activities = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $actual, $summary, $contentExecutionJson, $expectedActivitiesJson, $actId]);
    } else {
        $stmt = $db->prepare("
            UPDATE preparedness_activities SET
                status = ?,
                actual_participants = ?,
                evaluation_summary = ?,
                content_execution = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $actual, $summary, $contentExecutionJson, $actId]);
    }

    logSystemEvent('EVALUATE_ACTIVITY', 'Preparedness', "Evaluated activity #$actId ($status, $actual participants attended)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => 'Activity evaluation and content execution recorded successfully.',
            'activity_id' => $actId,
            'actual_participants' => $actual
        ]);
    }

    redirectWithFlash($returnUrl, 'success', 'Activity evaluation and attendance recorded successfully.');
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
