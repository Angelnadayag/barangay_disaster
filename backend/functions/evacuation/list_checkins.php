<?php
// ============================================================================
// Backend Functions: Evacuation - List Sheltered Families (JSON)
// Provides filtered check-in roster for modals and telemetry widgets.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$centerId = (int)($_GET['center_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$purok = trim($_GET['purok'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT c.*, ea.name AS center_name, ea.capacity_families, ea.capacity_individuals,
           ph.economic_status, ph.housing_type, ph.evacuation_priority
    FROM evacuation_family_checkins c
    JOIN evacuation_areas ea ON c.evacuation_area_id = ea.id
    LEFT JOIN purok_households ph ON c.household_id = ph.id
    WHERE 1=1
";
$params = [];

if ($centerId > 0) {
    $sql .= " AND c.evacuation_area_id = ?";
    $params[] = $centerId;
}

if ($user['role'] === 'barangay_head') {
    $sql .= " AND ea.barangay_id = ?";
    $params[] = (int)$user['barangay_id'];
}

if (!empty($status)) {
    $sql .= " AND c.status = ?";
    $params[] = $status;
}

if (!empty($purok)) {
    $sql .= " AND c.purok_name = ?";
    $params[] = $purok;
}

if (!empty($search)) {
    $sql .= " AND (c.family_name LIKE ? OR c.household_code LIKE ? OR c.head_full_name LIKE ? OR c.room_tent_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(c.status, 'Sheltered', 'Transferred', 'Decamped / Returned Home'), c.checkin_time DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$checkins = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'success' => true,
    'total' => count($checkins),
    'data' => $checkins
]);
