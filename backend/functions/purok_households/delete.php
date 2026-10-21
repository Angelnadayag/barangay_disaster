<?php
// ============================================================================
// Backend API: Purok Households - Delete / Archive Household Family
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
    echo json_encode(['success' => false, 'message' => 'Household record not found.']);
    exit;
}

try {
    // Soft-delete / archive
    $delStmt = $db->prepare("UPDATE purok_households SET status = 'archived' WHERE id = ?");
    $delStmt->execute([$id]);

    logSystemEvent('ARCHIVE_HOUSEHOLD', 'Puroks', "Archived Household Family '{$existing['family_name']}' (#{$existing['household_code']}) in {$existing['purok_name']}");

    $dyn = syncPurokDemographics($db, $existing['purok_id'], $existing['purok_name'], $existing['barangay_id']);

    echo json_encode([
        'success' => true,
        'message' => "Household family '{$existing['family_name']}' archived successfully.",
        'purok_id' => $dyn['purok_id'],
        'dynamic_households' => $dyn['households'],
        'dynamic_population' => $dyn['population']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to archive: ' . $e->getMessage()]);
}
exit;
