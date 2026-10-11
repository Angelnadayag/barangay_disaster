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
    $bId = (int)$p['barangay_id'];
    $puroksByBarangay[$bId][] = [
        'id' => (int)$p['id'],
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
        $bId = (int)($cc['barangay_id'] ?? 0);
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
                    'id' => (int)$cc['id'],
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
} catch (Exception $e) {}

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

$filterBarangay = (int)($_GET['barangay'] ?? 0);
$filterType = trim($_GET['type'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterUrgency = trim($_GET['urgency'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// 5. Dynamic Counts for Tabs & KPI Telemetry
$countAll = (int)$db->query("SELECT COUNT(*) FROM disaster_reports")->fetchColumn();
$countOngoing = (int)$db->query("SELECT COUNT(*) FROM disaster_reports WHERE status = 'Ongoing'")->fetchColumn();
$countNeedsRec = (int)$db->query("
    SELECT COUNT(*) FROM disaster_reports dr 
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id 
    WHERE (r.id IS NULL OR r.status = 'Pending Review') AND dr.status NOT IN ('Completed', 'Rejected')
")->fetchColumn();
$countCompleted = (int)$db->query("SELECT COUNT(*) FROM disaster_reports WHERE status = 'Completed'")->fetchColumn();

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

// 6. Base Incident Reports Query
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.recommendation_code, r.status AS recommendation_status,
           r.confidence_score, r.priority AS recommendation_priority
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    LEFT JOIN users u ON dr.submitted_by = u.id
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id
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

$sql .= " ORDER BY FIELD(dr.status, 'Ongoing', 'Submitted', 'Under Review', 'Recommendation Ready', 'Approved', 'Allocated', 'Dispatched', 'Completed', 'Rejected'), dr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Disaster Reports of Barangays — ICDRRMO";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

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
.kpi-stat-card.active-card {
  border-left: 3px solid var(--color-warning, #B78103);
}
.kpi-stat-card.completed-card {
  border-left: 3px solid var(--color-success, #2E7D32);
}
.kpi-stat-card.displaced-card {
  border-left: 3px solid var(--color-danger, #C62828);
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
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(183, 129, 3, 0.6); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(183, 129, 3, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(183, 129, 3, 0); }
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
  width: 100% !important;
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
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Disaster Reports of Barangays</h1>
      <p class="page-header-desc">
        Central monitoring and review of disaster incident reports, population impact evaluations, and Decision Tree resource allocation across all 44 barangays of Iligan City.
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-primary" onclick="openCreateReportModal()">
        File Incident Report
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--space-3, 10px);margin-bottom:var(--space-4, 12px);">
    <div class="kpi-stat-card">
      <div style="font-size:9.5px;font-weight:600;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;">Total Filed Reports</div>
      <div style="font-size:20px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);"><?= number_format($countAll) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Across all city barangays</div>
    </div>
    <div class="kpi-stat-card active-card">
      <div style="font-size:9.5px;font-weight:600;color:#8C6202;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:4px;">
        <span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:var(--color-warning);"></span> Active / Ongoing
      </div>
      <div style="font-size:20px;font-weight:700;color:#8C6202;font-family:var(--font-secondary);"><?= number_format($countOngoing) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Incidents actively responding</div>
    </div>
    <div class="kpi-stat-card">
      <div style="font-size:9.5px;font-weight:600;color:var(--color-secondary);text-transform:uppercase;letter-spacing:0.5px;">Needs Recommendation</div>
      <div style="font-size:20px;font-weight:700;color:var(--color-secondary);font-family:var(--font-secondary);"><?= number_format($countNeedsRec) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Awaiting Decision Tree run</div>
    </div>
    <div class="kpi-stat-card completed-card">
      <div style="font-size:9.5px;font-weight:600;color:#1E5E22;text-transform:uppercase;letter-spacing:0.5px;">Concluded / Completed</div>
      <div style="font-size:20px;font-weight:700;color:#1E5E22;font-family:var(--font-secondary);"><?= number_format($countCompleted) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Closed operations</div>
    </div>
    <div class="kpi-stat-card displaced-card">
      <div style="font-size:9.5px;font-weight:600;color:#9B1C1C;text-transform:uppercase;letter-spacing:0.5px;">Displaced Families</div>
      <div style="font-size:20px;font-weight:700;color:#9B1C1C;font-family:var(--font-secondary);"><?= number_format($stats['total_displaced_families']) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);"><?= number_format($stats['total_affected_individuals']) ?> affected residents</div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div style="display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--color-border);margin-bottom:var(--space-3, 10px);">
    <a href="?tab=all<?= $filterBarangay ? '&barangay='.$filterBarangay : '' ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'all' ? 'active' : '' ?>">
      All Incident Reports
      <span class="badge badge-neutral" style="font-size:9.5px;padding:2px 6px;"><?= $countAll ?></span>
    </a>
    <a href="?tab=ongoing<?= $filterBarangay ? '&barangay='.$filterBarangay : '' ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'ongoing' ? 'active' : '' ?>">
      Active / Ongoing
      <span class="badge badge-warning" style="font-size:9.5px;padding:2px 6px;"><?= $countOngoing ?></span>
    </a>
    <a href="?tab=needs_recommender<?= $filterBarangay ? '&barangay='.$filterBarangay : '' ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'needs_recommender' ? 'active' : '' ?>">
      Needs Recommendation
      <span class="badge badge-info" style="font-size:9.5px;padding:2px 6px;"><?= $countNeedsRec ?></span>
    </a>
    <a href="?tab=completed<?= $filterBarangay ? '&barangay='.$filterBarangay : '' ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'completed' ? 'active' : '' ?>">
      Concluded / Completed
      <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;"><?= $countCompleted ?></span>
    </a>
  </div>

  <!-- Filter & Search Bar -->
  <div class="card" style="margin-bottom: var(--space-4, 12px);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">
        <div class="filter-group" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
          <div class="search-input-wrap" style="min-width:220px;flex:1;">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search tracking #, barangay, purok..." value="<?= clean($search) ?>">
          </div>

          <select name="barangay" class="form-control" style="width:160px;">
            <option value="">All 44 Barangays</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay === (int)$b['id'] ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
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
            <option value="Submitted" <?= $filterStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="Under Review" <?= $filterStatus === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
            <option value="Recommendation Ready" <?= $filterStatus === 'Recommendation Ready' ? 'selected' : '' ?>>Recommendation Ready</option>
            <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Allocated" <?= $filterStatus === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
            <option value="Dispatched" <?= $filterStatus === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
          </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline" style="padding:6px 12px;">Filter</button>
          <?php if (!empty($search) || !empty($filterBarangay) || !empty($filterType) || !empty($filterSeverity) || !empty($filterStatus)): ?>
            <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline" style="color:var(--color-text-muted);padding:6px 12px;">Reset</a>
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
          <th>Incident # & Hazard</th>
          <th>Barangay & Purok</th>
          <th>Severity / Urgency</th>
          <th>Impact & Displaced</th>
          <th>Status</th>
          <th>Decision Tree Support</th>
          <th>Date Filed</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:36px;color:var(--color-text-muted);">
              No disaster reports found matching the selected criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <!-- Incident # & Hazard -->
              <td style="font-family:var(--font-secondary);">
                <div style="font-weight:700;color:var(--color-primary);font-size:11.5px;">
                  <?= clean($r['tracking_code']) ?>
                </div>
                <div style="margin-top:2px;">
                  <span class="badge badge-neutral" style="font-size:9px;"><?= clean($r['disaster_type']) ?></span>
                </div>
              </td>

              <!-- Barangay & Purok -->
              <td>
                <div style="font-weight:600;color:var(--color-primary);font-size:11px;">
                  <?= clean($r['barangay_name']) ?>
                </div>
                <div style="font-size:9.5px;color:var(--color-text-secondary);margin-top:1px;">
                  <?= clean($r['purok_name']) ?>
                </div>
                <div style="font-size:8.5px;color:var(--color-text-muted);margin-top:1px;">
                  Filed by: <?= clean($r['submitted_by_name'] ?: 'Barangay Unit') ?>
                </div>
              </td>

              <!-- Severity / Urgency -->
              <td>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                  <?= renderStatusBadge($r['severity']) ?>
                  <span style="font-size:9px;color:var(--color-text-muted);">Urgency: <?= clean($r['urgency']) ?></span>
                </div>
              </td>

              <!-- Impact & Displaced -->
              <td style="font-family:var(--font-secondary);font-size:10.5px;">
                <div style="font-weight:600;color:var(--color-text);">
                  <?= number_format($r['affected_families']) ?> Fam (<?= number_format($r['affected_individuals']) ?> Ind)
                </div>
                <?php if ((int)$r['displaced_families'] > 0): ?>
                  <div style="font-size:9px;color:var(--color-danger);font-weight:600;">
                    • <?= number_format($r['displaced_families']) ?> Displaced
                  </div>
                <?php endif; ?>
                <?php if ((int)$r['casualties_count'] > 0 || (int)$r['injuries_count'] > 0 || (int)$r['missing_count'] > 0): ?>
                  <div style="font-size:8.5px;color:var(--color-text-muted);">
                    <?= (int)$r['casualties_count'] ?> Dead | <?= (int)$r['injuries_count'] ?> Injured | <?= (int)$r['missing_count'] ?> Missing
                  </div>
                <?php endif; ?>
              </td>

              <!-- Status -->
              <td>
                <?php if ($r['status'] === 'Ongoing'): ?>
                  <span class="badge-ongoing-pulse">Ongoing</span>
                <?php elseif ($r['status'] === 'Completed'): ?>
                  <span class="badge badge-success" style="font-weight:600;">Completed</span>
                  <?php if (!empty($r['completed_at'])): ?>
                    <div style="font-size:8.5px;color:var(--color-text-muted);margin-top:2px;">Ended <?= formatDate($r['completed_at'], 'M d, h:i A') ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <?= renderStatusBadge($r['status']) ?>
                <?php endif; ?>
              </td>

              <!-- Decision Tree Recommender Support -->
              <td>
                <?php if (!empty($r['recommendation_id'])): ?>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $r['recommendation_id'] ?>" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:9.5px;color:var(--color-secondary);border-color:var(--color-secondary);">
                    Rec Ready (<?= number_format($r['confidence_score'] ?? 0, 0) ?>%)
                  </a>
                <?php else: ?>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:9.5px;" onclick="triggerRecommender(<?= $r['id'] ?>, '<?= clean($r['tracking_code']) ?>')">
                    Run Decision Tree
                  </button>
                <?php endif; ?>
              </td>

              <!-- Date Filed -->
              <td style="font-size:9.5px;color:var(--color-text-muted);font-family:var(--font-secondary);white-space:nowrap;">
                <?= formatDate($r['created_at'], 'M d, Y') ?>
                <div style="font-size:8.5px;"><?= formatDate($r['created_at'], 'h:i A') ?></div>
              </td>

              <!-- Action: View Button -->
              <td style="text-align:right;">
                <button type="button" class="btn btn-outline btn-sm" style="padding:3px 10px;font-size:10px;font-weight:600;" onclick="viewReportDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)">
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
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:10.5px;color:var(--color-text-secondary);">
        Official ICDRRMO command report log. Filed reports are set to <strong>Ongoing</strong> to initiate disaster telemetry and Decision Tree resource evaluation.
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Target Barangay Jurisdiction</label>
          <select name="barangay_id" id="create_barangay_id" class="form-control" required onchange="handleBarangayChange(this.value, 'create')">
            <option value="">Select Barangay</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster Location</label>
          <select name="purok_name" id="create_purok_name" class="form-control" required onchange="handlePurokChange(this.value, 'create')">
            <option value="">Select Barangay First</option>
          </select>
        </div>
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
      <div style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
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
        <textarea name="situation_overview" class="form-control" rows="2" placeholder="Describe flood depth, damaged infrastructure, stranded residents, route access..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance / Supplies</label>
        <textarea name="requested_assistance" class="form-control" rows="2" placeholder="e.g. 100 Family Food Packs, rescue rubber boat, hygiene kits, medical kit..."></textarea>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('createReportModal')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmitCreateReport">Submit Ongoing Report</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Incident Report (Styled like Manage Users)   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewReportModal">
  <form class="modal-dialog modal-dialog-lg" id="viewReportForm" onsubmit="handleSaveReportChanges(event)">
    <input type="hidden" name="id" id="view_report_id">
    <input type="hidden" name="status" id="view_report_status_hidden">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
        <span style="font-size:11px;color:var(--color-text-muted);font-family:var(--font-secondary);" id="viewModalTrackingHeader"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewReportModal')">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Incident Identity Banner -->
      <div style="padding-bottom:10px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
          <div id="view_report_title_display" style="font-weight:700;font-size:15px;color:var(--color-primary);line-height:1.2;"></div>
          <div id="view_report_meta_sub" style="font-size:10px;color:var(--color-text-muted);margin-top:3px;"></div>
        </div>
        <div id="view_status_quick_callout"></div>
      </div>

      <!-- Section Heading -->
      <div style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading" style="margin:0;font-size:11.5px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
          Incident Report Details
        </h4>
        <span id="viewModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:600;">(View Only Mode)</span>
      </div>

      <!-- Form Inputs (Disabled on View, Enabled on Edit) -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Barangay Jurisdiction</label>
          <select name="barangay_id" id="view_barangay_id" class="form-control" disabled required onchange="handleBarangayChange(this.value, 'view')">
            <option value="">Select Barangay</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster</label>
          <select name="purok_name" id="view_purok_name" class="form-control" disabled required onchange="handlePurokChange(this.value, 'view')">
            <option value="">Select Purok Cluster</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Hazard Type</label>
          <select name="disaster_type" id="view_disaster_type" class="form-control" disabled required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
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
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:10px 12px;margin:10px 0 12px 0;">
        <div style="font-size:9.5px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:6px;">Population Impact Assessment</div>
        <div class="form-row" style="margin-bottom:6px;">
          <div class="form-group">
            <label class="form-label">Affected Families</label>
            <input type="number" name="affected_families" id="view_affected_families" class="form-control" min="0" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Displaced Families</label>
            <input type="number" name="displaced_families" id="view_displaced_families" class="form-control" min="0" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Affected Individuals</label>
            <input type="number" name="affected_individuals" id="view_affected_individuals" class="form-control" min="0" disabled>
          </div>
        </div>

        <div class="form-row" style="margin-bottom:0;">
          <div class="form-group">
            <label class="form-label">Casualties</label>
            <input type="number" name="casualties_count" id="view_casualties_count" class="form-control" min="0" disabled>
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
        <textarea name="situation_overview" id="view_situation_overview" class="form-control" rows="2" disabled></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance</label>
        <textarea name="requested_assistance" id="view_requested_assistance" class="form-control" rows="2" disabled></textarea>
      </div>

      <!-- Decision Tree Status Box -->
      <div id="viewDecisionTreeSection" style="margin-top:10px;padding:10px 12px;background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
          <div>
            <div style="font-size:10px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">Decision Tree Allocation Recommender</div>
            <div id="view_recommender_status_text" style="font-size:9.5px;color:var(--color-text-secondary);margin-top:2px;"></div>
          </div>
          <div id="view_recommender_action_wrap"></div>
        </div>
      </div>

      <!-- Resolution Section -->
      <div id="viewResolutionSection" style="margin-top:10px;padding:10px 12px;background:rgba(46, 125, 50, 0.05);border:1px solid rgba(46, 125, 50, 0.2);border-radius:6px;">
        <div style="font-size:10px;font-weight:700;color:var(--color-success);text-transform:uppercase;margin-bottom:4px;">
          Resolution & Operation Conclusion Notes
        </div>
        <textarea name="resolution_notes" id="view_resolution_notes" class="form-control" rows="2" placeholder="Incident closure summary, distribution completion, ground recovery remarks..." disabled></textarea>
        <div id="view_completed_at_timestamp" style="font-size:9px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Timestamps Footer -->
      <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:10px;padding-top:8px;border-top:1px dashed var(--color-border-light);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
        <div><strong style="color:var(--color-text);">Filed:</strong> <span id="view_created_at_text"></span></div>
        <div><strong style="color:var(--color-text);">Last Updated:</strong> <span id="view_updated_at_text"></span></div>
      </div>
    </div>

    <!-- Modal Footer Actions (Full CRUD & Status Control) -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left side: Status Transition & Delete -->
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm" id="btnFinishIncident" style="background:var(--color-success);color:#FFF;border-color:var(--color-success);font-weight:600;" onclick="handleFinishIncidentClick()">
          Finish / End Incident
        </button>
        <button type="button" class="btn btn-outline btn-sm" id="btnReopenIncident" style="color:var(--color-warning);border-color:var(--color-warning);display:none;" onclick="handleReopenIncidentClick()">
          Reopen Incident (Set to Ongoing)
        </button>
        <button type="button" class="btn btn-outline btn-sm" id="btnDeleteReport" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="handleDeleteReportClick()">
          Delete Report
        </button>
      </div>

      <!-- Right side: Edit Toggle & Close -->
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewReportModal')">Close</button>
        <button type="button" class="btn btn-outline btn-sm" id="btnToggleEditMode" onclick="toggleReportEditMode()">
          Edit Report
        </button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSaveReportChanges" style="display:none;">
          Save Changes
        </button>
      </div>
    </div>
  </form>
</div>

<script>
let currentActiveReport = null;
let isEditModeActive = false;

// 100% Dynamic data structures from PHP
const puroksByBarangay = <?= json_encode($puroksByBarangay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const purokHazardMap = <?= json_encode($purokHazardMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Dynamically populate Purok dropdown when Barangay is selected
function handleBarangayChange(barangayId, targetType, selectedPurok = '') {
  const purokSelect = document.getElementById(targetType === 'create' ? 'create_purok_name' : 'view_purok_name');
  if (!purokSelect) return;

  purokSelect.innerHTML = '<option value="">Select Purok Cluster</option>';
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
      if (selectedPurok && selectedPurok.toLowerCase() === p.name.toLowerCase()) {
        opt.selected = true;
      }
      purokSelect.appendChild(opt);
    });
  }

  // If selectedPurok wasn't in list, append it
  if (selectedPurok && !Array.from(purokSelect.options).some(o => o.value.toLowerCase() === selectedPurok.toLowerCase())) {
    const customOpt = document.createElement('option');
    customOpt.value = selectedPurok;
    customOpt.textContent = selectedPurok;
    customOpt.selected = true;
    purokSelect.appendChild(customOpt);
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

// View Report Details in Modal
function viewReportDetails(report) {
  currentActiveReport = report;
  isEditModeActive = false;

  // Header and Status Badges
  document.getElementById('view_report_id').value = report.id;
  document.getElementById('view_report_status_hidden').value = report.status;
  document.getElementById('viewModalTrackingHeader').textContent = report.tracking_code;

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

  document.getElementById('view_disaster_type').value = report.disaster_type;
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
  document.getElementById('view_resolution_notes').value = report.resolution_notes || '';

  // Decision Tree Box
  const dtText = document.getElementById('view_recommender_status_text');
  const dtAction = document.getElementById('view_recommender_action_wrap');
  if (report.recommendation_id) {
    dtText.innerHTML = `Recommendation Code: <strong>${escapeHtml(report.recommendation_code || '#'+report.recommendation_id)}</strong> • Confidence: <strong>${Math.round(report.confidence_score || 0)}%</strong> • Status: <strong>${escapeHtml(report.recommendation_status || 'Generated')}</strong>`;
    dtAction.innerHTML = `<a href="${BASE_URL}/views/icdrrmo/recommendations.php?id=${report.recommendation_id}" class="btn btn-outline btn-sm" style="color:var(--color-secondary);border-color:var(--color-secondary);padding:3px 10px;">View Recommendation</a>`;
  } else {
    dtText.textContent = 'No Decision Tree resource allocation has been executed for this incident yet.';
    dtAction.innerHTML = `<button type="button" class="btn btn-outline btn-sm" style="padding:3px 10px;" onclick="triggerRecommender(${report.id}, '${escapeHtml(report.tracking_code)}')">Run Decision Tree</button>`;
  }

  // Completed timestamp
  const completedTs = document.getElementById('view_completed_at_timestamp');
  if (report.completed_at) {
    completedTs.textContent = `Completed on: ${report.completed_at}`;
  } else {
    completedTs.textContent = '';
  }

  // Timestamps
  document.getElementById('view_created_at_text').textContent = report.created_at || 'N/A';
  document.getElementById('view_updated_at_text').textContent = report.updated_at || 'N/A';

  // Quick Action Buttons
  const btnFinish = document.getElementById('btnFinishIncident');
  const btnReopen = document.getElementById('btnReopenIncident');
  if (report.status === 'Completed') {
    btnFinish.style.display = 'none';
    btnReopen.style.display = 'inline-flex';
  } else {
    btnFinish.style.display = 'inline-flex';
    btnReopen.style.display = 'none';
  }

  // Reset to View Mode (disabled)
  setModalInputsDisabled(true);
  updateEditModeUI(false);

  openModal('viewReportModal');
}

// Toggle View vs Edit Mode
function toggleReportEditMode() {
  isEditModeActive = !isEditModeActive;
  setModalInputsDisabled(!isEditModeActive);
  updateEditModeUI(isEditModeActive);
}

function setModalInputsDisabled(disabled) {
  const fields = [
    'view_barangay_id', 'view_purok_name', 'view_disaster_type', 'view_severity', 'view_urgency',
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

// Finish / Conclude Incident (Status: Completed)
async function handleFinishIncidentClick() {
  if (!currentActiveReport) return;
  const notes = document.getElementById('view_resolution_notes').value.trim();

  const confirmAction = async () => {
    try {
      const formData = new FormData();
      formData.append('request_id', currentActiveReport.id);
      formData.append('status', 'Completed');
      if (notes) formData.append('resolution_notes', notes);

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
          showToast(data.message || 'Incident finished and marked as Completed.', 'success');
        }
        closeModal('viewReportModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        if (typeof showToast === 'function') showToast(data.message || 'Failed to finish incident.', 'danger');
      }
    } catch (e) {
      if (typeof showToast === 'function') showToast('Network error during status transition.', 'danger');
    }
  };

  if (typeof showConfirmModal === 'function') {
    showConfirmModal(
      'Conclude Incident',
      `End emergency response operations for Incident ${currentActiveReport.tracking_code}? This marks the report as Completed.`,
      confirmAction
    );
  } else if (confirm(`End incident ${currentActiveReport.tracking_code} and mark as Completed?`)) {
    confirmAction();
  }
}

// Reopen Incident (Status: Ongoing)
async function handleReopenIncidentClick() {
  if (!currentActiveReport) return;

  const confirmAction = async () => {
    try {
      const formData = new FormData();
      formData.append('request_id', currentActiveReport.id);
      formData.append('status', 'Ongoing');

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
          showToast(data.message || 'Incident status set back to Ongoing.', 'success');
        }
        closeModal('viewReportModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        if (typeof showToast === 'function') showToast(data.message || 'Failed to reopen incident.', 'danger');
      }
    } catch (e) {
      if (typeof showToast === 'function') showToast('Network error during status transition.', 'danger');
    }
  };

  if (typeof showConfirmModal === 'function') {
    showConfirmModal(
      'Reopen Incident',
      `Reopen Incident ${currentActiveReport.tracking_code} and set status back to Ongoing?`,
      confirmAction
    );
  } else if (confirm(`Reopen incident ${currentActiveReport.tracking_code} as Ongoing?`)) {
    confirmAction();
  }
}

// Delete Report
async function handleDeleteReportClick() {
  if (!currentActiveReport) return;

  const deleteAction = async () => {
    try {
      const formData = new FormData();
      formData.append('id', currentActiveReport.id);

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
          showToast(data.message || 'Report deleted successfully.', 'success');
        }
        closeModal('viewReportModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        if (typeof showToast === 'function') showToast(data.message || 'Failed to delete report.', 'danger');
      }
    } catch (e) {
      if (typeof showToast === 'function') showToast('Network error during deletion.', 'danger');
    }
  };

  if (typeof showConfirmModal === 'function') {
    showConfirmModal(
      'Delete Incident Report',
      `Are you sure you want to permanently delete Incident Report ${currentActiveReport.tracking_code}? This cannot be undone.`,
      deleteAction
    );
  } else if (confirm(`Delete report ${currentActiveReport.tracking_code}?`)) {
    deleteAction();
  }
}

// Trigger Decision Tree Recommender
async function triggerRecommender(requestId, trackingCode) {
  const runAction = async () => {
    if (typeof showToast === 'function') showToast('Evaluating Decision Tree recommendation engine...', 'info', 2000);
    try {
      const fd = new FormData();
      fd.append('request_id', requestId);
      fd.append('ajax', '1');

      const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/run_recommender.php', {
        method: 'POST',
        body: fd,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(`Recommendation generated! Confidence: ${Math.round(data.confidence || 0)}%`, 'success');
        }
        setTimeout(() => {
          window.location.href = `<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=${data.recommendation_id}`;
        }, 800);
      } else {
        if (typeof showToast === 'function') showToast(data.message || 'Recommender failed.', 'danger');
      }
    } catch (e) {
      if (typeof showToast === 'function') showToast('Network error while running recommender.', 'danger');
    }
  };

  if (typeof showConfirmModal === 'function') {
    showConfirmModal(
      'Run Decision Tree Recommender',
      `Execute Decision Tree resource allocation analysis for Request ${trackingCode}? This evaluates severity, displaced residents, and Central Warehouse stock sufficiency.`,
      runAction
    );
  } else if (confirm(`Execute Decision Tree analysis for Request ${trackingCode}?`)) {
    runAction();
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
