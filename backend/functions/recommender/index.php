<?php
// ============================================================================
// Decision Tree Recommender Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'review':
        require __DIR__ . '/review.php';
        break;
    case 'add_item':
        require __DIR__ . '/add_item.php';
        break;
    case 'remove_item':
        require __DIR__ . '/remove_item.php';
        break;
    case 'update_ayuda_budget':
        require __DIR__ . '/update_ayuda_budget.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid recommender action specified.');
        break;
}
