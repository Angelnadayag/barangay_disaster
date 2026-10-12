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

$purokId = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : null;
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : (!empty($user['barangay_id']) ? (int)$user['barangay_id'] : null);

$db = getDBConnection();

// If purokId is provided, resolve barangay_id if not present
if ($purokId && !$barangayId) {
    $pStmt = $db->prepare("SELECT barangay_id FROM puroks WHERE id = ? LIMIT 1");
    $pStmt->execute([$purokId]);
    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    if ($pRow && !empty($pRow['barangay_id'])) {
        $barangayId = (int)$pRow['barangay_id'];
    }
}

try {
    // Check if already exists for this purok / barangay
    if ($purokId) {
        $chk = $db->prepare("SELECT id, name FROM hazard_type_options WHERE LOWER(name) = LOWER(?) AND purok_id = ? LIMIT 1");
        $chk->execute([$name, $purokId]);
    } elseif ($barangayId) {
        $chk = $db->prepare("SELECT id, name FROM hazard_type_options WHERE LOWER(name) = LOWER(?) AND barangay_id = ? LIMIT 1");
        $chk->execute([$name, $barangayId]);
    } else {
        $chk = $db->prepare("SELECT id, name FROM hazard_type_options WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $chk->execute([$name]);
    }
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'purok_id' => $purokId,
            'barangay_id' => $barangayId,
            'message' => 'Hazard option already exists for this jurisdiction.'
        ]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO hazard_type_options (name, purok_id, barangay_id) VALUES (?, ?, ?)");
    $stmt->execute([$name, $purokId, $barangayId]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'purok_id' => $purokId,
        'barangay_id' => $barangayId,
        'message' => "Hazard type '$name' saved to database."
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
