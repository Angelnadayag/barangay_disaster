<?php
// ============================================================================
// Function: Evacuation Centers - Update Full Details
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to edit evacuation areas.');
}

$centerId = (int)($_POST['id'] ?? ($_POST['center_id'] ?? 0));
if ($centerId <= 0) {
    redirectWithFlash($returnUrl, 'error', 'Invalid evacuation center ID.');
}

// Check existing center
$chk = $db->prepare("SELECT * FROM evacuation_areas WHERE id = ?");
$chk->execute([$centerId]);
$existing = $chk->fetch();

if (!$existing) {
    redirectWithFlash($returnUrl, 'error', 'Evacuation shelter not found.');
}

if ($user['role'] === 'barangay_head' && (int)$existing['barangay_id'] !== (int)$user['barangay_id']) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify shelters outside your barangay.');
}

$name = trim($_POST['name'] ?? $existing['name']);
$address = trim($_POST['location_address'] ?? $existing['location_address']);
$type = trim($_POST['center_type'] ?? $existing['center_type']);
$status = trim($_POST['status'] ?? $existing['status']);
$allowedStatuses = ['Open / Active', 'Standby', 'At Capacity', 'Closed'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = $existing['status'];
}

$capInd = isset($_POST['capacity_individuals']) ? max(10, (int)$_POST['capacity_individuals']) : (int)$existing['capacity_individuals'];
$capFam = isset($_POST['capacity_families']) ? max(2, (int)$_POST['capacity_families']) : (int)$existing['capacity_families'];
$currentEvacuees = isset($_POST['current_evacuees_count']) ? max(0, (int)$_POST['current_evacuees_count']) : (int)$existing['current_evacuees_count'];

$accessibility = trim($_POST['accessibility'] ?? $existing['accessibility']);
$officer = trim($_POST['contact_officer'] ?? $existing['contact_officer']);
$contact = trim($_POST['contact_number'] ?? $existing['contact_number']);
$water = isset($_POST['has_potable_water']) ? 1 : 0;
$power = isset($_POST['has_electricity']) ? 1 : 0;
$medic = isset($_POST['has_medical_station']) ? 1 : 0;

$lat = !empty($_POST['coordinates_lat']) ? (float)$_POST['coordinates_lat'] : (float)$existing['coordinates_lat'];
$lng = !empty($_POST['coordinates_lng']) ? (float)$_POST['coordinates_lng'] : (float)$existing['coordinates_lng'];

if (empty($name) || empty($address)) {
    redirectWithFlash($returnUrl, 'error', 'Center name and address are required.');
}

try {
    $stmt = $db->prepare("
        UPDATE evacuation_areas SET
            name = ?,
            location_address = ?,
            center_type = ?,
            capacity_individuals = ?,
            capacity_families = ?,
            current_evacuees_count = ?,
            status = ?,
            accessibility = ?,
            has_potable_water = ?,
            has_electricity = ?,
            has_medical_station = ?,
            contact_officer = ?,
            contact_number = ?,
            coordinates_lat = ?,
            coordinates_lng = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $address, $type,
        $capInd, $capFam, $currentEvacuees,
        $status, $accessibility, $water, $power, $medic,
        $officer, $contact, $lat, $lng,
        $centerId
    ]);

    logSystemEvent('UPDATE_EVACUATION_CENTER', 'Evacuation', "Updated evacuation shelter #$centerId '$name' (Lat: $lat, Lng: $lng)");

    redirectWithFlash($returnUrl, 'success', "Evacuation shelter '$name' updated successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
