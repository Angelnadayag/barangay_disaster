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
$stmt = $db->prepare("SELECT id, name, hazard_types, risk_level FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$stmt->execute([$barangayId]);
jsonResponse(['success' => true, 'puroks' => $stmt->fetchAll()]);
