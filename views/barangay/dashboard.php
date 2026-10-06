<?php
// ============================================================================
// Views (Barangay Head): Modern Command & Operations Dashboard
// Features: Sleek Glassmorphism, Interactive/Clickable KPI Cards & Charts with Pop-ups,
// Real-time Jurisdiction Metrics, Demographics, Disaster Incidents & Relief Tracking.
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
    FROM users 
    WHERE barangay_id = ? AND role = 'resident'
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
     MODERN DASHBOARD AESTHETICS & MICRO-ANIMATIONS
     ========================================================================= */
  :root {
    --dash-primary: #17324D;
    --dash-secondary: #2F6F73;
    --dash-accent: #0EA5E9;
    --dash-critical: #EF4444;
    --dash-warning: #F59E0B;
    --dash-success: #10B981;
    --dash-card-bg: #FFFFFF;
    --dash-card-border: #E2E8F0;
    --dash-card-hover: 0 12px 28px -6px rgba(23, 50, 77, 0.12), 0 4px 12px -2px rgba(23, 50, 77, 0.06);
  }

  /* Hero Banner */
  .hero-command-banner {
    position: relative;
    background: linear-gradient(135deg, #17324D 0%, #1F4565 55%, #2F6F73 100%);
    color: #FFFFFF;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 10px 25px -5px rgba(23, 50, 77, 0.25);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
  }
  .hero-command-banner::after {
    content: '';
    position: absolute;
    top: -50px;
    right: -50px;
    width: 240px;
    height: 240px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
  }
  .hero-title {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.3px;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .hero-subtitle {
    font-size: 12.5px;
    color: #E2E8F0;
    margin: 0;
    line-height: 1.5;
  }
  .hero-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    backdrop-filter: blur(4px);
    margin-top: 8px;
  }

  /* Interactive KPI Cards Grid */
  .kpi-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .clickable-card {
    background: var(--dash-card-bg);
    border: 1px solid var(--dash-card-border);
    border-radius: 12px;
    padding: 18px 20px;
    position: relative;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    overflow: hidden;
  }
  .clickable-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: transparent;
    transition: background 0.25s ease;
  }
  .clickable-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--dash-card-hover);
    border-color: #CBD5E1;
  }
  .clickable-card.card-theme-danger::before { background: var(--dash-critical); }
  .clickable-card.card-theme-warning::before { background: var(--dash-warning); }
  .clickable-card.card-theme-primary::before { background: var(--dash-secondary); }
  .clickable-card.card-theme-success::before { background: var(--dash-success); }
  .clickable-card.card-theme-info::before { background: var(--dash-accent); }

  .kpi-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
  }
  .kpi-card-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--color-text-secondary, #64748B);
  }
  .kpi-icon-pill {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease;
  }
  .clickable-card:hover .kpi-icon-pill {
    transform: scale(1.1);
  }
  .kpi-value-wrap {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 6px;
  }
  .kpi-big-value {
    font-size: 28px;
    font-weight: 800;
    font-family: var(--font-secondary, inherit);
    color: var(--dash-primary);
    line-height: 1;
  }
  .kpi-sub-hint {
    font-size: 10px;
    color: var(--color-text-muted, #94A3B8);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px dashed #E2E8F0;
    padding-top: 8px;
    margin-top: 6px;
  }
  .kpi-click-tag {
    font-weight: 600;
    color: var(--dash-secondary);
    display: inline-flex;
    align-items: center;
    gap: 3px;
  }

  /* Graphs Section */
  .charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
  }
  @media (max-width: 992px) {
    .charts-grid {
      grid-template-columns: 1fr;
    }
  }

  .interactive-chart-card {
    background: #FFFFFF;
    border: 1px solid var(--dash-card-border);
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    display: flex;
    flex-direction: column;
    position: relative;
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
  }
  .interactive-chart-card:hover {
    border-color: #CBD5E1;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
  }
  .chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #F1F5F9;
  }
  .chart-title-wrap h3 {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--dash-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .chart-title-wrap p {
    font-size: 10px;
    color: #64748B;
    margin: 2px 0 0 0;
  }
  .chart-click-badge {
    background: #F0FDFA;
    color: var(--dash-secondary);
    border: 1px solid #CCFBF1;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 9.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .chart-click-badge:hover {
    background: #CCFBF1;
  }
  .chart-canvas-container {
    position: relative;
    height: 250px;
    width: 100%;
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
    background-color: rgba(23, 50, 77, 0.55) !important;
    backdrop-filter: blur(3px) !important;
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
    animation: fadeInModal 0.2s ease-out;
  }
  @keyframes fadeInModal {
    from { opacity: 0; transform: scale(0.98); }
    to { opacity: 1; transform: scale(1); }
  }

  .modal-dialog.modal-wide {
    max-width: 860px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: #FFFFFF !important;
    border-radius: 12px !important;
    border: 1px solid #CBD5E1 !important;
    box-shadow: 0 16px 40px -8px rgba(0, 0, 0, 0.25) !important;
    overflow: hidden !important;
    position: relative !important;
  }
  .modal-header-dash {
    padding: 14px 20px;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .modal-body-dash {
    padding: 18px 20px;
    overflow-y: auto;
    max-height: calc(100vh - 180px);
  }
  .modal-footer-dash {
    padding: 12px 20px;
    background: #F8FAFC;
    border-top: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .modal-search-box {
    margin-bottom: 12px;
    position: relative;
  }
  .modal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
  }
  .modal-table th {
    background: #F1F5F9;
    padding: 8px 10px;
    font-weight: 700;
    color: #475569;
    text-align: left;
    border-bottom: 1px solid #CBD5E1;
    position: sticky;
    top: 0;
    z-index: 2;
  }
  .modal-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #F1F5F9;
    color: #1E293B;
  }
  .modal-table tr:hover td {
    background: #F8FAFC;
  }
</style>

<div class="dashboard-wrap">
  <!-- Top Command Hero Banner -->
  <div class="hero-command-banner">
    <div style="z-index:2;max-width:700px;">
      <div class="hero-badge-pill" style="margin-top:0;margin-bottom:8px;">
        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#10B981;animation:pulseDot 1.5s infinite;"></span>
        Operational Jurisdiction Active • Real-Time Command
      </div>
      <h1 class="hero-title">
        Barangay <?= clean($barangay['name']) ?> Command Center
      </h1>
      <p class="hero-subtitle">
        Official Lead: <strong><?= clean($barangay['contact_person'] ?? 'Barangay Captain') ?></strong> • 
        Official Jurisdiction: <strong><?= number_format($barangay['population']) ?> citizens</strong> 
        (<?= number_format($barangay['total_households']) ?> households) • 
        Vulnerability: <span class="badge badge-<?= strtolower($barangay['risk_level']) === 'critical' ? 'danger' : (strtolower($barangay['risk_level']) === 'high' ? 'warning' : 'info') ?>" style="vertical-align:middle;"><?= clean($barangay['risk_level']) ?></span>
      </p>
      <div style="font-size:10px;color:rgba(255,255,255,0.7);margin-top:6px;">
        Emergency Ops Desk: <strong><?= clean($barangay['contact_number'] ?? 'Not specified') ?></strong> • 
        Coordinates: <strong><?= clean($barangay['coordinates_lat']) ?>, <?= clean($barangay['coordinates_lng']) ?></strong>
      </div>
    </div>
    <div style="display:flex;gap:8px;z-index:2;flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php?action=new" class="btn btn-primary" style="background:#0EA5E9;border-color:#0EA5E9;font-weight:600;box-shadow:0 4px 12px rgba(14,165,233,0.35);">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        File Incident Report
      </a>
      <a href="<?= BASE_URL ?>/views/barangay/risk-map.php" class="btn btn-outline" style="color:#FFF;border-color:rgba(255,255,255,0.4);background:rgba(255,255,255,0.08);">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon></svg>
        Hazard Risk Map
      </a>
    </div>
  </div>

  <!-- Interactive Clickable KPI Metric Cards (Click to Pop-up Data) -->
  <div class="kpi-cards-grid">
    <!-- Card 1: Active Disaster Incidents -->
    <div class="clickable-card card-theme-danger" onclick="openDashModal('modalActiveIncidents')" title="Click to view detailed list of active disaster incidents">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Active Incidents</span>
        <div class="kpi-icon-pill" style="background:#FEE2E2;color:#DC2626;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        </div>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value" style="color:<?= $activeRequestsCount > 0 ? '#DC2626' : '#1E293B' ?>;"><?= number_format($activeRequestsCount) ?></span>
        <span style="font-size:10.5px;color:#64748B;">Pending/Active</span>
      </div>
      <div class="kpi-sub-hint">
        <span><?= $totalRequests ?> Lifetime Reports Filed</span>
        <span class="kpi-click-tag">View Details ↗</span>
      </div>
    </div>

    <!-- Card 2: Relief Packs & Provisions Allocated -->
    <div class="clickable-card card-theme-primary" onclick="openDashModal('modalReliefAllocations')" title="Click to view full relief provisions and allocation log">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Relief Items Received</span>
        <div class="kpi-icon-pill" style="background:#E0F2FE;color:#0284C7;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
        </div>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalReliefAllocated) ?></span>
        <span style="font-size:10.5px;color:#64748B;">Units/Packs</span>
      </div>
      <div class="kpi-sub-hint">
        <span>From ICDRRMO Decision Tree</span>
        <span class="kpi-click-tag">View Items ↗</span>
      </div>
    </div>

    <!-- Card 3: Sheltered in Evacuation -->
    <div class="clickable-card card-theme-warning" onclick="openDashModal('modalEvacuationCenters')" title="Click to view evacuation centers status and occupancy">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Evacuees Sheltered</span>
        <div class="kpi-icon-pill" style="background:#FEF3C7;color:#D97706;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
        </div>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalCurrentEvacuees) ?></span>
        <span style="font-size:10.5px;color:#64748B;">/ <?= number_format($totalCapacity) ?> Cap (<?= $overallOccupancyPct ?>%)</span>
      </div>
      <div class="kpi-sub-hint">
        <span>Across <?= $totalCenters ?> Evacuation Center(s)</span>
        <span class="kpi-click-tag">View Shelters ↗</span>
      </div>
    </div>

    <!-- Card 4: Registered Residents -->
    <div class="clickable-card card-theme-success" onclick="openDashModal('modalResidentsRegistry')" title="Click to view verified residents directory">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Registered Residents</span>
        <div class="kpi-icon-pill" style="background:#D1FAE5;color:#059669;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
        </div>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= number_format($totalResidents) ?></span>
        <span style="font-size:10.5px;color:#10B981;"><?= $activeResidentsCount ?> Active</span>
      </div>
      <div class="kpi-sub-hint">
        <span>Citizen Identity Records</span>
        <span class="kpi-click-tag">View Directory ↗</span>
      </div>
    </div>

    <!-- Card 5: Purok Clusters Jurisdiction -->
    <div class="clickable-card card-theme-info" onclick="openDashModal('modalPuroksList')" title="Click to view local puroks and hazard registry">
      <div class="kpi-card-header">
        <span class="kpi-card-title">Purok Clusters</span>
        <div class="kpi-icon-pill" style="background:#E0E7FF;color:#4F46E5;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
        </div>
      </div>
      <div class="kpi-value-wrap">
        <span class="kpi-big-value"><?= count($allPuroks) ?></span>
        <span style="font-size:10.5px;color:<?= ($purokRiskCounts['Critical'] + $purokRiskCounts['High']) > 0 ? '#DC2626' : '#059669' ?>;">
          <?= $purokRiskCounts['Critical'] + $purokRiskCounts['High'] ?> High/Crit Risk
        </span>
      </div>
      <div class="kpi-sub-hint">
        <span>Sub-community Zones</span>
        <span class="kpi-click-tag">View Puroks ↗</span>
      </div>
    </div>
  </div>

  <!-- Interactive Clickable Charts Section -->
  <div class="charts-grid">
    <!-- Graph 1: Purok Risk & Vulnerability Breakdown (Donut Chart) -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--dash-secondary);"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a10 10 0 0 1 10 10"></path></svg>
            Purok Vulnerability & Hazard Levels
          </h3>
          <p>Distribution of local puroks categorized by risk priority</p>
        </div>
        <button type="button" class="chart-click-badge" onclick="openDashModal('modalPuroksList')" title="View all puroks breakdown">
          Clickable Slices ↗
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="purokRiskChart"></canvas>
      </div>
      <div style="font-size:9.5px;color:#94A3B8;text-align:center;margin-top:10px;">
        💡 <em>Tip: Click any slice in the donut chart to instantly view puroks in that risk category.</em>
      </div>
    </div>

    <!-- Graph 2: Disaster Reports by Type & Severity (Bar Chart) -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--dash-accent);"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
            Disaster Incidents Reported
          </h3>
          <p>Historical and active disaster assistance events logged</p>
        </div>
        <button type="button" class="chart-click-badge" onclick="openDashModal('modalActiveIncidents')" title="View incidents list">
          Clickable Bars ↗
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="disasterTypeChart"></canvas>
      </div>
      <div style="font-size:9.5px;color:#94A3B8;text-align:center;margin-top:10px;">
        💡 <em>Tip: Click on any disaster type bar to view incident reports for that hazard.</em>
      </div>
    </div>

    <!-- Graph 3: Evacuation Shelters Capacity vs Occupancy -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#D97706;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
            Evacuation Shelters Capacity vs Current Occupancy
          </h3>
          <p>Real-time intake tracking across designated barangay centers</p>
        </div>
        <button type="button" class="chart-click-badge" onclick="openDashModal('modalEvacuationCenters')" title="View evacuation center details">
          Explore Shelters ↗
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="evacOccupancyChart"></canvas>
      </div>
      <div style="font-size:9.5px;color:#94A3B8;text-align:center;margin-top:10px;">
        💡 <em>Tip: Click any center's bar to view amenities, contact personnel, and status.</em>
      </div>
    </div>

    <!-- Graph 4: Purok Demographics (Population & Households) -->
    <div class="interactive-chart-card">
      <div class="chart-header">
        <div class="chart-title-wrap">
          <h3>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#10B981;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            Purok Demographics Distribution
          </h3>
          <p>Population and household density across local community clusters</p>
        </div>
        <button type="button" class="chart-click-badge" onclick="openDashModal('modalPuroksList')" title="View full demographic table">
          View Puroks ↗
        </button>
      </div>
      <div class="chart-canvas-container">
        <canvas id="purokDemographicsChart"></canvas>
      </div>
      <div style="font-size:9.5px;color:#94A3B8;text-align:center;margin-top:10px;">
        💡 <em>Tip: Click any purok bar to view localized hazard exposures and coordinates.</em>
      </div>
    </div>
  </div>

  <!-- Operational Overview & Quick Access Panels -->
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
    <!-- Recent Disaster Assistance Reports -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <h3 class="card-title">Recent Disaster Incident Reports</h3>
          <div class="card-subtitle">Tracking assistance requests submitted to ICDRRMO for Decision Tree allocation</div>
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
              <tr><td colspan="7" style="text-align:center;padding:24px;color:#94A3B8;">No disaster incidents recorded for Barangay <?= clean($barangay['name']) ?>.</td></tr>
            <?php else: ?>
              <?php foreach (array_slice($allRequests, 0, 6) as $r): ?>
                <tr style="cursor:pointer;" onclick="openIncidentDetailsModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Click to view details">
                  <td>
                    <span style="font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);"><?= clean($r['tracking_code']) ?></span>
                    <div style="font-size:9px;color:#64748B;"><?= formatDate($r['created_at'], 'M d, Y') ?></div>
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
                      <span style="font-size:9px;color:#94A3B8;">Pending</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Right Panel: Emergency Directory & Local Centers -->
    <div style="display:flex;flex-direction:column;gap:18px;">
      <!-- Quick Action Directory Card -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3 class="card-title">Emergency Response Dispatch Directory</h3>
        </div>
        <div class="card-body" style="padding:14px 16px;">
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
              <div style="width:30px;height:30px;border-radius:6px;background:#DC2626;color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">161</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:#1E293B;">ICDRRMO Central Dispatch</div>
                <div style="font-size:9.5px;color:#64748B;">Hotline 161 / (063) 221-1234</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
              <div style="width:30px;height:30px;border-radius:6px;background:#0284C7;color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">911</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:#1E293B;">Iligan City Police / PNP</div>
                <div style="font-size:9.5px;color:#64748B;">Station Desk: (063) 221-2222</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
              <div style="width:30px;height:30px;border-radius:6px;background:#D97706;color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">BFP</div>
              <div>
                <div style="font-weight:700;font-size:11px;color:#1E293B;">Bureau of Fire Protection</div>
                <div style="font-size:9.5px;color:#64748B;">Fire Command: (063) 221-3333</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Preparedness Events -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h3 class="card-title">Community Drills & Events</h3>
          <a href="<?= BASE_URL ?>/views/barangay/events.php" class="btn btn-outline btn-sm">All</a>
        </div>
        <div class="card-body" style="padding:10px 14px;">
          <?php if (empty($recentActivities)): ?>
            <div style="text-align:center;padding:12px;font-size:10px;color:#94A3B8;">No recent preparedness events logged.</div>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($recentActivities as $act): ?>
                <div style="padding:8px 10px;background:#F8FAFC;border-radius:6px;border-left:3px solid var(--color-primary);font-size:10.5px;">
                  <div style="font-weight:700;color:var(--color-primary);"><?= clean($act['title']) ?></div>
                  <div style="font-size:9px;color:#64748B;display:flex;justify-content:space-between;margin-top:2px;">
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
        <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--dash-primary);display:flex;align-items:center;gap:6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
          <span id="modalIncidentsTitle">Disaster Incident Reports — Barangay <?= clean($barangay['name']) ?></span>
        </h3>
        <p style="margin:2px 0 0 0;font-size:10px;color:#64748B;" id="modalIncidentsSubtitle">Showing all active and recorded disaster assistance requests</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalActiveIncidents')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div class="modal-search-box">
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
              <tr><td colspan="10" style="text-align:center;padding:24px;color:#94A3B8;">No disaster incident records found.</td></tr>
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
                  <td style="font-family:var(--font-secondary);font-weight:700;color:#DC2626;"><?= number_format($r['displaced_families']) ?></td>
                  <td><?= renderStatusBadge($r['status']) ?></td>
                  <td style="font-size:9.5px;color:#64748B;"><?= formatDate($r['created_at'], 'M d, Y h:i A') ?></td>
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
      <span style="font-size:10px;color:#64748B;">Total Records: <?= count($allRequests) ?> Incident Requests</span>
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
        <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--dash-primary);display:flex;align-items:center;gap:6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0284C7" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
          Relief Provisions Allocated & Dispatched
        </h3>
        <p style="margin:2px 0 0 0;font-size:10px;color:#64748B;">Decision Tree generated relief goods dispatched to Barangay <?= clean($barangay['name']) ?></p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalReliefAllocations')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div class="modal-search-box">
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
              <tr><td colspan="8" style="text-align:center;padding:24px;color:#94A3B8;">No relief provisions currently recorded for this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($allocatedItems as $it): ?>
                <tr>
                  <td style="font-family:var(--font-secondary);font-size:9.5px;color:#64748B;"><?= clean($it['sku'] ?? 'N/A') ?></td>
                  <td style="font-weight:700;color:var(--color-primary);"><?= clean($it['resource_name']) ?></td>
                  <td><span class="badge badge-neutral"><?= clean($it['category'] ?? 'Relief Goods') ?></span></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:#0284C7;font-size:12px;">
                    <?= number_format($it['allocated_quantity']) ?> <?= clean($it['unit'] ?? 'packs') ?>
                  </td>
                  <td>
                    <span style="font-weight:600;"><?= clean($it['tracking_code']) ?></span>
                    <div style="font-size:8.5px;color:#64748B;"><?= clean($it['disaster_type']) ?></div>
                  </td>
                  <td><?= clean($it['purok_name']) ?></td>
                  <td><?= renderStatusBadge($it['status'] ?? 'Allocated') ?></td>
                  <td style="font-size:9.5px;color:#64748B;"><?= formatDate($it['allocated_at'], 'M d, Y') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span style="font-size:10px;color:#64748B;">Total Relief Supplies: <strong><?= number_format($totalReliefAllocated) ?> units</strong> across <?= count($allocatedItems) ?> item records</span>
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
        <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--dash-primary);display:flex;align-items:center;gap:6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
          <span id="modalEvacTitle">Barangay Evacuation Shelters & Capacity</span>
        </h3>
        <p style="margin:2px 0 0 0;font-size:10px;color:#64748B;" id="modalEvacSubtitle">Operational status, amenities, and current occupancy rates</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalEvacuationCenters')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div class="modal-search-box">
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
              <tr><td colspan="9" style="text-align:center;padding:24px;color:#94A3B8;">No evacuation centers registered in this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($evacuationCenters as $ec): ?>
                <?php
                  $pct = $ec['capacity_individuals'] > 0 ? min(100, round(($ec['current_evacuees_count'] / $ec['capacity_individuals']) * 100)) : 0;
                ?>
                <tr data-center="<?= clean($ec['name']) ?>">
                  <td style="font-weight:700;color:var(--color-primary);"><?= clean($ec['name']) ?></td>
                  <td><span style="font-size:9.5px;"><?= clean($ec['center_type'] ?? 'Shelter') ?></span></td>
                  <td style="font-size:10px;color:#64748B;"><?= clean($ec['location_address']) ?></td>
                  <td style="font-family:var(--font-secondary);"><?= number_format($ec['capacity_individuals']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:<?= $pct >= 90 ? '#DC2626' : '#1E293B' ?>;">
                    <?= number_format($ec['current_evacuees_count']) ?>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                      <div style="flex:1;height:5px;background:#E2E8F0;border-radius:3px;overflow:hidden;min-width:50px;">
                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 90 ? '#DC2626' : ($pct >= 70 ? '#F59E0B' : '#10B981') ?>;"></div>
                      </div>
                      <span style="font-size:9.5px;font-weight:700;"><?= $pct ?>%</span>
                    </div>
                  </td>
                  <td>
                    <div style="display:flex;gap:4px;font-size:9px;">
                      <?= $ec['has_potable_water'] ? '<span title="Potable Water">💧</span>' : '' ?>
                      <?= $ec['has_electricity'] ? '<span title="Electricity">⚡</span>' : '' ?>
                      <?= $ec['has_medical_station'] ? '<span title="Medical Station">🏥</span>' : '' ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-size:10px;font-weight:600;"><?= clean($ec['contact_officer']) ?></div>
                    <div style="font-size:8.5px;color:#64748B;"><?= clean($ec['contact_number']) ?></div>
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
      <span style="font-size:10px;color:#64748B;">Total Capacity: <strong><?= number_format($totalCapacity) ?> individuals</strong> across <?= $totalCenters ?> centers</span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="btn btn-primary btn-sm" style="margin-right:6px;">Manage Shelters</a>
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
        <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--dash-primary);display:flex;align-items:center;gap:6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          Registered Resident Citizens — Barangay <?= clean($barangay['name']) ?>
        </h3>
        <p style="margin:2px 0 0 0;font-size:10px;color:#64748B;">Official household registrations and emergency contact directory</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalResidentsRegistry')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div class="modal-search-box">
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
              <tr><td colspan="6" style="text-align:center;padding:24px;color:#94A3B8;">No residents registered yet in this jurisdiction.</td></tr>
            <?php else: ?>
              <?php foreach ($residentsList as $res): ?>
                <tr>
                  <td>
                    <div style="font-weight:700;color:var(--color-primary);font-size:11.5px;">
                      <?= clean(trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? '')) ?: ($res['full_name'] ?? $res['username'])) ?>
                    </div>
                    <div style="font-size:9px;color:#64748B;">@<?= clean($res['username']) ?></div>
                  </td>
                  <td><span style="font-weight:600;font-size:10.5px;"><?= clean($res['purok'] ?: 'Standard Zone') ?></span></td>
                  <td>
                    <div style="font-size:10px;font-family:var(--font-secondary);font-weight:600;"><?= clean($res['phone'] ?: 'N/A') ?></div>
                    <div style="font-size:9px;color:#64748B;"><?= clean($res['email']) ?></div>
                  </td>
                  <td style="font-size:10px;">
                    <?= clean($res['gender'] ?: 'Unspecified') ?>
                    <?php if (!empty($res['age'])): ?>
                      • <?= (int)$res['age'] ?> yrs
                    <?php endif; ?>
                  </td>
                  <td><?= renderStatusBadge($res['status']) ?></td>
                  <td style="font-size:9.5px;color:#64748B;"><?= formatDate($res['created_at'], 'M d, Y') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span style="font-size:10px;color:#64748B;">Total Registered Residents: <strong><?= number_format($totalResidents) ?></strong> (<?= $activeResidentsCount ?> Active)</span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/residents.php" class="btn btn-primary btn-sm" style="margin-right:6px;">Manage Residents Module</a>
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
        <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--dash-primary);display:flex;align-items:center;gap:6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4F46E5" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
          <span id="modalPurokTitle">Purok Clusters & Hazard Risk Registry</span>
        </h3>
        <p style="margin:2px 0 0 0;font-size:10px;color:#64748B;" id="modalPurokSubtitle">Community clusters, local hazards, and demographic breakdowns</p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalPuroksList')">&times;</button>
    </div>
    <div class="modal-body-dash">
      <div class="modal-search-box">
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
              <tr><td colspan="7" style="text-align:center;padding:24px;color:#94A3B8;">No purok clusters recorded for this barangay.</td></tr>
            <?php else: ?>
              <?php foreach ($allPuroks as $pk): ?>
                <?php
                  $pRisk = $pk['risk_level'] ?? 'Moderate';
                  $pColors = ['Critical' => 'badge-danger', 'High' => 'badge-warning', 'Moderate' => 'badge-info', 'Low' => 'badge-success'];
                  $badgeCls = $pColors[$pRisk] ?? 'badge-neutral';
                ?>
                <tr data-risk="<?= clean($pRisk) ?>" data-purok="<?= clean($pk['name']) ?>">
                  <td style="font-weight:700;color:var(--color-primary);font-size:12px;"><?= clean($pk['name']) ?></td>
                  <td style="font-size:10px;color:#475569;max-width:260px;"><?= clean($pk['hazard_types'] ?: 'Standard Flood/Squall Risk') ?></td>
                  <td><span class="badge <?= $badgeCls ?>" style="font-size:9.5px;"><?= clean($pRisk) ?></span></td>
                  <td style="font-family:var(--font-secondary);font-size:11px;"><?= number_format($pk['households']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);"><?= number_format($pk['population']) ?></td>
                  <td style="font-family:var(--font-secondary);font-size:10px;color:#64748B;white-space:nowrap;"><?= clean($pk['coordinates_lat']) ?>, <?= clean($pk['coordinates_lng']) ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:9.5px;">View Module</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer-dash">
      <span style="font-size:10px;color:#64748B;">Total Registered Puroks: <strong><?= count($allPuroks) ?> clusters</strong></span>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="btn btn-primary btn-sm" style="margin-right:6px;">Manage Puroks</a>
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
  // Chart Global Defaults
  Chart.defaults.font.family = "'Poppins', 'Roboto', sans-serif";
  Chart.defaults.color = '#64748B';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = { weight: 'bold', size: 12 };
  Chart.defaults.plugins.tooltip.bodyFont = { size: 11 };

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
          backgroundColor: ['#EF4444', '#F59E0B', '#06B6D4', '#10B981'],
          borderWidth: 2,
          borderColor: '#FFFFFF',
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '66%',
        plugins: {
          legend: {
            position: 'right',
            labels: { boxWidth: 12, font: { size: 10.5, weight: '500' }, padding: 12 }
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
          backgroundColor: '#0EA5E9',
          borderRadius: 6,
          maxBarThickness: 36
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
            ticks: { precision: 0, font: { size: 10 } },
            grid: { color: '#F1F5F9' }
          },
          x: {
            ticks: { font: { size: 10, weight: '600' } },
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
            backgroundColor: '#CBD5E1',
            borderRadius: 4,
            maxBarThickness: 16
          },
          {
            label: 'Current Occupants',
            data: centerOcc,
            backgroundColor: '#F59E0B',
            borderRadius: 4,
            maxBarThickness: 16
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { font: { size: 9.5 } },
            grid: { color: '#F1F5F9' }
          },
          y: {
            ticks: { font: { size: 10, weight: '600' } },
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
            backgroundColor: '#2F6F73',
            borderRadius: 4,
            maxBarThickness: 20
          },
          {
            label: 'Households',
            data: pHouse,
            backgroundColor: '#10B981',
            borderRadius: 4,
            maxBarThickness: 20
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { font: { size: 9.5 } },
            grid: { color: '#F1F5F9' }
          },
          x: {
            ticks: { font: { size: 9.5, weight: '600' } },
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

// Filter Puroks Modal by Risk Level slice click
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

// Filter Puroks Modal by Purok Name bar click
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

// Filter Incidents Modal by Tracking Code or row click
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
  const searchInput = document.querySelector('#modalActiveIncidents .modal-search-box input');

  if (title) title.innerText = `Incident Record: ${trackingCode}`;
  if (subtitle) subtitle.innerText = `Full assistance request overview and situational impact`;
  if (searchInput) searchInput.value = trackingCode;

  filterModalTable({ value: trackingCode }, 'incidentsModalTable');
  openModal('modalActiveIncidents');
}

// Filter Incidents Modal by Disaster Type bar click
function openFilteredIncidentsModal(disasterType) {
  const title = document.getElementById('modalIncidentsTitle');
  const subtitle = document.getElementById('modalIncidentsSubtitle');
  const searchInput = document.querySelector('#modalActiveIncidents .modal-search-box input');

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

// Filter Evac Modal by Center Name bar click
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

// Open a popup in its full, unfiltered state (used by cards & chart background clicks).
// Chart element clicks filter rows/titles; this restores them so stale filters never linger.
const dashModalDefaults = {};
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.modal-overlay').forEach(function (m) {
    const h3 = m.querySelector('.modal-header-dash h3 span[id]');
    const p = m.querySelector('.modal-header-dash p[id]');
    dashModalDefaults[m.id] = {
      titleId: h3 ? h3.id : null, title: h3 ? h3.innerText : '',
      subId: p ? p.id : null, sub: p ? p.innerText : ''
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
  const search = modal.querySelector('.modal-search-box input');
  if (search) search.value = '';
  modal.querySelectorAll('tbody tr').forEach(function (tr) { tr.style.display = ''; });
  openModal(modalId);
}

// Interactive Live Filter function for Tables inside Modals
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
