<?php
// ============================================================================
// Evacuation Centers Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'create':
        require __DIR__ . '/create.php';
        break;
    case 'update':
        require __DIR__ . '/update.php';
        break;
    case 'delete':
        require __DIR__ . '/delete.php';
        break;
    case 'update_status':
        require __DIR__ . '/update_status.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid evacuation action specified.');
        break;
}
