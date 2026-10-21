<?php
// ============================================================================
// Backend API: Purok Households - Update Household Family
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$user = getCurrentUser();
$role = $user['role'];
if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: insufficient permissions.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Household ID missing.']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM purok_households WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    echo json_encode(['success' => false, 'message' => 'Household not found.']);
    exit;
}

$familyName = trim($_POST['family_name'] ?? $existing['family_name']);
$headFullName = trim($_POST['head_full_name'] ?? $existing['head_full_name']);
$headRelation = trim($_POST['head_relation'] ?? $existing['head_relation']);
$headPhone = trim($_POST['head_phone'] ?? $existing['head_phone']);
$headGender = trim($_POST['head_gender'] ?? $existing['head_gender']);
$headAge = !empty($_POST['head_age']) ? (int)$_POST['head_age'] : $existing['head_age'];
$authorizedIdType = trim($_POST['authorized_id_type'] ?? $existing['authorized_id_type']);
$authorizedIdNumber = trim($_POST['authorized_id_number'] ?? $existing['authorized_id_number']);
$houseNumber = trim($_POST['house_number'] ?? $existing['house_number']);
$totalMembers = max(1, (int)($_POST['total_members'] ?? $existing['total_members']));
$seniorsCount = max(0, (int)($_POST['seniors_count'] ?? $existing['seniors_count']));
$minorsCount = max(0, (int)($_POST['minors_count'] ?? $existing['minors_count']));
$pwdCount = max(0, (int)($_POST['pwd_count'] ?? $existing['pwd_count']));
$housingType = trim($_POST['housing_type'] ?? $existing['housing_type']);
$economicStatus = trim($_POST['economic_status'] ?? $existing['economic_status']);
$evacPriority = trim($_POST['evacuation_priority'] ?? $existing['evacuation_priority']);
$emergencyNotes = trim($_POST['emergency_notes'] ?? $existing['emergency_notes']);

try {
    $upStmt = $db->prepare("
        UPDATE purok_households SET
            family_name = ?,
            head_full_name = ?,
            head_relation = ?,
            head_phone = ?,
            head_gender = ?,
            head_age = ?,
            authorized_id_type = ?,
            authorized_id_number = ?,
            house_number = ?,
            total_members = ?,
            seniors_count = ?,
            minors_count = ?,
            pwd_count = ?,
            housing_type = ?,
            economic_status = ?,
            evacuation_priority = ?,
            emergency_notes = ?
        WHERE id = ?
    ");
    $upStmt->execute([
        $familyName, $headFullName, $headRelation, $headPhone, $headGender, $headAge,
        $authorizedIdType, $authorizedIdNumber, $houseNumber,
        $totalMembers, $seniorsCount, $minorsCount, $pwdCount,
        $housingType, $economicStatus, $evacPriority, $emergencyNotes,
        $id
    ]);

    // Update or ensure 1 authorized head in household_members
    $chkHead = $db->prepare("SELECT id FROM household_members WHERE household_id = ? AND is_authorized_rep = 1 LIMIT 1");
    $chkHead->execute([$id]);
    $headMemberId = $chkHead->fetchColumn();
    if ($headMemberId) {
        $db->prepare("
            UPDATE household_members SET
                full_name = ?,
                relationship = ?,
                gender = ?,
                age = ?,
                is_senior = ?,
                is_minor = ?,
                is_pwd = ?
            WHERE id = ?
        ")->execute([
            $headFullName, "$headRelation (Authorized Rep)", $headGender, $headAge,
            ($headAge >= 60 ? 1 : 0), ($headAge < 18 ? 1 : 0), ($pwdCount > 0 && $headAge > 50 ? 1 : 0),
            $headMemberId
        ]);
    } else {
        $db->prepare("
            INSERT INTO household_members (household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
        ")->execute([
            $id, $headFullName, "$headRelation (Authorized Rep)", $headGender, $headAge,
            ($headAge >= 60 ? 1 : 0), ($headAge < 18 ? 1 : 0), ($pwdCount > 0 && $headAge > 50 ? 1 : 0)
        ]);
    }

    logSystemEvent('UPDATE_HOUSEHOLD', 'Puroks', "Updated Household Family '$familyName' (#{$existing['household_code']}) in {$existing['purok_name']}");

    $dyn = syncPurokDemographics($db, $existing['purok_id'], $existing['purok_name'], $existing['barangay_id']);

    echo json_encode([
        'success' => true,
        'message' => "Household '$familyName' updated successfully.",
        'purok_id' => $dyn['purok_id'],
        'dynamic_households' => $dyn['households'],
        'dynamic_population' => $dyn['population']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
}
exit;
