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
$batchNumber = trim($_POST['batch_number'] ?? '') ?: null;
$supplierInput = trim($_POST['supplier_id'] ?? ($_POST['supplier_donor'] ?? ($_POST['supplier'] ?? '')));
$supplierId = null;

if (!empty($supplierInput)) {
    if (is_numeric($supplierInput)) {
        $chkSupp = $db->prepare("SELECT id FROM resource_suppliers WHERE id = ?");
        $chkSupp->execute([(int)$supplierInput]);
        if ($chkSupp->fetch()) {
            $supplierId = (int)$supplierInput;
        }
    }
    if (!$supplierId) {
        // Find by name or insert dynamically
        $chkSupp = $db->prepare("SELECT id FROM resource_suppliers WHERE LOWER(name) = LOWER(?)");
        $chkSupp->execute([$supplierInput]);
        $existingSupp = $chkSupp->fetch();
        if ($existingSupp) {
            $supplierId = (int)$existingSupp['id'];
        } else {
            $db->prepare("INSERT IGNORE INTO resource_suppliers (name) VALUES (?)")->execute([$supplierInput]);
            $supplierId = (int)$db->lastInsertId();
        }
    }
}

$dateAcquired = !empty($_POST['date_acquired']) ? $_POST['date_acquired'] : date('Y-m-d');
$expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
$isPerishable = isset($_POST['is_perishable']) ? 1 : 0;
$brand = trim($_POST['brand'] ?? '') ?: null;
$weightPerUnit = trim($_POST['weight_per_unit'] ?? '') ?: null;
$costPerUnit = !empty($_POST['cost_per_unit']) ? (float)$_POST['cost_per_unit'] : null;
$itemCondition = in_array($_POST['item_condition'] ?? '', ['New', 'Good', 'Fair', 'Poor', 'Expired']) ? $_POST['item_condition'] : 'New';

$selectedResourceId = !empty($_POST['resource_id']) && is_numeric($_POST['resource_id']) ? (int)$_POST['resource_id'] : null;

if ($selectedResourceId) {
    // Direct selection from static resources catalog
    $catStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
    $catStmt->execute([$selectedResourceId]);
    $catRes = $catStmt->fetch(PDO::FETCH_ASSOC);
    if ($catRes) {
        $name = !empty($name) ? $name : $catRes['name'];
        $code = !empty($code) ? $code : $catRes['code'];
        $category = !empty($category) ? $category : $catRes['category'];
        $unit = !empty($unit) ? $unit : $catRes['unit'];
        $brand = !empty($brand) ? $brand : ($catRes['brand'] ?? null);
        $description = !empty($description) ? $description : ($catRes['description'] ?? '');
    }
}

// Generate or sanitize SKU code if still empty
if (empty($code)) {
    $catPrefixMap = [
        'Relief Goods' => 'REL',
        'Medical Supplies' => 'MED',
        'Rescue Equipment' => 'EQP',
        'Emergency Supplies' => 'EMG',
        'Shelter & Sanitation' => 'SHT',
        'Emergency Shelters' => 'SHT',
        'Sanitation & Hygiene' => 'SAN',
        'Emergency Supplies & Power' => 'PWR',
        'Water Supply & Distribution' => 'WAT',
        'Evacuation Center & Community Support' => 'COM'
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

// Determine target Agency ID for agency_resources junction table
$targetAgency = getAgencyForUser($user);
$agencyId = $targetAgency ? (int)$targetAgency['id'] : 1;

try {
    $resourceId = $selectedResourceId;

    // Check if resource name already exists in master catalog to prevent duplicates
    if (!$resourceId && !empty($name)) {
        $existingStmt = $db->prepare("SELECT id, code, name, category, unit, brand, description FROM resources WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1");
        $existingStmt->execute([$name]);
        $existingRes = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingRes) {
            $resourceId = (int)$existingRes['id'];
            $selectedResourceId = $resourceId;
            $name = $existingRes['name'];
            $code = $existingRes['code'];
            $category = $existingRes['category'];
            $unit = $existingRes['unit'];
            if (empty($brand) && !empty($existingRes['brand'])) $brand = $existingRes['brand'];
            if (empty($description) && !empty($existingRes['description'])) $description = $existingRes['description'];
        }
    }

    if (!$resourceId) {
        // Ensure unique code for new static resource definition
        $check = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
        $check->execute([$code]);
        if ($check->fetch()) {
            $code .= '-' . rand(10, 99);
        }

        // Insert new master catalog resource definition (barangay_id IS NULL so it's a global catalog item)
        $centralInitial = ($role === 'icdrrmo') ? $initialQuantity : 0;
        $stmt = $db->prepare("
            INSERT INTO resources (
                code, name, category, status, description, unit, total_quantity,
                available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                batch_number, supplier_id, date_acquired,
                expiry_date, is_perishable, brand, weight_per_unit, cost_per_unit,
                item_condition, barangay_id, updated_at, created_at
            ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW())
        ");
        $stmt->execute([
            $code, $name, $category, $description, $unit, $centralInitial,
            $centralInitial, $minThreshold, $batchNumber, $supplierId,
            $dateAcquired, $expiryDate, $isPerishable, $brand, $weightPerUnit,
            $costPerUnit, $itemCondition
        ]);
        $resourceId = (int)$db->lastInsertId();
    } else {
        // Update central depot aggregate quantity if added by ICDRRMO
        if ($role === 'icdrrmo' && $initialQuantity > 0) {
            $db->prepare("
                UPDATE resources SET 
                    total_quantity = total_quantity + ?,
                    available_quantity = available_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$initialQuantity, $initialQuantity, $resourceId]);
        }
    }

    // Insert or update into agency_resources junction table
    $arStmt = $db->prepare("
        INSERT INTO agency_resources (
            agency_id, resource_id, total_quantity, available_quantity, in_use_quantity,
            damaged_quantity, min_threshold, batch_number, supplier_id, date_acquired,
            expiry_date, cost_per_unit, item_condition, status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, 0,
            0, ?, ?, ?, ?,
            ?, ?, ?, 'active', NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            total_quantity = total_quantity + VALUES(available_quantity),
            available_quantity = available_quantity + VALUES(available_quantity),
            min_threshold = VALUES(min_threshold),
            batch_number = COALESCE(VALUES(batch_number), batch_number),
            supplier_id = COALESCE(VALUES(supplier_id), supplier_id),
            expiry_date = COALESCE(VALUES(expiry_date), expiry_date),
            cost_per_unit = COALESCE(VALUES(cost_per_unit), cost_per_unit),
            item_condition = VALUES(item_condition),
            status = 'active',
            updated_at = NOW()
    ");
    $arStmt->execute([
        $agencyId, $resourceId, $initialQuantity, $initialQuantity,
        $minThreshold, $batchNumber, $supplierId, $dateAcquired,
        $expiryDate, $costPerUnit, $itemCondition
    ]);

    // Ensure category and brand persist into dedicated lookup tables
    if (!empty($category)) {
        $db->prepare("INSERT IGNORE INTO resource_categories (name) VALUES (?)")->execute([$category]);
    }
    if (!empty($brand)) {
        $db->prepare("INSERT IGNORE INTO resource_brands (name) VALUES (?)")->execute([$brand]);
    }

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

    logSystemEvent('CREATE_SUPPLY', 'Supplies', "Created supply item '$name' ($code) with $initialQuantity $unit");

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
