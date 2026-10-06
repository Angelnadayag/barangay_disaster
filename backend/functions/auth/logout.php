<?php
// ============================================================================
// Function: Auth - User Logout
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

if (isLoggedIn()) {
    $u = getCurrentUser();
    logSystemEvent('LOGOUT', 'Auth', "User {$u['username']} logged out.", $u['id'], $u['full_name'], $u['role']);
}

unset($_SESSION['user']);
session_destroy();
header('Location: ' . BASE_URL . '/views/auth/login.php');
exit;
