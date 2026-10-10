<?php
// ============================================================================
// Function: Process Resource Allocation (ICDRRMO Manage Resources Only)
// Handles: Individual Item Allocation, Batch Recommendation Allocation, Direct Allocation
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
          || (!empty($_POST['is_ajax']) || !empty($_GET['is_ajax']));

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/manage-resources.php?tab=allocations'));

if ($user['role'] !== 'icdrrmo') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO personnel can process resource allocations.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can process resource allocations.');
}

$db = getDBConnection();
$subAction = trim($_POST['sub_action'] ?? ($_POST['action_type'] ?? ($_POST['action'] ?? 'process_item')));

// ----------------------------------------------------------------------------
// CASE 1: DIRECT ALLOCATION TO A BARANGAY
// ----------------------------------------------------------------------------
if ($subAction === 'direct_allocation' || $subAction === 'direct') {
    $barangayId = (int)($_POST['barangay_id'] ?? 0);
    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $disasterType = trim($_POST['disaster_type'] ?? 'Typhoon');
    $purpose = trim($_POST['purpose'] ?? 'Direct Relief Pre-positioning & Assistance');
    $remarks = trim($_POST['remarks'] ?? 'Direct allocation dispatched from Central Depot');

    if ($barangayId <= 0) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Please select a beneficiary barangay.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Please select a beneficiary barangay.');
    }
    if ($resourceId <= 0) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Please select a Central Depot resource commodity.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Please select a Central Depot resource commodity.');
    }
    if ($quantity <= 0) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Allocation quantity must be at least 1.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Allocation quantity must be at least 1.');
    }

    $validDisasterTypes = ['Flood', 'Typhoon', 'Landslide', 'Earthquake', 'Fire', 'Storm Surge', 'Flash Flood'];
    if (!in_array($disasterType, $validDisasterTypes, true)) {
        $disasterType = 'Typhoon';
    }

    $db->beginTransaction();
    try {
        // 1. Verify and lock Central Depot Resource
        $cStmt = $db->prepare("
            SELECT id, code, name, category, unit, description, available_quantity, total_quantity, storage_location
            FROM resources
            WHERE id = ? AND barangay_id IS NULL AND status = 'active'
            FOR UPDATE
        ");
        $cStmt->execute([$resourceId]);
        $centralRes = $cStmt->fetch();

        if (!$centralRes) {
            throw new Exception("Selected resource is not an active Central Depot commodity.");
        }

        if ((int)$centralRes['available_quantity'] < $quantity) {
            throw new Exception("Insufficient stock in Central Depot. Only " . number_format($centralRes['available_quantity']) . " " . $centralRes['unit'] . " available (requested {$quantity}).");
        }

        // 2. Fetch Barangay Name
        $bStmt = $db->prepare("SELECT id, name FROM barangays WHERE id = ?");
        $bStmt->execute([$barangayId]);
        $barangay = $bStmt->fetch();
        if (!$barangay) {
            throw new Exception("Invalid beneficiary barangay selected.");
        }

        // 3. Deduct Central Depot Inventory in Real-Time
        $deduct = $db->prepare("
            UPDATE resources SET
                available_quantity = available_quantity - ?,
                total_quantity = total_quantity - ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $deduct->execute([$quantity, $quantity, $resourceId]);

        // 4. Credit Beneficiary Barangay Inventory
        $chkLocal = $db->prepare("
            SELECT id, available_quantity, total_quantity
            FROM resources
            WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?))
            LIMIT 1
        ");
        $chkLocal->execute([$barangayId, $centralRes['name'], $centralRes['code']]);
        $bRes = $chkLocal->fetch();

        $targetLocalResId = 0;
        if ($bRes) {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = available_quantity + ?,
                    total_quantity = total_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$quantity, $quantity, $bRes['id']]);
            $targetLocalResId = (int)$bRes['id'];
        } else {
            $localCode = "BRG{$barangayId}-" . $centralRes['code'];
            $chkCode = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
            $chkCode->execute([$localCode]);
            if ($chkCode->fetch()) $localCode .= '-' . rand(10, 99);

            $insLocal = $db->prepare("
                INSERT INTO resources (
                    code, name, category, status, description, unit, total_quantity,
                    available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                    storage_location, supplier_donor, item_condition, barangay_id, updated_at, created_at
                ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, 20, ?, ?, 'New', ?, NOW(), NOW())
            ");
            $insLocal->execute([
                $localCode, $centralRes['name'], $centralRes['category'],
                $centralRes['description'], $centralRes['unit'], $quantity,
                $quantity, 'Barangay Hall Stockroom', "Direct Allocation by ICDRRMO Central Depot",
                $barangayId
            ]);
            $targetLocalResId = (int)$db->lastInsertId();
        }

        // 5. Create Disaster Request & Recommendation Records
        $trackingCode = 'ALLOC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $chkTrack = $db->prepare("SELECT id FROM disaster_requests WHERE tracking_code = ?");
        $chkTrack->execute([$trackingCode]);
        if ($chkTrack->fetch()) {
            $trackingCode = 'ALLOC-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        }

        $insReq = $db->prepare("
            INSERT INTO disaster_requests (
                tracking_code, barangay_id, purok_name, disaster_type, severity, urgency,
                affected_families, affected_individuals, displaced_families, requested_assistance,
                situation_overview, status, submitted_by, created_at, updated_at
            ) VALUES (?, ?, 'Barangay Wide', ?, 'Moderate', 'High', 0, 0, 0, ?, ?, 'Allocated', ?, NOW(), NOW())
        ");
        $insReq->execute([
            $trackingCode, $barangayId, $disasterType, $purpose,
            "Direct relief allocation by ICDRRMO Central Depot: {$remarks}",
            $user['id']
        ]);
        $requestId = (int)$db->lastInsertId();

        $recCode = 'REC-DIR-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $insRec = $db->prepare("
            INSERT INTO recommendations (
                recommendation_code, disaster_request_id, priority, confidence_score,
                decision_path, recommendation_reason, status, reviewed_by, reviewed_at,
                review_remarks, created_at
            ) VALUES (?, ?, 'High', 100.00, 'Direct Administrative Allocation by ICDRRMO', ?, 'Approved', ?, NOW(), ?, NOW())
        ");
        $insRec->execute([
            $recCode, $requestId, $purpose, $user['id'], $remarks
        ]);
        $recId = (int)$db->lastInsertId();

        $insItem = $db->prepare("
            INSERT INTO recommended_items (
                recommendation_id, resource_id, recommended_quantity, approved_quantity,
                allocated_quantity, status
            ) VALUES (?, ?, ?, ?, ?, 'Allocated')
        ");
        $insItem->execute([$recId, $resourceId, $quantity, $quantity, $quantity]);
        $allocatedItemId = (int)$db->lastInsertId();

        // 6. Log Resource Transactions for Audit Trail
        $db->prepare("
            INSERT INTO resource_transactions (
                resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
            ) VALUES (?, 'Allocation', ?, 'disaster_request', ?, ?, ?, NOW())
        ")->execute([
            $resourceId, $quantity, $requestId,
            "Direct allocation of {$quantity} {$centralRes['unit']} dispatched to Brgy. {$barangay['name']} ({$trackingCode})",
            $user['id']
        ]);

        $db->prepare("
            INSERT INTO resource_transactions (
                resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
            ) VALUES (?, 'Restock', ?, 'icdrrmo_allocation', ?, ?, ?, NOW())
        ")->execute([
            $targetLocalResId, $quantity, $requestId,
            "Received direct allocation of {$quantity} {$centralRes['unit']} from ICDRRMO Central Depot",
            $user['id']
        ]);

        // 7. Dispatch Notification to Barangay Head
        $db->prepare("
            INSERT INTO notifications (
                target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at
            ) VALUES ('barangay_head', ?, 'Relief Allocation Dispatched', ?, 'informational', 'resources', ?, NOW())
        ")->execute([
            $barangayId,
            "ICDRRMO has directly allocated and dispatched {$quantity} {$centralRes['unit']} of {$centralRes['name']} to your Barangay inventory ({$trackingCode}).",
            $requestId
        ]);

        logSystemEvent(
            'DIRECT_RESOURCE_ALLOCATION',
            'Resources',
            "Allocated {$quantity} {$centralRes['unit']} of [{$centralRes['code']}] {$centralRes['name']} to Brgy. {$barangay['name']} ({$trackingCode})",
            $user['id']
        );

        $db->commit();

        // Stock metrics after commit
        $remAvail = (int)$db->query("SELECT available_quantity FROM resources WHERE id = {$resourceId}")->fetchColumn();
        $remTotal = (int)$db->query("SELECT total_quantity FROM resources WHERE id = {$resourceId}")->fetchColumn();
        $totalDepotAvail = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND barangay_id IS NULL")->fetchColumn();
        $totalAllocUnits = (int)$db->query("SELECT COALESCE(SUM(allocated_quantity), 0) FROM recommended_items WHERE status = 'Allocated'")->fetchColumn();
        $totalAllocationsCount = (int)$db->query("SELECT COUNT(*) FROM recommended_items")->fetchColumn();

        $successMsg = "Successfully allocated {$quantity} {$centralRes['unit']} of {$centralRes['name']} to Brgy. {$barangay['name']} ({$trackingCode}).";

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $successMsg,
                'action_type' => 'direct',
                'allocation' => [
                    'item_id' => $allocatedItemId,
                    'tracking_code' => $trackingCode,
                    'disaster_type' => $disasterType,
                    'severity' => 'Moderate',
                    'barangay_name' => $barangay['name'],
                    'resource_name' => $centralRes['name'],
                    'resource_code' => $centralRes['code'],
                    'category' => $centralRes['category'],
                    'unit' => $centralRes['unit'],
                    'recommended_quantity' => $quantity,
                    'allocated_quantity' => $quantity,
                    'status' => 'Allocated',
                    'decision_date' => date('M d, Y h:i A')
                ],
                'metrics' => [
                    'remaining_depot_available' => $remAvail,
                    'remaining_depot_total' => $remTotal,
                    'total_depot_available' => $totalDepotAvail,
                    'total_allocated_units' => $totalAllocUnits,
                    'total_allocations_count' => $totalAllocationsCount
                ]
            ]);
        }

        redirectWithFlash($returnUrl, 'success', $successMsg);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($isAjax) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
        redirectWithFlash($returnUrl, 'error', 'Allocation Error: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// CASE 2: BATCH ALLOCATE ALL PENDING ITEMS FOR A RECOMMENDATION
// ----------------------------------------------------------------------------
elseif ($subAction === 'batch_allocate' || $subAction === 'batch') {
    $recId = (int)($_POST['recommendation_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? 'Batch allocation approved & dispatched by ICDRRMO');

    if ($recId <= 0) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid recommendation ID specified.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Invalid recommendation ID specified.');
    }

    $db->beginTransaction();
    try {
        $recStmt = $db->prepare("
            SELECT r.*, dr.id AS request_id, dr.tracking_code, dr.barangay_id, b.name AS barangay_name
            FROM recommendations r
            JOIN disaster_requests dr ON r.disaster_request_id = dr.id
            JOIN barangays b ON dr.barangay_id = b.id
            WHERE r.id = ?
            FOR UPDATE
        ");
        $recStmt->execute([$recId]);
        $rec = $recStmt->fetch();

        if (!$rec) throw new Exception("Recommendation record not found.");

        $pItemsStmt = $db->prepare("
            SELECT ri.*, res.code, res.name AS resource_name, res.category, res.unit,
                   res.available_quantity, res.total_quantity
            FROM recommended_items ri
            JOIN resources res ON ri.resource_id = res.id
            WHERE ri.recommendation_id = ? AND ri.status = 'Pending'
            FOR UPDATE
        ");
        $pItemsStmt->execute([$recId]);
        $pendingItems = $pItemsStmt->fetchAll();

        if (empty($pendingItems)) {
            throw new Exception("No pending recommended items to allocate for this recommendation.");
        }

        $allocatedCount = 0;
        $processedItemIds = [];

        foreach ($pendingItems as $pItem) {
            $qtyToAlloc = (int)$pItem['recommended_quantity'];
            if ($qtyToAlloc <= 0) continue;

            if ((int)$pItem['available_quantity'] < $qtyToAlloc) {
                throw new Exception("Insufficient stock for '{$pItem['resource_name']}'. Only {$pItem['available_quantity']} {$pItem['unit']} available (needed {$qtyToAlloc}).");
            }

            // Deduct Central Depot
            $db->prepare("
                UPDATE resources SET
                    available_quantity = available_quantity - ?,
                    total_quantity = total_quantity - ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qtyToAlloc, $qtyToAlloc, $pItem['resource_id']]);

            // Credit Barangay
            $chkLocal = $db->prepare("
                SELECT id FROM resources WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?)) LIMIT 1
            ");
            $chkLocal->execute([$rec['barangay_id'], $pItem['resource_name'], $pItem['code']]);
            $bRes = $chkLocal->fetch();

            $targetLocalResId = 0;
            if ($bRes) {
                $db->prepare("
                    UPDATE resources SET
                        available_quantity = available_quantity + ?,
                        total_quantity = total_quantity + ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$qtyToAlloc, $qtyToAlloc, $bRes['id']]);
                $targetLocalResId = (int)$bRes['id'];
            } else {
                $localCode = "BRG{$rec['barangay_id']}-" . $pItem['code'];
                $chkCode = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
                $chkCode->execute([$localCode]);
                if ($chkCode->fetch()) $localCode .= '-' . rand(10, 99);

                $insLocal = $db->prepare("
                    INSERT INTO resources (
                        code, name, category, status, description, unit, total_quantity,
                        available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                        storage_location, supplier_donor, item_condition, barangay_id, updated_at, created_at
                    ) VALUES (?, ?, ?, 'active', '', ?, ?, ?, 0, 0, 20, 'Barangay Hall Stockroom', 'ICDRRMO Central Allocation', 'New', ?, NOW(), NOW())
                ");
                $insLocal->execute([
                    $localCode, $pItem['resource_name'], $pItem['category'],
                    $pItem['unit'], $qtyToAlloc, $qtyToAlloc, $rec['barangay_id']
                ]);
                $targetLocalResId = (int)$db->lastInsertId();
            }

            // Update item record
            $db->prepare("
                UPDATE recommended_items SET
                    approved_quantity = ?,
                    allocated_quantity = ?,
                    status = 'Allocated'
                WHERE id = ?
            ")->execute([$qtyToAlloc, $qtyToAlloc, $pItem['id']]);

            // Log transactions
            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Allocation', ?, 'disaster_request', ?, ?, ?, NOW())
            ")->execute([
                $pItem['resource_id'], $qtyToAlloc, $rec['request_id'],
                "Batch allocated {$qtyToAlloc} {$pItem['unit']} to Brgy. {$rec['barangay_name']} ({$rec['tracking_code']})",
                $user['id']
            ]);

            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Restock', ?, 'icdrrmo_allocation', ?, ?, ?, NOW())
            ")->execute([
                $targetLocalResId, $qtyToAlloc, $rec['request_id'],
                "Received {$qtyToAlloc} {$pItem['unit']} from ICDRRMO Central Allocation",
                $user['id']
            ]);

            $allocatedCount++;
            $processedItemIds[] = (int)$pItem['id'];
        }

        // Update recommendation & disaster request status
        $db->prepare("
            UPDATE recommendations SET
                status = 'Approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_remarks = ?
            WHERE id = ?
        ")->execute([$user['id'], $remarks, $recId]);

        $db->prepare("
            UPDATE disaster_requests SET status = 'Allocated', updated_at = NOW() WHERE id = ?
        ")->execute([$rec['request_id']]);

        // Send notification
        $db->prepare("
            INSERT INTO notifications (
                target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at
            ) VALUES ('barangay_head', ?, 'Relief Package Dispatched', ?, 'informational', 'resources', ?, NOW())
        ")->execute([
            $rec['barangay_id'],
            "ICDRRMO has allocated all {$allocatedCount} relief items for Request {$rec['tracking_code']}. Supplies are on route.",
            $rec['request_id']
        ]);

        logSystemEvent(
            'BATCH_RESOURCE_ALLOCATION',
            'Resources',
            "Batch allocated {$allocatedCount} items for {$rec['recommendation_code']} to Brgy. {$rec['barangay_name']}",
            $user['id']
        );

        $db->commit();

        $totalDepotAvail = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND barangay_id IS NULL")->fetchColumn();
        $totalAllocUnits = (int)$db->query("SELECT COALESCE(SUM(allocated_quantity), 0) FROM recommended_items WHERE status = 'Allocated'")->fetchColumn();

        $msg = "Successfully allocated all {$allocatedCount} items for {$rec['recommendation_code']} to Brgy. {$rec['barangay_name']}.";

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $msg,
                'action_type' => 'batch',
                'processed_item_ids' => $processedItemIds,
                'metrics' => [
                    'total_depot_available' => $totalDepotAvail,
                    'total_allocated_units' => $totalAllocUnits
                ]
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $msg);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($isAjax) jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        redirectWithFlash($returnUrl, 'error', 'Batch Allocation Error: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// CASE 3: INDIVIDUAL ITEM ALLOCATION / REJECTION
// ----------------------------------------------------------------------------
else {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $decision = strtolower(trim($_POST['decision'] ?? 'allocate'));
    $quantity = (int)($_POST['quantity'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($itemId <= 0) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid allocation item ID.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Invalid allocation item ID.');
    }

    if (!in_array($decision, ['allocate', 'approve', 'reject', 'decline'], true)) {
        if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid decision action specified.'], 400);
        redirectWithFlash($returnUrl, 'error', 'Invalid decision action specified.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT ri.*, res.id AS central_resource_id, res.name AS resource_name, res.code AS resource_code,
                   res.category, res.unit, res.description, res.available_quantity AS depot_available,
                   res.total_quantity AS depot_total,
                   r.id AS rec_id, r.recommendation_code, dr.id AS request_id, dr.tracking_code,
                   dr.barangay_id, b.name AS barangay_name
            FROM recommended_items ri
            JOIN resources res ON ri.resource_id = res.id
            JOIN recommendations r ON ri.recommendation_id = r.id
            JOIN disaster_requests dr ON r.disaster_request_id = dr.id
            JOIN barangays b ON dr.barangay_id = b.id
            WHERE ri.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new Exception("Allocation item record not found.");
        }

        if ($item['status'] === 'Allocated') {
            throw new Exception("This resource commodity has already been allocated and dispatched.");
        }

        if ($decision === 'allocate' || $decision === 'approve') {
            $allocQty = $quantity > 0 ? $quantity : (int)$item['recommended_quantity'];
            if ($allocQty <= 0) {
                throw new Exception("Allocation quantity must be greater than zero.");
            }

            if ((int)$item['depot_available'] < $allocQty) {
                throw new Exception("Insufficient stock in Central Depot. Only " . number_format($item['depot_available']) . " " . $item['unit'] . " available (requested {$allocQty}).");
            }

            // Deduct Central Depot in Real-Time
            $db->prepare("
                UPDATE resources SET
                    available_quantity = available_quantity - ?,
                    total_quantity = total_quantity - ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$allocQty, $allocQty, $item['central_resource_id']]);

            // Credit Beneficiary Barangay
            $chkLocal = $db->prepare("
                SELECT id FROM resources WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?)) LIMIT 1
            ");
            $chkLocal->execute([$item['barangay_id'], $item['resource_name'], $item['resource_code']]);
            $bRes = $chkLocal->fetch();

            $targetLocalResId = 0;
            if ($bRes) {
                $db->prepare("
                    UPDATE resources SET
                        available_quantity = available_quantity + ?,
                        total_quantity = total_quantity + ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$allocQty, $allocQty, $bRes['id']]);
                $targetLocalResId = (int)$bRes['id'];
            } else {
                $localCode = "BRG{$item['barangay_id']}-" . $item['resource_code'];
                $chkCode = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
                $chkCode->execute([$localCode]);
                if ($chkCode->fetch()) $localCode .= '-' . rand(10, 99);

                $insLocal = $db->prepare("
                    INSERT INTO resources (
                        code, name, category, status, description, unit, total_quantity,
                        available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                        storage_location, supplier_donor, item_condition, barangay_id, updated_at, created_at
                    ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, 20, 'Barangay Hall Stockroom', 'Allocated by ICDRRMO Central Depot', 'New', ?, NOW(), NOW())
                ");
                $insLocal->execute([
                    $localCode, $item['resource_name'], $item['category'],
                    $item['description'], $item['unit'], $allocQty, $allocQty,
                    $item['barangay_id']
                ]);
                $targetLocalResId = (int)$db->lastInsertId();
            }

            // Update recommended_items
            $db->prepare("
                UPDATE recommended_items SET
                    approved_quantity = ?,
                    allocated_quantity = ?,
                    status = 'Allocated'
                WHERE id = ?
            ")->execute([$allocQty, $allocQty, $itemId]);

            // Check if any pending items remain in this recommendation
            $remPending = (int)$db->query("
                SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = {$item['rec_id']} AND status = 'Pending'
            ")->fetchColumn();

            if ($remPending === 0) {
                $db->prepare("
                    UPDATE recommendations SET
                        status = 'Approved',
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE id = ?
                ")->execute([$user['id'], $remarks ?: 'All recommended items allocated', $item['rec_id']]);

                $db->prepare("UPDATE disaster_requests SET status = 'Allocated', updated_at = NOW() WHERE id = ?")
                   ->execute([$item['request_id']]);
            }

            // Audit Trail Movements
            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Allocation', ?, 'disaster_request', ?, ?, ?, NOW())
            ")->execute([
                $item['central_resource_id'], $allocQty, $item['request_id'],
                "Allocated {$allocQty} {$item['unit']} to Brgy. {$item['barangay_name']} (Disaster Request {$item['tracking_code']})" . ($remarks ? " - Note: {$remarks}" : ""),
                $user['id']
            ]);

            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Restock', ?, 'icdrrmo_allocation', ?, ?, ?, NOW())
            ")->execute([
                $targetLocalResId, $allocQty, $item['request_id'],
                "Received {$allocQty} {$item['unit']} allocated by ICDRRMO Central Depot",
                $user['id']
            ]);

            // Notify Barangay Head
            $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at
                ) VALUES ('barangay_head', ?, 'Relief Allocation Dispatched', ?, 'informational', 'resources', ?, NOW())
            ")->execute([
                $item['barangay_id'],
                "ICDRRMO has allocated {$allocQty} {$item['unit']} of {$item['resource_name']} for Incident {$item['tracking_code']}.",
                $item['request_id']
            ]);

            logSystemEvent(
                'ALLOCATE_RESOURCE_ITEM',
                'Resources',
                "Allocated {$allocQty} {$item['unit']} of {$item['resource_name']} to Brgy. {$item['barangay_name']} for {$item['tracking_code']}",
                $user['id']
            );

            $db->commit();

            // Updated metrics
            $remAvail = (int)$db->query("SELECT available_quantity FROM resources WHERE id = {$item['central_resource_id']}")->fetchColumn();
            $remTotal = (int)$db->query("SELECT total_quantity FROM resources WHERE id = {$item['central_resource_id']}")->fetchColumn();
            $totalDepotAvail = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND barangay_id IS NULL")->fetchColumn();
            $totalAllocUnits = (int)$db->query("SELECT COALESCE(SUM(allocated_quantity), 0) FROM recommended_items WHERE status = 'Allocated'")->fetchColumn();

            $successMsg = "Successfully allocated {$allocQty} {$item['unit']} of {$item['resource_name']} to Brgy. {$item['barangay_name']}.";

            if ($isAjax) {
                jsonResponse([
                    'success' => true,
                    'message' => $successMsg,
                    'action_type' => 'allocate',
                    'item_id' => $itemId,
                    'status' => 'Allocated',
                    'allocated_quantity' => $allocQty,
                    'unit' => $item['unit'],
                    'decision_date' => date('M d, Y h:i A'),
                    'remaining_pending_items' => $remPending,
                    'metrics' => [
                        'remaining_depot_available' => $remAvail,
                        'remaining_depot_total' => $remTotal,
                        'total_depot_available' => $totalDepotAvail,
                        'total_allocated_units' => $totalAllocUnits
                    ]
                ]);
            }
            redirectWithFlash($returnUrl, 'success', $successMsg);

        } else {
            // Reject / Decline
            $db->prepare("
                UPDATE recommended_items SET
                    approved_quantity = 0,
                    allocated_quantity = 0,
                    status = 'Rejected'
                WHERE id = ?
            ")->execute([$itemId]);

            // Check if all items in recommendation are resolved
            $remPending = (int)$db->query("
                SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = {$item['rec_id']} AND status = 'Pending'
            ")->fetchColumn();

            if ($remPending === 0) {
                $hasAllocated = (int)$db->query("
                    SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = {$item['rec_id']} AND status = 'Allocated'
                ")->fetchColumn();

                $recStatus = $hasAllocated > 0 ? 'Modified' : 'Rejected';
                $db->prepare("
                    UPDATE recommendations SET
                        status = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE id = ?
                ")->execute([$recStatus, $user['id'], $remarks ?: 'Item declined by ICDRRMO', $item['rec_id']]);
            }

            logSystemEvent(
                'REJECT_RESOURCE_ITEM',
                'Resources',
                "Declined allocation of {$item['resource_name']} for {$item['tracking_code']} (Brgy. {$item['barangay_name']})",
                $user['id']
            );

            $db->commit();

            $totalDepotAvail = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND barangay_id IS NULL")->fetchColumn();
            $totalAllocUnits = (int)$db->query("SELECT COALESCE(SUM(allocated_quantity), 0) FROM recommended_items WHERE status = 'Allocated'")->fetchColumn();

            $rejectMsg = "Item [{$item['resource_name']}] was declined. Zero stock was deducted.";

            if ($isAjax) {
                jsonResponse([
                    'success' => true,
                    'message' => $rejectMsg,
                    'action_type' => 'reject',
                    'item_id' => $itemId,
                    'status' => 'Rejected',
                    'allocated_quantity' => 0,
                    'decision_date' => date('M d, Y h:i A'),
                    'remaining_pending_items' => $remPending,
                    'metrics' => [
                        'total_depot_available' => $totalDepotAvail,
                        'total_allocated_units' => $totalAllocUnits
                    ]
                ]);
            }
            redirectWithFlash($returnUrl, 'success', $rejectMsg);
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($isAjax) jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        redirectWithFlash($returnUrl, 'error', 'Allocation Processing Error: ' . $e->getMessage());
    }
}
