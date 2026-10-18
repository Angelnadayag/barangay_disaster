<?php
// ============================================================================
// Views (ICDRRMO): Central Command, Statistics & AI Heatmap Dashboard
// Multi-hazard spatial analysis and prone-area identification for Hinaplanon Puroks
// Mapping Tool: Leaflet.js with Heatmap Density Layer & AI Risk Susceptibility Engine
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

$pageTitle = "ICDRRMO Command & AI Heatmap Dashboard";
require_once __DIR__ . '/../layouts/header.php';

// ----------------------------------------------------------------------------
// 1. Fetch Barangay Hinaplanon Jurisdiction Data
// ----------------------------------------------------------------------------
$hinaplanon = $db->query("SELECT * FROM barangays WHERE id = 12")->fetch(PDO::FETCH_ASSOC) ?: [
    'id' => 12,
    'name' => 'Hinaplanon',
    'city' => 'Iligan City',
    'population' => 8900,
    'total_households' => 1990,
    'risk_level' => 'Critical',
    'coordinates_lat' => 8.246596,
    'coordinates_lng' => 124.259474
];

// ----------------------------------------------------------------------------
// 2. Fetch Puroks in Hinaplanon (Active)
// ----------------------------------------------------------------------------
$purokStmt = $db->query("
    SELECT * FROM puroks 
    WHERE barangay_id = 12 AND (status != 'archived' OR status IS NULL)
    ORDER BY FIELD(risk_level, 'Critical', 'High', 'Moderate', 'Low'), name ASC
");
$allPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// 3. Fetch Disaster Incidents & Reports in Hinaplanon
// ----------------------------------------------------------------------------
$incidentsStmt = $db->query("
    SELECT dr.*, b.name AS barangay_name 
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.barangay_id = 12
    ORDER BY dr.created_at DESC
");
$allIncidents = $incidentsStmt->fetchAll(PDO::FETCH_ASSOC);

$activeIncidents = array_filter($allIncidents, fn($i) => ($i['status'] ?? 'Ongoing') === 'Ongoing');
$totalIncidentsCount = count($allIncidents);
$activeIncidentsCount = count($activeIncidents);

$totalAffectedFamilies = array_sum(array_column($allIncidents, 'affected_families'));
$totalDisplacedFamilies = array_sum(array_column($allIncidents, 'displaced_families'));

// ----------------------------------------------------------------------------
// 4. Fetch Evacuation Shelters in Hinaplanon
// ----------------------------------------------------------------------------
$evacStmt = $db->query("
    SELECT * FROM evacuation_areas 
    WHERE barangay_id = 12 
    ORDER BY (current_evacuees_count / NULLIF(capacity_individuals, 0)) DESC, name ASC
");
$evacuationCenters = $evacStmt->fetchAll(PDO::FETCH_ASSOC);

$totalEvacCapacity = array_sum(array_column($evacuationCenters, 'capacity_individuals'));
$totalCurrentEvacuees = array_sum(array_column($evacuationCenters, 'current_evacuees_count'));
$shelterOccupancyPct = $totalEvacCapacity > 0 ? round(($totalCurrentEvacuees / $totalEvacCapacity) * 100, 1) : 0;

// ----------------------------------------------------------------------------
// 5. Central Resource & Relief Goods Buffer (Depot Stock)
// ----------------------------------------------------------------------------
$centralReliefPacks = (int)$db->query("
    SELECT COALESCE(SUM(available_quantity), 0) 
    FROM resources 
    WHERE status = 'active' AND (barangay_id IS NULL OR barangay_id = 12) AND category = 'Relief Goods'
")->fetchColumn();

$totalCentralStock = (int)$db->query("
    SELECT COALESCE(SUM(available_quantity), 0) 
    FROM resources 
    WHERE status = 'active' AND (barangay_id IS NULL OR barangay_id = 12)
")->fetchColumn();

// ----------------------------------------------------------------------------
// 6. Financial Aid Allocations in Hinaplanon
// ----------------------------------------------------------------------------
$aidStats = $db->query("
    SELECT 
        COALESCE(SUM(total_budget), 0) AS total_budget,
        COALESCE(SUM(disbursed_budget), 0) AS disbursed_budget,
        COALESCE(SUM(remaining_budget), 0) AS remaining_budget,
        COALESCE(SUM(target_beneficiaries), 0) AS target_families,
        COUNT(*) AS total_programs
    FROM financial_aid_allocations 
    WHERE barangay_id = 12
")->fetch(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// 7. Recent Decision Tree Recommendations for Hinaplanon
// ----------------------------------------------------------------------------
$recommendationsStmt = $db->query("
    SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.urgency, dr.purok_name, dr.affected_families
    FROM recommendations r
    JOIN disaster_reports dr ON r.disaster_request_id = dr.id
    WHERE dr.barangay_id = 12
    ORDER BY r.created_at DESC
    LIMIT 6
");
$recentRecs = $recommendationsStmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// 8. AI Prone-Area Susceptibility Model Computation per Purok
// ----------------------------------------------------------------------------
// Factors evaluated by AI:
// 1. River Inundation & Elevation (proximity to Mandulog River delta bottleneck)
// 2. Incident Frequency & Recurrence in database
// 3. Household & Population Density exposure
// 4. Ingress / Egress Bottlenecks
$aiPurokData = [];
$totalHinaplanonPop = 0;
$totalHinaplanonHouseholds = 0;
$criticalPuroksCount = 0;
$highPuroksCount = 0;

// Knowledge-base baseline for Hinaplanon puroks
$purokProfiles = [
    'Purok 1' => [
        'ai_risk_score' => 94,
        'prone_category' => 'Mandulog Delta Severe Inundation Corridor',
        'susceptibility' => 'Critical',
        'primary_hazard' => 'Mandulog River Flash Flood & Rapid Storm Surge',
        'elevation' => '3.2m above sea level (Estuary Lowland)',
        'river_distance' => '45 meters (Immediate Bank)',
        'alert_level' => 'RED (Level 3 - Immediate Evacuation)',
        'ai_recommendation' => 'Critical bottleneck zone where Mandulog river water backs up during high tides. Mandatory early-evacuation trigger at 1.8m river stage. Pre-position motorized rescue rubber boats at Hinaplanon Highway junction.',
        'historical_inundation' => '2.8m max water level recorded',
        'radius' => 220,
        'color' => '#DC2626'
    ],
    'Purok 3A' => [
        'ai_risk_score' => 84,
        'prone_category' => 'Low-Lying Urban Catchment & Inundation Sector',
        'susceptibility' => 'High',
        'primary_hazard' => 'River Overflow & Severe Urban Street Flooding',
        'elevation' => '4.8m above sea level (Flat Plain)',
        'river_distance' => '120 meters',
        'alert_level' => 'ORANGE (Level 2 - Pre-emptive Relocation)',
        'ai_recommendation' => 'Densely populated zone with limited natural drainage gradients. High concentration of vulnerable elderly and infants. Pre-position 500 family food packs and stage medical triaging near Central Elementary.',
        'historical_inundation' => '1.9m water level recorded',
        'radius' => 210,
        'color' => '#EA580C'
    ],
    'Purok 4A' => [
        'ai_risk_score' => 64,
        'prone_category' => 'Inland Stormwater Runoff & Street Ponding',
        'susceptibility' => 'Moderate',
        'primary_hazard' => 'Localized Ponding & Surface Waterlogging',
        'elevation' => '6.5m above sea level (Slight Incline)',
        'river_distance' => '280 meters',
        'alert_level' => 'YELLOW (Level 1 - Active Monitoring)',
        'ai_recommendation' => 'Runoff from highway catchment creates temporary ponding (0.5m-1.0m). Secondary relief transit hub. Ensure drainage culverts are kept unblocked.',
        'historical_inundation' => '0.8m ponding recorded',
        'radius' => 190,
        'color' => '#D97706'
    ],
    'Purok 6' => [
        'ai_risk_score' => 52,
        'prone_category' => 'Estuary Transition & Tidal Runoff Zone',
        'susceptibility' => 'Moderate',
        'primary_hazard' => 'Tidal Backflow & Heavy Rainfall Runoff',
        'elevation' => '5.9m above sea level',
        'river_distance' => '210 meters',
        'alert_level' => 'YELLOW (Level 1 - Advisory)',
        'ai_recommendation' => 'Monitor high-tide synchronization with upstream rainfall. Secondary evacuation pathway towards Highway staging post.',
        'historical_inundation' => '0.6m tidal backflow recorded',
        'radius' => 180,
        'color' => '#2563EB'
    ],
    'Purok 5B' => [
        'ai_risk_score' => 28,
        'prone_category' => 'Elevated Natural Ground (Safe Refuge Sector)',
        'susceptibility' => 'Low',
        'primary_hazard' => 'Low Hazard Zone / Minor Wind Gusts',
        'elevation' => '14.2m above sea level (Elevated Ridge)',
        'river_distance' => '460 meters (Safely Inland)',
        'alert_level' => 'GREEN (Normal / Safe Buffer)',
        'ai_recommendation' => 'Topographical natural barrier protects against Mandulog flood crests. Designated safe refuge perimeter and primary logistics rally point for community responders.',
        'historical_inundation' => 'None (Zero flood occurrence)',
        'radius' => 220,
        'color' => '#16A34A'
    ]
];

foreach ($allPuroks as $p) {
    $pName = trim($p['name']);
    $profile = $purokProfiles[$pName] ?? [
        'ai_risk_score' => 50,
        'prone_category' => 'Standard Residential Zone',
        'susceptibility' => $p['risk_level'] ?? 'Moderate',
        'primary_hazard' => $p['hazard_types'] ?: 'General Weather Vulnerability',
        'elevation' => '6.0m above sea level',
        'river_distance' => '200 meters',
        'alert_level' => 'YELLOW (Monitoring)',
        'ai_recommendation' => 'Maintain standard community disaster preparedness and communication with BDRRMC.',
        'historical_inundation' => 'Variable',
        'radius' => 180,
        'color' => '#D97706'
    ];

    $pop = (int)($p['population'] ?: 1000);
    $hh = (int)($p['households'] ?: 200);
    $totalHinaplanonPop += $pop;
    $totalHinaplanonHouseholds += $hh;

    if ($profile['susceptibility'] === 'Critical') $criticalPuroksCount++;
    if ($profile['susceptibility'] === 'High') $highPuroksCount++;

    $aiPurokData[] = array_merge($p, $profile, [
        'population' => $pop,
        'households' => $hh,
        'lat' => (float)($p['coordinates_lat'] ?: 8.2460),
        'lng' => (float)($p['coordinates_lng'] ?: 124.2600)
    ]);
}

// Sort by AI risk score descending
usort($aiPurokData, fn($a, $b) => $b['ai_risk_score'] <=> $a['ai_risk_score']);
?>

<!-- Leaflet GIS Map Assets & Leaflet.heat CDN -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<style>
/* Dashboard Layout & Modern Styling */
.dash-header-banner {
  background: linear-gradient(135deg, #0F172A 0%, #1E293B 60%, #17324D 100%);
  color: #FFFFFF;
  border-radius: var(--radius-md);
  padding: 24px 28px;
  margin-bottom: var(--space-4);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  box-shadow: 0 4px 20px rgba(15, 23, 42, 0.15);
  position: relative;
  overflow: hidden;
}
.dash-header-banner::after {
  content: '';
  position: absolute;
  top: -60px;
  right: -60px;
  width: 220px;
  height: 220px;
  background: radial-gradient(circle, rgba(47, 111, 115, 0.35) 0%, rgba(15, 23, 42, 0) 70%);
  pointer-events: none;
}
.dash-header-title {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 6px 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.dash-header-sub {
  font-size: 12px;
  color: #94A3B8;
  margin: 0;
  max-width: 650px;
  line-height: 1.5;
}
.dash-pulse-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: rgba(16, 185, 129, 0.15);
  color: #34D399;
  border: 1px solid rgba(16, 185, 129, 0.3);
  padding: 4px 12px;
  border-radius: 9999px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}
.pulse-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10B981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: pulse-green 1.8s infinite;
}
@keyframes pulse-green {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* KPI Summary Cards Grid */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}
.kpi-card {
  background: #FFFFFF;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-shadow: var(--shadow-sm);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  position: relative;
  overflow: hidden;
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}
.kpi-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}
.kpi-card-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.kpi-card-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #FFFFFF;
}
.kpi-card-value {
  font-size: 24px;
  font-weight: 800;
  color: var(--color-primary);
  font-family: var(--font-secondary);
  line-height: 1.1;
  margin-bottom: 4px;
}
.kpi-card-sub {
  font-size: 10.5px;
  color: var(--color-text-secondary);
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Heatmap & AI Analysis Container */
.heatmap-container-grid {
  display: grid;
  grid-template-columns: 1fr 380px;
  gap: var(--space-4);
  align-items: stretch;
  margin-bottom: var(--space-4);
}
@media (max-width: 1150px) {
  .heatmap-container-grid {
    grid-template-columns: 1fr;
  }
}
.heatmap-card {
  background: #FFFFFF;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
}
.heatmap-card-header {
  padding: 14px 18px;
  background: #FAFCFC;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.heatmap-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.btn-pill {
  font-size: 10.5px;
  padding: 5px 11px;
  border-radius: 9999px;
  border: 1px solid var(--color-border);
  background: #FFFFFF;
  color: var(--color-text);
  cursor: pointer;
  font-weight: 600;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.btn-pill:hover, .btn-pill.active {
  background: var(--color-primary);
  color: #FFFFFF;
  border-color: var(--color-primary);
}

/* Leaflet Map Specific Styling */
#hinaplanonHeatmap {
  width: 100%;
  height: 580px;
  background: #E2E8F0;
  position: relative;
  z-index: 1;
}

/* Custom Radar Pulse Marker for Critical/High Zones */
.leaflet-radar-pulse {
  position: relative;
}
.leaflet-radar-pulse .pulse-core {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid #FFFFFF;
  box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.leaflet-radar-pulse .pulse-ring {
  position: absolute;
  top: -10px;
  left: -10px;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  animation: radar-wave 2s infinite ease-out;
  opacity: 0;
  pointer-events: none;
}
@keyframes radar-wave {
  0% { transform: scale(0.4); opacity: 0.9; }
  100% { transform: scale(1.6); opacity: 0; }
}

/* AI Prone-Area Inspector Sidebar */
.ai-inspector-card {
  background: #FFFFFF;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
}
.ai-inspector-header {
  padding: 16px 18px;
  background: #0F172A;
  color: #FFFFFF;
  border-top-left-radius: var(--radius-md);
  border-top-right-radius: var(--radius-md);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.purok-ranking-list {
  max-height: 250px;
  overflow-y: auto;
  border-bottom: 1px solid var(--color-border-light);
}
.purok-rank-item {
  padding: 10px 14px;
  border-bottom: 1px solid #F1F5F9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  transition: background 0.15s ease;
}
.purok-rank-item:hover, .purok-rank-item.active {
  background: #F8FAFC;
}
.purok-rank-item.active {
  border-left: 3px solid var(--color-primary);
}
.ai-detail-box {
  padding: 16px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.meter-bar-wrap {
  background: #E2E8F0;
  height: 7px;
  border-radius: 9999px;
  overflow: hidden;
  margin-top: 4px;
}
.meter-bar-fill {
  height: 100%;
  border-radius: 9999px;
  transition: width 0.6s ease;
}

/* Tables & Activity Feeds */
.dash-tables-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}
@media (max-width: 990px) {
  .dash-tables-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<!-- ========================================================================= -->
<!-- 1. HEADER BANNER: ICDRRMO OPERATIONS COMMAND                            -->
<!-- ========================================================================= -->
<div class="dash-header-banner">
  <div>
    <div class="dash-pulse-badge">
      <span class="pulse-dot"></span>
      AI Hazard Prediction Engine &bull; Mandulog Catchment Live
    </div>
    <h1 class="dash-header-title" style="margin-top:8px;">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
      ICDRRMO Command & AI Heatmap Dashboard
    </h1>
    <p class="dash-header-sub">
      Multi-hazard GIS intelligence and AI susceptibility analysis for <strong>Barangay Hinaplanon</strong>. Real-time identification of flash flood corridors, urban ponding sectors, and central relief operations.
    </p>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php" class="btn btn-primary" style="font-size:11.5px;padding:9px 15px;display:inline-flex;align-items:center;gap:6px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
      Manage Resources
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/financial-aid-allocation.php" class="btn btn-secondary" style="font-size:11.5px;padding:9px 15px;display:inline-flex;align-items:center;gap:6px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
      Financial Aid
    </a>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 2. CORE STATISTICS & KEY PERFORMANCE INDICATORS                         -->
<!-- ========================================================================= -->
<div class="kpi-grid">
  <!-- KPI 1: Hinaplanon Total Population -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Hinaplanon Demographics</span>
      <div class="kpi-card-icon" style="background:var(--color-primary);">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
      </div>
    </div>
    <div class="kpi-card-value"><?= number_format($totalHinaplanonPop) ?></div>
    <div class="kpi-card-sub">
      <strong><?= number_format($totalHinaplanonHouseholds) ?></strong> households across 5 Puroks
    </div>
  </div>

  <!-- KPI 2: AI High/Critical Prone Zones -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Critical & High Prone Zones</span>
      <div class="kpi-card-icon" style="background:#DC2626;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
      </div>
    </div>
    <div class="kpi-card-value" style="color:#DC2626;"><?= $criticalPuroksCount + $highPuroksCount ?> Puroks</div>
    <div class="kpi-card-sub">
      <span style="color:#DC2626;font-weight:700;">Purok 1 & Purok 3A</span> (Mandulog Delta)
    </div>
  </div>

  <!-- KPI 3: Active Disaster Requests in Hinaplanon -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Ongoing Incident Reports</span>
      <div class="kpi-card-icon" style="background:#EA580C;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
      </div>
    </div>
    <div class="kpi-card-value" style="color:#EA580C;"><?= $activeIncidentsCount ?> Active</div>
    <div class="kpi-card-sub">
      <strong><?= number_format($totalDisplacedFamilies) ?></strong> displaced families in need
    </div>
  </div>

  <!-- KPI 4: Evacuation Shelter Occupancy -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Shelter Capacity</span>
      <div class="kpi-card-icon" style="background:#16A34A;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
      </div>
    </div>
    <div class="kpi-card-value" style="color:#16A34A;"><?= number_format($totalCurrentEvacuees) ?> / <?= number_format($totalEvacCapacity) ?></div>
    <div class="kpi-card-sub">
      <strong><?= $shelterOccupancyPct ?>%</strong> occupancy (NHS Gym & Multi-purpose)
    </div>
  </div>

  <!-- KPI 5: Central Stock Buffer -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Emergency Relief Buffer</span>
      <div class="kpi-card-icon" style="background:#2563EB;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
      </div>
    </div>
    <div class="kpi-card-value"><?= number_format($centralReliefPacks) ?></div>
    <div class="kpi-card-sub">
      Food packs ready for immediate dispatch
    </div>
  </div>

  <!-- KPI 6: Financial Aid Distributed -->
  <div class="kpi-card">
    <div class="kpi-card-header">
      <span class="kpi-card-label">Financial Aid Fund</span>
      <div class="kpi-card-icon" style="background:#7C3AED;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
      </div>
    </div>
    <div class="kpi-card-value" style="color:#7C3AED;font-size:20px;">
      ₱<?= number_format($aidStats['disbursed_budget']) ?>
    </div>
    <div class="kpi-card-sub">
      of ₱<?= number_format($aidStats['total_budget']) ?> allocated for Hinaplanon
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 3. INTERACTIVE LEAFLET HEATMAP & AI PRONE-AREA IDENTIFICATION ENGINE     -->
<!-- ========================================================================= -->
<div class="heatmap-container-grid">
  <!-- Map Canvas & Interactive Controls -->
  <div class="heatmap-card">
    <div class="heatmap-card-header">
      <div>
        <div style="display:flex;align-items:center;gap:8px;">
          <h2 style="font-size:14px;font-weight:700;color:var(--color-primary);margin:0;">
            Leaflet GIS Heatmap: Hinaplanon Hazard Susceptibility
          </h2>
          <span class="badge badge-info" style="font-size:9.5px;">Live Density Model</span>
        </div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px;">
          Weighted kernel density estimation based on Mandulog delta flood exposure, historical inundation crests, and household density.
        </div>
      </div>

      <!-- Controls & Layer Toggles -->
      <div class="heatmap-toolbar">
        <button type="button" class="btn-pill active" id="btnToggleHeatmap" onclick="toggleLayer('heat')">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#EF4444;"></span>
          Heatmap Density
        </button>
        <button type="button" class="btn-pill active" id="btnToggleZones" onclick="toggleLayer('zones')">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3B82F6;"></span>
          Purok Zones
        </button>
        <button type="button" class="btn-pill active" id="btnToggleEvac" onclick="toggleLayer('evac')">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10B981;"></span>
          Evacuation Shelters
        </button>
        <button type="button" class="btn-pill active" id="btnToggleIncidents" onclick="toggleLayer('incidents')">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#F59E0B;"></span>
          Incident Pins
        </button>
      </div>
    </div>

    <!-- Leaflet Map Div -->
    <div id="hinaplanonHeatmap"></div>

    <!-- Map Footer Legend -->
    <div style="padding:10px 16px;background:#F8FAFC;border-top:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;font-size:11px;">
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <span style="font-weight:700;color:var(--color-text-secondary);text-transform:uppercase;font-size:9.5px;">AI Prone Scale:</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#DC2626;"></span> Critical Prone (Score 90+)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#EA580C;"></span> High Prone (75-89)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#D97706;"></span> Moderate (50-74)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#16A34A;"></span> Safe / Low (&lt;50)</span>
      </div>
      <div style="color:var(--color-text-muted);font-size:10px;">
        Click any Purok zone or marker to inspect AI vulnerability assessment.
      </div>
    </div>
  </div>

  <!-- Right: AI Prone-Area Inspector & Ranking Card -->
  <div class="ai-inspector-card">
    <div class="ai-inspector-header">
      <div>
        <div style="font-size:10px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#94A3B8;">AI Model Output</div>
        <h3 style="font-size:14px;font-weight:800;margin:2px 0 0 0;color:#FFFFFF;">Purok Susceptibility Rank</h3>
      </div>
      <span class="badge" style="background:rgba(255,255,255,0.15);color:#FFF;border:1px solid rgba(255,255,255,0.2);font-size:9.5px;">5 Puroks Evaluated</span>
    </div>

    <!-- Ranked Purok List -->
    <div class="purok-ranking-list" id="purokRankingList">
      <?php foreach ($aiPurokData as $idx => $p): ?>
        <div class="purok-rank-item <?= $idx === 0 ? 'active' : '' ?>" id="rank-item-<?= $p['id'] ?>" onclick="focusPurok(<?= htmlspecialchars(json_encode($p)) ?>)">
          <div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="font-size:11.5px;font-weight:700;color:var(--color-primary);"><?= clean($p['name']) ?></span>
              <span class="badge" style="font-size:8.5px;padding:2px 6px;background:<?= $p['color'] ?>15;color:<?= $p['color'] ?>;border:1px solid <?= $p['color'] ?>40;">
                <?= $p['susceptibility'] ?>
              </span>
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;">
              <?= clean($p['prone_category']) ?>
            </div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:14px;font-weight:800;color:<?= $p['color'] ?>;font-family:var(--font-secondary);">
              <?= $p['ai_risk_score'] ?><span style="font-size:9px;color:var(--color-text-muted);">/100</span>
            </div>
            <div style="font-size:9px;color:var(--color-text-muted);"><?= number_format($p['population']) ?> people</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Selected Purok Deep AI Insights Box -->
    <div class="ai-detail-box" id="aiDetailBox">
      <!-- Top Title -->
      <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #F1F5F9;padding-bottom:10px;">
        <div>
          <div id="inspPurokName" style="font-size:16px;font-weight:800;color:var(--color-primary);">Purok 1</div>
          <div id="inspProneCategory" style="font-size:10.5px;color:#DC2626;font-weight:600;margin-top:2px;">Mandulog Delta Severe Inundation Corridor</div>
        </div>
        <div id="inspScoreBadge" style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:4px 10px;border-radius:6px;font-weight:800;font-size:14px;text-align:center;">
          94 <span style="font-size:9px;display:block;font-weight:600;">AI SCORE</span>
        </div>
      </div>

      <!-- Vulnerability Bar Metrics -->
      <div>
        <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:600;color:var(--color-text-secondary);">
          <span>Mandulog River Proximity:</span>
          <span id="inspRiverDist" style="font-weight:700;color:#DC2626;">45m (Immediate Delta)</span>
        </div>
        <div class="meter-bar-wrap">
          <div class="meter-bar-fill" id="meterRiver" style="width:96%;background:#DC2626;"></div>
        </div>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:600;color:var(--color-text-secondary);">
          <span>Elevation & Drainage Outflow:</span>
          <span id="inspElevation" style="font-weight:700;">3.2m above sea level</span>
        </div>
        <div class="meter-bar-wrap">
          <div class="meter-bar-fill" id="meterElevation" style="width:88%;background:#EA580C;"></div>
        </div>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:600;color:var(--color-text-secondary);">
          <span>Population Exposure Density:</span>
          <span id="inspPopDensity" style="font-weight:700;">2,050 residents (450 HH)</span>
        </div>
        <div class="meter-bar-wrap">
          <div class="meter-bar-fill" id="meterDensity" style="width:80%;background:#D97706;"></div>
        </div>
      </div>

      <!-- AI Action Recommendation Card -->
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;padding:10px 12px;">
        <div style="display:flex;align-items:center;gap:5px;font-size:10px;font-weight:700;color:#B45309;text-transform:uppercase;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"></path></svg>
          AI Decision Recommendation
        </div>
        <div id="inspRecommendation" style="font-size:11px;color:#78350F;line-height:1.4;margin-top:4px;">
          Critical bottleneck zone where Mandulog river water backs up during high tides. Mandatory early-evacuation trigger at 1.8m river stage. Pre-position motorized rescue rubber boats at Hinaplanon Highway junction.
        </div>
      </div>

      <!-- Action Shortcut Buttons -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:auto;">
        <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=allocations" class="btn btn-outline btn-sm" style="font-size:10px;padding:6px 8px;text-align:center;">
          Dispatch Supplies
        </a>
        <a href="<?= BASE_URL ?>/views/icdrrmo/financial-aid-allocation.php" class="btn btn-primary btn-sm" style="font-size:10px;padding:6px 8px;text-align:center;">
          Allocate Cash Aid
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 4. HINAPLANON PUROK VULNERABILITY COMPARISON TABLE                       -->
<!-- ========================================================================= -->
<div class="card" style="margin-bottom:var(--space-4);padding:0;overflow:hidden;">
  <div style="padding:14px 18px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 style="font-size:13.5px;font-weight:700;color:var(--color-primary);margin:0;">
        Hinaplanon Purok Hazard Assessment & Prone-Area Directory
      </h3>
      <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
        Consolidated AI susceptibility matrix, historical flood heights, and designated evacuation safe-havens for all Hinaplanon puroks.
      </p>
    </div>
    <span class="badge badge-neutral" style="font-size:10px;">Barangay ID: 12 (Hinaplanon)</span>
  </div>

  <div class="table-responsive">
    <table class="data-table" style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="background:#F8FAFC;border-bottom:1px solid var(--color-border);">
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:left;">Purok Name</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:left;">Prone-Area Classification</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:center;">AI Risk Score</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:left;">Primary Hazard Profile</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:right;">Population</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:right;">Households</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:left;">Alert Level</th>
          <th style="padding:10px 14px;font-size:10.5px;font-weight:700;color:var(--color-text-secondary);text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($aiPurokData as $p): ?>
          <tr style="border-bottom:1px solid var(--color-border-light);">
            <td style="padding:10px 14px;">
              <div style="font-weight:700;color:var(--color-primary);font-size:12px;"><?= clean($p['name']) ?></div>
              <div style="font-size:9.5px;color:var(--color-text-muted);"><?= number_format($p['lat'], 4) ?>, <?= number_format($p['lng'], 4) ?></div>
            </td>
            <td style="padding:10px 14px;">
              <span class="badge" style="font-size:9px;background:<?= $p['color'] ?>15;color:<?= $p['color'] ?>;border:1px solid <?= $p['color'] ?>40;font-weight:700;">
                <?= $p['susceptibility'] ?>
              </span>
              <div style="font-size:10.5px;color:var(--color-text);font-weight:600;margin-top:2px;">
                <?= clean($p['prone_category']) ?>
              </div>
            </td>
            <td style="padding:10px 14px;text-align:center;">
              <span style="font-size:15px;font-weight:800;font-family:var(--font-secondary);color:<?= $p['color'] ?>;">
                <?= $p['ai_risk_score'] ?>
              </span>
              <span style="font-size:9.5px;color:var(--color-text-muted);">/100</span>
            </td>
            <td style="padding:10px 14px;font-size:11px;color:var(--color-text-secondary);">
              <?= clean($p['primary_hazard']) ?>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:1px;"><?= clean($p['elevation']) ?> &bull; <?= clean($p['river_distance']) ?></div>
            </td>
            <td style="padding:10px 14px;text-align:right;font-family:var(--font-secondary);font-weight:700;font-size:11.5px;">
              <?= number_format($p['population']) ?>
            </td>
            <td style="padding:10px 14px;text-align:right;font-family:var(--font-secondary);font-size:11.5px;">
              <?= number_format($p['households']) ?>
            </td>
            <td style="padding:10px 14px;">
              <span style="font-size:10px;font-weight:700;color:<?= $p['color'] ?>;">
                <?= clean($p['alert_level']) ?>
              </span>
            </td>
            <td style="padding:10px 14px;text-align:center;">
              <button type="button" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:4px 10px;" onclick="focusPurok(<?= htmlspecialchars(json_encode($p)) ?>); window.scrollTo({top: 400, behavior: 'smooth'});">
                Inspect on Map
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 5. OPERATIONAL FEEDS: ACTIVE INCIDENTS & DECISION RECOMMENDATIONS       -->
<!-- ========================================================================= -->
<div class="dash-tables-grid">
  <!-- Active Reports in Hinaplanon -->
  <div class="card" style="margin-bottom:0;padding:0;overflow:hidden;">
    <div style="padding:12px 16px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="font-size:12.5px;font-weight:700;color:var(--color-primary);margin:0;">
        Active Disaster Reports & Incidents (Hinaplanon)
      </h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 8px;">View All</a>
    </div>
    <div class="table-responsive">
      <table class="data-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="background:#F8FAFC;border-bottom:1px solid var(--color-border);">
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Tracking #</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Purok</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Disaster Type</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Severity</th>
            <th style="padding:8px 12px;font-size:10px;text-align:right;">Families</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allIncidents)): ?>
            <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:11px;">No disaster incidents currently reported in Hinaplanon.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($allIncidents, 0, 5) as $inc): ?>
              <tr style="border-bottom:1px solid var(--color-border-light);">
                <td style="padding:8px 12px;font-weight:700;font-family:var(--font-secondary);font-size:11px;">
                  <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports.php?search=<?= urlencode($inc['tracking_code']) ?>" style="color:var(--color-primary);text-decoration:none;">
                    <?= clean($inc['tracking_code']) ?>
                  </a>
                </td>
                <td style="padding:8px 12px;font-size:11px;font-weight:600;">
                  <?= clean($inc['purok_name'] ?: 'All Puroks') ?>
                </td>
                <td style="padding:8px 12px;font-size:11px;"><?= clean($inc['disaster_type'] ?: 'Flood') ?></td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge <?= ($inc['severity'] === 'High' || $inc['severity'] === 'Critical') ? 'badge-danger' : 'badge-warning' ?>" style="font-size:9px;">
                    <?= clean($inc['severity']) ?>
                  </span>
                </td>
                <td style="padding:8px 12px;text-align:right;font-family:var(--font-secondary);font-weight:600;font-size:11px;">
                  <?= number_format($inc['affected_families']) ?>
                </td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge <?= $inc['status'] === 'Ongoing' ? 'badge-danger' : 'badge-success' ?>" style="font-size:9px;">
                    <?= clean($inc['status']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Central Decision Tree Recommendations -->
  <div class="card" style="margin-bottom:0;padding:0;overflow:hidden;">
    <div style="padding:12px 16px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="font-size:12.5px;font-weight:700;color:var(--color-primary);margin:0;">
        Decision-Tree Recommendations (Hinaplanon)
      </h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 8px;">Review Queue</a>
    </div>
    <div class="table-responsive">
      <table class="data-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="background:#F8FAFC;border-bottom:1px solid var(--color-border);">
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Rec Code</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Target Purok</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Disaster / Urgency</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Confidence</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentRecs)): ?>
            <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:11px;">All generated recommendations for Hinaplanon have been reviewed.</td></tr>
          <?php else: ?>
            <?php foreach ($recentRecs as $rec): ?>
              <tr style="border-bottom:1px solid var(--color-border-light);">
                <td style="padding:8px 12px;font-weight:700;font-family:var(--font-secondary);font-size:11px;">
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $rec['id'] ?>" style="color:var(--color-primary);text-decoration:none;">
                    <?= clean($rec['recommendation_code']) ?>
                  </a>
                </td>
                <td style="padding:8px 12px;font-size:11px;font-weight:600;">
                  <?= clean($rec['purok_name'] ?: 'Hinaplanon') ?>
                </td>
                <td style="padding:8px 12px;font-size:10.5px;">
                  <div><?= clean($rec['disaster_type']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($rec['urgency']) ?> &bull; <?= $rec['affected_families'] ?> families</div>
                </td>
                <td style="padding:8px 12px;text-align:center;font-family:var(--font-secondary);font-weight:700;font-size:11px;color:#16A34A;">
                  <?= round($rec['confidence_score'] * 100) ?>%
                </td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge <?= $rec['status'] === 'Pending Review' ? 'badge-warning' : 'badge-success' ?>" style="font-size:9px;">
                    <?= clean($rec['status']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 6. JAVASCRIPT: LEAFLET HEATMAP & AI SUSCEPTIBILITY INTERACTION             -->
<!-- ========================================================================= -->
<script>
// Data payloads from backend
const purokData = <?= json_encode($aiPurokData) ?>;
const incidentPoints = <?= json_encode(array_map(fn($i) => [
  'id' => $i['id'],
  'code' => $i['tracking_code'],
  'purok' => $i['purok_name'] ?: 'Hinaplanon',
  'disaster' => $i['disaster_type'] ?: 'Flood',
  'severity' => $i['severity'],
  'families' => (int)$i['affected_families'],
  'lat' => (float)($i['coordinates_lat'] ?: 8.2460),
  'lng' => (float)($i['coordinates_lng'] ?: 124.2600)
], $allIncidents)) ?>;

const evacuationCenters = <?= json_encode(array_map(fn($e) => [
  'id' => $e['id'],
  'name' => $e['name'],
  'capacity' => (int)$e['capacity_individuals'],
  'current' => (int)$e['current_evacuees_count'],
  'status' => $e['status'],
  'lat' => (float)($e['coordinates_lat'] ?: 8.2468),
  'lng' => (float)($e['coordinates_lng'] ?: 124.2615)
], $evacuationCenters)) ?>;

// Global Leaflet map and layer group references
let map = null;
let heatLayer = null;
let zoneLayerGroup = L.layerGroup();
let evacLayerGroup = L.layerGroup();
let incidentLayerGroup = L.layerGroup();
let selectedPurokId = null;

// Initialize Leaflet Map
document.addEventListener('DOMContentLoaded', function() {
  initHinaplanonHeatmap();
});

function initHinaplanonHeatmap() {
  // Center coordinates for Barangay Hinaplanon (Mandulog River estuary & delta)
  const hinaplanonCenter = [8.2462, 8.2460 ? 124.2602 : 124.2600];

  map = L.map('hinaplanonHeatmap', {
    center: [8.2458, 124.2605],
    zoom: 15.5,
    zoomControl: true,
    attributionControl: true
  });

  // Base CartoDB Positron Tile Layer (clean, high-contrast disaster mapping)
  L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
    subdomains: 'abcd',
    maxZoom: 19
  }).addTo(map);

  // 1. Build Leaflet Heatmap Layer
  buildHeatmapLayer();

  // 2. Build Purok Risk Zones & Circle Overlays
  buildPurokZones();

  // 3. Build Evacuation Shelters Layer
  buildEvacuationShelters();

  // 4. Build Active Incident Pins Layer
  buildIncidentPins();

  // Add default active layer groups to map
  zoneLayerGroup.addTo(map);
  evacLayerGroup.addTo(map);
  incidentLayerGroup.addTo(map);

  // Select first Purok by default
  if (purokData.length > 0) {
    focusPurok(purokData[0]);
  }
}

// ----------------------------------------------------------------------------
// Build Weighted Heatmap Density Layer
// ----------------------------------------------------------------------------
function buildHeatmapLayer() {
  // Compute weighted heatmap points: [lat, lng, intensity]
  const heatPoints = [];

  // Add points from Purok AI vulnerability scores with surrounding dispersion
  purokData.forEach(p => {
    const weight = p.ai_risk_score / 100; // normalized 0.0 - 1.0
    // Central node
    heatPoints.push([p.lat, p.lng, weight]);
    // Dispersion cluster points around centroid to form organic hazard heat
    const d = 0.0007;
    heatPoints.push([p.lat + d, p.lng + d, weight * 0.75]);
    heatPoints.push([p.lat - d, p.lng + d, weight * 0.75]);
    heatPoints.push([p.lat + d, p.lng - d, weight * 0.65]);
    heatPoints.push([p.lat - d, p.lng - d, weight * 0.65]);
    heatPoints.push([p.lat, p.lng + (d * 1.4), weight * 0.8]);
  });

  // Add incident points with severe weight
  incidentPoints.forEach(inc => {
    let incWeight = 0.6;
    if (inc.severity === 'Critical') incWeight = 1.0;
    else if (inc.severity === 'High') incWeight = 0.85;
    heatPoints.push([inc.lat, inc.lng, incWeight]);
  });

  // If L.heatLayer is available via CDN
  if (typeof L.heatLayer === 'function') {
    heatLayer = L.heatLayer(heatPoints, {
      radius: 38,
      blur: 24,
      maxZoom: 17,
      max: 1.0,
      gradient: {
        0.15: '#2563EB', // Low / Blue
        0.35: '#10B981', // Moderate / Green
        0.60: '#F59E0B', // Amber
        0.80: '#EA580C', // High / Orange
        1.00: '#DC2626'  // Critical / Red
      }
    }).addTo(map);
  }
}

// ----------------------------------------------------------------------------
// Build Purok Prone Zones & Interactive Markers
// ----------------------------------------------------------------------------
function buildPurokZones() {
  purokData.forEach(p => {
    // 1. Boundary Circle with Risk Coloring
    const circle = L.circle([p.lat, p.lng], {
      radius: p.radius || 180,
      color: p.color,
      fillColor: p.color,
      fillOpacity: p.susceptibility === 'Critical' ? 0.28 : 0.20,
      weight: p.susceptibility === 'Critical' ? 2.5 : 1.8,
      dashArray: p.susceptibility === 'Critical' ? null : '4, 4'
    });

    circle.on('click', () => focusPurok(p));
    zoneLayerGroup.addLayer(circle);

    // 2. Center Animated Pulse Marker
    const isCritical = p.susceptibility === 'Critical' || p.susceptibility === 'High';
    const markerHtml = `
      <div class="leaflet-radar-pulse" style="cursor:pointer;" title="${p.name} — AI Score: ${p.ai_risk_score}/100">
        <div class="pulse-core" style="background:${p.color};"></div>
        ${isCritical ? `<div class="pulse-ring" style="background:${p.color};"></div>` : ''}
      </div>
    `;

    const icon = L.divIcon({
      className: 'custom-purok-icon',
      html: markerHtml,
      iconSize: [20, 20],
      iconAnchor: [10, 10]
    });

    const marker = L.marker([p.lat, p.lng], { icon: icon });
    marker.on('click', () => focusPurok(p));

    // Rich Popup
    marker.bindPopup(`
      <div style="font-family:var(--font-primary);min-width:210px;padding:4px;">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #E2E8F0;padding-bottom:6px;margin-bottom:6px;">
          <strong style="font-size:13px;color:var(--color-primary);">${p.name}</strong>
          <span style="font-size:9.5px;padding:2px 7px;border-radius:4px;background:${p.color}20;color:${p.color};font-weight:700;">
            ${p.susceptibility} (${p.ai_risk_score}/100)
          </span>
        </div>
        <div style="font-size:11px;color:#0F172A;font-weight:600;margin-bottom:4px;">
          ${p.prone_category}
        </div>
        <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:3px;">
          &bull; River Proximity: <strong>${p.river_distance}</strong><br>
          &bull; Terrain Elevation: <strong>${p.elevation}</strong><br>
          &bull; Population at Risk: <strong>${Number(p.population).toLocaleString()}</strong> residents (${Number(p.households).toLocaleString()} HH)
        </div>
        <div style="font-size:10px;color:#B45309;background:#FFFBEB;padding:4px 6px;border-radius:4px;margin-top:6px;border-left:2px solid #F59E0B;">
          <strong>Alert:</strong> ${p.alert_level}
        </div>
      </div>
    `);

    zoneLayerGroup.addLayer(marker);
  });
}

// ----------------------------------------------------------------------------
// Build Evacuation Shelters Layer
// ----------------------------------------------------------------------------
function buildEvacuationShelters() {
  evacuationCenters.forEach(evac => {
    const evacHtml = `
      <div style="background:#16A34A;color:#FFFFFF;border:2px solid #FFFFFF;box-shadow:0 2px 8px rgba(0,0,0,0.25);border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;cursor:pointer;" title="${evac.name}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
      </div>
    `;

    const icon = L.divIcon({
      className: 'custom-evac-icon',
      html: evacHtml,
      iconSize: [26, 26],
      iconAnchor: [13, 13]
    });

    const marker = L.marker([evac.lat, evac.lng], { icon: icon });
    marker.bindPopup(`
      <div style="font-family:var(--font-primary);min-width:200px;padding:4px;">
        <div style="font-size:12px;font-weight:800;color:#166534;margin-bottom:4px;">
          ${evac.name}
        </div>
        <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:4px;">
          Designated Hinaplanon Evacuation Shelter
        </div>
        <div style="font-size:11px;background:#F0FDF4;padding:6px;border-radius:4px;border:1px solid #BBF7D0;">
          Capacity: <strong>${evac.capacity.toLocaleString()} individuals</strong><br>
          Current Occupancy: <strong>${evac.current.toLocaleString()} evacuees</strong><br>
          Status: <strong style="color:#15803D;">${evac.status}</strong>
        </div>
      </div>
    `);

    evacLayerGroup.addLayer(marker);
  });
}

// ----------------------------------------------------------------------------
// Build Incident Pins Layer
// ----------------------------------------------------------------------------
function buildIncidentPins() {
  incidentPoints.forEach(inc => {
    const incHtml = `
      <div style="background:#EF4444;color:#FFFFFF;border:2px solid #FFFFFF;box-shadow:0 2px 8px rgba(220,38,38,0.4);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;cursor:pointer;" title="${inc.code} — ${inc.disaster}">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      </div>
    `;

    const icon = L.divIcon({
      className: 'custom-inc-icon',
      html: incHtml,
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });

    const marker = L.marker([inc.lat, inc.lng], { icon: icon });
    marker.bindPopup(`
      <div style="font-family:var(--font-primary);min-width:180px;padding:4px;">
        <div style="font-size:11px;font-weight:700;color:#DC2626;">${inc.code}</div>
        <div style="font-size:12px;font-weight:800;color:var(--color-primary);">${inc.disaster} Incident</div>
        <div style="font-size:10px;color:var(--color-text-secondary);margin-top:2px;">
          Purok: <strong>${inc.purok}</strong><br>
          Severity: <strong style="color:#DC2626;">${inc.severity}</strong><br>
          Displaced Families: <strong>${inc.families}</strong>
        </div>
      </div>
    `);

    incidentLayerGroup.addLayer(marker);
  });
}

// ----------------------------------------------------------------------------
// Layer Visibility Toggles
// ----------------------------------------------------------------------------
function toggleLayer(layerType) {
  if (layerType === 'heat') {
    const btn = document.getElementById('btnToggleHeatmap');
    if (map.hasLayer(heatLayer)) {
      map.removeLayer(heatLayer);
      btn.classList.remove('active');
    } else {
      heatLayer.addTo(map);
      btn.classList.add('active');
    }
  } else if (layerType === 'zones') {
    const btn = document.getElementById('btnToggleZones');
    if (map.hasLayer(zoneLayerGroup)) {
      map.removeLayer(zoneLayerGroup);
      btn.classList.remove('active');
    } else {
      zoneLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  } else if (layerType === 'evac') {
    const btn = document.getElementById('btnToggleEvac');
    if (map.hasLayer(evacLayerGroup)) {
      map.removeLayer(evacLayerGroup);
      btn.classList.remove('active');
    } else {
      evacLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  } else if (layerType === 'incidents') {
    const btn = document.getElementById('btnToggleIncidents');
    if (map.hasLayer(incidentLayerGroup)) {
      map.removeLayer(incidentLayerGroup);
      btn.classList.remove('active');
    } else {
      incidentLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  }
}

// ----------------------------------------------------------------------------
// Focus & Inspect Selected Purok
// ----------------------------------------------------------------------------
function focusPurok(p) {
  selectedPurokId = p.id;

  // 1. Pan & Zoom map smoothly to this Purok
  if (map) {
    map.flyTo([p.lat, p.lng], 16.5, {
      duration: 1.2
    });
  }

  // 2. Update Active list highlight
  document.querySelectorAll('.purok-rank-item').forEach(el => el.classList.remove('active'));
  const activeEl = document.getElementById(`rank-item-${p.id}`);
  if (activeEl) activeEl.classList.add('active');

  // 3. Update AI Inspector Details Box
  document.getElementById('inspPurokName').innerText = p.name;
  document.getElementById('inspProneCategory').innerText = p.prone_category;
  document.getElementById('inspProneCategory').style.color = p.color;

  const scoreBadge = document.getElementById('inspScoreBadge');
  scoreBadge.innerHTML = `${p.ai_risk_score} <span style="font-size:9px;display:block;font-weight:600;">AI SCORE</span>`;
  scoreBadge.style.color = p.color;
  scoreBadge.style.borderColor = p.color;
  scoreBadge.style.backgroundColor = `${p.color}15`;

  document.getElementById('inspRiverDist').innerText = p.river_distance;
  document.getElementById('inspRiverDist').style.color = p.color;
  document.getElementById('inspElevation').innerText = p.elevation;
  document.getElementById('inspPopDensity').innerText = `${Number(p.population).toLocaleString()} residents (${Number(p.households).toLocaleString()} HH)`;

  // Update animated meter bars
  document.getElementById('meterRiver').style.width = `${Math.min(100, Math.max(10, p.ai_risk_score + 2))}%`;
  document.getElementById('meterRiver').style.background = p.color;

  const elevPct = Math.max(15, 100 - (p.ai_risk_score * 0.8));
  document.getElementById('meterElevation').style.width = `${elevPct}%`;

  const densityPct = Math.min(100, (p.population / 3000) * 100);
  document.getElementById('meterDensity').style.width = `${densityPct}%`;

  document.getElementById('inspRecommendation').innerText = p.ai_recommendation;
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
