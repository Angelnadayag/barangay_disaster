<?php
// ============================================================================
// Function: Resources - Add Resource to Master Catalog
// Ensures ICDRRMO and Barangay Head share a unified Master Resource Catalog
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$user = getCurrentUser();
$role = $user['role'];
if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? 'Relief Goods');
$unit = trim($_POST['unit'] ?? 'units');
$brand = trim($_POST['brand'] ?? '') ?: null;
$description = trim($_POST['description'] ?? '') ?: '';
$isPerishable = !empty($_POST['is_perishable']) ? 1 : 0;
$code = strtoupper(trim($_POST['code'] ?? ''));

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Resource name is required.']);
    exit;
}

$db = getDBConnection();

try {
    // 1. Check if resource already exists in catalog (case-insensitive name check)
    $chkStmt = $db->prepare("SELECT id, code, name, category, unit, brand, description, is_perishable, status FROM resources WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1");
    $chkStmt->execute([$name]);
    $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // If archived, re-activate so both can see and select it
        if ($existing['status'] === 'archived') {
            $db->prepare("UPDATE resources SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([(int)$existing['id']]);
            $existing['status'] = 'active';
        }

        echo json_encode([
            'success' => true,
            'already_exists' => true,
            'message' => 'Resource "' . $existing['name'] . '" already exists in Master Catalog.',
            'item' => [
                'id' => (int)$existing['id'],
                'code' => $existing['code'],
                'name' => $existing['name'],
                'category' => $existing['category'],
                'unit' => $existing['unit'],
                'brand' => $existing['brand'] ?? '',
                'description' => $existing['description'] ?? '',
                'is_perishable' => (int)($existing['is_perishable'] ?? 0)
            ]
        ]);
        exit;
    }

    // 2. Generate a clean, unified standardized SKU code if not supplied
    if (empty($code)) {
        $catPrefixMap = [
            'Relief Goods' => 'REL',
            'Medical Supplies' => 'MED',
            'Rescue Equipment' => 'EQP',
            'Emergency Supplies' => 'EMG',
            'Shelter & Sanitation' => 'SHT',
            'Emergency Shelters' => 'SHT',
            'Sanitation & Hygiene' => 'SAN',
            'Emergency Supplies & Power' => 'POW',
            'Water Supply & Distribution' => 'WAT',
            'Evacuation Center & Community Support' => 'EVAC'
        ];
        $pfx = $catPrefixMap[$category] ?? 'GEN';
        $num = (int)$db->query("SELECT COUNT(*) FROM resources")->fetchColumn() + 1;
        $code = "RES-{$pfx}-" . str_pad($num, 3, '0', STR_PAD_LEFT);

        $chkCode = $db->prepare("SELECT id FROM resources WHERE code = ?");
        $chkCode->execute([$code]);
        while ($chkCode->fetch()) {
            $num++;
            $code = "RES-{$pfx}-" . str_pad($num, 3, '0', STR_PAD_LEFT);
            $chkCode->execute([$code]);
        }
    }

    // 3. Ensure category is persisted into resource_categories table
    if (!empty($category)) {
        $catStmt = $db->prepare("SELECT id FROM resource_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $catStmt->execute([$category]);
        if (!$catStmt->fetch()) {
            $db->prepare("INSERT INTO resource_categories (name) VALUES (?)")->execute([$category]);
        }
    }

    // 4. Ensure brand is persisted into resource_brands table if provided
    if (!empty($brand)) {
        $brandStmt = $db->prepare("SELECT id FROM resource_brands WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $brandStmt->execute([$brand]);
        if (!$brandStmt->fetch()) {
            $db->prepare("INSERT INTO resource_brands (name) VALUES (?)")->execute([$brand]);
        }
    }

    // 5. Insert into resources master catalog (barangay_id IS NULL so it's a global master item)
    $ins = $db->prepare("
        INSERT INTO resources (
            code, name, category, status, description, unit, total_quantity,
            available_quantity, in_use_quantity, damaged_quantity, min_threshold,
            is_perishable, brand, item_condition, barangay_id, created_at, updated_at
        ) VALUES (
            ?, ?, ?, 'active', ?, ?, 0,
            0, 0, 0, 20,
            ?, ?, 'New', NULL, NOW(), NOW()
        )
    ");
    $ins->execute([$code, $name, $category, $description, $unit, $isPerishable, $brand]);
    $newId = (int)$db->lastInsertId();

    // 6. Log in system_logs and resource_transactions audit trail
    logSystemEvent(
        'CATALOG_SUPPLY_ADDED',
        'Supplies',
        "Added new commodity '{$name}' [{$code}] ({$category}) into Master Supply Catalog",
        $user['id'],
        $user['full_name'],
        $user['role']
    );

    $db->prepare("
        INSERT INTO resource_transactions (
            resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
        ) VALUES (?, 'New Product', 0, 'manual', ?, ?, ?, NOW())
    ")->execute([
        $newId,
        $newId,
        "Master catalog commodity entry created for '{$name}' [{$code}]",
        $user['id']
    ]);

    echo json_encode([
        'success' => true,
        'already_exists' => false,
        'message' => 'Resource "' . $name . '" added to Master Catalog successfully.',
        'item' => [
            'id' => $newId,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'unit' => $unit,
            'brand' => $brand ?? '',
            'description' => $description,
            'is_perishable' => $isPerishable
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
