<?php
// ============================================================================
// Views: Authentication Portal
// 2-Column Layout:
// Left Column: OpenStreetMap displaying Designated Evacuation Centers only
//              (Barangays, Puroks, and CDRRMO markers excluded per request)
// Right Column: Clean Login Box
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

if (isLoggedIn()) {
    $u = getCurrentUser();
    $folder = getRoleFolder($u['role']);
    if ($u['role'] === 'icdrrmo') {
        $redir = BASE_URL . '/views/icdrrmo/users.php';
    } elseif ($u['role'] === 'barangay_head') {
        $redir = BASE_URL . '/views/barangay/dashboard.php';
    } else {
        $redir = BASE_URL . "/views/{$folder}/dashboard.php";
    }
    header('Location: ' . $redir);
    exit;
}

$db = getDBConnection();

// Fetch all designated Evacuation Centers joined with their host Barangay
$evacCenters = $db->query("
    SELECT ea.id, ea.name, ea.barangay_id, ea.location_address, ea.center_type, 
           ea.capacity_individuals, ea.capacity_families, ea.current_evacuees_count, 
           ea.status, ea.accessibility, ea.has_potable_water, ea.has_electricity, 
           ea.has_medical_station, ea.contact_officer, ea.contact_number, 
           ea.coordinates_lat, ea.coordinates_lng,
           b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id
    ORDER BY ea.status ASC, ea.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch prepositioned and allocated resources for evacuation centers
$resourcesStmt = $db->query("
    SELECT ecr.evacuation_area_id, ecr.resource_name, ecr.category, ecr.unit, ecr.quantity, ecr.status
    FROM evacuation_center_resources ecr
    WHERE ecr.quantity > 0
    ORDER BY ecr.category ASC, ecr.resource_name ASC
");
$evacResources = [];
while ($row = $resourcesStmt->fetch(PDO::FETCH_ASSOC)) {
    $evacResources[$row['evacuation_area_id']][] = $row;
}

// Also check for any approved/allocated disaster request supplies for host barangays
$allocStmt = $db->query("
    SELECT dr.barangay_id, res.name AS resource_name, res.category, res.unit, 
           SUM(ri.allocated_quantity) AS quantity, 'Allocated' AS status
    FROM recommended_items ri
    JOIN resources res ON ri.resource_id = res.id
    JOIN recommendations r ON ri.recommendation_id = r.id
    JOIN disaster_requests dr ON r.disaster_request_id = dr.id
    WHERE ri.status = 'Allocated' AND ri.allocated_quantity > 0
    GROUP BY dr.barangay_id, res.id
");
$barangayAllocations = [];
while ($row = $allocStmt->fetch(PDO::FETCH_ASSOC)) {
    $barangayAllocations[$row['barangay_id']][] = $row;
}

// Compute capacity metrics & attach resources
foreach ($evacCenters as &$ec) {
    $cId = (int)$ec['id'];
    $bId = (int)$ec['barangay_id'];
    
    $combined = $evacResources[$cId] ?? [];
    if (!empty($barangayAllocations[$bId])) {
        foreach ($barangayAllocations[$bId] as $allocItem) {
            $found = false;
            foreach ($combined as &$existing) {
                if (strcasecmp($existing['resource_name'], $allocItem['resource_name']) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $combined[] = $allocItem;
            }
        }
    }
    $ec['resources'] = $combined;

    $capInd = max(1, (int)$ec['capacity_individuals']);
    $curEvac = max(0, (int)$ec['current_evacuees_count']);
    $remInd = max(0, $capInd - $curEvac);
    $capFam = max(1, (int)$ec['capacity_families']);
    $remFam = max(0, (int)round($capFam * ($remInd / $capInd)));
    $occupancyPct = min(100, (int)round(($curEvac / $capInd) * 100));
    $remainingPct = max(0, 100 - $occupancyPct);

    $ec['remaining_capacity_individuals'] = $remInd;
    $ec['remaining_capacity_families'] = $remFam;
    $ec['occupancy_percentage'] = $occupancyPct;
    $ec['remaining_percentage'] = $remainingPct;
}
unset($ec);

$errorMessage = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= APP_NAME ?></title>

  <!-- Google Fonts: Poppins (Primary) & Roboto (Secondary) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">

  <!-- Project CSS & Fonts -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/css/app.css">
  
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

  <style>
    :root {
      --font-primary: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      --font-secondary: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
      overflow: hidden;
      font-family: var(--font-primary);
      background-color: var(--color-background, #F7F8F7);
      color: var(--color-text, #24313A);
      -webkit-font-smoothing: antialiased;
    }

    /* 2-Column Split Layout */
    .login-wrapper {
      display: flex;
      height: 100vh;
      width: 100vw;
    }

    /* Column 1: Map Pane (Left) */
    .map-pane {
      flex: 1;
      height: 100%;
      position: relative;
      background: var(--color-background, #F7F8F7);
      min-width: 0;
    }

    #map {
      width: 100%;
      height: 100%;
    }

    /* Map Status Header Badge */
    .map-header-badge {
      position: absolute;
      top: 16px;
      left: 16px;
      z-index: 1000;
      background: var(--color-primary, #17324D);
      color: #FFFFFF;
      padding: 8px 14px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 500;
      font-family: var(--font-primary);
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1px solid rgba(255, 255, 255, 0.14);
      box-shadow: 0 2px 8px rgba(23, 50, 77, 0.16);
    }

    .status-pulse {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--color-secondary, #2F6F73);
      display: inline-block;
    }

    /* Map Controls Group (Top Right) */
    .map-top-controls {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 1000;
      display: flex;
      gap: 8px;
    }

    .map-control-btn {
      background: var(--color-surface, #FFFFFF);
      color: var(--color-primary, #17324D);
      border: 1px solid var(--color-border, #D9E0E3);
      border-radius: 6px;
      padding: 7px 14px;
      font-size: 11px;
      font-weight: 500;
      font-family: var(--font-primary);
      cursor: pointer;
      box-shadow: 0 1px 3px rgba(23, 50, 77, 0.08);
      transition: background 0.15s ease, border-color 0.15s ease;
    }

    .map-control-btn:hover {
      background: var(--color-surface-subtle, #F0F3F4);
      border-color: var(--color-secondary, #2F6F73);
    }

    /* Map Legend Overlay */
    .map-legend {
      position: absolute;
      bottom: 20px;
      left: 16px;
      z-index: 1000;
      background: var(--color-surface, #FFFFFF);
      border: 1px solid var(--color-border, #D9E0E3);
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 10px;
      font-family: var(--font-primary);
      box-shadow: 0 2px 10px rgba(23, 50, 77, 0.08);
      max-width: 440px;
    }

    .map-legend-title {
      font-weight: 600;
      color: var(--color-primary, #17324D);
      margin-bottom: 6px;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }

    .map-legend-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      font-family: var(--font-secondary);
      font-size: 11px;
    }

    .map-legend-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--color-text, #24313A);
    }

    .legend-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }

    .legend-dot.status-open { background: var(--color-success, #2E7D32); }
    .legend-dot.status-standby { background: var(--color-secondary, #2F6F73); }
    .legend-dot.status-capacity { background: var(--color-danger, #C62828); }
    .legend-dot.status-closed { background: var(--color-text-muted, #8A96A0); }

    /* Custom Evacuation Pin Styling */
    .custom-evac-marker {
      transition: transform 0.15s ease;
      cursor: pointer;
    }

    .custom-evac-marker:hover {
      transform: scale(1.05) translateY(-2px);
      z-index: 9999 !important;
    }

    .evac-pin-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 8px;
      border-radius: 5px;
      font-size: 10px;
      font-weight: 500;
      font-family: var(--font-primary);
      color: #FFFFFF;
      box-shadow: 0 2px 6px rgba(23, 50, 77, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.9);
      white-space: nowrap;
    }

    .evac-pin-badge.status-open {
      background: var(--color-success, #2E7D32);
    }

    .evac-pin-badge.status-standby {
      background: var(--color-secondary, #2F6F73);
    }

    .evac-pin-badge.status-capacity {
      background: var(--color-danger, #C62828);
    }

    .evac-pin-badge.status-closed {
      background: var(--color-text-muted, #8A96A0);
    }

    /* Evacuation Tooltip & Popup */
    .leaflet-tooltip.evac-hover-tooltip {
      background: var(--color-surface, #FFFFFF);
      border: 1px solid var(--color-border, #D9E0E3);
      border-radius: 6px;
      padding: 0;
      box-shadow: 0 4px 16px rgba(23, 50, 77, 0.12);
      color: var(--color-text, #24313A);
      font-family: var(--font-primary);
      line-height: 1.4;
      white-space: normal;
      width: 320px;
      max-width: 90vw;
      overflow: hidden;
      pointer-events: none;
    }

    .leaflet-tooltip-top.evac-hover-tooltip::before {
      border-top-color: var(--color-border, #D9E0E3);
    }
    .leaflet-tooltip-bottom.evac-hover-tooltip::before {
      border-bottom-color: var(--color-border, #D9E0E3);
    }

    .leaflet-popup.evac-click-popup .leaflet-popup-content-wrapper {
      padding: 0;
      border-radius: 6px;
      overflow: hidden;
      box-shadow: 0 6px 20px rgba(23, 50, 77, 0.16);
      border: 1px solid var(--color-border, #D9E0E3);
      font-family: var(--font-primary);
    }
    .leaflet-popup.evac-click-popup .leaflet-popup-content {
      margin: 0;
      line-height: inherit;
      width: 320px !important;
    }
    .leaflet-popup.evac-click-popup .leaflet-popup-close-button {
      top: 8px;
      right: 8px;
      color: #FFFFFF !important;
      font-size: 16px;
      z-index: 10;
    }

    /* Inner Evacuation Information Card */
    .evac-card-wrapper {
      font-size: 11px;
      font-family: var(--font-primary);
    }

    .evac-card-header {
      background: var(--color-primary, #17324D);
      color: #FFFFFF;
      padding: 10px 14px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .evac-card-header-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
      gap: 6px;
    }

    .evac-badge-type {
      font-size: 9px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: rgba(255, 255, 255, 0.85);
      background: rgba(255, 255, 255, 0.12);
      padding: 2px 6px;
      border-radius: 4px;
    }

    .evac-badge-status {
      font-size: 9px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 4px;
      color: #FFFFFF;
    }

    .evac-card-title {
      font-size: 13px;
      font-weight: 600;
      color: #FFFFFF;
      line-height: 1.3;
      margin-bottom: 2px;
    }

    .evac-card-location {
      font-size: 10px;
      color: rgba(255, 255, 255, 0.75);
      font-family: var(--font-secondary);
    }

    .evac-card-body {
      padding: 12px 14px;
      background: var(--color-surface, #FFFFFF);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    /* Capacity Status Box */
    .evac-capacity-box {
      background: var(--color-surface-subtle, #F0F3F4);
      border: 1px solid var(--color-border-light, #E7EDF0);
      border-radius: 6px;
      padding: 8px 10px;
    }

    .evac-capacity-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }

    .evac-capacity-highlight {
      font-weight: 600;
      font-size: 11px;
    }

    .evac-capacity-highlight.has-space {
      color: var(--color-primary, #17324D);
    }

    .evac-capacity-highlight.at-capacity {
      color: var(--color-danger, #C62828);
    }

    .evac-capacity-pill {
      font-size: 9px;
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 4px;
    }

    .evac-capacity-pill.pill-green {
      background: var(--color-success-bg, #EAF4EB);
      color: var(--color-success, #2E7D32);
      border: 1px solid var(--color-success-border, #BCE3C1);
    }

    .evac-capacity-pill.pill-red {
      background: var(--color-danger-bg, #FDE8E8);
      color: var(--color-danger, #C62828);
      border: 1px solid var(--color-danger-border, #F8B4B4);
    }

    .evac-capacity-bar-wrap {
      width: 100%;
      height: 6px;
      background: var(--color-border-light, #E7EDF0);
      border-radius: 3px;
      overflow: hidden;
      display: flex;
      margin-top: 6px;
    }

    .evac-capacity-bar-occupied {
      height: 100%;
      background: var(--color-secondary, #2F6F73);
      transition: width 0.3s ease;
    }

    .evac-capacity-bar-occupied.full {
      background: var(--color-danger, #C62828);
    }

    .evac-capacity-subtext {
      display: flex;
      justify-content: space-between;
      font-size: 9px;
      color: var(--color-text-secondary, #66737D);
      font-family: var(--font-secondary);
      margin-top: 5px;
    }

    /* Resources Section */
    .evac-resources-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }

    .evac-resources-title {
      font-size: 9px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--color-primary, #17324D);
    }

    .evac-resources-tag {
      font-size: 8.5px;
      color: var(--color-secondary, #2F6F73);
      background: var(--color-surface-subtle, #F0F3F4);
      border: 1px solid var(--color-border-light, #E7EDF0);
      padding: 1px 6px;
      border-radius: 3px;
      font-weight: 500;
    }

    .evac-resources-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 5px;
    }

    .evac-resource-card {
      background: var(--color-surface-subtle, #F0F3F4);
      border: 1px solid var(--color-border-light, #E7EDF0);
      border-radius: 4px;
      padding: 5px 8px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .evac-resource-name {
      font-size: 9px;
      color: var(--color-text-secondary, #66737D);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-bottom: 1px;
    }

    .evac-resource-qty {
      font-size: 11px;
      font-weight: 600;
      color: var(--color-primary, #17324D);
      font-family: var(--font-secondary);
    }

    /* Amenities Row */
    .evac-amenities-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4px;
      font-size: 9.5px;
      font-family: var(--font-secondary);
    }

    .evac-amenity-chip {
      background: var(--color-surface-subtle, #F0F3F4);
      border: 1px solid var(--color-border-light, #E7EDF0);
      padding: 3.5px 6px;
      border-radius: 4px;
      color: var(--color-text, #24313A);
    }

    .evac-amenity-chip.inactive {
      color: var(--color-text-muted, #8A96A0);
    }

    /* Focal Officer Footer */
    .evac-focal-card {
      border-top: 1px solid var(--color-border-light, #E7EDF0);
      padding-top: 7px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 9.5px;
      color: var(--color-text-secondary, #66737D);
      font-family: var(--font-secondary);
    }

    .evac-call-link {
      background: var(--color-secondary, #2F6F73);
      color: #FFFFFF;
      padding: 3px 8px;
      border-radius: 4px;
      text-decoration: none;
      font-weight: 500;
      font-size: 9px;
      transition: background 0.15s ease;
    }

    .evac-call-link:hover {
      background: var(--color-secondary-light, #3D8C91);
      color: #FFFFFF;
    }

    /* Column 2: Login Box (Right) */
    .login-pane {
      width: 440px;
      min-width: 380px;
      max-width: 480px;
      height: 100%;
      background: var(--color-surface, #FFFFFF);
      border-left: 1px solid var(--color-border, #D9E0E3);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 44px 38px;
      box-sizing: border-box;
      box-shadow: -4px 0 20px rgba(23, 50, 77, 0.04);
      z-index: 10;
      overflow-y: auto;
    }

    .login-header-group {
      margin-bottom: 24px;
    }

    .login-badge {
      display: inline-block;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: var(--color-secondary, #2F6F73);
      background: var(--color-surface-subtle, #F0F3F4);
      padding: 4px 8px;
      border-radius: 4px;
      margin-bottom: 12px;
      font-family: var(--font-primary);
    }

    .login-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--color-primary, #17324D);
      margin: 0 0 8px 0;
      line-height: 1.3;
      font-family: var(--font-primary);
    }

    .login-subtitle {
      font-size: 12px;
      color: var(--color-text-secondary, #66737D);
      margin: 0;
      line-height: 1.5;
      font-family: var(--font-secondary);
    }

    .form-group {
      margin-bottom: 18px;
    }

    .form-label {
      display: block;
      font-size: 11px;
      font-weight: 500;
      margin-bottom: 6px;
      color: var(--color-text, #24313A);
      font-family: var(--font-primary);
    }

    .form-control {
      width: 100%;
      height: 42px;
      padding: 8px 12px;
      font-size: 12px;
      font-family: var(--font-secondary);
      background: var(--color-surface, #FFFFFF);
      border: 1px solid var(--color-border, #D9E0E3);
      border-radius: 6px;
      color: var(--color-text, #24313A);
      box-sizing: border-box;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--color-primary, #17324D);
      box-shadow: 0 0 0 2px rgba(23, 50, 77, 0.1);
    }

    .btn-submit {
      width: 100%;
      height: 42px;
      background: var(--color-primary, #17324D);
      color: #FFFFFF;
      border: none;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      font-family: var(--font-primary);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s ease;
      margin-top: 24px;
    }

    .btn-submit:hover {
      background: var(--color-primary-light, #214364);
    }

    .btn-submit:active {
      background: var(--color-primary-dark, #102336);
    }

    .login-footer-info {
      margin-top: 26px;
      padding-top: 18px;
      border-top: 1px solid var(--color-border-light, #E7EDF0);
      font-size: 11px;
      color: var(--color-text-muted, #8A96A0);
      text-align: center;
      line-height: 1.5;
      font-family: var(--font-secondary);
    }

    /* Responsive */
    @media (max-width: 860px) {
      .login-wrapper {
        flex-direction: column;
        overflow-y: auto;
      }
      .map-pane {
        height: 380px;
        min-height: 380px;
        flex: none;
      }
      .login-pane {
        width: 100%;
        min-width: 100%;
        max-width: 100%;
        height: auto;
        padding: 32px 24px;
        border-left: none;
        border-top: 1px solid var(--color-border, #D9E0E3);
      }
    }
  </style>
</head>
<body>

<div class="login-wrapper">

  <!-- ===================================================================== -->
  <!-- COLUMN 1: OPENSTREETMAP (DESIGNATED EVACUATION CENTERS ONLY)          -->
  <!-- ===================================================================== -->
  <div class="map-pane">
    <div class="map-header-badge">
      <span class="status-pulse"></span>
      <span>Iligan City &bull; Designated Evacuation Centers</span>
    </div>

    <!-- Map Controls Group (Top Right) -->
    <div class="map-top-controls">
      <button class="map-control-btn" onclick="fitAllCenters()" title="View all evacuation shelters across Iligan City">
        Fit All Shelters
      </button>

      <button class="map-control-btn" onclick="resetMapView()" title="Reset to Iligan City center">
        Center City
      </button>
    </div>

    <div id="map"></div>

    <div class="map-legend">
      <div class="map-legend-title">
        <span>Designated Evacuation Shelters</span>
        <span style="color:var(--color-secondary);font-weight:600;"><?= count($evacCenters) ?> Shelters Tracked</span>
      </div>
      <div class="map-legend-row">
        <span class="map-legend-item"><span class="legend-dot status-open"></span> Open / Active</span>
        <span class="map-legend-item"><span class="legend-dot status-standby"></span> Standby</span>
        <span class="map-legend-item"><span class="legend-dot status-capacity"></span> At Capacity</span>
        <span class="map-legend-item"><span class="legend-dot status-closed"></span> Closed</span>
      </div>
    </div>
  </div>

  <!-- ===================================================================== -->
  <!-- COLUMN 2: LOGIN BOX                                                  -->
  <!-- ===================================================================== -->
  <div class="login-pane">
    <div>
      <div class="login-header-group">
        <span class="login-badge">ICDRRMO &bull; City Government of Iligan</span>
        <h1 class="login-title">Disaster Resource Allocation Recommender</h1>
        <p class="login-subtitle">
          Official decision-support and emergency resource recommender portal for all 44 barangays of Iligan City and municipal emergency responders.
        </p>
      </div>

      <?php if ($errorMessage): ?>
        <div style="background-color:var(--color-danger-bg, #FDE8E8);border:1px solid var(--color-danger-border, #F8B4B4);color:var(--color-danger, #C62828);padding:10px 12px;border-radius:6px;font-size:11px;margin-bottom:18px;font-family:var(--font-secondary);">
          <?= clean($errorMessage) ?>
        </div>
      <?php endif; ?>

      <form action="<?= BASE_URL ?>/backend/functions/auth/login.php" method="POST">
        <input type="hidden" name="action" value="login">

        <div class="form-group">
          <label class="form-label" for="username">Username or Email</label>
          <input type="text" class="form-control" id="username" name="username" placeholder="e.g. icdrrmo_admin or brgy_hinaplanon" required autofocus>
        </div>

        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label" for="password" style="margin-bottom:0;">Password</label>
            <a href="javascript:void(0)" onclick="togglePasswordVisibility()" style="font-size:10px;color:var(--color-secondary, #2F6F73);text-decoration:none;font-weight:600;font-family:var(--font-primary);">Show/Hide</a>
          </div>
          <input type="password" class="form-control" id="password" name="password" placeholder="Enter your account password" required>
        </div>

        <button type="submit" class="btn-submit">
          Sign In to Portal
        </button>
      </form>
    </div>

    <div class="login-footer-info">
      <strong>Iligan City Disaster Risk Reduction & Management Office</strong><br>
      Republic of the Philippines &bull; RA 10121 Compliance<br>
      Emergency Hotline: <strong>161</strong> or <strong>(063) 221-1234</strong>
    </div>
  </div>

</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
// Evacuation Centers data injected directly from database
const evacCentersData = <?= json_encode($evacCenters) ?>;

// Iligan City Coordinates, Mindanao, Philippines
const ILIGAN_CENTER = [8.2280, 124.2452];
const DEFAULT_ZOOM = 12;

let map;
let evacMarkerGroup;

document.addEventListener('DOMContentLoaded', function() {
  // 1. Initialize OpenStreetMap centered on Iligan City
  map = L.map('map', {
    zoomControl: true
  }).setView(ILIGAN_CENTER, DEFAULT_ZOOM);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors'
  }).addTo(map);

  evacMarkerGroup = L.featureGroup().addTo(map);

  // 2. Plot ONLY Evacuation Centers
  evacCentersData.forEach(ec => {
    if (ec.coordinates_lat && ec.coordinates_lng) {
      const lat = parseFloat(ec.coordinates_lat);
      const lng = parseFloat(ec.coordinates_lng);

      // Determine styling class based on shelter status using palette tokens
      let statusClass = 'status-standby';
      if (ec.status === 'Open / Active') {
        statusClass = 'status-open';
      } else if (ec.status === 'At Capacity') {
        statusClass = 'status-capacity';
      } else if (ec.status === 'Closed') {
        statusClass = 'status-closed';
      }

      // Custom Evacuation Marker without emoji icons
      const displayName = ec.name.length > 24 ? ec.name.substring(0, 22) + '...' : ec.name;
      const evacIcon = L.divIcon({
        className: 'custom-evac-marker',
        html: `
          <div class="evac-pin-badge ${statusClass}">
            <span>${escapeHtml(displayName)}</span>
          </div>
        `,
        iconSize: [140, 26],
        iconAnchor: [70, 13]
      });

      const marker = L.marker([lat, lng], { icon: evacIcon }).addTo(evacMarkerGroup);

      // Hover Tooltip Box
      marker.bindTooltip(generateEvacInfoHtml(ec, false), {
        direction: 'auto',
        offset: [0, -12],
        className: 'evac-hover-tooltip',
        opacity: 1
      });

      // Interactive Click Popup
      marker.bindPopup(generateEvacInfoHtml(ec, true), {
        maxWidth: 340,
        className: 'evac-click-popup'
      });
    }
  });

  setTimeout(() => {
    map.invalidateSize();
  }, 250);
});

// Generate clean HTML for hover tooltip and click popup strictly adhering to the palette
function generateEvacInfoHtml(ec, isPopup = false) {
  let statusBg = 'var(--color-secondary, #2F6F73)';

  if (ec.status === 'Open / Active') {
    statusBg = 'var(--color-success, #2E7D32)';
  } else if (ec.status === 'At Capacity') {
    statusBg = 'var(--color-danger, #C62828)';
  } else if (ec.status === 'Closed') {
    statusBg = 'var(--color-text-muted, #8A96A0)';
  }

  const capacityInd = parseInt(ec.capacity_individuals) || 1;
  const currentEvac = parseInt(ec.current_evacuees_count) || 0;
  const remainingInd = ec.remaining_capacity_individuals !== undefined ? parseInt(ec.remaining_capacity_individuals) : Math.max(0, capacityInd - currentEvac);
  const capacityFam = parseInt(ec.capacity_families) || 1;
  const remainingFam = ec.remaining_capacity_families !== undefined ? parseInt(ec.remaining_capacity_families) : Math.max(0, Math.round(capacityFam * (remainingInd / capacityInd)));
  const occupancyPct = ec.occupancy_percentage !== undefined ? parseInt(ec.occupancy_percentage) : Math.min(100, Math.round((currentEvac / capacityInd) * 100));
  const remainingPct = ec.remaining_percentage !== undefined ? parseInt(ec.remaining_percentage) : Math.max(0, 100 - occupancyPct);

  const isFull = (remainingInd === 0 || ec.status === 'At Capacity');

  // Format resource list cleanly without emojis
  const resourcesList = Array.isArray(ec.resources) ? ec.resources : [];
  let resItemsHtml = '';

  if (resourcesList.length > 0) {
    const displayResources = resourcesList.slice(0, 8);
    resItemsHtml = displayResources.map(r => {
      let label = r.resource_name;
      const lower = label.toLowerCase();
      if (lower.includes('food') || lower.includes('ration') || lower.includes('meal')) {
        label = 'Food Packs';
      } else if (lower.includes('water')) {
        label = 'Drinking Water';
      } else if (lower.includes('hygiene') || lower.includes('sanitation')) {
        label = 'Hygiene Kits';
      } else if (lower.includes('tent') || lower.includes('shelter') || lower.includes('modular')) {
        label = 'Modular Tents';
      } else if (lower.includes('medicine') || lower.includes('dispensing')) {
        label = 'Medicine Packs';
      } else if (lower.includes('first aid') || lower.includes('trauma') || lower.includes('medical')) {
        label = 'First Aid Kits';
      } else if (lower.includes('life vest') || lower.includes('boat') || lower.includes('flotation') || lower.includes('pfd')) {
        label = 'Life Vests';
      } else if (lower.includes('generator') || lower.includes('power')) {
        label = 'Generator';
      } else if (lower.includes('searchlight') || lower.includes('floodlight')) {
        label = 'Floodlights';
      }

      return `
        <div class="evac-resource-card">
          <div class="evac-resource-name" title="${escapeHtml(r.resource_name)}">${escapeHtml(label)}</div>
          <div class="evac-resource-qty">${Number(r.quantity).toLocaleString()} <span style="font-size:8.5px;font-weight:normal;color:var(--color-text-muted);">${escapeHtml(r.unit)}</span></div>
        </div>
      `;
    }).join('');
  } else {
    resItemsHtml = `
      <div style="grid-column:1/-1;background:var(--color-surface-subtle);border:1px dashed var(--color-border);border-radius:4px;padding:6px;text-align:center;color:var(--color-text-secondary);font-size:9.5px;font-family:var(--font-secondary);">
        Central Depot reserves on standby
      </div>
    `;
  }

  const lat = parseFloat(ec.coordinates_lat || 0);
  const lng = parseFloat(ec.coordinates_lng || 0);

  return `
    <div class="evac-card-wrapper">
      <!-- Header -->
      <div class="evac-card-header">
        <div class="evac-card-header-top">
          <span class="evac-badge-type">${escapeHtml(ec.center_type || 'Shelter')}</span>
          <span class="evac-badge-status" style="background:${statusBg};">${escapeHtml(ec.status)}</span>
        </div>
        <div class="evac-card-title">${escapeHtml(ec.name)}</div>
        <div class="evac-card-location">
          <span>Brgy. ${escapeHtml(ec.barangay_name)} &bull; ${escapeHtml(ec.location_address || 'Designated Area')}</span>
        </div>
      </div>

      <!-- Card Body -->
      <div class="evac-card-body">
        <!-- Capacity & Remaining Space Box -->
        <div class="evac-capacity-box">
          <div class="evac-capacity-header">
            <span class="evac-capacity-highlight ${isFull ? 'at-capacity' : 'has-space'}">
              ${isFull ? 'At Capacity (0 Available)' : `${remainingInd.toLocaleString()} Spaces Available`}
            </span>
            <span class="evac-capacity-pill ${isFull ? 'pill-red' : 'pill-green'}">
              ${isFull ? 'FULL' : `${remainingPct}% Capacity Left`}
            </span>
          </div>

          <div class="evac-capacity-bar-wrap">
            <div class="evac-capacity-bar-occupied ${isFull ? 'full' : ''}" style="width:${occupancyPct}%;" title="Sheltered: ${currentEvac}"></div>
          </div>

          <div class="evac-capacity-subtext">
            <span>Sheltered: <b>${currentEvac.toLocaleString()}</b> / ${capacityInd.toLocaleString()}</span>
            <span>Families: <b>~${remainingFam.toLocaleString()}</b> / ${capacityFam.toLocaleString()}</span>
          </div>
        </div>

        <!-- Remaining Resources & Available Supplies -->
        <div>
          <div class="evac-resources-header">
            <span class="evac-resources-title">Remaining Supplies</span>
            <span class="evac-resources-tag">On-Site</span>
          </div>
          <div class="evac-resources-grid">
            ${resItemsHtml}
          </div>
        </div>

        <!-- Facilities & Utilities -->
        <div class="evac-amenities-grid">
          <div class="evac-amenity-chip ${ec.has_potable_water == 1 ? 'active' : 'inactive'}">
            Water: <b>${ec.has_potable_water == 1 ? 'Available' : 'None'}</b>
          </div>
          <div class="evac-amenity-chip ${ec.has_electricity == 1 ? 'active' : 'inactive'}">
            Power: <b>${ec.has_electricity == 1 ? 'Active' : 'None'}</b>
          </div>
          <div class="evac-amenity-chip ${ec.has_medical_station == 1 ? 'active' : 'inactive'}">
            Clinic: <b>${ec.has_medical_station == 1 ? 'On-site' : 'None'}</b>
          </div>
          <div class="evac-amenity-chip active" title="${escapeHtml(ec.accessibility || 'Accessible')}">
            Access: <b>${escapeHtml(ec.accessibility ? (ec.accessibility.length > 15 ? ec.accessibility.substring(0, 13) + '...' : ec.accessibility) : 'Accessible')}</b>
          </div>
        </div>

        <!-- Contact & Actions -->
        <div class="evac-focal-card">
          <div>
            <span style="color:var(--color-text-muted);">Focal:</span> <b>${escapeHtml(ec.contact_officer || 'Evacuation Officer')}</b>
          </div>
          ${ec.contact_number ? (isPopup ? `
            <a href="tel:${escapeHtml(ec.contact_number)}" class="evac-call-link">
              Call ${escapeHtml(ec.contact_number)}
            </a>
          ` : `
            <span style="color:var(--color-secondary);font-weight:600;">${escapeHtml(ec.contact_number)}</span>
          `) : ''}
        </div>

        ${isPopup ? `
          <div style="color:var(--color-text-muted);font-size:9px;text-align:right;margin-top:2px;font-family:var(--font-secondary);">
            GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}
          </div>
        ` : ''}
      </div>
    </div>
  `;
}

// Helper to escape HTML characters safely
function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Fit map view to encompass all evacuation shelters
function fitAllCenters() {
  if (evacMarkerGroup && evacMarkerGroup.getLayers().length > 0) {
    map.fitBounds(evacMarkerGroup.getBounds().pad(0.12), {
      duration: 1.0
    });
  } else {
    resetMapView();
  }
}

// Reset map view to default Iligan City coordinates
function resetMapView() {
  if (map) {
    map.flyTo(ILIGAN_CENTER, DEFAULT_ZOOM, {
      duration: 1.0
    });
  }
}

// Show/Hide password toggle
function togglePasswordVisibility() {
  const pwd = document.getElementById('password');
  pwd.type = pwd.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>

