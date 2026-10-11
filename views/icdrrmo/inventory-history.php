<?php
// ============================================================================
// Redirect: views/icdrrmo/inventory-history.php -> views/icdrrmo/inventory.php?tab=movements
// Directly opens the ICDRRMO Inventory History and Movement Audit Log
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . BASE_URL . '/views/icdrrmo/inventory.php?tab=movements' . ($queryString ? '&' . $queryString : ''));
exit;
