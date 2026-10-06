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

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/recommender.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can review recommendations.');
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
$reviewAction = trim($_POST['review_action'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$modifiedQuantities = $_POST['quantities'] ?? [];

if (!$recId || !in_array($reviewAction, ['approve', 'modify', 'reject'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Invalid review submission.');
}

try {
    $recommender = new DecisionTreeRecommender();
    $result = $recommender->reviewRecommendation($recId, $reviewAction, $modifiedQuantities, $remarks, $user['id']);
    
    $actionWord = ($reviewAction === 'reject' ? 'rejected' : 'approved and allocated');
    redirectWithFlash($returnUrl, 'success', "Recommendation #$recId successfully $actionWord.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
