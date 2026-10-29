<?php
// ============================================================================
// Views (Responder): Tactical Field Command Dashboard
// Clean, rapid mobile-accessible situational dashboard for field responders
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$responderBarangayId = (int) ($user['barangay_id'] ?? 0);
$db = getDBConnection();

if ($responderBarangayId === 0) {
  $bStmt = $db->prepare("SELECT barangay_id FROM users WHERE id = ?");
  $bStmt->execute([(int) $user['id']]);
  $responderBarangayId = (int) $bStmt->fetchColumn();
}

$responderBrgyName = $user['barangay_name'] ?? '';
if (empty($responderBrgyName) && $responderBarangayId > 0) {
  $bNameStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
  $bNameStmt->execute([$responderBarangayId]);
  $responderBrgyName = $bNameStmt->fetchColumn() ?: '';
}

// Latest status of current responder
$statStmt = $db->prepare("SELECT * FROM responder_field_updates WHERE responder_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
$statStmt->execute([$user['id']]);
$currentStatus = $statStmt->fetch();
$activeStatus = $currentStatus['operational_status'] ?? 'Available';
$activeIncidentId = (int) ($currentStatus['disaster_request_id'] ?? 0);
$isOnDuty = in_array($activeStatus, ['On Duty', 'Responding'], true) && ($activeIncidentId > 0);
$activeIncidentCode = '';
if ($activeIncidentId > 0) {
  $codeStmt = $db->prepare("SELECT tracking_code FROM disaster_reports WHERE id = ?");
  $codeStmt->execute([$activeIncidentId]);
  $activeIncidentCode = $codeStmt->fetchColumn() ?: '';
}

// Active Incidents (Strictly for assigned barangay)
$incStmt = $db->prepare("
    SELECT dr.*, b.name AS barangay_name 
    FROM disaster_reports dr 
    JOIN barangays b ON dr.barangay_id = b.id 
    WHERE (dr.status = 'Ongoing' OR dr.id = ? OR dr.status = 'Completed') AND dr.barangay_id = ?
    ORDER BY (dr.id = ?) DESC, FIELD(dr.status, 'Ongoing', 'Completed'), FIELD(dr.severity, 'Critical', 'High', 'Moderate', 'Low'), dr.created_at DESC
    LIMIT 6
");
$incStmt->execute([$activeIncidentId, $responderBarangayId, $activeIncidentId]);
$activeIncidents = $incStmt->fetchAll();

// Evacuation Centers Open
$evacStmt = $db->prepare("
    SELECT ea.*, b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id 
    WHERE ea.status IN ('Open / Active', 'Standby')
    ORDER BY (ea.barangay_id = ?) DESC, ea.current_evacuees_count DESC
    LIMIT 4
");
$evacStmt->execute([$responderBarangayId]);
$openCenters = $evacStmt->fetchAll();

// Fetch all evacuation areas for tactical routing on scroll-down (prioritizing assigned barangay)
$allEvacStmt = $db->prepare("
    SELECT ea.*, 
           ea.capacity_individuals AS capacity, 
           ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power,
           ea.has_potable_water AS has_water,
           ea.has_medical_station AS has_medical,
           ea.coordinates_lat AS latitude,
           ea.coordinates_lng AS longitude,
           b.name AS barangay_name,
           b.risk_level AS brgy_risk
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id 
    ORDER BY (ea.barangay_id = ?) DESC, FIELD(ea.status, 'Open / Active', 'Standby', 'At Capacity', 'Closed'), ea.name ASC
");
$allEvacStmt->execute([$responderBarangayId]);
$allEvacAreas = $allEvacStmt->fetchAll(PDO::FETCH_ASSOC);

$evacMarkersJson = [];
foreach ($allEvacAreas as $ea) {
  if (!empty($ea['latitude']) && !empty($ea['longitude'])) {
    $evacMarkersJson[] = [
      'id' => (int) $ea['id'],
      'brgy_id' => (int) $ea['barangay_id'],
      'name' => $ea['name'],
      'barangay' => $ea['barangay_name'],
      'lat' => (float) $ea['latitude'],
      'lng' => (float) $ea['longitude'],
      'status' => $ea['status'],
      'capacity' => (int) $ea['capacity'],
      'current' => (int) $ea['current_evacuees'],
      'accessibility' => $ea['accessibility'] ?? 'All Vehicles',
      'officer' => $ea['contact_officer'] ?? '',
      'phone' => $ea['contact_number'] ?? '',
      'address' => $ea['location_address'] ?? ''
    ];
  }
}

// Urgent Notifications (Strictly for disaster, strictly for assigned barangay)
$notifStmt = $db->prepare("
    SELECT n.* FROM notifications n
    WHERE n.alert_level IN ('urgent', 'warning', 'Critical')
      AND n.target_barangay_id = ?
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = ?)
      AND n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid', 'resources')
      AND n.title NOT LIKE '%Event%'
      AND n.title NOT LIKE '%Drill%'
      AND n.title NOT LIKE '%Training%'
    ORDER BY n.created_at DESC LIMIT 3
");
$notifStmt->execute([$responderBarangayId, (int) $user['id']]);
$urgentAlerts = $notifStmt->fetchAll();

$pageTitle = "Responder Dashboard";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies for Evacuation Routing -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>


<style>
  .btn-responder-action {
    width: 100%;
    justify-content: center;
    border: none;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(16, 35, 54, 0.05);
  }

  .btn-responder-respond {
    background: #2F6F73;
    color: #FFFFFF;
  }

  .btn-responder-respond:hover {
    background: #24585B;
  }

  .btn-responder-cancel {
    background: #EEF2F4;
    color: #17324D;
  }

  .btn-responder-cancel:hover {
    background: #E2E8EC;
  }

  .btn-responder-finish {
    background: #17324D;
    color: #FFFFFF;
  }

  .btn-responder-finish:hover {
    background: #0F2234;
  }

  .btn-responder-disabled {
    background: #EEF2F4;
    color: #8A96A0;
    cursor: not-allowed;
    opacity: 0.65;
  }
</style>

<!-- Responder Profile & Duty Status Header -->
<div class="card"
  style="margin-bottom: 14px; border: none; border-radius: 10px; box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05); background: #FFFFFF;">
  <div style="padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
    <!-- Left: Profile Avatar + Name + Sub Details -->
    <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
      <div
        style="width: 40px; height: 40px; border-radius: 50%; background: #EEF2F4; color: #17324D; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?php if (!empty($user['avatar_url'])): ?>
          <img src="<?= clean($user['avatar_url']) ?>" alt="Profile"
            style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
        <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
        <?php endif; ?>
      </div>
      <div style="min-width: 0;">
        <div
          style="font-size: 13.5px; font-weight: 700; color: #17324D; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
          <?= clean($user['full_name']) ?>
        </div>
        <div style="font-size: 10.5px; color: #8A96A0; font-weight: 500; margin-top: 1px;">
          BDRRMC responders
        </div>
      </div>
    </div>

    <!-- Right: Non-clickable Status Box (Available or On Duty) -->
    <div style="flex-shrink: 0;">
      <div id="topDutyStatusBox"
        style="padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; text-align: center; user-select: none; pointer-events: none; border: none; <?= $isOnDuty ? 'background: #EBF3F5; color: #2F6F73;' : 'background: #EEF2F4; color: #17324D;' ?>">
        <?= $isOnDuty ? 'On Duty' : 'Available' ?>
      </div>
    </div>
  </div>
</div>

<!-- Active Disaster Reports (Primary Operational Focus for Responders) -->
<div class="card"
  style="margin-bottom: 16px; border: none; border-radius: 10px; box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05);">
  <div class="card-body" style="padding:16px 18px;">
    <?php if (empty($activeIncidents)): ?>
      <div style="text-align:center;padding:28px 16px;color:var(--color-text-muted);">
        <div style="font-weight:600;font-size:13px;color:var(--color-primary);margin-bottom:3px;">No Active Disaster
          Incidents</div>
        <div style="font-size:11px;">There are no active disaster reports in Brgy.
          <?= clean($responderBrgyName ?: 'your assigned barangay') ?>. Field units remain on standby.
        </div>
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:14px;">
        <?php foreach ($activeIncidents as $inc):
          $isMyMission = ($isOnDuty && $activeIncidentId === (int) $inc['id']);
          $cardJson = json_encode([
            "id" => (int) $inc["id"],
            "tracking_code" => $inc["tracking_code"],
            "disaster_type" => $inc["disaster_type"],
            "status" => $inc["status"] ?? "Ongoing",
            "severity" => $inc["severity"],
            "urgency" => $inc["urgency"] ?? "Immediate",
            "purok_name" => $inc["purok_name"],
            "incident_location" => $inc["incident_location"] ?? "",
            "barangay_name" => $inc["barangay_name"],
            "lat" => !empty($inc["coordinates_lat"]) ? (float) $inc["coordinates_lat"] : 8.246596,
            "lng" => !empty($inc["coordinates_lng"]) ? (float) $inc["coordinates_lng"] : 124.259474,
            "affected_families" => (int) $inc["affected_families"],
            "affected_individuals" => (int) ($inc["affected_individuals"] ?? $inc["affected_families"]),
            "displaced_families" => (int) $inc["displaced_families"],
            "casualties_count" => (int) ($inc["casualties_count"] ?? 0),
            "injuries_count" => (int) ($inc["injuries_count"] ?? 0),
            "missing_count" => (int) ($inc["missing_count"] ?? 0),
            "situation_overview" => $inc["situation_overview"] ?? "",
            "requested_assistance" => $inc["requested_assistance"] ?? ""
          ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
          ?>
          <div
            style="background:#F8FAFB;border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;border:none;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
              <div>
                <div style="font-size:9px;color:var(--color-text-muted);font-weight:600;">
                  <?= clean($inc['tracking_code']) ?> • <?= formatDate($inc['created_at'], 'M d, h:i A') ?>
                </div>
                <div style="font-size:13px;font-weight:700;color:var(--color-primary);margin-top:2px;">
                  <?= clean($inc['disaster_type']) ?> — Brgy. <?= clean($inc['barangay_name']) ?>
                </div>
                <div style="font-size:10px;color:var(--color-secondary);font-weight:600;margin-top:1px;">
                  Purok Location: <?= clean($inc['purok_name']) ?>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                <?php
                $incStatus = ($inc['status'] === 'Completed') ? 'Completed' : 'Ongoing';
                ?>
                <span
                  style="font-size:10px;font-weight:700;padding:3px 9px;border-radius:6px;border:none;<?= $incStatus === 'Completed' ? 'background:#EEF2F4;color:#17324D;' : 'background:#EBF3F5;color:#2F6F73;' ?>">
                  <?= $incStatus ?>
                </span>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:6px;text-align:center;">
              <div style="background:#FFFFFF;padding:6px;border-radius:6px;">
                <div style="font-size:8.5px;color:var(--color-text-muted);">Affected Families</div>
                <div style="font-size:12px;font-weight:700;color:var(--color-primary);"><?= $inc['affected_families'] ?>
                </div>
              </div>
              <div style="background:#FFFFFF;padding:6px;border-radius:6px;">
                <div style="font-size:8.5px;color:var(--color-text-muted);">Displaced Families</div>
                <div style="font-size:12px;font-weight:700;color:#17324D;"><?= $inc['displaced_families'] ?></div>
              </div>
            </div>

            <?php if (!empty($inc['situation_overview'])): ?>
              <div style="font-size:10.5px;color:var(--color-text-secondary);line-height:1.4;">
                <?= clean($inc['situation_overview']) ?>
              </div>
            <?php endif; ?>

            <!-- Route & Action Buttons: Route to Incident Site + Evacuation Routes + Update Report -->
            <div style="display:flex;justify-content:flex-start;align-items:center;flex-wrap:wrap;gap:6px;padding-top:4px;">
              <button type="button" class="btn btn-primary btn-sm"
                onclick='openTacticalRouteModal(<?= $cardJson ?>, "incident")'
                style="font-size:10px;padding:5px 10px;display:inline-flex;align-items:center;gap:4px;"
                title="Exact road pathway from your current location to incident site">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <polygon points="3 11 22 2 13 21 11 13 3 11" />
                </svg>
                Route to Incident
              </button>

              <button type="button" class="btn btn-outline btn-sm"
                onclick='openTacticalRouteModal(<?= $cardJson ?>, "evac")'
                style="font-size:10px;padding:5px 10px;display:inline-flex;align-items:center;gap:4px;color:#2F6F73;"
                title="View evacuation center routes and safe pathways">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                  <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                Evacuation Routes
              </button>

              <?php if (($inc['status'] ?? 'Ongoing') === 'Ongoing'): ?>
                <button type="button" class="btn btn-outline btn-sm"
                  onclick='openUpdateIncidentModal(<?= $cardJson ?>)'
                  style="font-size:10px;padding:5px 10px;display:inline-flex;align-items:center;gap:4px;color:#17324D;border-color:#CBD5E1;font-weight:600;"
                  title="Update ongoing incident report details">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                  </svg>
                  Update Report
                </button>
              <?php endif; ?>
            </div>

            <!-- Single Response Action Button (Respond / Cancel Respond / Finish Respond) -->
            <div style="display:flex;width:100%;padding-top:8px;border-top:1px solid #EEF2F4;">
              <?php if ($isMyMission): ?>
                <?php if ($inc['status'] === 'Completed'): ?>
                  <button type="button" class="btn-responder-action btn-responder-finish"
                    onclick="handleResponderAction('finish', <?= (int) $inc['id'] ?>, this)"
                    title="Finish response mission and set status to Available">
                    Finish Respond
                  </button>
                <?php else: ?>
                  <button type="button" class="btn-responder-action btn-responder-cancel"
                    onclick="handleResponderAction('cancel', <?= (int) $inc['id'] ?>, this)"
                    title="Cancel response and set status to Available">
                    Cancel Respond
                  </button>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($isOnDuty): ?>
                  <button type="button" class="btn-responder-action btn-responder-disabled" disabled
                    title="You already have an active response mission. Finish or cancel it before responding to another incident.">
                    Respond
                  </button>
                <?php else: ?>
                  <button type="button" class="btn-responder-action btn-responder-respond"
                    onclick="handleResponderAction('respond', <?= (int) $inc['id'] ?>, this)" title="Respond to this incident">
                    Respond
                  </button>
                <?php endif; ?>
              <?php endif; ?>
            </div>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Evacuation Routing & Safe Shelters Directory (Scroll-Down Section) -->
<section class="evac-routing-section" id="evacuation-routing">
  <!-- Filter Deck -->
  <div class="evac-filter-deck">
    <div class="evac-filter-pills">
      <button type="button" class="evac-pill-btn active" data-resp-filter="all">All Shelters
        (<?= count($allEvacAreas) ?>)</button>
      <button type="button" class="evac-pill-btn" data-resp-filter="open">Open / Active</button>
      <button type="button" class="evac-pill-btn" data-resp-filter="standby">Standby</button>
      <button type="button" class="evac-pill-btn" data-resp-filter="capacity">Full / At Capacity</button>
    </div>
  </div>

  <!-- Content Split: Shelters Cards List + Live Tactical Map -->
  <div class="evac-content-split">
    <div class="evac-shelters-list" id="respShelterCardsWrap">
      <?php foreach ($allEvacAreas as $center):
        $cap = (int) $center['capacity'];
        $current = (int) $center['current_evacuees'];
        $pct = $cap > 0 ? min(100, round(($current / $cap) * 100)) : 0;
        $remaining = max(0, $cap - $current);
        $isFull = ($center['status'] === 'At Capacity' || $pct >= 100);
        $barColor = $isFull ? 'var(--color-danger, #C62828)' : ($pct >= 75 ? 'var(--color-warning, #B78103)' : 'var(--color-success, #2E7D32)');
        ?>
        <div class="evac-shelter-card" id="resp-shelter-card-<?= (int) $center['id'] ?>"
          data-id="<?= (int) $center['id'] ?>" data-brgy-id="<?= (int) $center['barangay_id'] ?>"
          data-status="<?= clean($center['status']) ?>" data-lat="<?= (float) $center['latitude'] ?>"
          data-lng="<?= (float) $center['longitude'] ?>">
          <div class="evac-card-head">
            <div>
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <h3 class="evac-card-title"><?= clean($center['name']) ?></h3>
              </div>
              <div class="evac-card-subtitle">
                Brgy. <?= clean($center['barangay_name']) ?> &bull;
                <?= clean($center['center_type'] ?? 'Evacuation Center') ?>
              </div>
            </div>
            <div>
              <?= renderStatusBadge($center['status']) ?>
            </div>
          </div>

          <!-- Road Accessibility and Distance -->
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:10.5px;">
            <span style="font-weight:600;color:var(--color-secondary);">
              Convoy: <?= clean($center['accessibility'] ?? 'All Vehicles') ?>
            </span>
            <span class="badge badge-neutral shelter-dist-badge" id="resp-dist-badge-<?= (int) $center['id'] ?>"
              style="font-size:9.5px;font-weight:700;">
              -- km
            </span>
          </div>

          <!-- Occupancy bar -->
          <div class="evac-occupancy-wrap">
            <div style="display:flex;justify-content:space-between;font-size:10px;">
              <span style="font-weight:600;">Camp Headcount</span>
              <span style="font-weight:700;color:<?= $barColor ?>;">
                <?= number_format($current) ?> / <?= number_format($cap) ?> (<?= $pct ?>%)
              </span>
            </div>
            <div class="evac-progress-bar-bg">
              <div class="evac-progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
            </div>
            <div
              style="display:flex;justify-content:space-between;font-size:9px;color:var(--color-text-muted);margin-top:2px;">
              <span>Available Headroom: <?= number_format($remaining) ?></span>
              <span>Barangay Risk: <?= clean($center['brgy_risk'] ?? 'Normal') ?></span>
            </div>
          </div>

          <!-- Amenities -->
          <div class="evac-amenities-row">
            <span class="evac-utility-badge <?= !empty($center['has_power']) ? 'avail' : 'unavail' ?>">
              <?= !empty($center['has_power']) ? '✓ Power' : '✕ No Power' ?>
            </span>
            <span class="evac-utility-badge <?= !empty($center['has_water']) ? 'avail' : 'unavail' ?>">
              <?= !empty($center['has_water']) ? '✓ Water' : '✕ Water Truck' ?>
            </span>
            <span class="evac-utility-badge <?= !empty($center['has_medical']) ? 'avail' : 'unavail' ?>">
              <?= !empty($center['has_medical']) ? '✓ Medical' : '✕ No Clinic' ?>
            </span>
          </div>

          <!-- Actions -->
          <div class="evac-card-actions">
            <?php if (!empty($center['contact_number'])): ?>
              <a href="tel:<?= clean($center['contact_number']) ?>" class="btn btn-outline btn-sm"
                style="font-size:10px;padding:4px 8px;">
                📞 <?= clean($center['contact_officer'] ?: 'Contact Officer') ?>
              </a>
            <?php else: ?>
              <span style="font-size:9.5px;color:var(--color-text-muted);">Command 161</span>
            <?php endif; ?>
            <button type="button" class="evac-route-btn" onclick="plotTacticalRoute(<?= (int) $center['id'] ?>)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 11 22 2 13 21 11 13 3 11" />
              </svg>
              View Route
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Live Tactical Evacuation Map -->
    <div class="evac-map-wrapper">
      <div class="evac-map-header">
        <span>Evacuation Map &amp; Route Guide</span>
        <span style="font-size:9.5px;color:var(--color-text-muted);">OpenStreetMap / Leaflet</span>
      </div>
      <div id="respEvacMap" class="evac-live-map-box"></div>
      <div id="respRouteSheet" class="evac-route-sheet">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <div>
            <span class="badge badge-success" style="font-size:9px;padding:2px 5px;">Convoy Target</span>
            <h4 id="respSheetTargetName"
              style="font-size:13px;font-weight:700;color:var(--color-primary);margin:2px 0 1px 0;">Selected Shelter
            </h4>
            <div id="respSheetTargetAddress" style="font-size:10px;color:var(--color-text-secondary);">—</div>
          </div>
          <button type="button" class="btn btn-outline btn-sm" onclick="clearTacticalRoute()"
            style="padding:3px 7px;font-size:9.5px;">Close Route</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
          <div class="route-stat-box">
            <div class="route-stat-val" id="respSheetDistVal">0.0 km</div>
            <div class="route-stat-lbl">Distance</div>
          </div>
          <div class="route-stat-box">
            <div class="route-stat-val" id="respSheetConvoyVal">0 min</div>
            <div class="route-stat-lbl">Convoy ETA</div>
          </div>
          <div class="route-stat-box">
            <div class="route-stat-val" id="respSheetRapidVal">0 min</div>
            <div class="route-stat-lbl">Rapid Unit ETA</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  async function setQuickStatus(status) {
    try {
      const fd = new FormData();
      fd.append('status', status);

      const res = await fetch(`${BASE_URL}/backend/functions/responder/quick_status.php`, {
        method: 'POST',
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(`Operational readiness updated to: ${status}`, 'success');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Status update failed.', 'danger');
      }
    } catch (err) {
      showToast('Network error updating operational status.', 'danger');
    }
  }

  // Tactical Evacuation Map & Convoy Routing Engine (Responder Home)
  (function () {
    const evacShelters = <?= json_encode($evacMarkersJson) ?>;
    const defaultCityLat = 8.242850;
    const defaultCityLng = 124.255200; // Hinaplanon Emergency Operations Base

    let unitCoords = [defaultCityLat, defaultCityLng];
    let map = null;
    let unitMarker = null;
    let shelterMarkers = {};
    let activeRouteLine = null;

    function calculateDistance(lat1, lon1, lat2, lon2) {
      const R = 6371; // km
      const dLat = (lat2 - lat1) * Math.PI / 180;
      const dLon = (lon2 - lon1) * Math.PI / 180;
      const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
      const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      return R * c;
    }

    function updateAllDistanceBadges() {
      evacShelters.forEach(s => {
        const dist = calculateDistance(unitCoords[0], unitCoords[1], s.lat, s.lng);
        const el = document.getElementById(`resp-dist-badge-${s.id}`);
        if (el) el.textContent = `${dist.toFixed(1)} km`;
      });
    }

    function initMap() {
      const mapContainer = document.getElementById('respEvacMap');
      if (!mapContainer || typeof L === 'undefined') return;

      map = L.map('respEvacMap', {
        center: unitCoords,
        zoom: 14,
        zoomControl: true
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
      }).addTo(map);

      // Responder unit marker (Draggable to test routes anywhere)
      const unitIcon = L.divIcon({
        className: 'unit-pin',
        html: '<div style="background:#17324D;color:#FFF;width:26px;height:26px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,0.3);">🚑</div>',
        iconSize: [26, 26],
        iconAnchor: [13, 13]
      });
      unitMarker = L.marker(unitCoords, { icon: unitIcon, draggable: true }).addTo(map)
        .bindPopup('<strong>Responder Unit Position</strong><br><span style="font-size:10px;color:#2F6F73;">💡 Drag pin anywhere to test routes!</span>');

      unitMarker.on('dragend', function (e) {
        const p = e.target.getLatLng();
        unitCoords = [p.lat, p.lng];
        updateAllDistanceBadges();
        const activeCard = document.querySelector('#respShelterCardsWrap .evac-shelter-card.active-routed');
        if (activeCard) {
          const sId = parseInt(activeCard.getAttribute('data-id'));
          plotTacticalRoute(sId);
        }
      });

      // Add Shelter markers
      evacShelters.forEach(s => {
        const pinColor = (s.status === 'Open / Active' || s.status === 'Open') ? '#2E7D32' : ((s.status === 'Standby') ? '#B78103' : '#C62828');
        const shelterIcon = L.divIcon({
          className: 'shelter-pin',
          html: `<div style="background:${pinColor};color:#FFF;width:22px;height:22px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;box-shadow:0 2px 5px rgba(0,0,0,0.25);">🏛️</div>`,
          iconSize: [22, 22],
          iconAnchor: [11, 11]
        });

        const m = L.marker([s.lat, s.lng], { icon: shelterIcon }).addTo(map);
        m.bindPopup(`
        <div style="font-size:11px;padding:2px 0;">
          <strong style="color:#17324D;">${s.name}</strong><br>
          <span style="font-size:9.5px;color:#666;">Brgy. ${s.barangay} &bull; ${s.accessibility}</span><br>
          <div style="margin-top:4px;">
            <button onclick="plotTacticalRoute(${s.id})" style="background:#17324D;color:#FFF;border:none;border-radius:4px;font-size:9.5px;padding:3px 8px;cursor:pointer;">View Route &rarr;</button>
          </div>
        </div>
      `);
        shelterMarkers[s.id] = m;
      });

      updateAllDistanceBadges();
    }

    window.plotTacticalRoute = function (shelterId) {
      const s = evacShelters.find(item => item.id === shelterId);
      if (!s || !map) return;

      // Highlight card
      document.querySelectorAll('#respShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
      const targetCard = document.getElementById(`resp-shelter-card-${shelterId}`);
      if (targetCard) {
        targetCard.classList.add('active-routed');
      }

      // Clear previous line
      if (activeRouteLine) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
      }

      const sheet = document.getElementById('respRouteSheet');

      // Use exact street pathway routing (never a straight line)
      const routerPromise = (typeof computeStreetPathway === 'function')
        ? computeStreetPathway(unitCoords, [s.lat, s.lng])
        : (typeof computeClientStreetNetworkRoute === 'function')
          ? Promise.resolve(computeClientStreetNetworkRoute(unitCoords[0], unitCoords[1], s.lat, s.lng))
          : fetch(`<?= BASE_URL ?>/backend/functions/responder/route_proxy.php?start_lat=${unitCoords[0]}&start_lng=${unitCoords[1]}&end_lat=${s.lat}&end_lng=${s.lng}`)
            .then(r => r.json());

      routerPromise.then(routeResult => {
        if (!routeResult || !routeResult.points || routeResult.points.length < 2) return;

        activeRouteLine = L.polyline(routeResult.points, {
          color: '#2F6F73',
          weight: 5,
          opacity: 0.95,
          lineCap: 'round',
          lineJoin: 'round'
        }).addTo(map);

        map.fitBounds(activeRouteLine.getBounds(), { padding: [40, 40] });

        if (shelterMarkers[shelterId]) {
          shelterMarkers[shelterId].openPopup();
        }

        const convoyMin = Math.max(1, Math.round((routeResult.distanceKm / 20) * 60));
        const rapidMin = routeResult.durationMins || Math.max(1, Math.round((routeResult.distanceKm / 32) * 60));

        if (sheet) {
          sheet.classList.add('active');
          document.getElementById('respSheetTargetName').textContent = s.name;
          document.getElementById('respSheetTargetAddress').textContent = `${s.address} (Brgy. ${s.barangay}) - Access: ${s.accessibility}`;
          document.getElementById('respSheetDistVal').textContent = `${routeResult.distanceKm.toFixed(1)} km`;
          document.getElementById('respSheetConvoyVal').textContent = `${convoyMin} min`;
          document.getElementById('respSheetRapidVal').textContent = `${rapidMin} min`;
        }
      });

      // Automatically scroll down to the map
      const mapBox = document.getElementById('respEvacMap') || document.querySelector('.evac-map-wrapper');
      if (mapBox) {
        mapBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
          if (map) map.invalidateSize();
        }, 350);
      }
    };

    window.clearTacticalRoute = function () {
      if (activeRouteLine && map) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
      }
      const sheet = document.getElementById('respRouteSheet');
      if (sheet) sheet.classList.remove('active');
      document.querySelectorAll('#respShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
      if (map) map.setView(unitCoords, 13);
    };

    // Filter pills
    document.querySelectorAll('[data-resp-filter]').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('[data-resp-filter]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.getAttribute('data-resp-filter');

        document.querySelectorAll('#respShelterCardsWrap .evac-shelter-card').forEach(card => {
          const status = card.getAttribute('data-status');
          let show = true;

          if (filter === 'open') {
            show = (status === 'Open / Active' || status === 'Open');
          } else if (filter === 'standby') {
            show = (status === 'Standby');
          } else if (filter === 'capacity') {
            show = (status === 'At Capacity' || status === 'Full');
          }

          card.style.display = show ? 'flex' : 'none';
        });
      });
    });

    // GPS Locate Button
    const locateBtn = document.getElementById('respGpsLocateBtn');
    if (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          if (typeof showToast === 'function') showToast('Geolocation not supported.', 'danger');
          return;
        }
        locateBtn.disabled = true;
        locateBtn.textContent = 'Locating...';

        navigator.geolocation.getCurrentPosition(
          pos => {
            unitCoords = [pos.coords.latitude, pos.coords.longitude];
            if (unitMarker && map) {
              unitMarker.setLatLng(unitCoords);
              unitMarker.setPopupContent('<strong>Current Unit GPS Position</strong>');
              map.setView(unitCoords, 14);
            }
            updateAllDistanceBadges();
            locateBtn.disabled = false;
            locateBtn.textContent = 'GPS Active';
            if (typeof showToast === 'function') showToast('Responder Unit GPS position fixed.', 'success');
          },
          err => {
            locateBtn.disabled = false;
            locateBtn.textContent = 'Locate Unit';
            if (typeof showToast === 'function') showToast('Could not retrieve GPS location.', 'danger');
          },
          { enableHighAccuracy: true, timeout: 8000 }
        );
      });
    }

    // Init on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initMap);
    } else {
      initMap();
    }
  })();
</script>

<script>
  window.handleResponderAction = function (action, incidentId, btnEl) {
    if (btnEl) {
      btnEl.disabled = true;
    }

    const formData = new FormData();
    formData.append('action', action);
    if (incidentId) formData.append('disaster_request_id', incidentId);
    formData.append('ajax', '1');

    fetch('<?= BASE_URL ?>/backend/functions/responder/quick_status.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          if (typeof showToast === 'function') {
            showToast(data.message || 'Operational status updated.', 'success');
          }
          // Update top duty status box immediately
          const topBox = document.getElementById('topDutyStatusBox');
          if (topBox) {
            if (data.is_on_duty) {
              topBox.textContent = 'On Duty';
              topBox.style.background = '#EBF3F5';
              topBox.style.color = '#2F6F73';
            } else {
              topBox.textContent = 'Available';
              topBox.style.background = '#EEF2F4';
              topBox.style.color = '#17324D';
            }
          }

          setTimeout(() => {
            window.location.reload();
          }, 500);
        } else {
          if (btnEl) btnEl.disabled = false;
          if (typeof showToast === 'function') {
            showToast(data.message || 'Could not update status.', 'danger');
          } else {
            alert(data.message || 'Could not update status.');
          }
        }
      })
      .catch(err => {
        if (btnEl) btnEl.disabled = false;
        console.warn('Responder action network error:', err);
        setTimeout(() => { window.location.reload(); }, 500);
      });
  };

  // Legacy fallback
  window.handleCardQuickStatus = function (status, incidentId, btnEl) {
    const action = (status === 'Responding' || status === 'On Duty') ? 'respond' : 'cancel';
    window.handleResponderAction(action, incidentId, btnEl);
  };

  // Listen for updates triggered from inside the route modal
  window.addEventListener('responderStatusChanged', function (e) {
    const topBox = document.getElementById('topDutyStatusBox');
    if (topBox && e.detail && e.detail.status) {
      const isDuty = (e.detail.status === 'On Duty' || e.detail.status === 'Responding');
      topBox.textContent = isDuty ? 'On Duty' : 'Available';
      topBox.style.background = isDuty ? '#EBF3F5' : '#EEF2F4';
      topBox.style.color = isDuty ? '#2F6F73' : '#17324D';
    }
    setTimeout(() => { window.location.reload(); }, 500);
  });
</script>

<?php require_once __DIR__ . '/partials/update-incident-modal.php'; ?>
<?php require_once __DIR__ . '/partials/route-modal.php'; ?>
<?php require_once __DIR__ . '/../layouts/footer.php'; ?>