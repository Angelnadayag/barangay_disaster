<?php
// ============================================================================
// Function: Resources - ICDRRMO Review Barangay Resource Requisition
// Approve (with automatic stock transfer/allocation to Barangay) or Reject with remarks
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/manage-resources.php?tab=requests'));

if ($role !== 'icdrrmo') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO Admin can review resource requests.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$requestId = (int)($_POST['request_id'] ?? ($_POST['id'] ?? 0));
$action = strtolower(trim($_POST['action'] ?? '')); // 'approve', 'accept', 'reject', 'decline'
$reviewRemarks = trim($_POST['review_remarks'] ?? '');

$isApprove = in_array($action, ['approve', 'accept'], true);
$isDecline = in_array($action, ['reject', 'decline'], true);

if (!$requestId || (!$isApprove && !$isDecline)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid request ID or action.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid request ID or action.');
}

// Fetch request with barangay information
$stmt = $db->prepare("
    SELECT brr.*, b.name AS barangay_name, u.full_name AS requested_by_name
    FROM barangay_resource_requests brr
    JOIN barangays b ON brr.barangay_id = b.id
    LEFT JOIN users u ON brr.requested_by = u.id
    WHERE brr.id = ?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Requisition request not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Requisition request not found.');
}

if ($req['status'] !== 'Pending') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => "This request has already been {$req['status']}."], 400);
    redirectWithFlash($returnUrl, 'error', "This request has already been {$req['status']}.");
}

$barangayId = (int)$req['barangay_id'];

try {
    $db->beginTransaction();

    if ($isApprove) {
        $approvedQty = max(1, (int)($_POST['approved_quantity'] ?? $req['requested_quantity']));

        // Resolve central resource
        $targetResId = !empty($_POST['selected_resource_id']) ? (int)$_POST['selected_resource_id'] : (int)$req['resource_id'];
        $centralRes = null;

        if ($targetResId) {
            $cResStmt = $db->prepare("SELECT * FROM resources WHERE id = ? AND barangay_id IS NULL FOR UPDATE");
            $cResStmt->execute([$targetResId]);
            $centralRes = $cResStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$centralRes && !empty($req['item_name'])) {
            // Auto-match central resource by item name
            $cResStmt = $db->prepare("SELECT * FROM resources WHERE barangay_id IS NULL AND LOWER(name) = LOWER(?) FOR UPDATE");
            $cResStmt->execute([trim($req['item_name'])]);
            $centralRes = $cResStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($centralRes) {
            // Verify stock availability
            if ((int)$centralRes['available_quantity'] < $approvedQty) {
                throw new Exception("Cannot approve {$approvedQty} {$centralRes['unit']}. ICDRRMO Central Depot only has {$centralRes['available_quantity']} {$centralRes['unit']} available.");
            }

            // Real-time stock deduction from Central Depot: deduct BOTH available and total quantity
            $db->prepare("
                UPDATE resources SET 
                    available_quantity = GREATEST(0, available_quantity - ?),
                    total_quantity = GREATEST(0, total_quantity - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$approvedQty, $approvedQty, $centralRes['id']]);

            // Link resource_id on requisition if not linked
            $db->prepare("UPDATE barangay_resource_requests SET resource_id = ? WHERE id = ?")->execute([$centralRes['id'], $requestId]);

            // Record Central Movement Log
            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Allocation', ?, 'barangay_requisition', ?, ?, ?, NOW())
            ")->execute([
                $centralRes['id'], $approvedQty, $requestId,
                "Dispatched {$approvedQty} {$centralRes['unit']} to Brgy. {$req['barangay_name']} for Requisition {$req['request_code']}" . ($reviewRemarks ? " (Note: {$reviewRemarks})" : ''),
                $user['id']
            ]);

            // Credit to Barangay's Local Inventory
            $bResStmt = $db->prepare("SELECT id FROM resources WHERE barangay_id = ? AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?))");
            $bResStmt->execute([$barangayId, $centralRes['name'], "BRG{$barangayId}-" . $centralRes['code']]);
            $bRes = $bResStmt->fetch(PDO::FETCH_ASSOC);

            if ($bRes) {
                // Restock existing local item
                $db->prepare("
                    UPDATE resources SET 
                        available_quantity = available_quantity + ?,
                        total_quantity = total_quantity + ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$approvedQty, $approvedQty, $bRes['id']]);
                $targetLocalResId = $bRes['id'];
            } else {
                // Create new local inventory item for this Barangay
                $localCode = "BRG{$barangayId}-" . $centralRes['code'];
                $chkCode = $db->prepare("SELECT id FROM resources WHERE LOWER(code) = LOWER(?)");
                $chkCode->execute([$localCode]);
                if ($chkCode->fetch()) $localCode .= '-' . rand(10, 99);

                $insLocal = $db->prepare("
                    INSERT INTO resources (
                        code, name, category, status, description, unit, total_quantity,
                        available_quantity, in_use_quantity, damaged_quantity, min_threshold,
                        storage_location, supplier_donor, item_condition, barangay_id, updated_at, created_at
                    ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, 0, 0, 20, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insLocal->execute([
                    $localCode, $centralRes['name'], $centralRes['category'],
                    $centralRes['description'], $centralRes['unit'], $approvedQty,
                    $approvedQty, 'Barangay Hall Stockroom', "Allocated by ICDRRMO Central Depot ({$req['request_code']})",
                    'New', $barangayId
                ]);
                $targetLocalResId = (int)$db->lastInsertId();
            }

            // Log Barangay receipt transaction
            $db->prepare("
                INSERT INTO resource_transactions (
                    resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                ) VALUES (?, 'Restock', ?, 'icdrrmo_allocation', ?, ?, ?, NOW())
            ")->execute([
                $targetLocalResId, $approvedQty, $requestId,
                "Received {$approvedQty} {$req['unit']} allocated by ICDRRMO (Requisition {$req['request_code']})",
                $user['id']
            ]);
        }

        // 1. Update requisition status
        $upd = $db->prepare("
            UPDATE barangay_resource_requests SET
                status = 'Approved',
                approved_quantity = ?,
                review_remarks = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$approvedQty, $reviewRemarks, $user['id'], $requestId]);

        // Notify Barangay Head
        $notifMsg = "Your Resource Requisition {$req['request_code']} for {$approvedQty} {$req['unit']} of {$req['item_name']} has been ACCEPTED by ICDRRMO.";
        if (!empty($reviewRemarks)) {
            $notifMsg .= " Remarks: $reviewRemarks";
        }
        $db->prepare("
            INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
            VALUES ('barangay_head', ?, ?, ?, 'info', 'resource_request', ?, NOW())
        ")->execute([$barangayId, "Requisition Accepted: {$req['request_code']}", $notifMsg, $requestId]);

        logSystemEvent('APPROVE_REQUISITION', 'Resources', "ICDRRMO accepted requisition {$req['request_code']} for Brgy. {$req['barangay_name']} ({$approvedQty} {$req['unit']})");

        $db->commit();

        $remainingAvail = $centralRes ? max(0, (int)$centralRes['available_quantity'] - $approvedQty) : 0;
        $remainingTotal = $centralRes ? max(0, (int)$centralRes['total_quantity'] - $approvedQty) : 0;

        $successMsg = "Requisition {$req['request_code']} for Barangay {$req['barangay_name']} has been ACCEPTED and {$approvedQty} {$req['unit']} deducted from Central Depot stock in real-time.";
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $successMsg,
                'status' => 'Approved',
                'request_id' => $requestId,
                'request_code' => $req['request_code'],
                'approved_quantity' => $approvedQty,
                'resource_id' => $centralRes ? (int)$centralRes['id'] : null,
                'remaining_available' => $remainingAvail,
                'remaining_total' => $remainingTotal,
                'barangay_name' => $req['barangay_name']
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $successMsg);

    } else {
        // DECLINE / REJECT ACTION
        $upd = $db->prepare("
            UPDATE barangay_resource_requests SET
                status = 'Rejected',
                review_remarks = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$reviewRemarks, $user['id'], $requestId]);

        // Notify Barangay Head
        $notifMsg = "Your Resource Requisition {$req['request_code']} ({$req['item_name']}) was DECLINED by ICDRRMO.";
        if (!empty($reviewRemarks)) {
            $notifMsg .= " Reason: $reviewRemarks";
        }
        $db->prepare("
            INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
            VALUES ('barangay_head', ?, ?, ?, 'warning', 'resource_request', ?, NOW())
        ")->execute([$barangayId, "Requisition Declined: {$req['request_code']}", $notifMsg, $requestId]);

        logSystemEvent('REJECT_REQUISITION', 'Resources', "ICDRRMO declined requisition {$req['request_code']} for Brgy. {$req['barangay_name']}. Reason: $reviewRemarks");

        $db->commit();

        $rejectMsg = "Requisition {$req['request_code']} has been DECLINED.";
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $rejectMsg,
                'status' => 'Rejected',
                'request_id' => $requestId,
                'request_code' => $req['request_code']
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $rejectMsg);
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()], 400);
    redirectWithFlash($returnUrl, 'error', 'Operation failed: ' . $e->getMessage());
}
