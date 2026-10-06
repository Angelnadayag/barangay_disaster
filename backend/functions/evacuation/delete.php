<?php
// ============================================================================
// Function: Evacuation Centers - Delete Center
// Supports ICDRRMO Admin & Barangay Head (Scoped)
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$defaultReturn = ($user['role'] === 'barangay_head') 
    ? (BASE_URL . '/views/barangay/evacuation.php')
    : (BASE_URL . '/views/icdrrmo/evacuation.php');
$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? $defaultReturn);

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to delete evacuation areas.');
}

$centerId = (int)($_POST['id'] ?? ($_POST['center_id'] ?? ($_GET['id'] ?? 0)));
if ($centerId <= 0) {
    redirectWithFlash($returnUrl, 'error', 'Invalid evacuation center ID.');
}

// Check existing center
$chk = $db->prepare("SELECT * FROM evacuation_areas WHERE id = ?");
$chk->execute([$centerId]);
$center = $chk->fetch();

if (!$center) {
    redirectWithFlash($returnUrl, 'error', 'Evacuation shelter not found.');
}

if ($user['role'] === 'barangay_head' && (int)$center['barangay_id'] !== (int)$user['barangay_id']) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to delete shelters outside your barangay.');
}

try {
    // Delete any dependent resource records for this shelter
    $delRes = $db->prepare("DELETE FROM evacuation_center_resources WHERE evacuation_area_id = ?");
    $delRes->execute([$centerId]);

    // Delete evacuation area record
    $stmt = $db->prepare("DELETE FROM evacuation_areas WHERE id = ?");
    $stmt->execute([$centerId]);

    logSystemEvent('DELETE_EVACUATION_CENTER', 'Evacuation', "Deleted evacuation shelter #$centerId '{$center['name']}'");

    redirectWithFlash($returnUrl, 'success', "Evacuation shelter '{$center['name']}' deleted successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
