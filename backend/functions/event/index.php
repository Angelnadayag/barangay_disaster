<?php
// ============================================================================
// Preparedness Activities Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'create':
        require __DIR__ . '/create.php';
        break;
    case 'update':
        require __DIR__ . '/update.php';
        break;
    case 'cancel':
        require __DIR__ . '/cancel.php';
        break;
    case 'evaluate':
        require __DIR__ . '/evaluate.php';
        break;
    case 'register_resident':
        require __DIR__ . '/register_resident.php';
        break;
    case 'add_attendee':
        require __DIR__ . '/add_attendee.php';
        break;
    case 'remove_attendee':
    case 'delete_attendee':
        require __DIR__ . '/remove_attendee.php';
        break;
    case 'archive':
        require __DIR__ . '/archive.php';
        break;
    case 'restore':
        require __DIR__ . '/restore.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid preparedness activities action specified.');
        break;
}
