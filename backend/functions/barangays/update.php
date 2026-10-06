<?php
// ============================================================================
// Function: Barangays - Update Barangay Details
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/barangays.php'));

$id = (int)($_POST['id'] ?? 0);
if ($user['role'] !== 'icdrrmo') {
    if ($user['role'] === 'barangay_head' && $id === (int)$user['barangay_id']) {
        // Allowed to update own barangay
    } else {
        redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify this barangay.');
    }
}

$name = trim($_POST['name'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$contactPerson = ($contactPerson === '') ? null : $contactPerson;
$contactNumber = trim($_POST['contact_number'] ?? '');
$contactNumber = ($contactNumber === '') ? null : $contactNumber;
$population = (isset($_POST['population']) && $_POST['population'] !== '') ? max(0, (int)$_POST['population']) : null;
$households = (isset($_POST['total_households']) && $_POST['total_households'] !== '') ? max(0, (int)$_POST['total_households']) : ($population !== null ? (int)($population / 4.5) : null);
$riskLevel = !empty($_POST['risk_level']) ? trim($_POST['risk_level']) : null;
$radius = (isset($_POST['area_radius']) && $_POST['area_radius'] !== '') ? max(100, (int)$_POST['area_radius']) : null;
$lat = (isset($_POST['coordinates_lat']) && is_numeric($_POST['coordinates_lat'])) ? (float)$_POST['coordinates_lat'] : 8.228000;
$lng = (isset($_POST['coordinates_lng']) && is_numeric($_POST['coordinates_lng'])) ? (float)$_POST['coordinates_lng'] : 124.245200;

if (!$id || empty($name)) {
    redirectWithFlash($returnUrl, 'error', 'Barangay name is required.');
}

$check = $db->prepare("SELECT id FROM barangays WHERE LOWER(name) = LOWER(?) AND id != ?");
$check->execute([$name, $id]);
if ($check->fetch()) {
    redirectWithFlash($returnUrl, 'error', 'Another barangay with this name already exists.');
}

try {
    $stmt = $db->prepare("
        UPDATE barangays SET
            name = ?,
            contact_person = ?,
            contact_number = ?,
            population = ?,
            total_households = ?,
            risk_level = ?,
            area_radius = ?,
            coordinates_lat = ?,
            coordinates_lng = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $contactPerson, $contactNumber, $population, $households,
        $riskLevel, $radius, $lat, $lng, $id
    ]);

    logSystemEvent('UPDATE_BARANGAY', 'Barangays', "Updated information for Barangay $name (#$id)");

    redirectWithFlash($returnUrl, 'success', "Barangay {$name} updated successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to update barangay: ' . $e->getMessage());
}
