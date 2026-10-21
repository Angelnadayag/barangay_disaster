<?php
// ============================================================================
// Backend API: Purok Households - List Household Families in a Purok
// Returns list of household families, their 1 authorized representative,
// member breakdown, and summary statistics.
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

if (!in_array($role, ['barangay_head', 'icdrrmo', 'responder'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: insufficient permissions.']);
    exit;
}

$db = getDBConnection();

$barangayId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : (int)($_GET['barangay_id'] ?? $user['barangay_id'] ?? 12);
$purokName = trim($_GET['purok_name'] ?? '');
$purokId = !empty($_GET['purok_id']) ? (int)$_GET['purok_id'] : 0;
$search = trim($_GET['search'] ?? '');
$filterPriority = trim($_GET['priority'] ?? '');

// Resolve purok info
$purok = null;
if ($purokId > 0) {
    $pStmt = $db->prepare("SELECT * FROM puroks WHERE id = ? AND barangay_id = ?");
    $pStmt->execute([$purokId, $barangayId]);
    $purok = $pStmt->fetch(PDO::FETCH_ASSOC);
    if ($purok && empty($purokName)) {
        $purokName = $purok['name'];
    }
} elseif (!empty($purokName)) {
    $pStmt = $db->prepare("SELECT * FROM puroks WHERE name = ? AND barangay_id = ?");
    $pStmt->execute([$purokName, $barangayId]);
    $purok = $pStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$purok && empty($purokName)) {
    echo json_encode(['success' => false, 'message' => 'Purok identifier is required.']);
    exit;
}

// Fetch households in this purok
$sql = "
    SELECT ph.*, 
           COALESCE(r.id, r2.id) AS matched_resident_id,
           COALESCE(r.username, r2.username) AS resident_username,
           COALESCE(r.email, r2.email) AS resident_email,
           COALESCE(r.image, r2.image) AS resident_image
    FROM purok_households ph
    LEFT JOIN residents r ON ph.head_resident_id = r.id
    LEFT JOIN residents r2 ON (ph.head_phone IS NOT NULL AND ph.head_phone != '' AND ph.head_phone = r2.phone)
    WHERE ph.barangay_id = ? 
      AND (ph.purok_name = ? OR ph.purok_id = ?)
      AND ph.status = 'active'
";
$params = [$barangayId, $purokName, $purok ? (int)$purok['id'] : 0];

if (!empty($search)) {
    $sql .= " AND (ph.family_name LIKE ? OR ph.head_full_name LIKE ? OR ph.household_code LIKE ? OR ph.head_phone LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($filterPriority)) {
    $sql .= " AND ph.evacuation_priority = ?";
    $params[] = $filterPriority;
}

$sql .= " ORDER BY ph.household_code ASC, ph.family_name ASC";
$hhStmt = $db->prepare($sql);
$hhStmt->execute($params);
$households = $hhStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch members for these households
$householdIds = array_column($households, 'id');
$membersByHh = [];
if (!empty($householdIds)) {
    $inClause = implode(',', array_fill(0, count($householdIds), '?'));
    $mStmt = $db->prepare("
        SELECT * FROM household_members 
        WHERE household_id IN ($inClause)
        ORDER BY is_authorized_rep DESC, age DESC
    ");
    $mStmt->execute($householdIds);
    $allMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allMembers as $m) {
        $membersByHh[$m['household_id']][] = $m;
    }
}

// Attach members and compute summary stats
$totalMembers = 0;
$totalSeniors = 0;
$totalMinors = 0;
$totalPwd = 0;
$totalAuthorizedHeads = 0;

foreach ($households as &$hh) {
    $hhId = (int)$hh['id'];
    $hh['members'] = $membersByHh[$hhId] ?? [];
    $hh['is_registered_in_system'] = !empty($hh['matched_resident_id']);

    // Summary accumulations
    $totalMembers += (int)$hh['total_members'];
    $totalSeniors += (int)$hh['seniors_count'];
    $totalMinors += (int)$hh['minors_count'];
    $totalPwd += (int)$hh['pwd_count'];
    if ((int)$hh['is_authorized_rep'] === 1) {
        $totalAuthorizedHeads++;
    }
}
unset($hh);

echo json_encode([
    'success' => true,
    'purok' => [
        'id' => $purok['id'] ?? null,
        'name' => $purokName,
        'hazard_types' => $purok['hazard_types'] ?? 'Standard Flood/Squall Risk',
        'risk_level' => $purok['risk_level'] ?? 'Moderate',
        'households_estimate' => (int)($purok['households'] ?? 0),
        'population_estimate' => (int)($purok['population'] ?? 0),
        'coordinates_lat' => $purok['coordinates_lat'] ?? null,
        'coordinates_lng' => $purok['coordinates_lng'] ?? null,
    ],
    'summary' => [
        'total_households' => count($households),
        'total_members' => $totalMembers,
        'authorized_heads_count' => $totalAuthorizedHeads,
        'seniors_count' => $totalSeniors,
        'minors_count' => $totalMinors,
        'pwd_count' => $totalPwd,
    ],
    'households' => $households
], JSON_UNESCAPED_UNICODE);
exit;
