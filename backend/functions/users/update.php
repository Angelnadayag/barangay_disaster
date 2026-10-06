<?php
// ============================================================================
// Function: Users - Update Account (User or Resident)
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

$targetUserId = (int)($_POST['user_id'] ?? 0);
if (!canUserManageTarget($user, $targetUserId, $db)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to modify this account.');
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

$targetRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
$targetRoleStmt->execute([$targetUserId]);
$targetExistingRole = $targetRoleStmt->fetchColumn() ?: 'resident';

if ($user['role'] === 'barangay_head') {
    if (in_array($targetExistingRole, ['resident', 'responder'], true)) {
        $role = $targetExistingRole;
    } else {
        $role = 'resident';
    }
    $barangayId = (int)$user['barangay_id'];
}
$purok = trim($_POST['purok'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($targetUserId) || empty($firstName) || empty($lastName) || empty($username) || empty($email)) {
    redirectWithFlash($returnUrl, 'error', 'First name, last name, username, and email must not be empty.');
}

$chk = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
$chk->execute([$username, $email, $targetUserId]);
if ($chk->fetch()) {
    redirectWithFlash($returnUrl, 'error', 'Username or email already belongs to another account.');
}

try {
    $fields = [
        'first_name = ?', 'last_name = ?', 'full_name = ?', 'username = ?', 'email = ?', 'phone = ?',
        'gender = ?', 'age = ?', 'role = ?', 'barangay_id = ?', 'purok = ?'
    ];
    $params = [$firstName, $lastName, $fullName, $username, $email, $phone, $gender, $age, $role, $barangayId, $purok];

    if (isset($_POST['availability'])) {
        $avail = trim($_POST['availability']);
        $validAvails = ['Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'];
        if (in_array($avail, $validAvails, true)) {
            $fields[] = 'availability = ?';
            $params[] = $avail;
        }
    }

    if (!empty($password)) {
        $fields[] = 'password = ?';
        $params[] = password_hash($password, PASSWORD_BCRYPT);
    }

    $params[] = $targetUserId;
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    logSystemEvent('UPDATE_USER', 'Users', "Updated account #$targetUserId ($username)");
    redirectWithFlash($returnUrl, 'success', 'Account updated successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
