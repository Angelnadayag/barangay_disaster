<?php
// ============================================================================
// Function: Notifications - Broadcast Advisory / Alert
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/notifications.php'));

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to broadcast alerts.');
}

$title = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');
$alertLevel = trim($_POST['alert_level'] ?? 'informational');
$targetRole = trim($_POST['target_role'] ?? 'all');
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;

if ($user['role'] === 'barangay_head') {
    $barangayId = $user['barangay_id'];
}

if (empty($title) || empty($message)) {
    redirectWithFlash($returnUrl, 'error', 'Title and advisory message are required.');
}

try {
    $stmt = $db->prepare("
        INSERT INTO notifications (
            target_role, target_barangay_id, title, message, alert_level,
            related_module, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, 'system', ?, NOW())
    ");
    $stmt->execute([$targetRole, $barangayId, $title, $message, $alertLevel, $user['id']]);

    logSystemEvent('BROADCAST_ALERT', 'Notifications', "Broadcasted $alertLevel alert: $title");

    redirectWithFlash($returnUrl, 'success', 'Official advisory broadcasted successfully.');
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
