<?php
// ============================================================================
// Function: Resources - Fetch Real-Time Item Transaction History
// Returns history log for a specific resource scoped to user's jurisdiction
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$resourceId = (int)($_GET['resource_id'] ?? ($_POST['resource_id'] ?? 0));

if (!$resourceId) {
    jsonResponse(['success' => false, 'message' => 'Resource ID is required.'], 400);
}

// Fetch resource to verify jurisdiction
$resStmt = $db->prepare("SELECT id, name, code, unit, barangay_id FROM resources WHERE id = ?");
$resStmt->execute([$resourceId]);
$resource = $resStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
}

if ($role === 'barangay_head' && (int)$resource['barangay_id'] !== (int)$user['barangay_id']) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized jurisdiction.'], 403);
}

$stmt = $db->prepare("
    SELECT rt.id, rt.resource_id, rt.transaction_type, rt.quantity, rt.reference_type, rt.remarks, rt.created_at,
           u.full_name AS performed_by_name, res.unit, res.code, res.name AS resource_name
    FROM resource_transactions rt
    JOIN resources res ON rt.resource_id = res.id
    LEFT JOIN users u ON rt.performed_by = u.id
    WHERE rt.resource_id = ?
    ORDER BY rt.created_at DESC
    LIMIT 100
");
$stmt->execute([$resourceId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'success' => true,
    'resource_id' => $resourceId,
    'count' => count($transactions),
    'transactions' => $transactions
]);
