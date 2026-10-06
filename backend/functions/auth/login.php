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
$isResident = false;

// 1. Check agency users (ICDRRMO, Barangay Head, Responder)
$stmt = $db->prepare("
    SELECT u.*, b.name AS barangay_name, a.name AS agency_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    LEFT JOIN agencies a ON u.agency_id = a.id
    WHERE u.username = ? OR u.email = ?
    LIMIT 1
");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

// 2. If not found in users, check residents table
if (!$user) {
    $rStmt = $db->prepare("
        SELECT r.*, b.name AS barangay_name 
        FROM residents r 
        LEFT JOIN barangays b ON r.barangay_id = b.id 
        WHERE r.username = ? OR (r.email IS NOT NULL AND r.email != '' AND r.email = ?)
        LIMIT 1
    ");
    $rStmt->execute([$username, $username]);
    $user = $rStmt->fetch();
    if ($user) {
        $isResident = true;
        $user['role'] = 'resident';
    }
}

if ($user && password_verify($password, $user['password'])) {
    if ($user['status'] !== 'active') {
        $_SESSION['login_error'] = 'Your account is pending review or inactive. Please contact the barangay.';
        header('Location: ' . BASE_URL . '/views/auth/login.php');
        exit;
    }

    // Update last login timestamp in respective table
    if ($isResident) {
        $db->prepare("UPDATE residents SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    } else {
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    }

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
