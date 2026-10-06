<?php
// ============================================================================
// Root Application Router
// Routes authenticated users to their arranged role folder, or to login
// ============================================================================

require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/services/Auth.php';

if (isLoggedIn()) {
    $user = getCurrentUser();
    $folder = getRoleFolder($user['role'] ?? '');
    if ($user['role'] === 'icdrrmo') {
        $url = BASE_URL . '/views/icdrrmo/users.php';
    } elseif ($user['role'] === 'barangay_head') {
        $url = BASE_URL . '/views/barangay/residents.php';
    } else {
        $url = BASE_URL . "/views/{$folder}/dashboard.php";
    }
    header("Location: " . $url);
} else {
    header("Location: " . BASE_URL . "/views/auth/login.php");
}
exit;
