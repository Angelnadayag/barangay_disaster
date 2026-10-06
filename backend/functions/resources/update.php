<?php
// ============================================================================
// Function: Resources - Update Resource
// Scoped to Barangay jurisdiction with RBAC and AJAX support
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (
    $role === 'barangay_head' ? BASE_URL . '/views/barangay/resources.php' : BASE_URL . '/views/icdrrmo/manage-resources.php'
));

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid resource ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid resource ID.');
}

// Fetch existing record
$stmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
$stmt->execute([$id]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$res) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource not found.');
}

// Jurisdiction check for Barangay Head
if ($role === 'barangay_head' && (int)$res['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This resource belongs to another jurisdiction.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify resources outside your barangay.');
}

$code = strtoupper(trim($_POST['code'] ?? $res['code']));
$name = trim($_POST['name'] ?? $res['name']);
$category = trim($_POST['category'] ?? $res['category']);
$description = trim($_POST['description'] ?? $res['description']);
$unit = trim($_POST['unit'] ?? $res['unit']);
$availableQuantity = max(0, (int)($_POST['available_quantity'] ?? $res['available_quantity']));
$inUseQuantity = max(0, (int)($_POST['in_use_quantity'] ?? $res['in_use_quantity']));
$damagedQuantity = max(0, (int)($_POST['damaged_quantity'] ?? $res['damaged_quantity']));
$totalQuantity = isset($_POST['total_quantity']) && $_POST['total_quantity'] !== ''
    ? max(0, (int)$_POST['total_quantity'])
    : ($availableQuantity + $inUseQuantity + $damagedQuantity);
$minThreshold = max(1, (int)($_POST['min_threshold'] ?? $res['min_threshold']));
$location = trim($_POST['storage_location'] ?? $res['storage_location']);
$batchNumber = trim($_POST['batch_number'] ?? '') ?: $res['batch_number'];
$supplierDonor = trim($_POST['supplier_donor'] ?? '') ?: $res['supplier_donor'];
$dateAcquired = !empty($_POST['date_acquired']) ? $_POST['date_acquired'] : $res['date_acquired'];
$expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : $res['expiry_date'];
$isPerishable = isset($_POST['is_perishable']) ? 1 : $res['is_perishable'];
$brand = trim($_POST['brand'] ?? '') ?: $res['brand'];
$weightPerUnit = trim($_POST['weight_per_unit'] ?? '') ?: $res['weight_per_unit'];
$costPerUnit = !empty($_POST['cost_per_unit']) ? (float)$_POST['cost_per_unit'] : $res['cost_per_unit'];
$itemCondition = in_array($_POST['item_condition'] ?? '', ['New', 'Good', 'Fair', 'Poor', 'Expired']) ? $_POST['item_condition'] : $res['item_condition'];

if (empty($code) || empty($name)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'SKU Code and Name are required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Resource ID, SKU code, and name are required.');
}

// Check SKU uniqueness
$check = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?) AND id != ?");
$check->execute([$code, $id]);
if ($check->fetch()) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => "SKU code '{$code}' already exists."], 400);
    redirectWithFlash($returnUrl, 'error', "A resource with SKU code '{$code}' already exists.");
}

try {
    $updateStmt = $db->prepare("
        UPDATE resources SET
            code = ?, name = ?, category = ?, description = ?, unit = ?,
            total_quantity = ?, available_quantity = ?, in_use_quantity = ?, damaged_quantity = ?,
            min_threshold = ?, storage_location = ?, batch_number = ?, supplier_donor = ?,
            date_acquired = ?, expiry_date = ?, is_perishable = ?, brand = ?,
            weight_per_unit = ?, cost_per_unit = ?, item_condition = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $code, $name, $category, $description, $unit,
        $totalQuantity, $availableQuantity, $inUseQuantity, $damagedQuantity,
        $minThreshold, $location, $batchNumber, $supplierDonor,
        $dateAcquired, $expiryDate, $isPerishable, $brand,
        $weightPerUnit, $costPerUnit, $itemCondition,
        $id
    ]);

    logSystemEvent('UPDATE_RESOURCE', 'Resources', "Updated resource '$name' ($code)");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Resource '$name' updated successfully.",
            'id' => $id,
            'available_quantity' => $availableQuantity
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Resource '{$name}' updated successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to update resource: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to update resource: ' . $e->getMessage());
}
