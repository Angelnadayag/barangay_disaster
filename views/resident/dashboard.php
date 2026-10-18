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
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

// Fetch Resident's Barangay details
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

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
            'id' => (int)$ea['id'],
            'brgy_id' => (int)$ea['barangay_id'],
            'name' => $ea['name'],
            'barangay' => $ea['barangay_name'],
            'lat' => (float)$ea['latitude'],
            'lng' => (float)$ea['longitude'],
            'status' => $ea['status'],
            'capacity' => (int)$ea['capacity'],
            'current' => (int)$ea['current_evacuees'],
            'power' => (bool)$ea['has_power'],
            'water' => (bool)$ea['has_water'],
            'medical' => (bool)$ea['has_medical'],
            'address' => $ea['location_details'] ?? $ea['location_address'] ?? '',
            'officer' => $ea['contact_officer'] ?? '',
            'phone' => $ea['contact_number'] ?? '',
            'accessibility' => $ea['accessibility'] ?? 'All Vehicles'
        ];
    }
}

// Latest Public Advisories
$notifStmt = $db->prepare("
    SELECT n.*, b.name AS barangay_name 
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    WHERE n.target_role IN ('all', 'resident')
       OR n.target_barangay_id = ?
    ORDER BY n.alert_level = 'Critical' DESC, n.created_at DESC
    LIMIT 4
");
$notifStmt->execute([$barangayId]);
$recentAdvisories = $notifStmt->fetchAll();

// Fetch Preparedness Schedule Drills / Events for Resident
$actStmt = $db->prepare("
    SELECT e.*, b.name AS barangay_name 
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    WHERE (e.barangay_id = ? OR e.barangay_id IS NULL)
      AND (e.is_archived = 0 OR e.is_archived IS NULL)
      AND e.status != 'Cancelled'
    ORDER BY e.start_datetime ASC
");
$actStmt->execute([$barangayId]);
$scheduleEvents = $actStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($scheduleEvents)) {
    $actStmt2 = $db->query("
        SELECT e.*, b.name AS barangay_name 
        FROM events e
        LEFT JOIN barangays b ON e.barangay_id = b.id
        WHERE (e.is_archived = 0 OR e.is_archived IS NULL)
          AND e.status != 'Cancelled'
        ORDER BY e.start_datetime ASC
    ");
    $scheduleEvents = $actStmt2->fetchAll(PDO::FETCH_ASSOC);
}

// User RSVP status
$myRsvps = [];
if (!empty($scheduleEvents)) {
    $rsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE user_id = ? OR (phone != '' AND phone = ?)");
    $rsvpStmt->execute([(int)$user['id'], $user['phone'] ?? '']);
    $myRsvps = $rsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// Format events for client-side calendar
$calendarEventsJson = [];
foreach ($scheduleEvents as $ev) {
    $startTs = strtotime($ev['start_datetime']);
    $endTs = strtotime($ev['end_datetime']);
    $calendarEventsJson[] = [
        'id' => (int)$ev['id'],
        'title' => $ev['title'],
        'type' => $ev['activity_type'],
        'venue' => $ev['venue'],
        'barangay' => $ev['barangay_name'] ?? 'City-Wide',
        'dateStr' => date('Y-m-d', $startTs),
        'monthShort' => strtoupper(date('M', $startTs)),
        'dayNum' => date('d', $startTs),
        'year' => (int)date('Y', $startTs),
        'month' => (int)date('n', $startTs) - 1,
        'day' => (int)date('j', $startTs),
        'timeStr' => date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs),
        'status' => $ev['status'],
        'is_registered' => in_array((int)$ev['id'], $myRsvps, true)
    ];
}

$pageTitle = "Community Safety Home";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies for Evacuation Routing -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?> Resident Home</h1>
    </div>
    <div class="page-header-actions">
      <a href="#evacuation-routing" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing &darr;
      </a>
    </div>
  </div>

  <!-- Concise Status Banner (Only if Elevated Risk) -->
  <?php if (($barangay['risk_level'] ?? '') === 'Critical' || ($barangay['risk_level'] ?? '') === 'High'): ?>
    <div style="background:#FFF;border-left:4px solid #ef4444;border-radius:var(--radius-primary, 10px);padding:9px 12px;margin-bottom:var(--space-3);border:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <div style="display:flex;align-items:center;gap:8px;font-size:11px;font-weight:600;color:var(--color-primary);">
        <span style="color:#ef4444;">⚠️</span>
        <span>High Inundation Risk Sector — Monitor Evacuation Alerts</span>
      </div>
      <a href="#evacuation-routing" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 9px;">Map View &darr;</a>
    </div>
  <?php endif; ?>

  <!-- Preparedness Schedule (Calendar Style - Displayed First) -->
  <div class="prep-calendar-card" id="residentCalendarWidget">
    <div class="prep-calendar-header">
      <div class="prep-calendar-title-wrap">
        <h2 class="prep-calendar-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-secondary);">
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
        <button type="button" class="btn btn-secondary btn-sm" id="resCalTodayBtn" style="font-size:10px;padding:3px 8px;margin-left:4px;">Today</button>
        <a href="events.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;margin-left:8px;text-decoration:none;">View All Drills &rarr;</a>
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



  <!-- Evacuation Routing & Safe Shelters (Scroll-Down Section) -->
  <section class="evac-routing-section" id="evacuation-routing">
    <div class="evac-section-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <h2 class="evac-section-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-secondary);">
            <polygon points="3 11 22 2 13 21 11 13 3 11"/>
          </svg>
          Evacuation Routing &amp; Safe Shelters Directory
        </h2>
      </div>
      <div style="display:flex;align-items:center;gap:6px;">
        <button type="button" class="btn btn-secondary btn-sm" id="resGpsLocateBtn" style="font-size:10px;padding:4px 9px;display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
          Locate Me
        </button>
      </div>
    </div>

    <!-- Filter Deck -->
    <div class="evac-filter-deck">
      <div class="evac-filter-pills">
        <button type="button" class="evac-pill-btn active" data-res-filter="all">All Shelters (<?= count($allEvacAreas) ?>)</button>
        <button type="button" class="evac-pill-btn" data-res-filter="local">My Barangay (Brgy. <?= clean($barangay['name'] ?? 'Local') ?>)</button>
        <button type="button" class="evac-pill-btn" data-res-filter="open">Open / Active</button>
        <button type="button" class="evac-pill-btn" data-res-filter="standby">Standby</button>
      </div>
    </div>

    <!-- Content Split: Shelters List + Interactive Map -->
    <div class="evac-content-split">
      <!-- Shelters Cards List -->
      <div class="evac-shelters-list" id="resShelterCardsWrap">
        <?php foreach ($allEvacAreas as $center): 
          $cap = (int)$center['capacity'];
          $current = (int)$center['current_evacuees'];
          $pct = $cap > 0 ? min(100, round(($current / $cap) * 100)) : 0;
          $remaining = max(0, $cap - $current);
          $isFull = ($center['status'] === 'At Capacity' || $pct >= 100);
          $barColor = $isFull ? 'var(--color-danger, #C62828)' : ($pct >= 75 ? 'var(--color-warning, #B78103)' : 'var(--color-success, #2E7D32)');
          $isMyBrgy = ((int)$center['barangay_id'] === $barangayId);
        ?>
          <div class="evac-shelter-card"
               id="res-shelter-card-<?= (int)$center['id'] ?>"
               data-id="<?= (int)$center['id'] ?>"
               data-brgy-id="<?= (int)$center['barangay_id'] ?>"
               data-status="<?= clean($center['status']) ?>"
               data-lat="<?= (float)$center['latitude'] ?>"
               data-lng="<?= (float)$center['longitude'] ?>">
            <div class="evac-card-head">
              <div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <h3 class="evac-card-title"><?= clean($center['name']) ?></h3>
                  <?php if ($isMyBrgy): ?>
                    <span class="badge badge-primary" style="font-size:9px;padding:1px 5px;">Local</span>
                  <?php endif; ?>
                </div>
                <div class="evac-card-subtitle">
                  Brgy. <?= clean($center['barangay_name']) ?> &bull; <?= clean($center['center_type'] ?? 'Evacuation Center') ?>
                </div>
              </div>
              <div>
                <?= renderStatusBadge($center['status']) ?>
              </div>
            </div>

            <!-- Distance and Address -->
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:10.5px;">
              <span style="color:var(--color-text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:210px;">
                <?= clean($center['location_details'] ?? $center['location_address'] ?? 'Designated Safe Center') ?>
              </span>
              <span class="badge badge-neutral shelter-dist-badge" id="res-dist-badge-<?= (int)$center['id'] ?>" style="font-size:9.5px;font-weight:700;">
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
              <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--color-text-muted);margin-top:2px;">
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
              <?php if (!empty($center['contact_number'])): ?>
                <a href="tel:<?= clean($center['contact_number']) ?>" class="btn btn-outline btn-sm" style="font-size:10px;padding:4px 8px;">
                  📞 <?= clean($center['contact_officer'] ?: 'Contact') ?>
                </a>
              <?php else: ?>
                <span style="font-size:9.5px;color:var(--color-text-muted);">Hotline 161</span>
              <?php endif; ?>
              <button type="button" class="evac-route-btn" onclick="plotEvacuationRoute(<?= (int)$center['id'] ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
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
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
            <div>
              <span class="badge badge-success" style="font-size:9px;padding:2px 5px;">Active Route</span>
              <h4 id="sheetTargetName" style="font-size:13px;font-weight:700;color:var(--color-primary);margin:2px 0 1px 0;">Selected Shelter</h4>
              <div id="sheetTargetAddress" style="font-size:10px;color:var(--color-text-secondary);">—</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearHomeRoute()" style="padding:3px 7px;font-size:9.5px;">Close Route</button>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
            <div class="route-stat-box">
              <div class="route-stat-val" id="sheetDistVal">0.0 km</div>
              <div class="route-stat-lbl">Distance</div>
            </div>
            <div class="route-stat-box">
              <div class="route-stat-val" id="sheetWalkVal">0 min</div>
              <div class="route-stat-lbl">Walk</div>
            </div>
            <div class="route-stat-box">
              <div class="route-stat-val" id="sheetDriveVal">0 min</div>
              <div class="route-stat-lbl">Drive</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

<script>
(function() {
  const events = <?= json_encode($calendarEventsJson) ?>;
  const initialYear = <?= (int)date('Y') ?>;
  const initialMonth = <?= (int)date('n') - 1 ?>;
  const initialDay = <?= (int)date('j') ?>;

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
    const upcoming = events.filter(e => e.dateStr >= todayStr).sort((a,b) => a.dateStr.localeCompare(b.dateStr));
    return upcoming.length ? upcoming[0] : (events.length ? events[0] : null);
  }

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, function(m) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
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

      cell.addEventListener('click', function() {
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

    renderDrillsForDate(dStr);
  }

  function renderDrillsForDate(dStr) {
    if (paneTitle) paneTitle.textContent = `Drills: ${formatHumanDate(dStr)}`;
    const dayDrills = events.filter(e => e.dateStr === dStr);

    if (dayDrills.length > 0) {
      if (paneBadge) paneBadge.textContent = `${dayDrills.length} Scheduled`;
      drillList.innerHTML = dayDrills.map(ev => {
        const isAttending = ev.is_registered;
        const btnClass = isAttending ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
        const btnLabel = isAttending ? '✓ Attending' : 'RSVP';
        return `
          <div class="prep-drill-card" data-event-id="${ev.id}">
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
            <div class="prep-drill-actions">
              <button type="button" class="${btnClass}" style="font-size:10px;padding:5px 10px;" onclick="handleResidentRsvp(${ev.id}, this)">
                ${btnLabel}
              </button>
              <a href="events.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:5px 8px;">
                Details
              </a>
            </div>
          </div>
        `;
      }).join('');
    } else {
      if (paneBadge) paneBadge.textContent = 'None Scheduled';
      const nextDrill = getNextUpcomingDrill();
      let nextNotice = '';
      if (nextDrill && nextDrill.dateStr !== dStr) {
        nextNotice = `
          <div style="margin-top:6px;padding-top:6px;border-top:1px dashed var(--color-border-light);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="font-size:10px;color:var(--color-primary);font-weight:600;">
              Next: <span>${escapeHtml(nextDrill.title)}</span> (${formatHumanDate(nextDrill.dateStr)})
            </div>
            <button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:3px 8px;" onclick="window.resCalSelect('${nextDrill.dateStr}')">
              View Drill &rarr;
            </button>
          </div>
        `;
      }

      drillList.innerHTML = `
        <div style="padding:14px;text-align:center;color:var(--color-text-muted);font-size:10.5px;background:var(--color-surface-subtle);border-radius:6px;border:1px dashed var(--color-border);">
          <div>No preparedness drills scheduled for this date.</div>
          ${nextNotice}
        </div>
      `;
    }
  }

  // RSVP AJAX Handler
  window.handleResidentRsvp = async function(eventId, btnElem) {
    const origText = btnElem.innerHTML;
    btnElem.disabled = true;
    btnElem.innerHTML = '...';

    try {
      const fd = new FormData();
      fd.append('event_id', eventId);

      const res = await fetch(`${BASE_URL}/backend/functions/activities/rsvp.php`, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json();
      if (data.success) {
        const isAttending = data.is_attending;
        const targetEv = events.find(e => e.id === eventId);
        if (targetEv) targetEv.is_registered = isAttending;

        if (isAttending) {
          btnElem.className = 'btn btn-primary btn-sm';
          btnElem.textContent = '✓ Attending';
        } else {
          btnElem.className = 'btn btn-secondary btn-sm';
          btnElem.textContent = 'RSVP';
        }

        if (typeof showToast === 'function') {
          showToast(data.message || 'RSVP updated successfully.', 'success');
        }
      } else {
        btnElem.innerHTML = origText;
        if (typeof showToast === 'function') {
          showToast(data.message || 'Could not update RSVP.', 'danger');
        }
      }
    } catch (err) {
      btnElem.innerHTML = origText;
      if (typeof showToast === 'function') {
        showToast('Network error updating RSVP.', 'danger');
      }
    } finally {
      btnElem.disabled = false;
    }
  };

  window.resCalSelect = function(dStr) {
    selectDate(dStr);
  };

  // Nav Handlers
  if (prevBtn) {
    prevBtn.addEventListener('click', function() {
      let m = currentMonth - 1;
      let y = currentYear;
      if (m < 0) { m = 11; y--; }
      renderCalendar(y, m);
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function() {
      let m = currentMonth + 1;
      let y = currentYear;
      if (m > 11) { m = 0; y++; }
      renderCalendar(y, m);
    });
  }

  if (todayBtn) {
    todayBtn.addEventListener('click', function() {
      const todayStr = toDateStr(initialYear, initialMonth, initialDay);
      renderCalendar(initialYear, initialMonth);
      selectDate(todayStr);
    });
  }

  // Initial load: pick next drill or today
  const nextDrill = getNextUpcomingDrill();
  const initDateStr = nextDrill ? nextDrill.dateStr : toDateStr(initialYear, initialMonth, initialDay);
  const selParts = initDateStr.split('-');
  renderCalendar(parseInt(selParts[0], 10), parseInt(selParts[1], 10) - 1);
  selectDate(initDateStr);
})();

// Evacuation Map & Live Routing Engine (Resident Home)
(function() {
  const evacShelters = <?= json_encode($evacMarkersJson) ?>;
  const residentBrgyLat = <?= (float)($barangay['coordinates_lat'] ?? 8.246596) ?>;
  const residentBrgyLng = <?= (float)($barangay['coordinates_lng'] ?? 124.259474) ?>;
  const myBrgyId = <?= (int)$barangayId ?>;

  let userCoords = [residentBrgyLat, residentBrgyLng];
  let map = null;
  let userMarker = null;
  let shelterMarkers = {};
  let activeRouteLine = null;

  function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
  }

  function updateAllDistanceBadges() {
    evacShelters.forEach(s => {
      const dist = calculateDistance(userCoords[0], userCoords[1], s.lat, s.lng);
      const el = document.getElementById(`res-dist-badge-${s.id}`);
      if (el) el.textContent = `${dist.toFixed(1)} km`;
    });
  }

  function initMap() {
    const mapContainer = document.getElementById('homeEvacMap');
    if (!mapContainer || typeof L === 'undefined') return;

    map = L.map('homeEvacMap', {
      center: userCoords,
      zoom: 14,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(map);

    // User location marker
    const userIcon = L.divIcon({
      className: 'user-pin',
      html: '<div style="background:#17324D;color:#FFF;width:24px;height:24px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,0.3);">📍</div>',
      iconSize: [24, 24],
      iconAnchor: [12, 12]
    });
    userMarker = L.marker(userCoords, { icon: userIcon }).addTo(map)
      .bindPopup('<strong>Your Location</strong><br>Default: Brgy. <?= clean($barangay['name'] ?? 'Center') ?>');

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
          <span style="font-size:9.5px;color:#666;">Brgy. ${s.barangay}</span><br>
          <div style="margin-top:4px;">
            <button onclick="plotEvacuationRoute(${s.id})" style="background:#17324D;color:#FFF;border:none;border-radius:4px;font-size:9.5px;padding:3px 8px;cursor:pointer;">Plot Route &rarr;</button>
          </div>
        </div>
      `);
      shelterMarkers[s.id] = m;
    });

    updateAllDistanceBadges();
  }

  window.plotEvacuationRoute = function(shelterId) {
    const s = evacShelters.find(item => item.id === shelterId);
    if (!s || !map) return;

    // Highlight card
    document.querySelectorAll('#resShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
    const targetCard = document.getElementById(`res-shelter-card-${shelterId}`);
    if (targetCard) {
      targetCard.classList.add('active-routed');
    }

    // Clear previous line
    if (activeRouteLine) {
      map.removeLayer(activeRouteLine);
    }

    // Draw route polyline
    const latlngs = [userCoords, [s.lat, s.lng]];
    activeRouteLine = L.polyline(latlngs, {
      color: '#2F6F73',
      weight: 4,
      dashArray: '6, 6',
      opacity: 0.9
    }).addTo(map);

    map.fitBounds(activeRouteLine.getBounds(), { padding: [40, 40] });

    // Open popup
    if (shelterMarkers[shelterId]) {
      shelterMarkers[shelterId].openPopup();
    }

    // Update bottom sheet
    const distKm = calculateDistance(userCoords[0], userCoords[1], s.lat, s.lng);
    const walkMin = Math.round((distKm / 4.5) * 60);
    const driveMin = Math.max(1, Math.round((distKm / 30) * 60));

    const sheet = document.getElementById('homeRouteSheet');
    if (sheet) {
      sheet.classList.add('active');
      document.getElementById('sheetTargetName').textContent = s.name;
      document.getElementById('sheetTargetAddress').textContent = `${s.address} (Brgy. ${s.barangay})`;
      document.getElementById('sheetDistVal').textContent = `${distKm.toFixed(1)} km`;
      document.getElementById('sheetWalkVal').textContent = `${walkMin} min`;
      document.getElementById('sheetDriveVal').textContent = `${driveMin} min`;
    }

    // Automatically scroll down to the map
    const mapBox = document.getElementById('homeEvacMap') || document.querySelector('.evac-map-wrapper');
    if (mapBox) {
      mapBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => {
        if (map) map.invalidateSize();
      }, 350);
    }
  };

  window.clearHomeRoute = function() {
    if (activeRouteLine && map) {
      map.removeLayer(activeRouteLine);
      activeRouteLine = null;
    }
    const sheet = document.getElementById('homeRouteSheet');
    if (sheet) sheet.classList.remove('active');
    document.querySelectorAll('#resShelterCardsWrap .evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
    if (map) map.setView(userCoords, 14);
  };

  // Filter pills
  document.querySelectorAll('[data-res-filter]').forEach(btn => {
    btn.addEventListener('click', function() {
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
    locateBtn.addEventListener('click', function() {
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
            userMarker.setPopupContent('<strong>Current GPS Position</strong>');
            map.setView(userCoords, 15);
          }
          updateAllDistanceBadges();
          locateBtn.disabled = false;
          locateBtn.textContent = 'GPS Active';
          if (typeof showToast === 'function') showToast('Location updated via GPS.', 'success');
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
