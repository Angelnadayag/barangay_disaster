<?php
// ============================================================================
// Backend API: Purok Households - Remove Family Member
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
$memberId = (int)($_POST['member_id'] ?? 0);

if ($householdId <= 0 || $memberId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

$db = getDBConnection();

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

$memStmt = $db->prepare("SELECT * FROM household_members WHERE id = ? AND household_id = ?");
$memStmt->execute([$memberId, $householdId]);
$member = $memStmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Member not found.']);
    exit;
}

try {
    $db->beginTransaction();

    // Delete member
    $del = $db->prepare("DELETE FROM household_members WHERE id = ?");
    $del->execute([$memberId]);

    // If deleted member was authorized rep, designate first remaining member
    if ((int)$member['is_authorized_rep'] === 1) {
        $remStmt = $db->prepare("SELECT * FROM household_members WHERE household_id = ? ORDER BY id ASC LIMIT 1");
        $remStmt->execute([$householdId]);
        $nextRep = $remStmt->fetch(PDO::FETCH_ASSOC);
        if ($nextRep) {
            $updRep = $db->prepare("UPDATE household_members SET is_authorized_rep = 1 WHERE id = ?");
            $updRep->execute([$nextRep['id']]);

            $updHh = $db->prepare("
                UPDATE purok_households 
                SET head_full_name = ?, head_relation = ?, head_gender = ?, head_age = ?
                WHERE id = ?
            ");
            $updHh->execute([$nextRep['full_name'], $nextRep['relationship'], $nextRep['gender'], $nextRep['age'], $householdId]);
        }
    }

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

    logSystemEvent('DELETE_HOUSEHOLD_MEMBER', 'Puroks', "Removed member '{$member['full_name']}' from {$household['family_name']}");

    $dyn = syncPurokDemographics($db, $household['purok_id'], $household['purok_name'], $household['barangay_id']);

    echo json_encode([
        'success' => true,
        'message' => "Family member '{$member['full_name']}' removed.",
        'total_members' => (int)$counts['total_members'],
        'purok_id' => $dyn['purok_id'],
        'dynamic_households' => $dyn['households'],
        'dynamic_population' => $dyn['population']
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error removing member: ' . $e->getMessage()]);
}
exit;
