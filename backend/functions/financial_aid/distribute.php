<?php
// ============================================================================
// Function: Financial Aid - Distribute (Barangay Operations & Central Audit)
// Handles resident beneficiary disbursements, SMS alerts, and audit logging
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/SmsService.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (
    $role === 'icdrrmo' ? BASE_URL . '/views/icdrrmo/financial-aid-allocation.php' : BASE_URL . '/views/barangay/financial-aid-distribution.php'
));

if (!in_array($role, ['barangay_head', 'icdrrmo'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$userBrgyId = ($role === 'barangay_head') ? (int)$user['barangay_id'] : null;
$action = trim($_POST['action'] ?? $_GET['action'] ?? 'add_beneficiary');

try {
    if ($action === 'add_beneficiary') {
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        $residentId = !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null;
        $householdId = !empty($_POST['household_id']) ? (int)$_POST['household_id'] : null;
        $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
        $purok = trim($_POST['purok'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $claimStatus = in_array($_POST['claim_status'] ?? '', ['Claimed', 'Pending'], true) ? $_POST['claim_status'] : 'Claimed';
        $sendSms = !empty($_POST['send_sms']);
        $remarks = trim($_POST['remarks'] ?? '') ?: null;

        if ($allocationId <= 0) {
            throw new Exception("Please select an active Financial Aid Program.");
        }
        if (empty($beneficiaryName)) {
            throw new Exception("Beneficiary Name is required.");
        }
        if (empty($purok)) {
            throw new Exception("Purok is required.");
        }
        if (empty($phone)) {
            throw new Exception("Contact number is required.");
        }

        // Verify allocation
        $allocStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
        $allocStmt->execute([$allocationId]);
        $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

        if (!$alloc) {
            throw new Exception("Allocation program not found.");
        }

        // Scope check for barangay head
        if ($userBrgyId !== null && (int)$alloc['barangay_id'] !== $userBrgyId) {
            throw new Exception("This allocation belongs to another Barangay.");
        }

        if ($alloc['status'] !== 'Active') {
            throw new Exception("Cannot disburse aid under an inactive or completed allocation program.");
        }

        $amount = (float)($_POST['amount'] ?? $alloc['amount_per_beneficiary']);
        if ($amount <= 0) {
            $amount = (float)$alloc['amount_per_beneficiary'];
        }

        if ($claimStatus === 'Claimed' && (float)$alloc['remaining_budget'] < $amount) {
            throw new Exception("Insufficient remaining allocation budget. Available balance is only ₱" . number_format($alloc['remaining_budget'], 2));
        }

        // Prepare reference number and claim date
        $referenceNo = null;
        $claimDate = null;
        $disbursedBy = null;

        if ($claimStatus === 'Claimed') {
            $randHex = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
            $referenceNo = 'PAY-' . $alloc['barangay_id'] . '-' . date('ymd') . '-' . $randHex;
            $claimDate = date('Y-m-d H:i:s');
            $disbursedBy = $user['id'];
        }

        // Insert distribution record
        $insStmt = $db->prepare("
            INSERT INTO financial_aid_distributions (
                allocation_id, barangay_id, resident_id, household_id, beneficiary_name,
                purok, phone, amount, claim_status, claim_date,
                reference_no, sms_status, disbursed_by, remarks
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 'not_sent', ?, ?
            )
        ");
        $insStmt->execute([
            $allocationId, (int)$alloc['barangay_id'], $residentId, $householdId, $beneficiaryName,
            $purok, $phone, $amount, $claimStatus, $claimDate,
            $referenceNo, $disbursedBy, $remarks
        ]);
        $distId = (int)$db->lastInsertId();

        // If Claimed, update allocation budget
        if ($claimStatus === 'Claimed') {
            $upAlloc = $db->prepare("
                UPDATE financial_aid_allocations 
                SET disbursed_budget = disbursed_budget + ?,
                    remaining_budget = remaining_budget - ?
                WHERE id = ?
            ");
            $upAlloc->execute([$amount, $amount, $allocationId]);
        }

        // Audit Trail Entry
        $actionName = ($claimStatus === 'Claimed') ? 'Disbursed' : 'Status Change';
        $auditText = ($claimStatus === 'Claimed')
            ? sprintf("Disbursed ₱%s cash aid to %s (%s, %s) under %s. Ref: %s. Handled by %s.",
                number_format($amount, 2), $beneficiaryName, $purok, $alloc['barangay_name'], $alloc['allocation_code'], $referenceNo, $user['full_name'])
            : sprintf("Registered %s (%s, %s) as Pending beneficiary for ₱%s under %s.",
                $beneficiaryName, $purok, $alloc['barangay_name'], number_format($amount, 2), $alloc['allocation_code']);

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $auditStmt->execute([
            $allocationId, $distId, (int)$alloc['barangay_id'], $actionName,
            $auditText, $amount, $user['id'], $user['full_name'], $role
        ]);

        logSystemEvent('DISBURSE_FINANCIAL_AID', 'Financial Aid', $auditText);

        // In-app Notification for Resident
        try {
            $notifTitle = ($claimStatus === 'Claimed') ? "Financial Aid Disbursed: {$alloc['program_name']}" : "Eligible for Financial Aid: {$alloc['program_name']}";
            $notifBody = ($claimStatus === 'Claimed')
                ? "Purok {$purok}: ₱" . number_format($amount, 2) . " cash assistance disbursed to {$beneficiaryName} under {$alloc['program_name']}. Ref: " . ($referenceNo ?? $alloc['allocation_code']) . "."
                : "Purok {$purok}: {$beneficiaryName} is listed as an eligible beneficiary for ₱" . number_format($amount, 2) . " under {$alloc['program_name']}. Coordinate with Barangay Hall.";
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, target_user_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('resident', ?, ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
            ");
            $notifStmt->execute([(int)$alloc['barangay_id'], $residentId, $notifTitle, $notifBody, $distId, $user['id']]);
        } catch (Throwable $e) {
            error_log("Failed to insert distribution notification: " . $e->getMessage());
        }

        // SMS Notification dispatch if requested
        $smsResultText = "";
        if ($sendSms && !empty($phone)) {
            $smsMsg = ($claimStatus === 'Claimed')
                ? "Good day {$beneficiaryName}, you have received PHP " . number_format($amount, 2) . " Financial Assistance under {$alloc['program_name']} ({$alloc['barangay_name']}). Ref No: {$referenceNo}."
                : "Good day {$beneficiaryName}, you are listed as an eligible beneficiary for PHP " . number_format($amount, 2) . " Financial Assistance under {$alloc['program_name']}. Please coordinate with Barangay {$alloc['barangay_name']} Hall.";

            try {
                $smsResp = SmsService::send($phone, $smsMsg, $beneficiaryName, 'financial_aid', $distId);
                $smsStatus = ($smsResp['status'] === 'sent' || strpos($smsResp['status'], 'simulated') !== false) ? 'sent' : 'failed';
                $db->prepare("UPDATE financial_aid_distributions SET sms_status = ? WHERE id = ?")->execute([$smsStatus, $distId]);
                $smsResultText = " (SMS Alert: {$smsResp['status']})";
            } catch (Exception $smsErr) {
                $db->prepare("UPDATE financial_aid_distributions SET sms_status = 'failed' WHERE id = ?")->execute([$distId]);
                $smsResultText = " (SMS dispatch error: " . $smsErr->getMessage() . ")";
            }
        }

        $succMsg = ($claimStatus === 'Claimed')
            ? "Successfully disbursed ₱" . number_format($amount, 2) . " to {$beneficiaryName}. Ref: {$referenceNo}{$smsResultText}"
            : "Beneficiary {$beneficiaryName} registered as Pending.{$smsResultText}";

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => $succMsg, 'reference_no' => $referenceNo]);
        }
        redirectWithFlash($returnUrl, 'success', $succMsg);

    } elseif ($action === 'claim') {
        $distId = (int)($_POST['distribution_id'] ?? 0);
        $sendSms = !empty($_POST['send_sms']);

        $stmt = $db->prepare("
            SELECT d.*, a.allocation_code, a.program_name, a.remaining_budget, a.status AS alloc_status, b.name AS barangay_name
            FROM financial_aid_distributions d
            JOIN financial_aid_allocations a ON d.allocation_id = a.id
            JOIN barangays b ON d.barangay_id = b.id
            WHERE d.id = ?
        ");
        $stmt->execute([$distId]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dist) {
            throw new Exception("Distribution record not found.");
        }

        if ($userBrgyId !== null && (int)$dist['barangay_id'] !== $userBrgyId) {
            throw new Exception("Unauthorized: Record belongs to another barangay.");
        }

        if ($dist['claim_status'] === 'Claimed') {
            throw new Exception("Beneficiary has already claimed this financial aid.");
        }

        $amount = (float)$dist['amount'];
        if ((float)$dist['remaining_budget'] < $amount) {
            throw new Exception("Insufficient allocation balance (₱" . number_format($dist['remaining_budget'], 2) . ") to release ₱" . number_format($amount, 2));
        }

        $randHex = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $referenceNo = !empty($dist['reference_no']) ? $dist['reference_no'] : ('PAY-' . $dist['barangay_id'] . '-' . date('ymd') . '-' . $randHex);
        $claimDate = date('Y-m-d H:i:s');

        // Update distribution
        $upDist = $db->prepare("
            UPDATE financial_aid_distributions
            SET claim_status = 'Claimed',
                claim_date = ?,
                reference_no = ?,
                disbursed_by = ?
            WHERE id = ?
        ");
        $upDist->execute([$claimDate, $referenceNo, $user['id'], $distId]);

        // Update allocation budget
        $upAlloc = $db->prepare("
            UPDATE financial_aid_allocations
            SET disbursed_budget = disbursed_budget + ?,
                remaining_budget = remaining_budget - ?
            WHERE id = ?
        ");
        $upAlloc->execute([$amount, $amount, $dist['allocation_id']]);

        // Audit Trail Entry
        $auditText = sprintf("Completed claim payout of ₱%s to %s (%s, %s). Ref: %s. Disbursed by %s.",
            number_format($amount, 2), $dist['beneficiary_name'], $dist['purok'], $dist['barangay_name'], $referenceNo, $user['full_name']);

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (
                ?, ?, ?, 'Disbursed',
                ?, ?, ?, ?, ?
            )
        ");
        $auditStmt->execute([
            $dist['allocation_id'], $distId, (int)$dist['barangay_id'],
            $auditText, $amount, $user['id'], $user['full_name'], $role
        ]);

        logSystemEvent('DISBURSE_FINANCIAL_AID', 'Financial Aid', $auditText);

        // In-app Notification for Resident
        try {
            $notifTitle = "Financial Aid Claimed: {$dist['program_name']}";
            $notifBody = "Purok {$dist['purok']}: Payout confirmation for ₱" . number_format($amount, 2) . " under {$dist['program_name']} claimed by {$dist['beneficiary_name']}. Ref: {$referenceNo}.";
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, target_user_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('resident', ?, ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
            ");
            $notifStmt->execute([(int)$dist['barangay_id'], $dist['resident_id'] ?? null, $notifTitle, $notifBody, $distId, $user['id']]);
        } catch (Throwable $e) {
            error_log("Failed to insert claim notification: " . $e->getMessage());
        }

        // SMS Notification dispatch
        $smsResultText = "";
        if ($sendSms && !empty($dist['phone'])) {
            $smsMsg = "Good day {$dist['beneficiary_name']}, payout confirmation: Received PHP " . number_format($amount, 2) . " Financial Aid under {$dist['program_name']} ({$dist['barangay_name']}). Ref No: {$referenceNo}.";
            try {
                $smsResp = SmsService::send($dist['phone'], $smsMsg, $dist['beneficiary_name'], 'financial_aid', $distId);
                $smsStatus = ($smsResp['status'] === 'sent' || strpos($smsResp['status'], 'simulated') !== false) ? 'sent' : 'failed';
                $db->prepare("UPDATE financial_aid_distributions SET sms_status = ? WHERE id = ?")->execute([$smsStatus, $distId]);
                $smsResultText = " (SMS Notification: {$smsResp['status']})";
            } catch (Exception $smsErr) {
                $db->prepare("UPDATE financial_aid_distributions SET sms_status = 'failed' WHERE id = ?")->execute([$distId]);
            }
        }

        $succMsg = "Beneficiary {$dist['beneficiary_name']} successfully marked as Claimed. Ref: {$referenceNo}{$smsResultText}";

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => $succMsg, 'reference_no' => $referenceNo]);
        }
        redirectWithFlash($returnUrl, 'success', $succMsg);

    } elseif ($action === 'send_sms') {
        $distId = (int)($_POST['distribution_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT d.*, a.program_name, b.name AS barangay_name 
            FROM financial_aid_distributions d 
            JOIN financial_aid_allocations a ON d.allocation_id = a.id 
            JOIN barangays b ON d.barangay_id = b.id 
            WHERE d.id = ?
        ");
        $stmt->execute([$distId]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dist) throw new Exception("Distribution record not found.");
        if (empty($dist['phone'])) throw new Exception("No phone number registered for this beneficiary.");

        $smsMsg = ($dist['claim_status'] === 'Claimed')
            ? "Good day {$dist['beneficiary_name']}, your financial aid payout of PHP " . number_format($dist['amount'], 2) . " under {$dist['program_name']} ({$dist['barangay_name']}) is confirmed. Ref No: {$dist['reference_no']}."
            : "Good day {$dist['beneficiary_name']}, you are scheduled for PHP " . number_format($dist['amount'], 2) . " Financial Aid under {$dist['program_name']}. Please visit Barangay {$dist['barangay_name']} Hall with a valid ID.";

        $smsResp = SmsService::send($dist['phone'], $smsMsg, $dist['beneficiary_name'], 'financial_aid', $distId);
        $smsStatus = ($smsResp['status'] === 'sent' || strpos($smsResp['status'], 'simulated') !== false) ? 'sent' : 'failed';
        $db->prepare("UPDATE financial_aid_distributions SET sms_status = ? WHERE id = ?")->execute([$smsStatus, $distId]);

        $auditText = "Sent SMS alert to {$dist['beneficiary_name']} ({$dist['phone']}) for {$dist['program_name']}. Status: {$smsStatus}.";
        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, ?, 'Status Change', ?, 0.00, ?, ?, ?)
        ");
        $auditStmt->execute([$dist['allocation_id'], $distId, $dist['barangay_id'], $auditText, $user['id'], $user['full_name'], $role]);

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "SMS dispatched to {$dist['phone']}. Status: {$smsStatus}."]);
        }
        redirectWithFlash($returnUrl, 'success', "SMS dispatched to {$dist['phone']}. Status: {$smsStatus}.");

    } elseif ($action === 'void') {
        $distId = (int)($_POST['distribution_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT d.*, a.allocation_code, a.program_name, b.name AS barangay_name 
            FROM financial_aid_distributions d 
            JOIN financial_aid_allocations a ON d.allocation_id = a.id 
            JOIN barangays b ON d.barangay_id = b.id 
            WHERE d.id = ?
        ");
        $stmt->execute([$distId]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dist) throw new Exception("Distribution record not found.");
        if ($userBrgyId !== null && (int)$dist['barangay_id'] !== $userBrgyId) {
            throw new Exception("Unauthorized: Record belongs to another barangay.");
        }

        $amount = (float)$dist['amount'];

        // If it was claimed, refund to allocation
        if ($dist['claim_status'] === 'Claimed') {
            $upAlloc = $db->prepare("
                UPDATE financial_aid_allocations 
                SET disbursed_budget = GREATEST(0, disbursed_budget - ?),
                    remaining_budget = remaining_budget + ?
                WHERE id = ?
            ");
            $upAlloc->execute([$amount, $amount, $dist['allocation_id']]);
        }

        // Set to Unclaimed / Voided
        $upDist = $db->prepare("UPDATE financial_aid_distributions SET claim_status = 'Unclaimed', remarks = CONCAT(COALESCE(remarks, ''), ' [Voided/Cancelled]') WHERE id = ?");
        $upDist->execute([$distId]);

        $auditText = sprintf("Voided financial aid distribution for %s (₱%s) under %s. Funds reverted to allocation balance. Action by %s.",
            $dist['beneficiary_name'], number_format($amount, 2), $dist['allocation_code'], $user['full_name']);

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, ?, 'Voided', ?, ?, ?, ?, ?)
        ");
        $auditStmt->execute([$dist['allocation_id'], $distId, $dist['barangay_id'], $auditText, $amount, $user['id'], $user['full_name'], $role]);

        logSystemEvent('VOID_FINANCIAL_AID', 'Financial Aid', $auditText);

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "Distribution for {$dist['beneficiary_name']} voided. Allocation funds restored."]);
        }
        redirectWithFlash($returnUrl, 'success', "Distribution for {$dist['beneficiary_name']} voided. Allocation funds restored.");

    } else {
        throw new Exception("Invalid distribution action.");
    }

} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
