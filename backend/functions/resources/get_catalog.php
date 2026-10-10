<?php
// ============================================================================
// Function: Resources - Fetch Real-Time Active Central Catalog
// Returns current stock quantities ready for requisition dropdowns & validation
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$db = getDBConnection();

$stmt = $db->query("
    SELECT id, code, name, category, unit, available_quantity, total_quantity, min_threshold, storage_location, item_condition
    FROM resources
    WHERE barangay_id IS NULL AND status = 'active'
    ORDER BY category ASC, name ASC
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'success' => true,
    'count' => count($items),
    'catalog' => $items,
    'timestamp' => date('Y-m-d H:i:s')
]);
