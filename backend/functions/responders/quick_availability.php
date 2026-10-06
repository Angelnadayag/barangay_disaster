<?php
// ============================================================================
// Function: Responders - Quick Availability Status Update
// Allows Barangay Head and ICDRRMO to switch responder availability on-the-fly
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../users/can_manage.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/responders.php'));

$responderId = (int)($_POST['responder_id'] ?? 0);
$availability = trim($_POST['availability'] ?? '');

$validStatuses = ['Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'];
if (!in_array($availability, $validStatuses, true)) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'Invalid availability status.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid availability status.');
}

if (!canUserManageTarget($currentUser, $responderId, $db)) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to modify this responder.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify this responder.');
}

try {
    // Update user availability
    $stmt = $db->prepare("UPDATE users SET availability = ? WHERE id = ?");
    $stmt->execute([$availability, $responderId]);

    // Fetch responder info for logging
    $uStmt = $db->prepare("SELECT full_name, username, barangay_id, purok FROM users WHERE id = ?");
    $uStmt->execute([$responderId]);
    $resp = $uStmt->fetch(PDO::FETCH_ASSOC);

    // Optional field update record if barangay_id is known
    if ($resp && !empty($resp['barangay_id'])) {
        $opStatus = ($availability === 'Responding') ? 'Responding' : ($availability === 'On Duty' ? 'On Site' : ($availability === 'Standby' ? 'Standby' : 'Available'));
        $logStmt = $db->prepare("
            INSERT INTO responder_field_updates (
                responder_id, barangay_id, purok, operational_status, condition_overview, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $logStmt->execute([
            $responderId,
            $resp['barangay_id'],
            $resp['purok'] ?: 'Assigned Station',
            $opStatus,
            "Availability status changed to '{$availability}' by " . $currentUser['full_name']
        ]);
    }

    logSystemEvent(
        'UPDATE_AVAILABILITY',
        'Responders',
        "Set responder {$resp['full_name']} (@{$resp['username']}) availability to {$availability}.",
        $currentUser['id'],
        $currentUser['full_name'],
        $currentUser['role']
    );

    if (isAjaxRequest()) {
        jsonResponse([
            'success' => true,
            'message' => "Responder availability updated to {$availability}.",
            'availability' => $availability
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Responder availability updated to '{$availability}'.");
} catch (Exception $e) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
