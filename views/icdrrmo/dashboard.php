<?php
// ============================================================================
// Views (ICDRRMO): Operations & AI Heatmap Dashboard
// Strictly adheres to the project design system, palette, and typography.
// Leaflet GIS Mapping for Hinaplanon Puroks & Prone-Area Susceptibility
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

$pageTitle = "Dashboard — ICDRRMO";
require_once __DIR__ . '/../layouts/header.php';

// ----------------------------------------------------------------------------
// 1. Data Fetching (Strictly Barangay Hinaplanon, ID: 12)
// ----------------------------------------------------------------------------
$hinaplanon = $db->query("SELECT * FROM barangays WHERE id = 12")->fetch(PDO::FETCH_ASSOC) ?: [
    'id' => 12, 'name' => 'Hinaplanon', 'population' => 8900, 'total_households' => 1990
];

// Hinaplanon Puroks
$purokStmt = $db->query("
    SELECT * FROM puroks 
    WHERE barangay_id = 12 AND (status != 'archived' OR status IS NULL)
    ORDER BY FIELD(risk_level, 'Critical', 'High', 'Moderate', 'Low'), name ASC
");
$allPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Disaster Reports
$incidentsStmt = $db->query("
    SELECT dr.*, b.name AS barangay_name 
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.barangay_id = 12
    ORDER BY dr.created_at DESC
");
$allIncidents = $incidentsStmt->fetchAll(PDO::FETCH_ASSOC);
$activeIncidents = array_filter($allIncidents, fn($i) => ($i['status'] ?? 'Ongoing') === 'Ongoing');
$activeIncidentsCount = count($activeIncidents);
$totalDisplacedFamilies = array_sum(array_column($allIncidents, 'displaced_families'));

// Evacuation Centers
$evacStmt = $db->query("SELECT * FROM evacuation_areas WHERE barangay_id = 12 ORDER BY name ASC");
$evacuationCenters = $evacStmt->fetchAll(PDO::FETCH_ASSOC);
$totalEvacCapacity = array_sum(array_column($evacuationCenters, 'capacity_individuals'));
$totalCurrentEvacuees = array_sum(array_column($evacuationCenters, 'current_evacuees_count'));
$shelterOccupancyPct = $totalEvacCapacity > 0 ? round(($totalCurrentEvacuees / $totalEvacCapacity) * 100, 1) : 0;

// Central Emergency Stock
$centralReliefPacks = (int)$db->query("
    SELECT COALESCE(SUM(available_quantity), 0) 
    FROM resources 
    WHERE status = 'active' AND (barangay_id IS NULL OR barangay_id = 12) AND category = 'Relief Goods'
")->fetchColumn();

// Financial Aid Stats
$aidStats = $db->query("
    SELECT 
        COALESCE(SUM(total_budget), 0) AS total_budget,
        COALESCE(SUM(disbursed_budget), 0) AS disbursed_budget,
        COALESCE(SUM(remaining_budget), 0) AS remaining_budget
    FROM financial_aid_allocations 
    WHERE barangay_id = 12
")->fetch(PDO::FETCH_ASSOC);

// Recommendations
$recommendationsStmt = $db->query("
    SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.urgency, dr.purok_name, dr.affected_families
    FROM recommendations r
    JOIN disaster_reports dr ON r.disaster_request_id = dr.id
    WHERE dr.barangay_id = 12
    ORDER BY r.created_at DESC
    LIMIT 5
");
$recentRecs = $recommendationsStmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// 2. AI Susceptibility Model Profiles for Hinaplanon Puroks
// ----------------------------------------------------------------------------
$purokProfiles = [
    'Purok 1' => [
        'ai_risk_score' => 94,
        'prone_category' => 'River Delta Inundation Corridor',
        'susceptibility' => 'Critical',
        'primary_hazard' => 'Flash Flood & River Overflow',
        'elevation' => '3.2m',
        'river_distance' => '45m',
        'alert_level' => 'Red Alert',
        'ai_recommendation' => 'Early evacuation trigger at 1.8m river stage; pre-position watercraft at Sector 1-A.',
        'radius' => 200,
        'color' => '#C62828' // var(--color-danger)
    ],
    'Purok 3A' => [
        'ai_risk_score' => 84,
        'prone_category' => 'Low-Lying Urban Catchment',
        'susceptibility' => 'High',
        'primary_hazard' => 'River Overflow & Street Flooding',
        'elevation' => '4.8m',
        'river_distance' => '120m',
        'alert_level' => 'Orange Alert',
        'ai_recommendation' => 'Stage 500 food packs and initiate voluntary relocation for vulnerable households.',
        'radius' => 190,
        'color' => '#B78103' // var(--color-warning)
    ],
    'Purok 4A' => [
        'ai_risk_score' => 64,
        'prone_category' => 'Inland Runoff Sector',
        'susceptibility' => 'Moderate',
        'primary_hazard' => 'Localized Ponding',
        'elevation' => '6.5m',
        'river_distance' => '280m',
        'alert_level' => 'Yellow Alert',
        'ai_recommendation' => 'Keep drainage culverts clear and standby dewatering pumps.',
        'radius' => 170,
        'color' => '#2F6F73' // var(--color-secondary)
    ],
    'Purok 6' => [
        'ai_risk_score' => 52,
        'prone_category' => 'Estuary Transition Sector',
        'susceptibility' => 'Moderate',
        'primary_hazard' => 'Tidal Inflow & Runoff',
        'elevation' => '5.9m',
        'river_distance' => '210m',
        'alert_level' => 'Yellow Alert',
        'ai_recommendation' => 'Monitor high-tide synchronization with river discharge.',
        'radius' => 160,
        'color' => '#2F6F73' // var(--color-secondary)
    ],
    'Purok 5B' => [
        'ai_risk_score' => 28,
        'prone_category' => 'Elevated Ground (Safe Zone)',
        'susceptibility' => 'Low',
        'primary_hazard' => 'Low Hazard Zone',
        'elevation' => '14.2m',
        'river_distance' => '460m',
        'alert_level' => 'Normal',
        'ai_recommendation' => 'Safe refuge sector and emergency relief distribution hub.',
        'radius' => 200,
        'color' => '#2E7D32' // var(--color-success)
    ]
];

$aiPurokData = [];
$totalHinaplanonPop = 0;
$totalHinaplanonHouseholds = 0;
$criticalPuroksCount = 0;
$highPuroksCount = 0;

foreach ($allPuroks as $p) {
    $pName = trim($p['name']);
    $profile = $purokProfiles[$pName] ?? [
        'ai_risk_score' => 50,
        'prone_category' => 'General Residential Sector',
        'susceptibility' => $p['risk_level'] ?? 'Moderate',
        'primary_hazard' => $p['hazard_types'] ?: 'General Flood Hazard',
        'elevation' => '6.0m',
        'river_distance' => '200m',
        'alert_level' => 'Monitoring',
        'ai_recommendation' => 'Maintain standard community preparedness.',
        'radius' => 170,
        'color' => '#2F6F73'
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

usort($aiPurokData, fn($a, $b) => $b['ai_risk_score'] <=> $a['ai_risk_score']);
?>

<!-- Leaflet GIS Map Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<style>
/* Anti-Slop Strict Layout & Typography */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}
.kpi-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-primary);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.kpi-title {
  font-size: 10px;
  font-weight: 600;
  color: var(--color-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.kpi-val {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  font-family: var(--font-secondary);
  line-height: 1.2;
}
.kpi-sub {
  font-size: 10px;
  color: var(--color-text-muted);
}

/* Heatmap Section Grid */
.heatmap-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: var(--space-4);
  align-items: stretch;
  margin-bottom: var(--space-4);
}
@media (max-width: 1024px) {
  .heatmap-grid {
    grid-template-columns: 1fr;
  }
}

.map-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-primary);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.map-card-header {
  padding: 10px 14px;
  background: #FAFCFC;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.map-toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
}
.map-pill {
  font-size: 10px;
  padding: 4px 9px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  cursor: pointer;
  font-weight: 600;
  font-family: var(--font-primary);
  transition: background 0.15s ease, color 0.15s ease;
}
.map-pill.active {
  background: var(--color-primary);
  color: #FFFFFF;
  border-color: var(--color-primary);
}

#hinaplanonHeatmap {
  width: 100%;
  height: 480px;
  background: #F0F3F4;
}

/* AI Prone-Area Inspector */
.inspector-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-primary);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.inspector-header {
  padding: 10px 14px;
  background: #FAFCFC;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.inspector-header h3 {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--color-primary);
  margin: 0;
}
.purok-list {
  max-height: 200px;
  overflow-y: auto;
  border-bottom: 1px solid var(--color-border-light);
}
.purok-item {
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  font-size: 11px;
  transition: background 0.15s ease;
}
.purok-item:hover, .purok-item.active {
  background: var(--color-surface-subtle);
}
.purok-item.active {
  border-left: 3px solid var(--color-primary);
}
.inspector-body {
  padding: 12px 14px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.meter-track {
  background: var(--color-surface-subtle);
  height: 5px;
  border-radius: 999px;
  overflow: hidden;
  margin-top: 3px;
}
.meter-fill {
  height: 100%;
  border-radius: 999px;
}

.table-split-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}
@media (max-width: 900px) {
  .table-split-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<!-- ========================================================================= -->
<!-- 1. PAGE HEADER                                                            -->
<!-- ========================================================================= -->
<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Dashboard</h1>
    <p class="page-header-desc">
      Operations overview and AI hazard heatmap analysis for Hinaplanon puroks.
    </p>
  </div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php" class="btn btn-outline" style="font-size:11px;padding:6px 12px;">
      Manage Resources
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/financial-aid-allocation.php" class="btn btn-primary" style="font-size:11px;padding:6px 12px;">
      Financial Aid
    </a>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 2. TELEMETRY KPI CARDS                                                    -->
<!-- ========================================================================= -->
<div class="kpi-row">
  <div class="kpi-card">
    <div class="kpi-title">Hinaplanon Population</div>
    <div class="kpi-val"><?= number_format($totalHinaplanonPop) ?></div>
    <div class="kpi-sub"><?= number_format($totalHinaplanonHouseholds) ?> households across 5 puroks</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-title">Critical & High Prone Zones</div>
    <div class="kpi-val" style="color:var(--color-danger);"><?= $criticalPuroksCount + $highPuroksCount ?> Puroks</div>
    <div class="kpi-sub">Purok 1 & Purok 3A (Mandulog delta)</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-title">Active Disaster Reports</div>
    <div class="kpi-val" style="color:var(--color-warning);"><?= $activeIncidentsCount ?></div>
    <div class="kpi-sub"><?= number_format($totalDisplacedFamilies) ?> displaced families recorded</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-title">Shelter Occupancy</div>
    <div class="kpi-val" style="color:var(--color-success);"><?= number_format($totalCurrentEvacuees) ?> / <?= number_format($totalEvacCapacity) ?></div>
    <div class="kpi-sub"><?= $shelterOccupancyPct ?>% capacity in evacuation centers</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-title">Relief Goods Stock</div>
    <div class="kpi-val"><?= number_format($centralReliefPacks) ?></div>
    <div class="kpi-sub">Food packs available in depot</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-title">Financial Aid Disbursed</div>
    <div class="kpi-val" style="color:var(--color-primary);font-size:17px;">
      &#8369;<?= number_format($aidStats['disbursed_budget'], 2) ?>
    </div>
    <div class="kpi-sub">of &#8369;<?= number_format($aidStats['total_budget'], 2) ?> allocated</div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 3. AI HEATMAP & PRONE-AREA ANALYSIS (LEAFLET MAPPING)                    -->
<!-- ========================================================================= -->
<div class="heatmap-grid">
  <!-- Map Card -->
  <div class="map-card">
    <div class="map-card-header">
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--color-primary);">
          AI Heatmap Analysis — Hinaplanon Puroks
        </div>
        <div style="font-size:10px;color:var(--color-text-muted);">
          Leaflet GIS hazard susceptibility mapping and prone-area identification.
        </div>
      </div>

      <div class="map-toolbar">
        <button type="button" class="map-pill active" id="btnHeatmap" onclick="toggleLayer('heat')">
          Heatmap
        </button>
        <button type="button" class="map-pill active" id="btnZones" onclick="toggleLayer('zones')">
          Prone Zones
        </button>
        <button type="button" class="map-pill active" id="btnEvac" onclick="toggleLayer('evac')">
          Evacuation Shelters
        </button>
        <button type="button" class="map-pill active" id="btnIncidents" onclick="toggleLayer('incidents')">
          Incidents
        </button>
      </div>
    </div>

    <!-- Leaflet Map Container -->
    <div id="hinaplanonHeatmap"></div>

    <!-- Minimal Legend -->
    <div style="padding:7px 14px;background:#FAFCFC;border-top:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;font-size:10px;color:var(--color-text-secondary);flex-wrap:wrap;gap:8px;">
      <div style="display:flex;gap:12px;align-items:center;">
        <span style="font-weight:600;color:var(--color-text);">Prone Scale:</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;border-radius:2px;background:var(--color-danger);"></span> Critical (90+)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;border-radius:2px;background:var(--color-warning);"></span> High (75-89)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;border-radius:2px;background:var(--color-secondary);"></span> Moderate (50-74)</span>
        <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;border-radius:2px;background:var(--color-success);"></span> Low / Safe (&lt;50)</span>
      </div>
      <div>Click any purok zone to inspect assessment details.</div>
    </div>
  </div>

  <!-- AI Prone-Area Inspector -->
  <div class="inspector-card">
    <div class="inspector-header">
      <h3>Purok Susceptibility Ranking</h3>
      <span style="font-size:9.5px;color:var(--color-text-muted);">5 Puroks</span>
    </div>

    <!-- Purok Selection List -->
    <div class="purok-list" id="purokList">
      <?php foreach ($aiPurokData as $idx => $p): ?>
        <div class="purok-item <?= $idx === 0 ? 'active' : '' ?>" id="purok-item-<?= $p['id'] ?>" onclick="focusPurok(<?= htmlspecialchars(json_encode($p)) ?>)">
          <div>
            <div style="font-weight:600;color:var(--color-primary);"><?= clean($p['name']) ?></div>
            <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($p['prone_category']) ?></div>
          </div>
          <div style="text-align:right;">
            <div style="font-family:var(--font-secondary);font-weight:700;color:<?= $p['color'] ?>;font-size:12px;">
              <?= $p['ai_risk_score'] ?><span style="font-size:9px;color:var(--color-text-muted);">/100</span>
            </div>
            <span class="badge" style="font-size:8.5px;padding:1px 5px;background:<?= $p['color'] ?>15;color:<?= $p['color'] ?>;border:1px solid <?= $p['color'] ?>35;">
              <?= $p['susceptibility'] ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Inspector Details Box -->
    <div class="inspector-body">
      <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
        <div>
          <div id="inspName" style="font-size:13px;font-weight:700;color:var(--color-primary);">Purok 1</div>
          <div id="inspCategory" style="font-size:10px;font-weight:600;color:var(--color-danger);">River Delta Inundation Corridor</div>
        </div>
        <div id="inspScore" style="font-size:16px;font-weight:700;font-family:var(--font-secondary);color:var(--color-danger);">
          94 <span style="font-size:9px;color:var(--color-text-muted);">SCORE</span>
        </div>
      </div>

      <!-- Vulnerability Meters -->
      <div>
        <div style="display:flex;justify-content:space-between;font-size:9.5px;color:var(--color-text-secondary);">
          <span>River Proximity:</span>
          <span id="inspRiverDist" style="font-weight:600;color:var(--color-danger);">45m</span>
        </div>
        <div class="meter-track">
          <div class="meter-fill" id="meterRiver" style="width:96%;background:var(--color-danger);"></div>
        </div>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;font-size:9.5px;color:var(--color-text-secondary);">
          <span>Elevation:</span>
          <span id="inspElevation" style="font-weight:600;">3.2m</span>
        </div>
        <div class="meter-track">
          <div class="meter-fill" id="meterElevation" style="width:85%;background:var(--color-warning);"></div>
        </div>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;font-size:9.5px;color:var(--color-text-secondary);">
          <span>Population:</span>
          <span id="inspPop" style="font-weight:600;">2,050 (450 households)</span>
        </div>
        <div class="meter-track">
          <div class="meter-fill" id="meterPop" style="width:75%;background:var(--color-secondary);"></div>
        </div>
      </div>

      <!-- Recommendation -->
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);padding:8px 10px;margin-top:2px;">
        <div style="font-size:9.5px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;margin-bottom:2px;">
          Action Recommendation
        </div>
        <div id="inspRecommendation" style="font-size:10.5px;color:var(--color-text);line-height:1.4;">
          Early evacuation trigger at 1.8m river stage; pre-position watercraft at Sector 1-A.
        </div>
      </div>

      <!-- Quick Action Buttons -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:auto;padding-top:6px;">
        <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=allocations" class="btn btn-outline" style="font-size:10px;padding:5px 8px;text-align:center;">
          Allocate Resources
        </a>
        <a href="<?= BASE_URL ?>/views/icdrrmo/financial-aid-allocation.php" class="btn btn-primary" style="font-size:10px;padding:5px 8px;text-align:center;">
          Financial Aid
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 4. HINAPLANON PUROK PRONE-AREA DIRECTORY                                  -->
<!-- ========================================================================= -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:var(--space-4);">
  <div style="padding:10px 14px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
    <h3 style="font-size:12px;font-weight:700;color:var(--color-primary);margin:0;">
      Hinaplanon Purok Prone-Area Directory
    </h3>
    <span style="font-size:10px;color:var(--color-text-muted);">Brgy. Hinaplanon (ID: 12)</span>
  </div>

  <div class="table-responsive">
    <table class="data-table" style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--color-border);background:#F8FAFC;">
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:left;">Purok</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:left;">Prone Category</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:center;">AI Score</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:left;">Primary Hazard</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:right;">Population</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:right;">Households</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:left;">Alert Level</th>
          <th style="padding:8px 12px;font-size:10px;font-weight:600;color:var(--color-text-secondary);text-align:center;width:90px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($aiPurokData as $p): ?>
          <tr style="border-bottom:1px solid var(--color-border-light);">
            <td style="padding:8px 12px;font-weight:600;color:var(--color-primary);font-size:11px;">
              <?= clean($p['name']) ?>
            </td>
            <td style="padding:8px 12px;font-size:11px;">
              <?= clean($p['prone_category']) ?>
            </td>
            <td style="padding:8px 12px;text-align:center;font-family:var(--font-secondary);font-weight:700;color:<?= $p['color'] ?>;font-size:12px;">
              <?= $p['ai_risk_score'] ?>
            </td>
            <td style="padding:8px 12px;font-size:10.5px;color:var(--color-text-secondary);">
              <?= clean($p['primary_hazard']) ?>
            </td>
            <td style="padding:8px 12px;text-align:right;font-family:var(--font-secondary);font-size:11px;">
              <?= number_format($p['population']) ?>
            </td>
            <td style="padding:8px 12px;text-align:right;font-family:var(--font-secondary);font-size:11px;">
              <?= number_format($p['households']) ?>
            </td>
            <td style="padding:8px 12px;font-size:10px;font-weight:600;color:<?= $p['color'] ?>;">
              <?= clean($p['alert_level']) ?>
            </td>
            <td style="padding:8px 12px;text-align:center;">
              <button type="button" class="btn btn-outline" style="font-size:10px;padding:3px 8px;" onclick="focusPurok(<?= htmlspecialchars(json_encode($p)) ?>); window.scrollTo({top: 240, behavior: 'smooth'});">
                Inspect
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ========================================================================= -->
<!-- 5. OPERATIONAL TABLES                                                     -->
<!-- ========================================================================= -->
<div class="table-split-grid">
  <!-- Active Reports -->
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:0;">
    <div style="padding:10px 14px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="font-size:12px;font-weight:700;color:var(--color-primary);margin:0;">Active Disaster Reports</h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports.php" class="btn btn-outline" style="font-size:9.5px;padding:3px 7px;">View All</a>
    </div>
    <div class="table-responsive">
      <table class="data-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--color-border);background:#F8FAFC;">
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Tracking #</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Purok</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Type</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Severity</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allIncidents)): ?>
            <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--color-text-muted);font-size:11px;">No active reports recorded for Hinaplanon.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($allIncidents, 0, 5) as $inc): ?>
              <tr style="border-bottom:1px solid var(--color-border-light);">
                <td style="padding:8px 12px;font-family:var(--font-secondary);font-weight:600;font-size:11px;color:var(--color-primary);">
                  <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports.php?search=<?= urlencode($inc['tracking_code']) ?>" style="color:var(--color-primary);text-decoration:none;">
                    <?= clean($inc['tracking_code']) ?>
                  </a>
                </td>
                <td style="padding:8px 12px;font-size:11px;font-weight:500;"><?= clean($inc['purok_name'] ?: 'All Puroks') ?></td>
                <td style="padding:8px 12px;font-size:11px;"><?= clean($inc['disaster_type'] ?: 'Flood') ?></td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge" style="font-size:9px;padding:1px 6px;<?= in_array($inc['severity'], ['High', 'Critical']) ? 'background:var(--color-danger-bg);color:var(--color-danger);border:1px solid var(--color-danger-border);' : 'background:var(--color-warning-bg);color:var(--color-warning);border:1px solid var(--color-warning-border);' ?>">
                    <?= clean($inc['severity']) ?>
                  </span>
                </td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge" style="font-size:9px;padding:1px 6px;<?= $inc['status'] === 'Ongoing' ? 'background:var(--color-danger-bg);color:var(--color-danger);border:1px solid var(--color-danger-border);' : 'background:var(--color-success-bg);color:var(--color-success);border:1px solid var(--color-success-border);' ?>">
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

  <!-- Resource Recommendations -->
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:0;">
    <div style="padding:10px 14px;background:#FAFCFC;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="font-size:12px;font-weight:700;color:var(--color-primary);margin:0;">Resource Recommendations</h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-outline" style="font-size:9.5px;padding:3px 7px;">Review</a>
    </div>
    <div class="table-responsive">
      <table class="data-table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--color-border);background:#F8FAFC;">
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Code</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Target</th>
            <th style="padding:8px 12px;font-size:10px;text-align:left;">Disaster</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Confidence</th>
            <th style="padding:8px 12px;font-size:10px;text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentRecs)): ?>
            <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--color-text-muted);font-size:11px;">No pending recommendations for Hinaplanon.</td></tr>
          <?php else: ?>
            <?php foreach ($recentRecs as $rec): ?>
              <tr style="border-bottom:1px solid var(--color-border-light);">
                <td style="padding:8px 12px;font-family:var(--font-secondary);font-weight:600;font-size:11px;">
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $rec['id'] ?>" style="color:var(--color-primary);text-decoration:none;">
                    <?= clean($rec['recommendation_code']) ?>
                  </a>
                </td>
                <td style="padding:8px 12px;font-size:11px;font-weight:500;"><?= clean($rec['purok_name'] ?: 'Hinaplanon') ?></td>
                <td style="padding:8px 12px;font-size:11px;"><?= clean($rec['disaster_type']) ?></td>
                <td style="padding:8px 12px;text-align:center;font-family:var(--font-secondary);font-size:11px;font-weight:600;color:var(--color-success);">
                  <?= round($rec['confidence_score'] * 100) ?>%
                </td>
                <td style="padding:8px 12px;text-align:center;">
                  <span class="badge" style="font-size:9px;padding:1px 6px;<?= $rec['status'] === 'Pending Review' ? 'background:var(--color-warning-bg);color:var(--color-warning);border:1px solid var(--color-warning-border);' : 'background:var(--color-success-bg);color:var(--color-success);border:1px solid var(--color-success-border);' ?>">
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
<!-- 6. LEAFLET SCRIPT                                                         -->
<!-- ========================================================================= -->
<script>
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

let map = null;
let heatLayer = null;
let zoneLayerGroup = L.layerGroup();
let evacLayerGroup = L.layerGroup();
let incidentLayerGroup = L.layerGroup();

document.addEventListener('DOMContentLoaded', function() {
  initMap();
});

function initMap() {
  map = L.map('hinaplanonHeatmap', {
    center: [8.2458, 124.2605],
    zoom: 15.5,
    zoomControl: true,
    attributionControl: false
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  buildHeatmap();
  buildZones();
  buildEvacuation();
  buildIncidents();

  zoneLayerGroup.addTo(map);
  evacLayerGroup.addTo(map);
  incidentLayerGroup.addTo(map);

  if (purokData.length > 0) {
    focusPurok(purokData[0]);
  }
}

function buildHeatmap() {
  const points = [];
  purokData.forEach(p => {
    const weight = p.ai_risk_score / 100;
    points.push([p.lat, p.lng, weight]);
    const d = 0.0007;
    points.push([p.lat + d, p.lng + d, weight * 0.7]);
    points.push([p.lat - d, p.lng + d, weight * 0.7]);
    points.push([p.lat + d, p.lng - d, weight * 0.6]);
    points.push([p.lat - d, p.lng - d, weight * 0.6]);
  });

  incidentPoints.forEach(inc => {
    points.push([inc.lat, inc.lng, inc.severity === 'Critical' ? 1.0 : 0.8]);
  });

  if (typeof L.heatLayer === 'function') {
    heatLayer = L.heatLayer(points, {
      radius: 36,
      blur: 22,
      maxZoom: 17,
      max: 1.0,
      gradient: {
        0.2: '#2F6F73', // secondary teal
        0.5: '#B78103', // warning amber
        0.9: '#C62828'  // danger red
      }
    }).addTo(map);
  }
}

function buildZones() {
  purokData.forEach(p => {
    const circle = L.circle([p.lat, p.lng], {
      radius: p.radius || 180,
      color: p.color,
      fillColor: p.color,
      fillOpacity: p.susceptibility === 'Critical' ? 0.22 : 0.16,
      weight: 1.5
    });
    circle.on('click', () => focusPurok(p));
    zoneLayerGroup.addLayer(circle);

    const icon = L.divIcon({
      className: 'purok-marker',
      html: `<div style="width:12px;height:12px;border-radius:50%;background:${p.color};border:2px solid #FFF;box-shadow:0 1px 3px rgba(0,0,0,0.3);cursor:pointer;"></div>`,
      iconSize: [12, 12],
      iconAnchor: [6, 6]
    });

    const marker = L.marker([p.lat, p.lng], { icon: icon });
    marker.on('click', () => focusPurok(p));

    marker.bindPopup(`
      <div style="font-family:var(--font-primary);font-size:11px;min-width:180px;">
        <div style="font-weight:700;color:var(--color-primary);margin-bottom:2px;">${p.name}</div>
        <div style="color:${p.color};font-weight:600;font-size:10px;margin-bottom:4px;">${p.prone_category} (${p.ai_risk_score}/100)</div>
        <div style="font-size:10px;color:var(--color-text-secondary);">
          River: <strong>${p.river_distance}</strong> &bull; Elevation: <strong>${p.elevation}</strong><br>
          Population: <strong>${Number(p.population).toLocaleString()}</strong> (${Number(p.households).toLocaleString()} HH)
        </div>
      </div>
    `);

    zoneLayerGroup.addLayer(marker);
  });
}

function buildEvacuation() {
  evacuationCenters.forEach(evac => {
    const icon = L.divIcon({
      className: 'evac-marker',
      html: `<div style="background:var(--color-success);color:#FFF;border-radius:3px;padding:2px 4px;font-size:9px;font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,0.2);cursor:pointer;white-space:nowrap;border:1px solid #FFF;">Shelter: ${evac.name.substring(0, 14)}...</div>`,
      iconSize: [80, 20],
      iconAnchor: [40, 10]
    });

    const marker = L.marker([evac.lat, evac.lng], { icon: icon });
    marker.bindPopup(`
      <div style="font-family:var(--font-primary);font-size:11px;">
        <strong>${evac.name}</strong><br>
        <span style="font-size:10px;color:var(--color-text-secondary);">Capacity: ${evac.capacity} | Current: ${evac.current}</span>
      </div>
    `);
    evacLayerGroup.addLayer(marker);
  });
}

function buildIncidents() {
  incidentPoints.forEach(inc => {
    const icon = L.divIcon({
      className: 'inc-marker',
      html: `<div style="width:10px;height:10px;border-radius:50%;background:var(--color-danger);border:1.5px solid #FFF;cursor:pointer;"></div>`,
      iconSize: [10, 10],
      iconAnchor: [5, 5]
    });

    const marker = L.marker([inc.lat, inc.lng], { icon: icon });
    marker.bindPopup(`
      <div style="font-family:var(--font-primary);font-size:11px;">
        <strong>${inc.code} — ${inc.disaster}</strong><br>
        <span style="font-size:10px;color:var(--color-text-secondary);">${inc.purok} &bull; ${inc.families} families</span>
      </div>
    `);
    incidentLayerGroup.addLayer(marker);
  });
}

function toggleLayer(type) {
  if (type === 'heat' && heatLayer) {
    const btn = document.getElementById('btnHeatmap');
    if (map.hasLayer(heatLayer)) {
      map.removeLayer(heatLayer);
      btn.classList.remove('active');
    } else {
      heatLayer.addTo(map);
      btn.classList.add('active');
    }
  } else if (type === 'zones') {
    const btn = document.getElementById('btnZones');
    if (map.hasLayer(zoneLayerGroup)) {
      map.removeLayer(zoneLayerGroup);
      btn.classList.remove('active');
    } else {
      zoneLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  } else if (type === 'evac') {
    const btn = document.getElementById('btnEvac');
    if (map.hasLayer(evacLayerGroup)) {
      map.removeLayer(evacLayerGroup);
      btn.classList.remove('active');
    } else {
      evacLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  } else if (type === 'incidents') {
    const btn = document.getElementById('btnIncidents');
    if (map.hasLayer(incidentLayerGroup)) {
      map.removeLayer(incidentLayerGroup);
      btn.classList.remove('active');
    } else {
      incidentLayerGroup.addTo(map);
      btn.classList.add('active');
    }
  }
}

function focusPurok(p) {
  if (map) {
    map.flyTo([p.lat, p.lng], 16.2, { duration: 0.8 });
  }

  document.querySelectorAll('.purok-item').forEach(el => el.classList.remove('active'));
  const activeEl = document.getElementById(`purok-item-${p.id}`);
  if (activeEl) activeEl.classList.add('active');

  document.getElementById('inspName').innerText = p.name;
  document.getElementById('inspCategory').innerText = p.prone_category;
  document.getElementById('inspCategory').style.color = p.color;

  document.getElementById('inspScore').innerHTML = `${p.ai_risk_score} <span style="font-size:9px;color:var(--color-text-muted);">SCORE</span>`;
  document.getElementById('inspScore').style.color = p.color;

  document.getElementById('inspRiverDist').innerText = p.river_distance;
  document.getElementById('inspRiverDist').style.color = p.color;
  document.getElementById('inspElevation').innerText = p.elevation;
  document.getElementById('inspPop').innerText = `${Number(p.population).toLocaleString()} (${Number(p.households).toLocaleString()} households)`;

  document.getElementById('meterRiver').style.width = `${Math.min(100, Math.max(10, p.ai_risk_score + 2))}%`;
  document.getElementById('meterRiver').style.background = p.color;

  const elevPct = Math.max(15, 100 - (p.ai_risk_score * 0.8));
  document.getElementById('meterElevation').style.width = `${elevPct}%`;

  const densityPct = Math.min(100, (p.population / 3000) * 100);
  document.getElementById('meterPop').style.width = `${densityPct}%`;

  document.getElementById('inspRecommendation').innerText = p.ai_recommendation;
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
