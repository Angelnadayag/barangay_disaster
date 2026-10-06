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
$availability = trim($_POST['availability'] ?? 'Available');
$validAvailabilities = ['Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'];
if (!in_array($availability, $validAvailabilities, true)) {
    $availability = 'Available';
}

$userType = trim($_POST['user_type'] ?? '');
$position = trim($_POST['position'] ?? '');
$agencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;

if ($user['role'] === 'barangay_head') {
    if (!in_array($role, ['resident', 'responder'], true)) {
        $role = 'resident';
    }
    $barangayId = (int)$user['barangay_id'];
    if ($role === 'responder' && empty($agencyId)) {
        $agStmt = $db->prepare("SELECT id FROM agencies WHERE barangay_id = ? LIMIT 1");
        $agStmt->execute([$barangayId]);
        $agencyId = $agStmt->fetchColumn() ?: null;
    }
}

$isResident = ($role === 'resident' || $userType === 'resident');

if (empty($firstName) || empty($lastName) || empty($username) || empty($email) || empty($password)) {
    redirectWithFlash($returnUrl, 'error', 'First name, last name, username, email, and password are required.');
}

// Ensure username or email does not conflict in either users or residents
$chkUsers = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
$chkUsers->execute([$username, $email]);
if ($chkUsers->fetch()) {
    redirectWithFlash($returnUrl, 'error', 'Username or email already exists in system (users).');
}

$chkResidents = $db->prepare("SELECT id FROM residents WHERE username = ? OR email = ?");
$chkResidents->execute([$username, $email]);
if ($chkResidents->fetch()) {
    redirectWithFlash($returnUrl, 'error', 'Username or email already exists in system (residents).');
}

try {
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    if ($isResident) {
        $stmt = $db->prepare("
            INSERT INTO residents (
                username, password, first_name, last_name, full_name, email, phone, gender, age,
                barangay_id, purok, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([
            $username, $hashed, $firstName, $lastName, $fullName, $email, $phone, $gender, $age,
            $barangayId, $purok
        ]);

        logSystemEvent('CREATE_RESIDENT', 'Residents', "Registered resident $username ($fullName, Purok: $purok)");
        redirectWithFlash($returnUrl, 'success', 'Resident account created successfully.');
    } else {
        $stmt = $db->prepare("
            INSERT INTO users (
                username, password, first_name, last_name, full_name, email, phone, gender, age, role,
                agency_id, position, availability, barangay_id, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([
            $username, $hashed, $firstName, $lastName, $fullName, $email, $phone, $gender, $age, $role,
            $agencyId, $position, $availability, $barangayId
        ]);

        logSystemEvent('CREATE_USER', 'Users', "Created account $username ($fullName, $role, Position: $position)");
        redirectWithFlash($returnUrl, 'success', 'Personnel account created successfully.');
    }
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
