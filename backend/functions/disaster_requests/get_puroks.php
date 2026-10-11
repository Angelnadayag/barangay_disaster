<?php
// ============================================================================
// Function: Disaster Requests - Get Puroks by Barangay ID
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$db = getDBConnection();

$barangayId = (int)($_GET['barangay_id'] ?? ($_POST['barangay_id'] ?? 0));
$puroks = [];

if ($barangayId > 0) {
    // 1. Fetch Puroks from puroks table
    $stmt = $db->prepare("SELECT id, name, hazard_types, risk_level FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL) ORDER BY name ASC");
    $stmt->execute([$barangayId]);
    $puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch clusters from purok_cluster_options table
    try {
        $cStmt = $db->prepare("SELECT id, name, '' AS hazard_types, 'Moderate' AS risk_level FROM purok_cluster_options WHERE (barangay_id = ? OR barangay_id IS NULL) ORDER BY name ASC");
        $cStmt->execute([$barangayId]);
        $clusters = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $names = array_column($puroks, 'name');
        foreach ($clusters as $c) {
            if (!in_array($c['name'], $names, true)) {
                $puroks[] = $c;
                $names[] = $c['name'];
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully if cluster options table not present
    }
}

jsonResponse(['success' => true, 'puroks' => $puroks]);
