<?php
// ============================================================================
// Function: Evacuation Centers - Register Center
// Supports ICDRRMO Admin & Barangay Head (Captain)
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to register evacuation areas.');
}

$barangayId = ($user['role'] === 'barangay_head') 
    ? (int)$user['barangay_id'] 
    : (int)($_POST['barangay_id'] ?? 0);

$name = trim($_POST['name'] ?? '');
$address = trim($_POST['location_address'] ?? '');
$type = trim($_POST['center_type'] ?? 'School Gym');
$status = trim($_POST['status'] ?? 'Standby');
$allowedStatuses = ['Open / Active', 'Standby', 'At Capacity', 'Closed'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'Standby';
}

$capInd = max(10, (int)($_POST['capacity_individuals'] ?? 500));
$capFam = !empty($_POST['capacity_families']) 
    ? max(2, (int)$_POST['capacity_families']) 
    : max(2, (int)round($capInd / 4.5));

$accessibility = trim($_POST['accessibility'] ?? 'Accessible (All Vehicles)');
$officer = trim($_POST['contact_officer'] ?? '');
$contact = trim($_POST['contact_number'] ?? '');
$water = isset($_POST['has_potable_water']) ? 1 : 0;
$power = isset($_POST['has_electricity']) ? 1 : 0;
$medic = isset($_POST['has_medical_station']) ? 1 : 0;

$lat = !empty($_POST['coordinates_lat']) ? (float)$_POST['coordinates_lat'] : 8.228000;
$lng = !empty($_POST['coordinates_lng']) ? (float)$_POST['coordinates_lng'] : 124.245200;

if (empty($name) || empty($barangayId) || empty($address)) {
    redirectWithFlash($returnUrl, 'error', 'Center name, barangay, and address are required.');
}

try {
    $stmt = $db->prepare("
        INSERT INTO evacuation_areas (
            name, barangay_id, location_address, center_type,
            capacity_individuals, capacity_families, current_evacuees_count,
            status, accessibility, has_potable_water, has_electricity, has_medical_station,
            contact_officer, contact_number, coordinates_lat, coordinates_lng, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $name, $barangayId, $address, $type,
        $capInd, $capFam,
        $status, $accessibility, $water, $power, $medic,
        $officer, $contact, $lat, $lng
    ]);

    $newId = $db->lastInsertId();
    logSystemEvent('CREATE_EVACUATION_CENTER', 'Evacuation', "Registered evacuation shelter: '$name' (Lat: $lat, Lng: $lng)");

    redirectWithFlash($returnUrl, 'success', "Evacuation shelter '$name' registered successfully with pinned coordinates.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
