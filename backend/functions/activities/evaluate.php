<?php
// ============================================================================
// Function: Preparedness Activities - Record Evaluation
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/preparedness.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to evaluate activities.');
}

$actId = (int)($_POST['activity_id'] ?? 0);
$status = trim($_POST['status'] ?? 'Completed');
$actual = max(0, (int)($_POST['actual_participants'] ?? 0));
$summary = trim($_POST['evaluation_summary'] ?? '');

try {
    $stmt = $db->prepare("
        UPDATE preparedness_activities SET
            status = ?,
            actual_participants = ?,
            evaluation_summary = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $actual, $summary, $actId]);

    logSystemEvent('EVALUATE_ACTIVITY', 'Preparedness', "Evaluated activity #$actId ($status, $actual participants)");

    redirectWithFlash($returnUrl, 'success', 'Activity evaluation recorded successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
