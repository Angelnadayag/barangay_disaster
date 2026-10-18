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

$pageTitle = "Community Safety Dashboard";
require_once __DIR__ . '/../layouts/header.php';
?>

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?> Resident Dashboard</h1>
    </div>
    <div class="page-header-actions">
      <a href="evacuation.php" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
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
      <a href="risk-map.php" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 9px;">Map View</a>
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

  <!-- Summary Metric Cards (Clean Label & Value Only) -->
  <div class="metrics-grid" style="margin-bottom:var(--space-3);">
    <div class="metric-card">
      <div class="metric-label">My Barangay</div>
      <div class="metric-value" style="font-size:14.5px;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?></div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Open Shelters</div>
      <div class="metric-value" style="font-size:15px;"><?= count($localEvacs) ?> Verified</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Active Advisories</div>
      <div class="metric-value" style="font-size:15px;"><?= count($recentAdvisories) ?> Bulletins</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Emergency Call Direct</div>
      <div class="metric-value" style="color:var(--color-danger);font-size:15px;">161 (ICDRRMO)</div>
    </div>
  </div>

  <!-- Main Content Split -->
  <div class="responsive-split-grid split-60-40">
    <!-- Left Column: Safe Shelters & Public Advisories -->
    <div style="display:flex;flex-direction:column;gap:var(--space-3);">

      <!-- Evacuation Centers Available (Mobile-Optimized Clean Cards) -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Designated Evacuation Shelters</h2>
          <a href="evacuation.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;">View All &rarr;</a>
        </div>
        <div class="card-body" style="padding:10px 12px;display:flex;flex-direction:column;gap:8px;">
          <?php if (empty($localEvacs)): ?>
            <div style="padding:16px;text-align:center;color:var(--color-text-muted);font-size:10.5px;">
              No open evacuation shelters currently registered for this barangay.
            </div>
          <?php else: ?>
            <?php foreach ($localEvacs as $ev): 
              $cap = (int)$ev['capacity'];
              $cur = (int)$ev['current_evacuees'];
              $pct = $cap > 0 ? round(($cur / $cap) * 100) : 0;
              $barCol = $pct >= 90 ? 'var(--color-danger)' : ($pct >= 75 ? 'var(--color-warning)' : 'var(--color-success)');
              $remaining = max(0, $cap - $cur);
            ?>
              <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:170px;">
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="font-size:12px;color:var(--color-primary);"><?= clean($ev['name']) ?></strong>
                    <?= renderStatusBadge($ev['status']) ?>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                    <div style="flex:1;max-width:130px;background:#D9E0E3;height:5px;border-radius:3px;overflow:hidden;">
                      <div style="width:<?= $pct ?>%;height:100%;background:<?= $barCol ?>;"></div>
                    </div>
                    <span style="font-size:10px;color:var(--color-text-secondary);font-weight:600;">
                      <?= $pct ?>% (<?= number_format($remaining) ?> slots available)
                    </span>
                  </div>
                </div>
                <a href="evacuation.php" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:4px 10px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                  Route
                </a>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Public Safety Advisories -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Official Bulletins</h2>
          <a href="notifications.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;">All Bulletins &rarr;</a>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($recentAdvisories)): ?>
            <div style="padding:16px;text-align:center;color:var(--color-text-muted);font-size:10.5px;">
              No new public advisories at this time.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($recentAdvisories as $adv): ?>
                <div style="padding:10px 14px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                  <div style="display:flex;align-items:center;gap:6px;min-width:0;flex:1;">
                    <?= renderStatusBadge($adv['alert_level']) ?>
                    <span style="font-size:11px;font-weight:600;color:var(--color-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?= clean($adv['title']) ?>
                    </span>
                  </div>
                  <span style="font-size:9.5px;color:var(--color-text-muted);white-space:nowrap;">
                    <?= timeAgo($adv['created_at']) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Right Column: Emergency Contacts & Quick Navigation -->
    <div style="display:flex;flex-direction:column;gap:var(--space-3);">

      <!-- Direct Emergency Directory -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Emergency Hotlines</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:10.5px;margin-bottom:0;">
            <tbody>
              <tr>
                <td style="padding:9px 14px;"><strong>ICDRRMO Command Center</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:161" style="font-weight:700;color:var(--color-danger);">161 / (063) 221-1234</a>
                </td>
              </tr>
              <tr>
                <td style="padding:9px 14px;"><strong>Brgy. Emergency Desk</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:<?= clean($barangay['contact_number'] ?? '09170001111') ?>" style="font-weight:600;color:var(--color-primary);">
                    <?= clean($barangay['contact_number'] ?? '(063) 223-9000') ?>
                  </a>
                </td>
              </tr>
              <tr>
                <td style="padding:9px 14px;"><strong>National Emergency Hotline</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:911" style="font-weight:700;color:var(--color-danger);">911</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Quick Portals -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Community Safety Links</h2>
        </div>
        <div class="card-body" style="padding:10px 14px;display:flex;flex-direction:column;gap:6px;">
          <a href="evacuation.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Evacuation Centers &amp; Route Map</span>
            <span>&rarr;</span>
          </a>
          <a href="events.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Preparedness Events &amp; Drills</span>
            <span>&rarr;</span>
          </a>
          <a href="risk-map.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Flood Risk &amp; Hazard Map</span>
            <span>&rarr;</span>
          </a>
        </div>
      </div>

    </div>
  </div>

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
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
