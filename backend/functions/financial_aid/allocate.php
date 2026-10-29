<?php
// ============================================================================
// Function: Financial Aid - Allocate (ICDRRMO Central Office)
// Handles creation, status updates, and deletion of financial aid allocations
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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/financial-aid-allocation.php'));

if ($role !== 'icdrrmo') {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized. ICDRRMO access only.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. ICDRRMO access only.');
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'create');

try {
    if ($action === 'create') {
        $programName = trim($_POST['program_name'] ?? '');
        $disasterType = trim($_POST['disaster_type'] ?? '') ?: null;
        $barangayId = (int)($_POST['barangay_id'] ?? 0);
        if ($barangayId <= 0) {
            $barangayId = 12; // Default to Hinaplanon
        }
        $targetPurok = trim($_POST['target_purok'] ?? 'All Puroks');
        if (empty($targetPurok)) {
            $targetPurok = 'All Puroks';
        }
        $totalBudget = (float)($_POST['total_budget'] ?? 0);
        $amountPerBeneficiary = (float)($_POST['amount_per_beneficiary'] ?? 0);
        $fundingSource = trim($_POST['funding_source'] ?? 'LGU Calamity Fund');
        $notes = trim($_POST['notes'] ?? '') ?: null;

        if (empty($programName)) {
            throw new Exception("Program Name is required.");
        }
        if ($totalBudget <= 0) {
            throw new Exception("Total Budget must be greater than zero.");
        }
        if ($amountPerBeneficiary <= 0) {
            throw new Exception("Amount per Beneficiary must be greater than zero.");
        }
        if ($amountPerBeneficiary > $totalBudget) {
            throw new Exception("Amount per beneficiary cannot exceed total budget.");
        }

        // Verify barangay exists
        $brgyStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
        $brgyStmt->execute([$barangayId]);
        $barangayName = $brgyStmt->fetchColumn();
        if (!$barangayName) {
            $barangayId = 12;
            $barangayName = 'Hinaplanon';
        }

        // Generate Allocation Code
        $year = date('Y');
        $cntStmt = $db->query("SELECT COUNT(*) FROM financial_aid_allocations");
        $nextNum = ((int)$cntStmt->fetchColumn()) + 1;
        $allocationCode = 'AID-' . $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $targetBeneficiaries = (int)floor($totalBudget / $amountPerBeneficiary);
        $remainingBudget = $totalBudget;
        $disbursedBudget = 0.00;

        $addedType = trim($_POST['added_type'] ?? '');
        if (!in_array($addedType, ['ai_recommended', 'added_by_admin'], true)) {
            $addedType = 'ai_recommended';
        }

        $insertStmt = $db->prepare("
            INSERT INTO financial_aid_allocations (
                allocation_code, program_name, disaster_type, barangay_id, target_purok,
                total_budget, amount_per_beneficiary, disbursed_budget, remaining_budget,
                funding_source, target_beneficiaries, status, added_type, notes, allocated_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 'Active', ?, ?, ?
            )
        ");
        $insertStmt->execute([
            $allocationCode, $programName, $disasterType, $barangayId, $targetPurok,
            $totalBudget, $amountPerBeneficiary, $disbursedBudget, $remainingBudget,
            $fundingSource, $targetBeneficiaries, $addedType, $notes, $user['id']
        ]);
        $allocationId = (int)$db->lastInsertId();

        $recId = (int)($_POST['recommendation_id'] ?? 0);
        if ($recId > 0) {
            $linkStmt = $db->prepare("UPDATE recommendations SET financial_aid_allocation_id = ? WHERE id = ?");
            $linkStmt->execute([$allocationId, $recId]);
        }

        // Audit Trail entry with added_type & target purok
        $typeLabel = ($addedType === 'added_by_admin') ? 'Added by Admin' : 'AI Recommended';
        $auditDetails = sprintf(
            "ICDRRMO allocated ₱%s to Barangay %s (Target Purok: %s) under %s (%s). Target: %d beneficiaries at ₱%s each. Funding: %s. [Source: %s].",
            number_format($totalBudget, 2),
            $barangayName,
            $targetPurok,
            $programName,
            $allocationCode,
            $targetBeneficiaries,
            number_format($amountPerBeneficiary, 2),
            $fundingSource,
            $typeLabel
        );

        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, distribution_id, barangay_id, action,
                details, amount, actor_id, actor_name, actor_role
            ) VALUES (
                ?, NULL, ?, 'Allocated',
                ?, ?, ?, ?, 'icdrrmo'
            )
        ");
        $auditStmt->execute([
            $allocationId, $barangayId,
            $auditDetails, $totalBudget, $user['id'], $user['full_name']
        ]);

        logSystemEvent('CREATE_RESOURCE_ALLOCATION', 'Manage Resources', "ICDRRMO created resource allocation {$allocationCode}: ₱" . number_format($totalBudget, 2) . " for Barangay {$barangayName} ({$programName}, {$targetBeneficiaries} target families)");

        // Notify residents of the targeted barangay
        try {
            $notifTitle = "Financial Aid Program: {$programName}";
            $notifMsg = "Barangay {$barangayName} has announced emergency financial assistance under {$programName} (Purok: {$targetPurok}). Total Program Fund: ₱" . number_format($totalBudget, 2) . " (₱" . number_format($amountPerBeneficiary, 2) . " per qualified family). Ref: {$allocationCode}.";
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_by, created_at
                ) VALUES ('resident', ?, ?, ?, 'informational', 'financial_aid', ?, ?, NOW())
            ");
            $notifStmt->execute([$barangayId, $notifTitle, $notifMsg, $allocationId, $user['id']]);
        } catch (Throwable $e) {
            error_log("Failed to insert financial aid notification: " . $e->getMessage());
        }

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "Financial aid allocation {$allocationCode} successfully created for Barangay {$barangayName}."]);
        }
        redirectWithFlash($returnUrl, 'success', "Allocation {$allocationCode} (₱" . number_format($totalBudget, 2) . ") created for Barangay {$barangayName}.");

    } elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Active');
        if (!in_array($newStatus, ['Active', 'Completed', 'Archived'], true)) {
            throw new Exception("Invalid status specified.");
        }

        $checkStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
        $checkStmt->execute([$id]);
        $alloc = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) {
            throw new Exception("Allocation not found.");
        }

        $upStmt = $db->prepare("UPDATE financial_aid_allocations SET status = ? WHERE id = ?");
        $upStmt->execute([$newStatus, $id]);

        $auditDetails = "Updated allocation status from {$alloc['status']} to {$newStatus} for {$alloc['allocation_code']} ({$alloc['barangay_name']}).";
        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, barangay_id, action, details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, 'Status Change', ?, 0.00, ?, ?, 'icdrrmo')
        ");
        $auditStmt->execute([$id, $alloc['barangay_id'], $auditDetails, $user['id'], $user['full_name']]);

        $statusAction = ($newStatus === 'Archived') ? 'ARCHIVE_RESOURCE_ALLOCATION' : (($newStatus === 'Active' && $alloc['status'] === 'Archived') ? 'RESTORE_RESOURCE_ALLOCATION' : 'UPDATE_RESOURCE_STATUS');
        logSystemEvent($statusAction, 'Manage Resources', $auditDetails);

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "Allocation status updated to {$newStatus}."]);
        }
        redirectWithFlash($returnUrl, 'success', "Allocation status updated to {$newStatus}.");

    } elseif ($action === 'edit' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $programName = trim($_POST['program_name'] ?? '');
        $disasterType = trim($_POST['disaster_type'] ?? '') ?: 'General Calamity';
        $targetPurok = trim($_POST['target_purok'] ?? 'All Puroks');
        $totalBudget = (float)($_POST['total_budget'] ?? 0);
        $amountPerBeneficiary = (float)($_POST['amount_per_beneficiary'] ?? 0);
        $fundingSource = trim($_POST['funding_source'] ?? 'LGU Calamity Fund');
        $notes = trim($_POST['notes'] ?? '') ?: null;

        $checkStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
        $checkStmt->execute([$id]);
        $alloc = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) {
            throw new Exception("Allocation not found.");
        }

        $targetBeneficiaries = ($amountPerBeneficiary > 0) ? (int)floor($totalBudget / $amountPerBeneficiary) : $alloc['target_beneficiaries'];
        $remainingBudget = max(0, $totalBudget - (float)$alloc['disbursed_budget']);

        $upStmt = $db->prepare("
            UPDATE financial_aid_allocations SET
                program_name = ?,
                disaster_type = ?,
                target_purok = ?,
                total_budget = ?,
                amount_per_beneficiary = ?,
                remaining_budget = ?,
                funding_source = ?,
                target_beneficiaries = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $upStmt->execute([
            $programName, $disasterType, $targetPurok, $totalBudget,
            $amountPerBeneficiary, $remainingBudget, $fundingSource,
            $targetBeneficiaries, $notes, $id
        ]);

        $auditDetails = "Edited resource allocation {$alloc['allocation_code']} ({$programName}): Budget ₱" . number_format($totalBudget, 2) . ", Target: {$targetPurok}";
        $auditStmt = $db->prepare("
            INSERT INTO financial_aid_audits (
                allocation_id, barangay_id, action, details, amount, actor_id, actor_name, actor_role
            ) VALUES (?, ?, 'Status Change', ?, ?, ?, ?, 'icdrrmo')
        ");
        $auditStmt->execute([$id, $alloc['barangay_id'], $auditDetails, $totalBudget, $user['id'], $user['full_name']]);

        logSystemEvent('EDIT_RESOURCE_ALLOCATION', 'Manage Resources', $auditDetails);

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "Allocation {$alloc['allocation_code']} updated successfully."]);
        }
        redirectWithFlash($returnUrl, 'success', "Allocation {$alloc['allocation_code']} updated successfully.");

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $checkStmt = $db->prepare("SELECT a.*, b.name AS barangay_name FROM financial_aid_allocations a JOIN barangays b ON a.barangay_id = b.id WHERE a.id = ?");
        $checkStmt->execute([$id]);
        $alloc = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) {
            throw new Exception("Allocation record not found.");
        }

        if ((float)$alloc['disbursed_budget'] > 0) {
            throw new Exception("Cannot delete an allocation with disbursed funds (₱" . number_format($alloc['disbursed_budget'], 2) . "). Please set status to Archived instead for audit transparency.");
        }

        $delStmt = $db->prepare("DELETE FROM financial_aid_allocations WHERE id = ?");
        $delStmt->execute([$id]);

        logSystemEvent('DELETE_RESOURCE_ALLOCATION', 'Manage Resources', "Deleted allocation {$alloc['allocation_code']} for {$alloc['barangay_name']}");

        if ($isAjax) {
            jsonResponse(['success' => true, 'message' => "Allocation {$alloc['allocation_code']} removed."]);
        }
        redirectWithFlash($returnUrl, 'success', "Allocation {$alloc['allocation_code']} removed.");

    } else {
        throw new Exception("Invalid action requested.");
    }
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
