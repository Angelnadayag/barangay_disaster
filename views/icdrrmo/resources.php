<?php
// ============================================================================
// Redirect: views/icdrrmo/resources.php -> views/icdrrmo/inventory.php
// Ensures backward compatibility for all dashboard links & bookmarks
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . BASE_URL . '/views/icdrrmo/inventory.php' . ($queryString ? '?' . $queryString : ''));
exit;
