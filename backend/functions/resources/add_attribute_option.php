<?php
// ============================================================================
// Function: Resources - Add Dynamic Attribute Option (Category, Brand, Supplier)
// Permanently stores dynamic resource attributes into dedicated database tables
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

$type = trim($_POST['type'] ?? '');
$name = trim($_POST['name'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Item name cannot be empty.']);
    exit;
}

$tableMap = [
    'category' => 'resource_categories',
    'brand'    => 'resource_brands',
    'supplier' => 'resource_suppliers'
];

if (!isset($tableMap[$type])) {
    echo json_encode(['success' => false, 'message' => 'Invalid attribute type specified.']);
    exit;
}

$tableName = $tableMap[$type];
$db = getDBConnection();

try {
    // Check if name already exists (case-insensitive)
    $chkStmt = $db->prepare("SELECT id, name FROM `{$tableName}` WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $chkStmt->execute([$name]);
    $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'already_exists' => true,
            'message' => ucfirst($type) . ' "' . $existing['name'] . '" already exists in database.'
        ]);
        exit;
    }

    $insStmt = $db->prepare("INSERT INTO `{$tableName}` (name) VALUES (?)");
    $insStmt->execute([$name]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'already_exists' => false,
        'message' => ucfirst($type) . ' "' . $name . '" saved to database successfully.'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
