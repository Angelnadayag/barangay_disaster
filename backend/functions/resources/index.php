<?php
// ============================================================================
// Resources Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'create':
        require __DIR__ . '/create.php';
        break;
    case 'update':
        require __DIR__ . '/update.php';
        break;
    case 'restock':
        require __DIR__ . '/restock.php';
        break;
    case 'adjust':
        require __DIR__ . '/adjust.php';
        break;
    case 'archive':
        require __DIR__ . '/archive.php';
        break;
    case 'restore':
        require __DIR__ . '/restore.php';
        break;
    case 'delete':
        require __DIR__ . '/delete.php';
        break;
    case 'request':
        require __DIR__ . '/request.php';
        break;
    case 'review':
    case 'review_request':
        require __DIR__ . '/review_request.php';
        break;
    case 'catalog':
    case 'get_catalog':
        require __DIR__ . '/get_catalog.php';
        break;
    case 'allocate':
    case 'process_allocation':
        require __DIR__ . '/allocate.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/manage-resources.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid resources action specified.');
        break;
}
