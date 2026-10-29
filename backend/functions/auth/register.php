<?php
// ============================================================================
// Function: Auth - Resident Self-Registration (Create Account)
// Allows new residents to register an account directly from the portal login.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$redirectRegister = BASE_URL . '/views/auth/login.php?view=register';

// 1. Capture Form Inputs
$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$gender = trim($_POST['gender'] ?? 'Male');
$age = (!empty($_POST['age']) && is_numeric($_POST['age'])) ? (int)$_POST['age'] : null;
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
$purok = trim($_POST['purok'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Retain submitted values except password in case of validation failure
$_SESSION['register_old'] = [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'username' => $username,
    'email' => $email,
    'phone' => $phone,
    'gender' => $gender,
    'age' => $age,
    'barangay_id' => $barangayId,
    'purok' => $purok
];

// 2. Validate Inputs
if (empty($firstName) || empty($lastName) || empty($username) || empty($email) || empty($barangayId) || empty($password) || empty($confirmPassword)) {
    $_SESSION['register_error'] = 'Please fill in all required fields (Name, Username, Email, Barangay, and Password).';
    header('Location: ' . $redirectRegister);
    exit;
}

if (strlen($username) < 3) {
    $_SESSION['register_error'] = 'Username must be at least 3 characters long.';
    header('Location: ' . $redirectRegister);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['register_error'] = 'Please enter a valid email address.';
    header('Location: ' . $redirectRegister);
    exit;
}

if (strlen($password) < 6) {
    $_SESSION['register_error'] = 'Password must be at least 6 characters in length.';
    header('Location: ' . $redirectRegister);
    exit;
}

if ($password !== $confirmPassword) {
    $_SESSION['register_error'] = 'Passwords do not match.';
    header('Location: ' . $redirectRegister);
    exit;
}

$validGenders = ['Male', 'Female', 'Other'];
if (!in_array($gender, $validGenders, true)) {
    $gender = 'Male';
}

$db = getDBConnection();

// 3. Verify Barangay Exists
$bCheck = $db->prepare("SELECT id, name FROM barangays WHERE id = ? LIMIT 1");
$bCheck->execute([$barangayId]);
$barangay = $bCheck->fetch();
if (!$barangay) {
    $_SESSION['register_error'] = 'Selected barangay is invalid. Please select your barangay from the list.';
    header('Location: ' . $redirectRegister);
    exit;
}

// 4. Validate Purok conditionally:
// If barangay has puroks, resident must pick one; if barangay has no puroks, defaults to 'N/A'
$pCheck = $db->prepare("SELECT COUNT(*) FROM puroks WHERE barangay_id = ? AND status = 'active'");
$pCheck->execute([$barangayId]);
$hasPuroks = ((int)$pCheck->fetchColumn() > 0);

if ($hasPuroks) {
    if (empty($purok) || $purok === 'No puroks available') {
        $_SESSION['register_error'] = 'Please select a Purok for ' . htmlspecialchars($barangay['name']) . '.';
        header('Location: ' . $redirectRegister);
        exit;
    }
} else {
    if (empty($purok) || $purok === 'No puroks available') {
        $purok = 'N/A';
    }
}

// 4. Check Username & Email Uniqueness across both users and residents
$uCheck1 = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
$uCheck1->execute([$username, $email]);
if ($uCheck1->fetch()) {
    $_SESSION['register_error'] = 'That username or email address is already in use. Please choose a different one or sign in.';
    header('Location: ' . $redirectRegister);
    exit;
}

$rCheck1 = $db->prepare("SELECT id FROM residents WHERE username = ? OR email = ? LIMIT 1");
$rCheck1->execute([$username, $email]);
if ($rCheck1->fetch()) {
    $_SESSION['register_error'] = 'That username or email address is already in use. Please choose a different one or sign in.';
    header('Location: ' . $redirectRegister);
    exit;
}

// 5. Insert new Resident
try {
    $fullName = trim($firstName . ' ' . $lastName);
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $db->prepare("
        INSERT INTO residents (
            username, password, previous_password, first_name, last_name, full_name, email, phone, gender, age,
            barangay_id, purok, status, created_at
        ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");

    $stmt->execute([
        $username,
        $hashedPassword,
        $firstName,
        $lastName,
        $fullName,
        $email,
        $phone,
        $gender,
        $age,
        $barangayId,
        $purok
    ]);

    $newId = $db->lastInsertId();

    logSystemEvent(
        'REGISTER_RESIDENT',
        'Residents',
        "New resident registered self-service: {$username} ({$fullName}, {$barangay['name']}, Purok: {$purok})",
        $newId,
        $fullName,
        'resident'
    );

    // Clear saved form values
    unset($_SESSION['register_old']);

    $_SESSION['login_success'] = 'Your resident account has been created successfully! You can now log in with your credentials.';
    $_SESSION['login_prefill_username'] = $username;
    header('Location: ' . BASE_URL . '/views/auth/login.php?view=login');
    exit;
} catch (Exception $e) {
    $_SESSION['register_error'] = 'Registration error: ' . $e->getMessage();
    header('Location: ' . $redirectRegister);
    exit;
}
