<?php
// ============================================================================
// Views (ICDRRMO): City-Wide Disaster Reports of Barangays Module
// Features: Full CRUD with in-place View/Edit mode, city-wide 44 barangays
// jurisdiction, Decision Tree Recommender integration, active telemetry,
// status progression (Ongoing / Completed), clean anti-slop typography & colors.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

// 1. Fetch All Active Barangays (44 Official Barangays of Iligan City)
$bStmt = $db->query("SELECT id, name, risk_level, population, total_households FROM barangays WHERE status = 'active' ORDER BY name ASC");
$allBarangays = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Puroks and Clusters grouped by Barangay ID for instant dynamic JS dropdowns
$purokStmt = $db->query("
    SELECT p.id, p.barangay_id, p.name, p.hazard_types, p.risk_level, b.name AS barangay_name
    FROM puroks p
    JOIN barangays b ON p.barangay_id = b.id
    WHERE (p.status != 'archived' OR p.status IS NULL)
    ORDER BY b.name ASC, p.name ASC
");
$allPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

$puroksByBarangay = [];
$purokHazardMap = [];
foreach ($allPuroks as $p) {
  $bId = (int) $p['barangay_id'];
  $puroksByBarangay[$bId][] = [
    'id' => (int) $p['id'],
    'name' => $p['name'],
    'hazard_types' => $p['hazard_types'] ?? '',
    'risk_level' => $p['risk_level'] ?? 'Moderate'
  ];
  $purokHazardMap[$p['name']] = !empty($p['hazard_types']) ? $p['hazard_types'] : '';
}

// Also load custom cluster options
try {
  $clusterStmt = $db->query("SELECT id, barangay_id, name FROM purok_cluster_options ORDER BY name ASC");
  $customClusters = $clusterStmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($customClusters as $cc) {
    $bId = (int) ($cc['barangay_id'] ?? 0);
    if ($bId > 0) {
      $exists = false;
      if (isset($puroksByBarangay[$bId])) {
        foreach ($puroksByBarangay[$bId] as $item) {
          if (strcasecmp($item['name'], $cc['name']) === 0) {
            $exists = true;
            break;
          }
        }
      }
      if (!$exists) {
        $puroksByBarangay[$bId][] = [
          'id' => (int) $cc['id'],
          'name' => $cc['name'],
          'hazard_types' => '',
          'risk_level' => 'Moderate'
        ];
      }
    }
  }
} catch (Exception $e) {
  // Graceful fallback
}

// 3. Dynamically extract Hazard Types from database
$dynamicHazardTypes = [];
foreach ($allPuroks as $p) {
  if (!empty($p['hazard_types'])) {
    foreach (explode(',', $p['hazard_types']) as $ht) {
      $trim = trim($ht);
      if ($trim !== '' && !in_array($trim, $dynamicHazardTypes, true)) {
        $dynamicHazardTypes[] = $trim;
      }
    }
  }
}

try {
  $hazOptionsStmt = $db->query("SELECT name FROM hazard_type_options ORDER BY name ASC");
  $hazOptions = $hazOptionsStmt->fetchAll(PDO::FETCH_COLUMN);
  foreach ($hazOptions as $ho) {
    $trim = trim($ho);
    if ($trim !== '' && !in_array($trim, $dynamicHazardTypes, true)) {
      $dynamicHazardTypes[] = $trim;
    }
  }
} catch (Exception $e) {
}

$coreHazards = ['Flood', 'Flash Flood', 'Typhoon', 'Landslide', 'Fire', 'Earthquake', 'Storm Surge'];
foreach ($coreHazards as $ch) {
  if (!in_array($ch, $dynamicHazardTypes, true)) {
    $dynamicHazardTypes[] = $ch;
  }
}
natcasesort($dynamicHazardTypes);
$dynamicHazardTypes = array_values($dynamicHazardTypes);

// 4. View Tabs & Filter Parameters
$viewTab = $_GET['tab'] ?? 'all';
if (!in_array($viewTab, ['all', 'ongoing', 'needs_recommender', 'completed'], true)) {
  $viewTab = 'all';
}

$filterBarangay = (int) ($_GET['barangay'] ?? 0);
$filterType = trim($_GET['type'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterUrgency = trim($_GET['urgency'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// 5. Dynamic Counts for Tabs & KPI Telemetry
$countAll = (int) $db->query("SELECT COUNT(*) FROM disaster_reports")->fetchColumn();
$countOngoing = (int) $db->query("SELECT COUNT(*) FROM disaster_reports WHERE status = 'Ongoing'")->fetchColumn();
$countNeedsRec = (int) $db->query("
    SELECT COUNT(*) FROM disaster_reports dr 
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id 
    WHERE (r.id IS NULL OR r.status = 'Pending Review') AND dr.status NOT IN ('Completed', 'Rejected')
")->fetchColumn();
$countCompleted = (int) $db->query("SELECT COUNT(*) FROM disaster_reports WHERE status = 'Completed'")->fetchColumn();

// Telemetry Aggregates (respecting filtered barangay if specified)
$statSql = "
    SELECT 
        COALESCE(SUM(affected_families), 0) AS total_affected_families,
        COALESCE(SUM(affected_individuals), 0) AS total_affected_individuals,
        COALESCE(SUM(displaced_families), 0) AS total_displaced_families,
        COALESCE(SUM(casualties_count), 0) AS total_casualties,
        COALESCE(SUM(injuries_count), 0) AS total_injuries,
        COALESCE(SUM(missing_count), 0) AS total_missing
    FROM disaster_reports
";
$statParams = [];
if ($filterBarangay > 0) {
  $statSql .= " WHERE barangay_id = ?";
  $statParams[] = $filterBarangay;
}
$statStmt = $db->prepare($statSql);
$statStmt->execute($statParams);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC);

// 6. Base Incident Reports Query (Joined to latest recommendation per incident)
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.recommendation_code, r.status AS recommendation_status,
           r.confidence_score, r.priority AS recommendation_priority,
           r.decision_path, r.modality, r.financial_aid_source,
           r.financial_aid_recommended_budget, r.financial_aid_per_family, r.financial_aid_target_families,
           r.financial_aid_allocation_id
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    LEFT JOIN users u ON dr.submitted_by = u.id
    LEFT JOIN (
        SELECT r1.*
        FROM recommendations r1
        INNER JOIN (
            SELECT disaster_request_id, MAX(id) AS max_id
            FROM recommendations
            GROUP BY disaster_request_id
        ) r_latest ON r1.id = r_latest.max_id
    ) r ON dr.id = r.disaster_request_id
    WHERE 1=1
";
$params = [];

if ($viewTab === 'ongoing') {
  $sql .= " AND dr.status = 'Ongoing'";
} elseif ($viewTab === 'needs_recommender') {
  $sql .= " AND (r.id IS NULL OR r.status = 'Pending Review') AND dr.status NOT IN ('Completed', 'Rejected')";
} elseif ($viewTab === 'completed') {
  $sql .= " AND dr.status = 'Completed'";
}

if ($filterBarangay > 0) {
  $sql .= " AND dr.barangay_id = ?";
  $params[] = $filterBarangay;
}

if (!empty($filterType)) {
  $sql .= " AND dr.disaster_type = ?";
  $params[] = $filterType;
}

if (!empty($filterSeverity)) {
  $sql .= " AND dr.severity = ?";
  $params[] = $filterSeverity;
}

if (!empty($filterUrgency)) {
  $sql .= " AND dr.urgency = ?";
  $params[] = $filterUrgency;
}

if (!empty($filterStatus)) {
  $sql .= " AND dr.status = ?";
  $params[] = $filterStatus;
}

if (!empty($search)) {
  $sql .= " AND (dr.tracking_code LIKE ? OR b.name LIKE ? OR dr.purok_name LIKE ? OR dr.requested_assistance LIKE ? OR dr.situation_overview LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY FIELD(dr.status, 'Ongoing', 'Completed'), dr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Disaster Reports of Barangays — ICDRRMO";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<!-- Leaflet GIS Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
  /* High-clarity disabled inputs for View Mode (matching manage users & residents) */
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

  /* Minimal KPI Summary Cards adhering strictly to palette */
  .kpi-stat-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-primary, 10px);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    position: relative;
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }

  .kpi-stat-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(23, 50, 77, 0.08);
  }

  .kpi-stat-card.active-card,
  .kpi-stat-card.completed-card,
  .kpi-stat-card.displaced-card {
    border-left: 1px solid var(--color-border, #D9E0E3);
  }

  /* Restrained pulse indicator for active ongoing incidents */
  .badge-ongoing-pulse {
    background: rgba(183, 129, 3, 0.12);
    color: #8C6202;
    border: 1px solid rgba(183, 129, 3, 0.32);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 10px;
  }

  .badge-ongoing-pulse::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--color-warning, #B78103);
    box-shadow: 0 0 0 0 rgba(183, 129, 3, 0.6);
    animation: pulse-ring 2s infinite;
  }

  @keyframes pulse-ring {
    0% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(183, 129, 3, 0.6);
    }

    70% {
      transform: scale(1.1);
      box-shadow: 0 0 0 5px rgba(183, 129, 3, 0);
    }

    100% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(183, 129, 3, 0);
    }
  }

  /* Navigation Tabs */
  .report-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-secondary, #66737D);
    border-bottom: 2px solid transparent;
    text-decoration: none;
    transition: all 0.15s ease;
  }

  .report-tab-link:hover {
    color: var(--color-primary, #17324D);
  }

  .report-tab-link.active {
    color: var(--color-primary, #17324D);
    border-bottom-color: var(--color-primary, #17324D);
    font-weight: 600;
  }

  /* Modals Setup */
  #viewReportModal.modal-overlay,
  #createReportModal.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.48) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }

  #viewReportModal.modal-overlay.active,
  #createReportModal.modal-overlay.active {
    display: flex !important;
  }

  #viewReportModal .modal-dialog,
  #createReportModal .modal-dialog {
    max-width: 740px !important;
    width: 95% !important;
    max-height: calc(100vh - 36px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
    position: relative !important;
  }

  #viewReportModal .form-control:disabled,
  #viewReportModal .form-control[disabled] {
    background-color: var(--color-surface-subtle) !important;
    color: var(--color-text) !important;
    border-color: var(--color-border) !important;
    opacity: 1 !important;
    cursor: default !important;
  }

  #viewReportModal .modal-header,
  #createReportModal .modal-header {
    flex-shrink: 0 !important;
    padding: 12px 18px !important;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  #viewReportModal .modal-body,
  #createReportModal .modal-body {
    overflow-y: auto !important;
    padding: 16px 20px !important;
    flex: 1 1 auto !important;
  }

  #viewReportModal .modal-footer,
  #createReportModal .modal-footer {
    flex-shrink: 0 !important;
    padding: 12px 18px !important;
    border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
    background: var(--color-surface, #FFFFFF) !important;
  }

  /* Force persistent crisp white text on AI Recommendation Action button in all states */
  #viewModalHeaderRecommenderAction .btn-primary,
  #viewModalHeaderRecommenderAction a.btn-primary,
  #viewModalHeaderRecommenderAction .btn-primary:hover,
  #viewModalHeaderRecommenderAction a.btn-primary:hover,
  #viewModalHeaderRecommenderAction .btn-primary:focus,
  #viewModalHeaderRecommenderAction a.btn-primary:focus {
    color: #FFFFFF !important;
    text-decoration: none !important;
  }

  #viewModalHeaderRecommenderAction .btn-primary:hover,
  #viewModalHeaderRecommenderAction a.btn-primary:hover {
    background-color: var(--color-primary-dark, #0D1C2B) !important;
    color: #FFFFFF !important;
  }

  /* Leaflet Incident Pin & Pulse Markers */
  .hazard-div-icon {
    background: transparent !important;
    border: none !important;
  }

  .hazard-svg-pin {
    position: relative;
    width: 34px;
    height: 42px;
    cursor: pointer;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  }

  .hazard-svg-pin:hover {
    transform: translateY(-4px) scale(1.08);
  }

  .hazard-ground-pulse {
    position: absolute;
    bottom: -3px;
    left: 50%;
    transform: translateX(-50%);
    width: 14px;
    height: 6px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.25);
    z-index: -1;
    pointer-events: none;
  }

  .marker-pulse-critical .hazard-ground-pulse {
    animation: ground-pulse-red 2s infinite ease-out;
  }

  .marker-pulse-high .hazard-ground-pulse {
    animation: ground-pulse-amber 2s infinite ease-out;
  }

  @keyframes ground-pulse-red {
    0% {
      transform: translateX(-50%) scale(0.6);
      opacity: 0.9;
      box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.8);
    }
    70% {
      transform: translateX(-50%) scale(2.6);
      opacity: 0;
      box-shadow: 0 0 0 14px rgba(239, 68, 68, 0);
    }
    100% {
      transform: translateX(-50%) scale(0.6);
      opacity: 0;
    }
  }

  @keyframes ground-pulse-amber {
    0% {
      transform: translateX(-50%) scale(0.6);
      opacity: 0.9;
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.8);
    }
    70% {
      transform: translateX(-50%) scale(2.6);
      opacity: 0;
      box-shadow: 0 0 0 14px rgba(245, 158, 11, 0);
    }
    100% {
      transform: translateX(-50%) scale(0.6);
      opacity: 0;
    }
  }

  /* Leaflet Popup Styling - prevent overlaps and improve readability */
  .leaflet-popup-content-wrapper {
    border-radius: 8px !important;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18) !important;
    border: 1px solid var(--color-border, #CBD5E1) !important;
    padding: 0 !important;
  }

  .leaflet-popup-content {
    margin: 10px 16px 10px 12px !important;
    line-height: 1.45 !important;
  }

  .leaflet-popup-close-button {
    top: 7px !important;
    right: 7px !important;
    color: #64748B !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    padding: 2px !important;
  }

  .leaflet-popup-close-button:hover {
    color: #0F172A !important;
  }

  #btnViewModalStreetView:hover {
    background-color: #1D4ED8 !important;
    border-color: #1D4ED8 !important;
    color: #FFFFFF !important;
  }
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Disaster Reports of Barangays</h1>
      <p class="page-header-desc">
        Central monitoring and review of disaster incident reports, population impact evaluations, and Decision Tree
        resource allocation across all 44 barangays of Iligan City.
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-primary" onclick="openCreateReportModal()">
        File Incident Report
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Cards -->
  <div
    style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--space-3, 10px);margin-bottom:var(--space-4, 12px);">
    <div class="kpi-stat-card">
      <div
        style="font-size:9.5px;font-weight:600;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;">
        Total Filed Reports</div>
      <div style="font-size:20px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">
        <?= number_format($countAll) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Across all city barangays</div>
    </div>
    <div class="kpi-stat-card active-card">
      <div
        style="font-size:9.5px;font-weight:600;color:#8C6202;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:4px;">
        <span
          style="display:inline-block;width:5px;height:5px;border-radius:50%;background:var(--color-warning);"></span>
        Active / Ongoing
      </div>
      <div style="font-size:20px;font-weight:700;color:#8C6202;font-family:var(--font-secondary);">
        <?= number_format($countOngoing) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Incidents actively responding</div>
    </div>
    <div class="kpi-stat-card">
      <div
        style="font-size:9.5px;font-weight:600;color:var(--color-secondary);text-transform:uppercase;letter-spacing:0.5px;">
        Needs Recommendation</div>
      <div style="font-size:20px;font-weight:700;color:var(--color-secondary);font-family:var(--font-secondary);">
        <?= number_format($countNeedsRec) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Awaiting Decision Tree run</div>
    </div>
    <div class="kpi-stat-card completed-card">
      <div style="font-size:9.5px;font-weight:600;color:#1E5E22;text-transform:uppercase;letter-spacing:0.5px;">
        Concluded / Completed</div>
      <div style="font-size:20px;font-weight:700;color:#1E5E22;font-family:var(--font-secondary);">
        <?= number_format($countCompleted) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Closed operations</div>
    </div>
    <div class="kpi-stat-card displaced-card">
      <div style="font-size:9.5px;font-weight:600;color:#9B1C1C;text-transform:uppercase;letter-spacing:0.5px;">
        Displaced Families</div>
      <div style="font-size:20px;font-weight:700;color:#9B1C1C;font-family:var(--font-secondary);">
        <?= number_format($stats['total_displaced_families']) ?>
      </div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">
        <?= number_format($stats['total_affected_individuals']) ?> affected residents
      </div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div
    style="display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--color-border);margin-bottom:var(--space-3, 10px);">
    <a href="?tab=all<?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'all' ? 'active' : '' ?>">
      All Incident Reports
      <span class="badge badge-neutral" style="font-size:9.5px;padding:2px 6px;"><?= $countAll ?></span>
    </a>
    <a href="?tab=ongoing<?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'ongoing' ? 'active' : '' ?>">
      Active / Ongoing
      <span class="badge badge-warning" style="font-size:9.5px;padding:2px 6px;"><?= $countOngoing ?></span>
    </a>
    <a href="?tab=needs_recommender<?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'needs_recommender' ? 'active' : '' ?>">
      Needs Recommendation
      <span class="badge badge-info" style="font-size:9.5px;padding:2px 6px;"><?= $countNeedsRec ?></span>
    </a>
    <a href="?tab=completed<?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
      class="report-tab-link <?= $viewTab === 'completed' ? 'active' : '' ?>">
      Concluded / Completed
      <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;"><?= $countCompleted ?></span>
    </a>
  </div>

  <!-- Filter & Search Bar -->
  <div class="card" style="margin-bottom: var(--space-4, 12px);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar"
        style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">
        <div class="filter-group" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
          <div class="search-input-wrap" style="min-width:220px;flex:1;">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Search tracking #, barangay, purok..."
              value="<?= clean($search) ?>">
          </div>

          <select name="barangay" class="form-control" style="width:160px;">
            <option value="">All 44 Barangays</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay === (int) $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="type" class="form-control" style="width:130px;">
            <option value="">All Hazards</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>" <?= $filterType === $ht ? 'selected' : '' ?>><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="severity" class="form-control" style="width:120px;">
            <option value="">All Severity</option>
            <option value="Critical" <?= $filterSeverity === 'Critical' ? 'selected' : '' ?>>Critical</option>
            <option value="High" <?= $filterSeverity === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Moderate" <?= $filterSeverity === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
            <option value="Low" <?= $filterSeverity === 'Low' ? 'selected' : '' ?>>Low</option>
          </select>

          <?php if ($viewTab === 'all'): ?>
            <select name="status" class="form-control" style="width:130px;">
              <option value="">All Statuses</option>
              <option value="Ongoing" <?= $filterStatus === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
              <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline" style="padding:6px 12px;">Filter</button>
          <?php if (!empty($search) || !empty($filterBarangay) || !empty($filterType) || !empty($filterSeverity) || !empty($filterStatus)): ?>
            <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline"
              style="color:var(--color-text-muted);padding:6px 12px;">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10px;color:var(--color-text-muted);font-weight:600;">
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
          <th>Incident #</th>
          <th>Hazard</th>
          <th>Barangay</th>
          <th>Purok</th>
          <th>Reported By</th>
          <th>Severity</th>
          <th>Urgency</th>
          <th>Aid Support</th>
          <th>Impact</th>
          <th>Status</th>
          <th>Date Filed</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="12" style="text-align:center;padding:36px;color:var(--color-text-muted);">
              No disaster reports found matching the selected criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <!-- Incident # -->
              <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);font-size:11.5px;white-space:nowrap;">
                <?= clean($r['tracking_code']) ?>
              </td>

              <!-- Hazard -->
              <td>
                <span class="badge badge-neutral" style="font-size:9.5px;font-weight:600;"><?= clean($r['disaster_type']) ?></span>
              </td>

              <!-- Barangay -->
              <td>
                <div style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                  <?= clean($r['barangay_name']) ?>
                </div>
              </td>

              <!-- Purok -->
              <td>
                <div style="font-size:11px;color:var(--color-text);font-weight:500;">
                  <?= clean($r['purok_name'] ?: '—') ?>
                </div>
              </td>

              <!-- Reported By -->
              <td style="font-size:10.5px;color:var(--color-text-secondary);white-space:nowrap;">
                <?= clean($r['submitted_by_name'] ?: 'Barangay Unit') ?>
              </td>

              <!-- Severity -->
              <td>
                <?= renderStatusBadge($r['severity']) ?>
              </td>

              <!-- Urgency -->
              <td>
                <?= renderStatusBadge($r['urgency']) ?>
              </td>

              <!-- Aid Support -->
              <td>
                <?= renderAidSupportBadge($r['aid_support'] ?? 'resources') ?>
              </td>

              <!-- Impact -->
              <td style="font-family:var(--font-secondary);font-size:11px;white-space:nowrap;">
                <div style="font-weight:600;color:var(--color-text);">
                  <?= number_format($r['affected_families']) ?> Fam (<?= number_format($r['affected_individuals']) ?> Ind)
                </div>
              </td>

              <!-- Status -->
              <td>
                <?php if ($r['status'] === 'Ongoing'): ?>
                  <span class="badge-ongoing-pulse">Ongoing</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-size:10px;font-weight:600;">Completed</span>
                <?php endif; ?>
              </td>

              <!-- Date Filed -->
              <td
                style="font-size:9.5px;color:var(--color-text-muted);font-family:var(--font-secondary);white-space:nowrap;">
                <?= formatDate($r['created_at'], 'M d, Y') ?>
                <div style="font-size:8.5px;"><?= formatDate($r['created_at'], 'h:i A') ?></div>
              </td>

              <!-- Action: View -->
              <td style="text-align:right;">
                <button type="button" class="btn btn-outline btn-sm"
                  style="padding:3px 12px;font-size:10px;font-weight:600;"
                  onclick="viewReportDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)">
                  View
                </button>
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
  <form class="modal-dialog modal-dialog-lg" id="createReportForm" onsubmit="handleCreateReport(event)">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge-ongoing-pulse" style="font-size:10px;">Status: Ongoing</span>
        <h3 class="modal-title" style="margin:0;">File Disaster Incident Report</h3>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createReportModal')">&times;</button>
    </div>

    <div class="modal-body">
      <div
        style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:10.5px;color:var(--color-text-secondary);">
        Official ICDRRMO command report log. Filed reports are set to <strong>Ongoing</strong> to initiate disaster
        telemetry and Decision Tree resource evaluation.
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Target Barangay Jurisdiction</label>
          <select name="barangay_id" id="create_barangay_id" class="form-control" required
            onchange="handleBarangayChange(this.value, 'create')">
            <option value="">Select Barangay</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster Location</label>
          <select name="purok_name" id="create_purok_name" class="form-control" required
            onchange="handlePurokChange(this.value, 'create')">
            <option value="">Select Barangay First</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Specific Incident Landmark / Street Location</label>
        <input type="text" name="incident_location" id="create_incident_location" class="form-control"
          placeholder="e.g. Purok 3A Riverbank near Footbridge">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Disaster Hazard Type</label>
          <select name="disaster_type" id="create_disaster_type" class="form-control" required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Aid Support Needed</label>
          <select name="aid_support" id="create_aid_support" class="form-control" required>
            <option value="resources" selected>Physical Relief Resources</option>
            <option value="financial_aid">Emergency Financial Aid (Ayuda)</option>
            <option value="both">Both (Relief &amp; Financial Aid)</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Incident Severity</label>
          <select name="severity" class="form-control" required>
            <option value="Critical">Critical (Severe Emergency / Immediate Danger)</option>
            <option value="High" selected>High (Heightened Threat)</option>
            <option value="Moderate">Moderate (Standard Response)</option>
            <option value="Low">Low (Minor Localized Incident)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Response Urgency</label>
          <select name="urgency" class="form-control" required>
            <option value="Immediate" selected>Immediate (Deploy Relief ASAP)</option>
            <option value="High">High (Required Within 6 Hours)</option>
            <option value="Medium">Medium (Within 24 Hours)</option>
            <option value="Low">Low (Standby Reserve)</option>
          </select>
        </div>
      </div>

      <!-- Population Assessment -->
      <div
        style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
        Impact & Population Assessment
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Affected Families</label>
          <input type="number" name="affected_families" class="form-control" min="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Displaced Families</label>
          <input type="number" name="displaced_families" class="form-control" min="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Estimated Individuals</label>
          <input type="number" name="affected_individuals" class="form-control" min="0" value="0">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Casualties (Confirmed)</label>
          <input type="number" name="casualties_count" class="form-control" min="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Injuries</label>
          <input type="number" name="injuries_count" class="form-control" min="0" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">Missing Persons</label>
          <input type="number" name="missing_count" class="form-control" min="0" value="0">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Ground Situation Overview</label>
        <textarea name="situation_overview" class="form-control" rows="2"
          placeholder="Describe flood depth, damaged infrastructure, stranded residents, route access..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance / Supplies</label>
        <textarea name="requested_assistance" class="form-control" rows="2"
          placeholder="e.g. 100 Family Food Packs, rescue rubber boat, hygiene kits, medical kit..."></textarea>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('createReportModal')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmitCreateReport">Submit Ongoing Report</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Incident Report (Executive Command View)      -->
<!-- ========================================================================= -->
<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Incident Report                             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewReportModal">
  <form class="modal-dialog modal-dialog-lg" id="viewReportForm" onsubmit="handleSaveReportChanges(event)">
    <input type="hidden" name="id" id="view_report_id">
    <input type="hidden" name="status" id="view_report_status_hidden">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <h3 class="modal-title" style="margin:0;">Incident Report Details</h3>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewReportModal')">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Incident Identity Banner -->
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="flex:1 1 260px;">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
            <span id="viewModalStatusBadge"></span>
            <span id="viewModalTrackingHeader" style="font-family:var(--font-secondary);font-size:10px;font-weight:700;color:var(--color-text-secondary);"></span>
            <span id="viewModeHintText" style="font-size:10px;color:var(--color-text-muted);">(View Only)</span>
          </div>
          <div id="view_report_title_display" style="font-size:13px;font-weight:700;color:var(--color-primary);line-height:1.3;"></div>
          <div id="view_report_meta_sub" style="font-size:10.5px;color:var(--color-text-secondary);margin-top:2px;"></div>
        </div>
        <div id="viewModalHeaderRecommenderAction" style="display:inline-flex;align-items:center;flex-shrink:0;"></div>
      </div>

      <!-- Section: Incident Profile & Core Status -->
      <div style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
        Incident Profile &amp; Core Status
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tracking Case #</label>
          <input type="text" id="view_tracking_code" class="form-control" style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);" readonly disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Incident Status</label>
          <select name="status" id="view_status" class="form-control" disabled required>
            <option value="Ongoing">Ongoing (Active Response)</option>
            <option value="Completed">Completed (Concluded)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Disaster Hazard Type</label>
          <select name="disaster_type" id="view_disaster_type" class="form-control" disabled required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>
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
          <label class="form-label form-label-required">Incident Severity</label>
          <select name="severity" id="view_severity" class="form-control" disabled required>
            <option value="Critical">Critical (Severe Emergency / Immediate Danger)</option>
            <option value="High">High (Heightened Threat)</option>
            <option value="Moderate">Moderate (Standard Response)</option>
            <option value="Low">Low (Minor Localized Incident)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Response Urgency</label>
          <select name="urgency" id="view_urgency" class="form-control" disabled required>
            <option value="Immediate">Immediate (Deploy Relief ASAP)</option>
            <option value="High">High (Required Within 6 Hours)</option>
            <option value="Medium">Medium (Within 24 Hours)</option>
            <option value="Low">Low (Standby Reserve)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Reported By / Source</label>
          <input type="text" id="view_submitted_by" class="form-control" readonly disabled>
        </div>
      </div>

      <!-- Section: Incident Location & GIS Mapping -->
      <div style="margin:14px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;justify-content:space-between;align-items:center;">
        <span>Incident Location &amp; GIS Mapping</span>
        <div style="display:flex;align-items:center;gap:6px;">
          <button type="button" class="btn btn-outline btn-sm" style="font-size:10px;padding:2px 8px;height:22px;" onclick="recenterIncidentMap()">Recenter</button>
          <button type="button" class="btn btn-primary btn-sm" id="btnViewModalStreetView" style="font-size:10px;padding:2px 8px;height:22px;" onclick="openStreetViewForReport(currentActiveReport)">Street View (360°)</button>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Target Barangay Jurisdiction</label>
          <select name="barangay_id" id="view_barangay_id" class="form-control" disabled required onchange="handleBarangayChange(this.value, 'view')">
            <option value="">Select Barangay</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster Location</label>
          <select name="purok_name" id="view_purok_name" class="form-control" disabled required onchange="handlePurokChange(this.value, 'view')">
            <option value="">Select Purok Cluster</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Specific Incident Landmark / Street Location</label>
        <input type="text" name="incident_location" id="view_incident_location" class="form-control" placeholder="e.g. Purok 3A Riverbank near Footbridge" disabled>
      </div>

      <!-- Leaflet Map Container -->
      <div style="border:1px solid var(--color-border);border-radius:var(--radius-sm);overflow:hidden;background:var(--color-surface-subtle);margin-bottom:10px;">
        <div id="viewIncidentMap" style="height:190px;width:100%;position:relative;z-index:1;"></div>
        <div style="padding:6px 10px;background:var(--color-surface);border-top:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;font-size:10px;color:var(--color-text-secondary);">
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-weight:600;">GPS Coordinates:</span>
            <input type="number" step="0.000001" name="coordinates_lat" id="view_coordinates_lat" class="form-control" style="font-family:var(--font-secondary);font-size:10px;padding:2px 6px;width:110px;height:24px;" placeholder="Latitude" disabled onchange="handleManualCoordChange()">
            <span>•</span>
            <input type="number" step="0.000001" name="coordinates_lng" id="view_coordinates_lng" class="form-control" style="font-family:var(--font-secondary);font-size:10px;padding:2px 6px;width:110px;height:24px;" placeholder="Longitude" disabled onchange="handleManualCoordChange()">
          </div>
          <span id="viewMapCoordsBadge" style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-muted);">0.000000, 0.000000</span>
          <span id="viewMapPurokLocationText" style="display:none;"></span>
        </div>
      </div>

      <!-- Section: Impact & Population Assessment -->
      <div style="margin:14px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
        Impact &amp; Population Assessment
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Affected Families</label>
          <input type="number" name="affected_families" id="view_affected_families" class="form-control" min="0" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Displaced Families</label>
          <input type="number" name="displaced_families" id="view_displaced_families" class="form-control" min="0" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Estimated Individuals</label>
          <input type="number" name="affected_individuals" id="view_affected_individuals" class="form-control" min="0" disabled>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Casualties (Confirmed)</label>
          <input type="number" name="casualties_count" id="view_casualties_count" class="form-control" min="0" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Injuries</label>
          <input type="number" name="injuries_count" id="view_injuries_count" class="form-control" min="0" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Missing Persons</label>
          <input type="number" name="missing_count" id="view_missing_count" class="form-control" min="0" disabled>
        </div>
      </div>

      <!-- Section: Field Situation & Operational Notes -->
      <div style="margin:14px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
        Field Situation &amp; Operational Notes
      </div>

      <div class="form-group">
        <label class="form-label">Ground Situation Overview</label>
        <textarea name="situation_overview" id="view_situation_overview" class="form-control" rows="2" placeholder="Describe flood depth, damaged infrastructure, stranded residents, route access..." disabled></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance / Supplies</label>
        <textarea name="requested_assistance" id="view_requested_assistance" class="form-control" rows="2" placeholder="e.g. 100 Family Food Packs, rescue rubber boat, hygiene kits, medical kit..." disabled></textarea>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <label class="form-label" style="margin-bottom:0;">Resolution Notes</label>
          <span id="view_completed_at_timestamp" style="font-size:10px;color:var(--color-text-muted);"></span>
        </div>
        <textarea name="resolution_notes" id="view_resolution_notes" class="form-control" rows="2" placeholder="Administrative remarks or conclusion summary..." disabled></textarea>
      </div>

      <!-- Audit Timestamps -->
      <div style="font-size:10px;color:var(--color-text-muted);margin-top:10px;padding-top:6px;border-top:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
        <div>Filed: <span id="view_created_at_text" style="color:var(--color-text-secondary);font-weight:500;"></span></div>
        <div>Last Modified: <span id="view_updated_at_text" style="color:var(--color-text-secondary);font-weight:500;"></span></div>
      </div>
    </div>

    <!-- Modal Footer Actions -->
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewReportModal')">Close</button>
      <button type="button" class="btn btn-outline btn-sm" id="btnToggleEditMode" onclick="toggleReportEditMode()">Edit Report</button>
      <button type="submit" class="btn btn-primary btn-sm" id="btnSaveReportChanges" style="display:none;">Save Changes</button>
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
              <strong>Open in Google Street View</strong> or navigate to the area.</span>
          </div>
          <button type="button" class="btn btn-sm btn-outline"
            style="font-size:9.5px;color:#FFF;border-color:rgba(255,255,255,0.3);padding:2px 8px;"
            onclick="document.getElementById('streetViewNoticeOverlay').style.display='none'">Dismiss</button>
        </div>
      </div>
    </div>

    <div class="modal-footer"
      style="background:#1E293B;border-top:1px solid #334155;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:10px;color:#94A3B8;">ICDRRMO Command Center GIS & Ground Imagery System</span>
      <button type="button" class="btn btn-outline btn-sm" style="color:#FFF;border-color:#475569;"
        onclick="closeModal('streetViewModal')">Close Street View</button>
    </div>
  </div>
</div>

<script>
  let currentActiveReport = null;
  let isEditModeActive = false;

  function openStreetViewForReport(report) {
    if (!report) return;
    const lat = report.coordinates_lat ? parseFloat(report.coordinates_lat) : 8.2280;
    const lng = report.coordinates_lng ? parseFloat(report.coordinates_lng) : 124.2452;

    document.getElementById('streetViewModalTitle').innerText = `${report.disaster_type} Incident — ${report.tracking_code}`;
    document.getElementById('streetViewIncidentBadge').innerHTML = `<span class="badge badge-info" style="font-size:10px;">${escapeHtml(report.disaster_type)}</span>`;
    document.getElementById('streetViewPurokText').innerText = `${report.barangay_name || ''} (${report.purok_name})${report.incident_location ? ' — ' + report.incident_location : ''}`;
    document.getElementById('streetViewCoordsText').innerText = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;

    const iframe = document.getElementById('streetViewIframe');
    iframe.src = `https://maps.google.com/maps?q=${lat},${lng}&layer=c&cbll=${lat},${lng}&cbp=11,0,0,0,0&output=svembed`;

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

  // 100% Dynamic data structures from PHP
  const puroksByBarangay = <?= json_encode($puroksByBarangay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const purokHazardMap = <?= json_encode($purokHazardMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  // Dynamically populate Purok dropdown when Barangay is selected
  function handleBarangayChange(barangayId, targetType, selectedPurok = '') {
    const purokSelect = document.getElementById(targetType === 'create' ? 'create_purok_name' : 'view_purok_name');
    if (!purokSelect) return;

    purokSelect.innerHTML = '<option value="">Select Purok Cluster</option><option value="All Puroks">All Puroks (Barangay-wide)</option>';
    const bId = parseInt(barangayId, 10);
    const puroks = puroksByBarangay[bId] || [];

    if (puroks.length === 0) {
      const opt = document.createElement('option');
      opt.value = 'Purok Centro';
      opt.textContent = 'Purok Centro (Default)';
      purokSelect.appendChild(opt);
    } else {
      puroks.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.name;
        opt.textContent = p.name;
        purokSelect.appendChild(opt);
      });
    }

    // Select matching option (including All Puroks) or append custom
    if (selectedPurok) {
      let matched = false;
      for (let i = 0; i < purokSelect.options.length; i++) {
        if (purokSelect.options[i].value.toLowerCase() === selectedPurok.toLowerCase()) {
          purokSelect.selectedIndex = i;
          matched = true;
          break;
        }
      }
      if (!matched) {
        const customOpt = document.createElement('option');
        customOpt.value = selectedPurok;
        customOpt.textContent = selectedPurok;
        customOpt.selected = true;
        purokSelect.appendChild(customOpt);
      }
    }
  }

  // Auto-suggest hazard based on selected Purok
  function handlePurokChange(purokName, targetType) {
    if (!purokName) return;
    const selectElem = document.getElementById(targetType === 'create' ? 'create_disaster_type' : 'view_disaster_type');
    if (!selectElem) return;

    const rawHazards = purokHazardMap[purokName];
    if (rawHazards) {
      const firstHazard = rawHazards.split(',')[0].trim().toLowerCase();
      if (firstHazard) {
        for (let i = 0; i < selectElem.options.length; i++) {
          const optVal = selectElem.options[i].value.toLowerCase();
          if (optVal === firstHazard || firstHazard.includes(optVal) || optVal.includes(firstHazard)) {
            selectElem.selectedIndex = i;
            break;
          }
        }
      }
    }
  }

  // Open Create Modal
  function openCreateReportModal() {
    document.getElementById('createReportForm').reset();
    document.getElementById('create_purok_name').innerHTML = '<option value="">Select Barangay First</option>';
    openModal('createReportModal');
  }

  // Handle Create Form Submit (AJAX with dynamic reload)
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
        }
        closeModal('createReportModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to submit incident report.', 'danger');
        } else {
          alert(data.message || 'Failed to submit report.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error during report submission.', 'danger');
      }
    } finally {
      btn.disabled = false;
      btn.innerText = 'Submit Ongoing Report';
    }
  }

  // Leaflet Incident Pin Location Map Handler
  let viewIncidentMap = null;
  let viewIncidentMarker = null;

  function getHazardMarkerIcon(severity) {
    let color = '#2563EB'; // Blue (Moderate/General)
    let pulseClass = '';
    if (severity === 'Critical') {
      color = '#EF4444'; // Red
      pulseClass = 'marker-pulse-critical';
    } else if (severity === 'High') {
      color = '#F59E0B'; // Amber
      pulseClass = 'marker-pulse-high';
    } else if (severity === 'Completed' || severity === 'Low') {
      color = '#10B981'; // Green
    }

    const html = `
      <div class="hazard-svg-pin ${pulseClass}">
        <svg width="34" height="42" viewBox="0 0 34 42" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 3px 6px rgba(0,0,0,0.35)); display:block;">
          <path d="M17 0C7.611 0 0 7.611 0 17C0 27.5 17 42 17 42C17 42 34 27.5 34 17C34 7.611 26.389 0 17 0Z" fill="${color}" stroke="#FFFFFF" stroke-width="2"/>
          <circle cx="17" cy="16" r="10.5" fill="#FFFFFF"/>
          <g transform="translate(10, 9)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </g>
        </svg>
        <div class="hazard-ground-pulse"></div>
      </div>
    `;

    return L.divIcon({
      className: 'hazard-div-icon',
      html: html,
      iconSize: [34, 42],
      iconAnchor: [17, 42],
      popupAnchor: [0, -42]
    });
  }

  function initOrUpdateViewMap(latVal, lngVal, report) {
    const container = document.getElementById('viewIncidentMap');
    if (!container) return;

    const lat = (latVal && !isNaN(parseFloat(latVal))) ? parseFloat(latVal) : 8.2280;
    const lng = (lngVal && !isNaN(parseFloat(lngVal))) ? parseFloat(lngVal) : 124.2452;

    const coordsBadge = document.getElementById('viewMapCoordsBadge');
    if (coordsBadge) {
      coordsBadge.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    }
    const locText = document.getElementById('viewMapPurokLocationText');
    if (locText) {
      locText.textContent = `${report.barangay_name || 'Barangay'} — ${report.purok_name || 'All Puroks'}`;
    }

    const markerIcon = getHazardMarkerIcon(report.severity || 'Moderate');

    if (!viewIncidentMap) {
      viewIncidentMap = L.map('viewIncidentMap', {
        center: [lat, lng],
        zoom: 16,
        zoomControl: true
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(viewIncidentMap);

      viewIncidentMarker = L.marker([lat, lng], {
        icon: markerIcon
      }).addTo(viewIncidentMap);

      // In edit mode, clicking the map moves the incident pin and updates coordinate inputs
      viewIncidentMap.on('click', function (e) {
        if (!isEditModeActive) return;
        const newLat = e.latlng.lat;
        const newLng = e.latlng.lng;
        if (viewIncidentMarker) {
          viewIncidentMarker.setLatLng([newLat, newLng]);
        }
        const latEl = document.getElementById('view_coordinates_lat');
        const lngEl = document.getElementById('view_coordinates_lng');
        if (latEl) latEl.value = newLat.toFixed(6);
        if (lngEl) lngEl.value = newLng.toFixed(6);
        if (coordsBadge) coordsBadge.textContent = `${newLat.toFixed(6)}, ${newLng.toFixed(6)}`;
        if (currentActiveReport) {
          currentActiveReport.coordinates_lat = newLat;
          currentActiveReport.coordinates_lng = newLng;
        }
      });
    } else {
      viewIncidentMap.setView([lat, lng], 16);
      if (viewIncidentMarker) {
        viewIncidentMarker.setLatLng([lat, lng]);
        viewIncidentMarker.setIcon(markerIcon);
      } else {
        viewIncidentMarker = L.marker([lat, lng], {
          icon: markerIcon
        }).addTo(viewIncidentMap);
      }
    }

    let severityColor = '#2563EB';
    if (report.severity === 'Critical') severityColor = '#EF4444';
    else if (report.severity === 'High') severityColor = '#F59E0B';
    else if (report.severity === 'Low' || report.severity === 'Completed') severityColor = '#10B981';

    const popupHtml = `
      <div style="font-family:var(--font-primary, sans-serif);font-size:11px;line-height:1.45;padding-right:16px;">
        <div style="font-weight:700;color:#17324D;font-size:12px;margin-bottom:2px;">${escapeHtml(report.tracking_code || 'Incident')}</div>
        <div style="color:#2563EB;font-weight:600;font-size:11.5px;">${escapeHtml(report.disaster_type || 'Incident')}</div>
        <div style="color:#64748B;font-size:10px;margin-top:2px;">${escapeHtml(report.barangay_name || '')} • ${escapeHtml(report.purok_name || '')}</div>
        <div style="margin-top:5px;font-size:10.5px;color:#0F172A;">
          <strong>Severity:</strong> <span style="font-weight:700;color:${severityColor};">${escapeHtml(report.severity || 'Moderate')}</span>
        </div>
      </div>
    `;
    // Bind popup without auto-opening so the pin stays cleanly centered in the map viewport
    viewIncidentMarker.bindPopup(popupHtml);

    setTimeout(() => {
      if (viewIncidentMap) {
        viewIncidentMap.invalidateSize();
        viewIncidentMap.setView([lat, lng], 16, { animate: false });
      }
    }, 280);
  }

  function handleManualCoordChange() {
    const latEl = document.getElementById('view_coordinates_lat');
    const lngEl = document.getElementById('view_coordinates_lng');
    if (!latEl || !lngEl) return;
    const lat = parseFloat(latEl.value);
    const lng = parseFloat(lngEl.value);
    if (!isNaN(lat) && !isNaN(lng)) {
      if (viewIncidentMap) viewIncidentMap.setView([lat, lng], 16);
      if (viewIncidentMarker) viewIncidentMarker.setLatLng([lat, lng]);
      const coordsBadge = document.getElementById('viewMapCoordsBadge');
      if (coordsBadge) coordsBadge.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
      if (currentActiveReport) {
        currentActiveReport.coordinates_lat = lat;
        currentActiveReport.coordinates_lng = lng;
      }
    }
  }

  function recenterIncidentMap() {
    if (!viewIncidentMap || !currentActiveReport) return;
    const lat = (currentActiveReport.coordinates_lat && !isNaN(parseFloat(currentActiveReport.coordinates_lat))) ? parseFloat(currentActiveReport.coordinates_lat) : 8.2280;
    const lng = (currentActiveReport.coordinates_lng && !isNaN(parseFloat(currentActiveReport.coordinates_lng))) ? parseFloat(currentActiveReport.coordinates_lng) : 124.2452;
    viewIncidentMap.setView([lat, lng], 16, { animate: true });
  }

  // View Report Details in Modal
  function viewReportDetails(report) {
    currentActiveReport = report;
    isEditModeActive = false;

    // Header and Status Badges
    document.getElementById('view_report_id').value = report.id;
    const hiddenStatus = document.getElementById('view_report_status_hidden');
    if (hiddenStatus) hiddenStatus.value = report.status;
    document.getElementById('viewModalTrackingHeader').textContent = report.tracking_code;

    const trackingInput = document.getElementById('view_tracking_code');
    if (trackingInput) trackingInput.value = report.tracking_code || '';

    const statusSelect = document.getElementById('view_status');
    if (statusSelect) statusSelect.value = report.status || 'Ongoing';

    const submitterInput = document.getElementById('view_submitted_by');
    if (submitterInput) submitterInput.value = report.submitted_by_name || 'Barangay Unit';

    const statusBadge = document.getElementById('viewModalStatusBadge');
    if (report.status === 'Ongoing') {
      statusBadge.innerHTML = '<span class="badge-ongoing-pulse">Ongoing</span>';
    } else if (report.status === 'Completed') {
      statusBadge.innerHTML = '<span class="badge badge-success" style="font-weight:600;">Completed</span>';
    } else {
      statusBadge.innerHTML = `<span class="badge badge-info">${escapeHtml(report.status)}</span>`;
    }

    // Titles
    document.getElementById('view_report_title_display').textContent = `${report.disaster_type} — Brgy. ${report.barangay_name}`;
    document.getElementById('view_report_meta_sub').textContent = `Location: ${report.purok_name} • Reported by: ${report.submitted_by_name || 'Barangay Unit'} on ${report.created_at}`;

    // Form Controls Values
    document.getElementById('view_barangay_id').value = report.barangay_id;
    handleBarangayChange(report.barangay_id, 'view', report.purok_name);

    const locEl = document.getElementById('view_incident_location');
    if (locEl) locEl.value = report.incident_location || '';

    const latEl = document.getElementById('view_coordinates_lat');
    if (latEl) latEl.value = (report.coordinates_lat !== null && report.coordinates_lat !== undefined && report.coordinates_lat !== '') ? parseFloat(report.coordinates_lat).toFixed(6) : '8.228000';

    const lngEl = document.getElementById('view_coordinates_lng');
    if (lngEl) lngEl.value = (report.coordinates_lng !== null && report.coordinates_lng !== undefined && report.coordinates_lng !== '') ? parseFloat(report.coordinates_lng).toFixed(6) : '124.245200';

    document.getElementById('view_disaster_type').value = report.disaster_type;
    const aidSelect = document.getElementById('view_aid_support');
    if (aidSelect) aidSelect.value = report.aid_support || 'resources';
    document.getElementById('view_severity').value = report.severity;
    document.getElementById('view_urgency').value = report.urgency;

    document.getElementById('view_affected_families').value = report.affected_families || 0;
    document.getElementById('view_displaced_families').value = report.displaced_families || 0;
    document.getElementById('view_affected_individuals').value = report.affected_individuals || 0;

    document.getElementById('view_casualties_count').value = report.casualties_count || 0;
    document.getElementById('view_injuries_count').value = report.injuries_count || 0;
    document.getElementById('view_missing_count').value = report.missing_count || 0;

    document.getElementById('view_situation_overview').value = report.situation_overview || '';
    document.getElementById('view_requested_assistance').value = report.requested_assistance || '';

    // Resolution notes safe check
    const resNotesEl = document.getElementById('view_resolution_notes');
    if (resNotesEl) resNotesEl.value = report.resolution_notes || '';

    // Update Decision Support System (DSS) UI inside modal
    updateRecommenderModalUI(report);

    // Initialize or update Leaflet pin location map
    initOrUpdateViewMap(report.coordinates_lat, report.coordinates_lng, report);

    // Completed timestamp
    const completedTs = document.getElementById('view_completed_at_timestamp');
    if (completedTs) {
      if (report.completed_at) {
        completedTs.textContent = `(Completed on: ${report.completed_at})`;
      } else {
        completedTs.textContent = '';
      }
    }

    // Timestamps
    document.getElementById('view_created_at_text').textContent = report.created_at || 'N/A';
    document.getElementById('view_updated_at_text').textContent = report.updated_at || 'N/A';

    // Reset to View Mode (disabled)
    setModalInputsDisabled(true);
    updateEditModeUI(false);

    openModal('viewReportModal');
    setTimeout(() => {
      if (viewIncidentMap) {
        viewIncidentMap.invalidateSize();
        if (currentActiveReport) {
          const lat = (currentActiveReport.coordinates_lat && !isNaN(parseFloat(currentActiveReport.coordinates_lat))) ? parseFloat(currentActiveReport.coordinates_lat) : 8.2280;
          const lng = (currentActiveReport.coordinates_lng && !isNaN(parseFloat(currentActiveReport.coordinates_lng))) ? parseFloat(currentActiveReport.coordinates_lng) : 124.2452;
          viewIncidentMap.setView([lat, lng], 16, { animate: false });
        }
      }
    }, 280);
  }

  // Toggle View vs Edit Mode
  function toggleReportEditMode() {
    isEditModeActive = !isEditModeActive;
    setModalInputsDisabled(!isEditModeActive);
    updateEditModeUI(isEditModeActive);
  }

  function setModalInputsDisabled(disabled) {
    const fields = [
      'view_aid_support', 'view_barangay_id', 'view_purok_name', 'view_incident_location',
      'view_coordinates_lat', 'view_coordinates_lng',
      'view_disaster_type', 'view_severity', 'view_urgency',
      'view_affected_families', 'view_displaced_families', 'view_affected_individuals',
      'view_casualties_count', 'view_injuries_count', 'view_missing_count',
      'view_situation_overview', 'view_requested_assistance', 'view_resolution_notes'
    ];
    fields.forEach(fId => {
      const el = document.getElementById(fId);
      if (el) el.disabled = disabled;
    });
  }

  function updateEditModeUI(isEditing) {
    const btnToggle = document.getElementById('btnToggleEditMode');
    const btnSave = document.getElementById('btnSaveReportChanges');
    const hintText = document.getElementById('viewModeHintText');

    if (isEditing) {
      btnToggle.innerText = 'Cancel Edit';
      btnSave.style.display = 'inline-flex';
      hintText.textContent = '(Editing Mode)';
      hintText.style.color = 'var(--color-secondary)';
    } else {
      btnToggle.innerText = 'Edit Report';
      btnSave.style.display = 'none';
      hintText.textContent = '(View Only Mode)';
      hintText.style.color = 'var(--color-text-muted)';
    }
  }

  // Handle In-Place Save Report Changes (AJAX)
  async function handleSaveReportChanges(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSaveReportChanges');
    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const formData = new FormData(form);
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
          showToast(data.message || 'Changes saved successfully.', 'success');
        }
        closeModal('viewReportModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to save changes.', 'danger');
        } else {
          alert(data.message || 'Failed to save changes.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while saving changes.', 'danger');
      }
    } finally {
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  }

  // Dynamic Recommender UI Handler for Incident View Modal (Top banner button only)
  function updateRecommenderModalUI(report) {
    const headerAction = document.getElementById('viewModalHeaderRecommenderAction');
    if (!headerAction) return;

    const recId = report.recommendation_id;
    const isGenerated = !!(recId && parseInt(recId, 10) > 0);

    if (!isGenerated) {
      headerAction.innerHTML = `
        <button type="button" class="btn btn-primary btn-sm" onclick="triggerRecommender(${report.id}, '${escapeHtml(report.tracking_code)}', currentActiveReport)">
          Run AI Recommendation
        </button>
      `;
    } else {
      headerAction.innerHTML = `
        <a href="${BASE_URL}/views/icdrrmo/recommendations.php?id=${recId}" class="btn btn-primary btn-sm" style="text-decoration:none;">
          View AI Recommendation &rarr;
        </a>
      `;
    }
  }

  // Trigger Decision Support Recommender Engine with Modality Modal & Animation
  function triggerRecommender(requestId, trackingCode, reportObj) {
    let rData = reportObj;
    if (!rData) {
      if (typeof currentActiveReport !== 'undefined' && currentActiveReport && currentActiveReport.id == requestId) {
        rData = currentActiveReport;
      } else {
        rData = { id: requestId, tracking_code: trackingCode };
      }
    }

    if (typeof openDssExecutionModal === 'function') {
      openDssExecutionModal(rData, (result) => {
        if (typeof currentActiveReport !== 'undefined' && currentActiveReport && currentActiveReport.id == requestId) {
          currentActiveReport.recommendation_id = result.recommendation_id;
          currentActiveReport.recommendation_code = result.recommendation_code;
          currentActiveReport.confidence_score = result.confidence;
          currentActiveReport.recommendation_status = 'Pending Review';
          currentActiveReport.recommendation_priority = result.priority;
          currentActiveReport.modality = result.modality;
          if (result.financial_aid) {
            currentActiveReport.financial_aid_recommended_budget = result.financial_aid.recommended_budget;
            currentActiveReport.financial_aid_per_family = result.financial_aid.per_family;
            currentActiveReport.financial_aid_target_families = result.financial_aid.target_families;
          }
          updateRecommenderModalUI(currentActiveReport);
        }
      });
    } else {
      // Fallback if modal function not ready
      if (confirm(`Execute DSS recommendation analysis for Request ${trackingCode}?`)) {
        window.location.href = `<?= BASE_URL ?>/backend/functions/disaster_requests/run_recommender.php?request_id=${requestId}`;
      }
    }
  }

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