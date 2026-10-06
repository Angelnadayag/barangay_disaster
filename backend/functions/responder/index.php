<?php
// ============================================================================
// Responder Field Operations Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'quick_status':
        require __DIR__ . '/quick_status.php';
        break;
    case 'submit_report':
        require __DIR__ . '/submit_report.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../services/Helpers.php';
        $returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
        redirectWithFlash($returnUrl, 'error', 'Invalid responder action specified.');
        break;
}
