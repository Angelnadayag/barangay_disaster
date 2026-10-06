<?php
// ============================================================================
// Function: Users - Create Account (User or Resident)
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/can_manage.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/users.php'));

if ($user['role'] !== 'icdrrmo' && $user['role'] !== 'barangay_head') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$fullName = trim($firstName . ' ' . $lastName);
if (empty($fullName)) {
    $fullName = trim($_POST['full_name'] ?? '');
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$gender = trim($_POST['gender'] ?? 'Male');
$age = (!empty($_POST['age']) && is_numeric($_POST['age'])) ? (int)$_POST['age'] : null;
$role = trim($_POST['role'] ?? 'resident');
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
$purok = trim($_POST['purok'] ?? '');
$password = $_POST['password'] ?? 'admin123';

if ($user['role'] === 'barangay_head') {
    $role = 'resident';
    $barangayId = (int)$user['barangay_id'];
}

if (empty($firstName) || empty($lastName) || empty($username) || empty($email) || empty($password)) {
    redirectWithFlash($returnUrl, 'error', 'First name, last name, username, email, and password are required.');
}

$chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
$chk->execute([$username, $email]);
if ($chk->fetch()) {
    redirectWithFlash($returnUrl, 'error', 'Username or email already exists in system.');
}

try {
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("
        INSERT INTO users (
            username, password, first_name, last_name, full_name, email, phone, gender, age, role,
            barangay_id, purok, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    $stmt->execute([
        $username, $hashed, $firstName, $lastName, $fullName, $email, $phone, $gender, $age, $role,
        $barangayId, $purok
    ]);

    logSystemEvent('CREATE_USER', 'Users', "Created account $username ($fullName, $role)");

    redirectWithFlash($returnUrl, 'success', 'Account created successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
