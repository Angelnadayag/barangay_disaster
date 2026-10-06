<?php
// ============================================================================
// Function: Puroks - Delete Hazard Type Option from Database
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$user = getCurrentUser();
$role = $user['role'];
if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');

if (!$id && empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid hazard type identifier.']);
    exit;
}

$db = getDBConnection();

try {
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM hazard_type_options WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->prepare("DELETE FROM hazard_type_options WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$name]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Hazard type option removed from database.'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
