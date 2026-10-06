<?php
// ============================================================================
// Views (Resident): Community Hazard & Safe Evacuation Map
// Public interactive GIS mapping flood inundation risks, safe zones, and nearest shelters
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$bStmt = $db->prepare("SELECT id, name, risk_level, coordinates_lat AS latitude, coordinates_lng AS longitude FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

$allBarangays = $db->query("SELECT id, name, risk_level, area_radius, coordinates_lat AS latitude, coordinates_lng AS longitude FROM barangays ORDER BY name ASC")->fetchAll();

$evacStmt = $db->prepare("
    SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
           ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude,
           ea.has_electricity AS has_power, ea.has_potable_water AS has_water,
           b.name AS barangay_name 
    FROM evacuation_areas ea
    JOIN barangays b ON ea.barangay_id = b.id
    WHERE ea.status IN ('Open / Active', 'Standby', 'Open')
");
$evacStmt->execute();
$evacCenters = $evacStmt->fetchAll();

$puroksStmt = $db->prepare("SELECT * FROM puroks WHERE barangay_id = ? ORDER BY risk_level DESC");
$puroksStmt->execute([$barangayId]);
$puroks = $puroksStmt->fetchAll();

$pageTitle = "Community Hazard & Shelter Map";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Community Hazard & Evacuation Map</h1>
      <p class="page-header-desc">
        Explore safe evacuation routes, avoid low-lying flood inundation areas, and find nearest active municipal shelters.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary btn-sm" onclick="findNearestShelter()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
        Locate Nearest Shelter
      </button>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-4);align-items:start;">
    <!-- Map Container -->
    <div class="card" style="margin-bottom:0;overflow:hidden;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:var(--color-surface-subtle);padding:10px 14px;">
        <span style="font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">
          Barangay <?= clean($barangay['name'] ?? 'Local') ?> Public Safety Matrix
        </span>
        <div style="display:flex;gap:12px;font-size:11px;">
          <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;background:#ef4444;border-radius:2px;"></span> Flood Risk Zone</span>
          <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;background:#10b981;border-radius:50%;"></span> Safe Shelter</span>
        </div>
      </div>
      <div id="resident-map" style="height:600px;width:100%;background:#e2e8f0;"></div>
    </div>

    <!-- Right Sidebar: Local Puroks & Evac List -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Local Purok Hazard Levels -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title" style="font-size:13px;">Local Purok Hazard Status</h2>
        </div>
        <div class="card-body" style="padding:0;max-height:280px;overflow-y:auto;">
          <?php if (empty($puroks)): ?>
            <div style="padding:20px;text-align:center;color:var(--color-text-muted);font-size:12px;">
              No registered purok subdivisions.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($puroks as $p): ?>
                <div style="padding:10px 14px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
                  <div>
                    <strong style="font-size:12px;color:var(--color-text);"><?= clean($p['name']) ?></strong>
                    <div style="font-size:10px;color:var(--color-text-muted);"><?= number_format($p['population'] ?? 0) ?> residents</div>
                  </div>
                  <?= renderStatusBadge($p['risk_level']) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick Safe Centers List -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title" style="font-size:13px;">Active Shelters (Click to Focus)</h2>
        </div>
        <div class="card-body" style="padding:0;max-height:280px;overflow-y:auto;">
          <?php foreach ($evacCenters as $ec): 
            $pct = $ec['capacity'] > 0 ? round(($ec['current_evacuees'] / $ec['capacity']) * 100) : 0;
          ?>
            <div style="padding:10px 14px;border-bottom:1px solid var(--color-border);cursor:pointer;transition:background 0.15s;"
                 onclick="focusShelter(<?= (float)$ec['latitude'] ?>, <?= (float)$ec['longitude'] ?>, '<?= clean($ec['name']) ?>')"
                 onmouseover="this.style.background='var(--color-surface-subtle)'"
                 onmouseout="this.style.background='transparent'">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:12px;color:var(--color-primary);"><?= clean($ec['name']) ?></strong>
                <span class="badge badge-<?= $pct >= 90 ? 'danger' : 'success' ?>" style="font-size:10px;">
                  <?= $pct ?>% Full
                </span>
              </div>
              <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">
                Brgy. <?= clean($ec['barangay_name']) ?> &bull; Available: <?= max(0, $ec['capacity'] - $ec['current_evacuees']) ?> slots
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Leaflet Library -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
let map;
const evacData = <?= json_encode($evacCenters) ?>;
const currentLat = <?= (float)($barangay['latitude'] ?? 8.2280) ?>;
const currentLng = <?= (float)($barangay['longitude'] ?? 124.2452) ?>;

document.addEventListener('DOMContentLoaded', function() {
  map = L.map('resident-map').setView([currentLat, currentLng], 14);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // Barangay Hazard Zones
  const barangays = <?= json_encode($allBarangays) ?>;
  barangays.forEach(b => {
    if (b.latitude && b.longitude) {
      const color = b.risk_level === 'Critical' ? '#ef4444' : (b.risk_level === 'High' ? '#f59e0b' : '#10b981');
      const radius = b.area_radius ? parseFloat(b.area_radius) : 500;
      L.circle([parseFloat(b.latitude), parseFloat(b.longitude)], {
        color: color,
        fillColor: color,
        fillOpacity: 0.18,
        radius: radius
      }).bindTooltip(`<strong>Brgy. ${b.name}</strong><br>Area Radius: ${Number(radius).toLocaleString()}m<br>Risk: ${b.risk_level}`, {sticky: true}).addTo(map);
    }
  });

  // Safe Evacuation Centers
  evacData.forEach(ec => {
    if (ec.latitude && ec.longitude) {
      const marker = L.circleMarker([parseFloat(ec.latitude), parseFloat(ec.longitude)], {
        radius: 9,
        fillColor: '#10b981',
        color: '#ffffff',
        weight: 2,
        fillOpacity: 0.95
      }).addTo(map);

      marker.bindPopup(`
        <div style="font-family:var(--font-primary, 'Poppins', sans-serif);font-size:12px;line-height:1.4;">
          <strong style="color:var(--color-success, #047857);font-size:13px;">${ec.name}</strong><br>
          <b>Barangay:</b> ${ec.barangay_name}<br>
          <b>Current Occupancy:</b> ${ec.current_evacuees} / ${ec.capacity}<br>
          <b>Generator:</b> ${ec.has_power ? 'Yes' : 'No'} | <b>Potable Water:</b> ${ec.has_water ? 'Yes' : 'No'}<br>
          <div style="margin-top:6px;font-size:11px;color:var(--color-success, #047857);font-weight:bold;">SAFE EVACUATION SHELTER</div>
        </div>
      `);
    }
  });
});

function focusShelter(lat, lng, name) {
  if (map && lat && lng) {
    map.flyTo([lat, lng], 16, { duration: 1.2 });
  }
}

function findNearestShelter() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const uLat = pos.coords.latitude;
        const uLng = pos.coords.longitude;
        L.marker([uLat, uLng]).addTo(map).bindPopup("<strong>Your Location</strong>").openPopup();
        map.flyTo([uLat, uLng], 15);
      },
      () => {
        map.flyTo([currentLat, currentLng], 15);
      }
    );
  } else {
    map.flyTo([currentLat, currentLng], 15);
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
