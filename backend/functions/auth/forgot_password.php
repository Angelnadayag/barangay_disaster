<?php
// ============================================================================
// Function: Auth - Forgot / Reset Password
// Allows user to verify identity via Current Password and Previous Password,
// then immediately set a new password.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$username = trim($_POST['username'] ?? '');
$currentPassword = $_POST['current_password'] ?? '';
$previousPassword = $_POST['previous_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$redirectForgot = BASE_URL . '/views/auth/login.php?view=forgot';

// 1. Basic validation
if (empty($username) || empty($currentPassword) || empty($previousPassword) || empty($newPassword) || empty($confirmPassword)) {
    $_SESSION['forgot_error'] = 'Please fill in all required fields to reset your password.';
    header('Location: ' . $redirectForgot);
    exit;
}

if (strlen($newPassword) < 6) {
    $_SESSION['forgot_error'] = 'New password must be at least 6 characters in length.';
    header('Location: ' . $redirectForgot);
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['forgot_error'] = 'New password and confirmation password do not match.';
    header('Location: ' . $redirectForgot);
    exit;
}

$db = getDBConnection();
$table = 'users';
$isResident = false;

// 2. Locate account across users and residents
$stmt = $db->prepare("
    SELECT id, username, email, full_name, role, password, previous_password 
    FROM users 
    WHERE username = ? OR email = ? 
    LIMIT 1
");
$stmt->execute([$username, $username]);
$account = $stmt->fetch();

if (!$account) {
    $rStmt = $db->prepare("
        SELECT id, username, email, full_name, 'resident' AS role, password, previous_password 
        FROM residents 
        WHERE username = ? OR (email IS NOT NULL AND email != '' AND email = ?)
        LIMIT 1
    ");
    $rStmt->execute([$username, $username]);
    $account = $rStmt->fetch();
    if ($account) {
        $isResident = true;
        $table = 'residents';
    }
}

if (!$account) {
    $_SESSION['forgot_error'] = 'No account was found with that username or email address.';
    header('Location: ' . $redirectForgot);
    exit;
}

// 3. Verify Current Password and Previous Password
$storedPasswordHash = $account['password'] ?? '';
$storedPreviousHash = $account['previous_password'] ?? null;

$currentValid = password_verify($currentPassword, $storedPasswordHash);
$previousValid = false;

if (!empty($storedPreviousHash)) {
    // Account has a recorded previous password: check if entered previous password matches it
    $previousValid = password_verify($previousPassword, $storedPreviousHash);
} else {
    // If account has no previous password recorded yet (first time reset):
    // allow if current password is valid AND (previous password matches current or initial password)
    $previousValid = true;
}

// Check verification condition
$verified = false;

if ($currentValid && $previousValid) {
    $verified = true;
} elseif ($currentValid && empty($storedPreviousHash)) {
    $verified = true;
} elseif (!empty($storedPreviousHash) && password_verify($previousPassword, $storedPreviousHash)) {
    // User remembered previous password
    $verified = true;
} elseif (password_verify($previousPassword, $storedPasswordHash)) {
    // User entered correct current password in previous password field
    $verified = true;
}

if (!$verified) {
    $_SESSION['forgot_error'] = 'Verification failed. The current password or previous password entered does not match our records for this account.';
    header('Location: ' . $redirectForgot);
    exit;
}

// Prevent re-using the exact current password as new password
if (password_verify($newPassword, $storedPasswordHash)) {
    $_SESSION['forgot_error'] = 'New password cannot be identical to your current password. Please choose a different password.';
    header('Location: ' . $redirectForgot);
    exit;
}

// 4. Update password immediately & move active password to previous_password
try {
    $newHashed = password_hash($newPassword, PASSWORD_BCRYPT);
    $updStmt = $db->prepare("
        UPDATE {$table} 
        SET previous_password = password, password = ? 
        WHERE id = ?
    ");
    $updStmt->execute([$newHashed, $account['id']]);

    logSystemEvent(
        'PASSWORD_RESET', 
        'Auth', 
        "User {$account['username']} ({$account['role']}) reset password via current/previous password verification.", 
        $account['id'], 
        $account['full_name'], 
        $account['role']
    );

    $_SESSION['login_success'] = 'Password reset successfully! You can now sign in with your new password.';
    $_SESSION['login_prefill_username'] = $account['username'];
    header('Location: ' . BASE_URL . '/views/auth/login.php?view=login');
    exit;
} catch (Exception $e) {
    $_SESSION['forgot_error'] = 'Database error while resetting password: ' . $e->getMessage();
    header('Location: ' . $redirectForgot);
    exit;
}
