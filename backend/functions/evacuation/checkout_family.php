<?php
// ============================================================================
// Backend Functions: Evacuation - Check-Out / Decamp Household Family
// Marks family as decamped or transferred, timestamps checkout, and
// recalculates center capacity and active headcounts.
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
$checkoutStatus = trim($_POST['status'] ?? 'Decamped / Returned Home');
if (!in_array($checkoutStatus, ['Decamped / Returned Home', 'Transferred'], true)) {
    $checkoutStatus = 'Decamped / Returned Home';
}
$checkoutNotes = trim($_POST['notes'] ?? '');

if ($checkinId <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid check-in record ID.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid check-in record ID.');
}

try {
    // 1. Fetch checkin record
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
        throw new Exception("You are not authorized to modify evacuees outside your barangay jurisdiction.");
    }

    if ($checkin['status'] !== 'Sheltered') {
        throw new Exception("Family {$checkin['family_name']} is already checked out ({$checkin['status']}).");
    }

    $evacAreaId = (int)$checkin['evacuation_area_id'];

    // 2. Update Checkin Record
    $updatedNotes = $checkin['notes'] ?? '';
    if (!empty($checkoutNotes)) {
        $updatedNotes = !empty($updatedNotes) ? $updatedNotes . "\nCheckout: " . $checkoutNotes : "Checkout: " . $checkoutNotes;
    }

    $updStmt = $db->prepare("
        UPDATE evacuation_family_checkins
        SET status = ?, checkout_time = NOW(), notes = ?
        WHERE id = ?
    ");
    $updStmt->execute([$checkoutStatus, $updatedNotes, $checkinId]);

    // 3. Recalculate shelter active headcount
    $sumStmt = $db->prepare("
        SELECT COALESCE(SUM(members_count), 0) AS total_ind
        FROM evacuation_family_checkins
        WHERE evacuation_area_id = ? AND status = 'Sheltered'
    ");
    $sumStmt->execute([$evacAreaId]);
    $newShelteredCount = (int)$sumStmt->fetchColumn();

    // Check status
    $eaStmt = $db->prepare("SELECT * FROM evacuation_areas WHERE id = ?");
    $eaStmt->execute([$evacAreaId]);
    $evacArea = $eaStmt->fetch(PDO::FETCH_ASSOC);

    $newStatus = $evacArea['status'];
    if ($newShelteredCount === 0 && $newStatus === 'Open / Active') {
        $newStatus = 'Standby';
    } elseif ($newShelteredCount < (int)$evacArea['capacity_individuals'] && $newStatus === 'At Capacity') {
        $newStatus = 'Open / Active';
    }

    $updEa = $db->prepare("
        UPDATE evacuation_areas
        SET current_evacuees_count = ?, status = ?
        WHERE id = ?
    ");
    $updEa->execute([$newShelteredCount, $newStatus, $evacAreaId]);

    logSystemEvent(
        'CHECKOUT_EVACUEE_FAMILY',
        'Evacuation',
        "Family {$checkin['family_name']} decamped from {$checkin['center_name']} ({$checkoutStatus}). Remaining sheltered: {$newShelteredCount} individuals."
    );

    $msg = "Family {$checkin['family_name']} marked as '{$checkoutStatus}'. Remaining camp headcount: {$newShelteredCount} individuals.";

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
