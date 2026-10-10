<?php
// ============================================================================
// Views (Barangay Head): Modern Command & Operations Dashboard
// Refined Modern Aesthetics, Strict Color Palette & Cohesive Typography
// Real-time Jurisdiction Metrics, Demographics, Disaster Incidents & Relief Tracking
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

// Fetch Connected BDRRMC Agency
$agencyStmt = $db->prepare("SELECT * FROM agencies WHERE barangay_id = ? AND agency_type = 'BDRRMC'");
$agencyStmt->execute([$barangayId]);
$connectedAgency = $agencyStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Responder Force & Availability stats
$respKpiStmt = $db->prepare("
    SELECT 
        COUNT(*) AS total_responders,
        SUM(CASE WHEN availability = 'Available' THEN 1 ELSE 0 END) AS ready_responders,
        SUM(CASE WHEN availability = 'Responding' THEN 1 ELSE 0 END) AS responding_responders,
        SUM(CASE WHEN availability = 'On Duty' THEN 1 ELSE 0 END) AS onduty_responders
    FROM users 
    WHERE role = 'responder' AND barangay_id = ? AND status = 'active'
");
$respKpiStmt->execute([$barangayId]);
$respKpis = $respKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_responders' => 0, 'ready_responders' => 0, 'responding_responders' => 0, 'onduty_responders' => 0
];

// 1. Fetch Puroks in this Barangay (Active only)
$purokStmt = $db->prepare("
    SELECT * FROM puroks 
    WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)
    ORDER BY FIELD(risk_level, 'Critical', 'High', 'Moderate', 'Low'), name ASC
");
$purokStmt->execute([$barangayId]);
$allPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Purok Risk Distribution counts
$purokRiskCounts = ['Critical' => 0, 'High' => 0, 'Moderate' => 0, 'Low' => 0];
$purokNamesList = [];
$purokPopList = [];
$purokHouseholdsList = [];

foreach ($allPuroks as $p) {
    $rLevel = $p['risk_level'] ?? 'Moderate';
    if (isset($purokRiskCounts[$rLevel])) {
        $purokRiskCounts[$rLevel]++;
    } else {
        $purokRiskCounts['Moderate']++;
    }
    $purokNamesList[] = $p['name'];
    $purokPopList[] = (int)$p['population'];
    $purokHouseholdsList[] = (int)$p['households'];
}

// 2. Fetch Disaster Incidents & Assistance Requests
$reqStmt = $db->prepare("
    SELECT dr.*, r.id AS recommendation_id, r.status AS rec_status, r.confidence_score, r.recommendation_code
    FROM disaster_requests dr
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id
    WHERE dr.barangay_id = ?
    ORDER BY dr.created_at DESC
");
$reqStmt->execute([$barangayId]);
$allRequests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

$totalRequests = count($allRequests);
$activeRequestsList = [];
$disasterTypeCounts = [];
$totalAffectedFamilies = 0;
$totalDisplacedFamilies = 0;

foreach ($allRequests as $r) {
    $status = $r['status'] ?? 'Ongoing';
    if (in_array($status, ['Ongoing', 'Submitted', 'Under Review', 'Recommendation Ready', 'Approved', 'Allocated', 'Dispatched'], true)) {
        $activeRequestsList[] = $r;
    }
    $dtype = $r['disaster_type'] ?? 'Other';
    $disasterTypeCounts[$dtype] = ($disasterTypeCounts[$dtype] ?? 0) + 1;
    $totalAffectedFamilies += (int)($r['affected_families'] ?? 0);
    $totalDisplacedFamilies += (int)($r['displaced_families'] ?? 0);
}
$activeRequestsCount = count($activeRequestsList);

// 3. Fetch Allocated Relief Provisions
$allocStmt = $db->prepare("
    SELECT ri.*, res.name AS resource_name, res.category, res.code AS sku, res.unit,
           dr.tracking_code, dr.disaster_type, dr.purok_name, r.recommendation_code,
           COALESCE(r.reviewed_at, r.created_at) AS allocated_at
    FROM recommended_items ri
    JOIN recommendations r ON ri.recommendation_id = r.id
    JOIN disaster_requests dr ON r.disaster_request_id = dr.id
    JOIN resources res ON ri.resource_id = res.id
    WHERE dr.barangay_id = ?
    ORDER BY ri.id DESC
");
$allocStmt->execute([$barangayId]);
$allocatedItems = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
$totalReliefAllocated = array_sum(array_column($allocatedItems, 'allocated_quantity'));

// 4. Fetch Evacuation Centers
$evacStmt = $db->prepare("
    SELECT * FROM evacuation_areas 
    WHERE barangay_id = ? 
    ORDER BY (current_evacuees_count / NULLIF(capacity_individuals, 0)) DESC, name ASC
");
$evacStmt->execute([$barangayId]);
$evacuationCenters = $evacStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCenters = count($evacuationCenters);
$totalCapacity = array_sum(array_column($evacuationCenters, 'capacity_individuals'));
$totalCurrentEvacuees = array_sum(array_column($evacuationCenters, 'current_evacuees_count'));
$overallOccupancyPct = $totalCapacity > 0 ? round(($totalCurrentEvacuees / $totalCapacity) * 100) : 0;

// 5. Fetch Verified Residents
$resStmt = $db->prepare("
    SELECT id, first_name, last_name, full_name, username, email, phone, gender, age, purok, status, created_at
    FROM residents 
    WHERE barangay_id = ?
    ORDER BY created_at DESC
");
$resStmt->execute([$barangayId]);
$residentsList = $resStmt->fetchAll(PDO::FETCH_ASSOC);
$totalResidents = count($residentsList);
$activeResidentsCount = count(array_filter($residentsList, fn($u) => $u['status'] === 'active'));

// 6. Fetch Upcoming Preparedness Activities
$actStmt = $db->prepare("
    SELECT * FROM preparedness_activities 
    WHERE (barangay_id = ? OR barangay_id IS NULL)
    ORDER BY start_datetime DESC 
    LIMIT 6
");
$actStmt->execute([$barangayId]);
$recentActivities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Command Dashboard — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Include Chart.js (Local bundle with CDN fallback) -->
<script src="<?= BASE_URL ?>/frontend/js/chart.min.js"></script>
<script>
  if (typeof Chart === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script>');
  }
</script>

<style>
  /* =========================================================================
     MODERN DASHBOARD STYLING (Restrained Hierarchy & Standard Tokens)
     ========================================================================= */
  .dashboard-wrap {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  /* Connected Agency Ribbon */
  .agency-ribbon {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border-light, #E7EDF0);
    border-left: 3px solid var(--color-secondary, #2F6F73);
    border-radius: var(--radius-sm, 6px);
    padding: 8px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    box-shadow: var(--shadow-subtle, 0 1px 3px rgba(23, 50, 77, 0.04));
  }
  .agency-title {
    font-size: var(--text-base, 12px);
    font-weight: 600;
    color: var(--color-primary, #17324D);
  }
  .agency-subtitle {
    font-size: var(--text-xs, 10px);
    color: var(--color-text-secondary, #66737D);
  }

  /* Modern KPI Stat Cards Grid (Clean Typography, No Decorative Icons) */
  .kpi-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
  }
  .clickable-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-primary, 10px);
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    box-shadow: var(--shadow-subtle, 0 1px 3px rgba(23, 50, 77, 0.04));
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-decoration: none;
    color: inherit;
  }
  .clickable-card:hover {
    border-color: var(--color-secondary, #2F6F73);
    box-shadow: var(--shadow-card, 0 2px 6px rgba(23, 50, 77, 0.08));
  }
  .clickable-card.card-alert {
    border-left: 3px solid var(--color-danger, #C62828);
  }
  .clickable-card.card-alert .kpi-big-value {
    color: var(--color-danger, #C62828);
  }
  .kpi-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
  }
  .kpi-card-title {
    font-size: var(--text-xs, 10px);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-text-secondary, #66737D);
  }
  .kpi-card-badge {
    font-size: var(--text-2xs, 9px);
    font-weight: 600;
    color: var(--color-secondary, #2F6F73);
  }
  .clickable-card.card-alert .kpi-card-badge {
    color: var(--color-danger, #C62828);
  }
  .kpi-value-wrap {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin-bottom: 4px;
  }
  .kpi-big-value {
    font-size: 20px;
    font-weight: 700;
    font-family: var(--font-secondary, inherit);
    color: var(--color-primary, #17324D);
    line-height: 1.2;
  }
  .kpi-sub-text {
    font-size: var(--text-xs, 10px);
    color: var(--color-text-secondary, #66737D);
  }
  .kpi-sub-hint {
    font-size: var(--text-2xs, 9px);
    color: var(--color-text-muted, #8A96A0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--color-border-light, #E7EDF0);
    padding-top: 6px;
    margin-top: 6px;
  }
  .kpi-action-tag {
    font-weight: 600;
    color: var(--color-secondary, #2F6F73);
  }

  /* Interactive Charts Section */
  .charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 14px;
  }
  @media (max-width: 992px) {
    .charts-grid {
      grid-template-columns: 1fr;
    }
  }
  .interactive-chart-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-primary, 10px);
    padding: 14px 16px;
    box-shadow: var(--shadow-subtle, 0 1px 3px rgba(23, 50, 77, 0.04));
    display: flex;
    flex-direction: column;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  .interactive-chart-card:hover {
    border-color: var(--color-border, #D9E0E3);
    box-shadow: var(--shadow-card, 0 2px 8px rgba(23, 50, 77, 0.06));
  }
  .chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0);
  }
  .chart-title-wrap h3 {
    font-size: var(--text-card-title, 13px);
    font-weight: 600;
    color: var(--color-primary, #17324D);
    margin: 0;
  }
  .chart-title-wrap p {
    font-size: var(--text-xs, 10px);
    color: var(--color-text-secondary, #66737D);
    margin: 2px 0 0 0;
  }
  .chart-action-btn {
    background: var(--color-surface-subtle, #F0F3F4);
    color: var(--color-secondary, #2F6F73);
    border: 1px solid var(--color-border-light, #E7EDF0);
    padding: 3px 8px;
    border-radius: var(--radius-sm, 6px);
    font-size: var(--text-xs, 10px);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .chart-action-btn:hover {
    background: var(--color-surface, #FFFFFF);
    border-color: var(--color-secondary, #2F6F73);
  }
  .chart-canvas-container {
    position: relative;
    height: 215px;
    width: 100%;
  }
  .chart-footnote {
    font-size: var(--text-2xs, 9.5px);
    color: var(--color-text-muted, #8A96A0);
    text-align: right;
    margin-top: 6px;
  }

  /* Universal Pop-up Dialogs Styling */
  .modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.48) !important;
    backdrop-filter: blur(2px) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1200 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }
  .modal-overlay.active {
    display: flex !important;
  }
  .modal-dialog.modal-wide {
    max-width: 860px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 16px 36px rgba(23, 50, 77, 0.2) !important;
    overflow: hidden !important;
    position: relative !important;
  }
  .modal-header-dash {
    padding: 12px 18px;
    background: var(--color-surface-subtle, #F0F3F4);
    border-bottom: 1px solid var(--color-border-light, #E7EDF0);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .modal-header-dash h3 {
    margin: 0;
    font-size: var(--text-section-title, 14px);
    font-weight: 700;
    color: var(--color-primary, #17324D);
  }
  .modal-header-dash p {
    margin: 2px 0 0 0;
    font-size: var(--text-xs, 10px);
    color: var(--color-text-secondary, #66737D);
  }
  .modal-body-dash {
    padding: 14px 18px;
    overflow-y: auto;
    max-height: calc(100vh - 170px);
  }
  .modal-footer-dash {
    padding: 10px 18px;
    background: var(--color-surface-subtle, #F0F3F4);
    border-top: 1px solid var(--color-border-light, #E7EDF0);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: var(--text-xs, 10px);
    color: var(--color-text-secondary, #66737D);
  }
  .modal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--text-sm, 11px);
  }
  .modal-table th {
    background: var(--color-surface-subtle, #F0F3F4);
    padding: 7px 10px;
    font-weight: 700;
    color: var(--color-text-secondary, #66737D);
    text-align: left;
    border-bottom: 1px solid var(--color-border, #D9E0E3);
    position: sticky;
    top: 0;
    z-index: 2;
  }
  .modal-table td {
    padding: 7px 10px;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0);
    color: var(--color-text, #24313A);
  }
  .modal-table tr:hover td {
    background: var(--color-surface-hover, #F9FAFB);
  }

  /* Overview Split Grid */
  .overview-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
    align-items: start;
  }
  @media (max-width: 992px) {
    .overview-grid {
      grid-template-columns: 1fr;
    }
  }
  .directory-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-surface-subtle, #F0F3F4);
    border: 1px solid var(--color-border-light, #E7EDF0);
    border-radius: var(--radius-sm, 6px);
  }
  .directory-badge {
    width: 34px;
    height: 28px;
    border-radius: 4px;
    background: var(--color-primary, #17324D);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 10.5px;
    font-family: var(--font-secondary, inherit);
    flex-shrink: 0;
  }
</style>

<div class="dashboard-wrap">
  <!-- Page Header (Unified Standard Pattern) -->
  <div class="page-header" style="margin-bottom:0;">
    <div class="page-header-title-wrap">
      <h1>Barangay <?= clean($barangay['name']) ?> Command Dashboard</h1>
      <p class="page-header-desc">
        Lead: <strong><?= clean($barangay['contact_person'] ?? 'Barangay Captain') ?></strong> • 
        Jurisdiction: <strong><?= number_format($barangay['population']) ?> citizens</strong> (<?= number_format($barangay['total_households']) ?> households) • 
        Vulnerability: <span class="badge badge-<?= strtolower($barangay['risk_level']) === 'critical' ? 'danger' : (strtolower($barangay['risk_level']) === 'high' ? 'warning' : 'info') ?>"><?= clean($barangay['risk_level']) ?></span> • 
        Emergency Desk: <strong><?= clean($barangay['contact_number'] ?? 'Not specified') ?></strong>
      </p>
    </div>
    <div class="page-header-actions">
      <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php?action=new" class="btn btn-primary">
        File Incident Report
      </a>
      <a href="<?= BASE_URL ?>/views/barangay/risk-map.php" class="btn btn-outline">
        Hazard Risk Map
      </a>
    </div>
  </div>

  <!-- Connected Agency Lineage Strip -->
  <div class="agency-ribbon">
    <div>
      <span class="agency-title"><?= clean($connectedAgency['name'] ?? ('BDRRMC - ' . $barangay['name'])) ?></span>
      <span class="agency-subtitle"> — BDRRMC Operations Unit • Central Command: ICDRRMO HQ (Hotline: 161)</span>
    </div>
    <div>
      <a href="<?= BASE_URL ?>/views/barangay/agency.php" class="btn btn-outline btn-sm">Agency Details</a>
    </div>
  </div>

  <!-- Unified Interactive KPI Metric Cards (Clean Typography, No Redundant Icons) -->
  <div class="kpi-cards-grid">
    <!-- Card 0: Responder Force -->
    <a href="<?= BASE_URL ?>/views/barangay/users.php?tab=responders" class="clickable-card" title="Manage responder personnel">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Responder Force</span>
        <span class="kpi-card-badge"><?= number_format($respKpis['ready_responders'] ?? 0) ?> Ready</span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($respKpis['total_responders'] ?? 0) ?></span>
        <span class="kpi-sub-text">Total</span>
      </div>
      <div class="kpi-sub-hint">
        <span>Field Readiness</span>
        <span class="kpi-action-tag">Manage</span>
      </div>
    </a>

    <!-- Card 1: Active Disaster Incidents -->
    <div class="clickable-card <?= $activeRequestsCount > 0 ? 'card-alert' : '' ?>" onclick="openDashModal('modalActiveIncidents')" title="View active disaster incident reports">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Active Incidents</span>
        <span class="kpi-card-badge">
          <?= $activeRequestsCount > 0 ? 'Action Required' : 'Normal' ?>
        </span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($activeRequestsCount) ?></span>
        <span class="kpi-sub-text">Ongoing</span>
      </div>
      <div class="kpi-sub-hint">
        <span><?= $totalRequests ?> Recorded</span>
        <span class="kpi-action-tag">Inspect</span>
      </div>
    </div>

    <!-- Card 2: Relief Packs & Provisions Allocated -->
    <div class="clickable-card" onclick="openDashModal('modalReliefAllocations')" title="View relief goods allocation log">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Relief Allocated</span>
        <span class="kpi-card-badge">Supplies</span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalReliefAllocated) ?></span>
        <span class="kpi-sub-text">Units</span>
      </div>
      <div class="kpi-sub-hint">
        <span>ICDRRMO Allocated</span>
        <span class="kpi-action-tag">View Log</span>
      </div>
    </div>

    <!-- Card 3: Sheltered in Evacuation -->
    <div class="clickable-card" onclick="openDashModal('modalEvacuationCenters')" title="View evacuation center occupancy">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Evacuees Sheltered</span>
        <span class="kpi-card-badge"><?= $overallOccupancyPct ?>% Capacity</span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalCurrentEvacuees) ?></span>
        <span class="kpi-sub-text">Individuals</span>
      </div>
      <div class="kpi-sub-hint">
        <span><?= $totalCenters ?> Shelters</span>
        <span class="kpi-action-tag">Status</span>
      </div>
    </div>

    <!-- Card 4: Registered Residents -->
    <div class="clickable-card" onclick="openDashModal('modalResidentsRegistry')" title="View resident citizen directory">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Registered Residents</span>
        <span class="kpi-card-badge"><?= $activeResidentsCount ?> Active</span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalResidents) ?></span>
        <span class="kpi-sub-text">Citizens</span>
      </div>
      <div class="kpi-sub-hint">
        <span>Citizen Registry</span>
        <span class="kpi-action-tag">Directory</span>
      </div>
    </div>

    <!-- Card 5: Purok Clusters Jurisdiction -->
    <div class="clickable-card" onclick="openDashModal('modalPuroksList')" title="View purok risk registry">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Purok Clusters</span>
        <span class="kpi-card-badge" style="color:<?= ($purokRiskCounts['Critical'] + $purokRiskCounts['High']) > 0 ? 'var(--color-danger,#C62828)' : 'var(--color-success,#2E7D32)' ?>;">
          <?= $purokRiskCounts['Critical'] + $purokRiskCounts['High'] ?> High/Crit
        </span>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= count($allPuroks) ?></span>
        <span class="kpi-sub-text">Zones</span>
      </div>
      <div class="kpi-sub-hint">
        <span>Jurisdiction Zones</span>
        <span class="kpi-action-tag">Inspect</span>
      </div>
    </div>
  </div>

  <!-- Interactive Charts Section -->
  <div class="charts-grid">
    <!-- Graph 1: Purok Risk Breakdown (Donut Chart) -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>Purok Vulnerability & Hazard Levels</h3>
          <p>Distribution of local community clusters by risk priority</p>
        </div>
        <button type="button" class="chart-action-btn" onclick="openDashModal('modalPuroksList')">
          Filter Slices
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="purokRiskChart"></canvas>
      </div>
      <div class="chart-footnote">Click any slice to filter puroks by risk level</div>
    </div>

    <!-- Graph 2: Disaster Reports by Type (Bar Chart) -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>Disaster Incidents Reported</h3>
          <p>Historical and active disaster assistance events logged</p>
        </div>
        <button type="button" class="chart-action-btn" onclick="openDashModal('modalActiveIncidents')">
          Filter Bars
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="disasterTypeChart"></canvas>
      </div>
      <div class="chart-footnote">Click any bar to filter incidents by disaster type</div>
    </div>

    <!-- Graph 3: Evacuation Shelters Capacity vs Occupancy -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>Evacuation Shelters Capacity vs Occupancy</h3>
          <p>Capacity intake tracking across designated barangay centers</p>
        </div>
        <button type="button" class="chart-action-btn" onclick="openDashModal('modalEvacuationCenters')">
          View Shelters
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="evacOccupancyChart"></canvas>
      </div>
      <div class="chart-footnote">Click any bar to inspect shelter facility profile</div>
    </div>

    <!-- Graph 4: Purok Demographics Distribution -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>Purok Demographics Distribution</h3>
          <p>Population and household counts across local clusters</p>
        </div>
        <button type="button" class="chart-action-btn" onclick="openDashModal('modalPuroksList')">
          Demographics
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="purokDemographicsChart"></canvas>
      </div>
      <div class="chart-footnote">Click any bar to view cluster hazard exposure</div>
    </div>
  </div>

  <!-- Operational Overview & Quick Access Panels -->
  <div class="overview-grid">
    <!-- Recent Disaster Assistance Reports -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <h3 class="card-title">Recent Disaster Incident Reports</h3>
          <div class="card-subtitle">Assistance requests submitted for Decision Tree resource recommendations</div>
        </div>
        <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php" class="btn btn-outline btn-sm">Full Registry</a>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Tracking Code</th>
              <th>Purok</th>
              <th>Disaster</th>
              <th>Severity</th>
              <th>Displaced</th>
              <th>Status</th>
              <th>Decision Tree</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allRequests)): ?>
              <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--color-text-muted);">No disaster incidents recorded for Barangay <?= clean($barangay['name']) ?>.</td></tr>
            <?php else: ?>
              <?php foreach (array_slice($allRequests, 0, 6) as $r): ?>
                <tr style="cursor:pointer;" onclick="openIncidentDetailsModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Click to view details">
                  <td>
                    <span style="font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);"><?= clean($r['tracking_code']) ?></span>
                    <div style="font-size:9px;color:var(--color-text-muted);"><?= formatDate($r['created_at'], 'M d, Y') ?></div>
                  </td>
                  <td><?= clean($r['purok_name']) ?></td>
                  <td><span style="font-weight:600;"><?= clean($r['disaster_type']) ?></span></td>
                  <td><?= renderStatusBadge($r['severity']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:600;"><?= number_format($r['displaced_families']) ?> fam</td>
                  <td><?= renderStatusBadge($r['status']) ?></td>
                  <td>
                    <?php if (!empty($r['recommendation_id'])): ?>
                      <span class="badge badge-success" style="font-size:8.5px;">Ready (<?= number_format($r['confidence_score'], 0) ?>%)</span>
                    <?php else: ?>
                      <span style="font-size:9px;color:var(--color-text-muted);">Pending</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Right Panel: Emergency Directory & Local Events -->
    <div style="display:flex;flex-direction:column;gap:14px;">
      <!-- Quick Action Directory Card -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3 class="card-title">Emergency Dispatch Directory</h3>
        </div>
        <div class="card-body" style="padding:12px 14px;">
          <div style="display:flex;flex-direction:column;gap:8px;">
            <div class="directory-item">
              <div class="directory-badge">161</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:var(--color-primary);">ICDRRMO Central Dispatch</div>
                <div style="font-size:9.5px;color:var(--color-text-secondary);">Hotline 161 / (063) 221-1234</div>
              </div>
            </div>
            <div class="directory-item">
              <div class="directory-badge" style="background:var(--color-secondary,#2F6F73);">911</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:var(--color-primary);">Iligan City Police / PNP</div>
                <div style="font-size:9.5px;color:var(--color-text-secondary);">Station Desk: (063) 221-2222</div>
              </div>
            </div>
            <div class="directory-item">
              <div class="directory-badge" style="background:var(--color-primary-light,#214364);">BFP</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:var(--color-primary);">Bureau of Fire Protection</div>
                <div style="font-size:9.5px;color:var(--color-text-secondary);">Fire Command: (063) 221-3333</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Preparedness Events -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h3 class="card-title">Community Drills & Events</h3>
          <a href="<?= BASE_URL ?>/views/barangay/preparedness.php" class="btn btn-outline btn-sm">All</a>
        </div>
        <div class="card-body" style="padding:10px 14px;">
          <?php if (empty($recentActivities)): ?>
            <div style="text-align:center;padding:12px;font-size:10px;color:var(--color-text-muted);">No recent preparedness events logged.</div>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($recentActivities as $act): ?>
                <div style="padding:7px 10px;background:var(--color-surface-subtle,#F0F3F4);border-radius:var(--radius-sm,6px);border-left:3px solid var(--color-primary,#17324D);font-size:10.5px;">
                  <div style="font-weight:700;color:var(--color-primary,#17324D);"><?= clean($act['title']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-secondary,#66737D);display:flex;justify-content:space-between;margin-top:2px;">
                    <span><?= clean($act['venue'] ?? 'Barangay Center') ?></span>
                    <span><?= formatDate($act['start_datetime'], 'M d, Y') ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div><!-- /.dashboard-wrap -->

<!-- ========================================================================= -->
<!-- POP-UP MODAL 1: ACTIVE DISASTER INCIDENTS (Triggered by Card 1 or Chart 2) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalActiveIncidents">
  <div class="modal-dialog modal-wide">
    <div class="modal-header-dash">
      <div>
        <h3 id="modalIncidentsTitle">Disaster Incident Reports — Barangay <?= clean($barangay['name']) ?></h3>
        <p id="modalIncidentsSubtitle">Active and recorded disaster assistance requests</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalActiveIncidents')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div style="margin-bottom:10px;">
        <input type="text" class="form-control form-control-sm" placeholder="Search by tracking code, purok, disaster type, severity..." oninput="filterModalTable(this, 'incidentsModalTable')">
      </div>
      <div class="table-responsive">
        <table class="modal-table" id="incidentsModalTable">
          <thead>
            <tr>
              <th>Tracking Code</th>
              <th>Purok Zone</th>
              <th>Disaster Type</th>
              <th>Severity</th>
              <th>Urgency</th>
              <th>Affected Families</th>
              <th>Displaced</th>
              <th>Status</th>
              <th>Filed Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allRequests)): ?>
              <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--color-text-muted);">No disaster incident records found.</td></tr>
            <?php else: ?>
              <?php foreach ($allRequests as $r): ?>
                <tr data-disaster="<?= clean($r['disaster_type']) ?>" data-severity="<?= clean($r['severity']) ?>">
                  <td style="font-weight:700;font-family:var(--font-secondary);color:var(--color-primary);">
                    <?= clean($r['tracking_code']) ?>
                  </td>
                  <td><?= clean($r['purok_name']) ?></td>
                  <td><span class="badge badge-neutral"><?= clean($r['disaster_type']) ?></span></td>
                  <td><?= renderStatusBadge($r['severity']) ?></td>
                  <td><span style="font-size:9.5px;font-weight:600;"><?= clean($r['urgency'] ?? 'Medium') ?></span></td>
                  <td style="font-family:var(--font-secondary);"><?= number_format($r['affected_families']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-danger,#C62828);"><?= number_format($r['displaced_families']) ?></td>
                  <td><?= renderStatusBadge($r['status']) ?></td>
                  <td style="font-size:9.5px;color:var(--color-text-secondary);"><?= formatDate($r['created_at'], 'M d, Y h:i A') ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:9.5px;">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span>Total Records: <?= count($allRequests) ?> Incident Requests</span>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalActiveIncidents')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- POP-UP MODAL 2: RELIEF ALLOCATIONS LOG (Triggered by Card 2)               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalReliefAllocations">
  <div class="modal-dialog modal-wide">
    <div class="modal-header-dash">
      <div>
        <h3>Relief Provisions Allocated & Dispatched</h3>
        <p>Decision Tree generated relief goods dispatched to Barangay <?= clean($barangay['name']) ?></p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalReliefAllocations')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div style="margin-bottom:10px;">
        <input type="text" class="form-control form-control-sm" placeholder="Search by item name, SKU, tracking code, category..." oninput="filterModalTable(this, 'reliefModalTable')">
      </div>
      <div class="table-responsive">
        <table class="modal-table" id="reliefModalTable">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Resource Name</th>
              <th>Category</th>
              <th>Quantity Allocated</th>
              <th>Target Incident</th>
              <th>Purok Area</th>
              <th>Status</th>
              <th>Date Allocated</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allocatedItems)): ?>
              <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--color-text-muted);">No relief provisions currently recorded for this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($allocatedItems as $it): ?>
                <tr>
                  <td style="font-family:var(--font-secondary);font-size:9.5px;color:var(--color-text-secondary);"><?= clean($it['sku'] ?? 'N/A') ?></td>
                  <td style="font-weight:700;color:var(--color-primary);"><?= clean($it['resource_name']) ?></td>
                  <td><span class="badge badge-neutral"><?= clean($it['category'] ?? 'Relief Goods') ?></span></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);font-size:11px;">
                    <?= number_format($it['allocated_quantity']) ?> <?= clean($it['unit'] ?? 'packs') ?>
                  </td>
                  <td>
                    <span style="font-weight:600;"><?= clean($it['tracking_code']) ?></span>
                    <div style="font-size:8.5px;color:var(--color-text-secondary);"><?= clean($it['disaster_type']) ?></div>
                  </td>
                  <td><?= clean($it['purok_name']) ?></td>
                  <td><?= renderStatusBadge($it['status'] ?? 'Allocated') ?></td>
                  <td style="font-size:9.5px;color:var(--color-text-secondary);"><?= formatDate($it['allocated_at'], 'M d, Y') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span>Total Relief Supplies: <strong><?= number_format($totalReliefAllocated) ?> units</strong> across <?= count($allocatedItems) ?> item records</span>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalReliefAllocations')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- POP-UP MODAL 3: EVACUATION CENTERS DIRECTORY (Triggered by Card 3 or Chart 3) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalEvacuationCenters">
  <div class="modal-dialog modal-wide">
    <div class="modal-header-dash">
      <div>
        <h3 id="modalEvacTitle">Barangay Evacuation Shelters & Capacity</h3>
        <p id="modalEvacSubtitle">Operational status, amenities, and current occupancy rates</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalEvacuationCenters')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div style="margin-bottom:10px;">
        <input type="text" class="form-control form-control-sm" placeholder="Search evacuation center name, address, officer..." oninput="filterModalTable(this, 'evacModalTable')">
      </div>
      <div class="table-responsive">
        <table class="modal-table" id="evacModalTable">
          <thead>
            <tr>
              <th>Shelter Name</th>
              <th>Center Type</th>
              <th>Address / Location</th>
              <th>Capacity</th>
              <th>Evacuees</th>
              <th>Occupancy %</th>
              <th>Utilities</th>
              <th>Contact Officer</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($evacuationCenters)): ?>
              <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--color-text-muted);">No evacuation centers registered in this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($evacuationCenters as $ec): ?>
                <?php
                  $pct = $ec['capacity_individuals'] > 0 ? min(100, round(($ec['current_evacuees_count'] / $ec['capacity_individuals']) * 100)) : 0;
                  $utils = [];
                  if (!empty($ec['has_potable_water'])) $utils[] = 'Water';
                  if (!empty($ec['has_electricity'])) $utils[] = 'Power';
                  if (!empty($ec['has_medical_station'])) $utils[] = 'Clinic';
                ?>
                <tr data-center="<?= clean($ec['name']) ?>">
                  <td style="font-weight:700;color:var(--color-primary);"><?= clean($ec['name']) ?></td>
                  <td><span style="font-size:9.5px;"><?= clean($ec['center_type'] ?? 'Shelter') ?></span></td>
                  <td style="font-size:10px;color:var(--color-text-secondary);"><?= clean($ec['location_address']) ?></td>
                  <td style="font-family:var(--font-secondary);"><?= number_format($ec['capacity_individuals']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:<?= $pct >= 90 ? 'var(--color-danger,#C62828)' : 'var(--color-text)' ?>;">
                    <?= number_format($ec['current_evacuees_count']) ?>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                      <div style="flex:1;height:5px;background:var(--color-border-light,#E7EDF0);border-radius:3px;overflow:hidden;min-width:45px;">
                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 90 ? 'var(--color-danger,#C62828)' : ($pct >= 70 ? 'var(--color-warning,#B78103)' : 'var(--color-success,#2E7D32)') ?>;"></div>
                      </div>
                      <span style="font-size:9.5px;font-weight:700;"><?= $pct ?>%</span>
                    </div>
                  </td>
                  <td>
                    <div style="display:flex;gap:3px;flex-wrap:wrap;">
                      <?php if (!empty($utils)): ?>
                        <?php foreach ($utils as $u): ?>
                          <span class="badge badge-neutral" style="font-size:8.5px;padding:1px 5px;"><?= $u ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span style="color:var(--color-text-muted);font-size:9px;">Standard</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-size:10px;font-weight:600;"><?= clean($ec['contact_officer']) ?></div>
                    <div style="font-size:8.5px;color:var(--color-text-secondary);"><?= clean($ec['contact_number']) ?></div>
                  </td>
                  <td><?= renderStatusBadge($ec['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span>Total Capacity: <strong><?= number_format($totalCapacity) ?> individuals</strong> across <?= $totalCenters ?> centers</span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="btn btn-primary btn-sm" style="margin-right:6px;font-size:10.5px;">Manage Shelters</a>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalEvacuationCenters')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- POP-UP MODAL 4: RESIDENTS DIRECTORY (Triggered by Card 4)                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalResidentsRegistry">
  <div class="modal-dialog modal-wide">
    <div class="modal-header-dash">
      <div>
        <h3>Registered Resident Citizens — Barangay <?= clean($barangay['name']) ?></h3>
        <p>Household registrations and emergency contact directory</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalResidentsRegistry')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div style="margin-bottom:10px;">
        <input type="text" class="form-control form-control-sm" placeholder="Search resident name, username, purok, contact phone, email..." oninput="filterModalTable(this, 'residentsModalTable')">
      </div>
      <div class="table-responsive">
        <table class="modal-table" id="residentsModalTable">
          <thead>
            <tr>
              <th>Resident Name & Username</th>
              <th>Purok Assignment</th>
              <th>Contact Details</th>
              <th>Demographics</th>
              <th>Account Status</th>
              <th>Registered Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($residentsList)): ?>
              <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--color-text-muted);">No residents registered yet in this jurisdiction.</td></tr>
            <?php else: ?>
              <?php foreach ($residentsList as $res): ?>
                <tr>
                  <td>
                    <div style="font-weight:700;color:var(--color-primary);font-size:11px;">
                      <?= clean(trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? '')) ?: ($res['full_name'] ?? $res['username'])) ?>
                    </div>
                    <div style="font-size:9px;color:var(--color-text-secondary);">@<?= clean($res['username']) ?></div>
                  </td>
                  <td><span style="font-weight:600;font-size:10.5px;"><?= clean($res['purok'] ?: 'Standard Zone') ?></span></td>
                  <td>
                    <div style="font-size:10px;font-family:var(--font-secondary);font-weight:600;"><?= clean($res['phone'] ?: 'N/A') ?></div>
                    <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($res['email']) ?></div>
                  </td>
                  <td style="font-size:10px;">
                    <?= clean($res['gender'] ?: 'Unspecified') ?>
                    <?php if (!empty($res['age'])): ?>
                      • <?= (int)$res['age'] ?> yrs
                    <?php endif; ?>
                  </td>
                  <td><?= renderStatusBadge($res['status']) ?></td>
                  <td style="font-size:9.5px;color:var(--color-text-secondary);"><?= formatDate($res['created_at'], 'M d, Y') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span>Total Registered: <strong><?= number_format($totalResidents) ?></strong> (<?= $activeResidentsCount ?> Active)</span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/users.php?tab=residents" class="btn btn-primary btn-sm" style="margin-right:6px;font-size:10.5px;">Manage Users</a>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalResidentsRegistry')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- POP-UP MODAL 5: PUROKS & HAZARDS REGISTRY (Triggered by Card 5 or Chart 1/4) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalPuroksList">
  <div class="modal-dialog modal-wide">
    <div class="modal-header-dash">
      <div>
        <h3 id="modalPurokTitle">Purok Clusters & Hazard Risk Registry</h3>
        <p id="modalPurokSubtitle">Community clusters, local hazards, and demographic breakdowns</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalPuroksList')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div style="margin-bottom:10px;">
        <input type="text" class="form-control form-control-sm" id="purokModalSearchInput" placeholder="Search by purok name, hazard types, risk level..." oninput="filterModalTable(this, 'puroksModalTable')">
      </div>
      <div class="table-responsive">
        <table class="modal-table" id="puroksModalTable">
          <thead>
            <tr>
              <th>Purok Cluster Name</th>
              <th>Primary Hazard Exposures</th>
              <th>Vulnerability Level</th>
              <th>Households</th>
              <th>Population</th>
              <th>GPS Coordinates</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allPuroks)): ?>
              <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--color-text-muted);">No purok clusters recorded for this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($allPuroks as $pk): ?>
                <?php
                  $pRisk = $pk['risk_level'] ?? 'Moderate';
                  $pColors = ['Critical' => 'badge-danger', 'High' => 'badge-warning', 'Moderate' => 'badge-info', 'Low' => 'badge-success'];
                  $badgeCls = $pColors[$pRisk] ?? 'badge-neutral';
                ?>
                <tr data-risk="<?= clean($pRisk) ?>" data-purok="<?= clean($pk['name']) ?>">
                  <td style="font-weight:700;color:var(--color-primary);font-size:11.5px;"><?= clean($pk['name']) ?></td>
                  <td style="font-size:10px;color:var(--color-text-secondary);max-width:260px;"><?= clean($pk['hazard_types'] ?: 'Standard Flood/Squall Risk') ?></td>
                  <td><span class="badge <?= $badgeCls ?>" style="font-size:9.5px;"><?= clean($pRisk) ?></span></td>
                  <td style="font-family:var(--font-secondary);font-size:11px;"><?= number_format($pk['households']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);"><?= number_format($pk['population']) ?></td>
                  <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-secondary);white-space:nowrap;"><?= clean($pk['coordinates_lat']) ?>, <?= clean($pk['coordinates_lng']) ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:9.5px;">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span>Total Registered: <strong><?= count($allPuroks) ?> clusters</strong></span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="btn btn-primary btn-sm" style="margin-right:6px;font-size:10.5px;">Manage Puroks</a>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalPuroksList')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: INTERACTIVE CHARTS & POP-UP DISPATCHERS                       -->
<!-- ========================================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Chart Global Defaults (Cohesive Typography & Clean Borders)
  Chart.defaults.font.family = "'Poppins', 'Roboto', sans-serif";
  Chart.defaults.color = '#66737D';
  Chart.defaults.plugins.tooltip.padding = 8;
  Chart.defaults.plugins.tooltip.cornerRadius = 6;
  Chart.defaults.plugins.tooltip.titleFont = { weight: '600', size: 11 };
  Chart.defaults.plugins.tooltip.bodyFont = { size: 10 };

  // --------------------------------------------------------------------------
  // CHART 1: Purok Vulnerability & Hazard Levels (Donut Chart)
  // --------------------------------------------------------------------------
  const ctxPurok = document.getElementById('purokRiskChart')?.getContext('2d');
  if (ctxPurok) {
    const riskLabels = ['Critical Risk', 'High Risk', 'Moderate Risk', 'Low Risk'];
    const riskKeys = ['Critical', 'High', 'Moderate', 'Low'];
    const riskData = [
      <?= (int)$purokRiskCounts['Critical'] ?>,
      <?= (int)$purokRiskCounts['High'] ?>,
      <?= (int)$purokRiskCounts['Moderate'] ?>,
      <?= (int)$purokRiskCounts['Low'] ?>
    ];

    const purokChart = new Chart(ctxPurok, {
      type: 'doughnut',
      data: {
        labels: riskLabels,
        datasets: [{
          data: riskData,
          backgroundColor: ['#C62828', '#B78103', '#2F6F73', '#2E7D32'],
          borderWidth: 2,
          borderColor: '#FFFFFF',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
          legend: {
            position: 'right',
            labels: { boxWidth: 10, font: { size: 10, weight: '500' }, padding: 10 }
          }
        },
        onClick: function (evt, elements) {
          if (elements.length > 0) {
            const index = elements[0].index;
            const selectedRisk = riskKeys[index];
            openFilteredPuroksModal(selectedRisk);
          } else {
            openDashModal('modalPuroksList');
          }
        }
      }
    });
  }

  // --------------------------------------------------------------------------
  // CHART 2: Disaster Incidents Reported by Type (Bar Chart)
  // --------------------------------------------------------------------------
  const ctxDisaster = document.getElementById('disasterTypeChart')?.getContext('2d');
  if (ctxDisaster) {
    const disasterLabels = <?= json_encode(array_keys($disasterTypeCounts ?: ['Flood' => 0, 'Typhoon' => 0, 'Landslide' => 0])) ?>;
    const disasterValues = <?= json_encode(array_values($disasterTypeCounts ?: [0, 0, 0])) ?>;

    const disasterChart = new Chart(ctxDisaster, {
      type: 'bar',
      data: {
        labels: disasterLabels,
        datasets: [{
          label: 'Incidents Reported',
          data: disasterValues,
          backgroundColor: '#17324D',
          hoverBackgroundColor: '#2F6F73',
          borderRadius: 4,
          maxBarThickness: 32
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0, font: { size: 9.5 } },
            grid: { color: '#E7EDF0' }
          },
          x: {
            ticks: { font: { size: 9.5, weight: '600' } },
            grid: { display: false }
          }
        },
        onClick: function (evt, elements) {
          if (elements.length > 0) {
            const index = elements[0].index;
            const clickedType = disasterLabels[index];
            openFilteredIncidentsModal(clickedType);
          } else {
            openDashModal('modalActiveIncidents');
          }
        }
      }
    });
  }

  // --------------------------------------------------------------------------
  // CHART 3: Evacuation Shelters Capacity vs Occupancy (Horizontal Bar)
  // --------------------------------------------------------------------------
  const ctxEvac = document.getElementById('evacOccupancyChart')?.getContext('2d');
  if (ctxEvac) {
    const centerNames = <?= json_encode(array_column($evacuationCenters, 'name')) ?>;
    const centerCap = <?= json_encode(array_column($evacuationCenters, 'capacity_individuals')) ?>;
    const centerOcc = <?= json_encode(array_column($evacuationCenters, 'current_evacuees_count')) ?>;

    const evacChart = new Chart(ctxEvac, {
      type: 'bar',
      data: {
        labels: centerNames,
        datasets: [
          {
            label: 'Capacity',
            data: centerCap,
            backgroundColor: '#D9E0E3',
            borderRadius: 3,
            maxBarThickness: 14
          },
          {
            label: 'Current Occupants',
            data: centerOcc,
            backgroundColor: '#2F6F73',
            borderRadius: 3,
            maxBarThickness: 14
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 10, font: { size: 9.5 } } }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { font: { size: 9 } },
            grid: { color: '#E7EDF0' }
          },
          y: {
            ticks: { font: { size: 9.5, weight: '500' } },
            grid: { display: false }
          }
        },
        onClick: function (evt, elements) {
          if (elements.length > 0) {
            const index = elements[0].index;
            const clickedCenter = centerNames[index];
            openFilteredEvacModal(clickedCenter);
          } else {
            openDashModal('modalEvacuationCenters');
          }
        }
      }
    });
  }

  // --------------------------------------------------------------------------
  // CHART 4: Purok Demographics Distribution (Population & Households)
  // --------------------------------------------------------------------------
  const ctxDemo = document.getElementById('purokDemographicsChart')?.getContext('2d');
  if (ctxDemo) {
    const pNames = <?= json_encode(array_slice($purokNamesList, 0, 10)) ?>;
    const pPops = <?= json_encode(array_slice($purokPopList, 0, 10)) ?>;
    const pHouse = <?= json_encode(array_slice($purokHouseholdsList, 0, 10)) ?>;

    const demoChart = new Chart(ctxDemo, {
      type: 'bar',
      data: {
        labels: pNames,
        datasets: [
          {
            label: 'Population',
            data: pPops,
            backgroundColor: '#17324D',
            borderRadius: 3,
            maxBarThickness: 18
          },
          {
            label: 'Households',
            data: pHouse,
            backgroundColor: '#2F6F73',
            borderRadius: 3,
            maxBarThickness: 18
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 10, font: { size: 9.5 } } }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { font: { size: 9 } },
            grid: { color: '#E7EDF0' }
          },
          x: {
            ticks: { font: { size: 9.5, weight: '500' } },
            grid: { display: false }
          }
        },
        onClick: function (evt, elements) {
          if (elements.length > 0) {
            const index = elements[0].index;
            const clickedPurok = pNames[index];
            openFilteredPuroksModalByName(clickedPurok);
          } else {
            openDashModal('modalPuroksList');
          }
        }
      }
    });
  }
});

// ----------------------------------------------------------------------------
// INTERACTIVE FILTER MODAL DISPATCHERS (Triggered by Clicking Chart Elements)
// ----------------------------------------------------------------------------

function openFilteredPuroksModal(riskLevel) {
  const modal = document.getElementById('modalPuroksList');
  const title = document.getElementById('modalPurokTitle');
  const subtitle = document.getElementById('modalPurokSubtitle');
  const searchInput = document.getElementById('purokModalSearchInput');

  if (title) title.innerText = `${riskLevel} Risk Puroks — Barangay <?= clean($barangay['name']) ?>`;
  if (subtitle) subtitle.innerText = `Filtered list of community clusters categorized under ${riskLevel} vulnerability`;
  if (searchInput) searchInput.value = '';

  const rows = document.querySelectorAll('#puroksModalTable tbody tr');
  rows.forEach(tr => {
    const rowRisk = tr.getAttribute('data-risk');
    tr.style.display = (rowRisk === riskLevel) ? '' : 'none';
  });

  openModal('modalPuroksList');
}

function openFilteredPuroksModalByName(purokName) {
  const modal = document.getElementById('modalPuroksList');
  const title = document.getElementById('modalPurokTitle');
  const subtitle = document.getElementById('modalPurokSubtitle');
  const searchInput = document.getElementById('purokModalSearchInput');

  if (title) title.innerText = `Purok Profile: ${purokName}`;
  if (subtitle) subtitle.innerText = `Demographic and hazard details for ${purokName}`;
  if (searchInput) searchInput.value = '';

  const rows = document.querySelectorAll('#puroksModalTable tbody tr');
  rows.forEach(tr => {
    const name = tr.getAttribute('data-purok');
    tr.style.display = (name === purokName) ? '' : 'none';
  });

  openModal('modalPuroksList');
}

function openIncidentDetailsModal(r) {
  if (r && r.tracking_code) {
    openFilteredIncidentsByCode(r.tracking_code);
  } else {
    openModal('modalActiveIncidents');
  }
}

function openFilteredIncidentsByCode(trackingCode) {
  const title = document.getElementById('modalIncidentsTitle');
  const subtitle = document.getElementById('modalIncidentsSubtitle');
  const searchInput = document.querySelector('#modalActiveIncidents .form-control');

  if (title) title.innerText = `Incident Record: ${trackingCode}`;
  if (subtitle) subtitle.innerText = `Assistance request overview and situational impact`;
  if (searchInput) searchInput.value = trackingCode;

  filterModalTable({ value: trackingCode }, 'incidentsModalTable');
  openModal('modalActiveIncidents');
}

function openFilteredIncidentsModal(disasterType) {
  const title = document.getElementById('modalIncidentsTitle');
  const subtitle = document.getElementById('modalIncidentsSubtitle');
  const searchInput = document.querySelector('#modalActiveIncidents .form-control');

  if (title) title.innerText = `${disasterType} Incidents — Barangay <?= clean($barangay['name']) ?>`;
  if (subtitle) subtitle.innerText = `Filtered list of assistance requests triggered by ${disasterType}`;
  if (searchInput) searchInput.value = '';

  const rows = document.querySelectorAll('#incidentsModalTable tbody tr');
  rows.forEach(tr => {
    const dtype = tr.getAttribute('data-disaster');
    tr.style.display = (dtype === disasterType) ? '' : 'none';
  });

  openModal('modalActiveIncidents');
}

function openFilteredEvacModal(centerName) {
  const title = document.getElementById('modalEvacTitle');
  const subtitle = document.getElementById('modalEvacSubtitle');

  if (title) title.innerText = `Facility Profile: ${centerName}`;
  if (subtitle) subtitle.innerText = `Capacity, current occupancy, and amenities breakdown`;

  const rows = document.querySelectorAll('#evacModalTable tbody tr');
  rows.forEach(tr => {
    const cName = tr.getAttribute('data-center');
    tr.style.display = (cName === centerName) ? '' : 'none';
  });

  openModal('modalEvacuationCenters');
}

const dashModalDefaults = {};
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.modal-overlay').forEach(function (m) {
    const h3 = m.querySelector('.modal-header-dash h3');
    const p = m.querySelector('.modal-header-dash p');
    dashModalDefaults[m.id] = {
      titleId: h3 && h3.id ? h3.id : null, title: h3 ? h3.innerText : '',
      subId: p && p.id ? p.id : null, sub: p ? p.innerText : ''
    };
  });
});

function openDashModal(modalId) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  const d = dashModalDefaults[modalId];
  if (d) {
    if (d.titleId) document.getElementById(d.titleId).innerText = d.title;
    if (d.subId) document.getElementById(d.subId).innerText = d.sub;
  }
  const search = modal.querySelector('.modal-body-dash input');
  if (search) search.value = '';
  modal.querySelectorAll('tbody tr').forEach(function (tr) { tr.style.display = ''; });
  openModal(modalId);
}

function filterModalTable(input, tableId) {
  const filter = input.value.trim().toLowerCase();
  const table = document.getElementById(tableId);
  if (!table) return;

  const rows = table.querySelectorAll('tbody tr');
  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(filter) ? '' : 'none';
  });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
