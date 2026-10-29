<?php
// ============================================================================
// Auth Module Dispatcher
// ============================================================================

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'login':
        require __DIR__ . '/login.php';
        break;
    case 'logout':
        require __DIR__ . '/logout.php';
        break;
    case 'forgot_password':
        require __DIR__ . '/forgot_password.php';
        break;
    case 'register':
        require __DIR__ . '/register.php';
        break;
    default:
        require_once __DIR__ . '/../../config/config.php';
        header('Location: ' . BASE_URL . '/views/auth/login.php');
        exit;
}
