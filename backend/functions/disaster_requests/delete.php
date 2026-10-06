<?php
// ============================================================================
// Function: Disaster Requests / Incident Reports - Delete Report
// Scoped to Barangay jurisdiction with cascade safety and AJAX support
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
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid report ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid report ID.');
}

$stmt = $db->prepare("SELECT * FROM disaster_requests WHERE id = ?");
$stmt->execute([$id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Report not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Report not found.');
}

if ($role === 'barangay_head' && (int)$req['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized to delete reports outside your barangay.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to delete reports outside your barangay.');
}

try {
    $delStmt = $db->prepare("DELETE FROM disaster_requests WHERE id = ?");
    $delStmt->execute([$id]);

    logSystemEvent('DELETE_DISASTER_REQUEST', 'Disaster Reports', "Deleted incident report {$req['tracking_code']} (#$id)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Incident Report {$req['tracking_code']} has been removed.",
            'id' => $id
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Incident Report {$req['tracking_code']} removed successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to delete report: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to delete report: ' . $e->getMessage());
}
