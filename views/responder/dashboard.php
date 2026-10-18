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
$db = getDBConnection();

// Latest status of current responder
$statStmt = $db->prepare("SELECT * FROM responder_field_updates WHERE responder_id = ? ORDER BY created_at DESC LIMIT 1");
$statStmt->execute([$user['id']]);
$currentStatus = $statStmt->fetch();
$activeStatus = $currentStatus['operational_status'] ?? 'Available';

// Active Incidents
$incStmt = $db->query("
    SELECT dr.*, b.name AS barangay_name 
    FROM disaster_reports dr 
    JOIN barangays b ON dr.barangay_id = b.id 
    WHERE dr.status = 'Ongoing'
    ORDER BY FIELD(dr.severity, 'Critical', 'High', 'Moderate', 'Low'), dr.created_at DESC
    LIMIT 6
");
$activeIncidents = $incStmt->fetchAll();

// Evacuation Centers Open
$evacStmt = $db->query("
    SELECT ea.*, b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id 
    WHERE ea.status IN ('Open / Active', 'Standby')
    ORDER BY ea.current_evacuees_count DESC
    LIMIT 4
");
$openCenters = $evacStmt->fetchAll();

// Urgent Notifications
$notifStmt = $db->query("
    SELECT * FROM notifications 
    WHERE alert_level IN ('urgent', 'warning')
    ORDER BY created_at DESC LIMIT 3
");
$urgentAlerts = $notifStmt->fetchAll();

// Fetch Preparedness Schedule Drills / Events for Responder
$drillStmt = $db->query("
    SELECT e.*, b.name AS barangay_name 
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    WHERE (e.is_archived = 0 OR e.is_archived IS NULL)
      AND e.status != 'Cancelled'
    ORDER BY e.start_datetime ASC
");
$scheduleEvents = $drillStmt->fetchAll(PDO::FETCH_ASSOC);

// Responder RSVP / Attendance
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

$pageTitle = "Responder Field Dashboard";
require_once __DIR__ . '/../layouts/header.php';
?>

  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Tactical Field Operations Dashboard</h1>
    </div>
    <div class="page-header-actions">
      <a href="active-incidents.php" class="btn btn-secondary btn-sm" style="font-size:11px;padding:6px 12px;">Active Incidents</a>
      <a href="evacuation.php" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 12px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
      </a>
    </div>
  </div>

  <!-- Preparedness Schedule (Calendar Style - Displayed First) -->
  <div class="prep-calendar-card" id="responderCalendarWidget">
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
        <button type="button" class="prep-cal-btn" id="respCalPrevBtn" aria-label="Previous Month">&lsaquo;</button>
        <span class="prep-cal-month-label" id="respCalMonthLabel">October 2026</span>
        <button type="button" class="prep-cal-btn" id="respCalNextBtn" aria-label="Next Month">&rsaquo;</button>
        <button type="button" class="btn btn-secondary btn-sm" id="respCalTodayBtn" style="font-size:10px;padding:3px 8px;margin-left:4px;">Today</button>
        <a href="events.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;margin-left:8px;text-decoration:none;">Tactical Drills &rarr;</a>
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
        <div class="prep-cal-days" id="respCalDaysGrid"></div>
      </div>

      <!-- Selected Date / Drills Agenda Pane -->
      <div class="prep-cal-details-pane">
        <div class="prep-cal-pane-header">
          <span class="prep-cal-pane-title" id="respCalPaneTitle">Scheduled Operations &amp; Drills</span>
          <span id="respCalPaneBadge" style="font-size:10px;color:var(--color-text-secondary);font-weight:600;"></span>
        </div>
        <div id="respCalDrillList" style="display:flex;flex-direction:column;gap:8px;"></div>
      </div>
    </div>
  </div>

  <!-- Tactical Identity Banner -->
  <div style="background:var(--color-primary);color:#FFF;border-radius:var(--radius-primary, 10px);padding:12px 16px;margin-bottom:var(--space-3);box-shadow:var(--shadow-subtle);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-size:9px;text-transform:uppercase;color:#B5C6D4;letter-spacing:0.8px;font-weight:700;">Tactical Field Identity</div>
        <div style="font-size:15px;font-weight:700;margin-top:2px;"><?= clean($user['full_name']) ?></div>
        <div style="font-size:10px;color:#D9E0E3;">Field Unit: <?= clean(!empty($user['purok']) ? $user['purok'] : 'Rapid Response Team A') ?> • Base: Central Command</div>
      </div>
      <div>
        <span style="font-size:9px;color:#B5C6D4;display:block;margin-bottom:4px;text-align:right;">Current Mission Readiness:</span>
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
          <?php foreach (['Available', 'En Route', 'On Scene', 'Busy', 'Off Duty'] as $st): ?>
            <?php
              $isSelected = ($activeStatus === $st);
              $bgCol = $isSelected ? '#2F6F73' : 'rgba(255,255,255,0.12)';
              $borderCol = $isSelected ? '#4DD0E1' : 'transparent';
            ?>
            <button class="btn btn-sm" style="background:<?= $bgCol ?>;color:#FFF;border:1px solid <?= $borderCol ?>;font-size:9.5px;padding:4px 9px;"
                    onclick="setQuickStatus('<?= $st ?>')">
              <?= $st ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($urgentAlerts)): ?>
    <div style="margin-bottom:var(--space-3);display:flex;flex-direction:column;gap:6px;">
      <?php foreach ($urgentAlerts as $alert): ?>
        <div style="background:#FDE8E8;border-left:4px solid var(--color-danger);padding:7px 12px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;">
          <div style="font-size:10px;color:var(--color-danger);font-weight:600;">
            [<?= strtoupper($alert['alert_level']) ?>] <?= clean($alert['title']) ?>: <span style="font-weight:400;"><?= clean($alert['message']) ?></span>
          </div>
          <span style="font-size:8.5px;color:#991B1B;white-space:nowrap;margin-left:8px;"><?= formatDate($alert['created_at'], 'h:i A') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="responsive-split-grid split-50-50">
    <!-- Active Disasters Awaiting Support -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
        <h3 class="card-title" style="margin:0;">Priority Incident Dispatches</h3>
        <a href="<?= BASE_URL ?>/views/responder/active-incidents.php" class="btn btn-outline btn-sm">All Incidents</a>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Tracking #</th>
              <th>Location</th>
              <th>Severity</th>
              <th>Displaced</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeIncidents)): ?>
              <tr><td colspan="5" style="text-align:center;padding:24px;">No active disaster incidents reported.</td></tr>
            <?php else: ?>
              <?php foreach ($activeIncidents as $inc): ?>
                <tr>
                  <td style="font-weight:600;font-family:var(--font-secondary);">
                    <?= clean($inc['tracking_code']) ?>
                    <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($inc['disaster_type']) ?></div>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= clean($inc['barangay_name']) ?></div>
                    <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($inc['purok_name']) ?></div>
                  </td>
                  <td><?= renderStatusBadge($inc['severity']) ?></td>
                  <td style="font-family:var(--font-secondary);"><?= $inc['displaced_families'] ?> fam</td>
                  <td>
                    <a href="<?= BASE_URL ?>/views/responder/field-status.php?request_id=<?= $inc['id'] ?>" class="btn btn-secondary btn-sm">
                      Log Situation
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Evacuation Centers Available -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
        <h3 class="card-title" style="margin:0;">Evacuation Shelters Vacancy Status</h3>
        <a href="<?= BASE_URL ?>/views/responder/evacuation.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Shelter Name</th>
              <th>Barangay</th>
              <th>Occupancy</th>
              <th>Road Access</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($openCenters)): ?>
              <tr><td colspan="4" style="text-align:center;padding:24px;">No open shelters recorded.</td></tr>
            <?php else: ?>
              <?php foreach ($openCenters as $oc): ?>
                <?php
                  $pct = $oc['capacity_individuals'] > 0 ? min(100, round(($oc['current_evacuees_count'] / $oc['capacity_individuals']) * 100)) : 0;
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;color:var(--color-primary);"><?= clean($oc['name']) ?></div>
                    <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($oc['center_type']) ?></div>
                  </td>
                  <td><?= clean($oc['barangay_name']) ?></td>
                  <td style="font-family:var(--font-secondary);">
                    <?= $oc['current_evacuees_count'] ?> / <?= $oc['capacity_individuals'] ?> (<?= $pct ?>%)
                  </td>
                  <td>
                    <span style="font-size:9px;color:var(--color-secondary);font-weight:600;">
                      <?= clean($oc['accessibility']) ?>
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

// Preparedness Schedule Calendar Engine (Responder)
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

  const monthLabel = document.getElementById('respCalMonthLabel');
  const daysGrid = document.getElementById('respCalDaysGrid');
  const prevBtn = document.getElementById('respCalPrevBtn');
  const nextBtn = document.getElementById('respCalNextBtn');
  const todayBtn = document.getElementById('respCalTodayBtn');
  const paneTitle = document.getElementById('respCalPaneTitle');
  const paneBadge = document.getElementById('respCalPaneBadge');
  const drillList = document.getElementById('respCalDrillList');

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
        cell.title = 'Tactical Drill Scheduled';
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
        const btnLabel = isAttending ? '✓ Assigned' : 'Confirm Duty';
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
              <button type="button" class="${btnClass}" style="font-size:10px;padding:5px 10px;" onclick="handleResponderRsvp(${ev.id}, this)">
                ${btnLabel}
              </button>
              <a href="evacuation.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:5px 8px;display:inline-flex;align-items:center;gap:3px;" title="Route to Area">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                Route
              </a>
              <a href="events.php" class="btn btn-outline btn-sm" style="font-size:10px;padding:5px 8px;">
                Briefing
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
            <button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:3px 8px;" onclick="window.respCalSelect('${nextDrill.dateStr}')">
              View Drill &rarr;
            </button>
          </div>
        `;
      }

      drillList.innerHTML = `
        <div style="padding:14px;text-align:center;color:var(--color-text-muted);font-size:10.5px;background:var(--color-surface-subtle);border-radius:6px;border:1px dashed var(--color-border);">
          <div>No operational drills scheduled for this date.</div>
          ${nextNotice}
        </div>
      `;
    }
  }

  // Responder RSVP AJAX Handler
  window.handleResponderRsvp = async function(eventId, btnElem) {
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
          btnElem.textContent = '✓ Assigned';
        } else {
          btnElem.className = 'btn btn-secondary btn-sm';
          btnElem.textContent = 'Confirm Duty';
        }

        if (typeof showToast === 'function') {
          showToast(data.message || 'Deployment duty roster updated.', 'success');
        }
      } else {
        btnElem.innerHTML = origText;
        if (typeof showToast === 'function') {
          showToast(data.message || 'Could not update duty registration.', 'danger');
        }
      }
    } catch (err) {
      btnElem.innerHTML = origText;
      if (typeof showToast === 'function') {
        showToast('Network error updating duty status.', 'danger');
      }
    } finally {
      btnElem.disabled = false;
    }
  };

  window.respCalSelect = function(dStr) {
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
