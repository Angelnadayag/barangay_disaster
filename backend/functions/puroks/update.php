<?php
// ============================================================================
// Function: Puroks - Update Purok
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/puroks.php'));

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$hazardTypes = trim($_POST['hazard_types'] ?? 'Flood, Strong Winds');
$riskLevel = trim($_POST['risk_level'] ?? 'Moderate');
$households = max(0, (int)($_POST['households'] ?? 0));
$population = max(0, (int)($_POST['population'] ?? ($households * 4.5)));
$lat = isset($_POST['coordinates_lat']) && is_numeric($_POST['coordinates_lat']) ? (float)$_POST['coordinates_lat'] : null;
$lng = isset($_POST['coordinates_lng']) && is_numeric($_POST['coordinates_lng']) ? (float)$_POST['coordinates_lng'] : null;

if (!$id || empty($name)) {
    redirectWithFlash($returnUrl, 'error', 'Invalid purok record or missing name.');
}

// Verify ownership
$chk = $db->prepare("SELECT barangay_id FROM puroks WHERE id = ?");
$chk->execute([$id]);
$purok = $chk->fetch();
if (!$purok) {
    redirectWithFlash($returnUrl, 'error', 'Purok not found.');
}

if ($role === 'barangay_head' && (int)$purok['barangay_id'] !== (int)$user['barangay_id']) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to edit puroks outside your barangay.');
}

$barangayId = (int)$purok['barangay_id'];

// Check for duplicate purok name in this barangay
$chkDup = $db->prepare("SELECT id FROM puroks WHERE barangay_id = ? AND LOWER(name) = LOWER(?) AND id != ?");
$chkDup->execute([$barangayId, $name, $id]);
if ($chkDup->fetch()) {
    redirectWithFlash($returnUrl, 'error', "Another purok named '$name' already exists in this barangay.");
}

try {
    $stmt = $db->prepare("
        UPDATE puroks SET
            name = ?,
            hazard_types = ?,
            risk_level = ?,
            households = ?,
            population = ?,
            coordinates_lat = COALESCE(?, coordinates_lat),
            coordinates_lng = COALESCE(?, coordinates_lng)
        WHERE id = ?
    ");
    $stmt->execute([$name, $hazardTypes, $riskLevel, $households, $population, $lat, $lng, $id]);

    $barangayId = (int)$purok['barangay_id'];
    $db->prepare("
        UPDATE barangays SET
            population = (SELECT COALESCE(SUM(population), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)),
            total_households = (SELECT COALESCE(SUM(households), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL))
        WHERE id = ?
    ")->execute([$barangayId, $barangayId, $barangayId]);

    logSystemEvent('UPDATE_PUROK', 'Puroks', "Updated Purok '$name' (#$id)");

    redirectWithFlash($returnUrl, 'success', "Purok '$name' updated successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to update purok: ' . $e->getMessage());
}
