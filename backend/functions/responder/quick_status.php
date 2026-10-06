<?php
// ============================================================================
// Function: Responder - Quick Operational Status Update
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/responder/dashboard.php'));

if ($user['role'] !== 'responder') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$status = trim($_POST['status'] ?? 'Available');
$purok = trim($_POST['purok'] ?? ($user['purok'] ?? 'Hinaplanon Base'));
$barangayId = (int)($user['barangay_id'] ?? 1);

try {
    $stmt = $db->prepare("
        INSERT INTO responder_field_updates (
            responder_id, barangay_id, purok, operational_status, condition_overview,
            casualties, injuries, rescued_individuals, resources_utilized, remarks, created_at
        ) VALUES (?, ?, ?, ?, 'Status updated via operational switcher.', 0, 0, 0, 'Standard gear', 'Status update', NOW())
    ");
    $stmt->execute([$user['id'], $barangayId, $purok, $status]);

    logSystemEvent('FIELD_STATUS_TOGGLE', 'Responder Field', "Responder {$user['full_name']} changed operational status to $status");

    redirectWithFlash($returnUrl, 'success', "Operational status set to '$status'.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to update status: ' . $e->getMessage());
}
