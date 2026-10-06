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
$action = strtolower(trim($_POST['action'] ?? '')); // 'approve' or 'reject'
$reviewRemarks = trim($_POST['review_remarks'] ?? '');

if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid request ID or action.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid request ID or action.');
}

// Fetch request
$stmt = $db->prepare("SELECT brr.*, b.name AS barangay_name FROM barangay_resource_requests brr JOIN barangays b ON brr.barangay_id = b.id WHERE brr.id = ?");
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

    if ($action === 'approve') {
        $approvedQty = max(1, (int)($_POST['approved_quantity'] ?? $req['requested_quantity']));
        
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

        // 2. If requesting a central catalog item, deduct from Central Depot and credit to Barangay
        if (!empty($req['resource_id'])) {
            $cResStmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
            $cResStmt->execute([$req['resource_id']]);
            $centralRes = $cResStmt->fetch(PDO::FETCH_ASSOC);

            if ($centralRes) {
                // Deduct from Central Depot
                $deductQty = min($approvedQty, (int)$centralRes['available_quantity']);
                $db->prepare("
                    UPDATE resources SET 
                        available_quantity = GREATEST(0, available_quantity - ?),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$deductQty, $centralRes['id']]);

                // Record Central Movement Log
                $db->prepare("
                    INSERT INTO resource_transactions (
                        resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
                    ) VALUES (?, 'Allocation', ?, 'barangay_requisition', ?, ?, ?, NOW())
                ")->execute([
                    $centralRes['id'], $deductQty, $requestId,
                    "Dispatched {$deductQty} {$centralRes['unit']} to Brgy. {$req['barangay_name']} for Requisition {$req['request_code']}",
                    $user['id']
                ]);

                // Check if Barangay already has this resource in local inventory
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
                    // Ensure unique SKU
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
        }

        // Notify Barangay Head
        $notifMsg = "Your Resource Requisition {$req['request_code']} for {$approvedQty} {$req['unit']} of {$req['item_name']} has been APPROVED by ICDRRMO.";
        if (!empty($reviewRemarks)) {
            $notifMsg .= " Remarks: $reviewRemarks";
        }
        $db->prepare("
            INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
            VALUES ('barangay_head', ?, ?, ?, 'info', 'resource_request', ?, NOW())
        ")->execute([$barangayId, "Requisition Approved: {$req['request_code']}", $notifMsg, $requestId]);

        logSystemEvent('APPROVE_REQUISITION', 'Resources', "ICDRRMO approved requisition {$req['request_code']} for Brgy. {$req['barangay_name']} ({$approvedQty} {$req['unit']})");

        $db->commit();

        $successMsg = "Requisition {$req['request_code']} for Barangay {$req['barangay_name']} has been APPROVED and allocated.";
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $successMsg,
                'status' => 'Approved',
                'approved_quantity' => $approvedQty
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $successMsg);

    } else {
        // REJECT ACTION
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
        $notifMsg = "Your Resource Requisition {$req['request_code']} ({$req['item_name']}) was REJECTED by ICDRRMO.";
        if (!empty($reviewRemarks)) {
            $notifMsg .= " Reason: $reviewRemarks";
        }
        $db->prepare("
            INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
            VALUES ('barangay_head', ?, ?, ?, 'warning', 'resource_request', ?, NOW())
        ")->execute([$barangayId, "Requisition Rejected: {$req['request_code']}", $notifMsg, $requestId]);

        logSystemEvent('REJECT_REQUISITION', 'Resources', "ICDRRMO rejected requisition {$req['request_code']} for Brgy. {$req['barangay_name']}. Reason: $reviewRemarks");

        $db->commit();

        $rejectMsg = "Requisition {$req['request_code']} has been rejected.";
        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $rejectMsg,
                'status' => 'Rejected'
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $rejectMsg);
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Transaction failed: ' . $e->getMessage());
}
