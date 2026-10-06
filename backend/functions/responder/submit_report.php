<?php
// ============================================================================
// Function: Responder - Submit Tactical Field Report
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/responder/dashboard.php'));

if (!in_array($user['role'], ['responder', 'icdrrmo'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$requestId = !empty($_POST['disaster_request_id']) ? (int)$_POST['disaster_request_id'] : null;
$barangayId = (int)($_POST['barangay_id'] ?? ($user['barangay_id'] ?? 1));
$purok = trim($_POST['purok'] ?? '');
$status = trim($_POST['operational_status'] ?? 'On Site');
$condition = trim($_POST['condition_overview'] ?? '');
$casualties = max(0, (int)($_POST['casualties'] ?? 0));
$injuries = max(0, (int)($_POST['injuries'] ?? 0));
$rescued = max(0, (int)($_POST['rescued_individuals'] ?? 0));
$resources = trim($_POST['resources_utilized'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');

if (empty($purok) || empty($condition)) {
    redirectWithFlash($returnUrl, 'error', 'Purok and ground condition overview are required.');
}

try {
    $stmt = $db->prepare("
        INSERT INTO responder_field_updates (
            responder_id, disaster_request_id, barangay_id, purok, operational_status,
            condition_overview, casualties, injuries, rescued_individuals,
            resources_utilized, remarks, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user['id'], $requestId, $barangayId, $purok, $status,
        $condition, $casualties, $injuries, $rescued,
        $resources, $remarks
    ]);

    // If request ID provided, update request casualty / injury counts if higher
    if ($requestId) {
        $updReq = $db->prepare("
            UPDATE disaster_requests SET
                casualties_count = GREATEST(casualties_count, ?),
                injuries_count = GREATEST(injuries_count, ?)
            WHERE id = ?
        ");
        $updReq->execute([$casualties, $injuries, $requestId]);
    }

    // Notify Central ICDRRMO of ground field log
    $notif = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at)
        VALUES ('icdrrmo', ?, ?, ?, 'warning', 'responder', ?, ?, NOW())
    ");
    $notifMsg = "Field update from {$user['full_name']} at $purok ($status): Rescued: $rescued, Injuries: $injuries.";
    $notif->execute([$barangayId, "Field Report: $purok ($status)", $notifMsg, $requestId, $user['id']]);

    logSystemEvent('SUBMIT_FIELD_REPORT', 'Responder Field', "Field update logged for $purok by {$user['full_name']}");

    redirectWithFlash($returnUrl, 'success', 'Tactical field report logged successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
