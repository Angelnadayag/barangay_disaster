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

$modality = clean($_POST['modality'] ?? ($_GET['modality'] ?? 'both'));
if (!in_array($modality, ['both', 'resources', 'financial_aid'])) {
    $modality = 'both';
}

$options = [
    'financial_aid_amount' => isset($_POST['financial_aid_amount']) && is_numeric($_POST['financial_aid_amount']) ? (float)$_POST['financial_aid_amount'] : null,
    'financial_aid_sponsor' => trim($_POST['financial_aid_sponsor'] ?? ($_POST['financial_aid_source'] ?? '')),
    'financial_aid_target' => trim($_POST['financial_aid_target'] ?? 'displaced')
];

try {
    $customResources = [];
    if (!empty($_POST['custom_resources'])) {
        $decoded = json_decode($_POST['custom_resources'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $cr) {
                $rid = (int)($cr['resource_id'] ?? 0);
                $qty = (int)($cr['quantity'] ?? 0);
                if ($rid > 0 && $qty > 0) {
                    $customResources[$rid] = $qty;
                }
            }
        }
    }
    $options['custom_resources'] = $customResources;

    $recommender = new DecisionTreeRecommender();
    $result = $recommender->evaluateRequest($requestId, $modality, $options);
    
    $modalityLabel = ($modality === 'resources') ? 'Physical Resources' : (($modality === 'financial_aid') ? 'Emergency Financial Aid' : 'Comprehensive Dual Support');
    $msg = "Decision Support recommendation ($modalityLabel) generated successfully for Request #" . $requestId;
    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'recommendation_id' => $result['recommendation_id'] ?? null,
            'recommendation_code' => $result['recommendation_code'] ?? null,
            'confidence' => $result['confidence'] ?? 0,
            'confidence_breakdown' => $result['confidence_breakdown'] ?? null,
            'modality' => $result['modality'] ?? $modality,
            'priority' => $result['priority'] ?? 'Moderate',
            'items_count' => $result['items_count'] ?? 0,
            'financial_aid' => $result['financial_aid'] ?? null,
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
