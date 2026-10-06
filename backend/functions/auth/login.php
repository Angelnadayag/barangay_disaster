<?php
// ============================================================================
// Function: Auth - User Login
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter both username and password.';
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("
    SELECT u.*, b.name AS barangay_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    WHERE u.username = ? OR u.email = ?
    LIMIT 1
");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    if ($user['status'] !== 'active') {
        $_SESSION['login_error'] = 'Your account is pending review or inactive. Please contact ICDRRMO.';
        header('Location: ' . BASE_URL . '/views/auth/login.php');
        exit;
    }

    // Update last login timestamp
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    unset($user['password']);
    $_SESSION['user'] = $user;

    logSystemEvent('LOGIN', 'Auth', "User {$user['username']} logged in successfully.", $user['id'], $user['full_name'], $user['role']);

    $targetFolder = getRoleFolder($user['role']);
    if ($user['role'] === 'icdrrmo') {
        $redirectUrl = BASE_URL . '/views/icdrrmo/users.php';
    } elseif ($user['role'] === 'barangay_head') {
        $redirectUrl = BASE_URL . '/views/barangay/dashboard.php';
    } else {
        $redirectUrl = BASE_URL . "/views/{$targetFolder}/dashboard.php";
    }

    header('Location: ' . $redirectUrl);
    exit;
} else {
    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}
