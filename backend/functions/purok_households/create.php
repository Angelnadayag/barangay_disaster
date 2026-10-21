<?php
// ============================================================================
// Backend API: Purok Households - Create Household Family
// Registers a new household family with 1 authorized/recognized head.
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

$db = getDBConnection();
$barangayId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : (int)($_POST['barangay_id'] ?? $user['barangay_id'] ?? 12);

$purokName = trim($_POST['purok_name'] ?? '');
$purokId = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : 0;
$familyName = trim($_POST['family_name'] ?? '');
$householdCode = trim($_POST['household_code'] ?? '');

// Authorized Representative (1 per household)
$headFullName = trim($_POST['head_full_name'] ?? '');
$headRelation = trim($_POST['head_relation'] ?? 'Household Head');
$headPhone = trim($_POST['head_phone'] ?? '');
$headGender = trim($_POST['head_gender'] ?? 'Male');
$headAge = !empty($_POST['head_age']) ? (int)$_POST['head_age'] : 35;
$authorizedIdType = trim($_POST['authorized_id_type'] ?? 'PhilSys National ID');
$authorizedIdNumber = trim($_POST['authorized_id_number'] ?? '');
$isAuthorizedRep = 1; // Strictly 1 authorized representative per household

$houseNumber = trim($_POST['house_number'] ?? '');
$totalMembers = max(1, (int)($_POST['total_members'] ?? 4));
$seniorsCount = max(0, (int)($_POST['seniors_count'] ?? 0));
$minorsCount = max(0, (int)($_POST['minors_count'] ?? 0));
$pwdCount = max(0, (int)($_POST['pwd_count'] ?? 0));
$housingType = trim($_POST['housing_type'] ?? 'Concrete');
$economicStatus = trim($_POST['economic_status'] ?? 'Indigent / Low Income');
$evacPriority = trim($_POST['evacuation_priority'] ?? ($pwdCount > 0 ? 'High Priority' : ($seniorsCount > 0 ? 'Priority' : 'Standard')));
$emergencyNotes = trim($_POST['emergency_notes'] ?? '');

if (empty($purokName)) {
    echo json_encode(['success' => false, 'message' => 'Purok assignment is required.']);
    exit;
}
if (empty($familyName)) {
    echo json_encode(['success' => false, 'message' => 'Family Name (e.g. Mendoza Family) is required.']);
    exit;
}
if (empty($headFullName)) {
    echo json_encode(['success' => false, 'message' => 'Authorized Representative / Household Head name is required.']);
    exit;
}
if (empty($headPhone)) {
    echo json_encode(['success' => false, 'message' => 'Emergency contact number for authorized head is required.']);
    exit;
}

// Generate household code if empty
if (empty($householdCode)) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM purok_households WHERE barangay_id = ?");
    $countStmt->execute([$barangayId]);
    $currentCount = (int)$countStmt->fetchColumn() + 1;
    $pNum = preg_replace('/[^0-9]/', '', $purokName) ?: '01';
    $householdCode = sprintf("HH-%02d-%03d", (int)$pNum, $currentCount);
}

// Check for duplicate household code
$dupStmt = $db->prepare("SELECT id FROM purok_households WHERE barangay_id = ? AND household_code = ?");
$dupStmt->execute([$barangayId, $householdCode]);
if ($dupStmt->fetch()) {
    $householdCode .= '-' . rand(10, 99);
}

try {
    $stmt = $db->prepare("
        INSERT INTO purok_households (
            barangay_id, purok_id, purok_name, household_code, family_name,
            head_full_name, head_relation, head_phone, head_gender, head_age,
            is_authorized_rep, recognition_status, authorized_id_type, authorized_id_number,
            house_number, total_members, seniors_count, minors_count, pwd_count,
            housing_type, economic_status, evacuation_priority, emergency_notes, status
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            1, 'Recognized & Authorized', ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 'active'
        )
    ");
    $stmt->execute([
        $barangayId, $purokId ?: null, $purokName, $householdCode, $familyName,
        $headFullName, $headRelation, $headPhone, $headGender, $headAge,
        $authorizedIdType, $authorizedIdNumber,
        $houseNumber, $totalMembers, $seniorsCount, $minorsCount, $pwdCount,
        $housingType, $economicStatus, $evacPriority, $emergencyNotes
    ]);
    $newId = (int)$db->lastInsertId();

    // Insert 1 Authorized Head into household_members
    $memStmt = $db->prepare("
        INSERT INTO household_members (
            household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd, health_conditions
        ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
    ");
    $isSenior = ($headAge >= 60) ? 1 : 0;
    $isMinor = ($headAge < 18) ? 1 : 0;
    $isPwd = ($pwdCount > 0 && $headAge > 50) ? 1 : 0;
    $memStmt->execute([
        $newId, $headFullName, "$headRelation (Authorized Rep)", $headGender, $headAge,
        $isSenior, $isMinor, $isPwd, ($isSenior ? 'Hypertension maintenance' : 'None')
    ]);

    // Parse additional members if submitted
    $extraMembersJson = $_POST['members_json'] ?? '';
    if (!empty($extraMembersJson)) {
        $extraMembers = json_decode($extraMembersJson, true);
        if (is_array($extraMembers)) {
            $extraStmt = $db->prepare("
                INSERT INTO household_members (
                    household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd, health_conditions
                ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
            ");
            foreach ($extraMembers as $em) {
                $emName = trim($em['full_name'] ?? '');
                if (empty($emName)) continue;
                $emRel = trim($em['relationship'] ?? 'Family Member');
                $emGen = trim($em['gender'] ?? 'Male');
                $emAge = (int)($em['age'] ?? 20);
                $emSenior = ($emAge >= 60 || !empty($em['is_senior'])) ? 1 : 0;
                $emMinor = ($emAge < 18 || !empty($em['is_minor'])) ? 1 : 0;
                $emPwd = !empty($em['is_pwd']) ? 1 : 0;
                $emHealth = trim($em['health_conditions'] ?? 'None');
                $extraStmt->execute([$newId, $emName, $emRel, $emGen, $emAge, $emSenior, $emMinor, $emPwd, $emHealth]);
            }
        }
    }

    logSystemEvent('REGISTER_HOUSEHOLD', 'Puroks', "Registered Household Family '$familyName' (#$householdCode) in $purokName with Authorized Head: $headFullName");

    $dyn = syncPurokDemographics($db, $purokId, $purokName, $barangayId);

    echo json_encode([
        'success' => true,
        'message' => "Household family '$familyName' successfully registered in $purokName with authorized representative $headFullName.",
        'household_id' => $newId,
        'household_code' => $householdCode,
        'purok_id' => $dyn['purok_id'],
        'dynamic_households' => $dyn['households'],
        'dynamic_population' => $dyn['population']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error registering household: ' . $e->getMessage()]);
}
exit;
