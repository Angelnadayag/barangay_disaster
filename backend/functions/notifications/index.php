<?php
// ============================================================================
// Notifications Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'broadcast':
        require __DIR__ . '/broadcast.php';
        break;
    case 'mark_read':
        require __DIR__ . '/mark_read.php';
        break;
    case 'mark_all_read':
        require __DIR__ . '/mark_all_read.php';
        break;
    case 'recent':
        require __DIR__ . '/recent.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid notifications action specified.');
        break;
}
