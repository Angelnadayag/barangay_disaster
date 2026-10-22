<?php
// ============================================================================
// Backend Functions: Evacuation - Check-In Household Family
// Records household check-in, room/tent assignment, vulnerability profile,
// auto-updates shelter headcount, and logs initial relief kit disbursement.
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

$evacAreaId = (int)($_POST['evacuation_area_id'] ?? 0);
$householdId = (int)($_POST['household_id'] ?? 0);
$roomTent = trim($_POST['room_tent_number'] ?? '');
$specialNeeds = trim($_POST['special_medical_needs'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$giveInitialRelief = !empty($_POST['give_initial_relief']) ? 1 : 0;

if ($evacAreaId <= 0 || $householdId <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid evacuation center and household family.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Please select a valid evacuation center and household family.');
}

try {
    // 1. Fetch Evacuation Center
    $eaStmt = $db->prepare("SELECT ea.*, b.name AS barangay_name FROM evacuation_areas ea JOIN barangays b ON ea.barangay_id = b.id WHERE ea.id = ?");
    $eaStmt->execute([$evacAreaId]);
    $evacArea = $eaStmt->fetch(PDO::FETCH_ASSOC);

    if (!$evacArea) {
        throw new Exception("Evacuation center not found.");
    }

    if ($user['role'] === 'barangay_head' && (int)$evacArea['barangay_id'] !== (int)$user['barangay_id']) {
        throw new Exception("You are not authorized to check in families to shelters outside your barangay.");
    }

    // 2. Fetch Household
    $hhStmt = $db->prepare("SELECT * FROM purok_households WHERE id = ?");
    $hhStmt->execute([$householdId]);
    $household = $hhStmt->fetch(PDO::FETCH_ASSOC);

    if (!$household) {
        throw new Exception("Household family record not found.");
    }

    // 3. Verify household is not already actively sheltered
    $chkStmt = $db->prepare("
        SELECT c.*, ea.name AS center_name 
        FROM evacuation_family_checkins c
        JOIN evacuation_areas ea ON c.evacuation_area_id = ea.id
        WHERE c.household_id = ? AND c.status = 'Sheltered'
        LIMIT 1
    ");
    $chkStmt->execute([$householdId]);
    $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        throw new Exception("Household {$household['family_name']} ({$household['household_code']}) is already actively sheltered at {$existing['center_name']}.");
    }

    // Demographic counts (Use submitted overrides or fall back to household profile)
    $membersCount = isset($_POST['members_count']) && $_POST['members_count'] !== ''
        ? max(1, (int)$_POST['members_count'])
        : max(1, (int)$household['total_members']);

    $seniorsCount = isset($_POST['seniors_count']) && $_POST['seniors_count'] !== ''
        ? max(0, (int)$_POST['seniors_count'])
        : max(0, (int)$household['seniors_count']);

    $minorsCount = isset($_POST['minors_count']) && $_POST['minors_count'] !== ''
        ? max(0, (int)$_POST['minors_count'])
        : max(0, (int)$household['minors_count']);

    $pwdCount = isset($_POST['pwd_count']) && $_POST['pwd_count'] !== ''
        ? max(0, (int)$_POST['pwd_count'])
        : max(0, (int)$household['pwd_count']);

    $reliefPacksGiven = $giveInitialRelief ? 1 : 0;
    $checkedInBy = $user['full_name'] ?? 'Staff Officer';

    // 4. Insert Check-In
    $insStmt = $db->prepare("
        INSERT INTO evacuation_family_checkins (
            evacuation_area_id, household_id, family_name, household_code,
            head_full_name, head_phone, purok_name, room_tent_number,
            members_count, seniors_count, minors_count, pwd_count,
            special_medical_needs, relief_packs_given, hot_meals_claimed,
            status, checkin_time, checked_in_by, notes
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, 0,
            'Sheltered', NOW(), ?, ?
        )
    ");
    $insStmt->execute([
        $evacAreaId, $householdId, $household['family_name'], $household['household_code'],
        $household['head_full_name'], $household['head_phone'], $household['purok_name'], $roomTent,
        $membersCount, $seniorsCount, $minorsCount, $pwdCount,
        $specialNeeds, $reliefPacksGiven,
        $checkedInBy, $notes
    ]);

    $checkinId = (int)$db->lastInsertId();

    // 5. If initial relief given, log it
    if ($giveInitialRelief) {
        $logStmt = $db->prepare("
            INSERT INTO evacuation_relief_logs (
                checkin_id, evacuation_area_id, household_id, family_name,
                item_type, quantity, unit, distributed_by, notes, created_at
            ) VALUES (
                ?, ?, ?, ?,
                'Family Food Pack (Intake)', 1, 'pack', ?, 'Provided during check-in entry', NOW()
            )
        ");
        $logStmt->execute([
            $checkinId, $evacAreaId, $householdId, $household['family_name'], $checkedInBy
        ]);
    }

    // 6. Recalculate shelter total sheltered evacuees
    $sumStmt = $db->prepare("
        SELECT COALESCE(SUM(members_count), 0) AS total_ind
        FROM evacuation_family_checkins
        WHERE evacuation_area_id = ? AND status = 'Sheltered'
    ");
    $sumStmt->execute([$evacAreaId]);
    $newShelteredCount = (int)$sumStmt->fetchColumn();

    // Determine status
    $newStatus = $evacArea['status'];
    if (in_array($newStatus, ['Standby', 'Closed'], true)) {
        $newStatus = 'Open / Active';
    }
    if ((int)$evacArea['capacity_individuals'] > 0 && $newShelteredCount >= (int)$evacArea['capacity_individuals']) {
        $newStatus = 'At Capacity';
    } elseif ($newStatus === 'At Capacity' && $newShelteredCount < (int)$evacArea['capacity_individuals']) {
        $newStatus = 'Open / Active';
    }

    $updStmt = $db->prepare("
        UPDATE evacuation_areas 
        SET current_evacuees_count = ?, status = ?
        WHERE id = ?
    ");
    $updStmt->execute([$newShelteredCount, $newStatus, $evacAreaId]);

    // 7. System Audit Log
    logSystemEvent(
        'CHECKIN_EVACUEE_FAMILY',
        'Evacuation',
        "Family {$household['family_name']} ({$membersCount} members) checked in at {$evacArea['name']} by {$checkedInBy}."
    );

    $msg = "Family {$household['family_name']} ({$household['household_code']}) checked in successfully! Total sheltered: {$newShelteredCount} individuals.";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'checkin_id' => $checkinId,
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
