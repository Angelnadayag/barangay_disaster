<?php
// ============================================================================
// Function: Evacuation Centers - Update Status & Evacuees Count
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/evacuation.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head', 'responder'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$centerId = (int)($_POST['center_id'] ?? 0);
$status = trim($_POST['status'] ?? 'Standby');
$evacueesCount = max(0, (int)($_POST['evacuees_count'] ?? 0));
$accessibility = trim($_POST['accessibility'] ?? 'Accessible (All Vehicles)');

if ($user['role'] === 'barangay_head') {
    $bChk = $db->prepare("SELECT barangay_id FROM evacuation_areas WHERE id = ?");
    $bChk->execute([$centerId]);
    $bId = (int)$bChk->fetchColumn();
    if ($bId !== (int)$user['barangay_id']) {
        redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify shelters outside your barangay.');
    }
}

try {
    $stmt = $db->prepare("
        UPDATE evacuation_areas SET
            status = ?,
            current_evacuees_count = ?,
            accessibility = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $evacueesCount, $accessibility, $centerId]);

    logSystemEvent('UPDATE_EVACUATION_CENTER', 'Evacuation', "Updated center #$centerId status to $status ($evacueesCount evacuees)");

    redirectWithFlash($returnUrl, 'success', 'Evacuation center status updated successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
