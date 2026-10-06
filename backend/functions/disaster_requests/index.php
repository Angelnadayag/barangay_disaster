<?php
// ============================================================================
// Disaster Requests Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'create':
        require __DIR__ . '/create.php';
        break;
    case 'run_recommender':
        require __DIR__ . '/run_recommender.php';
        break;
    case 'update_status':
        require __DIR__ . '/update_status.php';
        break;
    case 'get_puroks':
        require __DIR__ . '/get_puroks.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid disaster requests action specified.');
        break;
}
