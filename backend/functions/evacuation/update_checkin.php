<?php
// ============================================================================
// Backend Functions: Evacuation - Update Sheltered Family Details
// Updates room/tent assignment, members count, medical alerts, and notes.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/evacuation.php'));

if (!in_array($user['role'], ['barangay_head', 'icdrrmo', 'responder'], true)) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid request method.');
}

$checkinId = (int)($_POST['checkin_id'] ?? 0);
$roomTent = trim($_POST['room_tent_number'] ?? '');
$specialNeeds = trim($_POST['special_medical_needs'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$membersCount = max(1, (int)($_POST['members_count'] ?? 1));
$seniorsCount = max(0, (int)($_POST['seniors_count'] ?? 0));
$minorsCount = max(0, (int)($_POST['minors_count'] ?? 0));
$pwdCount = max(0, (int)($_POST['pwd_count'] ?? 0));

if ($checkinId <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid check-in record ID.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid check-in record ID.');
}

try {
    $chkStmt = $db->prepare("
        SELECT c.*, ea.name AS center_name, ea.barangay_id, ea.capacity_individuals 
        FROM evacuation_family_checkins c
        JOIN evacuation_areas ea ON c.evacuation_area_id = ea.id
        WHERE c.id = ?
    ");
    $chkStmt->execute([$checkinId]);
    $checkin = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$checkin) {
        throw new Exception("Check-in record not found.");
    }

    if ($user['role'] === 'barangay_head' && (int)$checkin['barangay_id'] !== (int)$user['barangay_id']) {
        throw new Exception("You are not authorized to update evacuees outside your barangay jurisdiction.");
    }

    $evacAreaId = (int)$checkin['evacuation_area_id'];

    $updStmt = $db->prepare("
        UPDATE evacuation_family_checkins
        SET room_tent_number = ?, members_count = ?, seniors_count = ?,
            minors_count = ?, pwd_count = ?, special_medical_needs = ?, notes = ?
        WHERE id = ?
    ");
    $updStmt->execute([
        $roomTent, $membersCount, $seniorsCount,
        $minorsCount, $pwdCount, $specialNeeds, $notes, $checkinId
    ]);

    // Recalculate shelter headcount
    $sumStmt = $db->prepare("
        SELECT COALESCE(SUM(members_count), 0) AS total_ind
        FROM evacuation_family_checkins
        WHERE evacuation_area_id = ? AND status = 'Sheltered'
    ");
    $sumStmt->execute([$evacAreaId]);
    $newShelteredCount = (int)$sumStmt->fetchColumn();

    $eaStmt = $db->prepare("SELECT * FROM evacuation_areas WHERE id = ?");
    $eaStmt->execute([$evacAreaId]);
    $evacArea = $eaStmt->fetch(PDO::FETCH_ASSOC);

    $newStatus = $evacArea['status'];
    if ((int)$evacArea['capacity_individuals'] > 0 && $newShelteredCount >= (int)$evacArea['capacity_individuals']) {
        $newStatus = 'At Capacity';
    } elseif ($newStatus === 'At Capacity' && $newShelteredCount < (int)$evacArea['capacity_individuals']) {
        $newStatus = 'Open / Active';
    }

    $updEa = $db->prepare("
        UPDATE evacuation_areas
        SET current_evacuees_count = ?, status = ?
        WHERE id = ?
    ");
    $updEa->execute([$newShelteredCount, $newStatus, $evacAreaId]);

    $msg = "Family {$checkin['family_name']} room and member details updated successfully!";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'sheltered_count' => $newShelteredCount,
            'status' => $newStatus
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
