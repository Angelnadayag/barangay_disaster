<?php
// ============================================================================
// Backend API: Purok Households - Get Single Household Family Details
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Household ID missing.']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM purok_households WHERE id = ?");
$stmt->execute([$id]);
$hh = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hh) {
    echo json_encode(['success' => false, 'message' => 'Household not found.']);
    exit;
}

$memStmt = $db->prepare("SELECT * FROM household_members WHERE household_id = ? ORDER BY is_authorized_rep DESC, age DESC");
$memStmt->execute([$id]);
$hh['members'] = $memStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'household' => $hh]);
exit;
