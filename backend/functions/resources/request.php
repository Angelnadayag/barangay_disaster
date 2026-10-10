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

// Check if a central resource was selected from catalog or matches by item name
$catItem = null;
if ($resourceId) {
    $cStmt = $db->prepare("SELECT id, name, category, unit, available_quantity, storage_location FROM resources WHERE id = ? AND barangay_id IS NULL AND status = 'active'");
    $cStmt->execute([$resourceId]);
    $catItem = $cStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$catItem && !empty($itemName)) {
    // Try to auto-match with active central resource
    $cStmt = $db->prepare("SELECT id, name, category, unit, available_quantity, storage_location FROM resources WHERE barangay_id IS NULL AND status = 'active' AND LOWER(name) = LOWER(?) LIMIT 1");
    $cStmt->execute([$itemName]);
    $catItem = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($catItem) {
        $resourceId = (int)$catItem['id'];
    }
}

if ($catItem) {
    if (empty($itemName)) $itemName = $catItem['name'];
    if (empty($_POST['category'])) $category = $catItem['category'];
    if (empty($_POST['unit'])) $unit = $catItem['unit'];

    $avail = (int)$catItem['available_quantity'];
    if ($avail <= 0) {
        $err = "Commodity '{$catItem['name']}' is currently OUT OF STOCK at ICDRRMO Central Depot (0 {$catItem['unit']} available).";
        if ($isAjax) jsonResponse(['success' => false, 'message' => $err], 400);
        redirectWithFlash($returnUrl, 'error', $err);
    }
    if ($requestedQuantity > $avail) {
        $err = "Requested quantity ({$requestedQuantity} {$catItem['unit']}) exceeds available stock in ICDRRMO Central Depot (Max available: {$avail} {$catItem['unit']}).";
        if ($isAjax) jsonResponse(['success' => false, 'message' => $err], 400);
        redirectWithFlash($returnUrl, 'error', $err);
    }
}

if (empty($itemName) || empty($purpose)) {
    $err = 'Item Name and Purpose / Justification are required.';
    if ($isAjax) jsonResponse(['success' => false, 'message' => $err], 400);
    redirectWithFlash($returnUrl, 'error', $err);
}

// Generate unique Request Code REQ-RES-YYYY-XXXX
$cntStmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM barangay_resource_requests");
$nextId = ((int)$cntStmt->fetchColumn()) + 1;
$requestCode = 'REQ-RES-' . date('Y') . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
$chk = $db->prepare("SELECT id FROM barangay_resource_requests WHERE request_code = ?");
$chk->execute([$requestCode]);
if ($chk->fetch()) {
    $requestCode .= '-' . rand(10, 99);
}

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
            'message' => "Resource Requisition {$requestCode} has been submitted to ICDRRMO for review.",
            'id' => $requestId,
            'request_code' => $requestCode,
            'item_name' => $itemName,
            'category' => $category,
            'requested_quantity' => $requestedQuantity,
            'unit' => $unit,
            'urgency' => $urgency,
            'target_purok' => $targetPurok ?: 'Barangay-wide',
            'purpose' => $purpose,
            'status' => 'Pending',
            'created_at' => date('Y-m-d H:i:s'),
            'barangay_name' => $bName
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Resource Requisition {$requestCode} submitted to ICDRRMO successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to submit requisition: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to submit requisition: ' . $e->getMessage());
}
