<?php
// ============================================================================
// Function: Resources - Submit Barangay Requisition Request to ICDRRMO
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/resources.php'));

if ($role !== 'barangay_head') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Only Barangay Heads can submit resource requisition requests.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$barangayId = (int)$user['barangay_id'];
$resourceId = !empty($_POST['resource_id']) ? (int)$_POST['resource_id'] : null;
$itemName = trim($_POST['item_name'] ?? '');
$category = trim($_POST['category'] ?? 'Relief Goods');
$requestedQuantity = max(1, (int)($_POST['requested_quantity'] ?? 1));
$unit = trim($_POST['unit'] ?? 'units');
$urgency = in_array($_POST['urgency'] ?? '', ['Immediate', 'High', 'Medium', 'Low']) ? $_POST['urgency'] : 'High';
$targetPurok = trim($_POST['target_purok'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');

// If a central resource was selected from catalog, auto-populate item name/category/unit if blank
if ($resourceId) {
    $cStmt = $db->prepare("SELECT name, category, unit FROM resources WHERE id = ?");
    $cStmt->execute([$resourceId]);
    $catItem = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($catItem) {
        if (empty($itemName)) $itemName = $catItem['name'];
        if (empty($_POST['category'])) $category = $catItem['category'];
        if (empty($_POST['unit'])) $unit = $catItem['unit'];
    }
}

if (empty($itemName) || empty($purpose)) {
    $err = 'Item Name and Purpose / Justification are required.';
    if ($isAjax) jsonResponse(['success' => false, 'message' => $err], 400);
    redirectWithFlash($returnUrl, 'error', $err);
}

// Generate Request Code REQ-RES-YYYY-XXX
$cntStmt = $db->query("SELECT COUNT(*) FROM barangay_resource_requests");
$nextId = ((int)$cntStmt->fetchColumn()) + 1;
$requestCode = 'REQ-RES-' . date('Y') . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

try {
    $stmt = $db->prepare("
        INSERT INTO barangay_resource_requests (
            request_code, barangay_id, requested_by, resource_id, item_name,
            category, requested_quantity, unit, urgency, target_purok,
            purpose, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->execute([
        $requestCode, $barangayId, $user['id'], $resourceId, $itemName,
        $category, $requestedQuantity, $unit, $urgency, $targetPurok,
        $purpose
    ]);
    $requestId = (int)$db->lastInsertId();

    // Fetch barangay name
    $bStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
    $bStmt->execute([$barangayId]);
    $bName = $bStmt->fetchColumn() ?: 'Barangay';

    // Notify ICDRRMO Central Admin
    $notifStmt = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
        VALUES ('icdrrmo', ?, ?, ?, ?, 'resource_request', ?, NOW())
    ");
    $alertLevel = in_array($urgency, ['Immediate', 'High']) ? 'urgent' : 'info';
    $notifMsg = "Barangay $bName submitted supply requisition $requestCode for $requestedQuantity $unit of $itemName ($urgency Urgency).";
    $notifStmt->execute([$barangayId, "Requisition Request: $requestCode", $notifMsg, $alertLevel, $requestId]);

    logSystemEvent('REQUEST_RESOURCES', 'Resources', "Barangay $bName filed resource requisition $requestCode ($requestedQuantity $unit of $itemName)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Resource Requisition $requestCode has been submitted to ICDRRMO for review.",
            'id' => $requestId,
            'request_code' => $requestCode
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Resource Requisition $requestCode submitted to ICDRRMO successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to submit requisition: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to submit requisition: ' . $e->getMessage());
}
