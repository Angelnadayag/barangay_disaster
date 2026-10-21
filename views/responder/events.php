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
$myRsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE (is_archived = 0 OR is_archived IS NULL) AND (user_id = ? OR (phone != '' AND phone = ?))");
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

<!-- Leaflet GIS Map Dependencies for Venue Navigation -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

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

/* Navigate to Venue Button with Loading State */
.btn-navigate-venue {
  font-family: var(--font-primary, 'Poppins', sans-serif);
  font-size: 11px;
  font-weight: 600;
  border-radius: var(--radius-sm, 6px);
  padding: 6px 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 36px;
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-secondary, #2F6F73);
  color: var(--color-secondary, #2F6F73);
  text-decoration: none;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-navigate-venue:hover {
  background: var(--color-secondary, #2F6F73);
  color: #FFFFFF;
  box-shadow: 0 2px 8px rgba(47, 111, 115, 0.25);
  transform: translateY(-1px);
}
.btn-navigate-venue.is-loading {
  pointer-events: none;
  opacity: 0.85;
  background: #EBF3F5;
  color: var(--color-secondary, #2F6F73);
}
.btn-navigate-venue .spin-loader {
  animation: venueSpin 0.75s linear infinite;
}
@keyframes venueSpin {
  100% { transform: rotate(360deg); }
}

/* Venue Leaflet Modal */
.venue-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: venueFadeIn 0.2s ease;
}
.venue-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
.venue-modal-container {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 680px;
  background: #FFFFFF;
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.35);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  animation: venueSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  max-height: 90vh;
}
@keyframes venueFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes venueSlideUp {
  from { opacity: 0; transform: translateY(18px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.venue-modal-header {
  padding: 12px 16px;
  background: #F8FAFB;
  border-bottom: 1px solid var(--color-border-light, #E7EDF0);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.venue-modal-title {
  margin: 0;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
}
.venue-modal-close-btn {
  background: none;
  border: none;
  font-size: 20px;
  line-height: 1;
  color: var(--color-text-muted, #8A96A0);
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 4px;
}
.venue-modal-close-btn:hover {
  background: #EAEFF2;
  color: var(--color-danger, #C62828);
}
.venue-modal-summary {
  padding: 10px 16px;
  background: #FFFFFF;
  border-bottom: 1px solid var(--color-border-light, #E7EDF0);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.venue-name-heading {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
}
.venue-event-sub {
  font-size: 10.5px;
  color: var(--color-text-secondary, #5A6A75);
  margin-top: 2px;
}
.venue-summary-stats {
  display: flex;
  align-items: center;
  gap: 8px;
}
.venue-stat-pill {
  background: #EBF3F5;
  border: 1px solid #C4DDE2;
  border-radius: 6px;
  padding: 3px 8px;
  text-align: right;
}
.venue-map-wrap {
  position: relative;
  width: 100%;
  height: 380px;
  background: #EAEEF0;
}
.venue-map-loading-overlay {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.88);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 500;
  transition: opacity 0.2s ease;
}
.venue-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #D9E0E3;
  border-top-color: var(--color-secondary, #2F6F73);
  border-radius: 50%;
  animation: venueSpin 0.7s linear infinite;
}
.venue-modal-footer {
  padding: 10px 16px;
  background: #F8FAFB;
  border-top: 1px solid var(--color-border-light, #E7EDF0);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
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
              <button type="button" 
                      class="btn-navigate-venue" 
                      onclick="openVenueModal(this)"
                      data-event-id="<?= $eventId ?>"
                      data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>"
                      data-venue="<?= htmlspecialchars($act['venue'], ENT_QUOTES) ?>"
                      data-barangay="<?= htmlspecialchars($act['barangay_name'] ?? '', ENT_QUOTES) ?>"
                      data-lat="<?= (float)($act['latitude'] ?? 0) ?>"
                      data-lng="<?= (float)($act['longitude'] ?? 0) ?>"
                      data-time="<?= htmlspecialchars(date('h:i A', $startDt) . ' – ' . date('h:i A', $endDt), ENT_QUOTES) ?>"
                      data-date="<?= htmlspecialchars(date('M d, Y', $startDt), ENT_QUOTES) ?>">
                <span class="btn-nav-icon">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                  </svg>
                </span>
                <span class="btn-nav-label">Navigate to Venue &rarr;</span>
              </button>

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

  fetch('<?= BASE_URL ?>/backend/functions/event/rsvp.php', {
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

// ============================================================================
// Venue Navigation Leaflet Mapping Tool Engine & Modality Logic
// ============================================================================
let venueMapInstance = null;
let venueMarker = null;

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function openVenueModal(btn) {
  if (!btn) return;

  const originalHtml = btn.innerHTML;
  btn.classList.add('is-loading');
  btn.innerHTML = `
    <svg class="spin-loader" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle>
      <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor"></path>
    </svg>
    <span>Locating Venue...</span>
  `;

  const title = btn.getAttribute('data-title') || 'Drill / Event';
  const venue = btn.getAttribute('data-venue') || 'Venue';
  const barangay = btn.getAttribute('data-barangay') || '';
  let lat = parseFloat(btn.getAttribute('data-lat'));
  let lng = parseFloat(btn.getAttribute('data-lng'));
  const schedule = btn.getAttribute('data-time') || '';
  const dateStr = btn.getAttribute('data-date') || '';

  // Default fallback if coordinates not set (Hinaplanon center)
  if (!lat || !lng || isNaN(lat) || isNaN(lng)) {
    lat = 8.246596;
    lng = 124.259474;
  }

  // Populate Modal Text safely
  const vNameEl = document.getElementById('venueModalVenueName');
  if (vNameEl) vNameEl.textContent = venue;

  const vSubEl = document.getElementById('venueModalEventSub');
  if (vSubEl) vSubEl.textContent = `${title} • ${dateStr} (${schedule})`;

  // Show Modal Overlay with animation
  const modal = document.getElementById('venueNavModal');
  if (modal) modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';

  // Smooth loading animation simulation & map initialization
  const loaderOverlay = document.getElementById('venueMapLoading');
  if (loaderOverlay) loaderOverlay.style.display = 'flex';

  setTimeout(() => {
    initVenueMap(lat, lng, venue, title, barangay);
    if (loaderOverlay) loaderOverlay.style.display = 'none';
    btn.classList.remove('is-loading');
    btn.innerHTML = originalHtml;
  }, 400);
}

function initVenueMap(targetLat, targetLng, venueName, eventTitle, brgyName) {
  const mapContainer = document.getElementById('venueLeafletMap');
  if (!mapContainer) return;

  if (venueMapInstance) {
    venueMapInstance.remove();
    venueMapInstance = null;
  }

  venueMapInstance = L.map('venueLeafletMap', {
    center: [targetLat, targetLng],
    zoom: 16,
    zoomControl: true
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(venueMapInstance);

  // Custom Red/Burgundy Destination Pin
  const venuePinIcon = L.divIcon({
    className: 'custom-venue-pin',
    html: `
      <div style="background:#C62828;width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(198,40,40,0.45);border:2px solid #FFF;">
        <svg style="transform:rotate(45deg);color:#FFF;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polygon points="3 11 22 2 13 21 11 13 3 11"/>
        </svg>
      </div>
    `,
    iconSize: [34, 34],
    iconAnchor: [17, 34],
    popupAnchor: [0, -32]
  });

  venueMarker = L.marker([targetLat, targetLng], { icon: venuePinIcon }).addTo(venueMapInstance);
  venueMarker.bindPopup(`
    <div style="font-family:var(--font-primary, sans-serif);padding:4px;font-size:11px;min-width:180px;">
      <strong style="color:var(--color-primary);font-size:12px;display:block;margin-bottom:2px;">${escapeHtml(venueName)}</strong>
      <span style="font-size:10px;color:var(--color-secondary);font-weight:600;">Brgy. ${escapeHtml(brgyName)}</span>
      <div style="font-size:10px;color:var(--color-text-secondary);margin-top:3px;">${escapeHtml(eventTitle)}</div>
    </div>
  `).openPopup();

  // Fix map resize rendering
  setTimeout(() => {
    if (venueMapInstance) venueMapInstance.invalidateSize();
  }, 250);
}

function closeVenueModal() {
  const modal = document.getElementById('venueNavModal');
  if (modal) modal.style.display = 'none';
  document.body.style.overflow = '';
}

// ESC key to close modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeVenueModal();
});
</script>

<!-- Venue Navigation Leaflet Modal -->
<div id="venueNavModal" class="venue-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="venueModalTitle">
  <div class="venue-modal-backdrop" onclick="closeVenueModal()"></div>
  <div class="venue-modal-container">
    <div class="venue-modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-primary" style="font-size:9.5px;padding:3px 7px;background:var(--color-primary);color:#FFF;">VENUE MAP</span>
        <h3 id="venueModalTitle" class="venue-modal-title">Event Venue &amp; Navigation</h3>
      </div>
      <button type="button" class="venue-modal-close-btn" onclick="closeVenueModal()" aria-label="Close Modal">&times;</button>
    </div>

    <!-- Venue Summary Bar (Clean: Venue & Schedule Only) -->
    <div class="venue-modal-summary">
      <div class="venue-summary-info">
        <div class="venue-name-heading" id="venueModalVenueName">Venue Name</div>
        <div class="venue-event-sub" id="venueModalEventSub">Event Title &bull; Schedule</div>
      </div>
    </div>

    <!-- Leaflet Map Container -->
    <div class="venue-map-wrap">
      <div id="venueLeafletMap" style="width:100%;height:380px;"></div>
      <div id="venueMapLoading" class="venue-map-loading-overlay">
        <div class="venue-spinner"></div>
        <span style="font-size:11px;font-weight:600;color:var(--color-primary);margin-top:8px;">Initializing Map &amp; Navigation...</span>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
