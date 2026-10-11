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

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/disaster-reports.php'));

if ($user['role'] !== 'icdrrmo') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Only ICDRRMO personnel are authorized to run recommendations.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Only ICDRRMO personnel are authorized to run recommendations.');
}

$requestId = (int)($_POST['request_id'] ?? ($_GET['request_id'] ?? 0));
if (!$requestId) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid disaster request ID specified.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid request ID.');
}

try {
    $recommender = new DecisionTreeRecommender();
    $result = $recommender->evaluateRequest($requestId);
    
    $msg = "Decision Tree recommendation generated successfully for Request #" . $requestId;
    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'recommendation_id' => $result['recommendation_id'] ?? null,
            'confidence' => $result['confidence'] ?? 0,
            'priority' => $result['priority'] ?? 'Moderate',
            'items_count' => $result['items_count'] ?? 0,
            'result' => $result
        ]);
    }
    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Recommender error: ' . $e->getMessage()], 500);
    }
    redirectWithFlash($returnUrl, 'error', 'Recommender error: ' . $e->getMessage());
}
