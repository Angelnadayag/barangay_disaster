<?php
// ============================================================================
// Function: Preparedness Activities - Cancel Activity
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

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

$id = (int)($_POST['activity_id'] ?? 0);
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid activity ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid activity ID.');
}

// Fetch record
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to cancel events outside your barangay.');
}

// Read reason inputs
$reasonCategory = trim($_POST['cancellation_reason_category'] ?? '');
$reasonDetails = trim($_POST['cancellation_reason_details'] ?? '');

if (empty($reasonCategory) && empty($reasonDetails)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Please provide a reason for cancellation.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Please provide a reason for cancellation.');
}

$fullReason = $reasonCategory;
if (!empty($reasonDetails)) {
    if (!empty($fullReason) && strpos($reasonDetails, $fullReason) === false) {
        $fullReason .= ' — ' . $reasonDetails;
    } else {
        $fullReason = $reasonDetails;
    }
}

try {
    // Ensure cancellation_reason column exists
    $colCheck = $db->query("SHOW COLUMNS FROM preparedness_activities LIKE 'cancellation_reason'")->fetchAll();
    if (empty($colCheck)) {
        $db->exec("ALTER TABLE preparedness_activities ADD COLUMN cancellation_reason TEXT NULL AFTER status");
    }

    $cancelStmt = $db->prepare("UPDATE preparedness_activities SET status = 'Cancelled', cancellation_reason = ? WHERE id = ?");
    $cancelStmt->execute([$fullReason, $id]);

    logSystemEvent('CANCEL_ACTIVITY', 'Preparedness', "Cancelled event #$id: {$act['title']} (Reason: $fullReason)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Event '{$act['title']}' has been cancelled.",
            'id' => $id,
            'reason' => $fullReason
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Event '{$act['title']}' has been cancelled.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
