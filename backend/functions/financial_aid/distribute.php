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
    if ($action === 'save_distribution_plan') {
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        $programName = trim($_POST['program_name'] ?? '');
        $disasterType = trim($_POST['disaster_type'] ?? '') ?: 'General Calamity';
        $targetPurok = trim($_POST['target_purok'] ?? 'All Puroks');
        if ($targetPurok === '__custom__' || !empty($_POST['custom_target_purok'])) {
            $customP = trim($_POST['custom_target_purok'] ?? '');
            if (!empty($customP)) {
                $targetPurok = $customP;
            }
        }
        if (empty($targetPurok) || $targetPurok === '__custom__') $targetPurok = 'All Puroks';
        $totalBudget = (float)($_POST['total_budget'] ?? 0);
        $amountPerBeneficiary = (float)($_POST['amount_per_beneficiary'] ?? 0);
        $fundingSource = trim($_POST['funding_source'] ?? 'LGU Calamity Fund');
        $addedType = trim($_POST['added_type'] ?? 'ai_recommended');
        if (!in_array($addedType, ['ai_recommended', 'added_by_admin'], true)) {
            $addedType = 'ai_recommended';
        }
        $notes = trim($_POST['notes'] ?? '') ?: null;

        if (empty($programName)) {
            throw new Exception("Program Name is required.");
        }
        if ($totalBudget <= 0) {
            throw new Exception("Total Budget must be greater than zero.");
        }
        if ($amountPerBeneficiary <= 0) {
            throw new Exception("Amount per Family must be greater than zero.");
        }
        if ($amountPerBeneficiary > $totalBudget) {
            throw new Exception("Amount per Family cannot exceed Total Budget.");
        }

        $targetBeneficiaries = (int)floor($totalBudget / $amountPerBeneficiary);
        $bId = $userBrgyId ?: 12;

        if ($allocationId > 0) {
            $checkStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
            $checkStmt->execute([$allocationId]);
            $alloc = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$alloc) {
                throw new Exception("Financial aid program record not found.");
            }
            if ($userBrgyId !== null && (int)$alloc['barangay_id'] !== $userBrgyId) {
                throw new Exception("Unauthorized: Program belongs to another barangay.");
            }

            $disbursed = (float)$alloc['disbursed_budget'];
            $remaining = max(0, $totalBudget - $disbursed);

            $upStmt = $db->prepare("
                UPDATE financial_aid_allocations
                SET program_name = ?,
                    disaster_type = ?,
                    target_purok = ?,
                    total_budget = ?,
                    amount_per_beneficiary = ?,
                    remaining_budget = ?,
                    funding_source = ?,
                    target_beneficiaries = ?,
                    added_type = ?,
                    notes = ?,
                    is_distributed = 1,
                    status = 'Active'
                WHERE id = ?
            ");
            $upStmt->execute([
                $programName, $disasterType, $targetPurok, $totalBudget,
                $amountPerBeneficiary, $remaining, $fundingSource,
                $targetBeneficiaries, $addedType, $notes, $allocationId
            ]);

            // Sync with event if linked
            if (!empty($alloc['event_id'])) {
                $db->prepare("UPDATE events SET target_puroks = ? WHERE id = ?")
                   ->execute([$targetPurok, $alloc['event_id']]);
            }

            $auditText = sprintf(
                "Barangay Head updated distribution plan for %s (%s): Budget ₱%s, ₱%s/family, Scope: %s [%s].",
                $programName, $alloc['allocation_code'], number_format($totalBudget, 2),
                number_format($amountPerBeneficiary, 2), $targetPurok,
                ($addedType === 'ai_recommended' ? 'AI Recommended' : 'Added by Admin')
            );
            $auditStmt = $db->prepare("
                INSERT INTO financial_aid_audits (
                    allocation_id, barangay_id, action, details, amount, actor_id, actor_name, actor_role
                ) VALUES (?, ?, 'Allocated', ?, ?, ?, ?, ?)
            ");
            $auditStmt->execute([
                $allocationId, (int)$alloc['barangay_id'], $auditText,
                $totalBudget, $user['id'], $user['full_name'], $role
            ]);
            logSystemEvent('UPDATE_RESOURCE_FUND_PLAN', 'Manage Resources', $auditText);

            $succMsg = "Financial aid distribution for '{$programName}' configured successfully.";
        } else {
            $year = date('Y');
            $cntStmt = $db->query("SELECT COUNT(*) FROM financial_aid_allocations");
            $nextNum = ((int)$cntStmt->fetchColumn()) + 1;
            $allocationCode = 'AID-' . $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            $insStmt = $db->prepare("
                INSERT INTO financial_aid_allocations (
                    allocation_code, program_name, disaster_type, barangay_id, target_purok,
                    total_budget, amount_per_beneficiary, disbursed_budget, remaining_budget,
                    funding_source, target_beneficiaries, is_distributed, status, added_type, notes, allocated_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, 0.00, ?,
                    ?, ?, 1, 'Active', ?, ?, ?, NOW()
                )
            ");
            $insStmt->execute([
                $allocationCode, $programName, $disasterType, $bId, $targetPurok,
                $totalBudget, $amountPerBeneficiary, $totalBudget,
                $fundingSource, $targetBeneficiaries, $addedType, $notes, $user['id']
            ]);
            $allocationId = (int)$db->lastInsertId();

            $auditText = sprintf(
                "Barangay Head created financial aid distribution %s (%s): Budget ₱%s, ₱%s/family, Scope: %s [%s].",
                $programName, $allocationCode, number_format($totalBudget, 2),
                number_format($amountPerBeneficiary, 2), $targetPurok,
                ($addedType === 'ai_recommended' ? 'AI Recommended' : 'Added by Admin')
            );
            $auditStmt = $db->prepare("
                INSERT INTO financial_aid_audits (
                    allocation_id, barangay_id, action, details, amount, actor_id, actor_name, actor_role
                ) VALUES (?, ?, 'Allocated', ?, ?, ?, ?, ?)
            ");
            $auditStmt->execute([
                $allocationId, $bId, $auditText,
                $totalBudget, $user['id'], $user['full_name'], $role
            ]);
            logSystemEvent('CREATE_RESOURCE_FUND_PLAN', 'Manage Resources', $auditText);

            $succMsg = "Financial aid distribution for '{$programName}' created successfully.";
        }

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => $succMsg,
                'allocation_id' => $allocationId
            ]);
        }
        redirectWithFlash($returnUrl . (strpos($returnUrl, '?') !== false ? '&' : '?') . 'selected_id=' . $allocationId, 'success', $succMsg);

    } elseif ($action === 'add_beneficiary') {
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
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $claimDate = null;
        $disbursedBy = null;

        if ($claimStatus === 'Claimed') {
            if (empty($referenceNo)) {
                $randHex = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
                $referenceNo = 'PAY-' . $alloc['barangay_id'] . '-' . date('ymd') . '-' . $randHex;
            }
            $claimDate = date('Y-m-d H:i:s');
            $disbursedBy = $user['id'];
        }

        // Retrieve household info if available
        $hhInfo = null;
        if ($householdId > 0) {
            $hhInfoStmt = $db->prepare("SELECT household_code, family_name, head_full_name, head_relation, total_members, purok_name FROM purok_households WHERE id = ?");
            $hhInfoStmt->execute([$householdId]);
            $hhInfo = $hhInfoStmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($residentId > 0) {
            $hhInfoStmt = $db->prepare("SELECT id AS household_id, household_code, family_name, head_full_name, head_relation, total_members, purok_name FROM purok_households WHERE head_resident_id = ? LIMIT 1");
            $hhInfoStmt->execute([$residentId]);
            $hhInfo = $hhInfoStmt->fetch(PDO::FETCH_ASSOC);
            if ($hhInfo && empty($householdId)) {
                $householdId = (int)$hhInfo['household_id'];
            }
        }

        // Check if a distribution record already exists for this household in this allocation
        $existingDist = null;
        if ($householdId > 0) {
            $findStmt = $db->prepare("SELECT * FROM financial_aid_distributions WHERE allocation_id = ? AND household_id = ? ORDER BY id DESC LIMIT 1");
            $findStmt->execute([$allocationId, $householdId]);
            $existingDist = $findStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingDist) {
            $distId = (int)$existingDist['id'];
            if ($existingDist['claim_status'] === 'Claimed' && $claimStatus === 'Claimed') {
                if ($isAjax) {
                    jsonResponse([
                        'success' => true,
                        'message' => "Aid already disbursed for {$beneficiaryName}.",
                        'reference_no' => $existingDist['reference_no'],
                        'distribution_id' => $distId,
                        'claim_date' => $existingDist['claim_date'],
                        'sms_status' => $existingDist['sms_status']
                    ]);
                }
                redirectWithFlash($returnUrl, 'info', "Beneficiary already claimed.");
            }

            // Re-activate or update existing record
            $wasClaimed = ($existingDist['claim_status'] === 'Claimed');
            $upStmt = $db->prepare("
                UPDATE financial_aid_distributions
                SET claim_status = ?,
                    claim_date = ?,
                    reference_no = COALESCE(?, reference_no),
                    disbursed_by = ?,
                    amount = ?,
                    beneficiary_name = ?,
                    purok = ?,
                    phone = ?,
                    remarks = CONCAT(COALESCE(remarks, ''), ' [Re-issued]')
                WHERE id = ?
            ");
            $upStmt->execute([
                $claimStatus, $claimDate, $referenceNo, $disbursedBy,
                $amount, $beneficiaryName, $purok, $phone, $distId
            ]);

            // Update budget if newly claimed
            if (!$wasClaimed && $claimStatus === 'Claimed') {
                $upAlloc = $db->prepare("
                    UPDATE financial_aid_allocations 
                    SET disbursed_budget = disbursed_budget + ?,
                        remaining_budget = GREATEST(0, remaining_budget - ?)
                    WHERE id = ?
                ");
                $upAlloc->execute([$amount, $amount, $allocationId]);
            }
            $referenceNo = $referenceNo ?: $existingDist['reference_no'];
        } else {
            // Insert distribution record
            $eventId = !empty($alloc['event_id']) ? (int)$alloc['event_id'] : null;
            $insStmt = $db->prepare("
                INSERT INTO financial_aid_distributions (
                    allocation_id, event_id, barangay_id, resident_id, household_id, beneficiary_name,
                    purok, phone, amount, claim_status, claim_date,
                    reference_no, sms_status, disbursed_by, remarks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, 'not_sent', ?, ?
                )
            ");
            $insStmt->execute([
                $allocationId, $eventId, (int)$alloc['barangay_id'], $residentId, $householdId, $beneficiaryName,
                $purok, $phone, $amount, $claimStatus, $claimDate,
                $referenceNo, $disbursedBy, $remarks
            ]);
            $distId = (int)$db->lastInsertId();

            // If Claimed, update allocation budget
            if ($claimStatus === 'Claimed') {
                $upAlloc = $db->prepare("
                    UPDATE financial_aid_allocations 
                    SET disbursed_budget = disbursed_budget + ?,
                        remaining_budget = GREATEST(0, remaining_budget - ?)
                    WHERE id = ?
                ");
                $upAlloc->execute([$amount, $amount, $allocationId]);
            }
        }

        // Audit Trail Entry with Family and Household specifics
        $actionName = ($claimStatus === 'Claimed') ? 'Disbursed' : 'Status Change';
        $famName = !empty($hhInfo['family_name']) ? (trim($hhInfo['family_name']) . (stripos($hhInfo['family_name'], 'Family') === false ? ' Family' : '')) : '';
        $hhCode = !empty($hhInfo['household_code']) ? $hhInfo['household_code'] : ($householdId > 0 ? ('HH-' . $householdId) : '');
        $repRel = !empty($hhInfo['head_relation']) ? " ({$hhInfo['head_relation']})" : "";
        $memCount = (!empty($hhInfo['total_members']) && (int)$hhInfo['total_members'] > 0) ? (", " . (int)$hhInfo['total_members'] . " members") : "";

        if ($claimStatus === 'Claimed') {
            if (!empty($famName)) {
                $auditText = sprintf(
                    "Released Ayuda / Calamity Cash Aid of ₱%s to %s [Household: %s, Rep: %s%s%s] (%s, %s) under %s (%s). Ref: %s. Disbursed by %s.",
                    number_format($amount, 2),
                    $famName,
                    $hhCode,
                    $beneficiaryName,
                    $repRel,
                    $memCount,
                    $purok,
                    $alloc['barangay_name'],
                    $alloc['program_name'],
                    $alloc['allocation_code'],
                    $referenceNo,
                    $user['full_name']
                );
            } else {
                $auditText = sprintf(
                    "Released Ayuda / Calamity Cash Aid of ₱%s to %s (%s, %s) under %s (%s). Ref: %s. Disbursed by %s.",
                    number_format($amount, 2),
                    $beneficiaryName,
                    $purok,
                    $alloc['barangay_name'],
                    $alloc['program_name'],
                    $alloc['allocation_code'],
                    $referenceNo,
                    $user['full_name']
                );
            }
        } else {
            if (!empty($famName)) {
                $auditText = sprintf(
                    "Registered %s [Household: %s, Rep: %s%s] (%s, %s) as Pending beneficiary for ₱%s under %s (%s).",
                    $famName,
                    $hhCode,
                    $beneficiaryName,
                    $repRel,
                    $purok,
                    $alloc['barangay_name'],
                    number_format($amount, 2),
                    $alloc['program_name'],
                    $alloc['allocation_code']
                );
            } else {
                $auditText = sprintf(
                    "Registered %s (%s, %s) as Pending beneficiary for ₱%s under %s (%s).",
                    $beneficiaryName,
                    $purok,
                    $alloc['barangay_name'],
                    number_format($amount, 2),
                    $alloc['program_name'],
                    $alloc['allocation_code']
                );
            }
        }

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

        $aidAction = ($claimStatus === 'Claimed') ? 'RELEASE_AYUDA_RECEIVED' : 'REQUEST_RESOURCE_AID';
        logSystemEvent($aidAction, 'Manage Resources', $auditText);

        // In-app Notification for Resident (scoped to beneficiary or their purok)
        try {
            $targetUserId = null;
            if ($residentId > 0) {
                $uCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'resident' LIMIT 1");
                $uCheck->execute([$residentId]);
                $targetUserId = $uCheck->fetchColumn() ?: null;
                if (!$targetUserId) {
                    $uCheck2 = $db->prepare("SELECT id FROM users WHERE (full_name = ? OR (phone != '' AND phone = ?)) AND role = 'resident' LIMIT 1");
                    $uCheck2->execute([$beneficiaryName, $phone]);
                    $targetUserId = $uCheck2->fetchColumn() ?: null;
                }
            } else {
                $uCheck2 = $db->prepare("SELECT id FROM users WHERE (full_name = ? OR (phone != '' AND phone = ?)) AND role = 'resident' LIMIT 1");
                $uCheck2->execute([$beneficiaryName, $phone]);
                $targetUserId = $uCheck2->fetchColumn() ?: null;
            }

            if (!empty($targetUserId)) {
                $notifTitle = ($claimStatus === 'Claimed') ? "Financial Aid Disbursed: {$alloc['program_name']}" : "Eligible for Financial Aid: {$alloc['program_name']}";
                $notifBody = ($claimStatus === 'Claimed')
                    ? "₱" . number_format($amount, 2) . " cash assistance disbursed to you under {$alloc['program_name']}. Ref: " . ($referenceNo ?? $alloc['allocation_code']) . "."
                    : "You are listed as an eligible beneficiary for ₱" . number_format($amount, 2) . " under {$alloc['program_name']}. Coordinate with Barangay Hall.";
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (
                        target_role, target_barangay_id, target_puroks, target_user_id, title, message, alert_level, related_module, related_id, created_by, created_at
                    ) VALUES ('resident', ?, ?, ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
                ");
                $notifStmt->execute([(int)$alloc['barangay_id'], $purok, $targetUserId, $notifTitle, $notifBody, $distId, $user['id']]);
            }
        } catch (Throwable $e) {
            error_log("Failed to insert distribution notification: " . $e->getMessage());
        }

        // SMS Notification dispatch if requested
        $smsResultText = "";
        $smsStatus = 'not_sent';
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
            jsonResponse([
                'success' => true,
                'message' => $succMsg,
                'reference_no' => $referenceNo,
                'distribution_id' => $distId,
                'claim_date' => $claimDate,
                'sms_status' => $smsStatus
            ]);
        }
        redirectWithFlash($returnUrl, 'success', $succMsg);

    } elseif ($action === 'claim') {
        $distId = (int)($_POST['distribution_id'] ?? 0);
        $sendSms = !empty($_POST['send_sms']);

        $stmt = $db->prepare("
            SELECT d.*, a.allocation_code, a.program_name, a.remaining_budget, a.status AS alloc_status, b.name AS barangay_name,
                   h.household_code, h.family_name, h.head_full_name, h.head_relation, h.total_members, h.purok_name AS household_purok
            FROM financial_aid_distributions d
            JOIN financial_aid_allocations a ON d.allocation_id = a.id
            JOIN barangays b ON d.barangay_id = b.id
            LEFT JOIN purok_households h ON d.household_id = h.id
            WHERE d.id = ?
        ");
        $stmt->execute([$distId]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dist) {
            throw new Exception("Distribution record not found.");
        }

        if (empty($dist['family_name']) && !empty($dist['resident_id'])) {
            $hFallback = $db->prepare("SELECT household_code, family_name, head_full_name, head_relation, total_members, purok_name FROM purok_households WHERE head_resident_id = ? LIMIT 1");
            $hFallback->execute([$dist['resident_id']]);
            $hf = $hFallback->fetch(PDO::FETCH_ASSOC);
            if ($hf) {
                $dist = array_merge($dist, $hf);
            }
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

        // Audit Trail Entry with Family and Household specifics
        if (!empty($dist['family_name'])) {
            $famName = trim($dist['family_name']) . (stripos($dist['family_name'], 'Family') === false ? ' Family' : '');
            $hhCode = !empty($dist['household_code']) ? $dist['household_code'] : ($dist['household_id'] ? ('HH-' . $dist['household_id']) : '');
            $repRel = !empty($dist['head_relation']) ? " ({$dist['head_relation']})" : "";
            $memCount = (!empty($dist['total_members']) && (int)$dist['total_members'] > 0) ? (", " . (int)$dist['total_members'] . " members") : "";
            $auditText = sprintf(
                "Released Ayuda / Calamity Cash Aid of ₱%s to %s [Household: %s, Rep: %s%s%s] (%s, %s) under %s (%s). Ref: %s. Disbursed by %s.",
                number_format($amount, 2),
                $famName,
                $hhCode,
                $dist['beneficiary_name'],
                $repRel,
                $memCount,
                $dist['purok'] ?: ($dist['household_purok'] ?? 'Purok'),
                $dist['barangay_name'],
                $dist['program_name'],
                $dist['allocation_code'],
                $referenceNo,
                $user['full_name']
            );
        } else {
            $auditText = sprintf(
                "Released Ayuda / Calamity Cash Aid of ₱%s to %s (%s, %s) under %s (%s). Ref: %s. Disbursed by %s.",
                number_format($amount, 2),
                $dist['beneficiary_name'],
                $dist['purok'],
                $dist['barangay_name'],
                $dist['program_name'],
                $dist['allocation_code'],
                $referenceNo,
                $user['full_name']
            );
        }

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

        logSystemEvent('RELEASE_AYUDA_RECEIVED', 'Manage Resources', $auditText);

        // In-app Notification for Resident (only for registered user accounts)
        try {
            if (!empty($dist['resident_id'])) {
                $notifTitle = "Financial Aid Claimed: {$dist['program_name']}";
                $notifBody = "₱" . number_format($amount, 2) . " financial aid claimed under {$dist['program_name']}. Ref: {$referenceNo}.";
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (
                        target_role, target_barangay_id, target_user_id, title, message, alert_level, related_module, related_id, created_by, created_at
                    ) VALUES ('resident', ?, ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
                ");
                $notifStmt->execute([(int)$dist['barangay_id'], (int)$dist['resident_id'], $notifTitle, $notifBody, $distId, $user['id']]);
            }
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
        $householdId = (int)($_POST['household_id'] ?? 0);
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        $customPhone = trim($_POST['phone'] ?? '');
        $customName = trim($_POST['beneficiary_name'] ?? '');
        $referenceNo = trim($_POST['reference_no'] ?? '');

        $dist = null;
        if ($distId > 0) {
            $stmt = $db->prepare("
                SELECT d.*, a.program_name, b.name AS barangay_name, a.allocation_code
                FROM financial_aid_distributions d 
                JOIN financial_aid_allocations a ON d.allocation_id = a.id 
                JOIN barangays b ON d.barangay_id = b.id 
                WHERE d.id = ?
            ");
            $stmt->execute([$distId]);
            $dist = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // If no dist record yet, find by allocation + household or create an unreleased record
        if (!$dist && $householdId > 0 && $allocationId > 0) {
            $allocStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
            $allocStmt->execute([$allocationId]);
            $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC);
            if (!$alloc) {
                throw new Exception("Allocation program not found.");
            }

            $checkStmt = $db->prepare("SELECT * FROM financial_aid_distributions WHERE allocation_id = ? AND household_id = ? ORDER BY id DESC LIMIT 1");
            $checkStmt->execute([$allocationId, $householdId]);
            $dist = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($dist) {
                $distId = (int)$dist['id'];
                $dist['program_name'] = $alloc['program_name'];
                $dist['barangay_name'] = $alloc['barangay_name'];
                $dist['allocation_code'] = $alloc['allocation_code'];
            } else {
                $hhStmt = $db->prepare("SELECT * FROM purok_households WHERE id = ?");
                $hhStmt->execute([$householdId]);
                $hh = $hhStmt->fetch(PDO::FETCH_ASSOC);

                $bName = $customName ?: ($hh['head_full_name'] ?? ($hh['family_name'] . ' Family'));
                $bPhone = $customPhone ?: ($hh['head_phone'] ?? '');
                $bPurok = $hh['purok_name'] ?? 'Purok';
                $bAmount = (float)$alloc['amount_per_beneficiary'];

                if (empty($referenceNo)) {
                    $cleanAlloc = str_replace('AID-', '', $alloc['allocation_code'] ?: ('EVT' . $alloc['id']));
                    $cleanHh = $hh['household_code'] ?? ('HH' . $householdId);
                    $referenceNo = 'REF-' . $cleanAlloc . '-' . $cleanHh;
                }

                $eventId = !empty($alloc['event_id']) ? (int)$alloc['event_id'] : null;
                $insStmt = $db->prepare("
                    INSERT INTO financial_aid_distributions (
                        allocation_id, event_id, barangay_id, resident_id, household_id,
                        beneficiary_name, purok, phone, amount, claim_status,
                        reference_no, sms_status, disbursed_by, remarks
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, 'Unclaimed',
                        ?, 'not_sent', ?, ?
                    )
                ");
                $insStmt->execute([
                    $allocationId, $eventId, (int)$alloc['barangay_id'], $hh['head_resident_id'] ?? null, $householdId,
                    $bName, $bPurok, $bPhone, $bAmount,
                    $referenceNo, $user['id'], 'SMS Pre-notification'
                ]);
                $distId = (int)$db->lastInsertId();

                $dist = [
                    'id' => $distId,
                    'allocation_id' => $allocationId,
                    'household_id' => $householdId,
                    'beneficiary_name' => $bName,
                    'phone' => $bPhone,
                    'amount' => $bAmount,
                    'purok' => $bPurok,
                    'claim_status' => 'Unclaimed',
                    'reference_no' => $referenceNo,
                    'program_name' => $alloc['program_name'],
                    'barangay_name' => $alloc['barangay_name'],
                    'allocation_code' => $alloc['allocation_code']
                ];
            }
        }

        if (!$dist) throw new Exception("Beneficiary distribution record not found.");
        $targetPhone = !empty($customPhone) ? $customPhone : ($dist['phone'] ?? '');
        if (empty($targetPhone)) throw new Exception("No phone number registered for {$dist['beneficiary_name']}.");

        $refNo = !empty($dist['reference_no']) ? $dist['reference_no'] : ($referenceNo ?: ('REF-' . $dist['id']));

        $smsMsg = ($dist['claim_status'] === 'Claimed')
            ? "Good day {$dist['beneficiary_name']}, your financial aid payout of PHP " . number_format($dist['amount'], 2) . " under {$dist['program_name']} ({$dist['barangay_name']}) is confirmed. Ref No: {$refNo}."
            : "Good day {$dist['beneficiary_name']}, you are scheduled for PHP " . number_format($dist['amount'], 2) . " Financial Aid under {$dist['program_name']}. Ref No: {$refNo}. Please visit Barangay {$dist['barangay_name']} Hall with a valid ID.";

        $smsResp = SmsService::send($targetPhone, $smsMsg, $dist['beneficiary_name'], 'financial_aid', $distId);
        $smsStatus = ($smsResp['status'] === 'sent' || strpos($smsResp['status'], 'simulated') !== false) ? 'sent' : 'failed';
        $db->prepare("UPDATE financial_aid_distributions SET sms_status = ?, phone = COALESCE(phone, ?), reference_no = COALESCE(reference_no, ?) WHERE id = ?")
           ->execute([$smsStatus, $targetPhone, $refNo, $distId]);

        $auditText = "Sent SMS alert to {$dist['beneficiary_name']} ({$targetPhone}) for {$dist['program_name']}. Status: {$smsStatus}. Ref: {$refNo}.";
        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, ?, 'Status Change', ?, 0.00, ?, ?, ?)
        ");
        $auditStmt->execute([$dist['allocation_id'], $distId, (int)($dist['barangay_id'] ?? $userBrgyId ?? 12), $auditText, $user['id'], $user['full_name'], $role]);

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => "SMS dispatched to {$targetPhone} ({$dist['beneficiary_name']}). Status: {$smsStatus}.",
                'sms_status' => $smsStatus,
                'reference_no' => $refNo,
                'distribution_id' => $distId
            ]);
        }
        redirectWithFlash($returnUrl, 'success', "SMS dispatched to {$targetPhone}. Status: {$smsStatus}.");

    } elseif ($action === 'void') {
        $distId = (int)($_POST['distribution_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT d.*, a.allocation_code, a.program_name, b.name AS barangay_name,
                   h.household_code, h.family_name, h.head_full_name, h.head_relation, h.total_members, h.purok_name AS household_purok
            FROM financial_aid_distributions d 
            JOIN financial_aid_allocations a ON d.allocation_id = a.id 
            JOIN barangays b ON d.barangay_id = b.id 
            LEFT JOIN purok_households h ON d.household_id = h.id
            WHERE d.id = ?
        ");
        $stmt->execute([$distId]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dist) throw new Exception("Distribution record not found.");
        if ($userBrgyId !== null && (int)$dist['barangay_id'] !== $userBrgyId) {
            throw new Exception("Unauthorized: Record belongs to another barangay.");
        }

        if (empty($dist['family_name']) && !empty($dist['resident_id'])) {
            $hFallback = $db->prepare("SELECT household_code, family_name, head_full_name, head_relation, total_members, purok_name FROM purok_households WHERE head_resident_id = ? LIMIT 1");
            $hFallback->execute([$dist['resident_id']]);
            $hf = $hFallback->fetch(PDO::FETCH_ASSOC);
            if ($hf) {
                $dist = array_merge($dist, $hf);
            }
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

        // Audit Trail Entry with Family and Household specifics
        if (!empty($dist['family_name'])) {
            $famName = trim($dist['family_name']) . (stripos($dist['family_name'], 'Family') === false ? ' Family' : '');
            $hhCode = !empty($dist['household_code']) ? $dist['household_code'] : ($dist['household_id'] ? ('HH-' . $dist['household_id']) : '');
            $repRel = !empty($dist['head_relation']) ? " ({$dist['head_relation']})" : "";
            $auditText = sprintf(
                "Voided Ayuda disbursement of ₱%s for %s [Household: %s, Rep: %s%s] (%s, %s) under %s (%s). Funds reverted to allocation balance. Action by %s.",
                number_format($amount, 2),
                $famName,
                $hhCode,
                $dist['beneficiary_name'],
                $repRel,
                $dist['purok'] ?: ($dist['household_purok'] ?? 'Purok'),
                $dist['barangay_name'],
                $dist['program_name'],
                $dist['allocation_code'],
                $user['full_name']
            );
        } else {
            $auditText = sprintf(
                "Voided financial aid distribution for %s (₱%s) under %s (%s). Funds reverted to allocation balance. Action by %s.",
                $dist['beneficiary_name'],
                number_format($amount, 2),
                $dist['program_name'],
                $dist['allocation_code'],
                $user['full_name']
            );
        }

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, ?, 'Voided', ?, ?, ?, ?, ?)
        ");
        $auditStmt->execute([$dist['allocation_id'], $distId, $dist['barangay_id'], $auditText, $amount, $user['id'], $user['full_name'], $role]);

        logSystemEvent('VOID_AYUDA_NOT_RECEIVED', 'Manage Resources', $auditText);

        if ($isAjax) {
            jsonResponse([
                'success' => true,
                'message' => "Distribution for {$dist['beneficiary_name']} voided. Allocation funds restored.",
                'distribution_id' => $distId,
                'amount' => $amount
            ]);
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
