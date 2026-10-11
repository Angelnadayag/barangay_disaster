<?php
// ============================================================================
// Redirect: views/icdrrmo/inventory-adjustment.php -> views/icdrrmo/inventory.php?tab=catalog&action=adjust
// Directly opens the ICDRRMO Central Inventory Adjustment Module
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . BASE_URL . '/views/icdrrmo/inventory.php?tab=catalog&action=adjust' . ($queryString ? '&' . $queryString : ''));
exit;
