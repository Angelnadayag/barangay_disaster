<?php
// ============================================================================
// Function: Auth - Switch Demo Role
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$targetRole = $_POST['role'] ?? ($_GET['role'] ?? '');
$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
$allowed = ['icdrrmo', 'barangay_head', 'responder', 'resident'];

if (!in_array($targetRole, $allowed, true)) {
    redirectWithFlash($returnUrl, 'error', 'Invalid role specified.');
}

$success = switchDemoRole($targetRole);
if ($success) {
    $u = getCurrentUser();
    $targetFolder = getRoleFolder($u['role']);
    if ($u['role'] === 'icdrrmo') {
        $targetUrl = BASE_URL . '/views/icdrrmo/users.php';
    } elseif ($u['role'] === 'barangay_head') {
        $targetUrl = BASE_URL . '/views/barangay/dashboard.php';
    } else {
        $targetUrl = BASE_URL . "/views/{$targetFolder}/dashboard.php";
    }
    redirectWithFlash($targetUrl, 'success', 'Switched session to ' . formatRoleName($u['role']) . '.');
} else {
    redirectWithFlash($returnUrl, 'error', 'Unable to find active account for selected role.');
}
