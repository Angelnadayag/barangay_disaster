<?php
// ============================================================================
// Function: Financial Aid - Confirm Receipt or Report Not Received (Barangay Head)
// Handles barangay reception confirmation of calamity cash aid from ICDRRMO
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/financial-aid-distribution.php'));

if ($role !== 'barangay_head') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized. Only Barangay Heads can confirm aid reception.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only Barangay Heads can confirm aid reception.');
}

$allocationId = (int)($_POST['allocation_id'] ?? 0);
$receiptStatus = trim($_POST['receipt_status'] ?? '');
$receiptRemarks = trim($_POST['receipt_remarks'] ?? '');
$barangayId = (int)$user['barangay_id'];

if (!$allocationId) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Allocation ID is required.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Allocation ID is required.');
}

if (!in_array($receiptStatus, ['Received', 'Not Received'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid reception status specified.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid reception status specified.');
}

try {
    // Fetch allocation ensuring it belongs to this barangay
    $stmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ? AND a.barangay_id = ?");
    $stmt->execute([$allocationId, $barangayId]);
    $alloc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alloc) {
        throw new Exception("Allocation record not found or does not belong to your barangay jurisdiction.");
    }

    $barangayName = $alloc['barangay_name'] ?? 'Hinaplanon';

    if ($receiptStatus === 'Received') {
        $upStmt = $db->prepare("
            UPDATE financial_aid_allocations
            SET received_status = 'Received',
                is_distributed = 1,
                received_at = NOW(),
                received_by = ?,
                receipt_remarks = NULL,
                status = 'Active'
            WHERE id = ?
        ");
        $upStmt->execute([$user['id'], $allocationId]);

        // Audit log in financial_aid_audits
        $auditDetails = sprintf(
            "Barangay Head %s confirmed receipt of allocation %s (%s, ₱%s, %s target families) from ICDRRMO. Aid program is now active for beneficiary distribution.",
            $user['full_name'],
            $alloc['allocation_code'],
            $alloc['program_name'],
            number_format((float)$alloc['total_budget'], 2),
            number_format((int)$alloc['target_beneficiaries'])
        );

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (
                ?, NULL, ?, 'Received',
                ?, ?, ?, ?, 'barangay_head'
            )
        ");
        $auditStmt->execute([
            $allocationId, $barangayId,
            $auditDetails, (float)$alloc['total_budget'], $user['id'], $user['full_name']
        ]);

        logSystemEvent('RECEIVED_RESOURCE_AID', 'Manage Resources', "Barangay {$barangayName} received resource allocation {$alloc['allocation_code']}: ₱" . number_format((float)$alloc['total_budget'], 2) . " ({$alloc['program_name']}, {$alloc['target_purok']})");

        // Notify ICDRRMO
        try {
            $notifTitle = "Aid Received: {$alloc['allocation_code']}";
            $notifMsg = "Barangay {$barangayName} has confirmed receipt of {$alloc['allocation_code']} ({$alloc['program_name']}, ₱" . number_format((float)$alloc['total_budget'], 2) . "). The program is now stored and active in their distribution ledger.";
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('icdrrmo', ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
            ");
            $notifStmt->execute([$barangayId, $notifTitle, $notifMsg, $allocationId, $user['id']]);
        } catch (Throwable $e) {}

        // Notify residents of the barangay that the program is now active
        try {
            $resNotifTitle = "Financial Aid Program: {$alloc['program_name']}";
            $resNotifMsg = "Barangay {$barangayName} has received and activated emergency assistance under {$alloc['program_name']} (Target Purok: {$alloc['target_purok']}). Qualified families may check eligibility with their Barangay Representative.";
            $resNotifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('resident', ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
            ");
            $resNotifStmt->execute([$barangayId, $resNotifTitle, $resNotifMsg, $allocationId, $user['id']]);
        } catch (Throwable $e) {}

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => "Allocation {$alloc['allocation_code']} successfully received and stored in Financial Aid Programs.",
                'allocation_id' => $allocationId
            ]);
        }
        redirectWithFlash($returnUrl . (strpos($returnUrl, '?') !== false ? '&' : '?') . 'selected_id=' . $allocationId, 'success', "Allocation {$alloc['allocation_code']} successfully received and stored in Financial Aid Programs.");

    } else { // 'Not Received'
        $upStmt = $db->prepare("
            UPDATE financial_aid_allocations
            SET received_status = 'Not Received',
                received_at = NOW(),
                received_by = ?,
                receipt_remarks = ?,
                status = 'Archived'
            WHERE id = ?
        ");
        $upStmt->execute([$user['id'], $receiptRemarks, $allocationId]);

        // Audit log in financial_aid_audits
        $auditDetails = sprintf(
            "Barangay Head %s marked allocation %s (%s, ₱%s) as NOT RECEIVED. Remarks: %s",
            $user['full_name'],
            $alloc['allocation_code'],
            $alloc['program_name'],
            number_format((float)$alloc['total_budget'], 2),
            $receiptRemarks ?: 'No remarks provided'
        );

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (
                ?, NULL, ?, 'Not Received',
                ?, ?, ?, ?, 'barangay_head'
            )
        ");
        $auditStmt->execute([
            $allocationId, $barangayId,
            $auditDetails, (float)$alloc['total_budget'], $user['id'], $user['full_name']
        ]);

        logSystemEvent('NOT_RECEIVED_RESOURCE_AID', 'Manage Resources', "Barangay {$barangayName} marked resource allocation {$alloc['allocation_code']} as NOT RECEIVED. Reason: " . ($receiptRemarks ?: 'Unspecified'));

        // Notify ICDRRMO with urgent alert
        try {
            $notifTitle = "ALERT: Aid Allocation Not Received ({$alloc['allocation_code']})";
            $notifMsg = "Barangay {$barangayName} reported that allocation {$alloc['allocation_code']} ({$alloc['program_name']}, ₱" . number_format((float)$alloc['total_budget'], 2) . ") was NOT RECEIVED. Remarks: " . ($receiptRemarks ?: 'None provided');
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('icdrrmo', ?, ?, ?, 'danger', 'financial_aid', ?, ?, NOW())
            ");
            $notifStmt->execute([$barangayId, $notifTitle, $notifMsg, $allocationId, $user['id']]);
        } catch (Throwable $e) {}

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => "Allocation {$alloc['allocation_code']} reported as Not Received. ICDRRMO has been alerted.",
                'allocation_id' => $allocationId
            ]);
        }
        redirectWithFlash($returnUrl, 'info', "Allocation {$alloc['allocation_code']} reported as Not Received. ICDRRMO has been notified.");
    }

} catch (Throwable $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
