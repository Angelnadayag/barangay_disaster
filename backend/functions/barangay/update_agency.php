<?php
// ============================================================================
// Function: Barangay - Update Connected BDRRMC Agency Details
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$db = getDBConnection();
$barangayId = (int)$user['barangay_id'];

$returnUrl = $_POST['return_url'] ?? (BASE_URL . '/views/barangay/agency.php');

$contactPerson = trim($_POST['contact_person'] ?? '');
$contactNumber = trim($_POST['contact_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$officeAddress = trim($_POST['office_address'] ?? '');
$lat = (!empty($_POST['coordinates_lat']) && is_numeric($_POST['coordinates_lat'])) ? (float)$_POST['coordinates_lat'] : null;
$lng = (!empty($_POST['coordinates_lng']) && is_numeric($_POST['coordinates_lng'])) ? (float)$_POST['coordinates_lng'] : null;

if (empty($contactPerson) || empty($contactNumber)) {
    redirectWithFlash($returnUrl, 'error', 'Contact Person and Contact Number are required.');
}

try {
    // Check if an agency record exists for this barangay
    $chk = $db->prepare("SELECT id FROM agencies WHERE barangay_id = ? AND agency_type = 'BDRRMC'");
    $chk->execute([$barangayId]);
    $agencyId = $chk->fetchColumn();

    if ($agencyId) {
        $stmt = $db->prepare("
            UPDATE agencies 
            SET contact_person = ?, contact_number = ?, email = ?, office_address = ?,
                coordinates_lat = COALESCE(?, coordinates_lat),
                coordinates_lng = COALESCE(?, coordinates_lng)
            WHERE id = ?
        ");
        $stmt->execute([$contactPerson, $contactNumber, $email, $officeAddress, $lat, $lng, $agencyId]);
    } else {
        // Fetch Barangay Name
        $bStmt = $db->prepare("SELECT name, coordinates_lat, coordinates_lng FROM barangays WHERE id = ?");
        $bStmt->execute([$barangayId]);
        $bInfo = $bStmt->fetch();
        $bName = $bInfo['name'] ?? "Barangay #$barangayId";
        $defaultLat = $lat ?? ($bInfo['coordinates_lat'] ?? 8.228);
        $defaultLng = $lng ?? ($bInfo['coordinates_lng'] ?? 124.245);

        $ins = $db->prepare("
            INSERT INTO agencies (
                name, agency_type, barangay_id, contact_person, contact_number, email, office_address,
                coordinates_lat, coordinates_lng, status, created_at
            ) VALUES (?, 'BDRRMC', ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $ins->execute([
            "BDRRMC - $bName",
            $barangayId,
            $contactPerson,
            $contactNumber,
            $email,
            $officeAddress,
            $defaultLat,
            $defaultLng
        ]);
    }

    logSystemEvent(
        'UPDATE_AGENCY',
        'Agency',
        "Barangay Head updated BDRRMC Agency details for Barangay #$barangayId",
        $user['id'],
        $user['full_name'],
        $user['role']
    );

    redirectWithFlash($returnUrl, 'success', 'Agency information updated successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error updating agency: ' . $e->getMessage());
}
