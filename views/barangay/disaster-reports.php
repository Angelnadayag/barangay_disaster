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
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    die("Barangay jurisdiction not found or unassigned.");
}

$viewTab = $_GET['tab'] ?? 'all';
if (!in_array($viewTab, ['all', 'ongoing', 'completed'], true)) {
    $viewTab = 'all';
}

$filterType = trim($_GET['type'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// Counts for navigation tabs & KPI cards
$countAllStmt = $db->prepare("SELECT COUNT(*) FROM disaster_requests WHERE barangay_id = ?");
$countAllStmt->execute([$barangayId]);
$countAll = (int)$countAllStmt->fetchColumn();

$countOngoingStmt = $db->prepare("SELECT COUNT(*) FROM disaster_requests WHERE barangay_id = ? AND status = 'Ongoing'");
$countOngoingStmt->execute([$barangayId]);
$countOngoing = (int)$countOngoingStmt->fetchColumn();

$countCompletedStmt = $db->prepare("SELECT COUNT(*) FROM disaster_requests WHERE barangay_id = ? AND status = 'Completed'");
$countCompletedStmt->execute([$barangayId]);
$countCompleted = (int)$countCompletedStmt->fetchColumn();

// Telemetry aggregates
$statsStmt = $db->prepare("
    SELECT 
        COALESCE(SUM(affected_families), 0) AS total_affected_families,
        COALESCE(SUM(affected_individuals), 0) AS total_affected_individuals,
        COALESCE(SUM(displaced_families), 0) AS total_displaced_families,
        COALESCE(SUM(casualties_count), 0) AS total_casualties,
        COALESCE(SUM(injuries_count), 0) AS total_injuries,
        COALESCE(SUM(missing_count), 0) AS total_missing
    FROM disaster_requests 
    WHERE barangay_id = ?
");
$statsStmt->execute([$barangayId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Build query
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.status AS recommendation_status, r.confidence_score
    FROM disaster_requests dr
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

$sql .= " ORDER BY FIELD(dr.status, 'Ongoing', 'Submitted', 'Recommendation Ready', 'Approved', 'Allocated', 'Dispatched', 'Completed', 'Rejected'), dr.created_at DESC";
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

// 2. Dynamically extract Hazard Types created for/by this Barangay (from its puroks & hazard options)
$dynamicHazardTypes = [];

// From puroks created by this barangay
foreach ($localPuroks as $lp) {
    if (!empty($lp['hazard_types'])) {
        $parts = explode(',', $lp['hazard_types']);
        foreach ($parts as $p) {
            $trim = trim($p);
            if ($trim !== '' && !in_array($trim, $dynamicHazardTypes, true)) {
                $dynamicHazardTypes[] = $trim;
            }
        }
    }
}

// From hazard_type_options in database
$hazOptionsStmt = $db->query("SELECT name FROM hazard_type_options ORDER BY name ASC");
$hazOptions = $hazOptionsStmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($hazOptions as $ho) {
    $hoTrim = trim($ho);
    if ($hoTrim !== '' && !in_array($hoTrim, $dynamicHazardTypes, true)) {
        $dynamicHazardTypes[] = $hoTrim;
    }
}

// Core emergency hazard types as baseline if database has none
$coreHazards = ['Flood', 'Flash Flood', 'Typhoon', 'Landslide', 'Fire', 'Earthquake', 'Storm Surge'];
foreach ($coreHazards as $ch) {
    if (!in_array($ch, $dynamicHazardTypes, true)) {
        $dynamicHazardTypes[] = $ch;
    }
}
natcasesort($dynamicHazardTypes);
$dynamicHazardTypes = array_values($dynamicHazardTypes);

// Mapping of purok name -> primary hazard for smart auto-suggestion
$purokHazardMap = [];
foreach ($localPuroks as $lp) {
    $purokHazardMap[$lp['name']] = !empty($lp['hazard_types']) ? $lp['hazard_types'] : '';
}

$pageTitle = "Disaster Reports — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<style>
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
  box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.kpi-stat-card.ongoing-card {
  border-left: 4px solid #F59E0B;
}
.kpi-stat-card.completed-card {
  border-left: 4px solid #10B981;
}
.kpi-stat-card.displaced-card {
  border-left: 4px solid #EF4444;
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
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
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
        Record local disaster incidents, assess displaced residents, and track real-time operational status (automatically marked <strong>Ongoing</strong> until concluded).
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-primary" onclick="openCreateReportModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        File Incident Report
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:var(--space-3, 12px);margin-bottom:var(--space-4, 16px);">
    <div class="kpi-stat-card">
      <div style="font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;">Total Filed Reports</div>
      <div style="font-size:22px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);"><?= number_format($countAll) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Barangay jurisdiction registry</div>
    </div>
    <div class="kpi-stat-card ongoing-card">
      <div style="font-size:10px;font-weight:700;color:#B45309;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:4px;">
        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#F59E0B;"></span> Active / Ongoing
      </div>
      <div style="font-size:22px;font-weight:700;color:#B45309;font-family:var(--font-secondary);"><?= number_format($countOngoing) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Active incidents requiring response</div>
    </div>
    <div class="kpi-stat-card completed-card">
      <div style="font-size:10px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:0.5px;">Concluded / Completed</div>
      <div style="font-size:22px;font-weight:700;color:#047857;font-family:var(--font-secondary);"><?= number_format($countCompleted) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Ended response operations</div>
    </div>
    <div class="kpi-stat-card displaced-card">
      <div style="font-size:10px;font-weight:700;color:#B91C1C;text-transform:uppercase;letter-spacing:0.5px;">Displaced Families</div>
      <div style="font-size:22px;font-weight:700;color:#B91C1C;font-family:var(--font-secondary);"><?= number_format($stats['total_displaced_families']) ?></div>
      <div style="font-size:9.5px;color:var(--color-text-secondary);">Across <?= number_format($stats['total_affected_individuals']) ?> affected residents</div>
    </div>
  </div>

  <!-- Navigation Tabs (Matching style in Manage Residents) -->
  <div style="display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--color-border);margin-bottom:var(--space-3, 12px);">
    <a href="?tab=all<?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'all' ? 'active' : '' ?>">
      All Incident Reports
      <span class="badge badge-neutral" style="font-size:9.5px;padding:2px 6px;"><?= $countAll ?></span>
    </a>
    <a href="?tab=ongoing<?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'ongoing' ? 'active' : '' ?>">
      Ongoing Incidents
      <span class="badge badge-warning" style="font-size:9.5px;padding:2px 6px;"><?= $countOngoing ?></span>
    </a>
    <a href="?tab=completed<?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="report-tab-link <?= $viewTab === 'completed' ? 'active' : '' ?>">
      Completed / Concluded
      <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;"><?= $countCompleted ?></span>
    </a>
  </div>

  <!-- Filter & Search Bar -->
  <div class="card" style="margin-bottom: var(--space-4, 16px);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">
        <div class="filter-group" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
          <div class="search-input-wrap" style="min-width:240px;flex:1;">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search tracking #, purok, disaster type..." value="<?= clean($search) ?>">
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
            <option value="Submitted" <?= $filterStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="Recommendation Ready" <?= $filterStatus === 'Recommendation Ready' ? 'selected' : '' ?>>Recommendation Ready</option>
            <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Allocated" <?= $filterStatus === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
            <option value="Dispatched" <?= $filterStatus === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
          </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline" style="padding:6px 12px;">Filter</button>
          <?php if (!empty($search) || !empty($filterType) || !empty($filterSeverity) || !empty($filterStatus)): ?>
            <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline" style="color:var(--color-text-muted);padding:6px 12px;">Reset</a>
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
          <th>Impact & Displaced</th>
          <th>Status</th>
          <th>Date Filed</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="7" style="text-align:center;padding:36px;color:var(--color-text-muted);">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;opacity:0.6;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
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

              <!-- Purok Cluster -->
              <td>
                <div style="font-weight:600;color:var(--color-primary);display:flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                  <?= clean($r['purok_name']) ?>
                </div>
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

              <!-- Impact & Displaced -->
              <td style="font-family:var(--font-secondary);font-size:11px;">
                <div style="font-weight:600;color:var(--color-text);">
                  <?= number_format($r['affected_families']) ?> Fam (<?= number_format($r['affected_individuals']) ?> Ind)
                </div>
                <?php if ((int)$r['displaced_families'] > 0): ?>
                  <div style="font-size:9.5px;color:var(--color-danger);font-weight:600;">
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
                  <span class="badge badge-success" style="font-weight:700;">✓ Completed</span>
                  <?php if (!empty($r['completed_at'])): ?>
                    <div style="font-size:8.5px;color:var(--color-text-muted);margin-top:2px;">Ended <?= formatDate($r['completed_at'], 'M d, h:i A') ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <?= renderStatusBadge($r['status']) ?>
                <?php endif; ?>
              </td>

              <!-- Date Filed -->
              <td style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);white-space:nowrap;">
                <?= formatDate($r['created_at'], 'M d, Y') ?>
                <div style="font-size:8.5px;color:var(--color-text-muted);"><?= formatDate($r['created_at'], 'h:i A') ?></div>
              </td>

              <!-- Action: ONLY THE "VIEW" BUTTON (Styled like Manage Residents) -->
              <td style="text-align:right;">
                <div style="display:inline-flex;gap:4px;align-items:center;">
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 10px;font-size:10.5px;font-weight:600;" onclick="viewReportDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)">
                    View
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
  <form class="modal-dialog modal-dialog-lg" id="createReportForm" onsubmit="handleCreateReport(event)" style="max-width:680px;">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge-ongoing-pulse" style="font-size:10px;">Auto-Status: Ongoing</span>
        <h3 class="modal-title" style="margin:0;">File Disaster Incident Report</h3>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createReportModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px;">
      <div style="background:rgba(245, 158, 11, 0.08);border:1px solid rgba(245, 158, 11, 0.25);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:11px;color:#92400E;display:flex;align-items:center;gap:8px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <div>When filed, this report is <strong>automatically set to Ongoing</strong> to activate emergency telemetry until you conclude or end the operation.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster Location</label>
          <select name="purok_name" id="create_purok_name" class="form-control" required onchange="handlePurokSelectChange(this.value, 'create')">
            <option value="">Select Purok Cluster</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($localPuroks)): ?>
            <small style="color:var(--color-danger);font-size:9.5px;display:block;margin-top:2px;">No puroks created yet for this barangay. Add puroks in Manage Purok.</small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Disaster Hazard Type</label>
          <select name="disaster_type" id="create_disaster_type" class="form-control" required>
            <option value="">Select Hazard Type</option>
            <?php foreach ($dynamicHazardTypes as $ht): ?>
              <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
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
      <div style="margin:12px 0 8px 0;padding-bottom:4px;border-bottom:1px solid var(--color-border-light);font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;">
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
        <textarea name="situation_overview" class="form-control" rows="2" placeholder="Brief narrative: water depth, blocked bridges, trapped households, power outages..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Requested Assistance / Supplies</label>
        <textarea name="requested_assistance" class="form-control" rows="2" placeholder="e.g. 100 Family Food Packs, rescue rubber boat, medical team, hygiene kits..."></textarea>
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
  <form class="modal-dialog modal-dialog-lg" id="viewReportForm" onsubmit="handleSaveReportChanges(event)" style="max-width:720px;">
    <input type="hidden" name="id" id="view_report_id">
    <input type="hidden" name="status" id="view_report_status_hidden">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
        <span style="font-size:11px;color:var(--color-text-muted);font-family:var(--font-secondary);" id="viewModalTrackingHeader"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewReportModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px;">
      <!-- Incident Identity Banner -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
          <div id="view_report_title_display" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
          <div id="view_report_meta_sub" style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px;"></div>
        </div>
        <div id="view_status_quick_callout"></div>
      </div>

      <!-- Section Heading -->
      <div style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading" style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          Incident Report Details
        </h4>
        <span id="viewModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:600;">(View Only Mode)</span>
      </div>

      <!-- Form Inputs (Disabled on View, Enabled on Edit) -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Purok Cluster</label>
          <select name="purok_name" id="view_purok_name" class="form-control" disabled required onchange="handlePurokSelectChange(this.value, 'view')">
            <option value="">Select Purok Cluster</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Hazard Type</label>
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
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:8px;padding:12px;margin:10px 0 14px 0;">
        <div style="font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:8px;">Population Impact Telemetry</div>
        <div class="form-row" style="margin-bottom:8px;">
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

      <!-- Resolution Section (for Completed or when concluding) -->
      <div id="viewResolutionSection" style="margin-top:12px;padding:12px;background:rgba(16, 185, 129, 0.06);border:1px solid rgba(16, 185, 129, 0.25);border-radius:8px;">
        <div style="font-size:10.5px;font-weight:700;color:#047857;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:5px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Resolution & Accomplishment Notes
        </div>
        <textarea name="resolution_notes" id="view_resolution_notes" class="form-control" rows="2" placeholder="Record incident conclusion summary, relief distribution completion, or recovery notes..." disabled></textarea>
        <div id="view_completed_at_timestamp" style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Timestamps Footer -->
      <div style="font-size:10px;color:var(--color-text-muted);margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border-light);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
        <div><strong style="color:var(--color-text);">Filed:</strong> <span id="view_created_at_text"></span></div>
        <div><strong style="color:var(--color-text);">Last Updated:</strong> <span id="view_updated_at_text"></span></div>
      </div>
    </div>

    <!-- Modal Footer Actions (Full CRUD & Status Control) -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left side: Status Transition & Delete -->
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <!-- End / Finish Incident Button -->
        <button type="button" class="btn btn-sm" id="btnFinishIncident" style="background:#047857;color:#FFF;border-color:#047857;font-weight:600;" onclick="handleFinishIncidentClick()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Finish / End Incident
        </button>

        <!-- Reopen Button (if already completed) -->
        <button type="button" class="btn btn-outline btn-sm" id="btnReopenIncident" style="color:#B45309;border-color:#F59E0B;display:none;" onclick="handleReopenIncidentClick()">
          Reopen Incident (Set to Ongoing)
        </button>

        <!-- Delete Button -->
        <button type="button" class="btn btn-outline btn-sm" id="btnDeleteReport" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="handleDeleteReportClick()">
          Delete Report
        </button>
      </div>

      <!-- Right side: Edit Toggle & Close -->
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewReportModal')">Close</button>
        <button type="button" class="btn btn-outline btn-sm" id="btnToggleEditMode" onclick="toggleReportEditMode()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
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

// Dynamic mapping of Puroks to their created hazards
const purokHazardMap = <?= json_encode($purokHazardMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Auto-suggest / highlight hazard based on selected Purok
function handlePurokSelectChange(purokName, targetType) {
  if (!purokName) return;
  const selectElem = document.getElementById(targetType === 'create' ? 'create_disaster_type' : 'view_disaster_type');
  if (!selectElem) return;

  const rawHazards = purokHazardMap[purokName];
  if (rawHazards) {
    // Extract first hazard name from comma-separated list
    const firstHazard = rawHazards.split(',')[0].trim();
    if (firstHazard) {
      for (let i = 0; i < selectElem.options.length; i++) {
        const optVal = selectElem.options[i].value.toLowerCase();
        const hazLower = firstHazard.toLowerCase();
        if (optVal === hazLower || hazLower.includes(optVal) || optVal.includes(hazLower)) {
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
  openModal('createReportModal');
}

// Auto open modal if ?action=new
if (new URLSearchParams(window.location.search).get('action') === 'new') {
  openCreateReportModal();
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

// Populate and open View / In-Place Edit Modal
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

  // Populate Input Fields (Ensure options exist dynamically)
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

  document.getElementById('view_severity').value = req.severity;
  document.getElementById('view_urgency').value = req.urgency;
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
}

// Toggle In-Place Edit Mode
function toggleReportEditMode() {
  setFormEditMode(!isEditModeActive);
}

function setFormEditMode(enableEdit) {
  isEditModeActive = enableEdit;

  const fields = [
    'view_disaster_type', 'view_purok_name', 'view_severity', 'view_urgency',
    'view_affected_families', 'view_displaced_families', 'view_affected_individuals',
    'view_casualties_count', 'view_injuries_count', 'view_missing_count',
    'view_situation_overview', 'view_requested_assistance', 'view_resolution_notes'
  ];

  fields.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = !enableEdit;
  });

  const btnToggle = document.getElementById('btnToggleEditMode');
  const btnSave = document.getElementById('btnSaveReportChanges');
  const hintText = document.getElementById('viewModeHintText');

  if (enableEdit) {
    btnToggle.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Cancel Edit';
    btnToggle.className = 'btn btn-outline btn-sm';
    btnSave.style.display = 'inline-flex';
    hintText.innerText = '(Editing Mode — Fields Editable)';
    hintText.style.color = 'var(--color-primary)';
  } else {
    btnToggle.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Edit Report';
    btnToggle.className = 'btn btn-outline btn-sm';
    btnSave.style.display = 'none';
    hintText.innerText = '(View Only Mode)';
    hintText.style.color = 'var(--color-text-muted)';
  }
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
