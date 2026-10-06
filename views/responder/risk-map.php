<?php
// ============================================================================
// Views (Responder): Tactical Operational & Hazard Map
// Field-level GIS tracking incident locations, safe access corridors, and evacuation centers
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$incidents = $db->query("
    SELECT dr.*, dr.disaster_type AS calamity_type, dr.situation_overview AS details,
           b.name AS barangay_name, b.coordinates_lat AS latitude, b.coordinates_lng AS longitude, b.risk_level AS brgy_risk
    FROM disaster_requests dr
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.status IN ('Under Review', 'Verified', 'Recommended', 'Allocated', 'Dispatched')
    ORDER BY dr.urgency = 'Immediate' DESC, dr.created_at DESC
")->fetchAll();

$evacAreas = $db->query("
    SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
           ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude,
           ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
           b.name AS barangay_name 
    FROM evacuation_areas ea
    JOIN barangays b ON ea.barangay_id = b.id
    WHERE ea.status IN ('Open / Active', 'Standby', 'Open')
    ORDER BY ea.current_evacuees_count DESC
")->fetchAll();

$barangays = $db->query("SELECT id, name, risk_level, area_radius, coordinates_lat AS latitude, coordinates_lng AS longitude FROM barangays ORDER BY name ASC")->fetchAll();

$pageTitle = "Tactical Hazard Map — Field Ops";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Tactical Operations & Hazard Map</h1>
      <p class="page-header-desc">
        Field situational overview showing active deployment zones, high-risk riverine corridors, and open evacuation staging areas.
      </p>
    </div>
    <div class="page-header-actions">
      <span class="badge badge-danger" style="display:inline-flex;align-items:center;gap:6px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;animation:pulse 2s infinite;"></span>
        <?= count($incidents) ?> Active Incident Sectors
      </span>
      <button class="btn btn-secondary btn-sm" onclick="locateResponder()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
        Center My Unit
      </button>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 360px;gap:var(--space-4);align-items:start;">
    <!-- Map Canvas Card -->
    <div class="card" style="margin-bottom:0;overflow:hidden;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:var(--color-surface-subtle);padding:10px 14px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
          <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--color-primary);letter-spacing:0.04em;">Iligan City Sector GIS Matrix</span>
        </div>
        <div style="display:flex;gap:12px;font-size:11px;">
          <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;background:#ef4444;border-radius:50%;"></span> Incident</span>
          <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;background:#10b981;border-radius:2px;"></span> Safe Center</span>
          <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;background:#3b82f6;border-radius:50%;"></span> Responder</span>
        </div>
      </div>
      <div id="responder-map" style="height:620px;width:100%;background:#e2e8f0;"></div>
    </div>

    <!-- Incident & Safe Route List -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Incident Queue -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title" style="font-size:13px;">Active Incident Dispatch</h2>
          <span class="badge badge-primary"><?= count($incidents) ?></span>
        </div>
        <div class="card-body" style="padding:0;max-height:300px;overflow-y:auto;">
          <?php if (empty($incidents)): ?>
            <div style="padding:24px;text-align:center;color:var(--color-text-muted);font-size:12px;">
              No active operational dispatches reported.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($incidents as $inc): ?>
                <div style="padding:12px 14px;border-bottom:1px solid var(--color-border);cursor:pointer;transition:background 0.15s;" 
                     onclick="focusMap(<?= (float)$inc['latitude'] ?>, <?= (float)$inc['longitude'] ?>, '<?= clean($inc['barangay_name']) ?> — <?= clean($inc['calamity_type']) ?>')"
                     onmouseover="this.style.background='var(--color-surface-subtle)'"
                     onmouseout="this.style.background='transparent'">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <strong style="font-size:12px;color:var(--color-text);">Brgy. <?= clean($inc['barangay_name']) ?></strong>
                    <?= renderStatusBadge($inc['urgency']) ?>
                  </div>
                  <div style="font-size:11px;color:var(--color-primary);font-weight:600;">
                    <?= clean($inc['calamity_type']) ?>
                  </div>
                  <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                    <?= clean($inc['details'] ?: 'No notes provided') ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Safe Staging / Evacuation Centers -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title" style="font-size:13px;">Safe Staging & Evac Centers</h2>
          <span class="badge badge-success"><?= count($evacAreas) ?> Open</span>
        </div>
        <div class="card-body" style="padding:0;max-height:280px;overflow-y:auto;">
          <?php foreach ($evacAreas as $ea): 
            $pct = $ea['capacity'] > 0 ? round(($ea['current_evacuees'] / $ea['capacity']) * 100) : 0;
          ?>
            <div style="padding:10px 14px;border-bottom:1px solid var(--color-border);font-size:11px;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:var(--color-text);"><?= clean($ea['name']) ?></strong>
                <span class="badge badge-<?= $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success') ?>">
                  <?= $pct ?>% Full
                </span>
              </div>
              <div style="color:var(--color-text-muted);margin-top:2px;">
                Brgy. <?= clean($ea['barangay_name']) ?> &bull; <?= number_format($ea['current_evacuees']) ?> / <?= number_format($ea['capacity']) ?> pax
              </div>
              <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">
                <?= $ea['has_power'] ? '⚡ Power OK' : '⚠️ No Power' ?> &bull; <?= $ea['has_water'] ? '💧 Water OK' : '⚠️ No Water' ?> &bull; <?= $ea['has_medical'] ? '🩺 Med Station' : 'No Meds' ?>
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
let responderMarker;

document.addEventListener('DOMContentLoaded', function() {
  map = L.map('responder-map').setView([8.2280, 124.2452], 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // Barangay centers
  const barangays = <?= json_encode($barangays) ?>;
  barangays.forEach(b => {
    if (b.latitude && b.longitude) {
      const color = b.risk_level === 'Critical' ? '#ef4444' : (b.risk_level === 'High' ? '#f59e0b' : '#10b981');
      const radius = b.area_radius ? parseFloat(b.area_radius) : 500;
      L.circle([parseFloat(b.latitude), parseFloat(b.longitude)], {
        color: color,
        fillColor: color,
        fillOpacity: 0.15,
        radius: radius
      }).bindTooltip(`<strong>Brgy. ${b.name}</strong><br>Area Radius: ${Number(radius).toLocaleString()}m<br>Risk: ${b.risk_level}`, {sticky: true}).addTo(map);
    }
  });

  // Incidents
  const incidents = <?= json_encode($incidents) ?>;
  incidents.forEach(inc => {
    if (inc.latitude && inc.longitude) {
      const marker = L.circleMarker([parseFloat(inc.latitude), parseFloat(inc.longitude)], {
        radius: 9,
        fillColor: '#ef4444',
        color: '#ffffff',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.95
      }).addTo(map);
      marker.bindPopup(`
        <div style="font-family:var(--font-primary, 'Poppins', sans-serif);font-size:12px;line-height:1.4;">
          <strong style="color:var(--color-danger, #b91c1c);">ALERT: ${inc.calamity_type}</strong><br>
          <b>Location:</b> Brgy. ${inc.barangay_name}<br>
          <b>Urgency:</b> ${inc.urgency}<br>
          <b>Reported:</b> ${inc.created_at}<br>
          <p style="margin:4px 0 0 0;font-size:11px;color:var(--color-text-secondary, #475569);">${inc.details || ''}</p>
        </div>
      `);
    }
  });

  // Evacuation areas
  const evacs = <?= json_encode($evacAreas) ?>;
  evacs.forEach(ea => {
    if (ea.latitude && ea.longitude) {
      const marker = L.marker([parseFloat(ea.latitude), parseFloat(ea.longitude)]).addTo(map);
      marker.bindPopup(`
        <div style="font-family:var(--font-primary, 'Poppins', sans-serif);font-size:12px;line-height:1.4;">
          <strong style="color:var(--color-success, #047857);">SAFE ZONE: ${ea.name}</strong><br>
          <b>Barangay:</b> ${ea.barangay_name}<br>
          <b>Evacuees:</b> ${ea.current_evacuees} / ${ea.capacity}<br>
          <b>Status:</b> ${ea.status}
        </div>
      `);
    }
  });

  // Add default responder unit pin
  responderMarker = L.circleMarker([8.2260, 124.2415], {
    radius: 11,
    fillColor: '#3b82f6',
    color: '#ffffff',
    weight: 3,
    fillOpacity: 1
  }).addTo(map).bindPopup("<strong>My Unit (Rescue Alpha-1)</strong><br>Status: Standby / En Route");
});

function focusMap(lat, lng, title) {
  if (map && lat && lng) {
    map.flyTo([lat, lng], 15, { duration: 1.2 });
  }
}

function locateResponder() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        if (responderMarker) responderMarker.setLatLng([lat, lng]);
        map.flyTo([lat, lng], 16);
      },
      () => {
        // Fallback to unit's predefined location
        map.flyTo([8.2260, 124.2415], 15);
      }
    );
  } else {
    map.flyTo([8.2260, 124.2415], 15);
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
