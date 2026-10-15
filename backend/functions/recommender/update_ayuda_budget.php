<?php
// ============================================================================
// Function: Decision Tree Recommender - Update Financial Aid (Ayuda) Budget
// Allows ICDRRMO officer to customize or override the algorithmic budget.
// Automatically flags budget as 'added_by_admin'.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || !empty($_POST['is_ajax']);

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/recommendations.php'));

if ($user['role'] !== 'icdrrmo') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO personnel can adjust recommendation budgets.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can adjust recommendation budgets.');
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
$totalBudget = (float)($_POST['total_budget'] ?? 0);
$perFamily = (float)($_POST['per_family'] ?? 0);
$fundingSource = trim($_POST['funding_source'] ?? '');

if ($recId <= 0 || $totalBudget <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Please provide a valid recommendation ID and a budget amount greater than zero.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Please provide a valid recommendation ID and a budget amount greater than zero.');
}

try {
    $db = getDBConnection();

    // Check recommendation
    $recStmt = $db->prepare("SELECT id, recommendation_code, status, financial_aid_recommended_budget, financial_aid_per_family, financial_aid_source FROM recommendations WHERE id = ?");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch();

    if (!$rec) {
        throw new Exception("Recommendation #$recId not found.");
    }

    if ($rec['status'] !== 'Pending Review') {
        throw new Exception("Cannot modify budget: recommendation is already {$rec['status']}.");
    }

    if ($perFamily <= 0) {
        $perFamily = (float)($rec['financial_aid_per_family'] ?: 3000);
    }
    if (empty($fundingSource)) {
        $fundingSource = $rec['financial_aid_source'] ?: 'LGU Calamity Fund (QRF)';
    }

    $targetFamilies = (int)floor($totalBudget / $perFamily);

    $updStmt = $db->prepare("
        UPDATE recommendations SET
            financial_aid_recommended_budget = ?,
            financial_aid_approved_budget = ?,
            financial_aid_per_family = ?,
            financial_aid_target_families = ?,
            financial_aid_source = ?,
            financial_aid_added_type = 'added_by_admin'
        WHERE id = ?
    ");
    $updStmt->execute([$totalBudget, $totalBudget, $perFamily, $targetFamilies, $fundingSource, $recId]);

    logSystemEvent('UPDATE_AYUDA_BUDGET', 'Decision Tree Engine', "ICDRRMO updated Financial Aid budget for recommendation #$recId to ₱" . number_format($totalBudget, 2) . " (Source: added_by_admin)", $user['id']);

    $msg = "Financial aid budget successfully updated to ₱" . number_format($totalBudget, 2) . " (Marked as: Added by Admin).";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'budget' => $totalBudget,
            'per_family' => $perFamily,
            'target_families' => $targetFamilies,
            'funding_source' => $fundingSource,
            'added_type' => 'added_by_admin'
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);

} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
