<?php
// ============================================================================
// Views (Resident): Community Events, Disaster Drills & Family Preparedness
// Mobile-First Priority & Responsive Desktop Community Participation Module
// Features: Event Calendar, 1-Tap Attendance RSVP, and Emergency Action Protocols
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$userId = (int)$user['id'];
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterTab = $_GET['tab'] ?? 'upcoming';
$searchQuery = trim($_GET['q'] ?? '');

// Fetch Resident's Registered Event IDs
$myRsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE user_id = ? OR (phone != '' AND phone = ?)");
$myRsvpStmt->execute([$userId, $user['phone'] ?? '']);
$myRegisteredEventIds = $myRsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

// Fetch Events query
$sql = "
    SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE (e.barangay_id = ? OR e.barangay_id IS NULL)
      AND (e.is_archived = 0 OR e.is_archived IS NULL)
";
$params = [$barangayId];

if ($filterTab === 'upcoming') {
    $sql .= " AND e.start_datetime >= CURDATE() AND e.status != 'Cancelled'";
} elseif ($filterTab === 'my_drills') {
    if (!empty($myRegisteredEventIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
        $sql .= " AND e.id IN ($inPlaceholders)";
        $params = array_merge($params, $myRegisteredEventIds);
    } else {
        $sql .= " AND 1=0"; // No registered drills
    }
} elseif ($filterTab === 'completed') {
    $sql .= " AND (e.status = 'Completed' OR e.start_datetime < CURDATE())";
}

if (!empty($searchQuery)) {
    $sql .= " AND (e.title LIKE ? OR e.venue LIKE ? OR e.activity_type LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (e.start_datetime >= NOW()) DESC, e.start_datetime ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$eventsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter pills
$countUpcoming = (int)$db->query("SELECT COUNT(*) FROM events WHERE (barangay_id = {$barangayId} OR barangay_id IS NULL) AND start_datetime >= CURDATE() AND status != 'Cancelled' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
$countMyDrills = count($myRegisteredEventIds);

$pageTitle = "Community Events & Drills";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* ==========================================================================
   Resident Events & Drills: Mobile-First Priority & Anti-Slop Guidelines
   Strict 10px radius, standard Poppins/Roboto typography, crisp color tokens
   ========================================================================== */
.resident-events-wrap {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  width: 100%;
}

/* Event Filter Deck */
.event-filter-deck {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.event-search-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
.event-search-input {
  flex: 1;
  font-size: 11px;
  padding: 7px 11px;
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-sm, 6px);
  outline: none;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
}
.event-search-input:focus {
  border-color: var(--color-primary, #17324D);
}

.event-pills-row {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: none;
}
.event-pills-row::-webkit-scrollbar {
  display: none;
}
.event-pill-link {
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
.event-pill-link.active {
  background: var(--color-primary, #17324D);
  border-color: var(--color-primary, #17324D);
  color: #FFFFFF;
  font-weight: 600;
}

/* Responsive Split on Desktop: Events List (Left) + Safety Protocols (Right) */
.event-layout-split {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
}
@media (min-width: 901px) {
  .event-layout-split {
    display: grid;
    grid-template-columns: 1.25fr 0.75fr;
    align-items: start;
  }
}

/* Event Card Item */
.resident-event-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.resident-event-card:hover {
  border-color: var(--color-secondary, #2F6F73);
  box-shadow: 0 3px 10px rgba(23, 50, 77, 0.06);
}

/* Date + Content Grid inside Card */
.event-card-inner {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.event-date-box {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: var(--radius-sm, 6px);
  padding: 6px 10px;
  text-align: center;
  min-width: 56px;
  flex-shrink: 0;
}
.event-date-month {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-primary, #17324D);
  letter-spacing: 0.04em;
}
.event-date-day {
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text, #24313A);
  line-height: 1.1;
  margin: 1px 0;
}
.event-date-year {
  font-size: 9px;
  color: var(--color-text-muted, #8A96A0);
}

.event-details-col {
  flex: 1;
  min-width: 0;
}
.event-title {
  font-family: var(--font-primary, 'Poppins', sans-serif);
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary, #17324D);
  margin: 0 0 2px 0;
  line-height: 1.3;
}
.event-meta-line {
  font-size: 10.5px;
  color: var(--color-text-secondary, #66737D);
  margin-bottom: 6px;
}

/* Progress of Attendees */
.event-attendee-meter {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 10px;
  margin-top: 6px;
}
.event-attendee-bar {
  width: 100%;
  height: 5px;
  background: #D9E0E3;
  border-radius: 3px;
  overflow: hidden;
  margin-top: 4px;
}
.event-attendee-fill {
  height: 100%;
  background: var(--color-secondary, #2F6F73);
  border-radius: 3px;
}

/* RSVP Actions */
.event-action-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid var(--color-border-light, #E7EDF0);
  padding-top: 10px;
  margin-top: 4px;
  gap: 8px;
}

.rsvp-toggle-btn {
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
.rsvp-toggle-btn.attend {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border: 1px solid var(--color-primary-dark, #102336);
}
.rsvp-toggle-btn.attend:hover {
  background: var(--color-primary-light, #214364);
}
.rsvp-toggle-btn.registered {
  background: var(--color-success-bg, #EAF4EB);
  color: var(--color-success, #2E7D32);
  border: 1px solid var(--color-success-border, #BCE3C1);
}

/* Protocols Card */
.protocols-side-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.protocol-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  font-size: 11px;
  line-height: 1.4;
  color: var(--color-text, #24313A);
}
.protocol-bullet {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-secondary, #2F6F73);
  margin-top: 5px;
  flex-shrink: 0;
}
</style>

  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Community Events & Disaster Drills</h1>
      <p class="page-header-desc">
        Participate in local BDRRMC disaster preparedness drills, family simulation exercises, and community training sessions.
      </p>
    </div>
    <div class="page-header-actions">
      <a href="evacuation.php" class="btn btn-secondary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
      </a>
    </div>
  </div>

  <div class="resident-events-wrap">
    <!-- Filter Deck -->
    <div class="event-filter-deck">
      <form method="GET" class="event-search-row">
        <input type="text" 
               name="q" 
               value="<?= clean($searchQuery) ?>" 
               placeholder="Search event title, drill type, or venue..." 
               class="event-search-input">
        <?php if (!empty($filterTab)): ?><input type="hidden" name="tab" value="<?= clean($filterTab) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:0 14px;font-size:11px;">Search</button>
        <?php if (!empty($searchQuery)): ?>
          <a href="events.php?tab=<?= clean($filterTab) ?>" class="btn btn-outline btn-sm" style="padding:0 10px;font-size:11px;display:flex;align-items:center;">Reset</a>
        <?php endif; ?>
      </form>

      <div class="event-pills-row">
        <a href="events.php?tab=upcoming" class="event-pill-link <?= ($filterTab === 'upcoming') ? 'active' : '' ?>">
          Upcoming Events (<?= $countUpcoming ?>)
        </a>
        <a href="events.php?tab=my_drills" class="event-pill-link <?= ($filterTab === 'my_drills') ? 'active' : '' ?>">
          My Registered Drills (<?= $countMyDrills ?>)
        </a>
        <a href="events.php?tab=all" class="event-pill-link <?= ($filterTab === 'all') ? 'active' : '' ?>">
          All Events
        </a>
        <a href="events.php?tab=completed" class="event-pill-link <?= ($filterTab === 'completed') ? 'active' : '' ?>">
          Past / Completed
        </a>
      </div>
    </div>

    <!-- Responsive Layout Split -->
    <div class="event-layout-split">
      
      <!-- Left Column: Events Feed -->
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php if (empty($eventsList)): ?>
          <div class="resident-event-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No preparedness drills or community events match this filter.
          </div>
        <?php else: ?>
          <?php foreach ($eventsList as $act): 
            $eventId = (int)$act['id'];
            $isAttending = in_array($eventId, $myRegisteredEventIds, true);
            $startDt = strtotime($act['start_datetime']);
            $endDt = strtotime($act['end_datetime']);
            $target = max(1, (int)$act['target_participants']);
            $actual = (int)$act['actual_participants'];
            $pctAtt = min(100, round(($actual / $target) * 100));
            $isPast = ($startDt < time());
            $isCancelled = ($act['status'] === 'Cancelled');
          ?>
            <div class="resident-event-card" id="event-card-<?= $eventId ?>">
              <div class="event-card-inner">
                <!-- Date Box -->
                <div class="event-date-box">
                  <div class="event-date-month"><?= date('M', $startDt) ?></div>
                  <div class="event-date-day"><?= date('d', $startDt) ?></div>
                  <div class="event-date-year"><?= date('Y', $startDt) ?></div>
                </div>

                <!-- Event Details -->
                <div class="event-details-col">
                  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
                    <h3 class="event-title"><?= clean($act['title']) ?></h3>
                    <div><?= renderStatusBadge($act['status']) ?></div>
                  </div>

                  <div class="event-meta-line">
                    <strong><?= clean($act['activity_type']) ?></strong> &bull; 
                    Venue: <?= clean($act['venue']) ?> 
                    <?php if (!empty($act['barangay_name'])): ?>
                      (Brgy. <?= clean($act['barangay_name']) ?>)
                    <?php endif; ?>
                  </div>

                  <div style="font-size:10px;color:var(--color-primary);font-weight:600;margin-bottom:6px;">
                    Schedule: <?= date('h:i A', $startDt) ?> &ndash; <?= date('h:i A', $endDt) ?>
                  </div>

                  <?php if (!empty($act['description'])): ?>
                    <div style="font-size:11px;color:var(--color-text-secondary);line-height:1.4;margin-bottom:6px;">
                      <?= clean($act['description']) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($act['assigned_personnel'])): ?>
                    <div style="font-size:10px;color:var(--color-text-muted);">
                      Lead Facilitator: <strong><?= clean($act['assigned_personnel']) ?></strong>
                    </div>
                  <?php endif; ?>

                  <!-- Attendees Capacity Meter -->
                  <div class="event-attendee-meter">
                    <div style="display:flex;justify-content:space-between;font-family:var(--font-secondary);">
                      <span style="font-weight:600;">Community Attendance</span>
                      <span style="font-weight:700;color:var(--color-primary);" id="actual-count-<?= $eventId ?>">
                        <?= number_format($actual) ?> / <?= number_format($target) ?> Registered
                      </span>
                    </div>
                    <div class="event-attendee-bar">
                      <div class="event-attendee-fill" id="attendee-fill-<?= $eventId ?>" style="width:<?= $pctAtt ?>%;"></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Action Bar with RSVP Toggle Button -->
              <div class="event-action-bar">
                <span style="font-size:10px;color:var(--color-text-muted);">
                  Organized by <?= clean($act['barangay_name'] ? "BDRRMC {$act['barangay_name']}" : "ICDRRMO Central") ?>
                </span>

                <?php if ($isCancelled || $isPast): ?>
                  <span class="badge badge-neutral" style="font-size:10px;">
                    <?= $isCancelled ? 'Event Cancelled' : 'Completed Activity' ?>
                  </span>
                <?php else: ?>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" 
                            id="rsvp-btn-<?= $eventId ?>"
                            class="rsvp-toggle-btn <?= $isAttending ? 'registered' : 'attend' ?>"
                            onclick="toggleRsvp(<?= $eventId ?>)">
                      <?php if ($isAttending): ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Attending (Registered)
                      <?php else: ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Join / Attend Drill
                      <?php endif; ?>
                    </button>
                  </div>
                <?php endif; ?>
              </div>

            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Right Column: Family Emergency Preparedness Protocols -->
      <div style="display:flex;flex-direction:column;gap:12px;">
        <div class="protocols-side-card">
          <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <h3 style="font-family:var(--font-primary);font-size:12.5px;font-weight:700;color:var(--color-primary);margin:0;">
              Community Action Protocols
            </h3>
            <span class="badge badge-primary" style="font-size:9.5px;">BDRRMC Standard</span>
          </div>

          <div style="display:flex;flex-direction:column;gap:10px;">
            <div class="protocol-item">
              <div class="protocol-bullet"></div>
              <div>
                <strong>Earthquake: "Duck, Cover & Hold"</strong><br>
                Drop down onto your hands and knees. Cover your head and neck under a sturdy table. Hold on until shaking ceases completely.
              </div>
            </div>

            <div class="protocol-item">
              <div class="protocol-bullet"></div>
              <div>
                <strong>Flood Warning & River Alert:</strong><br>
                When Mandulog river siren sounds (Red Warning), immediately proceed to your designated barangay high-ground shelter.
              </div>
            </div>

            <div class="protocol-item">
              <div class="protocol-bullet"></div>
              <div>
                <strong>Family Go-Bag Check:</strong><br>
                Ensure your 72-hour survival kit contains drinking water, ready-to-eat canned goods, first-aid medicine, and emergency whistle.
              </div>
            </div>

            <div class="protocol-item">
              <div class="protocol-bullet"></div>
              <div>
                <strong>Toll-Free Emergency Hotline:</strong><br>
                In any immediate distress or life-threatening scenario, dial <strong style="color:var(--color-danger);">161</strong> (ICDRRMO Command).
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

<script>
// Dynamic AJAX RSVP Toggle
function toggleRsvp(eventId) {
  const btn = document.getElementById(`rsvp-btn-${eventId}`);
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
        btn.className = 'rsvp-toggle-btn registered';
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Attending (Registered)';
      } else {
        btn.className = 'rsvp-toggle-btn attend';
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Join / Attend Drill';
      }

      // Update count display
      const countEl = document.getElementById(`actual-count-${eventId}`);
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
      alert(data.message || 'Error updating RSVP.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    console.error(err);
    alert('Network error while processing registration.');
  });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
