<?php
// ============================================================================
// Views (Resident): Community Disaster Safety Dashboard
// Public citizen portal for localized early warning, nearest shelter, and emergency hotlines
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int) ($user['barangay_id'] ?? 1);
$db = getDBConnection();

// Fetch Resident's Barangay details
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

// High-precision Resident origin coordinates (from Purok or Barangay)
$residentPurokName = trim($user['purok'] ?? '');
$residentLat = (float) ($barangay['coordinates_lat'] ?? 8.246596);
$residentLng = (float) ($barangay['coordinates_lng'] ?? 124.259474);

if (!empty($residentPurokName)) {
  $purokCoordStmt = $db->prepare("
    SELECT coordinates_lat, coordinates_lng, name 
    FROM puroks 
    WHERE barangay_id = ? 
      AND (LOWER(TRIM(name)) = LOWER(?) OR LOWER(TRIM(REPLACE(name, ' ', ''))) = LOWER(?)) 
      AND coordinates_lat IS NOT NULL 
    LIMIT 1
  ");
  $purokClean = str_replace(' ', '', $residentPurokName);
  $purokCoordStmt->execute([$barangayId, $residentPurokName, $purokClean]);
  $purokRow = $purokCoordStmt->fetch(PDO::FETCH_ASSOC);
  if ($purokRow && !empty($purokRow['coordinates_lat']) && !empty($purokRow['coordinates_lng'])) {
    $residentLat = (float) $purokRow['coordinates_lat'];
    $residentLng = (float) $purokRow['coordinates_lng'];
  }
}

// Nearest open evacuation centers
$evacStmt = $db->prepare("
    SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
           ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude
    FROM evacuation_areas ea 
    WHERE ea.barangay_id = ? AND ea.status IN ('Open / Active', 'Standby', 'Open')
    ORDER BY ea.status = 'Open / Active' DESC, ea.capacity_individuals DESC
");
$evacStmt->execute([$barangayId]);
$localEvacs = $evacStmt->fetchAll();

// If none in barangay, fetch city-wide open
if (empty($localEvacs)) {
  $evacStmt2 = $db->query("
        SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
               ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
               ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude,
               b.name AS barangay_name 
        FROM evacuation_areas ea 
        JOIN barangays b ON ea.barangay_id = b.id
        WHERE ea.status IN ('Open / Active', 'Open')
        LIMIT 3
    ");
  $localEvacs = $evacStmt2->fetchAll();
}

// Fetch all evacuation areas for the interactive routing section on scroll-down
$allEvacStmt = $db->query("
    SELECT ea.*, 
           ea.capacity_individuals AS capacity, 
           ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power, 
           ea.has_potable_water AS has_water, 
           ea.has_medical_station AS has_medical,
           ea.location_address AS location_details,
           ea.coordinates_lat AS latitude,
           ea.coordinates_lng AS longitude,
           b.name AS barangay_name, 
           b.risk_level AS brgy_risk
    FROM evacuation_areas ea
    JOIN barangays b ON ea.barangay_id = b.id
    ORDER BY (ea.barangay_id = {$barangayId}) DESC, FIELD(ea.status, 'Open / Active', 'Open', 'Standby', 'At Capacity', 'Closed'), ea.name ASC
");
$allEvacAreas = $allEvacStmt->fetchAll(PDO::FETCH_ASSOC);

// JSON for Leaflet routing map markers
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
      'power' => (bool) $ea['has_power'],
      'water' => (bool) $ea['has_water'],
      'medical' => (bool) $ea['has_medical'],
      'address' => $ea['location_details'] ?? $ea['location_address'] ?? '',
      'officer' => $ea['contact_officer'] ?? '',
      'phone' => $ea['contact_number'] ?? '',
      'accessibility' => $ea['accessibility'] ?? 'All Vehicles'
    ];
  }
}

// Latest Public Advisories (strictly scoped to resident's barangay & target puroks, created by Barangay Head)
$userPurok = trim($user['purok'] ?? '');
$hasNotifPuroks = function_exists('ensureNotificationColumns') ? ensureNotificationColumns($db) : false;
$purokExpr = $hasNotifPuroks ? "COALESCE(n.target_puroks, e.target_puroks)" : "e.target_puroks";

$notifStmt = $db->prepare("
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name 
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN events e ON (n.related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND n.related_id = e.id)
    WHERE (
        (n.target_user_id IS NOT NULL AND n.target_user_id = ?)
        OR
        (n.target_user_id IS NULL AND (n.target_barangay_id = ? OR n.target_barangay_id IS NULL) AND n.target_role IN ('all', 'resident'))
    )
    AND (u.role = 'barangay_head' OR n.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
    AND (
        (n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid', 'resources'))
        OR (e.id IS NOT NULL AND (e.is_archived = 0 OR e.is_archived IS NULL))
        OR (n.related_module = 'financial_aid' AND n.target_user_id IS NOT NULL)
    )
    AND (
        n.target_user_id IS NOT NULL
        OR {$purokExpr} IS NULL
        OR TRIM({$purokExpr}) = ''
        OR {$purokExpr} IN ('All Puroks', 'All')
        OR FIND_IN_SET(?, REPLACE({$purokExpr}, ', ', ',')) > 0
    )
    ORDER BY n.alert_level = 'urgent' DESC, n.alert_level = 'warning' DESC, n.created_at DESC
    LIMIT 4
");
$notifStmt->execute([(int) $user['id'], $barangayId, $barangayId, $userPurok]);
$recentAdvisories = $notifStmt->fetchAll();

// Fetch Preparedness Schedule Drills / Events for Resident (created by Barangay Head)
// Strictly scoped by target puroks: if All Puroks -> all residents; if specific puroks -> only matching residents
$actWhere = "
    e.barangay_id = ?
    AND (e.is_archived = 0 OR e.is_archived IS NULL)
    AND e.status != 'Cancelled'
    AND (u.role = 'barangay_head' OR e.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
    AND (
        e.target_puroks IS NULL
        OR TRIM(e.target_puroks) = ''
        OR e.target_puroks IN ('All Puroks', 'All')
        OR FIND_IN_SET(?, REPLACE(e.target_puroks, ', ', ',')) > 0
    )
";
$actParams = [$barangayId, $barangayId, $userPurok];

$actStmt = $db->prepare("
    SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name 
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE {$actWhere}
    ORDER BY e.start_datetime ASC
");
$actStmt->execute($actParams);
$scheduleEvents = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// User RSVP status
$myRsvps = [];
if (!empty($scheduleEvents)) {
  $rsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE (is_archived = 0 OR is_archived IS NULL) AND (user_id = ? OR (phone != '' AND phone = ?))");
  $rsvpStmt->execute([(int) $user['id'], $user['phone'] ?? '']);
  $myRsvps = array_map('intval', $rsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

// Only upcoming events that the resident has NOT joined yet (apply to join pa)
$upcomingJoinEvents = array_filter($scheduleEvents, function ($ev) use ($myRsvps) {
  return strtotime($ev['start_datetime']) >= strtotime('today') && !in_array((int) $ev['id'], $myRsvps, true);
});

// Format events for client-side calendar
$calendarEventsJson = [];
foreach ($scheduleEvents as $ev) {
  $startTs = strtotime($ev['start_datetime']);
  $endTs = strtotime($ev['end_datetime']);
  $calendarEventsJson[] = [
    'id' => (int) $ev['id'],
    'title' => $ev['title'],
    'type' => $ev['activity_type'],
    'venue' => $ev['venue'],
    'barangay' => $ev['barangay_name'] ?? 'City-Wide',
    'dateStr' => date('Y-m-d', $startTs),
    'monthShort' => strtoupper(date('M', $startTs)),
    'dayNum' => date('d', $startTs),
    'year' => (int) date('Y', $startTs),
    'month' => (int) date('n', $startTs) - 1,
    'day' => (int) date('j', $startTs),
    'timeStr' => date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs),
    'status' => $ev['status'],
    'is_registered' => in_array((int) $ev['id'], $myRsvps, true)
  ];
}

$pageTitle = "Community Safety Home";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies for Evacuation Routing (Local Vendor) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/frontend/vendor/leaflet/leaflet.css" />
<script src="<?= BASE_URL ?>/frontend/vendor/leaflet/leaflet.js"></script>


<!-- Preparedness Schedule (Calendar Style - Displayed First) -->
<div class="prep-calendar-card" id="residentCalendarWidget">
  <div class="prep-calendar-header">
    <div class="prep-calendar-title-wrap">
      <h2 class="prep-calendar-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          style="color:var(--color-secondary);">
          <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
          <line x1="16" y1="2" x2="16" y2="6"></line>
          <line x1="8" y1="2" x2="8" y2="6"></line>
          <line x1="3" y1="10" x2="21" y2="10"></line>
        </svg>
        Preparedness Schedule
      </h2>
    </div>
    <div class="prep-calendar-nav">
      <button type="button" class="prep-cal-btn" id="resCalPrevBtn" aria-label="Previous Month">&lsaquo;</button>
      <span class="prep-cal-month-label" id="resCalMonthLabel">October 2026</span>
      <button type="button" class="prep-cal-btn" id="resCalNextBtn" aria-label="Next Month">&rsaquo;</button>
      <button type="button" class="btn btn-secondary btn-sm" id="resCalTodayBtn"
        style="font-size:10px;padding:3px 8px;margin-left:4px;">Today</button>
      <a href="events.php"
        style="font-size:11px;color:var(--color-secondary);font-weight:600;margin-left:8px;text-decoration:none;">View
        All Drills &rarr;</a>
    </div>
  </div>

  <div class="prep-calendar-body">
    <!-- Calendar Grid Pane -->
    <div class="prep-cal-grid-pane">
      <div class="prep-cal-weekdays">
        <div class="prep-cal-weekday">Su</div>
        <div class="prep-cal-weekday">Mo</div>
        <div class="prep-cal-weekday">Tu</div>
        <div class="prep-cal-weekday">We</div>
        <div class="prep-cal-weekday">Th</div>
        <div class="prep-cal-weekday">Fr</div>
        <div class="prep-cal-weekday">Sa</div>
      </div>
      <div class="prep-cal-days" id="resCalDaysGrid"></div>
    </div>

    <!-- Selected Date / Drills Agenda Pane -->
    <div class="prep-cal-details-pane">
      <div class="prep-cal-pane-header">
        <span class="prep-cal-pane-title" id="resCalPaneTitle">Scheduled Drills</span>
        <span id="resCalPaneBadge" style="font-size:10px;color:var(--color-text-secondary);font-weight:600;"></span>
      </div>
      <div id="resCalDrillList" style="display:flex;flex-direction:column;gap:8px;"></div>
    </div>
  </div>
</div>

<section class="evac-routing-section" id="evacuation-routing">


  <!-- Filter Deck -->
  <div class="evac-filter-deck">
    <div class="evac-filter-pills">
      <button type="button" class="evac-pill-btn active" data-res-filter="all">All Shelters
        (<?= count($allEvacAreas) ?>)</button>
      <button type="button" class="evac-pill-btn" data-res-filter="local">My Barangay (Brgy.
        <?= clean($barangay['name'] ?? 'Local') ?>)</button>
      <button type="button" class="evac-pill-btn" data-res-filter="open">Open / Active</button>
      <button type="button" class="evac-pill-btn" data-res-filter="standby">Standby</button>
    </div>
  </div>

  <!-- Content Split: Shelters List + Interactive Map -->
  <div class="evac-content-split">
    <!-- Shelters Cards List -->
    <div class="evac-shelters-list" id="resShelterCardsWrap">
      <?php foreach ($allEvacAreas as $center):
        $cap = (int) $center['capacity'];
        $current = (int) $center['current_evacuees'];
        $pct = $cap > 0 ? min(100, round(($current / $cap) * 100)) : 0;
        $remaining = max(0, $cap - $current);
        $isFull = ($center['status'] === 'At Capacity' || $pct >= 100);
        $barColor = $isFull ? 'var(--color-danger, #C62828)' : ($pct >= 75 ? 'var(--color-warning, #B78103)' : 'var(--color-success, #2E7D32)');
        $isMyBrgy = ((int) $center['barangay_id'] === $barangayId);
        ?>
        <div class="evac-shelter-card" id="res-shelter-card-<?= (int) $center['id'] ?>"
          data-id="<?= (int) $center['id'] ?>" data-brgy-id="<?= (int) $center['barangay_id'] ?>"
          data-status="<?= clean($center['status']) ?>" data-lat="<?= (float) $center['latitude'] ?>"
          data-lng="<?= (float) $center['longitude'] ?>">
          <div class="evac-card-head">
            <div>
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <h3 class="evac-card-title"><?= clean($center['name']) ?></h3>
                <?php if ($isMyBrgy): ?>
                  <span class="badge badge-primary" style="font-size:9px;padding:1px 5px;">Local</span>
                <?php endif; ?>
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

          <!-- Distance and Address -->
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:10.5px;">
            <span
              style="color:var(--color-text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:210px;">
              <?= clean($center['location_details'] ?? $center['location_address'] ?? 'Designated Safe Center') ?>
            </span>
            <span class="badge badge-neutral shelter-dist-badge" id="res-dist-badge-<?= (int) $center['id'] ?>"
              style="font-size:9.5px;font-weight:700;">
              -- km
            </span>
          </div>

          <!-- Occupancy bar -->
          <div class="evac-occupancy-wrap">
            <div style="display:flex;justify-content:space-between;font-size:10px;">
              <span style="font-weight:600;">Load</span>
              <span style="font-weight:700;color:<?= $barColor ?>;">
                <?= number_format($current) ?> / <?= number_format($cap) ?> (<?= $pct ?>%)
              </span>
            </div>
            <div class="evac-progress-bar-bg">
              <div class="evac-progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
            </div>
            <div
              style="display:flex;justify-content:space-between;font-size:9px;color:var(--color-text-muted);margin-top:2px;">
              <span>Slots: <?= number_format($remaining) ?></span>
              <span>Convoy: <?= clean($center['accessibility'] ?? 'All Vehicles') ?></span>
            </div>
          </div>

          <!-- Amenities -->
          <div class="evac-amenities-row">
            <span class="evac-utility-badge <?= $center['has_power'] ? 'avail' : 'unavail' ?>">
              <?= $center['has_power'] ? '✓ Power' : '✕ No Power' ?>
            </span>
            <span class="evac-utility-badge <?= $center['has_water'] ? 'avail' : 'unavail' ?>">
              <?= $center['has_water'] ? '✓ Water' : '✕ Water Truck' ?>
            </span>
            <span class="evac-utility-badge <?= $center['has_medical'] ? 'avail' : 'unavail' ?>">
              <?= $center['has_medical'] ? '✓ Medical' : '✕ No Clinic' ?>
            </span>
          </div>

          <!-- Actions -->
          <div class="evac-card-actions">
            <button type="button" class="evac-route-btn" style="width: 100%;"
              onclick="plotEvacuationRoute(<?= (int) $center['id'] ?>)">
              Route
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Live Evacuation Route Map -->
    <div class="evac-map-wrapper">
      <div class="evac-map-header">
        <span>Live Evacuation Routing Map</span>
        <span style="font-size:9.5px;color:var(--color-text-muted);">OSM / Leaflet</span>
      </div>
      <div id="homeEvacMap" class="evac-live-map-box"></div>
      <div id="homeRouteSheet" class="evac-route-sheet">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
          <div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <span class="badge badge-success" style="font-size:9px;padding:2px 5px;">Active Route</span>
              <span class="badge" style="background:#E0F2F1;color:#00796B;font-size:9px;padding:2px 6px;font-weight:600;" id="sheetRouteBadge">✓ 100% Street-Accurate Route</span>
            </div>
            <h4 id="sheetTargetName"
              style="font-size:13px;font-weight:700;color:var(--color-primary);margin:3px 0 1px 0;">Selected Shelter
            </h4>
            <div id="sheetTargetAddress" style="font-size:10px;color:var(--color-text-secondary);">—</div>
          </div>
          <button type="button" class="btn btn-outline btn-sm" onclick="clearHomeRoute()"
            style="padding:3px 7px;font-size:9.5px;">Close Route</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
          <div class="route-stat-box">
            <div class="route-stat-val" id="sheetDistVal">0.0 km</div>
            <div class="route-stat-lbl">Street Distance</div>
          </div>
          <div class="route-stat-box">
            <div class="route-stat-val" id="sheetWalkVal">0 min</div>
            <div class="route-stat-lbl">Est. Walk</div>
          </div>
          <div class="route-stat-box">
            <div class="route-stat-val" id="sheetDriveVal">0 min</div>
            <div class="route-stat-lbl">Est. Drive</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const events = <?= json_encode($calendarEventsJson) ?>;
    const initialYear = <?= (int) date('Y') ?>;
    const initialMonth = <?= (int) date('n') - 1 ?>;
    const initialDay = <?= (int) date('j') ?>;

    let currentYear = initialYear;
    let currentMonth = initialMonth;
    let selectedDateStr = null;

    const monthNames = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];

    const monthLabel = document.getElementById('resCalMonthLabel');
    const daysGrid = document.getElementById('resCalDaysGrid');
    const prevBtn = document.getElementById('resCalPrevBtn');
    const nextBtn = document.getElementById('resCalNextBtn');
    const todayBtn = document.getElementById('resCalTodayBtn');
    const paneTitle = document.getElementById('resCalPaneTitle');
    const paneBadge = document.getElementById('resCalPaneBadge');
    const drillList = document.getElementById('resCalDrillList');

    function toDateStr(y, m, d) {
      const mm = String(m + 1).padStart(2, '0');
      const dd = String(d).padStart(2, '0');
      return `${y}-${mm}-${dd}`;
    }

    function formatHumanDate(dateStr) {
      const parts = dateStr.split('-');
      const dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      return dt.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }

    function getNextUpcomingDrill() {
      const todayStr = toDateStr(initialYear, initialMonth, initialDay);
      const upcoming = events.filter(e => e.dateStr >= todayStr).sort((a, b) => a.dateStr.localeCompare(b.dateStr));
      return upcoming.length ? upcoming[0] : (events.length ? events[0] : null);
    }

    function escapeHtml(str) {
      return (str || '').replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function renderCalendar(y, m) {
      currentYear = y;
      currentMonth = m;
      if (monthLabel) monthLabel.textContent = `${monthNames[m]} ${y}`;
      if (!daysGrid) return;

      daysGrid.innerHTML = '';

      const firstDayIndex = new Date(y, m, 1).getDay();
      const totalDays = new Date(y, m + 1, 0).getDate();
      const prevMonthDays = new Date(y, m, 0).getDate();

      // Previous month padding days
      for (let i = firstDayIndex - 1; i >= 0; i--) {
        const pDay = prevMonthDays - i;
        const cell = document.createElement('div');
        cell.className = 'prep-cal-day other-month';
        cell.textContent = pDay;
        daysGrid.appendChild(cell);
      }

      // Current month days
      for (let d = 1; d <= totalDays; d++) {
        const cell = document.createElement('div');
        const dStr = toDateStr(y, m, d);
        cell.className = 'prep-cal-day';
        cell.textContent = d;

        if (y === initialYear && m === initialMonth && d === initialDay) {
          cell.classList.add('is-today');
          cell.title = 'Today';
        }

        const hasEv = events.some(e => e.dateStr === dStr);
        if (hasEv) {
          cell.classList.add('has-event');
          cell.title = 'Preparedness Drill Scheduled';
        }

        if (dStr === selectedDateStr) {
          cell.classList.add('is-selected');
        }

        cell.addEventListener('click', function () {
          selectDate(dStr);
        });

        daysGrid.appendChild(cell);
      }

      // Trailing days padding
      const currentCells = firstDayIndex + totalDays;
      const remaining = (currentCells % 7 === 0) ? 0 : 7 - (currentCells % 7);
      for (let j = 1; j <= remaining; j++) {
        const cell = document.createElement('div');
        cell.className = 'prep-cal-day other-month';
        cell.textContent = j;
        daysGrid.appendChild(cell);
      }
    }

    function renderDrillCard(ev) {
      return `
      <div class="prep-drill-card" id="home-drill-card-${ev.id}" data-event-id="${ev.id}">
        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:180px;">
          <div class="prep-drill-date-block">
            <span class="prep-drill-month">${ev.monthShort}</span>
            <span class="prep-drill-day">${ev.dayNum}</span>
          </div>
          <div class="prep-drill-info">
            <div class="prep-drill-title">${escapeHtml(ev.title)}</div>
            <div class="prep-drill-meta">
              <span>🕒 ${escapeHtml(ev.timeStr)}</span>
              <span>📍 ${escapeHtml(ev.venue)}</span>
              <span class="badge" style="background:#EBF3F5;color:var(--color-secondary);font-size:9px;padding:2px 6px;">${escapeHtml(ev.type)}</span>
            </div>
          </div>
        </div>
        <div class="prep-drill-actions" style="display:flex;gap:6px;align-items:center;">
          <button type="button" class="btn btn-primary btn-sm" style="font-size:10px;padding:5px 12px;" onclick="handleResidentRsvp(${ev.id}, this)">
            Join Event
          </button>
          <a href="#evacuation-routing" class="btn btn-outline btn-sm" style="font-size:10px;padding:5px 8px;display:inline-flex;align-items:center;gap:3px;" title="Route to Area">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
            Route
          </a>
          <a href="events.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:5px 8px;">
            Details
          </a>
        </div>
      </div>
    `;
    }

    function renderOpenDrillsList(filterDate = null) {
      const todayStr = toDateStr(initialYear, initialMonth, initialDay);
      const unjoinedEvents = events.filter(e => !e.is_registered && e.dateStr >= todayStr && e.status !== 'Cancelled')
        .sort((a, b) => a.dateStr.localeCompare(b.dateStr));

      if (filterDate) {
        const dateUnjoined = unjoinedEvents.filter(e => e.dateStr === filterDate);
        const dateRegistered = events.filter(e => e.dateStr === filterDate && e.is_registered);

        if (dateUnjoined.length > 0) {
          if (paneTitle) paneTitle.textContent = `Drills: ${formatHumanDate(filterDate)}`;
          if (paneBadge) paneBadge.textContent = `${dateUnjoined.length} Scheduled`;
          drillList.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span style="font-size:10px;color:var(--color-text-secondary);">${formatHumanDate(filterDate)}</span>
            ${unjoinedEvents.length > dateUnjoined.length ? `<button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:2px 7px;" onclick="window.resCalShowAll()">Show All Open (${unjoinedEvents.length})</button>` : ''}
          </div>
          ${dateUnjoined.map(ev => renderDrillCard(ev)).join('')}
        `;
          return;
        } else if (dateRegistered.length > 0) {
          if (paneTitle) paneTitle.textContent = `Drills: ${formatHumanDate(filterDate)}`;
          if (paneBadge) paneBadge.textContent = `Attending`;
          drillList.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span style="font-size:10px;color:var(--color-text-secondary);">${formatHumanDate(filterDate)}</span>
            <button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:2px 7px;" onclick="window.resCalShowAll()">Show All Open (${unjoinedEvents.length})</button>
          </div>
          ${dateRegistered.map(ev => `
            <div style="padding:12px 14px;background:#F0F8F4;border:1px solid #C8E6C9;border-radius:6px;font-size:11px;color:#2E7D32;display:flex;justify-content:space-between;align-items:center;gap:8px;">
              <div>
                <strong style="color:#1B5E20;">✓ Attending: ${escapeHtml(ev.title)}</strong>
                <div style="font-size:10px;color:var(--color-text-secondary);margin-top:2px;">You already joined this drill. Managed in your Events module.</div>
              </div>
              <a href="events.php?tab=attending" class="btn btn-secondary btn-sm" style="font-size:10px;padding:4px 10px;white-space:nowrap;">
                Manage in Events &rarr;
              </a>
            </div>
          `).join('')}
        `;
          return;
        } else {
          if (paneTitle) paneTitle.textContent = `Drills: ${formatHumanDate(filterDate)}`;
          if (paneBadge) paneBadge.textContent = `None Scheduled`;
          drillList.innerHTML = `
          <div style="padding:12px;text-align:center;color:var(--color-text-muted);font-size:10.5px;background:var(--color-surface-subtle);border-radius:6px;border:1px dashed var(--color-border);margin-bottom:4px;">
            <div>No preparedness drills scheduled for ${formatHumanDate(filterDate)}.</div>
            ${unjoinedEvents.length > 0 ? `<button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:3px 8px;margin-top:4px;" onclick="window.resCalShowAll()">View All Open Events (${unjoinedEvents.length}) &rarr;</button>` : ''}
          </div>
        `;
          return;
        }
      }

      // Default view: list down ALL open events that the resident can join
      if (paneTitle) paneTitle.textContent = 'Events to Join';
      if (unjoinedEvents.length > 0) {
        if (paneBadge) paneBadge.textContent = `${unjoinedEvents.length} Scheduled`;
        drillList.innerHTML = unjoinedEvents.map(ev => renderDrillCard(ev)).join('');
      } else {
        if (paneBadge) paneBadge.textContent = 'All Caught Up';
        drillList.innerHTML = `
        <div style="padding:18px;text-align:center;color:var(--color-primary);font-size:11.5px;background:#F8FAFB;border-radius:6px;border:1px dashed #D9E0E3;">
          <div style="font-size:18px;margin-bottom:4px;">✓</div>
          <strong>You are attending all open community drills!</strong>
          <div style="color:var(--color-text-secondary);font-size:10.5px;margin-top:2px;margin-bottom:8px;">
            All available disaster drills have been joined. You can view and manage your attendance in the Events module.
          </div>
          <a href="events.php?tab=attending" class="btn btn-primary btn-sm" style="font-size:10.5px;padding:5px 12px;">
            View Attending in Events &rarr;
          </a>
        </div>
      `;
      }
    }

    function selectDate(dStr) {
      selectedDateStr = dStr;
      const parts = dStr.split('-');
      const selY = parseInt(parts[0], 10);
      const selM = parseInt(parts[1], 10) - 1;
      const selD = parseInt(parts[2], 10);

      if (selY !== currentYear || selM !== currentMonth) {
        renderCalendar(selY, selM);
      } else {
        const cells = daysGrid.querySelectorAll('.prep-cal-day:not(.other-month)');
        cells.forEach(c => {
          if (parseInt(c.textContent, 10) === selD) {
            c.classList.add('is-selected');
          } else {
            c.classList.remove('is-selected');
          }
        });
      }

      renderOpenDrillsList(dStr);
    }

    // RSVP AJAX Handler
    window.handleResidentRsvp = async function (eventId, btnElem) {
      const origText = btnElem.innerHTML;
      btnElem.disabled = true;
      btnElem.textContent = 'Joining...';

      try {
        const fd = new FormData();
        fd.append('event_id', eventId);

        const res = await fetch(`${BASE_URL}/backend/functions/event/rsvp.php`, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await res.json();
        if (data.success && data.is_attending) {
          const targetEv = events.find(e => e.id === eventId);
          if (targetEv) targetEv.is_registered = true;

          // Animate card removal since it immediately moves to the Events module!
          const card = document.getElementById(`home-drill-card-${eventId}`) || btnElem.closest('.prep-drill-card');
          if (card) {
            card.style.transition = 'all 0.35s cubic-bezier(0.4, 0, 0.2, 1)';
            card.style.opacity = '0';
            card.style.transform = 'translateY(-10px) scale(0.98)';
            setTimeout(() => {
              renderOpenDrillsList(selectedDateStr);
            }, 350);
          } else {
            renderOpenDrillsList(selectedDateStr);
          }

          if (typeof showToast === 'function') {
            showToast('Successfully joined! Event displayed now in your Events module under Attending.', 'success');
          } else {
            alert('Successfully joined! Event displayed now in your Events module under Attending.');
          }
        } else {
          btnElem.disabled = false;
          btnElem.innerHTML = origText;
          if (typeof showToast === 'function') {
            showToast(data.message || 'Could not join event.', 'danger');
          } else {
            alert(data.message || 'Could not join event.');
          }
        }
      } catch (err) {
        btnElem.disabled = false;
        btnElem.innerHTML = origText;
        console.error(err);
        if (typeof showToast === 'function') {
          showToast('Network error joining event.', 'danger');
        } else {
          alert('Network error joining event.');
        }
      }
    };

    window.resCalSelect = function (dStr) {
      selectDate(dStr);
    };

    window.resCalShowAll = function () {
      selectedDateStr = null;
      const cells = daysGrid.querySelectorAll('.prep-cal-day');
      cells.forEach(c => c.classList.remove('is-selected'));
      renderOpenDrillsList();
    };

    // Nav Handlers
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        let m = currentMonth - 1;
        let y = currentYear;
        if (m < 0) { m = 11; y--; }
        renderCalendar(y, m);
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        let m = currentMonth + 1;
        let y = currentYear;
        if (m > 11) { m = 0; y++; }
        renderCalendar(y, m);
      });
    }

    if (todayBtn) {
      todayBtn.addEventListener('click', function () {
        renderCalendar(initialYear, initialMonth);
        window.resCalShowAll();
      });
    }

    // Initial load: render calendar and list down all events to join
    renderCalendar(currentYear, currentMonth);
    renderOpenDrillsList();
  })();

  // Evacuation Map & Live Routing Engine (Resident Home)
  (function () {
    const evacShelters = <?= json_encode($evacMarkersJson) ?>;
    const residentLat = <?= (float) $residentLat ?>;
    const residentLng = <?= (float) $residentLng ?>;
    const residentPurokName = <?= json_encode($residentPurokName) ?>;
    const residentBrgyName = <?= json_encode($barangay['name'] ?? 'Hinaplanon') ?>;
    const myBrgyId = <?= (int) $barangayId ?>;

    let userCoords = [residentLat, residentLng];
    let map = null;
    let userMarker = null;
    let shelterMarkers = {};
    let activeRouteGlow = null;
    let activeRouteLine = null;
    let currentRoutingShelterId = null;

    function escapeHtml(str) {
      return (str || '').replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

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

    // High-Precision Multi-Tier Street Routing Engine (100% Real Roads & Turns)
    async function computeAccurateStreetRoute(startLat, startLng, endLat, endLng) {
      // 1. Try Backend Tactical Route Proxy (connects to OSRM + caches + local corridor solver)
      try {
        const proxyUrl = `<?= BASE_URL ?>/backend/functions/responder/route_proxy.php?start_lat=${encodeURIComponent(startLat)}&start_lng=${encodeURIComponent(startLng)}&end_lat=${encodeURIComponent(endLat)}&end_lng=${encodeURIComponent(endLng)}`;
        const res = await fetch(proxyUrl, { signal: AbortSignal.timeout(7000) });
        if (res.ok) {
          const data = await res.json();
          if (data && data.success && Array.isArray(data.points) && data.points.length > 1) {
            const pts = [...data.points];
            pts[0] = [startLat, startLng];
            pts[pts.length - 1] = [endLat, endLng];
            return {
              points: pts,
              distanceKm: parseFloat(data.distanceKm) || 0.1,
              durationMins: parseInt(data.durationMins, 10) || 1,
              source: data.source || 'proxy'
            };
          }
        }
      } catch (err) {
        console.warn('Route proxy unavailable, trying direct OSRM engine:', err);
      }

      // 2. Direct browser fallback to public OSRM router
      try {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${endLng},${endLat}?overview=full&geometries=geojson`;
        const res = await fetch(osrmUrl, { signal: AbortSignal.timeout(7000) });
        if (res.ok) {
          const json = await res.json();
          if (json && json.code === 'Ok' && json.routes && json.routes[0]) {
            const route = json.routes[0];
            const pts = route.geometry.coordinates.map(pt => [pt[1], pt[0]]);
            pts[0] = [startLat, startLng];
            pts[pts.length - 1] = [endLat, endLng];
            return {
              points: pts,
              distanceKm: Math.round((route.distance / 1000) * 100) / 100,
              durationMins: Math.max(1, Math.round(route.duration / 60)),
              source: 'osrm_client'
            };
          }
        }
      } catch (err) {
        console.warn('Direct OSRM fallback failed:', err);
      }

      // 3. Fallback: straight path with accurate Haversine distance
      const dist = calculateDistance(startLat, startLng, endLat, endLng);
      return {
        points: [[startLat, startLng], [endLat, endLng]],
        distanceKm: Math.max(0.05, Math.round(dist * 100) / 100),
        durationMins: Math.max(1, Math.round((dist / 30) * 60)),
        source: 'fallback'
      };
    }

    function updateAllDistanceBadges() {
      evacShelters.forEach(s => {
        const dist = calculateDistance(userCoords[0], userCoords[1], s.lat, s.lng);
        const el = document.getElementById(`res-dist-badge-${s.id}`);
        if (el) el.textContent = `~${dist.toFixed(1)} km`;
      });
    }

    function initMap() {
      const mapContainer = document.getElementById('homeEvacMap');
      if (!mapContainer || typeof L === 'undefined') return;

      map = L.map('homeEvacMap', {
        center: userCoords,
        zoom: 15,
        zoomControl: true
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
      }).addTo(map);

      // User location marker
      const userIcon = L.divIcon({
        className: 'user-pin',
        html: '<div style="background:#17324D;color:#FFF;width:26px;height:26px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;box-shadow:0 3px 8px rgba(0,0,0,0.35);">📍</div>',
        iconSize: [26, 26],
        iconAnchor: [13, 13]
      });
      userMarker = L.marker(userCoords, { icon: userIcon }).addTo(map)
        .bindPopup('<strong>Your Location</strong><br>' + (residentPurokName ? 'Purok ' + escapeHtml(residentPurokName) + ', ' : '') + 'Brgy. <?= clean($barangay['name'] ?? 'Center') ?>');

      // Add Shelter markers
      evacShelters.forEach(s => {
        const pinColor = (s.status === 'Open / Active' || s.status === 'Open') ? '#2E7D32' : ((s.status === 'Standby') ? '#B78103' : '#C62828');
        const shelterIcon = L.divIcon({
          className: 'shelter-pin',
          html: `<div style="background:${pinColor};color:#FFF;width:24px;height:24px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,0.25);">🏛️</div>`,
          iconSize: [24, 24],
          iconAnchor: [12, 12]
        });

        const m = L.marker([s.lat, s.lng], { icon: shelterIcon }).addTo(map);
        m.bindPopup(`
          <div style="font-size:11.5px;padding:3px 1px;min-width:160px;">
            <strong style="color:#17324D;display:block;margin-bottom:2px;">${escapeHtml(s.name)}</strong>
            <span style="font-size:10px;color:#666;">Brgy. ${escapeHtml(s.barangay)}</span>
            <div style="margin-top:6px;">
              <button onclick="plotEvacuationRoute(${s.id})" style="background:#17324D;color:#FFF;border:none;border-radius:4px;font-size:10px;font-weight:600;padding:4px 9px;cursor:pointer;">Plot Street Route &rarr;</button>
            </div>
          </div>
        `);
        shelterMarkers[s.id] = m;
      });

      updateAllDistanceBadges();
    }

    window.plotEvacuationRoute = async function (shelterId) {
      const s = evacShelters.find(item => item.id === shelterId);
      if (!s || !map) return;
      currentRoutingShelterId = shelterId;

      // Highlight active card
      document.querySelectorAll('#resShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
      const targetCard = document.getElementById(`res-shelter-card-${shelterId}`);
      if (targetCard) {
        targetCard.classList.add('active-routed');
      }

      // Open bottom sheet in loading state
      const sheet = document.getElementById('homeRouteSheet');
      if (sheet) {
        sheet.classList.add('active');
        document.getElementById('sheetTargetName').textContent = s.name;
        document.getElementById('sheetTargetAddress').textContent = `${s.address} (Brgy. ${s.barangay})`;
        document.getElementById('sheetDistVal').textContent = 'Routing...';
        document.getElementById('sheetWalkVal').textContent = '...';
        document.getElementById('sheetDriveVal').textContent = '...';
      }

      // Automatically scroll down to the map
      const mapBox = document.getElementById('homeEvacMap') || document.querySelector('.evac-map-wrapper');
      if (mapBox) {
        mapBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
          if (map) map.invalidateSize();
        }, 300);
      }

      // Fetch 100% accurate turn-by-turn road route
      const routeData = await computeAccurateStreetRoute(userCoords[0], userCoords[1], s.lat, s.lng);

      // Verify resident hasn't switched to another shelter in between
      if (currentRoutingShelterId !== shelterId) return;

      // Clear previous lines
      if (activeRouteGlow) {
        map.removeLayer(activeRouteGlow);
        activeRouteGlow = null;
      }
      if (activeRouteLine) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
      }

      // Draw dual-layer street route (navy glow background + sharp teal road line)
      activeRouteGlow = L.polyline(routeData.points, {
        color: '#17324D',
        weight: 6,
        opacity: 0.35,
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(map);

      activeRouteLine = L.polyline(routeData.points, {
        color: '#2F6F73',
        weight: 4,
        opacity: 0.95,
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(map);

      map.fitBounds(activeRouteLine.getBounds(), { padding: [45, 45] });

      // Open shelter popup
      if (shelterMarkers[shelterId]) {
        shelterMarkers[shelterId].openPopup();
      }

      // Real street network distance & durations
      const distKm = routeData.distanceKm;
      const walkMin = Math.max(1, Math.round((distKm / 4.2) * 60)); // ~4.2 km/h walking speed along streets
      const driveMin = Math.max(1, routeData.durationMins || Math.round((distKm / 28) * 60));

      if (sheet) {
        document.getElementById('sheetDistVal').textContent = `${distKm.toFixed(2)} km`;
        document.getElementById('sheetWalkVal').textContent = `${walkMin} min`;
        document.getElementById('sheetDriveVal').textContent = `${driveMin} min`;
      }
    };

    window.clearHomeRoute = function () {
      currentRoutingShelterId = null;
      if (activeRouteGlow && map) {
        map.removeLayer(activeRouteGlow);
        activeRouteGlow = null;
      }
      if (activeRouteLine && map) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
      }
      const sheet = document.getElementById('homeRouteSheet');
      if (sheet) sheet.classList.remove('active');
      document.querySelectorAll('#resShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
      if (map) map.setView(userCoords, 15);
    };

    // Filter pills
    document.querySelectorAll('[data-res-filter]').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('[data-res-filter]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.getAttribute('data-res-filter');

        document.querySelectorAll('#resShelterCardsWrap .evac-shelter-card').forEach(card => {
          const brgyId = parseInt(card.getAttribute('data-brgy-id'), 10);
          const status = card.getAttribute('data-status');
          let show = true;

          if (filter === 'local') {
            show = (brgyId === myBrgyId);
          } else if (filter === 'open') {
            show = (status === 'Open / Active' || status === 'Open');
          } else if (filter === 'standby') {
            show = (status === 'Standby');
          }

          card.style.display = show ? 'flex' : 'none';
        });
      });
    });

    // GPS Locate Button
    const locateBtn = document.getElementById('resGpsLocateBtn');
    if (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          if (typeof showToast === 'function') showToast('Geolocation is not supported by your browser.', 'danger');
          return;
        }
        locateBtn.disabled = true;
        locateBtn.textContent = 'Locating...';

        navigator.geolocation.getCurrentPosition(
          pos => {
            userCoords = [pos.coords.latitude, pos.coords.longitude];
            if (userMarker && map) {
              userMarker.setLatLng(userCoords);
              userMarker.setPopupContent('<strong>Current GPS Position</strong><br>Accuracy: ~' + Math.round(pos.coords.accuracy) + 'm');
              map.setView(userCoords, 15);
            }
            updateAllDistanceBadges();
            locateBtn.disabled = false;
            locateBtn.textContent = 'GPS Active';
            if (typeof showToast === 'function') showToast('Origin updated to live GPS position.', 'success');

            // If a route was already open, replot it immediately with new GPS coords!
            if (currentRoutingShelterId) {
              plotEvacuationRoute(currentRoutingShelterId);
            }
          },
          err => {
            locateBtn.disabled = false;
            locateBtn.textContent = 'Locate Me';
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

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>