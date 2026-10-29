<?php
// ============================================================================
// Function: Resources - Barangay Confirm or Report Receipt of Requisition Supplies
// Dynamic Stock Adjustment:
// - If Received: Barangay inventory increases (+quantity) dynamically in agency_resources & resources.
// - If Not Received: Central Depot quantity is restored (+quantity), and any previously received stock is deducted.
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/resources.php?tab=requests'));

if ($role !== 'barangay_head') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized. Only Barangay Heads can confirm resource receipt.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$receiptStatus = trim($_POST['receipt_status'] ?? '');
$receiptRemarks = trim($_POST['receipt_remarks'] ?? '');

if (!in_array($receiptStatus, ['Received', 'Not Received'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid delivery receipt status.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid delivery receipt status.');
}

if (!$requestId) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Requisition ID is required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Requisition ID is required.');
}

$barangayId = (int)$user['barangay_id'];

// Fetch requisition
$stmt = $db->prepare("
    SELECT brr.*, b.name AS barangay_name
    FROM barangay_resource_requests brr
    JOIN barangays b ON brr.barangay_id = b.id
    WHERE brr.id = ? AND brr.barangay_id = ?
");
$stmt->execute([$requestId, $barangayId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Requisition request not found or does not belong to your barangay.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Requisition request not found.');
}

if ($req['status'] !== 'Approved') {
    $msg = "Cannot update receipt status. This requisition is currently {$req['status']}. Only Approved requisitions can be confirmed as Received or Not Received.";
    if ($isAjax) jsonResponse(['success' => false, 'message' => $msg], 400);
    redirectWithFlash($returnUrl, 'error', $msg);
}

$prevReceiptStatus = $req['receipt_status'] ?? 'Pending';
$qty = max(1, (int)($req['approved_quantity'] ?: $req['requested_quantity']));
$unit = $req['unit'];
$item = $req['item_name'];
$code = $req['request_code'];
$bName = $req['barangay_name'] ?? 'Barangay';
$now = date('Y-m-d H:i:s');

try {
    $db->beginTransaction();

    // 1. Resolve Central Depot Resource
    $centralResId = !empty($req['resource_id']) ? (int)$req['resource_id'] : 0;
    if (!$centralResId) {
        $cStmt = $db->prepare("SELECT id FROM resources WHERE (barangay_id IS NULL OR barangay_id = 0) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $cStmt->execute([$item]);
        $centralResId = (int)$cStmt->fetchColumn();
    }
    if ($centralResId) {
        $db->prepare("UPDATE barangay_resource_requests SET resource_id = ? WHERE id = ?")->execute([$centralResId, $requestId]);
    }

    // 2. Resolve Barangay Agency (for agency_resources)
    $agStmt = $db->prepare("SELECT id FROM agencies WHERE barangay_id = ? LIMIT 1");
    $agStmt->execute([$barangayId]);
    $agencyId = (int)$agStmt->fetchColumn();
    if (!$agencyId) {
        $db->prepare("INSERT INTO agencies (name, agency_type, barangay_id, created_at) VALUES (?, 'BDRRMC', ?, NOW())")
           ->execute(["BDRRMC - {$bName}", $barangayId]);
        $agencyId = (int)$db->lastInsertId();
    }

    // ------------------------------------------------------------------------
    // CASE A: Changing to "Received"
    // ------------------------------------------------------------------------
    if ($receiptStatus === 'Received' && $prevReceiptStatus !== 'Received') {
        // 1. Increase Barangay Inventory in agency_resources
        if ($agencyId && $centralResId) {
            $chkAr = $db->prepare("SELECT id FROM agency_resources WHERE agency_id = ? AND resource_id = ? LIMIT 1 FOR UPDATE");
            $chkAr->execute([$agencyId, $centralResId]);
            $arId = (int)$chkAr->fetchColumn();
            if ($arId) {
                $db->prepare("UPDATE agency_resources SET available_quantity = available_quantity + ?, total_quantity = total_quantity + ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$qty, $qty, $arId]);
            } else {
                $db->prepare("
                    INSERT INTO agency_resources (
                        agency_id, resource_id, total_quantity, available_quantity, in_use_quantity,
                        damaged_quantity, min_threshold, item_condition, status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 0, 0, 20, 'New', 'active', NOW(), NOW())
                ")->execute([$agencyId, $centralResId, $qty, $qty]);
            }
        }

        // 2. Also increase/create local resource in resources table (for barangay_id = 12)
        $chkLocal = $db->prepare("SELECT id FROM resources WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR id = ?) LIMIT 1 FOR UPDATE");
        $chkLocal->execute([$barangayId, $item, $centralResId]);
        $localRes = $chkLocal->fetch(PDO::FETCH_ASSOC);

        $targetLocalResId = 0;
        if ($localRes) {
            $db->prepare("UPDATE resources SET available_quantity = available_quantity + ?, total_quantity = total_quantity + ?, updated_at = NOW() WHERE id = ?")
               ->execute([$qty, $qty, $localRes['id']]);
            $targetLocalResId = (int)$localRes['id'];
        } else {
            $cDetailStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
            $cDetailStmt->execute([$centralResId]);
            $cDetail = $cDetailStmt->fetch(PDO::FETCH_ASSOC);

            $localCode = "BRG{$barangayId}-" . ($cDetail['code'] ?? ('ITEM-' . rand(100, 999)));
            $chkCode = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
            $chkCode->execute([$localCode]);
            if ($chkCode->fetch()) $localCode .= '-' . rand(10, 99);

            $suppId = $cDetail['supplier_id'] ?? null;
            $insLocal = $db->prepare("
                INSERT INTO resources (
                    code, name, category, status, description, unit, total_quantity,
                    available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                    supplier_id, item_condition, barangay_id, updated_at, created_at
                ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, 20, ?, 'New', ?, NOW(), NOW())
            ");
            $insLocal->execute([
                $localCode, $item, $req['category'],
                $cDetail['description'] ?? '', $unit, $qty,
                $qty, $suppId, $barangayId
            ]);
            $targetLocalResId = (int)$db->lastInsertId();
        }

        // 3. Log Barangay Restock Transaction
        $db->prepare("
            INSERT INTO resource_transactions (
                resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
            ) VALUES (?, 'Restock', ?, 'barangay_requisition', ?, ?, ?, NOW())
        ")->execute([
            $targetLocalResId ?: ($centralResId ?: 0),
            $qty,
            $requestId,
            "Received {$qty} {$unit} for Requisition {$code} from ICDRRMO Central Depot" . ($receiptRemarks ? " (Note: {$receiptRemarks})" : ""),
            $user['id']
        ]);

        // 4. Central Depot adjustment if previously marked "Not Received"
        // (because when it was marked Not Received, Central Depot had the stock restored back)
        if ($prevReceiptStatus === 'Not Received' && $centralResId) {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = GREATEST(0, available_quantity - ?),
                    total_quantity = GREATEST(0, total_quantity - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $centralResId]);

            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Allocation', ?, 'barangay_requisition', ?, ?, ?, NOW())
            ")->execute([
                $centralResId,
                $qty,
                $requestId,
                "Re-deducted {$qty} {$unit}: Requisition {$code} confirmed Received by Brgy. {$bName}",
                $user['id']
            ]);
        }
    }

    // ------------------------------------------------------------------------
    // CASE B: Changing to "Not Received"
    // ------------------------------------------------------------------------
    elseif ($receiptStatus === 'Not Received' && $prevReceiptStatus !== 'Not Received') {
        // 1. Restore deducted quantity back to ICDRRMO Central Depot (+qty)
        if ($centralResId) {
            $db->prepare("
                UPDATE resources SET
                    available_quantity = available_quantity + ?,
                    total_quantity = total_quantity + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$qty, $qty, $centralResId]);

            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Returned', ?, 'barangay_requisition', ?, ?, ?, NOW())
            ")->execute([
                $centralResId,
                $qty,
                $requestId,
                "Restored {$qty} {$unit} to Central Depot: Requisition {$code} reported Not Received by Brgy. {$bName}" . ($receiptRemarks ? " (Reason: {$receiptRemarks})" : ""),
                $user['id']
            ]);
        }

        // 2. If it was previously marked "Received", reverse the credited stock from Barangay
        if ($prevReceiptStatus === 'Received') {
            if ($agencyId && $centralResId) {
                $db->prepare("
                    UPDATE agency_resources SET
                        available_quantity = GREATEST(0, available_quantity - ?),
                        total_quantity = GREATEST(0, total_quantity - ?),
                        updated_at = NOW()
                    WHERE agency_id = ? AND resource_id = ?
                ")->execute([$qty, $qty, $agencyId, $centralResId]);
            }

            $chkLocal = $db->prepare("SELECT id FROM resources WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR id = ?) LIMIT 1 FOR UPDATE");
            $chkLocal->execute([$barangayId, $item, $centralResId]);
            $localRes = $chkLocal->fetch(PDO::FETCH_ASSOC);

            if ($localRes) {
                $db->prepare("
                    UPDATE resources SET
                        available_quantity = GREATEST(0, available_quantity - ?),
                        total_quantity = GREATEST(0, total_quantity - ?),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$qty, $qty, $localRes['id']]);

                $db->prepare("
                    INSERT INTO resource_transactions (
                        resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                    ) VALUES (?, 'Adjustment', ?, 'barangay_requisition', ?, ?, ?, NOW())
                ")->execute([
                    $localRes['id'],
                    $qty,
                    $requestId,
                    "Reversed receipt of {$qty} {$unit} for Requisition {$code} (Flagged Not Received)",
                    $user['id']
                ]);
            }
        }
    }

    // 3. Update Requisition record
    $updStmt = $db->prepare("
        UPDATE barangay_resource_requests SET
            receipt_status = ?,
            received_at = ?,
            received_by = ?,
            receipt_remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updStmt->execute([$receiptStatus, $now, $user['id'], $receiptRemarks, $requestId]);

    // 4. Send Notification to ICDRRMO
    $notifTitle = ($receiptStatus === 'Received')
        ? "Requisition Delivery Confirmed: {$code}"
        : "Requisition Delivery Issue Reported: {$code}";
    
    $notifMsg = ($receiptStatus === 'Received')
        ? "Brgy. {$bName} confirmed receipt of {$qty} {$unit} of {$item} (Requisition {$code}). Local stock updated." . ($receiptRemarks ? " Note: {$receiptRemarks}" : "")
        : "Brgy. {$bName} reported NOT RECEIVED for {$qty} {$unit} of {$item} (Requisition {$code}). Deducted stock restored to Central Depot." . ($receiptRemarks ? " Reason: {$receiptRemarks}" : "");
    
    $alertLevel = ($receiptStatus === 'Received') ? 'info' : 'urgent';

    $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
        VALUES ('icdrrmo', ?, ?, ?, ?, 'resource_request', ?, NOW())
    ")->execute([$barangayId, $notifTitle, $notifMsg, $alertLevel, $requestId]);

    $receiptAction = ($receiptStatus === 'Received') ? 'RECEIVED_SUPPLY' : 'NOT_RECEIVED_SUPPLY';
    $receiptLogDetails = ($receiptStatus === 'Received')
        ? "Brgy. {$bName} confirmed RECEIVED for requisition {$code} ({$qty} {$unit} of {$item}). Stockroom quantity dynamically increased."
        : "Brgy. {$bName} marked NOT RECEIVED for requisition {$code} ({$qty} {$unit} of {$item}). Deducted quantity returned back to ICDRRMO Central Depot." . ($receiptRemarks ? " Reason: {$receiptRemarks}" : "");

    logSystemEvent(
        $receiptAction,
        'Supplies',
        $receiptLogDetails
    );

    $db->commit();

    $successMsg = ($receiptStatus === 'Received')
        ? "Requisition {$code} successfully confirmed as Received. Your local inventory has been increased by {$qty} {$unit}."
        : "Requisition {$code} marked as Not Received. Central Depot quantity has been restored and ICDRRMO has been alerted.";

    if ($isAjax) {
        $liveArStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM agency_resources WHERE agency_id = {$agencyId} AND (status != 'archived' OR status IS NULL)")->fetchColumn();
        jsonResponse([
            'success' => true,
            'message' => $successMsg,
            'request_id' => $requestId,
            'receipt_status' => $receiptStatus,
            'received_at' => $now,
            'received_by_name' => $user['full_name'] ?? 'Barangay Head',
            'receipt_remarks' => $receiptRemarks,
            'live_local_stock' => $liveArStock
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $successMsg);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to update receipt status: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to update receipt status: ' . $e->getMessage());
}
