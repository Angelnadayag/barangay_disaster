<?php
// ============================================================================
// Function: Decision Tree Recommender - Add Resource Item to Recommendation
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || !empty($_POST['is_ajax']);

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/recommendations.php'));

if ($user['role'] !== 'icdrrmo') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO personnel can manage recommendations.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can manage recommendations.');
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
$resourceId = (int)($_POST['resource_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);

if ($recId <= 0 || $resourceId <= 0 || $quantity <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid resource and enter an allocation quantity greater than zero.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Please select a valid resource and enter an allocation quantity greater than zero.');
}

try {
    $db = getDBConnection();

    // Verify recommendation exists and is Pending Review
    $recStmt = $db->prepare("SELECT id, recommendation_code, status FROM recommendations WHERE id = ?");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch();

    if (!$rec) {
        throw new Exception("Recommendation #$recId not found.");
    }

    if ($rec['status'] !== 'Pending Review') {
        throw new Exception("Cannot add resources: this recommendation is already " . clean($rec['status']) . ".");
    }

    // Verify resource exists in central depot
    $resStmt = $db->prepare("
        SELECT id, code, name, category, unit, total_quantity, available_quantity, min_threshold
        FROM resources
        WHERE id = ? AND barangay_id IS NULL AND (status = 'active' OR status IS NULL)
    ");
    $resStmt->execute([$resourceId]);
    $resource = $resStmt->fetch();

    if (!$resource) {
        throw new Exception("Selected resource not found in Central Depot inventory.");
    }

    // Check if item already exists in recommended_items for this recommendation
    $checkStmt = $db->prepare("
        SELECT id, recommended_quantity, approved_quantity, status
        FROM recommended_items
        WHERE recommendation_id = ? AND resource_id = ?
    ");
    $checkStmt->execute([$recId, $resourceId]);
    $existingItem = $checkStmt->fetch();

    if ($existingItem) {
        // Update existing item quantity by adding to it and mark as added_by_admin
        $newQty = (int)$existingItem['recommended_quantity'] + $quantity;
        $updStmt = $db->prepare("
            UPDATE recommended_items
            SET recommended_quantity = ?,
                approved_quantity = ?,
                status = 'Pending',
                added_type = 'added_by_admin'
            WHERE id = ?
        ");
        $updStmt->execute([$newQty, $newQty, $existingItem['id']]);
        $itemId = (int)$existingItem['id'];
        $finalQty = $newQty;
    } else {
        // Insert new item with added_type = added_by_admin
        $insStmt = $db->prepare("
            INSERT INTO recommended_items (
                recommendation_id, resource_id, recommended_quantity, approved_quantity, allocated_quantity, status, added_type
            ) VALUES (?, ?, ?, ?, 0, 'Pending', 'added_by_admin')
        ");
        $insStmt->execute([$recId, $resourceId, $quantity, $quantity]);
        $itemId = (int)$db->lastInsertId();
        $finalQty = $quantity;
    }

    // Calculate updated items count for response
    $countStmt = $db->prepare("SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = ?");
    $countStmt->execute([$recId]);
    $totalItems = (int)$countStmt->fetchColumn();

    $itemData = [
        'id' => $itemId,
        'recommendation_id' => $recId,
        'resource_id' => $resourceId,
        'code' => $resource['code'],
        'resource_name' => $resource['name'],
        'category' => $resource['category'],
        'unit' => $resource['unit'],
        'total_quantity' => (int)$resource['total_quantity'],
        'recommended_quantity' => $finalQty,
        'approved_quantity' => $finalQty,
        'available_quantity' => (int)$resource['available_quantity'],
        'is_sufficient' => ((int)$resource['available_quantity'] >= $finalQty),
        'added_type' => 'added_by_admin'
    ];

    $successMsg = "Successfully added " . number_format($quantity) . " " . clean($resource['unit']) . " of " . clean($resource['name']) . " to the allocation package.";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $successMsg,
            'item' => $itemData,
            'total_items' => $totalItems
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $successMsg);

} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
