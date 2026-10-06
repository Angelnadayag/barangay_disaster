<?php
// ============================================================================
// Function: Barangays - Register Barangay
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/official_list.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/barangays.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO Admin can register barangays.');
}

$name = trim($_POST['name'] ?? '');

if (empty($name)) {
    redirectWithFlash($returnUrl, 'error', 'Please select an official barangay name.');
}

$check = $db->prepare("SELECT id FROM barangays WHERE LOWER(name) = LOWER(?)");
$check->execute([$name]);
if ($check->fetch()) {
    redirectWithFlash($returnUrl, 'error', "Barangay '{$name}' is already registered and cannot be added twice.");
}

$lat = isset($_POST['coordinates_lat']) && is_numeric($_POST['coordinates_lat']) ? (float)$_POST['coordinates_lat'] : null;
$lng = isset($_POST['coordinates_lng']) && is_numeric($_POST['coordinates_lng']) ? (float)$_POST['coordinates_lng'] : null;

if ($lat === null || $lng === null) {
    if (isset($officialBarangays[$name])) {
        $lat = $officialBarangays[$name]['lat'];
        $lng = $officialBarangays[$name]['lng'];
    } else {
        $lat = 8.228000;
        $lng = 124.245200;
    }
}

$contactPerson = null;
$contactNumber = null;
$population = null;
$households = null;
$riskLevel = null;
$radius = null;

try {
    $stmt = $db->prepare("
        INSERT INTO barangays (
            name, city, contact_person, contact_number, population, total_households,
            risk_level, area_radius, coordinates_lat, coordinates_lng, created_at
        ) VALUES (?, 'Iligan City', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $name, $contactPerson, $contactNumber, $population, $households,
        $riskLevel, $radius, $lat, $lng
    ]);
    $newId = $db->lastInsertId();

    logSystemEvent('CREATE_BARANGAY', 'Barangays', "Registered new Barangay $name (ID #$newId)");

    redirectWithFlash($returnUrl, 'success', "Barangay {$name} registered successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to create barangay: ' . $e->getMessage());
}
