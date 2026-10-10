<?php
// ============================================================================
// Legacy Route: Manage Responders
// Seamlessly forwards to the consolidated Manage User module (users.php)
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';

$tab = ($_GET['tab'] ?? '') === 'archived' ? 'archived' : 'responders';
$params = $_GET;
$params['tab'] = $tab;
if ($tab === 'archived' && empty($params['type'])) {
    $params['type'] = 'responder';
}

header("Location: " . BASE_URL . "/views/barangay/users.php?" . http_build_query($params));
exit;
