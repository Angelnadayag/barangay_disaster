<?php
// Evacuation route has been merged into Home (dashboard.php#evacuation-routing)
require_once __DIR__ . '/../../backend/config/config.php';
header("Location: " . BASE_URL . "/views/responder/dashboard.php#evacuation-routing");
exit;
