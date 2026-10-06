<?php
// ============================================================================
// Function: Resources - Create Resource
// Scoped to Barangay jurisdiction for Barangay Heads, Central Depot for ICDRRMO
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

$barangayId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : (!empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null);

$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? 'Relief Goods');
$description = trim($_POST['description'] ?? '');
$unit = trim($_POST['unit'] ?? 'units');
$initialQuantity = max(0, (int)($_POST['quantity'] ?? ($_POST['available_quantity'] ?? 0)));
$minThreshold = max(1, (int)($_POST['min_threshold'] ?? 20));
$location = trim($_POST['storage_location'] ?? ($role === 'barangay_head' ? 'Barangay Hall Stockroom' : 'Central ICDRRMO Depot'));
$batchNumber = trim($_POST['batch_number'] ?? '') ?: null;
$supplierDonor = trim($_POST['supplier_donor'] ?? '') ?: null;
$dateAcquired = !empty($_POST['date_acquired']) ? $_POST['date_acquired'] : date('Y-m-d');
$expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
$isPerishable = isset($_POST['is_perishable']) ? 1 : 0;
$brand = trim($_POST['brand'] ?? '') ?: null;
$weightPerUnit = trim($_POST['weight_per_unit'] ?? '') ?: null;
$costPerUnit = !empty($_POST['cost_per_unit']) ? (float)$_POST['cost_per_unit'] : null;
$itemCondition = in_array($_POST['item_condition'] ?? '', ['New', 'Good', 'Fair', 'Poor', 'Expired']) ? $_POST['item_condition'] : 'New';

// Generate or sanitize SKU code
$code = strtoupper(trim($_POST['code'] ?? ''));
if (empty($code)) {
    $catPrefixMap = [
        'Relief Goods' => 'REL',
        'Medical Supplies' => 'MED',
        'Rescue Equipment' => 'EQP',
        'Emergency Supplies' => 'EMG',
        'Shelter & Sanitation' => 'SHT'
    ];
    $prefix = $catPrefixMap[$category] ?? 'GEN';
    $prefixScope = ($barangayId) ? "BRG{$barangayId}-{$prefix}" : "ICD-{$prefix}";
    $countStmt = $db->query("SELECT COUNT(*) FROM resources");
    $nextNum = ((int)$countStmt->fetchColumn()) + 1;
    $code = $prefixScope . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

if (empty($name)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource Name is required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Resource Name is required.');
}

// Ensure unique code
$check = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
$check->execute([$code]);
if ($check->fetch()) {
    $code .= '-' . rand(10, 99);
}

try {
    $stmt = $db->prepare("
        INSERT INTO resources (
            code, name, category, status, description, unit, total_quantity,
            available_quantity, in_use_quantity, damaged_quantity, min_threshold,
            storage_location, batch_number, supplier_donor, date_acquired,
            expiry_date, is_perishable, brand, weight_per_unit, cost_per_unit,
            item_condition, barangay_id, updated_at, created_at
        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $code, $name, $category, $description, $unit, $initialQuantity,
        $initialQuantity, $minThreshold, $location, $batchNumber, $supplierDonor,
        $dateAcquired, $expiryDate, $isPerishable, $brand, $weightPerUnit,
        $costPerUnit, $itemCondition, $barangayId
    ]);
    $resourceId = (int)$db->lastInsertId();

    // Log movement transaction
    if ($initialQuantity > 0) {
        $transStmt = $db->prepare("
            INSERT INTO resource_transactions (
                resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
            ) VALUES (?, 'New Product', ?, 'initial_inventory', ?, ?, ?, NOW())
        ");
        $transStmt->execute([
            $resourceId, $initialQuantity, $resourceId,
            "Initial inventory stock entry ($name)", $user['id']
        ]);
    }

    logSystemEvent('CREATE_RESOURCE', 'Resources', "Created resource '$name' ($code) with $initialQuantity $unit");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Resource '$name' ($code) added successfully.",
            'id' => $resourceId,
            'code' => $code,
            'name' => $name
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Resource '$name' ($code) added successfully.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to create resource: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to create resource: ' . $e->getMessage());
}
