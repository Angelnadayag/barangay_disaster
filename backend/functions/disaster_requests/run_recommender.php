<?php
// ============================================================================
// Function: Disaster Requests - Run Decision Tree Recommender
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/DecisionTreeRecommender.php';

requireLogin();
$user = getCurrentUser();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/disaster-requests.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Only ICDRRMO personnel are authorized to run recommendations.');
}

$requestId = (int)($_POST['request_id'] ?? ($_GET['request_id'] ?? 0));
if (!$requestId) {
    redirectWithFlash($returnUrl, 'error', 'Invalid request ID.');
}

try {
    $recommender = new DecisionTreeRecommender();
    $result = $recommender->evaluateRequest($requestId);
    
    $msg = "Recommendation generated successfully for Request #" . $requestId;
    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Recommender error: ' . $e->getMessage());
}
