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
$userType = trim($_POST['user_type'] ?? '');
$role = trim($_POST['role'] ?? '');

// Determine whether this target is in residents table or users table
$isResident = ($userType === 'resident' || $role === 'resident');
if (!$isResident) {
    // Check if ID exists in residents table
    $chkR = $db->prepare("SELECT id FROM residents WHERE id = ?");
    $chkR->execute([$targetUserId]);
    if ($chkR->fetch()) {
        $isResident = true;
    }
}

if (!canUserManageTarget($user, $targetUserId, $db, $isResident ? 'resident' : 'user')) {
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
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
$purok = trim($_POST['purok'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($targetUserId) || empty($firstName) || empty($lastName) || empty($username) || empty($email)) {
    redirectWithFlash($returnUrl, 'error', 'First name, last name, username, and email must not be empty.');
}

if ($user['role'] === 'barangay_head') {
    $barangayId = (int)$user['barangay_id'];
}

try {
    if ($isResident) {
        // Unique check for residents
        $chk1 = $db->prepare("SELECT id FROM residents WHERE (username = ? OR email = ?) AND id != ?");
        $chk1->execute([$username, $email, $targetUserId]);
        if ($chk1->fetch()) {
            redirectWithFlash($returnUrl, 'error', 'Username or email already belongs to another resident.');
        }

        $chk2 = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk2->execute([$username, $email]);
        if ($chk2->fetch()) {
            redirectWithFlash($returnUrl, 'error', 'Username or email already belongs to an existing user account.');
        }

        $fields = [
            'first_name = ?', 'last_name = ?', 'full_name = ?', 'username = ?', 'email = ?', 'phone = ?',
            'gender = ?', 'age = ?', 'barangay_id = ?', 'purok = ?'
        ];
        $params = [$firstName, $lastName, $fullName, $username, $email, $phone, $gender, $age, $barangayId, $purok];

        if (!empty($password)) {
            $fields[] = 'password = ?';
            $params[] = password_hash($password, PASSWORD_BCRYPT);
        }

        $params[] = $targetUserId;
        $sql = "UPDATE residents SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        logSystemEvent('UPDATE_RESIDENT', 'Residents', "Updated resident account #$targetUserId ($username)");
        redirectWithFlash($returnUrl, 'success', 'Resident details updated successfully.');
    } else {
        // Unique check for users
        $chk1 = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $chk1->execute([$username, $email, $targetUserId]);
        if ($chk1->fetch()) {
            redirectWithFlash($returnUrl, 'error', 'Username or email already belongs to another user.');
        }

        $chk2 = $db->prepare("SELECT id FROM residents WHERE username = ? OR email = ?");
        $chk2->execute([$username, $email]);
        if ($chk2->fetch()) {
            redirectWithFlash($returnUrl, 'error', 'Username or email already belongs to an existing resident.');
        }

        $targetRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $targetRoleStmt->execute([$targetUserId]);
        $targetExistingRole = $targetRoleStmt->fetchColumn() ?: 'responder';

        if ($user['role'] === 'barangay_head') {
            $role = 'responder';
        } elseif (empty($role)) {
            $role = $targetExistingRole;
        }

        $fields = [
            'first_name = ?', 'last_name = ?', 'full_name = ?', 'username = ?', 'email = ?', 'phone = ?',
            'gender = ?', 'age = ?', 'role = ?', 'barangay_id = ?'
        ];
        $params = [$firstName, $lastName, $fullName, $username, $email, $phone, $gender, $age, $role, $barangayId];

        if (isset($_POST['position'])) {
            $fields[] = 'position = ?';
            $params[] = trim($_POST['position']);
        }

        if (isset($_POST['agency_id'])) {
            $fields[] = 'agency_id = ?';
            $params[] = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

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

        logSystemEvent('UPDATE_USER', 'Users', "Updated personnel account #$targetUserId ($username)");
        redirectWithFlash($returnUrl, 'success', 'Account updated successfully.');
    }
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Error: ' . $e->getMessage());
}
