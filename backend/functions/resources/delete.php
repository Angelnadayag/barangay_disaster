<?php
// ============================================================================
// Function: Resources - Delete Resource
// Scoped to Barangay jurisdiction with cascade safety and AJAX support
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

requireLogin();
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (
    $role === 'barangay_head' ? BASE_URL . '/views/barangay/resources.php' : BASE_URL . '/views/icdrrmo/manage-resources.php'
));

if (!in_array($role, ['icdrrmo', 'barangay_head'], true)) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Invalid resource ID.'], 400);
    redirectWithFlash($returnUrl, 'error', 'Invalid resource ID.');
}

$stmt = $db->prepare("SELECT * FROM resources WHERE id = ?");
$stmt->execute([$id]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$res) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    redirectWithFlash($returnUrl, 'error', 'Resource not found.');
}

if ($role === 'barangay_head' && (int)$res['barangay_id'] !== (int)$user['barangay_id']) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Unauthorized: This resource belongs to another barangay.'], 403);
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to delete resources outside your barangay.');
}

try {
    $del = $db->prepare("DELETE FROM resources WHERE id = ?");
    $del->execute([$id]);

    logSystemEvent('DELETE_RESOURCE', 'Resources', "Deleted resource '{$res['name']}' ({$res['code']})");

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => "Resource '{$res['name']}' removed from inventory.",
            'id' => $id
        ]);
    }

    redirectWithFlash($returnUrl, 'success', "Resource '{$res['name']}' removed from inventory.");
} catch (Exception $e) {
    if ($isAjax) jsonResponse(['success' => false, 'message' => 'Failed to delete resource: ' . $e->getMessage()], 500);
    redirectWithFlash($returnUrl, 'error', 'Failed to delete resource: ' . $e->getMessage());
}
