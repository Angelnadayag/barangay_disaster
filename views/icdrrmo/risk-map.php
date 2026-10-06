<?php
// ============================================================================
// Views (ICDRRMO): Risk Level / Heatmap & Spatial Visualization Module
// Clean, professional GIS interface, semantic colors, compact controls
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Risk Level & Spatial Hazard Map";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Fetch all barangays with risk levels and coordinates
$barangays = $db->query("SELECT * FROM barangays ORDER BY risk_level DESC, name ASC")->fetchAll();

// Fetch puroks
$puroks = $db->query("
    SELECT p.*, b.name AS barangay_name 
    FROM puroks p 
    JOIN barangays b ON p.barangay_id = b.id
")->fetchAll();

// Fetch active incidents
$activeIncidents = $db->query("
    SELECT dr.*, b.name AS barangay_name, b.coordinates_lat, b.coordinates_lng 
    FROM disaster_requests dr 
    JOIN barangays b ON dr.barangay_id = b.id 
    WHERE dr.status NOT IN ('Completed', 'Rejected')
")->fetchAll();

// Fetch evacuation centers
$evacCenters = $db->query("
    SELECT ea.*, b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id
")->fetchAll();
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Risk Level & Spatial Hazard Visualization</h1>
      <p class="page-header-desc">
        Interactive GIS risk assessment mapping barangay hazard zones, active disaster requests, and designated evacuation sanctuaries.
      </p>
    </div>
    <div class="page-header-actions">
      <span class="badge badge-danger" style="font-size:10px;">Mandulog River Flood Stage: Red Alert</span>
    </div>
  </div>

  <!-- Map Container & Inspector Grid -->
  <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-4);align-items:start;">
    <!-- Left: Map Canvas & Controls -->
    <div class="card" style="margin-bottom:0;overflow:hidden;">
      <div class="card-header" style="padding:8px 12px;background:var(--color-surface-subtle);flex-wrap:wrap;gap:8px;">
        <!-- Compact Layer Controls -->
        <div style="display:flex;align-items:center;gap:6px;">
          <span style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">Map Layers:</span>
          <button class="btn btn-outline btn-sm layer-toggle-btn active" data-layer="all" onclick="toggleMapLayer('all', this)">All Layers</button>
          <button class="btn btn-outline btn-sm layer-toggle-btn" data-layer="hazards" onclick="toggleMapLayer('hazards', this)">Hazard Risk Zones</button>
          <button class="btn btn-outline btn-sm layer-toggle-btn" data-layer="evac" onclick="toggleMapLayer('evac', this)">Evacuation Centers</button>
          <button class="btn btn-outline btn-sm layer-toggle-btn" data-layer="incidents" onclick="toggleMapLayer('incidents', this)">Active Incidents</button>
        </div>

        <!-- Quick Barangay Focus -->
        <div style="display:flex;align-items:center;gap:6px;">
          <span style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">Focus:</span>
          <select id="mapBarangayFocus" class="form-control" style="width:140px;height:24px;font-size:9px;" onchange="focusBarangay(this.value)">
            <option value="all">Entire City Overview</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?> (<?= clean($b['risk_level']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Map Render Area -->
      <div style="position:relative;background:#EAF0F4;height:520px;width:100%;overflow:hidden;" id="mapCanvasContainer">
        <!-- SVG Interactive Vector Map of Iligan City Coastline & Watershed -->
        <svg id="iliganSvgMap" viewBox="0 0 800 520" style="width:100%;height:100%;cursor:grab;">
          <defs>
            <radialGradient id="criticalGlow" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#C62828" stop-opacity="0.4"/>
              <stop offset="100%" stop-color="#C62828" stop-opacity="0.0"/>
            </radialGradient>
            <radialGradient id="highGlow" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#B78103" stop-opacity="0.35"/>
              <stop offset="100%" stop-color="#B78103" stop-opacity="0.0"/>
            </radialGradient>
          </defs>

          <!-- Base Topography & Iligan Bay Water Body -->
          <rect width="800" height="520" fill="#E8EEF3"/>
          <!-- Iligan Bay (Ocean) on Left / Northwest -->
          <path d="M 0 0 L 260 0 C 240 100, 270 200, 230 310 C 200 400, 150 480, 100 520 L 0 520 Z" fill="#D3E2ED" stroke="#B8CFE0" stroke-width="1.5"/>
          <text x="70" y="240" fill="#7E9EB8" font-size="12" font-weight="700" letter-spacing="2" transform="rotate(-40 70 240)">ILIGAN BAY</text>

          <!-- Mandulog River System Flowing into Bay at Hinaplanon -->
          <path d="M 780 140 Q 600 130 480 150 T 360 170 T 260 160" fill="none" stroke="#6AA5C9" stroke-width="6" stroke-linecap="round"/>
          <path d="M 780 140 Q 600 130 480 150 T 360 170 T 260 160" fill="none" stroke="#A2CCE4" stroke-width="3" stroke-linecap="round"/>
          <text x="500" y="142" fill="#4B7B99" font-size="9" font-weight="600">Mandulog River</text>

          <!-- Tubod River / Coastal Creek -->
          <path d="M 750 360 Q 520 340 380 330 T 240 320" fill="none" stroke="#90BEDB" stroke-width="4" stroke-linecap="round"/>

          <!-- BARANGAY TERRITORY POLYGONS -->
          <!-- 1. Hinaplanon (Mandulog River delta, Critical Flood Risk) -->
          <polygon id="poly-brgy-1" class="brgy-poly hazard-zone-poly"
                   points="255,120 380,120 420,180 360,220 240,200 250,150"
                   fill="#FDE8E8" stroke="#E05252" stroke-width="2"
                   data-id="1" data-name="Hinaplanon" data-risk="Critical"
                   onclick="selectBarangayOnMap(1)"/>
          <circle cx="320" cy="165" r="45" fill="url(#criticalGlow)" class="hazard-overlay"/>
          <text x="290" y="160" font-size="10" font-weight="700" fill="#8A1F1F">Hinaplanon</text>
          <text x="292" y="172" font-size="8" font-weight="600" fill="#C62828">CRITICAL RISK</text>

          <!-- 2. Tubod (Coastal & Highway High Risk) -->
          <polygon id="poly-brgy-2" class="brgy-poly hazard-zone-poly"
                   points="220,290 350,280 390,340 320,380 200,360"
                   fill="#FEF8E8" stroke="#D4A12B" stroke-width="1.8"
                   data-id="2" data-name="Tubod" data-risk="High"
                   onclick="selectBarangayOnMap(2)"/>
          <circle cx="280" cy="330" r="40" fill="url(#highGlow)" class="hazard-overlay"/>
          <text x="260" y="325" font-size="10" font-weight="700" fill="#6E500A">Tubod</text>
          <text x="260" y="337" font-size="8" font-weight="600" fill="#B78103">HIGH RISK</text>

          <!-- 3. Tambacan (Coastal / River Mouth High Risk) -->
          <polygon id="poly-brgy-3" class="brgy-poly hazard-zone-poly"
                   points="235,200 320,200 340,260 225,260"
                   fill="#FEF8E8" stroke="#D4A12B" stroke-width="1.8"
                   data-id="3" data-name="Tambacan" data-risk="High"
                   onclick="selectBarangayOnMap(3)"/>
          <text x="250" y="235" font-size="9" font-weight="700" fill="#6E500A">Tambacan</text>

          <!-- 4. Pala-o (Central Urban Moderate Risk) -->
          <polygon id="poly-brgy-4" class="brgy-poly hazard-zone-poly"
                   points="320,200 440,190 460,270 340,265"
                   fill="#FFFDF4" stroke="#B8C4CC" stroke-width="1.5"
                   data-id="4" data-name="Pala-o" data-risk="Moderate"
                   onclick="selectBarangayOnMap(4)"/>
          <text x="370" y="230" font-size="9" font-weight="600" fill="#24313A">Pala-o</text>

          <!-- 5. Suarez (Southern Low Risk) -->
          <polygon id="poly-brgy-5" class="brgy-poly hazard-zone-poly"
                   points="190,360 320,380 340,460 170,440"
                   fill="#EAF4EB" stroke="#7CB683" stroke-width="1.5"
                   data-id="5" data-name="Suarez" data-risk="Low"
                   onclick="selectBarangayOnMap(5)"/>
          <text x="240" y="415" font-size="9" font-weight="600" fill="#1B5E20">Suarez</text>

          <!-- 6. San Roque (Upland Hinterland Moderate Risk) -->
          <polygon id="poly-brgy-6" class="brgy-poly hazard-zone-poly"
                   points="420,180 560,170 590,260 450,265"
                   fill="#FFFDF4" stroke="#B8C4CC" stroke-width="1.5"
                   data-id="6" data-name="San Roque" data-risk="Moderate"
                   onclick="selectBarangayOnMap(6)"/>
          <text x="480" y="220" font-size="9" font-weight="600" fill="#24313A">San Roque</text>

          <!-- 7. Sta. Elena (Northeastern Foothills Low Risk) -->
          <polygon id="poly-brgy-7" class="brgy-poly hazard-zone-poly"
                   points="380,110 540,100 560,170 410,175"
                   fill="#EAF4EB" stroke="#7CB683" stroke-width="1.5"
                   data-id="7" data-name="Sta. Elena" data-risk="Low"
                   onclick="selectBarangayOnMap(7)"/>
          <text x="440" y="140" font-size="9" font-weight="600" fill="#1B5E20">Sta. Elena</text>

          <!-- ACTIVE INCIDENT MARKERS (Pulsing Pin) -->
          <g id="layer-incidents" class="map-layer-group">
            <g transform="translate(310, 155)" class="incident-marker" onclick="inspectIncident(1)" style="cursor:pointer;">
              <circle cx="0" cy="0" r="12" fill="#C62828" opacity="0.25"/>
              <circle cx="0" cy="0" r="7" fill="#C62828"/>
              <path d="M -3 -3 L 3 3 M 3 -3 L -3 3" stroke="#FFF" stroke-width="1.5"/>
              <text x="12" y="4" font-size="8" font-weight="700" fill="#C62828">FLOOD: REQ-001</text>
            </g>

            <g transform="translate(245, 310)" class="incident-marker" onclick="inspectIncident(2)" style="cursor:pointer;">
              <circle cx="0" cy="0" r="10" fill="#B78103" opacity="0.3"/>
              <circle cx="0" cy="0" r="6" fill="#B78103"/>
              <circle cx="0" cy="0" r="2" fill="#FFF"/>
              <text x="10" y="4" font-size="8" font-weight="700" fill="#B78103">SURGE: REQ-002</text>
            </g>
          </g>

          <!-- EVACUATION CENTER MARKERS -->
          <g id="layer-evac" class="map-layer-group">
            <g transform="translate(345, 140)" class="evac-marker" onclick="inspectEvac(1)" style="cursor:pointer;">
              <rect x="-8" y="-8" width="16" height="16" rx="4" fill="#17324D" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="4" font-size="9" font-weight="bold" fill="#FFF" text-anchor="middle">E1</text>
              <circle cx="6" cy="-6" r="3" fill="#C62828" stroke="#FFF"/>
            </g>

            <g transform="translate(305, 185)" class="evac-marker" onclick="inspectEvac(2)" style="cursor:pointer;">
              <rect x="-7" y="-7" width="14" height="14" rx="4" fill="#2F6F73" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="3.5" font-size="8" font-weight="bold" fill="#FFF" text-anchor="middle">E2</text>
            </g>

            <g transform="translate(295, 345)" class="evac-marker" onclick="inspectEvac(3)" style="cursor:pointer;">
              <rect x="-8" y="-8" width="16" height="16" rx="4" fill="#17324D" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="4" font-size="9" font-weight="bold" fill="#FFF" text-anchor="middle">E3</text>
              <circle cx="6" cy="-6" r="3" fill="#C62828" stroke="#FFF"/>
            </g>

            <g transform="translate(280, 240)" class="evac-marker" onclick="inspectEvac(4)" style="cursor:pointer;">
              <rect x="-7" y="-7" width="14" height="14" rx="4" fill="#2F6F73" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="3.5" font-size="8" font-weight="bold" fill="#FFF" text-anchor="middle">E4</text>
            </g>

            <g transform="translate(390, 235)" class="evac-marker" onclick="inspectEvac(5)" style="cursor:pointer;">
              <rect x="-7" y="-7" width="14" height="14" rx="4" fill="#2F6F73" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="3.5" font-size="8" font-weight="bold" fill="#FFF" text-anchor="middle">E5</text>
            </g>
          </g>
        </svg>

        <!-- Map Floating Compact Legend (Bottom Left) -->
        <div style="position:absolute;bottom:10px;left:10px;background:rgba(255,255,255,0.94);border:1px solid var(--color-border);border-radius:8px;padding:6px 10px;font-size:9px;box-shadow:var(--shadow-subtle);">
          <div style="font-weight:700;color:var(--color-primary);margin-bottom:3px;font-size:8px;text-transform:uppercase;">Semantic Legend:</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <span style="display:inline-flex;align-items:center;gap:3px;"><span style="width:8px;height:8px;background:#C62828;border-radius:2px;"></span> Critical</span>
            <span style="display:inline-flex;align-items:center;gap:3px;"><span style="width:8px;height:8px;background:#B78103;border-radius:2px;"></span> High</span>
            <span style="display:inline-flex;align-items:center;gap:3px;"><span style="width:8px;height:8px;background:#F59E0B;border-radius:2px;"></span> Moderate</span>
            <span style="display:inline-flex;align-items:center;gap:3px;"><span style="width:8px;height:8px;background:#2E7D32;border-radius:2px;"></span> Low</span>
            <span style="display:inline-flex;align-items:center;gap:3px;"><span style="width:8px;height:8px;background:#17324D;border-radius:2px;"></span> Evacuation Camp</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Location Inspector Panel -->
    <div class="card" style="margin-bottom:0;" id="mapInspectorCard">
      <div class="card-header">
        <h3 class="card-title" id="inspectorTitle">Barangay Hinaplanon</h3>
        <span class="badge badge-danger" id="inspectorRiskBadge">Critical Risk</span>
      </div>
      <div class="card-body" id="inspectorBody">
        <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:12px;">
          Click on any barangay territory, evacuation marker, or incident icon on the map to inspect spatial risk factors.
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
          <div style="background:var(--color-surface-subtle);padding:6px 8px;border-radius:6px;">
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Population</div>
            <div style="font-size:12px;font-weight:700;color:var(--color-primary);" id="inspectorPop">21,450</div>
          </div>
          <div style="background:var(--color-surface-subtle);padding:6px 8px;border-radius:6px;">
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Households</div>
            <div style="font-size:12px;font-weight:700;color:var(--color-primary);" id="inspectorHouseholds">4,820</div>
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <div style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:4px;">Hazard Vulnerabilities</div>
          <div style="font-size:10px;color:var(--color-danger);font-weight:600;" id="inspectorHazards">
            Mandulog River Flash Flood, Riverbank Overtopping, Coastal Squall
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <div style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:4px;">High-Risk Puroks</div>
          <ul style="font-size:10px;color:var(--color-text-secondary);padding-left:14px;line-height:1.4;" id="inspectorPuroks">
            <li><strong>Purok Riverside 1</strong> (Flash Flood, Critical)</li>
            <li><strong>Purok Riverside 2</strong> (River Overflow, Critical)</li>
            <li><strong>Purok San Miguel</strong> (Urban Ponding, High)</li>
          </ul>
        </div>

        <div style="margin-bottom:12px;">
          <div style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:4px;">Designated Evacuation Shelters</div>
          <div style="font-size:10px;color:var(--color-primary);" id="inspectorEvac">
            • Hinaplanon NHS Gym (Capacity: 1,200 | Open / Active)<br>
            • Brgy. Multi-Purpose Court (Capacity: 800 | Standby)
          </div>
        </div>

        <div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--color-border-light);" id="inspectorActions">
          <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php?barangay=1" class="btn btn-outline btn-sm" style="width:100%;">
            View Barangay Requests
          </a>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const BARANGAY_DATA = <?= json_encode($barangays) ?>;
const PUROK_DATA = <?= json_encode($puroks) ?>;
const EVAC_DATA = <?= json_encode($evacCenters) ?>;

function selectBarangayOnMap(id) {
  const brgy = BARANGAY_DATA.find(b => b.id == id);
  if (!brgy) return;

  document.getElementById('mapBarangayFocus').value = id;
  document.getElementById('inspectorTitle').innerText = `Barangay ${brgy.name}`;
  
  const badge = document.getElementById('inspectorRiskBadge');
  badge.className = `badge badge-${brgy.risk_level === 'Critical' ? 'danger' : (brgy.risk_level === 'High' ? 'warning' : (brgy.risk_level === 'Moderate' ? 'warning' : 'success'))}`;
  badge.innerText = `${brgy.risk_level} Risk`;

  document.getElementById('inspectorPop').innerText = parseInt(brgy.population).toLocaleString();
  document.getElementById('inspectorHouseholds').innerText = parseInt(brgy.total_households).toLocaleString();

  // Filter puroks
  const pList = PUROK_DATA.filter(p => p.barangay_id == id);
  const purokUl = document.getElementById('inspectorPuroks');
  if (pList.length > 0) {
    purokUl.innerHTML = pList.map(p => `<li><strong>${escapeHtml(p.name)}</strong> (${escapeHtml(p.hazard_types)}, ${p.risk_level})</li>`).join('');
  } else {
    purokUl.innerHTML = '<li>Standard residential clusters.</li>';
  }

  // Filter evacuation centers
  const eList = EVAC_DATA.filter(e => e.barangay_id == id);
  const evacDiv = document.getElementById('inspectorEvac');
  if (eList.length > 0) {
    evacDiv.innerHTML = eList.map(e => `• ${escapeHtml(e.name)} (Capacity: ${e.capacity_individuals.toLocaleString()} | ${escapeHtml(e.status)})`).join('<br>');
  } else {
    evacDiv.innerHTML = 'No dedicated shelter registered.';
  }

  document.getElementById('inspectorActions').innerHTML = `
    <a href="${BASE_URL}/views/icdrrmo/disaster-requests.php?barangay=${id}" class="btn btn-outline btn-sm" style="width:100%;">
      View Requests for Brgy. ${escapeHtml(brgy.name)}
    </a>
  `;

  // Highlight polygon
  document.querySelectorAll('.brgy-poly').forEach(p => p.style.strokeWidth = '1.5');
  const targetPoly = document.getElementById(`poly-brgy-${id}`);
  if (targetPoly) targetPoly.style.strokeWidth = '3.5';
}

function focusBarangay(val) {
  if (val === 'all') {
    document.querySelectorAll('.brgy-poly').forEach(p => p.style.strokeWidth = '1.5');
  } else {
    selectBarangayOnMap(val);
  }
}

function toggleMapLayer(layerName, btn) {
  document.querySelectorAll('.layer-toggle-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const incidents = document.getElementById('layer-incidents');
  const evac = document.getElementById('layer-evac');
  const hazardPolys = document.querySelectorAll('.hazard-zone-poly');

  if (layerName === 'all') {
    incidents.style.display = 'block';
    evac.style.display = 'block';
    hazardPolys.forEach(p => p.style.opacity = '1');
  } else if (layerName === 'hazards') {
    incidents.style.display = 'none';
    evac.style.display = 'none';
    hazardPolys.forEach(p => p.style.opacity = '1');
  } else if (layerName === 'evac') {
    incidents.style.display = 'none';
    evac.style.display = 'block';
    hazardPolys.forEach(p => p.style.opacity = '0.35');
  } else if (layerName === 'incidents') {
    incidents.style.display = 'block';
    evac.style.display = 'none';
    hazardPolys.forEach(p => p.style.opacity = '0.35');
  }
}

function inspectIncident(requestId) {
  showToast(`Active Incident selected: Request #REQ-2026-00${requestId}`, 'info');
  selectBarangayOnMap(requestId === 1 ? 1 : 2);
}

function inspectEvac(evacId) {
  const evac = EVAC_DATA.find(e => e.id == evacId);
  if (evac) {
    showToast(`Evacuation Center: ${evac.name} (${evac.status})`, 'info');
    selectBarangayOnMap(evac.barangay_id);
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
