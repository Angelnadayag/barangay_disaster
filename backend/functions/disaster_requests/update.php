<?php
// ============================================================================
// Function: Disaster Requests / Incident Reports - Update Report
// Full CRUD editing with RBAC, status progression (Ongoing / Completed), and AJAX support
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/disaster-reports.php'));

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? ($_POST['request_id'] ?? 0));
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid report ID specified.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid report ID specified.');
}

// Fetch existing record
$stmt = $db->prepare("SELECT * FROM disaster_reports WHERE id = ?");
$stmt->execute([$id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Incident report not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Incident report not found.');
}

// Verify Barangay Head jurisdiction
if ($role === 'barangay_head' && (int)$req['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This report belongs to another barangay.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify reports outside your barangay.');
}

$barangayId = ($role === 'icdrrmo' && !empty($_POST['barangay_id'])) ? (int)$_POST['barangay_id'] : (int)$req['barangay_id'];
$purokName = trim($_POST['purok_name'] ?? $req['purok_name']);
$disasterType = trim($_POST['disaster_type'] ?? $req['disaster_type']);
$severity = trim($_POST['severity'] ?? $req['severity']);
$urgency = trim($_POST['urgency'] ?? $req['urgency']);
$affectedFamilies = max(0, (int)($_POST['affected_families'] ?? $req['affected_families']));
$affectedIndividuals = max($affectedFamilies, (int)($_POST['affected_individuals'] ?? $req['affected_individuals']));
$displacedFamilies = max(0, (int)($_POST['displaced_families'] ?? $req['displaced_families']));
$casualties = max(0, (int)($_POST['casualties_count'] ?? $req['casualties_count']));
$injuries = max(0, (int)($_POST['injuries_count'] ?? $req['injuries_count']));
$missing = max(0, (int)($_POST['missing_count'] ?? $req['missing_count']));
$requestedAssistance = trim($_POST['requested_assistance'] ?? $req['requested_assistance']);
$situationOverview = trim($_POST['situation_overview'] ?? $req['situation_overview']);
$resolutionNotes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : $req['resolution_notes'];
$incidentLocation = isset($_POST['incident_location']) ? trim($_POST['incident_location']) : ($req['incident_location'] ?? '');
$coordinatesLat = (isset($_POST['coordinates_lat']) && $_POST['coordinates_lat'] !== '') 
    ? (float)$_POST['coordinates_lat'] 
    : (isset($req['coordinates_lat']) && $req['coordinates_lat'] !== null ? (float)$req['coordinates_lat'] : null);
$coordinatesLng = (isset($_POST['coordinates_lng']) && $_POST['coordinates_lng'] !== '') 
    ? (float)$_POST['coordinates_lng'] 
    : (isset($req['coordinates_lng']) && $req['coordinates_lng'] !== null ? (float)$req['coordinates_lng'] : null);

$aidSupport = trim($_POST['aid_support'] ?? ($req['aid_support'] ?? 'resources'));
if (!in_array($aidSupport, ['resources', 'financial_aid', 'both'], true)) {
    $aidSupport = 'resources';
}

// Optional status transition (only Ongoing or Completed) - Concluding an incident is restricted to Barangay Head
$newStatus = trim($_POST['status'] ?? '');
$allowedStatuses = ['Ongoing', 'Completed'];
if ($newStatus === 'Completed' && $role !== 'barangay_head') {
    $status = $req['status']; // Non-barangay head cannot conclude incident
} else {
    $status = (in_array($newStatus, $allowedStatuses, true)) ? $newStatus : (in_array($req['status'], $allowedStatuses, true) ? $req['status'] : 'Ongoing');
}

// Handle completion timestamp
$completedAt = $req['completed_at'];
if ($status === 'Completed' && $req['status'] !== 'Completed') {
    $completedAt = date('Y-m-d H:i:s');
} elseif ($status === 'Ongoing' && $req['status'] === 'Completed') {
    $completedAt = null; // Reopened
}

try {
    $updateStmt = $db->prepare("
        UPDATE disaster_reports SET
            barangay_id = ?,
            purok_name = ?,
            coordinates_lat = ?,
            coordinates_lng = ?,
            incident_location = ?,
            disaster_type = ?,
            severity = ?,
            urgency = ?,
            aid_support = ?,
            affected_families = ?,
            affected_individuals = ?,
            displaced_families = ?,
            casualties_count = ?,
            injuries_count = ?,
            missing_count = ?,
            requested_assistance = ?,
            situation_overview = ?,
            resolution_notes = ?,
            status = ?,
            completed_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $barangayId,
        $purokName,
        $coordinatesLat,
        $coordinatesLng,
        $incidentLocation,
        $disasterType, $severity, $urgency,
        $aidSupport,
        $affectedFamilies, $affectedIndividuals, $displacedFamilies,
        $casualties, $injuries, $missing,
        $requestedAssistance, $situationOverview, $resolutionNotes,
        $status, $completedAt,
        $id
    ]);

    logSystemEvent('UPDATE_DISASTER_REQUEST', 'Disaster Reports', "Updated incident report {$req['tracking_code']} (Status: $status)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Incident Report {$req['tracking_code']} updated successfully.",
            'status' => $status,
            'completed_at' => $completedAt
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Incident Report {$req['tracking_code']} updated successfully.");
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
    redirectWithFlash($returnUrl, 'error', 'Failed to update report: ' . $e->getMessage());
}
