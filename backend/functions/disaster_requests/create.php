<?php
// ============================================================================
// Function: Disaster Requests / Incident Reports - Submit New Report
// Automatically flags status as 'Ongoing' for active field management
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/disaster-reports.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to submit disaster reports.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to submit disaster reports.');
}

$barangayId = ($user['role'] === 'barangay_head') ? (int)$user['barangay_id'] : (int)($_POST['barangay_id'] ?? 0);
$purokName = trim($_POST['purok_name'] ?? '');
$disasterType = trim($_POST['disaster_type'] ?? '');
$severity = trim($_POST['severity'] ?? 'Moderate');
$urgency = trim($_POST['urgency'] ?? 'Immediate');
$affectedFamilies = max(0, (int)($_POST['affected_families'] ?? 0));
$affectedIndividuals = max($affectedFamilies, (int)($_POST['affected_individuals'] ?? ($affectedFamilies * 4)));
$displacedFamilies = max(0, (int)($_POST['displaced_families'] ?? 0));
$casualties = max(0, (int)($_POST['casualties_count'] ?? 0));
$injuries = max(0, (int)($_POST['injuries_count'] ?? 0));
$missing = max(0, (int)($_POST['missing_count'] ?? 0));
$requestedAssistance = trim($_POST['requested_assistance'] ?? '');
$situationOverview = trim($_POST['situation_overview'] ?? '');

// Auto-default to Ongoing status
$status = 'Ongoing';

if (empty($barangayId) || empty($purokName) || empty($disasterType)) {
    $errMsg = 'Please fill in all mandatory fields: Purok Cluster and Disaster Type.';
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $errMsg], 400);
    }
    redirectWithFlash($returnUrl, 'error', $errMsg);
}

// Generate Tracking Code REQ-YYYY-XXX
$countStmt = $db->query("SELECT COUNT(*) FROM disaster_requests");
$nextId = ((int)$countStmt->fetchColumn()) + 1;
$trackingCode = 'REQ-' . date('Y') . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

try {
    $stmt = $db->prepare("
        INSERT INTO disaster_requests (
            tracking_code, barangay_id, purok_name, disaster_type, severity, urgency,
            affected_families, affected_individuals, displaced_families,
            casualties_count, injuries_count, missing_count,
            requested_assistance, situation_overview, status, submitted_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $trackingCode, $barangayId, $purokName, $disasterType, $severity, $urgency,
        $affectedFamilies, $affectedIndividuals, $displacedFamilies,
        $casualties, $injuries, $missing,
        $requestedAssistance, $situationOverview, $status, $user['id']
    ]);
    $requestId = (int)$db->lastInsertId();

    // Get barangay name
    $bStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
    $bStmt->execute([$barangayId]);
    $bName = $bStmt->fetchColumn() ?: 'Barangay';

    // Notify Central ICDRRMO
    $notifStmt = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
        VALUES ('icdrrmo', ?, ?, ?, ?, 'disaster_request', ?, NOW())
    ");
    $alertLevel = in_array($severity, ['Critical', 'High']) ? 'urgent' : 'warning';
    $notifMsg = "Active disaster incident ($disasterType - $severity) reported by Brgy. $bName ($purokName). Status is Ongoing.";
    $notifStmt->execute([$barangayId, "Active Incident: $trackingCode", $notifMsg, $alertLevel, $requestId]);

    logSystemEvent('CREATE_DISASTER_REQUEST', 'Disaster Reports', "Submitted active disaster report $trackingCode for Brgy. $bName ($disasterType - Ongoing)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Incident Report $trackingCode submitted successfully and is now Ongoing.",
            'id' => $requestId,
            'tracking_code' => $trackingCode,
            'status' => 'Ongoing'
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Incident Report $trackingCode filed successfully and is currently Ongoing.");
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
    redirectWithFlash($returnUrl, 'error', 'Database error: ' . $e->getMessage());
}
