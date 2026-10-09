<?php
// ============================================================================
// Function: Preparedness Activities - Update Activity
// Scoped to Barangay Head jurisdiction with locked times if event already started
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

$id = (int)($_POST['activity_id'] ?? 0);
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid activity ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid activity ID.');
}

// Fetch existing record
$stmt = $db->prepare("SELECT * FROM preparedness_activities WHERE id = ?");
$stmt->execute([$id]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Event not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Event not found.');
}

// Jurisdiction check
if ($role === 'barangay_head' && (int)$act['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized for this barangay jurisdiction.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify events outside your barangay.');
}

$title = trim($_POST['title'] ?? $act['title']);
$type = trim($_POST['activity_type'] ?? $act['activity_type']);
$venue = trim($_POST['venue'] ?? ($_POST['venue_select'] ?? $act['venue']));
if ($venue === '__custom__') {
    $venue = trim($_POST['venue_custom'] ?? $act['venue']);
}
$personnel = trim($_POST['assigned_personnel'] ?? $act['assigned_personnel']);
$target = max(5, (int)($_POST['target_participants'] ?? $act['target_participants']));
$description = trim($_POST['description'] ?? $act['description']);

// Process content execution checklist if supplied
$contentExecutionInput = $_POST['content_execution'] ?? ($_POST['expected_activities'] ?? null);
$contentExecutionJson = null;
if ($contentExecutionInput !== null) {
    if (is_array($contentExecutionInput)) {
        $contentExecutionJson = json_encode(array_values(array_filter($contentExecutionInput)), JSON_UNESCAPED_UNICODE);
    } else {
        $contentExecutionJson = trim((string)$contentExecutionInput);
    }
}

// Check if event has already started, is completed/accomplished, or archived
$isStarted = (time() >= strtotime($act['start_datetime']));
$isCompleted = ($act['status'] === 'Completed');
$isArchived = !empty($act['is_archived']);

if ($isStarted || $isCompleted || $isArchived) {
    // If event has already started or is accomplished, start time and end time CANNOT be edited
    $startDate = $act['start_datetime'];
    $endDate = $act['end_datetime'];
} else {
    // If event has not yet started, allow editing starting time and end time
    $startDate = trim($_POST['start_datetime'] ?? $act['start_datetime']);
    $endDate = trim($_POST['end_datetime'] ?? $act['end_datetime']);
}

if (empty($title) || empty($venue) || empty($startDate) || empty($endDate)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Please provide all required fields.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Please provide all required fields.');
}

try {
    // Self-heal expected_activities column if missing
    $colsEa = $db->query("SHOW COLUMNS FROM preparedness_activities LIKE 'expected_activities'")->fetchAll();
    if (empty($colsEa)) {
        $db->exec("ALTER TABLE preparedness_activities ADD COLUMN expected_activities TEXT NULL AFTER content_execution");
    }

    if ($contentExecutionJson !== null) {
        if ($act['status'] === 'Completed') {
            $updateStmt = $db->prepare("
                UPDATE preparedness_activities SET
                    title = ?,
                    activity_type = ?,
                    venue = ?,
                    start_datetime = ?,
                    end_datetime = ?,
                    assigned_personnel = ?,
                    target_participants = ?,
                    description = ?,
                    expected_activities = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $title, $type, $venue, $startDate, $endDate,
                $personnel, $target, $description, $contentExecutionJson, $id
            ]);
        } else {
            $updateStmt = $db->prepare("
                UPDATE preparedness_activities SET
                    title = ?,
                    activity_type = ?,
                    venue = ?,
                    start_datetime = ?,
                    end_datetime = ?,
                    assigned_personnel = ?,
                    target_participants = ?,
                    description = ?,
                    content_execution = ?,
                    expected_activities = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $title, $type, $venue, $startDate, $endDate,
                $personnel, $target, $description, $contentExecutionJson, $contentExecutionJson, $id
            ]);
        }
    } else {
        $updateStmt = $db->prepare("
            UPDATE preparedness_activities SET
                title = ?,
                activity_type = ?,
                venue = ?,
                start_datetime = ?,
                end_datetime = ?,
                assigned_personnel = ?,
                target_participants = ?,
                description = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $title, $type, $venue, $startDate, $endDate,
            $personnel, $target, $description, $id
        ]);
    }

    logSystemEvent('UPDATE_ACTIVITY', 'Preparedness', "Updated event #$id: $title ($type)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => 'Event details updated successfully.',
            'id' => $id,
            'title' => $title
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Event '$title' updated successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
