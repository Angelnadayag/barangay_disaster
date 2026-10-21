<?php
// ============================================================================
// Backend API: Purok Households - Manually Add Family Member
// Allows manually adding an individual member to a household roster.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user = getCurrentUser();
$role = $user['role'];

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: insufficient permissions.']);
    exit;
}

$householdId = (int)($_POST['household_id'] ?? 0);
$fullName = trim($_POST['full_name'] ?? '');
$relationship = trim($_POST['relationship'] ?? 'Family Member');
$gender = trim($_POST['gender'] ?? 'Male');
$age = !empty($_POST['age']) ? (int)$_POST['age'] : 20;
$isAuthorizedRep = !empty($_POST['is_authorized_rep']) ? 1 : 0;
$healthConditions = trim($_POST['health_conditions'] ?? 'None');

if ($householdId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid household ID is required.']);
    exit;
}

if (empty($fullName)) {
    echo json_encode(['success' => false, 'message' => 'Member full name is required.']);
    exit;
}

$db = getDBConnection();

// Verify household belongs to user's jurisdiction
$hhStmt = $db->prepare("SELECT * FROM purok_households WHERE id = ?");
$hhStmt->execute([$householdId]);
$household = $hhStmt->fetch(PDO::FETCH_ASSOC);

if (!$household) {
    echo json_encode(['success' => false, 'message' => 'Household not found.']);
    exit;
}

if ($role === 'barangay_head' && (int)$household['barangay_id'] !== (int)$user['barangay_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized jurisdiction.']);
    exit;
}

try {
    $db->beginTransaction();

    // If designated as 1 Authorized Representative, reset other members and update household
    if ($isAuthorizedRep === 1) {
        $clearStmt = $db->prepare("UPDATE household_members SET is_authorized_rep = 0 WHERE household_id = ?");
        $clearStmt->execute([$householdId]);

        $updHh = $db->prepare("
            UPDATE purok_households 
            SET head_full_name = ?, head_relation = ?, head_gender = ?, head_age = ?
            WHERE id = ?
        ");
        $updHh->execute([$fullName, $relationship, $gender, $age, $householdId]);
    }

    $isSenior = ($age >= 60) ? 1 : 0;
    $isMinor = ($age < 18) ? 1 : 0;
    $isPwd = (stripos($healthConditions, 'pwd') !== false || stripos($healthConditions, 'disability') !== false) ? 1 : 0;

    $insStmt = $db->prepare("
        INSERT INTO household_members (
            household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd, health_conditions
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insStmt->execute([
        $householdId, $fullName, $relationship, $gender, $age, $isAuthorizedRep, $isSenior, $isMinor, $isPwd, $healthConditions
    ]);
    $newMemberId = (int)$db->lastInsertId();

    // Recalculate member totals
    $cntStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_members,
            COALESCE(SUM(CASE WHEN age >= 60 THEN 1 ELSE 0 END), 0) as seniors_count,
            COALESCE(SUM(CASE WHEN age < 18 THEN 1 ELSE 0 END), 0) as minors_count
        FROM household_members 
        WHERE household_id = ?
    ");
    $cntStmt->execute([$householdId]);
    $counts = $cntStmt->fetch(PDO::FETCH_ASSOC);

    $updTotals = $db->prepare("
        UPDATE purok_households 
        SET total_members = ?, seniors_count = ?, minors_count = ?
        WHERE id = ?
    ");
    $updTotals->execute([
        $counts['total_members'], $counts['seniors_count'], $counts['minors_count'], $householdId
    ]);

    $db->commit();

    logSystemEvent('ADD_HOUSEHOLD_MEMBER', 'Puroks', "Added family member '$fullName' ($relationship) to {$household['family_name']}");

    $dyn = syncPurokDemographics($db, $household['purok_id'], $household['purok_name'], $household['barangay_id']);

    echo json_encode([
        'success' => true,
        'message' => "Family member '$fullName' added successfully.",
        'member' => [
            'id' => $newMemberId,
            'household_id' => $householdId,
            'full_name' => $fullName,
            'relationship' => $relationship,
            'gender' => $gender,
            'age' => $age,
            'is_authorized_rep' => $isAuthorizedRep,
            'is_senior' => $isSenior,
            'is_minor' => $isMinor,
            'is_pwd' => $isPwd,
            'health_conditions' => $healthConditions
        ],
        'total_members' => (int)$counts['total_members'],
        'purok_id' => $dyn['purok_id'],
        'dynamic_households' => $dyn['households'],
        'dynamic_population' => $dyn['population']
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error adding family member: ' . $e->getMessage()]);
}
exit;
