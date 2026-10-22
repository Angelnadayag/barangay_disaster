<?php
// ============================================================================
// Views (Responder): Completed Disaster Reports
// Dedicated archive and detail log for concluded disaster operations
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$responderBarangayId = (int)($user['barangay_id'] ?? 0);
$db = getDBConnection();

if ($responderBarangayId === 0) {
    $bStmt = $db->prepare("SELECT barangay_id FROM users WHERE id = ?");
    $bStmt->execute([(int)$user['id']]);
    $responderBarangayId = (int)$bStmt->fetchColumn();
}

// Current status of responder
$statStmt = $db->prepare("SELECT * FROM responder_field_updates WHERE responder_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
$statStmt->execute([$user['id']]);
$currentStatus = $statStmt->fetch();
$activeStatus = $currentStatus['operational_status'] ?? 'Available';
$activeIncidentId = (int)($currentStatus['disaster_request_id'] ?? 0);
$isOnDuty = in_array($activeStatus, ['On Duty', 'Responding'], true) && ($activeIncidentId > 0);

$responderBrgyName = $user['barangay_name'] ?? '';
if (empty($responderBrgyName) && $responderBarangayId > 0) {
    $bNameStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
    $bNameStmt->execute([$responderBarangayId]);
    $responderBrgyName = $bNameStmt->fetchColumn() ?: '';
}

$filterSeverity = $_GET['severity'] ?? '';
$search = trim($_GET['search'] ?? '');

// Strictly Completed Disaster Reports for this Barangay
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name
    FROM disaster_reports dr
    JOIN barangays b ON dr.barangay_id = b.id
    LEFT JOIN users u ON dr.submitted_by = u.id
    WHERE dr.status = 'Completed'
      AND dr.barangay_id = ?
";
$params = [$responderBarangayId];

if (!empty($filterSeverity)) {
    $sql .= " AND dr.severity = ?";
    $params[] = $filterSeverity;
}

if (!empty($search)) {
    $sql .= " AND (dr.tracking_code LIKE ? OR dr.purok_name LIKE ? OR dr.incident_location LIKE ? OR dr.disaster_type LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY COALESCE(dr.completed_at, dr.updated_at, dr.created_at) DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Completed Disaster Reports";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
@media (max-width: 640px) {
  .impact-grid-container {
    grid-template-columns: repeat(3, 1fr) !important;
  }
  .aid-grid-container {
    grid-template-columns: 1fr !important;
  }
}
</style>

<!-- Responder Profile & Duty Status Header -->
<div class="card" style="margin-bottom: 14px; border: none; border-radius: 10px; box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05); background: #FFFFFF;">
  <div style="padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
    <!-- Left: Profile Avatar + Name + Sub Details -->
    <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
      <div style="width: 40px; height: 40px; border-radius: 50%; background: #EEF2F4; color: #17324D; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?php if (!empty($user['avatar_url'])): ?>
          <img src="<?= clean($user['avatar_url']) ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
        <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
        <?php endif; ?>
      </div>
      <div style="min-width: 0;">
        <div style="font-size: 13.5px; font-weight: 700; color: #17324D; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
          <?= clean($user['full_name']) ?>
        </div>
        <div style="font-size: 10.5px; color: #8A96A0; font-weight: 500; margin-top: 1px;">
          BDRRMC responders
        </div>
      </div>
    </div>

    <!-- Right: Non-clickable Status Box (Available or On Duty) -->
    <div style="flex-shrink: 0;">
      <div id="topDutyStatusBox" style="padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; text-align: center; user-select: none; pointer-events: none; border: none; <?= $isOnDuty ? 'background: #EBF3F5; color: #2F6F73;' : 'background: #EEF2F4; color: #17324D;' ?>">
        <?= $isOnDuty ? 'On Duty' : 'Available' ?>
      </div>
    </div>
  </div>
</div>

<!-- Search & Severity Filter Bar -->
<div class="card" style="margin-bottom: var(--space-4); border: none; border-radius: 10px; box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05); background: #FFFFFF;">
  <div class="card-body" style="padding: 10px 14px;">
    <form method="GET" class="filter-bar" style="margin-bottom: 0;">
      <div class="filter-group">
        <div class="search-input-wrap">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="search" class="form-control" placeholder="Search tracking #, purok, or location..." value="<?= clean($search) ?>">
        </div>

        <select name="severity" class="form-control" style="width: 140px;">
          <option value="">All Severities</option>
          <option value="Critical" <?= $filterSeverity === 'Critical' ? 'selected' : '' ?>>Critical</option>
          <option value="High" <?= $filterSeverity === 'High' ? 'selected' : '' ?>>High</option>
          <option value="Moderate" <?= $filterSeverity === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
          <option value="Low" <?= $filterSeverity === 'Low' ? 'selected' : '' ?>>Low</option>
        </select>

        <button type="submit" class="btn btn-outline" style="border: none; background: #EEF2F4; color: #17324D; font-weight: 600;">Filter</button>
        <?php if (!empty($search) || !empty($filterSeverity)): ?>
          <a href="<?= BASE_URL ?>/views/responder/active-incidents.php" class="btn btn-outline" style="border: none; background: transparent; color: #8A96A0;">Reset</a>
        <?php endif; ?>
      </div>
      <div>
        <span style="font-size: 11px; font-weight: 600; color: #2F6F73;">
          Completed Reports: <?= count($incidents) ?>
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Completed Incident Reports List -->
<div style="display: flex; flex-direction: column; gap: 16px;">
  <?php if (empty($incidents)): ?>
    <div class="card" style="border: none; border-radius: 10px; box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05); background: #FFFFFF;">
      <div class="empty-state" style="padding: 40px 20px; text-align: center;">
        <div style="width: 50px; height: 50px; border-radius: 50%; background: #EEF2F4; color: #2F6F73; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
        </div>
        <div class="empty-state-title" style="font-size: 15px; font-weight: 700; color: #17324D; margin-bottom: 4px;">No Completed Disaster Reports</div>
        <div class="empty-state-desc" style="font-size: 12px; color: #8A96A0; max-width: 440px; margin: 0 auto;">There are currently no completed disaster reports for Barangay <?= clean($responderBrgyName ?: 'your assigned barangay') ?>. Incident reports marked completed will appear here with full logs and location maps.</div>
      </div>
    </div>
  <?php else: ?>
    <?php 
    $mapDataList = [];
    foreach ($incidents as $inc): 
      $lat = !empty($inc["coordinates_lat"]) ? (float)$inc["coordinates_lat"] : 8.247041;
      $lng = !empty($inc["coordinates_lng"]) ? (float)$inc["coordinates_lng"] : 124.261594;
      $mapDataList[] = [
        "id" => (int)$inc["id"],
        "tracking_code" => $inc["tracking_code"],
        "disaster_type" => $inc["disaster_type"] ?: 'Incident Report',
        "purok_name" => $inc["purok_name"],
        "barangay_name" => $inc["barangay_name"],
        "location" => $inc["incident_location"] ?: '',
        "lat" => $lat,
        "lng" => $lng,
        "completed_at" => formatDate($inc['completed_at'] ?: $inc['updated_at'], 'M d, Y • h:i A')
      ];
    ?>
      <!-- Completed Incident Report Card Box -->
      <div class="card completed-report-card" style="border: none; border-radius: 10px; box-shadow: 0 1px 4px rgba(16, 35, 54, 0.06); background: #FFFFFF; overflow: hidden; margin-bottom: 0;">
        
        <!-- Header: Tracking Code, Date, Badges -->
        <div style="padding: 14px 18px 10px; border-bottom: 1px solid #EEF2F4;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
            <div>
              <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                <span style="font-size: 11px; font-family: monospace; font-weight: 700; color: #17324D; background: #EEF2F4; padding: 2px 7px; border-radius: 4px;">
                  <?= clean($inc['tracking_code']) ?>
                </span>
                <span style="font-size: 11px; color: #8A96A0;">
                  Reported: <?= formatDate($inc['created_at'], 'M d, Y • h:i A') ?>
                </span>
                <?php if (!empty($inc['completed_at'])): ?>
                  <span style="font-size: 11px; color: #2F6F73; font-weight: 500;">
                    • Concluded: <?= formatDate($inc['completed_at'], 'M d, Y • h:i A') ?>
                  </span>
                <?php endif; ?>
              </div>

              <h2 style="font-size: 16px; font-weight: 700; color: #17324D; margin: 0 0 3px 0; line-height: 1.3;">
                <?= clean($inc['disaster_type'] ?: 'Disaster Incident') ?> — Barangay <?= clean($inc['barangay_name']) ?>
              </h2>
              <div style="font-size: 11.5px; color: #556270; display: flex; align-items: center; gap: 5px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2F6F73" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                <span><strong><?= clean($inc['purok_name']) ?></strong> <?= !empty($inc['incident_location']) ? '— ' . clean($inc['incident_location']) : '' ?></span>
              </div>
            </div>

            <!-- Badges -->
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
              <span style="font-size: 10.5px; font-weight: 700; padding: 4px 10px; border-radius: 6px; background: #EEF2F4; color: #17324D; border: none;">
                Completed
              </span>
              <span style="font-size: 10.5px; font-weight: 700; padding: 4px 10px; border-radius: 6px; background: #EBF3F5; color: #2F6F73; border: none;">
                <?= clean($inc['severity'] ?: 'Normal') ?> Severity
              </span>
              <?php if (!empty($inc['urgency'])): ?>
                <span style="font-size: 10.5px; font-weight: 600; padding: 4px 8px; border-radius: 6px; background: #F8FAFB; color: #8A96A0; border: none;">
                  <?= clean($inc['urgency']) ?> Urgency
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div style="padding: 14px 18px;">

          <!-- Leaflet Location Mapping Tool Section -->
          <div style="margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
              <div style="display: flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2F6F73" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span style="font-size: 11.5px; font-weight: 700; color: #17324D;">Incident Location Mapping Tool (Leaflet)</span>
              </div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 10.5px; font-family: monospace; color: #8A96A0;">
                  <?= number_format($lat, 6) ?>, <?= number_format($lng, 6) ?>
                </span>
                <button type="button" 
                        class="btn-recenter-map"
                        onclick="recenterIncidentMap(<?= (int)$inc['id'] ?>, <?= $lat ?>, <?= $lng ?>)"
                        style="border: none; background: #EEF2F4; color: #17324D; padding: 3px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; cursor: pointer;"
                        title="Recenter on Incident Pin">
                  Recenter Pin
                </button>
              </div>
            </div>

            <!-- Leaflet Map Container -->
            <div id="incidentMap_<?= (int)$inc['id'] ?>" 
                 style="width: 100%; height: 210px; border-radius: 8px; background: #EEF2F4; position: relative; z-index: 1;">
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px; font-size: 10px; color: #8A96A0;">
              <span>Showing georeferenced incident coordinates and 80m perimeter zone</span>
              <a href="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>" target="_blank" rel="noopener noreferrer" style="color: #2F6F73; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;">
                External Map View
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
              </a>
            </div>
          </div>

          <!-- Impact Metrics Grid -->
          <div style="margin-bottom: 14px;">
            <div style="font-size: 11px; font-weight: 700; color: #17324D; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
              Impact & Casualty Assessment
            </div>
            <div class="impact-grid-container" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; text-align: center;">
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Affected Fam.</div>
                <div style="font-size: 13px; font-weight: 700; color: #17324D;"><?= number_format($inc['affected_families']) ?></div>
              </div>
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Affected Indiv.</div>
                <div style="font-size: 13px; font-weight: 700; color: #17324D;"><?= number_format($inc['affected_individuals']) ?></div>
              </div>
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Displaced Fam.</div>
                <div style="font-size: 13px; font-weight: 700; color: #2F6F73;"><?= number_format($inc['displaced_families']) ?></div>
              </div>
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Casualties</div>
                <div style="font-size: 13px; font-weight: 700; color: #17324D;"><?= number_format($inc['casualties_count']) ?></div>
              </div>
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Injuries</div>
                <div style="font-size: 13px; font-weight: 700; color: #17324D;"><?= number_format($inc['injuries_count']) ?></div>
              </div>
              <div style="background: #F8FAFB; padding: 8px 6px; border-radius: 6px;">
                <div style="font-size: 9px; color: #8A96A0; margin-bottom: 2px;">Missing</div>
                <div style="font-size: 13px; font-weight: 700; color: #17324D;"><?= number_format($inc['missing_count']) ?></div>
              </div>
            </div>
          </div>

          <!-- Assistance & Relief Allocation -->
          <div class="aid-grid-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
            <div style="background: #F8FAFB; padding: 10px 12px; border-radius: 6px;">
              <div style="font-size: 10px; font-weight: 700; color: #8A96A0; text-transform: uppercase; margin-bottom: 3px;">
                Aid Support Category
              </div>
              <div style="font-size: 12px; font-weight: 600; color: #17324D;">
                <?= clean(ucfirst(str_replace('_', ' ', $inc['aid_support'] ?: 'Standard Relief & Rescue'))) ?>
              </div>
            </div>
            <div style="background: #F8FAFB; padding: 10px 12px; border-radius: 6px;">
              <div style="font-size: 10px; font-weight: 700; color: #8A96A0; text-transform: uppercase; margin-bottom: 3px;">
                Requested Assistance
              </div>
              <div style="font-size: 12px; color: #17324D; line-height: 1.35;">
                <?= clean($inc['requested_assistance'] ?: 'No specific aid package indicated.') ?>
              </div>
            </div>
          </div>

          <!-- Situation Narrative & Resolution Summary -->
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <div style="background: #F8FAFB; padding: 10px 12px; border-radius: 6px;">
              <div style="font-size: 10px; font-weight: 700; color: #8A96A0; text-transform: uppercase; margin-bottom: 3px;">
                Initial Situation Overview
              </div>
              <div style="font-size: 11.5px; color: #17324D; line-height: 1.45;">
                <?= nl2br(clean($inc['situation_overview'] ?: 'Initial alert dispatched with no detailed written situation narrative.')) ?>
              </div>
            </div>

            <div style="background: #EBF3F5; padding: 10px 12px; border-radius: 6px;">
              <div style="font-size: 10px; font-weight: 700; color: #2F6F73; text-transform: uppercase; margin-bottom: 3px;">
                Final Resolution Summary & Concluding Actions
              </div>
              <div style="font-size: 11.5px; color: #17324D; line-height: 1.45;">
                <?= nl2br(clean($inc['resolution_notes'] ?: 'Incident completed. All response operations concluded and area verified secure by barangay disaster authority.')) ?>
              </div>
            </div>
          </div>

          <!-- Submitter Footer -->
          <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #EEF2F4; display: flex; justify-content: space-between; align-items: center; font-size: 10.5px; color: #8A96A0;">
            <div>
              Submitted By: <strong style="color: #17324D;"><?= clean($inc['submitted_by_name'] ?: 'Barangay Staff') ?></strong>
            </div>
            <div>
              Status: <span style="font-weight: 600; color: #2F6F73;">Archived & Resolved</span>
            </div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
window.incidentMapInstances = {};

function recenterIncidentMap(incidentId, lat, lng) {
  const map = window.incidentMapInstances[incidentId];
  if (map) {
    map.setView([lat, lng], 16, { animate: true });
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const mapData = <?= json_encode($mapDataList ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  
  if (Array.isArray(mapData)) {
    mapData.forEach(function(item) {
      const containerId = 'incidentMap_' + item.id;
      const el = document.getElementById(containerId);
      if (!el) return;

      const lat = item.lat;
      const lng = item.lng;

      try {
        const map = L.map(containerId, {
          center: [lat, lng],
          zoom: 16,
          zoomControl: true,
          scrollWheelZoom: false
        });

        window.incidentMapInstances[item.id] = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Custom Marker Pin
        const pinIcon = L.divIcon({
          className: 'custom-incident-pin',
          html: `<div style="width:28px;height:28px;border-radius:50%;background:#17324D;color:#FFFFFF;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(16,35,54,0.35);border:2px solid #FFFFFF;">
                   <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                 </div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 28],
          popupAnchor: [0, -28]
        });

        const marker = L.marker([lat, lng], { icon: pinIcon }).addTo(map);
        marker.bindPopup(`
          <div style="font-family: inherit; font-size: 11.5px; line-height: 1.4; color: #17324D; min-width: 170px;">
            <div style="font-weight: 700; margin-bottom: 2px;">${item.tracking_code}</div>
            <div style="color: #2F6F73; font-weight: 600;">${item.disaster_type}</div>
            <div style="color: #556270; font-size: 10.5px; margin-top: 2px;">${item.purok_name}, Brgy. ${item.barangay_name}</div>
            ${item.location ? `<div style="color: #8A96A0; font-size: 10px; margin-top: 1px;">${item.location}</div>` : ''}
            <div style="margin-top: 4px; font-size: 9.5px; color: #2F6F73; font-weight: 700;">Status: Completed</div>
          </div>
        `);

        // Incident Impact Perimeter Circle (80m)
        L.circle([lat, lng], {
          radius: 80,
          color: '#2F6F73',
          fillColor: '#2F6F73',
          fillOpacity: 0.12,
          weight: 1.5
        }).addTo(map);

        setTimeout(function() {
          map.invalidateSize();
        }, 300);
      } catch (err) {
        console.warn('Leaflet initialization error on card #' + item.id, err);
      }
    });
  }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
