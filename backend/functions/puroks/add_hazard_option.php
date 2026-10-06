<?php
// ============================================================================
// Function: Puroks - Add Hazard Type Option to Database
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

$name = trim($_POST['name'] ?? '');
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Hazard type name cannot be empty.']);
    exit;
}

$db = getDBConnection();

try {
    // Check if already exists
    $chk = $db->prepare("SELECT id, name FROM hazard_type_options WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $chk->execute([$name]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'message' => 'Hazard option already exists in database.'
        ]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO hazard_type_options (name) VALUES (?)");
    $stmt->execute([$name]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'message' => "Hazard type '$name' saved to database."
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
