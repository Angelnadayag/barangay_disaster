<?php
// ============================================================================
// Function: Puroks - Create Purok
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
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to manage puroks.');
}

$barangayId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : (int)($_POST['barangay_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$hazardTypes = trim($_POST['hazard_types'] ?? 'Flood, Strong Winds');
$riskLevel = trim($_POST['risk_level'] ?? 'Moderate');
$households = max(0, (int)($_POST['households'] ?? 0));
$population = max(0, (int)($_POST['population'] ?? ($households * 4.5)));
$lat = isset($_POST['coordinates_lat']) && is_numeric($_POST['coordinates_lat']) ? (float)$_POST['coordinates_lat'] : null;
$lng = isset($_POST['coordinates_lng']) && is_numeric($_POST['coordinates_lng']) ? (float)$_POST['coordinates_lng'] : null;

if (!$barangayId) {
    redirectWithFlash($returnUrl, 'error', 'Barangay assignment missing.');
}
if (empty($name)) {
    redirectWithFlash($returnUrl, 'error', 'Purok name is required.');
}

// Check for duplicate purok name in this barangay
$chkDup = $db->prepare("SELECT id FROM puroks WHERE barangay_id = ? AND LOWER(name) = LOWER(?)");
$chkDup->execute([$barangayId, $name]);
if ($chkDup->fetch()) {
    redirectWithFlash($returnUrl, 'error', "Purok '$name' already exists in this barangay.");
}

// Default coordinates to barangay coordinates if omitted
if ($lat === null || $lng === null) {
    $bCoord = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM barangays WHERE id = ?");
    $bCoord->execute([$barangayId]);
    $row = $bCoord->fetch();
    $lat = $row['coordinates_lat'] ?? 8.228000;
    $lng = $row['coordinates_lng'] ?? 124.245200;
}

try {
    $stmt = $db->prepare("
        INSERT INTO puroks (barangay_id, name, hazard_types, risk_level, households, population, coordinates_lat, coordinates_lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$barangayId, $name, $hazardTypes, $riskLevel, $households, $population, $lat, $lng]);
    $newId = $db->lastInsertId();

    // Update barangay total counts
    $db->prepare("
        UPDATE barangays SET
            population = (SELECT COALESCE(SUM(population), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)),
            total_households = (SELECT COALESCE(SUM(households), 0) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL))
        WHERE id = ?
    ")->execute([$barangayId, $barangayId, $barangayId]);

    logSystemEvent('CREATE_PUROK', 'Puroks', "Created Purok '$name' (#$newId) in Barangay #$barangayId");

    redirectWithFlash($returnUrl, 'success', "Purok '$name' created successfully.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to create purok: ' . $e->getMessage());
}
