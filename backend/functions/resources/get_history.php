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

$userAgency = getAgencyForUser($user);
$agencyId = $userAgency ? (int)$userAgency['id'] : null;

// Check if $resourceId is an agency_resources.id
$catalogId = $resourceId;
$agencyResource = null;
if ($agencyId) {
    $arStmt = $db->prepare("SELECT * FROM agency_resources WHERE id = ? AND agency_id = ?");
    $arStmt->execute([$resourceId, $agencyId]);
    $agencyResource = $arStmt->fetch(PDO::FETCH_ASSOC);
    if ($agencyResource) {
        $catalogId = (int)$agencyResource['resource_id'];
    } else {
        // Maybe $resourceId was already the catalog resource_id
        $arStmt2 = $db->prepare("SELECT * FROM agency_resources WHERE resource_id = ? AND agency_id = ?");
        $arStmt2->execute([$resourceId, $agencyId]);
        $agencyResource = $arStmt2->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch resource to verify
$resStmt = $db->prepare("SELECT id, name, code, unit, barangay_id FROM resources WHERE id = ?");
$resStmt->execute([$catalogId]);
$resource = $resStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource && !$agencyResource) {
    jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
}

if ($role === 'barangay_head') {
    if (!$agencyResource && (int)($resource['barangay_id'] ?? 0) !== (int)$user['barangay_id']) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized jurisdiction.'], 403);
    }
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
$stmt->execute([$catalogId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'success' => true,
    'resource_id' => $resourceId,
    'count' => count($transactions),
    'transactions' => $transactions
]);
