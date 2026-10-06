<?php
// ============================================================================
// Function: Disaster Requests / Incident Reports - Update Status
// Supports Ongoing to Completed lifecycle transitions with completion timestamp
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

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$requestId = (int)($_POST['request_id'] ?? ($_POST['id'] ?? 0));
$status = trim($_POST['status'] ?? '');
$resolutionNotes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : null;

$allowed = ['Submitted', 'Ongoing', 'Under Review', 'Recommendation Ready', 'Approved', 'Allocated', 'Dispatched', 'Completed', 'Rejected'];

if (!$requestId || !in_array($status, $allowed, true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid status or report ID specified.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid status specified.');
}

// Check ownership if Barangay Head
$chk = $db->prepare("SELECT * FROM disaster_requests WHERE id = ?");
$chk->execute([$requestId]);
$req = $chk->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Report not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Report not found.');
}

if ($role === 'barangay_head' && (int)$req['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized to modify reports outside your barangay.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify reports outside your barangay.');
}

try {
    if ($status === 'Completed') {
        $stmt = $db->prepare("
            UPDATE disaster_requests SET
                status = 'Completed',
                resolution_notes = COALESCE(?, resolution_notes),
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$resolutionNotes, $requestId]);
        $msg = "Incident report {$req['tracking_code']} has been ended and marked as Completed.";
    } elseif ($status === 'Ongoing') {
        $stmt = $db->prepare("
            UPDATE disaster_requests SET
                status = 'Ongoing',
                completed_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
        $msg = "Incident report {$req['tracking_code']} status set to Ongoing.";
    } else {
        $stmt = $db->prepare("UPDATE disaster_requests SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $requestId]);
        $msg = "Request status updated to '$status'.";
    }

    logSystemEvent('UPDATE_REQUEST_STATUS', 'Disaster Reports', "Updated {$req['tracking_code']} status to $status");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'status' => $status,
            'tracking_code' => $req['tracking_code']
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to update request: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to update request: ' . $e->getMessage());
}
