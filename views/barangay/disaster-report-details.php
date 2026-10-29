<?php
// ============================================================================
// Views (Barangay Head): Disaster Incident Report Details
// Dedicated full-page view replacing former overview modality.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

// Fetch Incident Report ID
$reportId = (int) ($_GET['id'] ?? 0);
if ($reportId <= 0) {
  redirectWithFlash(BASE_URL . '/views/barangay/disaster-reports.php', 'error', 'Invalid incident report ID.');
}

// Fetch Incident Report Details
$stmt = $db->prepare("
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.status AS recommendation_status, r.confidence_score
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    LEFT JOIN users u ON dr.submitted_by = u.id
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id
    WHERE dr.id = ? AND dr.barangay_id = ?
");
$stmt->execute([$reportId, $barangayId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
  redirectWithFlash(BASE_URL . '/views/barangay/disaster-reports.php', 'error', 'Incident report record not found or access denied.');
}

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

$brgyLat = !empty($barangay['coordinates_lat']) ? (float) $barangay['coordinates_lat'] : 8.246596;
$brgyLng = !empty($barangay['coordinates_lng']) ? (float) $barangay['coordinates_lng'] : 124.259474;

// Fetch Local Puroks
$purokStmt = $db->prepare("
    SELECT id, name, hazard_types, risk_level 
    FROM puroks 
    WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL) 
    ORDER BY name ASC
");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Custom cluster options
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

// Load Dynamic Hazard Types
$hazOptionsStmt = $db->prepare("
  SELECT id, purok_id, barangay_id, name 
  FROM hazard_type_options 
  WHERE barangay_id = ? OR purok_id IN (SELECT id FROM puroks WHERE barangay_id = ?) OR (barangay_id IS NULL AND purok_id IS NULL)
  ORDER BY name ASC
");
$hazOptionsStmt->execute([$barangayId, $barangayId]);
$allHazardOptions = $hazOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

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

$isCompleted = ($req['status'] === 'Completed');
$isOngoing = ($req['status'] === 'Ongoing');

$reportLat = !empty($req['coordinates_lat']) ? (float) $req['coordinates_lat'] : $brgyLat;
$reportLng = !empty($req['coordinates_lng']) ? (float) $req['coordinates_lng'] : $brgyLng;

$pageTitle = clean($req['tracking_code']) . " — Report Details";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<!-- Leaflet GIS Mapping Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
  .report-details-header {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-primary, 10px);
    padding: 18px 20px;
    margin-bottom: var(--space-4, 16px);
  }

  .kpi-stat-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: var(--space-4, 16px);
  }

  @media (max-width: 1100px) {
    .kpi-stat-grid {
      grid-template-columns: repeat(3, 1fr);
    }
  }

  @media (max-width: 600px) {
    .kpi-stat-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  .kpi-stat-box {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm, 8px);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .kpi-stat-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-text-secondary);
  }

  .kpi-stat-val {
    font-size: 18px;
    font-weight: 700;
    font-family: var(--font-secondary);
    color: var(--color-primary);
    line-height: 1.2;
  }

  .report-grid-two-col {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 16px;
    align-items: start;
    margin-bottom: var(--space-4, 16px);
  }

  @media (max-width: 980px) {
    .report-grid-two-col {
      grid-template-columns: 1fr;
    }
  }

  .card-pane {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-primary, 10px);
    overflow: hidden;
  }

  .card-pane-head {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--color-surface-subtle, #FAFBFB);
  }

  .card-pane-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
  }

  .card-pane-body {
    padding: 16px;
  }

  /* Map Container */
  .details-map-picker {
    width: 100%;
    height: 340px;
    border-radius: var(--radius-md, 8px);
    border: 1px solid var(--color-border);
    position: relative;
    z-index: 1;
  }

  .map-tile-btn {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border);
    color: var(--color-text-secondary);
    font-size: 10px;
    font-weight: 600;
    padding: 3px 8px;
    cursor: pointer;
    transition: all 0.15s ease;
  }

  .map-tile-btn.active {
    background: var(--color-primary, #0B2545);
    border-color: var(--color-primary, #0B2545);
    color: #FFFFFF;
  }

  /* Disabled read-only inputs */
  .form-control:disabled,
  select.form-control:disabled,
  textarea.form-control:disabled {
    background-color: var(--color-surface-subtle, #F0F3F4) !important;
    color: var(--color-text, #24313A) !important;
    border-color: var(--color-border-light, #E7EDF0) !important;
    cursor: default !important;
    opacity: 0.95 !important;
    -webkit-text-fill-color: var(--color-text, #24313A) !important;
  }

  /* Status Badges */
  .badge-ongoing-pulse {
    background: rgba(245, 158, 11, 0.12);
    color: #B45309;
    border: 1px solid rgba(245, 158, 11, 0.35);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
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
  }

  /* Marker Styling */
  .hazard-div-icon {
    background: transparent !important;
    border: none !important;
  }

  .custom-hazard-marker {
    width: 30px;
    height: 30px;
    border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #FFFFFF;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.25);
    position: relative;
    cursor: pointer;
  }

  .custom-hazard-marker-inner {
    transform: rotate(45deg);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  @media print {
    .sidebar, .topbar, .breadcrumb-bar, .no-print {
      display: none !important;
    }
    .main-content {
      margin: 0 !important;
      padding: 0 !important;
    }
  }
</style>

<!-- Breadcrumb Navigation -->
<div class="breadcrumb-bar" style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
  <div style="display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--color-text-muted);">
    <a href="<?= BASE_URL ?>/views/barangay/dashboard.php" style="color: var(--color-text-muted); text-decoration: none;">Dashboard</a>
    <span>/</span>
    <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php" style="color: var(--color-text-muted); text-decoration: none;">Disaster Reports</a>
    <span>/</span>
    <span style="color: var(--color-primary); font-weight: 600;"><?= clean($req['tracking_code']) ?></span>
  </div>

  <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; padding: 4px 10px; text-decoration: none;">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="15 18 9 12 15 6"></polyline>
    </svg>
    Back to Disaster Reports
  </a>
</div>

<!-- Header Card: Identity & Single Clean Toolbar -->
<div class="report-details-header">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 260px;">
      <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px;">
        <span style="font-family: var(--font-secondary); font-size: 11px; font-weight: 700; color: var(--color-primary); background: var(--color-surface-subtle); padding: 2px 7px; border-radius: 4px; border: 1px solid var(--color-border);">
          <?= clean($req['tracking_code']) ?>
        </span>

        <span class="badge badge-info" style="font-size: 10px; font-weight: 600; padding: 2px 7px;">
          <?= clean($req['disaster_type']) ?>
        </span>

        <span class="badge badge-neutral" style="font-size: 10px;">
          Severity: <?= clean($req['severity']) ?>
        </span>

        <?php if ($isOngoing): ?>
          <span class="badge-ongoing-pulse">Ongoing</span>
        <?php elseif ($isCompleted): ?>
          <span class="badge badge-success" style="font-size: 10px; font-weight: 600;">Completed</span>
        <?php else: ?>
          <span class="badge badge-neutral"><?= clean($req['status']) ?></span>
        <?php endif; ?>
      </div>

      <h1 style="font-size: 18px; font-weight: 700; color: var(--color-primary); margin: 0 0 4px 0; line-height: 1.3;">
        <?= clean($req['disaster_type']) ?> — <?= clean($req['purok_name']) ?>
      </h1>

      <div style="font-size: 11px; color: var(--color-text-secondary);">
        Filed by <strong><?= clean($req['submitted_by_name'] ?: 'Barangay Head') ?></strong> &bull;
        Barangay <?= clean($req['barangay_name'] ?? $barangay['name']) ?> &bull;
        <?= formatDate($req['created_at'], 'M d, Y h:i A') ?>
        <?php if (!empty($req['completed_at'])): ?>
          &bull; <span style="color: var(--color-success, #047857);">Concluded: <?= formatDate($req['completed_at'], 'M d, Y h:i A') ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Actions Toolbar (Only 1 360 View Button, No Directions Button) -->
    <div class="no-print" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
      <!-- Single 360° Street View Button -->
      <button type="button" class="btn btn-outline btn-sm" onclick="openStreetViewModal()"
        style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="5" r="3"></circle>
          <path d="M12 8v8"></path>
          <path d="M9 21l3-5 3 5"></path>
          <path d="M6 11l6-2 6 2"></path>
        </svg>
        360° Street View
      </button>

      <!-- Toggle Edit Mode -->
      <button type="button" class="btn btn-outline btn-sm" id="btnToggleEdit" onclick="toggleEditMode()"
        style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 20h9"></path>
          <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
        </svg>
        <span id="btnToggleEditText">Edit Report</span>
      </button>

      <!-- Save Button (Only shown when editing) -->
      <button type="button" class="btn btn-primary btn-sm" id="btnSaveTop" onclick="submitReportForm()"
        style="display: none; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;">
        Save Changes
      </button>

      <!-- Finish / End Incident Button -->
      <?php if ($isOngoing): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnFinishIncident" onclick="handleFinishIncident()"
          style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
          Finish Incident
        </button>
      <?php else: ?>
        <button type="button" class="btn btn-outline btn-sm" id="btnReopenIncident" onclick="handleReopenIncident()"
          style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
          Reopen Incident
        </button>
      <?php endif; ?>

      <!-- Delete Button -->
      <button type="button" class="btn btn-outline btn-sm" onclick="handleDeleteReport()"
        style="color: var(--color-danger); border-color: var(--color-danger); font-size: 11px;">
        Delete
      </button>
    </div>
  </div>
</div>

<!-- Population Impact KPI Cards -->
<div class="kpi-stat-grid">
  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Affected Families</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['affected_families']) ?></div>
  </div>

  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Displaced Families</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['displaced_families']) ?></div>
  </div>

  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Affected Individuals</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['affected_individuals']) ?></div>
  </div>

  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Casualties</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['casualties_count']) ?></div>
  </div>

  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Injuries</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['injuries_count']) ?></div>
  </div>

  <div class="kpi-stat-box">
    <div class="kpi-stat-label">Missing</div>
    <div class="kpi-stat-val"><?= number_format((int)$req['missing_count']) ?></div>
  </div>
</div>

<!-- Main Two-Column Layout -->
<div class="report-grid-two-col">
  <!-- Left Column: Details Form -->
  <div class="card-pane">
    <div class="card-pane-head">
      <h3 class="card-pane-title">Incident Details</h3>
    </div>

    <div class="card-pane-body">
      <form id="incidentReportForm" onsubmit="handleSaveReportForm(event)">
        <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
        <input type="hidden" name="status" id="form_status" value="<?= clean($req['status']) ?>">
        <input type="hidden" name="coordinates_lat" id="form_lat" value="<?= htmlspecialchars($req['coordinates_lat'] ?? $brgyLat) ?>">
        <input type="hidden" name="coordinates_lng" id="form_lng" value="<?= htmlspecialchars($req['coordinates_lng'] ?? $brgyLng) ?>">
        <input type="hidden" name="incident_location" id="form_location" value="<?= htmlspecialchars($req['incident_location'] ?? '') ?>">

        <!-- Row 1: Jurisdiction & Hazard Type -->
        <div class="form-row">
          <div class="form-group" style="flex: 1;">
            <label class="form-label form-label-required">Purok Cluster</label>
            <select name="purok_name" id="inp_purok_name" class="form-control" disabled required onchange="handlePurokChange(this.value)">
              <option value="">Select Purok Cluster</option>
              <option value="All Puroks" data-id="all" <?= ($req['purok_name'] === 'All Puroks') ? 'selected' : '' ?>>All Puroks</option>
              <?php foreach ($localPuroks as $p): ?>
                <option value="<?= clean($p['name']) ?>" data-id="<?= (int)$p['id'] ?>" <?= ($req['purok_name'] === $p['name']) ? 'selected' : '' ?>>
                  <?= clean($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="flex: 1;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
              <label class="form-label form-label-required" style="margin-bottom:0;">Hazard Type</label>
              <button type="button" class="btn btn-outline btn-sm" id="btnAddHazardInline" onclick="toggleDynamicHazardBox(true)"
                style="display:none; padding:1px 6px; font-size:9.5px;">
                + Add Type
              </button>
            </div>
            <select name="disaster_type" id="inp_disaster_type" class="form-control" disabled required>
              <option value="">Select Hazard Type</option>
              <?php foreach ($dynamicHazardTypes as $ht): ?>
                <option value="<?= clean($ht) ?>" <?= (strcasecmp($req['disaster_type'], $ht) === 0) ? 'selected' : '' ?>>
                  <?= clean($ht) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <!-- Inline Dynamic Add Box for Edit Mode -->
            <div id="dynamicHazardBox" style="display:none; margin-top:6px; padding:8px 10px; background:var(--color-surface-subtle); border:1px solid var(--color-border); border-radius:6px;">
              <div style="display:flex; gap:6px;">
                <input type="text" id="dynamic_hazard_input" class="form-control" placeholder="New hazard type..." style="font-size:11px; padding:4px 8px;"
                  onkeydown="if(event.key === 'Enter'){event.preventDefault();submitDynamicHazard();}">
                <button type="button" class="btn btn-primary btn-sm" onclick="submitDynamicHazard()" style="padding:4px 8px; font-size:10px; white-space:nowrap;">
                  Add
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicHazardBox(false)" style="padding:4px 6px; font-size:10px;">
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Row 2: Aid Support, Severity & Urgency -->
        <div class="form-row">
          <div class="form-group" style="flex: 1;">
            <label class="form-label form-label-required">Aid Support Needed</label>
            <select name="aid_support" id="inp_aid_support" class="form-control" disabled required>
              <option value="resources" <?= ($req['aid_support'] === 'resources') ? 'selected' : '' ?>>Relief Resources</option>
              <option value="financial_aid" <?= ($req['aid_support'] === 'financial_aid') ? 'selected' : '' ?>>Financial Aid</option>
              <option value="both" <?= ($req['aid_support'] === 'both') ? 'selected' : '' ?>>Both</option>
            </select>
          </div>

          <div class="form-group" style="flex: 1;">
            <label class="form-label form-label-required">Severity</label>
            <select name="severity" id="inp_severity" class="form-control" disabled required onchange="handleSeverityChange(this.value)">
              <option value="Critical" <?= ($req['severity'] === 'Critical') ? 'selected' : '' ?>>Critical</option>
              <option value="High" <?= ($req['severity'] === 'High') ? 'selected' : '' ?>>High</option>
              <option value="Moderate" <?= ($req['severity'] === 'Moderate') ? 'selected' : '' ?>>Moderate</option>
              <option value="Low" <?= ($req['severity'] === 'Low') ? 'selected' : '' ?>>Low</option>
            </select>
          </div>

          <div class="form-group" style="flex: 1;">
            <label class="form-label form-label-required">Urgency</label>
            <select name="urgency" id="inp_urgency" class="form-control" disabled required>
              <option value="Immediate" <?= ($req['urgency'] === 'Immediate') ? 'selected' : '' ?>>Immediate</option>
              <option value="High" <?= ($req['urgency'] === 'High') ? 'selected' : '' ?>>High</option>
              <option value="Medium" <?= ($req['urgency'] === 'Medium') ? 'selected' : '' ?>>Medium</option>
              <option value="Low" <?= ($req['urgency'] === 'Low') ? 'selected' : '' ?>>Low</option>
            </select>
          </div>
        </div>

        <!-- Population Breakdown Fields -->
        <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: 6px; padding: 12px; margin: 10px 0 14px 0;">
          <div style="font-size: 10px; font-weight: 700; color: var(--color-text-secondary); text-transform: uppercase; margin-bottom: 8px;">
            Casualty &amp; Population Impact
          </div>
          <div class="form-row" style="margin-bottom: 8px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Affected Families</label>
              <input type="number" name="affected_families" id="inp_affected_families" class="form-control" min="0" value="<?= (int)$req['affected_families'] ?>" disabled>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Displaced Families</label>
              <input type="number" name="displaced_families" id="inp_displaced_families" class="form-control" min="0" value="<?= (int)$req['displaced_families'] ?>" disabled>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Affected Individuals</label>
              <input type="number" name="affected_individuals" id="inp_affected_individuals" class="form-control" min="0" value="<?= (int)$req['affected_individuals'] ?>" disabled>
            </div>
          </div>

          <div class="form-row" style="margin-bottom: 0;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Casualties</label>
              <input type="number" name="casualties_count" id="inp_casualties_count" class="form-control" min="0" value="<?= (int)$req['casualties_count'] ?>" disabled>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Injuries</label>
              <input type="number" name="injuries_count" id="inp_injuries_count" class="form-control" min="0" value="<?= (int)$req['injuries_count'] ?>" disabled>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 10.5px;">Missing</label>
              <input type="number" name="missing_count" id="inp_missing_count" class="form-control" min="0" value="<?= (int)$req['missing_count'] ?>" disabled>
            </div>
          </div>
        </div>

        <!-- Ground Situation Overview -->
        <div class="form-group">
          <label class="form-label">Ground Situation Overview</label>
          <textarea name="situation_overview" id="inp_situation_overview" class="form-control" rows="3" disabled
            placeholder="Ground situation narrative..."><?= htmlspecialchars($req['situation_overview'] ?? '') ?></textarea>
        </div>

        <!-- Requested Assistance -->
        <div class="form-group">
          <label class="form-label">Requested Assistance</label>
          <textarea name="requested_assistance" id="inp_requested_assistance" class="form-control" rows="3" disabled
            placeholder="Requested relief or rescue assistance..."><?= htmlspecialchars($req['requested_assistance'] ?? '') ?></textarea>
        </div>

        <!-- Resolution Notes -->
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Resolution &amp; Accomplishment Notes</label>
          <textarea name="resolution_notes" id="inp_resolution_notes" class="form-control" rows="2" disabled
            placeholder="Resolution notes upon conclusion..."><?= htmlspecialchars($req['resolution_notes'] ?? '') ?></textarea>
        </div>

        <!-- In-Form Action Buttons (Shown when editing) -->
        <div id="formActionsBottom" class="no-print" style="display: none; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border); justify-content: flex-end; gap: 6px;">
          <button type="button" class="btn btn-outline btn-sm" onclick="toggleEditMode(false)">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm" id="btnSaveBottom">
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Right Column: Location & GIS Map -->
  <div class="card-pane">
    <div class="card-pane-head">
      <h3 class="card-pane-title">Location &amp; Map</h3>

      <!-- Clean Tile Switcher (No Emojis) -->
      <div class="no-print" style="display: flex; gap: 4px; align-items: center;">
        <div class="btn-group" style="display: inline-flex; border-radius: 4px; overflow: hidden; border: 1px solid var(--color-border);">
          <button type="button" class="btn btn-sm map-tile-btn active" id="btnTileOsm" onclick="switchMapTile('osm')">
            Streets
          </button>
          <button type="button" class="btn btn-sm map-tile-btn" id="btnTileSat" onclick="switchMapTile('satellite')">
            Satellite
          </button>
        </div>
      </div>
    </div>

    <div class="card-pane-body">
      <!-- Leaflet Map Container -->
      <div id="incidentDetailsMap" class="details-map-picker"></div>

      <!-- Location Input & Coordinates -->
      <div style="margin-top: 12px;">
        <div class="form-group" style="margin-bottom: 6px;">
          <label class="form-label" style="font-size: 10px; margin-bottom: 2px;">Street / Landmark Location</label>
          <input type="text" id="inp_incident_location" class="form-control" style="font-size: 11px;"
            value="<?= htmlspecialchars($req['incident_location'] ?? '') ?>" disabled
            placeholder="Landmark or street location"
            oninput="syncLocationToForm(this.value)">
        </div>

        <div class="form-row" style="margin-bottom: 0;">
          <div class="form-group" style="margin-bottom: 0; flex: 1;">
            <label class="form-label" style="font-size: 10px; margin-bottom: 2px;">Latitude</label>
            <input type="number" step="0.000001" id="inp_lat" class="form-control" style="font-size: 11px; font-family: var(--font-secondary);"
              value="<?= htmlspecialchars(number_format($reportLat, 6, '.', '')) ?>" disabled
              onchange="handleManualCoordinatesChange()">
          </div>
          <div class="form-group" style="margin-bottom: 0; flex: 1;">
            <label class="form-label" style="font-size: 10px; margin-bottom: 2px;">Longitude</label>
            <input type="number" step="0.000001" id="inp_lng" class="form-control" style="font-size: 11px; font-family: var(--font-secondary);"
              value="<?= htmlspecialchars(number_format($reportLng, 6, '.', '')) ?>" disabled
              onchange="handleManualCoordinatesChange()">
          </div>
        </div>

        <!-- 360° Street View Button -->
        <div class="no-print" style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border);">
          <button type="button" class="btn btn-outline btn-sm" onclick="openStreetViewModal()"
            style="width: 100%; display: inline-flex; justify-content: center; align-items: center; gap: 6px; padding: 7px 12px; font-size: 11px; font-weight: 600;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="5" r="3"></circle>
              <path d="M12 8v8"></path>
              <path d="M9 21l3-5 3 5"></path>
              <path d="M6 11l6-2 6 2"></path>
            </svg>
            360° Street View
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal: 360° Street View Inspector                                        -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="streetViewModal" style="z-index: 10001;">
  <div class="modal-dialog modal-dialog-lg" style="max-width: 820px; width: 95%;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <h3 class="modal-title" style="margin: 0; font-size: 13px;">
          Street View (360°) &bull; <?= clean($req['tracking_code']) ?>
        </h3>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('streetViewModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding: 0; background: #000000;">
      <div style="position: relative; width: 100%; height: 420px; overflow: hidden;">
        <iframe id="streetViewIframe" src="" width="100%" height="100%" style="border: 0;" allowfullscreen loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
    </div>

    <div class="modal-footer" style="padding: 10px 16px; display: flex; justify-content: flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('streetViewModal')">Close</button>
    </div>
  </div>
</div>

<script>
  const REPORT_ID = <?= (int)$req['id'] ?>;
  const BRGY_ID = <?= (int)$barangayId ?>;
  const TRACKING_CODE = <?= json_encode($req['tracking_code']) ?>;
  const BRGY_CENTER_LAT = <?= $brgyLat ?>;
  const BRGY_CENTER_LNG = <?= $brgyLng ?>;

  let currentLat = <?= $reportLat ?>;
  let currentLng = <?= $reportLng ?>;
  let isEditMode = false;

  let detailsMap = null;
  let detailsMarker = null;
  let tileOsm = null;
  let tileSat = null;

  const purokHazardOptionsMap = <?= json_encode($purokSpecificHazards) ?>;
  const allBarangayHazards = <?= json_encode($dynamicHazardTypes) ?>;

  function initDetailsMap() {
    const container = document.getElementById('incidentDetailsMap');
    if (!container || detailsMap) return;

    detailsMap = L.map('incidentDetailsMap', {
      center: [currentLat, currentLng],
      zoom: 16,
      zoomControl: true
    });

    tileOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(detailsMap);

    tileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19,
      attribution: 'Tiles &copy; Esri'
    });

    const severityVal = document.getElementById('inp_severity')?.value || 'Moderate';
    detailsMarker = L.marker([currentLat, currentLng], {
      draggable: isEditMode,
      icon: getHazardMarkerIcon(severityVal)
    }).addTo(detailsMap);

    detailsMarker.bindPopup(`<strong>${escapeHtml(TRACKING_CODE)}</strong><br><span style="font-size:10px;color:#64748B;">${currentLat.toFixed(6)}, ${currentLng.toFixed(6)}</span>`).openPopup();

    detailsMarker.on('dragend', function (e) {
      if (!isEditMode) return;
      const pos = e.target.getLatLng();
      updateCoordinates(pos.lat, pos.lng, true);
    });

    detailsMap.on('click', function (e) {
      if (!isEditMode) return;
      const pos = e.latlng;
      detailsMarker.setLatLng(pos);
      updateCoordinates(pos.lat, pos.lng, true);
    });
  }

  function getHazardMarkerIcon(severity) {
    let color = '#2563EB';
    if (severity === 'Critical') color = '#DC2626';
    else if (severity === 'High') color = '#D97706';
    else if (severity === 'Completed' || severity === 'Low') color = '#047857';

    const html = `
      <div class="custom-hazard-marker" style="background-color: ${color};">
        <div class="custom-hazard-marker-inner">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
          </svg>
        </div>
      </div>
    `;

    return L.divIcon({
      className: 'hazard-div-icon',
      html: html,
      iconSize: [30, 30],
      iconAnchor: [15, 30],
      popupAnchor: [0, -30]
    });
  }

  function switchMapTile(type) {
    if (!detailsMap || !tileOsm || !tileSat) return;
    const btnOsm = document.getElementById('btnTileOsm');
    const btnSat = document.getElementById('btnTileSat');

    if (type === 'satellite') {
      detailsMap.removeLayer(tileOsm);
      detailsMap.addLayer(tileSat);
      btnOsm.classList.remove('active');
      btnSat.classList.add('active');
    } else {
      detailsMap.removeLayer(tileSat);
      detailsMap.addLayer(tileOsm);
      btnSat.classList.remove('active');
      btnOsm.classList.add('active');
    }
  }

  function updateCoordinates(lat, lng, doReverseGeocode) {
    currentLat = parseFloat(lat);
    currentLng = parseFloat(lng);

    document.getElementById('inp_lat').value = currentLat.toFixed(6);
    document.getElementById('inp_lng').value = currentLng.toFixed(6);
    document.getElementById('form_lat').value = currentLat.toFixed(6);
    document.getElementById('form_lng').value = currentLng.toFixed(6);

    if (detailsMarker) {
      detailsMarker.getPopup().setContent(`<strong>${escapeHtml(TRACKING_CODE)}</strong><br><span style="font-size:10px;color:#64748B;">${currentLat.toFixed(6)}, ${currentLng.toFixed(6)}</span>`);
    }

    if (doReverseGeocode) {
      reverseGeocodePin(currentLat, currentLng);
    }
  }

  function handleManualCoordinatesChange() {
    const lat = parseFloat(document.getElementById('inp_lat').value);
    const lng = parseFloat(document.getElementById('inp_lng').value);
    if (isNaN(lat) || isNaN(lng)) return;

    updateCoordinates(lat, lng, false);
    if (detailsMarker && detailsMap) {
      detailsMarker.setLatLng([lat, lng]);
      detailsMap.panTo([lat, lng]);
    }
  }

  function syncLocationToForm(val) {
    const formLoc = document.getElementById('form_location');
    if (formLoc) formLoc.value = val;
  }

  async function reverseGeocodePin(lat, lng) {
    const locInput = document.getElementById('inp_incident_location');
    if (!locInput) return;

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
          const locStr = parts.join(', ');
          locInput.value = locStr;
          syncLocationToForm(locStr);
        }
      }
    } catch (e) {
      console.log('Geocoding notice:', e);
    }
  }

  function toggleEditMode(forceState) {
    isEditMode = (forceState !== undefined) ? forceState : !isEditMode;

    const fields = [
      'inp_purok_name', 'inp_disaster_type', 'inp_aid_support', 'inp_severity', 'inp_urgency',
      'inp_affected_families', 'inp_displaced_families', 'inp_affected_individuals',
      'inp_casualties_count', 'inp_injuries_count', 'inp_missing_count',
      'inp_situation_overview', 'inp_requested_assistance', 'inp_resolution_notes',
      'inp_incident_location', 'inp_lat', 'inp_lng'
    ];

    fields.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = !isEditMode;
    });

    if (detailsMarker) {
      if (isEditMode) detailsMarker.dragging.enable();
      else detailsMarker.dragging.disable();
    }

    const btnToggleText = document.getElementById('btnToggleEditText');
    const btnSaveTop = document.getElementById('btnSaveTop');
    const formActionsBottom = document.getElementById('formActionsBottom');
    const btnAddHazard = document.getElementById('btnAddHazardInline');

    if (isEditMode) {
      if (btnToggleText) btnToggleText.innerText = 'Cancel';
      if (btnSaveTop) btnSaveTop.style.display = 'inline-flex';
      if (formActionsBottom) formActionsBottom.style.display = 'flex';
      if (btnAddHazard) btnAddHazard.style.display = 'inline-flex';
    } else {
      if (btnToggleText) btnToggleText.innerText = 'Edit Report';
      if (btnSaveTop) btnSaveTop.style.display = 'none';
      if (formActionsBottom) formActionsBottom.style.display = 'none';
      if (btnAddHazard) btnAddHazard.style.display = 'none';
      toggleDynamicHazardBox(false);
    }
  }

  function handleSeverityChange(sev) {
    if (detailsMarker) {
      detailsMarker.setIcon(getHazardMarkerIcon(sev));
    }
  }

  function handlePurokChange(purokName) {
    if (!purokName) return;
    const selectElem = document.getElementById('inp_disaster_type');
    const purokSelect = document.getElementById('inp_purok_name');
    if (!selectElem || !purokSelect) return;

    const selectedOpt = purokSelect.options[purokSelect.selectedIndex];
    const purokId = selectedOpt ? selectedOpt.getAttribute('data-id') : null;
    const currentVal = selectElem.value;
    selectElem.innerHTML = '<option value="">Select Hazard Type</option>';

    if (purokName === 'All Puroks' || purokId === 'all') {
      allBarangayHazards.forEach(h => {
        const opt = new Option(h, h);
        if (h === currentVal) opt.selected = true;
        selectElem.add(opt);
      });
      return;
    }

    const hazardsForPurok = (purokId && purokHazardOptionsMap[purokId]) ? purokHazardOptionsMap[purokId] : [];
    const listToRender = (hazardsForPurok.length > 0) ? hazardsForPurok : allBarangayHazards;
    listToRender.forEach(h => {
      const opt = new Option(h, h);
      if (h === currentVal) opt.selected = true;
      selectElem.add(opt);
    });

    if (hazardsForPurok.length === 1 && !currentVal) {
      selectElem.value = hazardsForPurok[0];
    }
  }

  function toggleDynamicHazardBox(show) {
    const box = document.getElementById('dynamicHazardBox');
    const input = document.getElementById('dynamic_hazard_input');
    if (!box) return;
    box.style.display = show ? 'block' : 'none';
    if (show && input) {
      input.value = '';
      setTimeout(() => input.focus(), 80);
    }
  }

  async function submitDynamicHazard() {
    const input = document.getElementById('dynamic_hazard_input');
    if (!input) return;
    const rawVal = input.value.trim();
    if (!rawVal) {
      alert('Please enter a hazard name.');
      input.focus();
      return;
    }

    const purokSelect = document.getElementById('inp_purok_name');
    const selectedPurokOpt = purokSelect && purokSelect.selectedIndex > 0 ? purokSelect.options[purokSelect.selectedIndex] : null;
    const purokVal = purokSelect ? purokSelect.value : '';
    const purokIdAttr = selectedPurokOpt ? selectedPurokOpt.getAttribute('data-id') : null;
    const isAllPuroks = purokVal === 'All Puroks' || purokIdAttr === 'all';
    const purokId = isAllPuroks ? '' : (purokIdAttr || '');

    if (!purokVal) {
      alert('Please select a Purok first.');
      if (purokSelect) purokSelect.focus();
      return;
    }

    const formData = new FormData();
    formData.append('name', rawVal);
    formData.append('purok_id', purokId);
    formData.append('barangay_id', BRGY_ID);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/add_hazard_option.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        const optName = data.name || rawVal;
        const select = document.getElementById('inp_disaster_type');
        if (select) {
          let exists = false;
          for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value.toLowerCase() === optName.toLowerCase()) {
              select.selectedIndex = i;
              exists = true;
              break;
            }
          }
          if (!exists) {
            const opt = new Option(optName, optName, true, true);
            select.add(opt);
          }
        }
        toggleDynamicHazardBox(false);
        if (typeof showToast === 'function') {
          showToast(`Hazard "${optName}" added.`, 'success');
        }
      } else {
        alert(data.message || 'Error saving hazard type.');
      }
    } catch (e) {
      alert('Network error while saving hazard type.');
    }
  }

  function submitReportForm() {
    const form = document.getElementById('incidentReportForm');
    if (form) form.requestSubmit();
  }

  async function handleSaveReportForm(e) {
    e.preventDefault();
    const form = e.target;
    const btnSaveTop = document.getElementById('btnSaveTop');
    const btnSaveBottom = document.getElementById('btnSaveBottom');

    if (btnSaveTop) { btnSaveTop.disabled = true; btnSaveTop.innerText = 'Saving...'; }
    if (btnSaveBottom) { btnSaveBottom.disabled = true; btnSaveBottom.innerText = 'Saving...'; }

    try {
      const formData = new FormData(form);
      formData.append('coordinates_lat', document.getElementById('inp_lat').value);
      formData.append('coordinates_lng', document.getElementById('inp_lng').value);
      formData.append('incident_location', document.getElementById('inp_incident_location').value);

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
        }
        setTimeout(() => location.reload(), 600);
      } else {
        alert(data.message || 'Failed to update report.');
        if (btnSaveTop) { btnSaveTop.disabled = false; btnSaveTop.innerText = 'Save Changes'; }
        if (btnSaveBottom) { btnSaveBottom.disabled = false; btnSaveBottom.innerText = 'Save Changes'; }
      }
    } catch (err) {
      alert('Network error while saving report.');
      if (btnSaveTop) { btnSaveTop.disabled = false; btnSaveTop.innerText = 'Save Changes'; }
      if (btnSaveBottom) { btnSaveBottom.disabled = false; btnSaveBottom.innerText = 'Save Changes'; }
    }
  }

  async function handleFinishIncident() {
    const notes = prompt(`Conclude operations for Incident ${TRACKING_CODE}?\n\nEnter optional Accomplishment / Resolution Notes:`, document.getElementById('inp_resolution_notes')?.value || 'Incident operations concluded.');
    if (notes === null) return;

    const formData = new FormData();
    formData.append('request_id', REPORT_ID);
    formData.append('status', 'Completed');
    formData.append('resolution_notes', notes);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update_status.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Incident marked as Completed.', 'success');
        }
        setTimeout(() => location.reload(), 600);
      } else {
        alert(data.message || 'Failed to finish incident.');
      }
    } catch (e) {
      alert('Network error while concluding incident.');
    }
  }

  async function handleReopenIncident() {
    if (!confirm(`Set status back to Ongoing for Incident ${TRACKING_CODE}?`)) return;

    const formData = new FormData();
    formData.append('request_id', REPORT_ID);
    formData.append('status', 'Ongoing');

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update_status.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Incident status set to Ongoing.', 'success');
        }
        setTimeout(() => location.reload(), 600);
      } else {
        alert(data.message || 'Failed to reopen incident.');
      }
    } catch (e) {
      alert('Network error while reopening incident.');
    }
  }

  async function handleDeleteReport() {
    if (!confirm(`Are you sure you want to permanently delete Incident ${TRACKING_CODE}?`)) return;

    const formData = new FormData();
    formData.append('id', REPORT_ID);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/delete.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        alert(data.message || 'Incident report deleted.');
        window.location.href = '<?= BASE_URL ?>/views/barangay/disaster-reports.php';
      } else {
        alert(data.message || 'Error deleting incident report.');
      }
    } catch (e) {
      alert('Network error while deleting incident report.');
    }
  }

  function openStreetViewModal() {
    const iframe = document.getElementById('streetViewIframe');
    if (iframe) {
      iframe.src = `https://maps.google.com/maps?q=${currentLat},${currentLng}&layer=c&cbll=${currentLat},${currentLng}&cbp=11,0,0,0,0&output=svembed`;
    }
    openModal('streetViewModal');
  }

  document.addEventListener('DOMContentLoaded', () => {
    initDetailsMap();
  });
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
