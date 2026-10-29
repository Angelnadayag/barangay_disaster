<?php
// ============================================================================
// Function: Resources - Archive Resource
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/icdrrmo/manage-resources.php'));

if ($user['role'] !== 'icdrrmo') {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
$stmt = $db->prepare("SELECT name, code FROM resources WHERE id = ?");
$stmt->execute([$id]);
$resItem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resItem) {
    redirectWithFlash($returnUrl, 'error', 'Resource not found.');
}

try {
    $up = $db->prepare("UPDATE resources SET status = 'archived', updated_at = NOW() WHERE id = ?");
    $up->execute([$id]);

    $db->prepare("
        INSERT INTO resource_transactions (
            resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at
        ) VALUES (?, 'Archived', 0, 'manual', ?, ?, ?, NOW())
    ")->execute([
        $id,
        $id,
        "Commodity '{$resItem['name']}' [{$resItem['code']}] decommissioned / archived by {$user['full_name']}",
        $user['id']
    ]);

    logSystemEvent('ARCHIVE_SUPPLY', 'Supplies', "Archived supply {$resItem['code']}: {$resItem['name']} (#$id)");

    redirectWithFlash($returnUrl, 'success', "Resource '{$resItem['name']}' moved to Archived.");
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Failed to archive: ' . $e->getMessage());
}
