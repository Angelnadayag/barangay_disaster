<?php
// ============================================================================
// Function: Decision Tree Recommender - Remove Resource Item from Recommendation
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
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Only ICDRRMO personnel can manage recommendations.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized. Only ICDRRMO personnel can manage recommendations.');
}

$itemId = (int)($_POST['item_id'] ?? 0);
$recId = (int)($_POST['recommendation_id'] ?? 0);

if ($itemId <= 0 || $recId <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid item removal parameters.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid item removal parameters.');
}

try {
    $db = getDBConnection();

    // Verify recommendation exists and is Pending Review
    $recStmt = $db->prepare("SELECT id, status FROM recommendations WHERE id = ?");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch();

    if (!$rec) {
        throw new Exception("Recommendation #$recId not found.");
    }

    if ($rec['status'] !== 'Pending Review') {
        throw new Exception("Cannot remove items: recommendation is already " . clean($rec['status']) . ".");
    }

    $delStmt = $db->prepare("DELETE FROM recommended_items WHERE id = ? AND recommendation_id = ?");
    $delStmt->execute([$itemId, $recId]);

    // Calculate updated items count for response
    $countStmt = $db->prepare("SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = ?");
    $countStmt->execute([$recId]);
    $totalItems = (int)$countStmt->fetchColumn();

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => 'Resource removed from allocation package.',
            'total_items' => $totalItems
        ]);
    }

    redirectWithFlash($returnUrl, 'success', 'Resource removed from allocation package.');

} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
