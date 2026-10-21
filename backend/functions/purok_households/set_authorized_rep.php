<?php
// ============================================================================
// Backend API: Purok Households - Set 1 Authorized Representative
// Updates the 1 recognized/authorized representative for a household family.
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

    // Reset all members to 0
    $clear = $db->prepare("UPDATE household_members SET is_authorized_rep = 0 WHERE household_id = ?");
    $clear->execute([$householdId]);

    // Set designated member to 1
    $setRep = $db->prepare("UPDATE household_members SET is_authorized_rep = 1 WHERE id = ?");
    $setRep->execute([$memberId]);

    // Update household head details in purok_households
    $updHh = $db->prepare("
        UPDATE purok_households 
        SET head_full_name = ?, head_relation = ?, head_gender = ?, head_age = ?
        WHERE id = ?
    ");
    $cleanRel = preg_replace('/\s*\(Authorized.*?\)/i', '', $member['relationship']);
    $updHh->execute([$member['full_name'], $cleanRel, $member['gender'], $member['age'], $householdId]);

    $db->commit();

    logSystemEvent('SET_AUTHORIZED_REP', 'Puroks', "Designated '{$member['full_name']}' as Authorized Representative for {$household['family_name']}");

    echo json_encode([
        'success' => true,
        'message' => "'{$member['full_name']}' is now the 1 Authorized Representative.",
        'head_full_name' => $member['full_name'],
        'head_relation' => $cleanRel
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error updating representative: ' . $e->getMessage()]);
}
exit;
