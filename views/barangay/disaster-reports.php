<?php
// ============================================================================
// Views (Barangay Head): Disaster Incident Reports & Assistance Monitoring
// Features: Full CRUD with in-place View/Edit mode (styled like Manage Residents),
// Automatic "Ongoing" status on submission until the Barangay Head concludes/ends it,
// Single "View" action button, KPI telemetry metrics, and status progression.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
  die("Barangay jurisdiction not found or unassigned.");
}

$brgyLat = !empty($barangay['coordinates_lat']) ? (float) $barangay['coordinates_lat'] : 8.246596;
$brgyLng = !empty($barangay['coordinates_lng']) ? (float) $barangay['coordinates_lng'] : 124.259474;

$viewTab = $_GET['tab'] ?? 'all';
if (!in_array($viewTab, ['all', 'ongoing', 'completed'], true)) {
  $viewTab = 'all';
}

$filterType = trim($_GET['type'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// Counts for navigation tabs & KPI cards
$countAllStmt = $db->prepare("SELECT COUNT(*) FROM disaster_reports WHERE barangay_id = ?");
$countAllStmt->execute([$barangayId]);
$countAll = (int) $countAllStmt->fetchColumn();

$countOngoingStmt = $db->prepare("SELECT COUNT(*) FROM disaster_reports WHERE barangay_id = ? AND status = 'Ongoing'");
$countOngoingStmt->execute([$barangayId]);
$countOngoing = (int) $countOngoingStmt->fetchColumn();

$countCompletedStmt = $db->prepare("SELECT COUNT(*) FROM disaster_reports WHERE barangay_id = ? AND status = 'Completed'");
$countCompletedStmt->execute([$barangayId]);
$countCompleted = (int) $countCompletedStmt->fetchColumn();

// Telemetry aggregates
$statsStmt = $db->prepare("
    SELECT 
        COALESCE(SUM(affected_families), 0) AS total_affected_families,
        COALESCE(SUM(affected_individuals), 0) AS total_affected_individuals,
        COALESCE(SUM(displaced_families), 0) AS total_displaced_families,
        COALESCE(SUM(casualties_count), 0) AS total_casualties,
        COALESCE(SUM(injuries_count), 0) AS total_injuries,
        COALESCE(SUM(missing_count), 0) AS total_missing
    FROM disaster_reports 
    WHERE barangay_id = ?
");
$statsStmt->execute([$barangayId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Build query
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.status AS recommendation_status, r.confidence_score
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    LEFT JOIN users u ON dr.submitted_by = u.id
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id
    WHERE dr.barangay_id = ?
";
$params = [$barangayId];

if ($viewTab === 'ongoing') {
  $sql .= " AND dr.status = 'Ongoing'";
} elseif ($viewTab === 'completed') {
  $sql .= " AND dr.status = 'Completed'";
}

if (!empty($filterStatus)) {
  $sql .= " AND dr.status = ?";
  $params[] = $filterStatus;
}

if (!empty($filterType)) {
  $sql .= " AND dr.disaster_type = ?";
  $params[] = $filterType;
}

if (!empty($filterSeverity)) {
  $sql .= " AND dr.severity = ?";
  $params[] = $filterSeverity;
}

if (!empty($search)) {
  $sql .= " AND (dr.tracking_code LIKE ? OR dr.purok_name LIKE ? OR dr.requested_assistance LIKE ? OR dr.situation_overview LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY FIELD(dr.status, 'Ongoing', 'Completed'), dr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 1. Fetch Puroks created for this specific Barangay
$purokStmt = $db->prepare("
    SELECT id, name, hazard_types, risk_level 
    FROM puroks 
    WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL) 
    ORDER BY name ASC
");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Also include any custom clusters registered for this barangay in purok_cluster_options
$clusterStmt = $db->prepare("
    SELECT id, name, '' AS hazard_types, 'Moderate' AS risk_level 
    FROM purok_cluster_options 
    WHERE barangay_id = ? 
    ORDER BY name ASC
");
$clusterStmt->execute([$barangayId]);
$customClusters = $clusterStmt->fetchAll(PDO::FETCH_ASSOC);
$purokNamesMap = array_column($localPuroks, 'name');
foreach ($customClusters as $cc) {
  if (!in_array($cc['name'], $purokNamesMap, true)) {
    $localPuroks[] = $cc;
    $purokNamesMap[] = $cc['name'];
  }
}

// 2. Dynamically load Hazard Types specific to this Barangay and its Puroks
$hazOptionsStmt = $db->prepare("
  SELECT id, purok_id, barangay_id, name 
  FROM hazard_type_options 
  WHERE barangay_id = ? OR purok_id IN (SELECT id FROM puroks WHERE barangay_id = ?)
  ORDER BY name ASC
");
$hazOptionsStmt->execute([$barangayId, $barangayId]);
$allHazardOptions = $hazOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Map purok_id -> list of hazard names, and barangay-wide hazard list
$purokSpecificHazards = [];
$dynamicHazardTypes = [];

foreach ($allHazardOptions as $ho) {
  $hName = trim($ho['name']);
  if ($hName === '') continue;

  if (!in_array($hName, $dynamicHazardTypes, true)) {
    $dynamicHazardTypes[] = $hName;
  }

  if (!empty($ho['purok_id'])) {
    $pid = (int) $ho['purok_id'];
    if (!isset($purokSpecificHazards[$pid])) {
      $purokSpecificHazards[$pid] = [];
    }
    if (!in_array($hName, $purokSpecificHazards[$pid], true)) {
      $purokSpecificHazards[$pid][] = $hName;
    }
  }
}

// Include any distinct disaster types from existing disaster reports in this barangay
$existingTypesStmt = $db->prepare("
  SELECT DISTINCT disaster_type 
  FROM disaster_reports 
  WHERE barangay_id = ? AND disaster_type IS NOT NULL AND TRIM(disaster_type) != ''
");
$existingTypesStmt->execute([$barangayId]);
if ($existingTypesStmt) {
  foreach ($existingTypesStmt->fetchAll(PDO::FETCH_COLUMN) as $et) {
    $etTrim = trim($et);
    if ($etTrim !== '' && !in_array($etTrim, $dynamicHazardTypes, true)) {
      $dynamicHazardTypes[] = $etTrim;
    }
  }
}

natcasesort($dynamicHazardTypes);
$dynamicHazardTypes = array_values($dynamicHazardTypes);

// Mapping of purok name -> primary hazard (if any)
$purokHazardMap = [];
foreach ($localPuroks as $lp) {
  $purokHazardMap[$lp['name']] = !empty($lp['hazard_types']) ? $lp['hazard_types'] : '';
}

$pageTitle = "Disaster Reports — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<!-- Leaflet GIS Mapping Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
  /* Leaflet Maps & Pinpoint Tool Styling */
  .incident-map-picker {
    width: 100%;
    height: 250px;
    border-radius: var(--radius-md, 8px);
    border: 1px solid var(--color-border);
    position: relative;
    z-index: 1;
  }

  .incident-overview-map {
    width: 100%;
    height: 360px;
    border-radius: 0;
    position: relative;
    z-index: 1;
  }

  .map-tile-btn {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #E2E8F0);
    color: var(--color-text-secondary, #64748B);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
  }

  .map-tile-btn.active {
    background: var(--color-primary, #0B2545);
    border-color: var(--color-primary, #0B2545);
    color: #FFFFFF;
  }

  /* Custom Pulsing Hazard Markers */
  .hazard-div-icon {
    background: transparent !important;
    border: none !important;
  }

  .custom-hazard-marker {
    width: 32px;
    height: 32px;
    border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #FFFFFF;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
    position: relative;
    cursor: pointer;
    transition: transform 0.15s ease;
  }

  .custom-hazard-marker:hover {
    transform: rotate(-45deg) scale(1.15);
  }

  .custom-hazard-marker-inner {
    transform: rotate(45deg);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .marker-pulse-critical::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: inherit;
    background: rgba(239, 68, 68, 0.4);
    animation: marker-pulse-ring 1.8s infinite;
    z-index: -1;
  }

  .marker-pulse-high::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: inherit;
    background: rgba(245, 158, 11, 0.4);
    animation: marker-pulse-ring 1.8s infinite;
    z-index: -1;
  }

  @keyframes marker-pulse-ring {
    0% {
      transform: scale(1);
      opacity: 0.8;
    }

    100% {
      transform: scale(2.2);
      opacity: 0;
    }
  }

  /* Leaflet Popup Styling */
  .leaflet-popup-content-wrapper {
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
    padding: 2px;
  }

  .leaflet-popup-content {
    margin: 10px 12px;
    line-height: 1.4;
    font-family: inherit;
  }

  /* High-clarity disabled inputs for View Mode (matching manage residents) */
  .form-control:disabled,
  select.form-control:disabled,
  textarea.form-control:disabled {
    background-color: var(--color-surface-subtle, #F0F3F4) !important;
    color: var(--color-text, #24313A) !important;
    border-color: var(--color-border-light, #E7EDF0) !important;
    cursor: default !important;
    opacity: 0.96 !important;
    -webkit-text-fill-color: var(--color-text, #24313A) !important;
  }

  .kpi-stat-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #E2E8F0);
    border-radius: var(--radius-md, 8px);
    padding: var(--space-4, 16px);
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .kpi-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
  }

  .kpi-stat-card.ongoing-card,
  .kpi-stat-card.completed-card,
  .kpi-stat-card.displaced-card {
    border-left: 1px solid var(--color-border, #E2E8F0);
  }

  /* Pulsing badge indicator for Ongoing */
  .badge-ongoing-pulse {
    background: rgba(245, 158, 11, 0.15);
    color: #B45309;
    border: 1px solid rgba(245, 158, 11, 0.35);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 10.5px;
  }

  .badge-ongoing-pulse::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #F59E0B;
    box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
    animation: pulse-ring 1.8s infinite;
  }

  @keyframes pulse-ring {
    0% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
    }

    70% {
      transform: scale(1.1);
      box-shadow: 0 0 0 6px rgba(245, 158, 11, 0);
    }

    100% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0);
    }
  }

  .report-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary, #64748B);
    border-bottom: 2px solid transparent;
    text-decoration: none;
    transition: all 0.15s ease;
  }

  .report-tab-link:hover {
    color: var(--color-primary, #0B2545);
  }

  .report-tab-link.active {
    color: var(--color-primary, #0B2545);
    border-bottom-color: var(--color-primary, #0B2545);
  }
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Disaster Incident Reports — Barangay <?= clean($barangay['name']) ?></h1>
      <p class="page-header-desc">
        Record local disaster incidents, assess displaced residents, and track real-time operational status
        (automatically marked <strong>Ongoing</strong> until concluded).
      </p>
    </div>
    <div class="page-header-actions" style="display:flex;gap:8px;align-items:center;">
      <button type="button" class="btn btn-outline" id="btnToggleOverviewMap" onclick="toggleOverviewMapCard()"
        style="display:inline-flex;align-items:center;gap:6px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
          <line x1="8" y1="2" x2="8" y2="18"></line>
          <line x1="16" y1="6" x2="16" y2="22"></line>
        </svg>
        <span id="btnToggleOverviewMapText">Spatial Incident Map</span>
      </button>
      <button type="button" class="btn btn-primary" onclick="openCreateReportModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        File Incident Report
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Cards -->
  <div
    style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:var(--space-3, 12px);margin-bottom:var(--space-4, 16px);">
    <div class="kpi-stat-card">
      <div
        style="font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;">
        Total Filed Reports</div>
      <div style="font-size:22px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">
        <?= number_format($countAll) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Barangay jurisdiction registry</div>
    </div>
    <div class="kpi-stat-card ongoing-card">
      <div
        style="font-size:10px;font-weight:700;color:#B45309;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:4px;">
        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#F59E0B;"></span> Active /
        Ongoing
      </div>
      <div style="font-size:22px;font-weight:700;color:#B45309;font-family:var(--font-secondary);">
        <?= number_format($countOngoing) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Active incidents requiring response</div>
    </div>
    <div class="kpi-stat-card completed-card">
      <div style="font-size:10px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:0.5px;">Concluded
        / Completed</div>
      <div style="font-size:22px;font-weight:700;color:#047857;font-family:var(--font-secondary);">
        <?= number_format($countCompleted) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Ended response operations</div>
    </div>
    <div class="kpi-stat-card displaced-card">
      <div style="font-size:10px;font-weight:700;color:#B91C1C;text-transform:uppercase;letter-spacing:0.5px;">Displaced
        Families</div>
      <div style="font-size:22px;font-weight:700;color:#B91C1C;font-family:var(--font-secondary);">
        <?= number_format($stats['total_displaced_families']) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Across
        <?= number_format($stats['total_affected_individuals']) ?> affected residents
      </div>
    </div>
  </div>

  <!-- Interactive Incident Spatial Map Overview (Leaflet) -->
  <div class="card" id="spatialIncidentMapCard"
    style="margin-bottom:var(--space-4, 16px);border:1px solid var(--color-border);overflow:hidden;">
    <div class="card-header"
      style="padding:10px 14px;background:var(--color-surface-subtle);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <span
          style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;background:rgba(11,37,69,0.08);color:var(--color-primary);">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
            <line x1="8" y1="2" x2="8" y2="18"></line>
            <line x1="16" y1="6" x2="16" y2="22"></line>
          </svg>
        </span>
        <div>
          <h3
            style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
            Barangay <?= clean($barangay['name']) ?> Incident Spatial Pinpoint Map
          </h3>
          <span style="font-size:10px;color:var(--color-text-muted);">
            Geospatial distribution of active disaster incidents with Leaflet Pinpoint & 360° Street View inspection
          </span>
        </div>
      </div>
      <!-- Map Controls & Layer Selector -->
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <div class="btn-group"
          style="display:inline-flex;border-radius:4px;overflow:hidden;border:1px solid var(--color-border);">
          <button type="button" class="btn btn-sm map-tile-btn active" id="btnTileOsmOverview"
            onclick="switchMapTileLayer('overview', 'osm')" style="padding:3px 9px;font-size:10px;">🗺️ Streets</button>
          <button type="button" class="btn btn-sm map-tile-btn" id="btnTileSatOverview"
            onclick="switchMapTileLayer('overview', 'satellite')" style="padding:3px 9px;font-size:10px;">🛰️
            Satellite</button>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="recenterOverviewMap()"
          style="padding:3px 9px;font-size:10px;">
          🎯 Fit All Incidents
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="toggleOverviewMapCard()"
          style="padding:3px 9px;font-size:10px;">
          Minimize Map
        </button>
      </div>
    </div>
    <div id="overviewIncidentMapContainer" style="height:360px;width:100%;position:relative;background:#E2E8F0;">
      <!-- Leaflet map -->
      <div id="overviewIncidentMap" class="incident-overview-map"></div>
      <!-- Map Incident Counter Legend Overlay -->
      <div
        style="position:absolute;bottom:10px;left:10px;z-index:999;background:rgba(255,255,255,0.92);backdrop-filter:blur(4px);padding:6px 12px;border-radius:6px;border:1px solid rgba(0,0,0,0.1);font-size:10px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 6px rgba(0,0,0,0.08);">
        <div style="display:flex;align-items:center;gap:4px;"><span
            style="width:8px;height:8px;border-radius:50%;background:#EF4444;display:inline-block;"></span>
          <strong>Critical</strong>
        </div>
        <div style="display:flex;align-items:center;gap:4px;"><span
            style="width:8px;height:8px;border-radius:50%;background:#F59E0B;display:inline-block;"></span>
          <strong>High</strong>
        </div>
        <div style="display:flex;align-items:center;gap:4px;"><span
            style="width:8px;height:8px;border-radius:50%;background:#3B82F6;display:inline-block;"></span>
          <strong>Moderate</strong>
        </div>
        <div style="display:flex;align-items:center;gap:4px;"><span
            style="width:8px;height:8px;border-radius:50%;background:#10B981;display:inline-block;"></span>
          <strong>Completed</strong>
        </div>
      </div>
    </div>
  </div>

  <!-- Navigation Tabs (Matching style in Manage Residents) -->
  <div
    style="display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--color-border);margin-bottom:var(--space-3, 12px);">
    <a href="?tab=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'all' ? 'active' : '' ?>">
      All Incident Reports
      <span class="badge badge-neutral" style="font-size:9.5px;padding:2px 6px;"><?= $countAll ?></span>
    </a>
    <a href="?tab=ongoing<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'ongoing' ? 'active' : '' ?>">
      Ongoing Incidents
      <span class="badge badge-warning" style="font-size:9.5px;padding:2px 6px;"><?= $countOngoing ?></span>
    </a>
    <a href="?tab=completed<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'completed' ? 'active' : '' ?>">
      Completed / Concluded
      <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;"><?= $countCompleted ?></span>
    </a>
  </div>

  <!-- Filter & Search Bar -->
  <div class="card" style="margin-bottom: var(--space-4, 16px);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar"
        style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">
        <div class="filter-group" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
          <div class="search-input-wrap" style="min-width:240px;flex:1;">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" name="search" class="form-control"
              placeholder="Search tracking #, purok, disaster type..." value="<?= clean($search) ?>">
          </div>

          <select name="type" class="form-control" style="width:140px;">
            <option value="">All Hazards</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>" <?= $filterType === $ht ? 'selected' : '' ?>><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="severity" class="form-control" style="width:130px;">
            <option value="">All Severity</option>
            <option value="Critical" <?= $filterSeverity === 'Critical' ? 'selected' : '' ?>>Critical</option>
            <option value="High" <?= $filterSeverity === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Moderate" <?= $filterSeverity === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
            <option value="Low" <?= $filterSeverity === 'Low' ? 'selected' : '' ?>>Low</option>
          </select>

          <?php if ($viewTab === 'all'): ?>
            <select name="status" class="form-control" style="width:140px;">
              <option value="">All Statuses</option>
              <option value="Ongoing" <?= $filterStatus === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
              <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline" style="padding:6px 12px;">Filter</button>
          <?php if (!empty($search) || !empty($filterType) || !empty($filterSeverity) || !empty($filterStatus)): ?>
            <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline"
              style="color:var(--color-text-muted);padding:6px 12px;">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10.5px;color:var(--color-text-muted);font-weight:600;">
            Showing <?= count($requests) ?> incident(s)
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Incident Reports Table -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Incident # & Hazard</th>
          <th>Purok Cluster</th>
          <th>Severity / Urgency</th>
          <th>Aid Support</th>
          <th>Impact & Displaced</th>
          <th>Status</th>
          <th>Date Filed</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:36px;color:var(--color-text-muted);">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                style="margin:0 auto 8px;display:block;opacity:0.6;">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              </svg>
              No disaster reports found for the selected criteria in Barangay <?= clean($barangay['name']) ?>.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <!-- Incident # & Hazard -->
              <td style="font-family:var(--font-secondary);">
                <div style="font-weight:700;color:var(--color-primary);font-size:12px;">
                  <?= clean($r['tracking_code']) ?>
                </div>
                <div style="display:inline-flex;align-items:center;gap:4px;margin-top:2px;">
                  <span class="badge badge-info" style="font-size:9px;"><?= clean($r['disaster_type']) ?></span>
                </div>
              </td>

              <!-- Purok Cluster & Pinpoint Location -->
              <td>
                <div style="font-weight:600;color:var(--color-primary);display:flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                  </svg>
                  <?= clean($r['purok_name']) ?>
                </div>
                <?php if (!empty($r['incident_location'])): ?>
                  <div
                    style="font-size:9.5px;color:var(--color-text);margin-top:2px;display:flex;align-items:center;gap:3px;">
                    <span style="color:var(--color-text-muted);">📍</span> <?= clean($r['incident_location']) ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($r['coordinates_lat']) && !empty($r['coordinates_lng'])): ?>
                  <div style="display:inline-flex;align-items:center;gap:4px;margin-top:2px;">
                    <a href="javascript:void(0)"
                      onclick="focusIncidentOnOverviewMap(<?= (float) $r['coordinates_lat'] ?>, <?= (float) $r['coordinates_lng'] ?>, <?= (int) $r['id'] ?>)"
                      class="badge badge-neutral"
                      style="font-size:8.5px;text-decoration:none;font-family:var(--font-secondary);padding:1px 5px;"
                      title="Focus on Leaflet Map">
                      🎯 <?= number_format($r['coordinates_lat'], 4) ?>, <?= number_format($r['coordinates_lng'], 4) ?>
                    </a>
                  </div>
                <?php endif; ?>
                <div style="font-size:9px;color:var(--color-text-muted);margin-top:1px;">
                  Filed by <?= clean($r['submitted_by_name'] ?? 'Barangay Head') ?>
                </div>
              </td>

              <!-- Severity / Urgency -->
              <td>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                  <?= renderStatusBadge($r['severity']) ?>
                  <span style="font-size:9px;color:var(--color-text-muted);">Urgency: <?= clean($r['urgency']) ?></span>
                </div>
              </td>

              <!-- Aid Support -->
              <td>
                <?= renderAidSupportBadge($r['aid_support'] ?? 'resources') ?>
              </td>

              <!-- Impact & Displaced -->
              <td style="font-family:var(--font-secondary);font-size:11px;">
                <div style="font-weight:600;color:var(--color-text);">
                  <?= number_format($r['affected_families']) ?> Fam (<?= number_format($r['affected_individuals']) ?> Ind)
                </div>
                <?php if ((int) $r['displaced_families'] > 0): ?>
                  <div style="font-size:9.5px;color:var(--color-danger);font-weight:600;">
                    • <?= number_format($r['displaced_families']) ?> Displaced
                  </div>
                <?php endif; ?>
                <?php if ((int) $r['casualties_count'] > 0 || (int) $r['injuries_count'] > 0 || (int) $r['missing_count'] > 0): ?>
                  <div style="font-size:8.5px;color:var(--color-text-muted);">
                    <?= (int) $r['casualties_count'] ?> Dead | <?= (int) $r['injuries_count'] ?> Injured |
                    <?= (int) $r['missing_count'] ?> Missing
                  </div>
                <?php endif; ?>
              </td>

              <!-- Status -->
              <td>
                <?php if ($r['status'] === 'Ongoing'): ?>
                  <span class="badge-ongoing-pulse">Ongoing</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-weight:700;">✓ Completed</span>
                  <?php if (!empty($r['completed_at'])): ?>
                    <div style="font-size:8.5px;color:var(--color-text-muted);margin-top:2px;">Ended
                      <?= formatDate($r['completed_at'], 'M d, h:i A') ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>

              <!-- Date Filed -->
              <td
                style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);white-space:nowrap;">
                <?= formatDate($r['created_at'], 'M d, Y') ?>
                <div style="font-size:8.5px;color:var(--color-text-muted);"><?= formatDate($r['created_at'], 'h:i A') ?>
                </div>
              </td>

              <!-- Action: View & Street View -->
              <td style="text-align:right;">
                <div style="display:inline-flex;gap:4px;align-items:center;">
                  <button type="button" class="btn btn-outline btn-sm"
                    style="padding:3px 9px;font-size:10px;font-weight:600;"
                    onclick="viewReportDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)">
                    View
                  </button>
                  <button type="button" class="btn btn-sm"
                    style="padding:3px 8px;font-size:10px;background:#2563EB;color:#FFF;border-color:#2563EB;display:inline-flex;align-items:center;gap:3px;"
                    onclick="openStreetViewForReport(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)"
                    title="Inspect 360° Street View">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="5" r="3"></circle>
                      <path d="M12 8v8"></path>
                      <path d="M9 21l3-5 3 5"></path>
                      <path d="M6 11l6-2 6 2"></path>
                    </svg>
                    Street View
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- ========================================================================= -->
<!-- Modal 1: File Incident Report (Create New Incident)                        -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createReportModal">
  <form class="modal-dialog modal-dialog-lg" id="createReportForm" onsubmit="handleCreateReport(event)"
    style="max-width:680px;">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge-ongoing-pulse" style="font-size:10px;">Auto-Status: Ongoing</span>
        <h3 class="modal-title" style="margin:0;">File Disaster Incident Report</h3>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createReportModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px;">
      <div
        style="background:rgba(245, 158, 11, 0.08);border:1px solid rgba(245, 158, 11, 0.25);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:11px;color:#92400E;display:flex;align-items:center;gap:8px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="8" x2="12" y2="12"></line>
          <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
        <div>When filed, this report is <strong>automatically set to Ongoing</strong> to activate emergency telemetry
          until you conclude or end the operation.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster Location</label>
          <select name="purok_name" id="create_purok_name" class="form-control" required
            onchange="handlePurokSelectChange(this.value, 'create')">
            <option value="">Select Purok Cluster</option>
            <option value="All Puroks" data-id="all">All Puroks (Barangay-wide)</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>" data-id="<?= (int) $p['id'] ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($localPuroks)): ?>
            <small style="color:var(--color-danger);font-size:9.5px;display:block;margin-top:2px;">No puroks created yet
              for this barangay. Add puroks in Manage Purok.</small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label class="form-label form-label-required" style="margin-bottom:0;">Disaster Hazard Type</label>
            <button type="button" class="btn btn-outline btn-sm" id="btnShowAddHazardCreate"
              onclick="toggleDynamicHazardInput('create', true)"
              style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:2px 8px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
              + Add Hazard Type
            </button>
          </div>
          <select name="disaster_type" id="create_disaster_type" class="form-control" required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>

          <!-- Inline Dynamic Add Box for Create -->
          <div id="dynamicHazardBox_create"
            style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Hazard Type to Database</label>
            <div style="display:flex;gap:6px;">
              <input type="text" id="dynamic_hazard_input_create" class="form-control"
                placeholder="Type hazard (e.g. Flash Flood, Landslide, Fire)" style="font-size:11px;padding:5px 9px;"
                onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicHazard('create');}">
              <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicHazard('create')"
                style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add & Select
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicHazardInput('create', false)"
                style="padding:4px 8px;font-size:10px;">Cancel</button>
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
              New hazard type will be saved in the database and automatically selected.
            </div>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
          <span>Specific Incident Landmark / Street Location</span>
          <button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:1px 6px;"
            onclick="reverseGeocodeIncidentPin('create')" title="Detect street name from pinned map position">
            🔍 Detect Street from Pin
          </button>
        </label>
        <input type="text" name="incident_location" id="create_incident_location" class="form-control"
          placeholder="e.g. Purok 1 Riverbank Dike, near Bridge Footwalk">
      </div>

      <!-- Leaflet Geolocation Pinpointing & Street View Tool -->
      <div class="incident-map-card-wrapper"
        style="margin-bottom:14px;border:1px solid var(--color-border);border-radius:8px;padding:10px 12px;background:var(--color-surface);">
        <div
          style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
          <div
            style="font-size:10.5px;font-weight:700;color:var(--color-primary);text-transform:uppercase;display:flex;align-items:center;gap:5px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            Pinpoint Disaster Incident Site (Leaflet GIS)
          </div>
          <!-- Controls toolbar -->
          <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
            <div class="btn-group"
              style="display:inline-flex;border-radius:4px;overflow:hidden;border:1px solid var(--color-border);">
              <button type="button" class="btn btn-sm map-tile-btn active" id="btnTileOsmCreate"
                onclick="switchMapTileLayer('create', 'osm')" style="padding:2px 7px;font-size:9px;">🗺️
                Streets</button>
              <button type="button" class="btn btn-sm map-tile-btn" id="btnTileSatCreate"
                onclick="switchMapTileLayer('create', 'satellite')" style="padding:2px 7px;font-size:9px;">🛰️
                Satellite</button>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="recenterMapToBarangay('create')"
              style="padding:2px 7px;font-size:9px;" title="Reset pin to Barangay Center">
              🎯 Center Brgy
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="locateUserPosition('create')"
              style="padding:2px 7px;font-size:9px;" title="Use my GPS location">
              📍 My GPS
            </button>
            <button type="button" class="btn btn-sm" onclick="previewStreetViewFromForm('create')"
              style="padding:2px 8px;font-size:9px;background:#2563EB;color:#FFF;border-color:#2563EB;"
              title="Preview Street View at pin">
              🚶 Street View
            </button>
          </div>
        </div>

        <div style="font-size:9.5px;color:var(--color-text-muted);margin-bottom:6px;">
          Click anywhere or drag marker to set exact incident location. Latitude & Longitude are captured automatically.
        </div>

        <div id="createIncidentMap" class="incident-map-picker"></div>

        <div class="form-row" style="margin-top:8px;margin-bottom:0;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:9.5px;margin-bottom:2px;">Captured Latitude</label>
            <input type="number" step="0.000001" name="coordinates_lat" id="create_lat" class="form-control"
              style="font-size:11px;font-family:var(--font-secondary);" value="<?= $brgyLat ?>"
              onchange="handleManualCoordChange('create')">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:9.5px;margin-bottom:2px;">Captured Longitude</label>
            <input type="number" step="0.000001" name="coordinates_lng" id="create_lng" class="form-control"
              style="font-size:11px;font-family:var(--font-secondary);" value="<?= $brgyLng ?>"
              onchange="handleManualCoordChange('create')">
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Aid Support Needed</label>
          <select name="aid_support" id="create_aid_support" class="form-control" required>
            <option value="resources" selected>Physical Relief Resources</option>
            <option value="financial_aid">Emergency Financial Aid (Ayuda)</option>
            <option value="both">Both (Relief &amp; Financial Aid)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Incident Severity</label>
          <select name="severity" class="form-control" required>
            <option value="Moderate">Moderate (Standard Response)</option>
            <option value="High">High (Heightened Threat)</option>
            <option value="Critical">Critical (Severe Emergency / Immediate Danger)</option>
            <option value="Low">Low (Minor Localized Incident)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Response Urgency</label>
          <select name="urgency" class="form-control" required>
            <option value="Immediate">Immediate (Deploy Rescue / Relief ASAP)</option>
            <option value="High">High (Required Within 6 Hours)</option>
            <option value="Medium">Medium (Scheduled Response)</option>
            <option value="Low">Low (Standby Reserve)</option>
          </select>
        </div>
      </div>

      <!-- Impact Telemetry -->
      <div
        style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
        Impact & Population Assessment
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Affected Families</label>
          <input type="number" name="affected_families" class="form-control" min="0" placeholder="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Displaced Families</label>
          <input type="number" name="displaced_families" class="form-control" min="0" placeholder="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Estimated Individuals</label>
          <input type="number" name="affected_individuals" class="form-control" min="0" placeholder="0" value="0">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Casualties (Confirmed)</label>
          <input type="number" name="casualties_count" class="form-control" min="0" placeholder="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Injuries</label>
          <input type="number" name="injuries_count" class="form-control" min="0" placeholder="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Missing Persons</label>
          <input type="number" name="missing_count" class="form-control" min="0" placeholder="0" value="0">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Ground Situation Overview</label>
        <textarea name="situation_overview" class="form-control" rows="2"
          placeholder="Brief narrative: water depth, blocked bridges, trapped households, power outages..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance / Supplies</label>
        <textarea name="requested_assistance" class="form-control" rows="2"
          placeholder="e.g. 100 Family Food Packs, rescue rubber boat, medical team, hygiene kits..."></textarea>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('createReportModal')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmitCreateReport">Submit Ongoing Report</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Incident Report (Styled like Manage Residents) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewReportModal">
  <form class="modal-dialog modal-dialog-lg" id="viewReportForm" onsubmit="handleSaveReportChanges(event)"
    style="max-width:720px;">
    <input type="hidden" name="id" id="view_report_id">
    <input type="hidden" name="status" id="view_report_status_hidden">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
        <span style="font-size:11px;color:var(--color-text-muted);font-family:var(--font-secondary);"
          id="viewModalTrackingHeader"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewReportModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px;">
      <!-- Incident Identity Banner -->
      <div
        style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
          <div id="view_report_title_display"
            style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
          <div id="view_report_meta_sub" style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px;"></div>
        </div>
        <div id="view_status_quick_callout"></div>
      </div>

      <!-- Section Heading -->
      <div
        style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading"
          style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
          </svg>
          Incident Report Details
        </h4>
        <span id="viewModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:600;">(View Only
          Mode)</span>
      </div>

      <!-- Form Inputs (Disabled on View, Enabled on Edit) -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster</label>
          <select name="purok_name" id="view_purok_name" class="form-control" disabled required
            onchange="handlePurokSelectChange(this.value, 'view')">
            <option value="">Select Purok Cluster</option>
            <option value="All Puroks" data-id="all">All Puroks (Barangay-wide)</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>" data-id="<?= (int) $p['id'] ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label class="form-label form-label-required" style="margin-bottom:0;">Hazard Type</label>
            <button type="button" class="btn btn-outline btn-sm" id="btnShowAddHazardView"
              onclick="toggleDynamicHazardInput('view', true)"
              style="display:none;align-items:center;gap:4px;font-size:10px;padding:2px 8px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
              + Add Hazard Type
            </button>
          </div>
          <select name="disaster_type" id="view_disaster_type" class="form-control" disabled required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>

          <!-- Inline Dynamic Add Box for View/Edit -->
          <div id="dynamicHazardBox_view"
            style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Hazard Type to Database</label>
            <div style="display:flex;gap:6px;">
              <input type="text" id="dynamic_hazard_input_view" class="form-control"
                placeholder="Type hazard (e.g. Flash Flood, Landslide, Fire)" style="font-size:11px;padding:5px 9px;"
                onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicHazard('view');}">
              <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicHazard('view')"
                style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add & Select
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicHazardInput('view', false)"
                style="padding:4px 8px;font-size:10px;">Cancel</button>
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
              New hazard type will be saved in the database and automatically selected.
            </div>
          </div>
        </div>
      </div>

      <!-- Incident Geolocation & Street View Section -->
      <div class="incident-map-card-wrapper"
        style="margin:10px 0 14px 0;border:1px solid var(--color-border);border-radius:8px;padding:10px 12px;background:var(--color-surface);">
        <div
          style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
          <div>
            <div
              style="font-size:10.5px;font-weight:700;color:var(--color-primary);text-transform:uppercase;display:flex;align-items:center;gap:5px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
              Incident Pinpoint Location & Street View
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);" id="viewMapInteractiveHint">
              Interactive Leaflet GIS map with Street View coverage and satellite terrain inspection.
            </div>
          </div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
            <div class="btn-group"
              style="display:inline-flex;border-radius:4px;overflow:hidden;border:1px solid var(--color-border);">
              <button type="button" class="btn btn-sm map-tile-btn active" id="btnTileOsmView"
                onclick="switchMapTileLayer('view', 'osm')" style="padding:2px 7px;font-size:9px;">🗺️ Streets</button>
              <button type="button" class="btn btn-sm map-tile-btn" id="btnTileSatView"
                onclick="switchMapTileLayer('view', 'satellite')" style="padding:2px 7px;font-size:9px;">🛰️
                Satellite</button>
            </div>
            <button type="button" class="btn btn-sm" onclick="openStreetViewForCurrentReport()"
              style="padding:2px 9px;font-size:9.5px;background:#2563EB;color:#FFF;border-color:#2563EB;display:inline-flex;align-items:center;gap:3px;"
              title="Inspect 360° Street View">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="5" r="3"></circle>
                <path d="M12 8v8"></path>
                <path d="M9 21l3-5 3 5"></path>
                <path d="M6 11l6-2 6 2"></path>
              </svg>
              🚶 Street View
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="openGoogleNavigationForCurrentReport()"
              style="padding:2px 7px;font-size:9px;display:inline-flex;align-items:center;gap:3px;"
              title="Google Maps Directions">
              🧭 Directions
            </button>
          </div>
        </div>

        <div id="viewIncidentMap" class="incident-map-picker"></div>

        <div class="form-row" style="margin-top:8px;margin-bottom:0;">
          <div class="form-group" style="margin-bottom:0;flex:2;">
            <label class="form-label" style="font-size:9.5px;margin-bottom:2px;">Street / Landmark Location</label>
            <input type="text" name="incident_location" id="view_incident_location" class="form-control"
              style="font-size:11px;" placeholder="Specific street, landmark, or site" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;flex:1;">
            <label class="form-label" style="font-size:9.5px;margin-bottom:2px;">Latitude</label>
            <input type="number" step="0.000001" name="coordinates_lat" id="view_lat" class="form-control"
              style="font-size:11px;font-family:var(--font-secondary);" disabled
              onchange="handleManualCoordChange('view')">
          </div>
          <div class="form-group" style="margin-bottom:0;flex:1;">
            <label class="form-label" style="font-size:9.5px;margin-bottom:2px;">Longitude</label>
            <input type="number" step="0.000001" name="coordinates_lng" id="view_lng" class="form-control"
              style="font-size:11px;font-family:var(--font-secondary);" disabled
              onchange="handleManualCoordChange('view')">
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Aid Support Needed</label>
          <select name="aid_support" id="view_aid_support" class="form-control" disabled required>
            <option value="resources">Physical Relief Resources</option>
            <option value="financial_aid">Emergency Financial Aid (Ayuda)</option>
            <option value="both">Both (Relief &amp; Financial Aid)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Severity Level</label>
          <select name="severity" id="view_severity" class="form-control" disabled required>
            <option value="Critical">Critical</option>
            <option value="High">High</option>
            <option value="Moderate">Moderate</option>
            <option value="Low">Low</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Response Urgency</label>
          <select name="urgency" id="view_urgency" class="form-control" disabled required>
            <option value="Immediate">Immediate</option>
            <option value="High">High</option>
            <option value="Medium">Medium</option>
            <option value="Low">Low</option>
          </select>
        </div>
      </div>

      <!-- Impact & Census Breakdown -->
      <div
        style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:8px;padding:12px;margin:10px 0 14px 0;">
        <div
          style="font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:8px;">
          Population Impact Telemetry</div>
        <div class="form-row" style="margin-bottom:8px;">
          <div class="form-group">
            <label class="form-label">Affected Families</label>
            <input type="number" name="affected_families" id="view_affected_families" class="form-control" min="0"
              disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Displaced Families</label>
            <input type="number" name="displaced_families" id="view_displaced_families" class="form-control" min="0"
              disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Affected Individuals</label>
            <input type="number" name="affected_individuals" id="view_affected_individuals" class="form-control" min="0"
              disabled>
          </div>
        </div>

        <div class="form-row" style="margin-bottom:0;">
          <div class="form-group">
            <label class="form-label">Casualties</label>
            <input type="number" name="casualties_count" id="view_casualties_count" class="form-control" min="0"
              disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Injuries</label>
            <input type="number" name="injuries_count" id="view_injuries_count" class="form-control" min="0" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Missing</label>
            <input type="number" name="missing_count" id="view_missing_count" class="form-control" min="0" disabled>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Ground Situation Overview</label>
        <textarea name="situation_overview" id="view_situation_overview" class="form-control" rows="2"
          disabled></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance</label>
        <textarea name="requested_assistance" id="view_requested_assistance" class="form-control" rows="2"
          disabled></textarea>
      </div>

      <!-- Resolution Section (for Completed or when concluding) -->
      <div id="viewResolutionSection"
        style="margin-top:12px;padding:12px;background:rgba(16, 185, 129, 0.06);border:1px solid rgba(16, 185, 129, 0.25);border-radius:8px;">
        <div
          style="font-size:10.5px;font-weight:700;color:#047857;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:5px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Resolution & Accomplishment Notes
        </div>
        <textarea name="resolution_notes" id="view_resolution_notes" class="form-control" rows="2"
          placeholder="Record incident conclusion summary, relief distribution completion, or recovery notes..."
          disabled></textarea>
        <div id="view_completed_at_timestamp" style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;">
        </div>
      </div>

      <!-- Timestamps Footer -->
      <div
        style="font-size:10px;color:var(--color-text-muted);margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border-light);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
        <div><strong style="color:var(--color-text);">Filed:</strong> <span id="view_created_at_text"></span></div>
        <div><strong style="color:var(--color-text);">Last Updated:</strong> <span id="view_updated_at_text"></span>
        </div>
      </div>
    </div>

    <!-- Modal Footer Actions (Full CRUD & Status Control) -->
    <div class="modal-footer"
      style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left side: Status Transition & Delete -->
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <!-- End / Finish Incident Button -->
        <button type="button" class="btn btn-sm" id="btnFinishIncident"
          style="background:#047857;color:#FFF;border-color:#047857;font-weight:600;"
          onclick="handleFinishIncidentClick()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Finish / End Incident
        </button>

        <!-- Reopen Button (if already completed) -->
        <button type="button" class="btn btn-outline btn-sm" id="btnReopenIncident"
          style="color:#B45309;border-color:#F59E0B;display:none;" onclick="handleReopenIncidentClick()">
          Reopen Incident (Set to Ongoing)
        </button>

        <!-- Delete Button -->
        <button type="button" class="btn btn-outline btn-sm" id="btnDeleteReport"
          style="color:var(--color-danger);border-color:var(--color-danger);" onclick="handleDeleteReportClick()">
          Delete Report
        </button>
      </div>

      <!-- Right side: Edit Toggle & Close -->
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewReportModal')">Close</button>
        <button type="button" class="btn btn-outline btn-sm" id="btnToggleEditMode" onclick="toggleReportEditMode()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 20h9"></path>
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
          </svg>
          Edit Report
        </button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSaveReportChanges" style="display:none;">
          Save Changes
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Dedicated 360° Street View & Ground Imagery Inspector             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="streetViewModal" style="z-index:10001;">
  <div class="modal-dialog modal-dialog-lg" style="max-width:860px;width:95%;">
    <div class="modal-header" style="background:#0F172A;color:#FFFFFF;border-bottom:1px solid #1E293B;">
      <div style="display:flex;align-items:center;gap:8px;">
        <span
          style="background:#2563EB;color:#FFF;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:4px;">
          🚶 Street View 360°
        </span>
        <h3 class="modal-title" id="streetViewModalTitle" style="margin:0;font-size:14px;color:#FFF;">Incident Street
          Level Imagery</h3>
      </div>
      <button type="button" class="modal-close-btn" style="color:#FFF;"
        onclick="closeModal('streetViewModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:0;background:#0F172A;">
      <!-- Street View Sub-header / Telemetry Bar -->
      <div
        style="padding:10px 16px;background:#1E293B;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:11px;color:#E2E8F0;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span id="streetViewIncidentBadge"></span>
          <span id="streetViewPurokText" style="font-weight:600;color:#F8FAFC;"></span>
          <span style="color:#94A3B8;">•</span>
          <span id="streetViewCoordsText" style="font-family:var(--font-secondary);color:#38BDF8;"></span>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
          <button type="button" class="btn btn-sm" id="btnLaunchExternalStreetView"
            style="background:#2563EB;color:#FFF;border-color:#2563EB;font-size:10.5px;padding:3px 10px;">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
              <polyline points="15 3 21 3 21 9"></polyline>
              <line x1="10" y1="14" x2="21" y2="3"></line>
            </svg>
            Open in Google Street View (360°)
          </button>
          <button type="button" class="btn btn-outline btn-sm" id="btnLaunchExternalNavigation"
            style="color:#FFF;border-color:#475569;font-size:10.5px;padding:3px 10px;">
            🧭 Responders Route
          </button>
        </div>
      </div>

      <!-- Street View Player / Embed Container -->
      <div style="position:relative;width:100%;height:440px;background:#000000;overflow:hidden;">
        <iframe id="streetViewIframe" src="" width="100%" height="100%" style="border:0;" allowfullscreen loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"></iframe>

        <!-- Fallback & Imagery Guidance Overlay -->
        <div id="streetViewNoticeOverlay"
          style="position:absolute;bottom:8px;left:8px;right:8px;background:rgba(15, 23, 42, 0.88);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,0.15);border-radius:6px;padding:8px 12px;font-size:10.5px;color:#CBD5E1;display:flex;align-items:center;justify-content:space-between;gap:8px;">
          <div style="display:flex;align-items:center;gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="16" x2="12" y2="12"></line>
              <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <span>Google Street View 360° panorama player. If interior pathway is unmapped by vehicle camera, click
              <strong>Open in Google Street View</strong> or switch to <strong>Satellite Aerial View</strong> in
              Leaflet.</span>
          </div>
          <button type="button" class="btn btn-sm btn-outline"
            style="font-size:9.5px;color:#FFF;border-color:rgba(255,255,255,0.3);padding:2px 8px;"
            onclick="document.getElementById('streetViewNoticeOverlay').style.display='none'">Dismiss</button>
        </div>
      </div>
    </div>

    <div class="modal-footer"
      style="background:#1E293B;border-top:1px solid #334155;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:10px;color:#94A3B8;">Barangay Disaster Management GIS & Ground Imagery System</span>
      <button type="button" class="btn btn-outline btn-sm" style="color:#FFF;border-color:#475569;"
        onclick="closeModal('streetViewModal')">Close Street View</button>
    </div>
  </div>
</div>

<script>
  let currentActiveReport = null;
  let isEditModeActive = false;

  // Dynamic mapping of Puroks to their created hazards
  const purokHazardMap = <?= json_encode($purokHazardMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const purokHazardOptionsMap = <?= json_encode($purokSpecificHazards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const allBarangayHazards = <?= json_encode($dynamicHazardTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const CURRENT_BARANGAY_ID = <?= (int) $barangayId ?>;
  const allIncidentRequests = <?= json_encode($requests, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const BRGY_CENTER_LAT = <?= $brgyLat ?>;
  const BRGY_CENTER_LNG = <?= $brgyLng ?>;
  const BRGY_NAME = <?= json_encode($barangay['name']) ?>;

  // Leaflet Map & Marker Instances
  let overviewMap = null;
  let overviewMarkers = [];
  let overviewTileOsm = null;
  let overviewTileSat = null;

  let createMap = null;
  let createMarker = null;
  let createTileOsm = null;
  let createTileSat = null;

  let viewMap = null;
  let viewMarker = null;
  let viewTileOsm = null;
  let viewTileSat = null;

  // Custom pulsing hazard pin generator
  function getHazardMarkerIcon(severity) {
    let color = '#3B82F6';
    let pulseClass = '';
    if (severity === 'Critical') {
      color = '#EF4444';
      pulseClass = 'marker-pulse-critical';
    } else if (severity === 'High') {
      color = '#F59E0B';
      pulseClass = 'marker-pulse-high';
    } else if (severity === 'Completed' || severity === 'Low') {
      color = '#10B981';
    }

    const html = `
    <div class="custom-hazard-marker ${pulseClass}" style="background-color: ${color};">
      <div class="custom-hazard-marker-inner">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
          <line x1="12" y1="9" x2="12" y2="13"></line>
          <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>
      </div>
    </div>
  `;

    return L.divIcon({
      className: 'hazard-div-icon',
      html: html,
      iconSize: [32, 32],
      iconAnchor: [16, 32],
      popupAnchor: [0, -32]
    });
  }

  // Switch Tile layer between Streets (OSM) and High-Res Satellite (Esri)
  function switchMapTileLayer(mapType, tileType) {
    let targetMap, osmLayer, satLayer, btnOsm, btnSat;
    if (mapType === 'overview') {
      targetMap = overviewMap;
      osmLayer = overviewTileOsm;
      satLayer = overviewTileSat;
      btnOsm = document.getElementById('btnTileOsmOverview');
      btnSat = document.getElementById('btnTileSatOverview');
    } else if (mapType === 'create') {
      targetMap = createMap;
      osmLayer = createTileOsm;
      satLayer = createTileSat;
      btnOsm = document.getElementById('btnTileOsmCreate');
      btnSat = document.getElementById('btnTileSatCreate');
    } else if (mapType === 'view') {
      targetMap = viewMap;
      osmLayer = viewTileOsm;
      satLayer = viewTileSat;
      btnOsm = document.getElementById('btnTileOsmView');
      btnSat = document.getElementById('btnTileSatView');
    }

    if (!targetMap || !osmLayer || !satLayer) return;

    if (tileType === 'satellite') {
      targetMap.removeLayer(osmLayer);
      targetMap.addLayer(satLayer);
      if (btnOsm) btnOsm.classList.remove('active');
      if (btnSat) btnSat.classList.add('active');
    } else {
      targetMap.removeLayer(satLayer);
      targetMap.addLayer(osmLayer);
      if (btnSat) btnSat.classList.remove('active');
      if (btnOsm) btnOsm.classList.add('active');
    }
  }

  // =========================================================================
  // 1. OVERVIEW MAP INITIALIZATION
  // =========================================================================
  function initOverviewMap() {
    const container = document.getElementById('overviewIncidentMap');
    if (!container || overviewMap) return;

    overviewMap = L.map('overviewIncidentMap', {
      center: [BRGY_CENTER_LAT, BRGY_CENTER_LNG],
      zoom: 14,
      zoomControl: true
    });

    overviewTileOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(overviewMap);

    overviewTileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19,
      attribution: 'Tiles &copy; Esri'
    });

    // Plot incident markers
    const bounds = [];
    allIncidentRequests.forEach(r => {
      const lat = r.coordinates_lat ? parseFloat(r.coordinates_lat) : BRGY_CENTER_LAT;
      const lng = r.coordinates_lng ? parseFloat(r.coordinates_lng) : BRGY_CENTER_LNG;
      if (isNaN(lat) || isNaN(lng)) return;

      bounds.push([lat, lng]);
      const marker = L.marker([lat, lng], {
        icon: getHazardMarkerIcon(r.severity)
      }).addTo(overviewMap);

      const popupHtml = `
      <div style="min-width:220px;font-family:inherit;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <span style="font-weight:700;color:#0B2545;font-size:12px;">${escapeHtml(r.tracking_code)}</span>
          <span class="badge badge-info" style="font-size:9px;">${escapeHtml(r.disaster_type)}</span>
        </div>
        <div style="font-size:11px;font-weight:600;color:#1E293B;">📍 ${escapeHtml(r.purok_name)}${r.incident_location ? ' — ' + escapeHtml(r.incident_location) : ''}</div>
        <div style="font-size:10px;color:#64748B;margin:3px 0 6px 0;">
          Severity: <strong>${escapeHtml(r.severity)}</strong> | Status: <strong>${escapeHtml(r.status)}</strong><br>
          Affected: ${r.affected_families} Fam (${r.displaced_families} Displaced)
        </div>
        <div style="display:flex;gap:4px;margin-top:6px;">
          <button type="button" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:10px;flex:1;" onclick="viewReportDetailsFromMap(${r.id})">View Details</button>
          <button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:10px;background:#2563EB;color:#FFF;border-color:#2563EB;flex:1;" onclick="openStreetViewForReportFromMap(${r.id})">🚶 Street View</button>
        </div>
      </div>
    `;

      marker.bindPopup(popupHtml);
      overviewMarkers.push({ id: r.id, marker: marker, lat: lat, lng: lng });
    });

    if (bounds.length > 0) {
      overviewMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 16 });
    }
  }

  function recenterOverviewMap() {
    if (!overviewMap) return;
    const bounds = overviewMarkers.map(m => [m.lat, m.lng]);
    if (bounds.length > 0) {
      overviewMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 16 });
    } else {
      overviewMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 15);
    }
  }

  function focusIncidentOnOverviewMap(lat, lng, id) {
    const container = document.getElementById('overviewIncidentMapContainer');
    const btn = document.getElementById('btnToggleOverviewMapText');
    if (container && container.style.display === 'none') {
      container.style.display = 'block';
      if (btn) btn.innerText = 'Hide Incident Map';
      setTimeout(() => { if (overviewMap) overviewMap.invalidateSize(); }, 150);
    }
    if (!overviewMap) initOverviewMap();
    if (overviewMap) {
      overviewMap.setView([lat, lng], 17);
      const item = overviewMarkers.find(m => m.id === id);
      if (item) item.marker.openPopup();
      const card = document.getElementById('spatialIncidentMapCard');
      if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function toggleOverviewMapCard() {
    const container = document.getElementById('overviewIncidentMapContainer');
    const btn = document.getElementById('btnToggleOverviewMapText');
    if (container.style.display === 'none') {
      container.style.display = 'block';
      if (btn) btn.innerText = 'Hide Incident Map';
      setTimeout(() => { if (overviewMap) overviewMap.invalidateSize(); }, 150);
    } else {
      container.style.display = 'none';
      if (btn) btn.innerText = 'Show Incident Map';
    }
  }

  function viewReportDetailsFromMap(id) {
    const report = allIncidentRequests.find(r => r.id == id);
    if (report) viewReportDetails(report);
  }

  function openStreetViewForReportFromMap(id) {
    const report = allIncidentRequests.find(r => r.id == id);
    if (report) openStreetViewForReport(report);
  }

  // Auto-suggest / filter hazard based on selected Purok
  function handlePurokSelectChange(purokName, targetType) {
    if (!purokName) return;
    const selectElem = document.getElementById(targetType === 'create' ? 'create_disaster_type' : 'view_disaster_type');
    if (!selectElem) return;

    // Get selected purok id
    const purokSelect = document.getElementById(targetType === 'create' ? 'create_purok_name' : 'view_purok_name');
    const selectedOpt = purokSelect && purokSelect.selectedIndex > 0 ? purokSelect.options[purokSelect.selectedIndex] : null;
    const purokId = selectedOpt ? selectedOpt.getAttribute('data-id') : null;

    // Repopulate select options based on selected purok
    const currentVal = selectElem.value;
    selectElem.innerHTML = '<option value="">Select Hazard Type</option>';

    if (purokName === 'All Puroks' || purokId === 'all') {
      allBarangayHazards.forEach(h => {
        const opt = new Option(h, h);
        if (h === currentVal) opt.selected = true;
        selectElem.add(opt);
      });
      if (targetType === 'create') {
        recenterMapToBarangay('create');
      }
      return;
    }

    // Get hazard types specific to this purok
    const hazardsForPurok = (purokId && purokHazardOptionsMap[purokId]) ? purokHazardOptionsMap[purokId] : [];

    // If this purok has specific hazards, show them; otherwise show all registered barangay hazards
    const listToRender = (hazardsForPurok.length > 0) ? hazardsForPurok : allBarangayHazards;
    listToRender.forEach(h => {
      const opt = new Option(h, h);
      if (h === currentVal) opt.selected = true;
      selectElem.add(opt);
    });

    // Auto-select if purok has only 1 hazard or keep current selection
    if (hazardsForPurok.length === 1 && !currentVal) {
      selectElem.value = hazardsForPurok[0];
    }
  }

  // =========================================================================
  // DYNAMIC HAZARD TYPE MANAGEMENT (Scoped by Purok & Barangay)
  // =========================================================================
  function toggleDynamicHazardInput(type, show) {
    const box = document.getElementById('dynamicHazardBox_' + type);
    const input = document.getElementById('dynamic_hazard_input_' + type);
    if (!box) return;
    box.style.display = show ? 'block' : 'none';
    if (show && input) {
      input.value = '';
      setTimeout(() => input.focus(), 80);
    }
  }

  async function addDynamicHazard(type) {
    const input = document.getElementById('dynamic_hazard_input_' + type);
    if (!input) return;
    const rawVal = input.value.trim();
    if (!rawVal) {
      if (typeof showToast === 'function') {
        showToast('Please type a hazard type name.', 'warning');
      } else {
        alert('Please type a hazard type name.');
      }
      input.focus();
      return;
    }

    const purokSelect = document.getElementById(type === 'create' ? 'create_purok_name' : 'view_purok_name');
    const selectedPurokOpt = purokSelect && purokSelect.selectedIndex > 0 ? purokSelect.options[purokSelect.selectedIndex] : null;
    const purokVal = purokSelect ? purokSelect.value : '';
    const purokIdAttr = selectedPurokOpt ? selectedPurokOpt.getAttribute('data-id') : null;
    const isAllPuroks = purokVal === 'All Puroks' || purokIdAttr === 'all';
    const purokId = isAllPuroks ? '' : (purokIdAttr || '');

    if (!purokVal) {
      if (typeof showToast === 'function') {
        showToast('Please select a Purok Cluster Location first so the hazard type is linked to that jurisdiction.', 'warning');
      } else {
        alert('Please select a Purok Cluster Location first so the hazard type is linked to that jurisdiction.');
      }
      if (purokSelect) purokSelect.focus();
      return;
    }

    const formData = new FormData();
    formData.append('name', rawVal);
    formData.append('purok_id', purokId);
    formData.append('barangay_id', CURRENT_BARANGAY_ID);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/add_hazard_option.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        const optName = data.name || rawVal;

        // Update local maps for this purok if scoped to a specific purok
        if (purokId) {
          if (!purokHazardOptionsMap[purokId]) {
            purokHazardOptionsMap[purokId] = [];
          }
          if (!purokHazardOptionsMap[purokId].includes(optName)) {
            purokHazardOptionsMap[purokId].push(optName);
          }
        }
        if (!allBarangayHazards.includes(optName)) {
          allBarangayHazards.push(optName);
        }

        // Add option to target selects
        ['create_disaster_type', 'view_disaster_type'].forEach(selId => {
          const select = document.getElementById(selId);
          if (select) {
            let exists = false;
            for (let i = 0; i < select.options.length; i++) {
              if (select.options[i].value.toLowerCase() === optName.toLowerCase()) {
                exists = true;
                break;
              }
            }
            if (!exists) {
              const opt = new Option(optName, optName);
              select.add(opt);
            }
          }
        });

        // Also add to filter select if present
        const filterSel = document.querySelector('select[name="type"]');
        if (filterSel) {
          let exists = false;
          for (let i = 0; i < filterSel.options.length; i++) {
            if (filterSel.options[i].value.toLowerCase() === optName.toLowerCase()) {
              exists = true;
              break;
            }
          }
          if (!exists) {
            filterSel.add(new Option(optName, optName));
          }
        }

        // Auto-select in current target select
        const currentSelect = document.getElementById(type === 'create' ? 'create_disaster_type' : 'view_disaster_type');
        if (currentSelect) {
          currentSelect.value = optName;
        }

        toggleDynamicHazardInput(type, false);

        const scopeLabel = isAllPuroks ? `Barangay ${BRGY_NAME} (All Puroks)` : selectedPurokOpt.textContent.trim();
        if (typeof showToast === 'function') {
          showToast(`Hazard type "${optName}" saved for ${scopeLabel} and selected.`, 'success');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error saving hazard type.', 'danger');
        } else {
          alert(data.message || 'Error saving hazard type.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while saving hazard type.', 'danger');
      } else {
        alert('Network error while saving hazard type.');
      }
    }
  }

  // =========================================================================
  // 2. CREATE INCIDENT MODAL & LEAFLET PINPOINTING
  // =========================================================================
  function openCreateReportModal() {
    document.getElementById('createReportForm').reset();
    toggleDynamicHazardInput('create', false);
    document.getElementById('create_lat').value = BRGY_CENTER_LAT.toFixed(6);
    document.getElementById('create_lng').value = BRGY_CENTER_LNG.toFixed(6);

    // Reset hazard type select to full barangay hazard list
    const hazSelect = document.getElementById('create_disaster_type');
    if (hazSelect) {
      hazSelect.innerHTML = '<option value="">Select Hazard Type</option>';
      allBarangayHazards.forEach(h => {
        hazSelect.add(new Option(h, h));
      });
    }

    openModal('createReportModal');
    setTimeout(() => {
      initCreateMap();
    }, 250);
  }

  if (new URLSearchParams(window.location.search).get('action') === 'new') {
    openCreateReportModal();
  }

  function initCreateMap() {
    const container = document.getElementById('createIncidentMap');
    if (!container) return;

    const lat = parseFloat(document.getElementById('create_lat').value) || BRGY_CENTER_LAT;
    const lng = parseFloat(document.getElementById('create_lng').value) || BRGY_CENTER_LNG;

    if (!createMap) {
      createMap = L.map('createIncidentMap', {
        center: [lat, lng],
        zoom: 15,
        zoomControl: true
      });

      createTileOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(createMap);

      createTileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
      });

      createMarker = L.marker([lat, lng], {
        draggable: true,
        icon: getHazardMarkerIcon('Critical')
      }).addTo(createMap);

      createMarker.bindPopup(`<strong>Pinpoint Disaster Location</strong><br><span style="font-size:10px;color:#64748B;">Drag pin or click map to set exact site</span>`).openPopup();

      createMarker.on('dragend', function (e) {
        const pos = e.target.getLatLng();
        updateCreateCoords(pos.lat, pos.lng, false);
      });

      createMap.on('click', function (e) {
        const pos = e.latlng;
        createMarker.setLatLng(pos);
        updateCreateCoords(pos.lat, pos.lng, false);
      });
    } else {
      createMarker.setLatLng([lat, lng]);
      createMap.setView([lat, lng], 15);
      createMap.invalidateSize();
      createMarker.openPopup();
    }
  }

  function updateCreateCoords(lat, lng, doReverseGeocode) {
    document.getElementById('create_lat').value = parseFloat(lat).toFixed(6);
    document.getElementById('create_lng').value = parseFloat(lng).toFixed(6);
    if (createMarker) {
      createMarker.getPopup().setContent(`<strong>Selected Disaster Site</strong><br><span style="font-size:10px;color:#64748B;">${parseFloat(lat).toFixed(6)}, ${parseFloat(lng).toFixed(6)}</span>`);
    }
    if (doReverseGeocode) {
      reverseGeocodeIncidentPin('create');
    }
  }

  function recenterMapToBarangay(mode) {
    if (mode === 'create') {
      updateCreateCoords(BRGY_CENTER_LAT, BRGY_CENTER_LNG, false);
      if (createMap && createMarker) {
        createMarker.setLatLng([BRGY_CENTER_LAT, BRGY_CENTER_LNG]);
        createMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 15);
      }
    } else if (mode === 'view') {
      updateViewCoords(BRGY_CENTER_LAT, BRGY_CENTER_LNG, false);
      if (viewMap && viewMarker) {
        viewMarker.setLatLng([BRGY_CENTER_LAT, BRGY_CENTER_LNG]);
        viewMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 15);
      }
    }
  }

  function handleManualCoordChange(mode) {
    const lat = parseFloat(document.getElementById(mode === 'create' ? 'create_lat' : 'view_lat').value);
    const lng = parseFloat(document.getElementById(mode === 'create' ? 'create_lng' : 'view_lng').value);
    if (isNaN(lat) || isNaN(lng)) return;

    if (mode === 'create' && createMap && createMarker) {
      createMarker.setLatLng([lat, lng]);
      createMap.panTo([lat, lng]);
    } else if (mode === 'view' && viewMap && viewMarker) {
      viewMarker.setLatLng([lat, lng]);
      viewMap.panTo([lat, lng]);
    }
  }

  // Reverse Geocoding helper via OpenStreetMap Nominatim
  async function reverseGeocodeIncidentPin(mode) {
    const latElem = document.getElementById(mode === 'create' ? 'create_lat' : 'view_lat');
    const lngElem = document.getElementById(mode === 'create' ? 'create_lng' : 'view_lng');
    const locElem = document.getElementById(mode === 'create' ? 'create_incident_location' : 'view_incident_location');
    if (!latElem || !lngElem || !locElem) return;

    const lat = parseFloat(latElem.value);
    const lng = parseFloat(lngElem.value);
    if (isNaN(lat) || isNaN(lng)) return;

    const originalPlaceholder = locElem.placeholder;
    locElem.placeholder = 'Detecting street location via OpenStreetMap...';

    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();
      if (data && data.address) {
        const road = data.address.road || data.address.street || data.address.pedestrian || data.address.path || '';
        const suburb = data.address.suburb || data.address.neighbourhood || data.address.village || '';
        const landmark = data.address.amenity || data.address.building || '';
        const parts = [landmark, road, suburb].filter(Boolean);
        if (parts.length > 0) {
          locElem.value = parts.join(', ');
          if (typeof showToast === 'function') {
            showToast('Street detected: ' + locElem.value, 'info');
          }
        }
      }
    } catch (err) {
      console.log('Reverse geocoding notice:', err);
    } finally {
      locElem.placeholder = originalPlaceholder;
    }
  }

  // Device GPS locator
  function locateUserPosition(mode) {
    if (!navigator.geolocation) {
      alert('Geolocation is not supported by your browser.');
      return;
    }
    if (typeof showToast === 'function') {
      showToast('Pinpointing device GPS location...', 'info');
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        if (mode === 'create') {
          updateCreateCoords(lat, lng, false);
          if (createMarker && createMap) {
            createMarker.setLatLng([lat, lng]);
            createMap.setView([lat, lng], 17);
          }
        } else if (mode === 'view' && isEditModeActive) {
          updateViewCoords(lat, lng, false);
          if (viewMarker && viewMap) {
            viewMarker.setLatLng([lat, lng]);
            viewMap.setView([lat, lng], 17);
          }
        }
        if (typeof showToast === 'function') {
          showToast('Device GPS coordinates captured.', 'success');
        }
      },
      (err) => {
        alert('Unable to capture location: ' + err.message);
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  }

  // Handle Create Form Submit (AJAX with fallback)
  async function handleCreateReport(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSubmitCreateReport');
    btn.disabled = true;
    btn.innerText = 'Submitting...';

    try {
      const formData = new FormData(form);
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/create.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Incident report submitted successfully as Ongoing.', 'success');
        } else {
          alert(data.message || 'Incident report submitted successfully as Ongoing.');
        }
        closeModal('createReportModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error submitting report.', 'danger');
        } else {
          alert(data.message || 'Error submitting report.');
        }
        btn.disabled = false;
        btn.innerText = 'Submit Ongoing Report';
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while filing incident report.', 'danger');
      } else {
        alert('Network error while filing incident report.');
      }
      btn.disabled = false;
      btn.innerText = 'Submit Ongoing Report';
    }
  }

  // =========================================================================
  // 3. VIEW / IN-PLACE EDIT INCIDENT REPORT & MAP INITIALIZATION
  // =========================================================================
  function viewReportDetails(req) {
    currentActiveReport = req;
    isEditModeActive = false;

    // Set ID & hidden status
    document.getElementById('view_report_id').value = req.id;
    document.getElementById('view_report_status_hidden').value = req.status;

    // Header Title & Tracking
    document.getElementById('viewModalTrackingHeader').innerText = req.tracking_code;
    document.getElementById('view_report_title_display').innerText = `${req.disaster_type} Incident — ${req.purok_name}`;
    document.getElementById('view_report_meta_sub').innerText = `Filed by ${req.submitted_by_name || 'Barangay Head'} on ${formatDateTimeDisplay(req.created_at)}`;

    // Header Status Badge
    const statusBadge = document.getElementById('viewModalStatusBadge');
    if (req.status === 'Ongoing') {
      statusBadge.innerHTML = '<span class="badge-ongoing-pulse">Ongoing</span>';
    } else if (req.status === 'Completed') {
      statusBadge.innerHTML = '<span class="badge badge-success" style="font-weight:700;">✓ Completed</span>';
    } else {
      statusBadge.innerHTML = `<span class="badge badge-info">${escapeHtml(req.status)}</span>`;
    }

    // Populate Input Fields
    const viewDisasterSelect = document.getElementById('view_disaster_type');
    let disasterOptFound = false;
    for (let i = 0; i < viewDisasterSelect.options.length; i++) {
      if (viewDisasterSelect.options[i].value.toLowerCase() === (req.disaster_type || '').toLowerCase()) {
        viewDisasterSelect.selectedIndex = i;
        disasterOptFound = true;
        break;
      }
    }
    if (!disasterOptFound && req.disaster_type) {
      const newOpt = new Option(req.disaster_type, req.disaster_type, true, true);
      viewDisasterSelect.add(newOpt);
    }

    const viewPurokSelect = document.getElementById('view_purok_name');
    let purokOptFound = false;
    for (let i = 0; i < viewPurokSelect.options.length; i++) {
      if (viewPurokSelect.options[i].value.toLowerCase() === (req.purok_name || '').toLowerCase()) {
        viewPurokSelect.selectedIndex = i;
        purokOptFound = true;
        break;
      }
    }
    if (!purokOptFound && req.purok_name) {
      const newOpt = new Option(req.purok_name, req.purok_name, true, true);
      viewPurokSelect.add(newOpt);
    }

    const aidSelect = document.getElementById('view_aid_support');
    if (aidSelect) aidSelect.value = req.aid_support || 'resources';

    document.getElementById('view_severity').value = req.severity;
    document.getElementById('view_urgency').value = req.urgency;
    document.getElementById('view_incident_location').value = req.incident_location || '';
    document.getElementById('view_lat').value = req.coordinates_lat ? parseFloat(req.coordinates_lat).toFixed(6) : BRGY_CENTER_LAT.toFixed(6);
    document.getElementById('view_lng').value = req.coordinates_lng ? parseFloat(req.coordinates_lng).toFixed(6) : BRGY_CENTER_LNG.toFixed(6);

    document.getElementById('view_affected_families').value = req.affected_families;
    document.getElementById('view_displaced_families').value = req.displaced_families;
    document.getElementById('view_affected_individuals').value = req.affected_individuals;
    document.getElementById('view_casualties_count').value = req.casualties_count;
    document.getElementById('view_injuries_count').value = req.injuries_count;
    document.getElementById('view_missing_count').value = req.missing_count;
    document.getElementById('view_situation_overview').value = req.situation_overview || '';
    document.getElementById('view_requested_assistance').value = req.requested_assistance || '';
    document.getElementById('view_resolution_notes').value = req.resolution_notes || '';

    // Completed Timestamp text
    const compTimeElem = document.getElementById('view_completed_at_timestamp');
    if (req.status === 'Completed' && req.completed_at) {
      compTimeElem.innerText = `Operations concluded on: ${formatDateTimeDisplay(req.completed_at)}`;
      compTimeElem.style.display = 'block';
    } else {
      compTimeElem.innerText = '';
      compTimeElem.style.display = 'none';
    }

    // Footer timestamps
    document.getElementById('view_created_at_text').innerText = formatDateTimeDisplay(req.created_at);
    document.getElementById('view_updated_at_text').innerText = formatDateTimeDisplay(req.updated_at || req.created_at);

    // Status Action Buttons (Finish vs Reopen)
    const btnFinish = document.getElementById('btnFinishIncident');
    const btnReopen = document.getElementById('btnReopenIncident');
    if (req.status === 'Ongoing') {
      btnFinish.style.display = 'inline-flex';
      btnReopen.style.display = 'none';
    } else if (req.status === 'Completed') {
      btnFinish.style.display = 'none';
      btnReopen.style.display = 'inline-flex';
    } else {
      btnFinish.style.display = 'inline-flex';
      btnReopen.style.display = 'none';
    }

    // Reset to View Mode (All fields disabled)
    setFormEditMode(false);

    openModal('viewReportModal');

    setTimeout(() => {
      initViewMap(req.coordinates_lat, req.coordinates_lng, req);
    }, 250);
  }

  function initViewMap(latVal, lngVal, req) {
    const container = document.getElementById('viewIncidentMap');
    if (!container) return;

    const lat = latVal ? parseFloat(latVal) : BRGY_CENTER_LAT;
    const lng = lngVal ? parseFloat(lngVal) : BRGY_CENTER_LNG;

    if (!viewMap) {
      viewMap = L.map('viewIncidentMap', {
        center: [lat, lng],
        zoom: 16,
        zoomControl: true
      });

      viewTileOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(viewMap);

      viewTileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
      });

      viewMarker = L.marker([lat, lng], {
        draggable: isEditModeActive,
        icon: getHazardMarkerIcon(req.severity || 'Moderate')
      }).addTo(viewMap);

      viewMarker.bindPopup(`<strong>${escapeHtml(req.tracking_code)}</strong><br><span style="font-size:10px;color:#64748B;">${escapeHtml(req.disaster_type)} — ${escapeHtml(req.purok_name)}</span>`).openPopup();

      viewMarker.on('dragend', function (e) {
        if (!isEditModeActive) return;
        const pos = e.target.getLatLng();
        updateViewCoords(pos.lat, pos.lng, true);
      });

      viewMap.on('click', function (e) {
        if (!isEditModeActive) return;
        const pos = e.latlng;
        viewMarker.setLatLng(pos);
        updateViewCoords(pos.lat, pos.lng, true);
      });
    } else {
      viewMarker.setIcon(getHazardMarkerIcon(req.severity || 'Moderate'));
      viewMarker.setLatLng([lat, lng]);
      if (isEditModeActive) {
        viewMarker.dragging.enable();
      } else {
        viewMarker.dragging.disable();
      }
      viewMap.setView([lat, lng], 16);
      viewMap.invalidateSize();
      viewMarker.getPopup().setContent(`<strong>${escapeHtml(req.tracking_code)}</strong><br><span style="font-size:10px;color:#64748B;">${escapeHtml(req.disaster_type)} — ${escapeHtml(req.purok_name)}</span>`).openPopup();
    }
  }

  function updateViewCoords(lat, lng, doReverseGeocode) {
    document.getElementById('view_lat').value = parseFloat(lat).toFixed(6);
    document.getElementById('view_lng').value = parseFloat(lng).toFixed(6);
    if (viewMarker) {
      viewMarker.getPopup().setContent(`<strong>Updated Pinpoint</strong><br><span style="font-size:10px;color:#64748B;">${parseFloat(lat).toFixed(6)}, ${parseFloat(lng).toFixed(6)}</span>`);
    }
    if (doReverseGeocode) {
      reverseGeocodeIncidentPin('view');
    }
  }

  // Toggle In-Place Edit Mode
  function toggleReportEditMode() {
    setFormEditMode(!isEditModeActive);
  }

  function setFormEditMode(enableEdit) {
    isEditModeActive = enableEdit;

    const fields = [
      'view_aid_support', 'view_disaster_type', 'view_purok_name', 'view_severity', 'view_urgency',
      'view_incident_location', 'view_lat', 'view_lng',
      'view_affected_families', 'view_displaced_families', 'view_affected_individuals',
      'view_casualties_count', 'view_injuries_count', 'view_missing_count',
      'view_situation_overview', 'view_requested_assistance', 'view_resolution_notes'
    ];

    fields.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = !enableEdit;
    });

    if (viewMarker) {
      if (enableEdit) {
        viewMarker.dragging.enable();
      } else {
        viewMarker.dragging.disable();
      }
    }

    const hintMap = document.getElementById('viewMapInteractiveHint');
    if (hintMap) {
      hintMap.innerText = enableEdit
        ? 'Editing Mode: Drag pin or click map to reposition the incident location.'
        : 'Interactive Leaflet GIS map with Street View coverage and satellite terrain inspection.';
    }

    const btnToggle = document.getElementById('btnToggleEditMode');
    const btnSave = document.getElementById('btnSaveReportChanges');
    const hintText = document.getElementById('viewModeHintText');

    if (enableEdit) {
      btnToggle.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Cancel Edit';
      btnToggle.className = 'btn btn-outline btn-sm';
      btnSave.style.display = 'inline-flex';
      hintText.innerText = '(Editing Mode — Fields & Pinpoint Editable)';
      hintText.style.color = 'var(--color-primary)';
    } else {
      btnToggle.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Edit Report';
      btnToggle.className = 'btn btn-outline btn-sm';
      btnSave.style.display = 'none';
      hintText.innerText = '(View Only Mode)';
      hintText.style.color = 'var(--color-text-muted)';
    }

    const btnAddHazardView = document.getElementById('btnShowAddHazardView');
    if (btnAddHazardView) {
      btnAddHazardView.style.display = enableEdit ? 'inline-flex' : 'none';
      if (!enableEdit) {
        toggleDynamicHazardInput('view', false);
      }
    }
  }

  // =========================================================================
  // 4. STREET VIEW 360° INSPECTOR INTEGRATION
  // =========================================================================
  function openStreetViewForReport(report) {
    if (!report) return;
    const lat = report.coordinates_lat ? parseFloat(report.coordinates_lat) : BRGY_CENTER_LAT;
    const lng = report.coordinates_lng ? parseFloat(report.coordinates_lng) : BRGY_CENTER_LNG;

    document.getElementById('streetViewModalTitle').innerText = `${report.disaster_type} Incident — ${report.tracking_code}`;
    document.getElementById('streetViewIncidentBadge').innerHTML = `<span class="badge badge-info" style="font-size:10px;">${escapeHtml(report.disaster_type)}</span>`;
    document.getElementById('streetViewPurokText').innerText = `${report.purok_name}${report.incident_location ? ' (' + report.incident_location + ')' : ''}`;
    document.getElementById('streetViewCoordsText').innerText = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;

    // Set Street View embed URL
    const iframe = document.getElementById('streetViewIframe');
    iframe.src = `https://maps.google.com/maps?q=${lat},${lng}&layer=c&cbll=${lat},${lng}&cbp=11,0,0,0,0&output=svembed`;

    // External Links
    const btnExternal = document.getElementById('btnLaunchExternalStreetView');
    btnExternal.onclick = () => {
      window.open(`https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lng}`, '_blank');
    };

    const btnNav = document.getElementById('btnLaunchExternalNavigation');
    btnNav.onclick = () => {
      window.open(`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`, '_blank');
    };

    openModal('streetViewModal');
  }

  function openStreetViewForCurrentReport() {
    if (currentActiveReport) {
      const lat = document.getElementById('view_lat').value;
      const lng = document.getElementById('view_lng').value;
      const loc = document.getElementById('view_incident_location').value;
      const customReport = {
        ...currentActiveReport,
        incident_location: loc,
        coordinates_lat: lat,
        coordinates_lng: lng
      };
      openStreetViewForReport(customReport);
    }
  }

  function openGoogleNavigationForCurrentReport() {
    const lat = document.getElementById('view_lat')?.value || currentActiveReport?.coordinates_lat || BRGY_CENTER_LAT;
    const lng = document.getElementById('view_lng')?.value || currentActiveReport?.coordinates_lng || BRGY_CENTER_LNG;
    window.open(`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`, '_blank');
  }

  function previewStreetViewFromForm(mode) {
    const lat = parseFloat(document.getElementById(mode === 'create' ? 'create_lat' : 'view_lat').value) || BRGY_CENTER_LAT;
    const lng = parseFloat(document.getElementById(mode === 'create' ? 'create_lng' : 'view_lng').value) || BRGY_CENTER_LNG;
    const purok = document.getElementById(mode === 'create' ? 'create_purok_name' : 'view_purok_name').value || 'Barangay Area';
    const type = document.getElementById(mode === 'create' ? 'create_disaster_type' : 'view_disaster_type').value || 'Disaster';
    const loc = document.getElementById(mode === 'create' ? 'create_incident_location' : 'view_incident_location').value || '';

    openStreetViewForReport({
      tracking_code: 'Live Pinpoint',
      disaster_type: type,
      purok_name: purok,
      incident_location: loc,
      coordinates_lat: lat,
      coordinates_lng: lng
    });
  }

  // Save Changes Handler
  async function handleSaveReportChanges(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSaveReportChanges');
    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const formData = new FormData(form);
      // Ensure coordinates and location are guaranteed present
      if (!formData.has('coordinates_lat')) {
        formData.append('coordinates_lat', document.getElementById('view_lat').value);
      }
      if (!formData.has('coordinates_lng')) {
        formData.append('coordinates_lng', document.getElementById('view_lng').value);
      }
      if (!formData.has('incident_location')) {
        formData.append('incident_location', document.getElementById('view_incident_location').value);
      }

      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Report updated successfully.', 'success');
        } else {
          alert(data.message || 'Report updated successfully.');
        }
        closeModal('viewReportModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to update report.', 'danger');
        } else {
          alert(data.message || 'Failed to update report.');
        }
        btn.disabled = false;
        btn.innerText = 'Save Changes';
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while saving changes.', 'danger');
      } else {
        alert('Network error while saving changes.');
      }
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  }

  // Auto initialize overview map on page ready
  document.addEventListener('DOMContentLoaded', () => {
    initOverviewMap();
  });

  // Finish / End Incident Handler ("matically ongoing until the barangay head finish or end nah")
  async function handleFinishIncidentClick() {
    if (!currentActiveReport) return;

    const notes = prompt(`Conclude operations for Incident ${currentActiveReport.tracking_code}?\n\nEnter optional Accomplishment / Resolution Notes:`, document.getElementById('view_resolution_notes')?.value || 'Incident operations concluded. Evacuees assisted and area secured.');
    if (notes === null) return; // User cancelled prompt

    const formData = new FormData();
    formData.append('request_id', currentActiveReport.id);
    formData.append('status', 'Completed');
    formData.append('resolution_notes', notes);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update_status.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || `Incident ${currentActiveReport.tracking_code} concluded successfully.`, 'success');
        } else {
          alert(data.message || `Incident ${currentActiveReport.tracking_code} concluded successfully.`);
        }
        closeModal('viewReportModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to update status.', 'danger');
        } else {
          alert(data.message || 'Failed to update status.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while concluding incident.', 'danger');
      } else {
        alert('Network error while concluding incident.');
      }
    }
  }

  // Reopen Incident Handler
  async function handleReopenIncidentClick() {
    if (!currentActiveReport) return;
    if (!confirm(`Reopen Incident ${currentActiveReport.tracking_code} and set status back to Ongoing?`)) return;

    const formData = new FormData();
    formData.append('request_id', currentActiveReport.id);
    formData.append('status', 'Ongoing');

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update_status.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || `Incident ${currentActiveReport.tracking_code} status set to Ongoing.`, 'success');
        } else {
          alert(data.message || `Incident ${currentActiveReport.tracking_code} status set to Ongoing.`);
        }
        closeModal('viewReportModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to reopen incident.', 'danger');
        } else {
          alert(data.message || 'Failed to reopen incident.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while reopening incident.', 'danger');
      } else {
        alert('Network error while reopening incident.');
      }
    }
  }

  // Delete Report Handler
  async function handleDeleteReportClick() {
    if (!currentActiveReport) return;
    if (!confirm(`Are you sure you want to permanently delete Incident Report "${currentActiveReport.tracking_code}"? This action cannot be undone.`)) return;

    const formData = new FormData();
    formData.append('id', currentActiveReport.id);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/delete.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Incident report removed.', 'success');
        } else {
          alert(data.message || 'Incident report removed.');
        }
        closeModal('viewReportModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error deleting incident report.', 'danger');
        } else {
          alert(data.message || 'Error deleting incident report.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while deleting incident report.', 'danger');
      } else {
        alert('Network error while deleting incident report.');
      }
    }
  }

  // Format Date Helper
  function formatDateTimeDisplay(dtStr) {
    if (!dtStr || dtStr === '0000-00-00 00:00:00') return '—';
    try {
      const d = new Date(dtStr.replace(' ', 'T'));
      if (isNaN(d.getTime())) return dtStr;
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
        d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
      return dtStr;
    }
  }

  // Escape HTML Helper
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>