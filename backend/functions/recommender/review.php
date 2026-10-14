<?php
// ============================================================================
// Function: Decision Tree Recommender - Review Recommendation
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/DecisionTreeRecommender.php';

requireLogin();
$user = getCurrentUser();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || !empty($_POST['is_ajax']);

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/recommendations.php'));

if ($user['role'] !== 'icdrrmo') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO personnel can review recommendations.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can review recommendations.');
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
$reviewAction = trim($_POST['review_action'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$modifiedQuantities = $_POST['quantities'] ?? [];

if (!$recId || !in_array($reviewAction, ['approve', 'modify', 'reject'], true)) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid review submission.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid review submission.');
}

try {
    $recommender = new DecisionTreeRecommender();
    $result = $recommender->reviewRecommendation($recId, $reviewAction, $modifiedQuantities, $remarks, $user['id']);
    
    $actionWord = ($reviewAction === 'reject' ? 'rejected' : ($reviewAction === 'modify' ? 'modified and approved' : 'approved and allocated'));
    $msg = "Recommendation #$recId successfully $actionWord.";
    
    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'status' => $result['status'] ?? $reviewAction
        ]);
    }
    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
