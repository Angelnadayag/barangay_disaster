<?php
// ============================================================================
// Function: Puroks - Add Cluster Option to Database
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
    echo json_encode(['success' => false, 'message' => 'Cluster name cannot be empty.']);
    exit;
}

$barangayId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : null;
$db = getDBConnection();

try {
    // Check if already exists for this barangay or global
    $chk = $db->prepare("SELECT id, name FROM purok_cluster_options WHERE LOWER(name) = LOWER(?) AND (barangay_id = ? OR barangay_id IS NULL) LIMIT 1");
    $chk->execute([$name, $barangayId]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'message' => 'Option already exists in database.'
        ]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO purok_cluster_options (barangay_id, name) VALUES (?, ?)");
    $stmt->execute([$barangayId, $name]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'message' => "Purok cluster '$name' saved to database."
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
