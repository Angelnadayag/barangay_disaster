<?php
// ============================================================================
// Views (Responder): Tactical Drills & Operational Briefings Module
// Mobile-First Priority & Field-Responsive Training and Simulation Schedule
// Features: Operational Objectives, Convoy Venue Routing, and Deployment Confirmation
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$userId = (int)$user['id'];
$db = getDBConnection();

$filterTab = $_GET['tab'] ?? 'upcoming';
$searchQuery = trim($_GET['q'] ?? '');

// Fetch Responder's Confirmed Event IDs
$myRsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE user_id = ? OR (phone != '' AND phone = ?)");
$myRsvpStmt->execute([$userId, $user['phone'] ?? '']);
$myConfirmedEventIds = $myRsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

// Fetch Events query
$sql = "
    SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE (e.is_archived = 0 OR e.is_archived IS NULL)
";
$params = [];

if ($filterTab === 'upcoming') {
    $sql .= " AND e.start_datetime >= CURDATE() AND e.status != 'Cancelled'";
} elseif ($filterTab === 'my_duties') {
    if (!empty($myConfirmedEventIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($myConfirmedEventIds), '?'));
        $sql .= " AND e.id IN ($inPlaceholders)";
        $params = array_merge($params, $myConfirmedEventIds);
    } else {
        $sql .= " AND 1=0";
    }
} elseif ($filterTab === 'completed') {
    $sql .= " AND (e.status = 'Completed' OR e.start_datetime < CURDATE())";
}

if (!empty($searchQuery)) {
    $sql .= " AND (e.title LIKE ? OR e.venue LIKE ? OR e.assigned_personnel LIKE ? OR e.activity_type LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (e.start_datetime >= NOW()) DESC, e.start_datetime ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tacticalEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$countUpcoming = (int)$db->query("SELECT COUNT(*) FROM events WHERE start_datetime >= CURDATE() AND status != 'Cancelled' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
$countMyDuties = count($myConfirmedEventIds);

$pageTitle = "Preparedness Drills & Events";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* ==========================================================================
   Responder Tactical Events: Mobile-First Priority & Anti-Slop Guidelines
   Strict 10px radius, standard Poppins/Roboto typography, crisp color tokens
   ========================================================================== */
.tactical-events-wrap {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  width: 100%;
}

/* Filter Deck */
.tac-filter-deck {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.tac-search-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
.tac-search-input {
  flex: 1;
  font-size: 11px;
  padding: 7px 11px;
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-sm, 6px);
  outline: none;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
}
.tac-search-input:focus {
  border-color: var(--color-primary, #17324D);
}

.tac-pills-row {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: none;
}
.tac-pills-row::-webkit-scrollbar {
  display: none;
}
.tac-pill-link {
  white-space: nowrap;
  font-size: 10px;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: var(--radius-full, 9999px);
  border: 1px solid var(--color-border, #D9E0E3);
  background: var(--color-surface, #FFFFFF);
  color: var(--color-text-secondary, #66737D);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.tac-pill-link.active {
  background: var(--color-primary, #17324D);
  border-color: var(--color-primary, #17324D);
  color: #FFFFFF;
  font-weight: 600;
}

/* Tactical Event Card */
.tac-event-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.tac-event-card:hover {
  border-color: var(--color-secondary, #2F6F73);
  box-shadow: 0 3px 10px rgba(23, 50, 77, 0.06);
}

.tac-card-inner {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.tac-date-box {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: var(--radius-sm, 6px);
  padding: 6px 10px;
  text-align: center;
  min-width: 56px;
  flex-shrink: 0;
}
.tac-date-month {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-primary, #17324D);
}
.tac-date-day {
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text, #24313A);
  line-height: 1.1;
  margin: 1px 0;
}

/* Briefing Details Box */
.tac-briefing-box {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 10.5px;
  line-height: 1.4;
  color: var(--color-text, #24313A);
  margin-top: 6px;
}

.tac-action-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid var(--color-border-light, #E7EDF0);
  padding-top: 10px;
  margin-top: 4px;
  gap: 8px;
}

.tac-rsvp-btn {
  font-family: var(--font-primary, 'Poppins', sans-serif);
  font-size: 11px;
  font-weight: 600;
  border-radius: var(--radius-sm, 6px);
  padding: 6px 14px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 36px;
  transition: all 0.15s ease;
}
.tac-rsvp-btn.confirm {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border: 1px solid var(--color-primary-dark, #102336);
}
.tac-rsvp-btn.confirm:hover {
  background: var(--color-primary-light, #214364);
}
.tac-rsvp-btn.deployed {
  background: var(--color-success-bg, #EAF4EB);
  color: var(--color-success, #2E7D32);
  border: 1px solid var(--color-success-border, #BCE3C1);
}
</style>

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Preparedness Drills &amp; Training Events</h1>
    </div>
    <div class="page-header-actions">
      <a href="evacuation.php" class="btn btn-secondary btn-sm" style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
      </a>
    </div>
  </div>

  <div class="tactical-events-wrap">
    <!-- Filter Deck -->
    <div class="tac-filter-deck">
      <form method="GET" class="tac-search-row">
        <input type="text" 
               name="q" 
               value="<?= clean($searchQuery) ?>" 
               placeholder="Search event title, facilitator, or venue..." 
               class="tac-search-input">
        <?php if (!empty($filterTab)): ?><input type="hidden" name="tab" value="<?= clean($filterTab) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:0 14px;font-size:11px;">Search</button>
        <?php if (!empty($searchQuery)): ?>
          <a href="events.php?tab=<?= clean($filterTab) ?>" class="btn btn-outline btn-sm" style="padding:0 10px;font-size:11px;display:flex;align-items:center;">Reset</a>
        <?php endif; ?>
      </form>

      <div class="tac-pills-row">
        <a href="events.php?tab=upcoming" class="tac-pill-link <?= ($filterTab === 'upcoming') ? 'active' : '' ?>">
          Upcoming Events (<?= $countUpcoming ?>)
        </a>
        <a href="events.php?tab=my_duties" class="tac-pill-link <?= ($filterTab === 'my_duties') ? 'active' : '' ?>">
          My Registered Events (<?= $countMyDuties ?>)
        </a>
        <a href="events.php?tab=all" class="tac-pill-link <?= ($filterTab === 'all') ? 'active' : '' ?>">
          All Events
        </a>
        <a href="events.php?tab=completed" class="tac-pill-link <?= ($filterTab === 'completed') ? 'active' : '' ?>">
          Completed Events
        </a>
      </div>
    </div>

    <!-- Events List -->
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php if (empty($tacticalEvents)): ?>
        <div class="tac-event-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
          No drills or events match this filter.
        </div>
      <?php else: ?>
        <?php foreach ($tacticalEvents as $act): 
          $eventId = (int)$act['id'];
          $isConfirmed = in_array($eventId, $myConfirmedEventIds, true);
          $startDt = strtotime($act['start_datetime']);
          $endDt = strtotime($act['end_datetime']);
          $target = max(1, (int)$act['target_participants']);
          $actual = (int)$act['actual_participants'];
          $pctAtt = min(100, round(($actual / $target) * 100));
          $isPast = ($startDt < time());
          $isCancelled = ($act['status'] === 'Cancelled');
          $venueMapUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($act['venue'] . " Iligan City");
        ?>
          <div class="tac-event-card" id="tac-event-card-<?= $eventId ?>">
            <div class="tac-card-inner">
              <div class="tac-date-box">
                <div class="tac-date-month"><?= date('M', $startDt) ?></div>
                <div class="tac-date-day"><?= date('d', $startDt) ?></div>
                <div style="font-size:9px;color:var(--color-text-muted);"><?= date('Y', $startDt) ?></div>
              </div>

              <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
                  <h3 style="font-family:var(--font-primary);font-size:13.5px;font-weight:700;color:var(--color-primary);margin:0 0 2px 0;">
                    <?= clean($act['title']) ?>
                  </h3>
                  <div><?= renderStatusBadge($act['status']) ?></div>
                </div>

                <div style="font-size:10.5px;color:var(--color-text-secondary);margin-bottom:4px;">
                  <strong><?= clean($act['activity_type']) ?></strong> &bull; 
                  Venue: <?= clean($act['venue']) ?>
                  <?php if (!empty($act['barangay_name'])): ?>
                    (Brgy. <?= clean($act['barangay_name']) ?>)
                  <?php endif; ?>
                </div>

                <div style="font-size:10px;color:var(--color-primary);font-weight:600;margin-bottom:6px;">
                  Schedule: <?= date('h:i A', $startDt) ?> &ndash; <?= date('h:i A', $endDt) ?>
                </div>

                <?php if (!empty($act['assigned_personnel'])): ?>
                  <div style="font-size:10px;color:var(--color-text-muted);margin-bottom:6px;">
                    Assigned Lead: <strong><?= clean($act['assigned_personnel']) ?></strong>
                  </div>
                <?php endif; ?>

                <!-- Expected Activities / Tactical Briefing -->
                <?php if (!empty($act['content_execution']) || !empty($act['expected_activities']) || !empty($act['description'])): ?>
                  <div class="tac-briefing-box">
                    <strong>Event Details:</strong> 
                    <?= clean($act['expected_activities'] ?: ($act['content_execution'] ?: $act['description'])) ?>
                  </div>
                <?php endif; ?>

                <!-- Attendance Meter -->
                <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:6px 10px;font-size:10px;margin-top:6px;">
                  <div style="display:flex;justify-content:space-between;font-family:var(--font-secondary);">
                    <span style="font-weight:600;">Attendance</span>
                    <span style="font-weight:700;color:var(--color-primary);" id="tac-count-<?= $eventId ?>">
                      <?= number_format($actual) ?> / <?= number_format($target) ?> Registered
                    </span>
                  </div>
                  <div style="width:100%;height:5px;background:#D9E0E3;border-radius:3px;overflow:hidden;margin-top:4px;">
                    <div id="tac-fill-<?= $eventId ?>" style="height:100%;background:var(--color-secondary);border-radius:3px;width:<?= $pctAtt ?>%;"></div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Action Bar -->
            <div class="tac-action-bar">
              <a href="<?= $venueMapUrl ?>" target="_blank" rel="noopener noreferrer" style="font-size:10.5px;color:var(--color-secondary);font-weight:600;display:inline-flex;align-items:center;gap:4px;text-decoration:none;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                Navigate to Venue &rarr;
              </a>

              <?php if ($isCancelled || $isPast): ?>
                <span class="badge badge-neutral" style="font-size:10px;">
                  <?= $isCancelled ? 'Cancelled' : 'Completed' ?>
                </span>
              <?php else: ?>
                <button type="button" 
                        id="tac-rsvp-btn-<?= $eventId ?>"
                        class="tac-rsvp-btn <?= $isConfirmed ? 'deployed' : 'confirm' ?>"
                        onclick="toggleResponderDuty(<?= $eventId ?>)">
                  <?php if ($isConfirmed): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Attending ✓
                  <?php else: ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Join Event
                  <?php endif; ?>
                </button>
              <?php endif; ?>
            </div>

          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

<script>
function toggleResponderDuty(eventId) {
  const btn = document.getElementById(`tac-rsvp-btn-${eventId}`);
  if (!btn) return;

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = 'Updating...';

  const formData = new FormData();
  formData.append('event_id', eventId);

  fetch('<?= BASE_URL ?>/backend/functions/activities/rsvp.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    if (data.success) {
      if (data.is_attending) {
        btn.className = 'tac-rsvp-btn deployed';
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Attending ✓';
      } else {
        btn.className = 'tac-rsvp-btn confirm';
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Join Event';
      }

      const countEl = document.getElementById(`tac-count-${eventId}`);
      if (countEl && data.actual_participants !== undefined) {
        countEl.innerText = `${data.actual_participants} Registered`;
      }

      if (typeof showToast === 'function') {
        showToast(data.message, 'success');
      } else {
        alert(data.message);
      }
    } else {
      btn.innerHTML = originalText;
      alert(data.message || 'Error updating attendance status.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    console.error(err);
    alert('Network error while updating event attendance.');
  });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
