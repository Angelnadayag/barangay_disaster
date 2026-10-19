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
$userId = (int) $user['id'];
$barangayId = (int) ($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterTab = $_GET['tab'] ?? 'upcoming';
$searchQuery = trim($_GET['q'] ?? '');

// Fetch Resident's Registered Event IDs
$myRsvpStmt = $db->prepare("SELECT activity_id FROM event_attendees WHERE user_id = ? OR (phone != '' AND phone = ?)");
$myRsvpStmt->execute([$userId, $user['phone'] ?? '']);
$myRegisteredEventIds = $myRsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

// Fetch Events query strictly scoped to resident's barangay and created by their Barangay Head
$sql = "
    SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.barangay_id = ?
      AND (e.is_archived = 0 OR e.is_archived IS NULL)
      AND (u.role = 'barangay_head' OR e.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
";
$params = [$barangayId, $barangayId];

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
} elseif ($filterTab === 'missed') {
  $sql .= " AND (e.start_datetime < NOW() OR e.status = 'Completed') AND e.status != 'Cancelled'";
  if (!empty($myRegisteredEventIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
    $sql .= " AND e.id NOT IN ($inPlaceholders)";
    $params = array_merge($params, $myRegisteredEventIds);
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

// Counts for filter pills (strictly scoped to this barangay and created by Barangay Head)
$countUpcomingStmt = $db->prepare("
    SELECT COUNT(*) FROM events e 
    LEFT JOIN users u ON e.created_by = u.id 
    WHERE e.barangay_id = ? 
      AND e.start_datetime >= CURDATE() 
      AND e.status != 'Cancelled' 
      AND (e.is_archived = 0 OR e.is_archived IS NULL)
      AND (u.role = 'barangay_head' OR e.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
");
$countUpcomingStmt->execute([$barangayId, $barangayId]);
$countUpcoming = (int) $countUpcomingStmt->fetchColumn();
$countMyDrills = count($myRegisteredEventIds);

// Missed Events Count (Past events where resident did NOT register/attend)
$missedQuery = "
    SELECT COUNT(*) FROM events e 
    LEFT JOIN users u ON e.created_by = u.id 
    WHERE e.barangay_id = ? 
      AND (e.start_datetime < NOW() OR e.status = 'Completed')
      AND e.status != 'Cancelled'
      AND (e.is_archived = 0 OR e.is_archived IS NULL)
      AND (u.role = 'barangay_head' OR e.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
";
if (!empty($myRegisteredEventIds)) {
  $inPlaceholders = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
  $missedQuery .= " AND e.id NOT IN ($inPlaceholders)";
  $countMissedStmt = $db->prepare($missedQuery);
  $countMissedStmt->execute(array_merge([$barangayId, $barangayId], $myRegisteredEventIds));
} else {
  $countMissedStmt = $db->prepare($missedQuery);
  $countMissedStmt->execute([$barangayId, $barangayId]);
}
$countMissed = (int) $countMissedStmt->fetchColumn();

$pageTitle = "Community Events & Drills";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies for Venue Navigation -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

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
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    cursor: pointer;
    position: relative;
  }

  .resident-event-card:hover {
    border-color: var(--color-primary, #17324D);
    box-shadow: 0 4px 14px rgba(23, 50, 77, 0.08);
    transform: translateY(-1px);
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
    100% {
      transform: rotate(360deg);
    }
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
    from {
      opacity: 0;
    }

    to {
      opacity: 1;
    }
  }

  @keyframes venueSlideUp {
    from {
      opacity: 0;
      transform: translateY(18px) scale(0.98);
    }

    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
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
<div class="page-header"
  style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
  <div class="page-header-title-wrap">
    <h1 style="font-size:16px;font-weight:700;margin:0;">Community Events &amp; Disaster Drills</h1>
  </div>
  <div class="page-header-actions">
    <a href="evacuation.php" class="btn btn-secondary btn-sm"
      style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:5px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="3 11 22 2 13 21 11 13 3 11" />
      </svg>
      Evacuation Routing
    </a>
  </div>
</div>

<div class="resident-events-wrap">
  <!-- Filter Deck -->
  <div class="event-filter-deck">
    <form method="GET" class="event-search-row">
      <input type="text" name="q" value="<?= clean($searchQuery) ?>"
        placeholder="Search event title, drill type, or venue..." class="event-search-input">
      <?php if (!empty($filterTab)): ?><input type="hidden" name="tab" value="<?= clean($filterTab) ?>"><?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm" style="padding:0 14px;font-size:11px;">Search</button>
      <?php if (!empty($searchQuery)): ?>
        <a href="events.php?tab=<?= clean($filterTab) ?>" class="btn btn-outline btn-sm"
          style="padding:0 10px;font-size:11px;display:flex;align-items:center;">Reset</a>
      <?php endif; ?>
    </form>

    <div class="event-pills-row">
      <a href="events.php?tab=upcoming" class="event-pill-link <?= ($filterTab === 'upcoming') ? 'active' : '' ?>">
        Upcoming Events (<?= $countUpcoming ?>)
      </a>
      <a href="events.php?tab=my_drills" class="event-pill-link <?= ($filterTab === 'my_drills') ? 'active' : '' ?>">
        My Registered Drills (<?= $countMyDrills ?>)
      </a>
      <a href="events.php?tab=missed" class="event-pill-link <?= ($filterTab === 'missed') ? 'active' : '' ?>">
        Missed Events (<?= $countMissed ?>)
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
          $eventId = (int) $act['id'];
          $isAttending = in_array($eventId, $myRegisteredEventIds, true);
          $startDt = strtotime($act['start_datetime']);
          $endDt = strtotime($act['end_datetime']);
          $target = max(1, (int) $act['target_participants']);
          $actual = (int) $act['actual_participants'];
          $pctAtt = min(100, round(($actual / $target) * 100));
          $isPast = ($startDt < time());
          $isCancelled = ($act['status'] === 'Cancelled');
          $isMissed = ($isPast && !$isAttending && !$isCancelled);

          $statusLabel = $isCancelled ? 'Cancelled' : ($isMissed ? 'Missed Event' : ($isPast ? 'Completed' : 'Upcoming'));
          $attendanceLabel = $isMissed ? 'Missed (Concluded)' : ($isAttending ? 'Registered' : 'Not Registered');
          ?>
          <div class="resident-event-card" id="event-card-<?= $eventId ?>"
            onclick="openEventDetailsModal(this)"
            data-id="<?= $eventId ?>"
            data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>"
            data-type="<?= htmlspecialchars($act['activity_type'], ENT_QUOTES) ?>"
            data-status="<?= $statusLabel ?>"
            data-is-missed="<?= $isMissed ? '1' : '0' ?>"
            data-is-past="<?= $isPast ? '1' : '0' ?>"
            data-date="<?= htmlspecialchars(date('M d, Y', $startDt), ENT_QUOTES) ?>"
            data-time="<?= htmlspecialchars(date('h:i A', $startDt) . ' – ' . date('h:i A', $endDt), ENT_QUOTES) ?>"
            data-venue="<?= htmlspecialchars($act['venue'], ENT_QUOTES) ?>"
            data-barangay="<?= htmlspecialchars($act['barangay_name'] ?? '', ENT_QUOTES) ?>"
            data-organizer="<?= htmlspecialchars($act['organizer_name'] ?? 'Hon. Rodrigo Almonte (Barangay Head)', ENT_QUOTES) ?>"
            data-turnout="<?= number_format($actual) ?> / <?= number_format($target) ?> Registered"
            data-attendance="<?= $attendanceLabel ?>"
            data-desc="<?= htmlspecialchars($act['description'] ?? '', ENT_QUOTES) ?>"
            role="button"
            tabindex="0"
            aria-label="View event details for <?= htmlspecialchars($act['title'], ENT_QUOTES) ?>">
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
                  <div>
                    <?php if ($isMissed): ?>
                      <span class="badge" style="font-size:10px;padding:3px 7px;background:#FFF3CD;color:#856404;font-weight:600;border:1px solid #FFEEBA;">Missed Event</span>
                    <?php elseif ($isPast && $isAttending): ?>
                      <span class="badge" style="font-size:10px;padding:3px 7px;background:#E8F5E9;color:#2E7D32;font-weight:600;border:1px solid #C8E6C9;">Attended</span>
                    <?php else: ?>
                      <?= renderStatusBadge($act['status']) ?>
                    <?php endif; ?>
                  </div>
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
                    <div class="event-attendee-fill" id="attendee-fill-<?= $eventId ?>" style="width:<?= $pctAtt ?>%;">
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Action Bar with Venue Navigation & RSVP Button -->
            <div class="event-action-bar">
              <button type="button" class="btn-navigate-venue" onclick="event.stopPropagation(); openVenueModal(this)"
                data-event-id="<?= $eventId ?>" data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>"
                data-venue="<?= htmlspecialchars($act['venue'], ENT_QUOTES) ?>"
                data-barangay="<?= htmlspecialchars($act['barangay_name'] ?? '', ENT_QUOTES) ?>"
                data-lat="<?= (float) ($act['latitude'] ?? 0) ?>" data-lng="<?= (float) ($act['longitude'] ?? 0) ?>"
                data-time="<?= htmlspecialchars(date('h:i A', $startDt) . ' – ' . date('h:i A', $endDt), ENT_QUOTES) ?>"
                data-date="<?= htmlspecialchars(date('M d, Y', $startDt), ENT_QUOTES) ?>">
                <span class="btn-nav-icon">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="3 11 22 2 13 21 11 13 3 11" />
                  </svg>
                </span>
                <span class="btn-nav-label">Venue Map &rarr;</span>
              </button>

              <?php if ($isMissed): ?>
                <span style="font-size:10.5px;color:#856404;font-weight:600;">Missed Activity</span>
              <?php elseif ($isCancelled || $isPast): ?>
                <span class="badge badge-neutral" style="font-size:10px;">
                  <?= $isCancelled ? 'Event Cancelled' : 'Completed Activity' ?>
                </span>
              <?php else: ?>
                <div style="display:flex;align-items:center;gap:8px;">
                  <button type="button" id="rsvp-btn-<?= $eventId ?>"
                    class="rsvp-toggle-btn <?= $isAttending ? 'registered' : 'attend' ?>"
                    onclick="event.stopPropagation(); toggleRsvp(<?= $eventId ?>)">
                    <?php if ($isAttending): ?>
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12" />
                      </svg>
                      Attending (Registered)
                    <?php else: ?>
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                      </svg>
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
        <div
          style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
          <h3
            style="font-family:var(--font-primary);font-size:12.5px;font-weight:700;color:var(--color-primary);margin:0;">
            Community Action Protocols
          </h3>
          <span class="badge badge-primary" style="font-size:9.5px;">BDRRMC Standard</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;">
          <div class="protocol-item">
            <div class="protocol-bullet"></div>
            <div>
              <strong>Earthquake: "Duck, Cover & Hold"</strong><br>
              Drop down onto your hands and knees. Cover your head and neck under a sturdy table. Hold on until shaking
              ceases completely.
            </div>
          </div>

          <div class="protocol-item">
            <div class="protocol-bullet"></div>
            <div>
              <strong>Flood Warning & River Alert:</strong><br>
              When Mandulog river siren sounds (Red Warning), immediately proceed to your designated barangay
              high-ground shelter.
            </div>
          </div>

          <div class="protocol-item">
            <div class="protocol-bullet"></div>
            <div>
              <strong>Family Go-Bag Check:</strong><br>
              Ensure your 72-hour survival kit contains drinking water, ready-to-eat canned goods, first-aid medicine,
              and emergency whistle.
            </div>
          </div>

          <div class="protocol-item">
            <div class="protocol-bullet"></div>
            <div>
              <strong>Toll-Free Emergency Hotline:</strong><br>
              In any immediate distress or life-threatening scenario, dial <strong
                style="color:var(--color-danger);">161</strong> (ICDRRMO Command).
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

    const title = btn.getAttribute('data-title') || 'Community Event';
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

    // Custom Red Destination Pin
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

  // ============================================================================
  // Event Details Direct Modal Logic (Clean, Direct, Single Restrained Palette)
  // ============================================================================
  function openEventDetailsModal(cardEl) {
    if (!cardEl) return;

    const title = cardEl.getAttribute('data-title') || '';
    const type = cardEl.getAttribute('data-type') || '';
    const status = cardEl.getAttribute('data-status') || '';
    const date = cardEl.getAttribute('data-date') || '';
    const time = cardEl.getAttribute('data-time') || '';
    const venue = cardEl.getAttribute('data-venue') || '';
    const barangay = cardEl.getAttribute('data-barangay') || '';
    const organizer = cardEl.getAttribute('data-organizer') || 'Hon. Rodrigo Almonte (Barangay Head)';
    const attendance = cardEl.getAttribute('data-attendance') || '';
    const desc = cardEl.getAttribute('data-desc') || 'No additional details provided.';
    const isMissed = cardEl.getAttribute('data-is-missed') === '1';
    const isPast = cardEl.getAttribute('data-is-past') === '1';

    const titleEl = document.getElementById('detailsModalTitle');
    if (titleEl) titleEl.textContent = title;

    const typeEl = document.getElementById('detailsModalType');
    if (typeEl) typeEl.textContent = type;

    const statusBadge = document.getElementById('detailsModalStatusBadge');
    if (statusBadge) {
      statusBadge.textContent = status;
      if (isMissed) {
        statusBadge.style.color = '#856404';
        statusBadge.style.background = '#FFF3CD';
        statusBadge.style.border = '1px solid #FFEEBA';
      } else if (isPast) {
        statusBadge.style.color = '#2E7D32';
        statusBadge.style.background = '#E8F5E9';
        statusBadge.style.border = '1px solid #C8E6C9';
      } else {
        statusBadge.style.color = '#17324D';
        statusBadge.style.background = '#EBF3F5';
        statusBadge.style.border = '1px solid #D9E0E3';
      }
    }

    const dateTimeEl = document.getElementById('detailsModalDateTime');
    if (dateTimeEl) dateTimeEl.textContent = `${date} • ${time}`;

    const venueEl = document.getElementById('detailsModalVenue');
    if (venueEl) venueEl.textContent = barangay ? `${venue}, Brgy. ${barangay}` : venue;

    const organizerEl = document.getElementById('detailsModalOrganizer');
    if (organizerEl) organizerEl.textContent = organizer;

    const attendanceEl = document.getElementById('detailsModalAttendance');
    if (attendanceEl) {
      attendanceEl.textContent = attendance;
      if (isMissed) {
        attendanceEl.style.color = '#856404';
      } else if (attendance.includes('Registered') && !attendance.includes('Not')) {
        attendanceEl.style.color = '#2E7D32';
      } else {
        attendanceEl.style.color = '#66737D';
      }
    }

    const descEl = document.getElementById('detailsModalDesc');
    if (descEl) descEl.textContent = desc;

    const modal = document.getElementById('eventDetailsModal');
    if (modal) {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  }

  function closeEventDetailsModal() {
    const modal = document.getElementById('eventDetailsModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  // ESC key to close any active modal
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeVenueModal();
      closeEventDetailsModal();
    }
  });
</script>

<!-- Event Details Modal (Direct, Focused, Single Unified Palette, No Icon Clutter) -->
<div id="eventDetailsModal" class="venue-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="detailsModalHeading">
  <div class="venue-modal-backdrop" onclick="closeEventDetailsModal()"></div>
  <div class="venue-modal-container" style="max-width:520px;">
    <!-- Modal Header -->
    <div class="venue-modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-primary" style="font-size:9.5px;padding:3px 7px;background:var(--color-primary);color:#FFF;">EVENT DETAILS</span>
        <h3 id="detailsModalHeading" class="venue-modal-title" style="font-size:13px;font-weight:600;margin:0;">Full Information</h3>
      </div>
      <button type="button" class="venue-modal-close-btn" onclick="closeEventDetailsModal()" aria-label="Close Modal">&times;</button>
    </div>

    <!-- Modal Content -->
    <div style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;background:#FFFFFF;">
      <!-- Title & Type Header -->
      <div>
        <h2 id="detailsModalTitle" style="font-family:var(--font-primary);font-size:15px;font-weight:700;color:var(--color-primary);line-height:1.35;margin:0 0 6px 0;"></h2>
        <div style="font-size:11px;color:var(--color-text-secondary);display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <span id="detailsModalType" style="font-weight:600;color:var(--color-primary);"></span>
          <span>&bull;</span>
          <span id="detailsModalStatusBadge" style="font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600;"></span>
        </div>
      </div>

      <!-- Essential Key Facts (Clean 2-column grid, neutral styling, direct) -->
      <div style="background:#F8FAFB;border:1px solid #E7EDF0;border-radius:8px;padding:12px 14px;display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;font-size:11px;">
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Date &amp; Schedule</div>
          <div id="detailsModalDateTime" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Location</div>
          <div id="detailsModalVenue" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Organizer</div>
          <div id="detailsModalOrganizer" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Your Status</div>
          <div id="detailsModalAttendance" style="font-weight:600;"></div>
        </div>
      </div>

      <!-- Description / Direct Guidelines -->
      <div>
        <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;margin-bottom:4px;">Details &amp; Guidelines</div>
        <div id="detailsModalDesc" style="font-size:11.5px;line-height:1.5;color:#2C3E50;white-space:pre-line;"></div>
      </div>

      <!-- Footer Action -->
      <div style="display:flex;justify-content:flex-end;padding-top:8px;border-top:1px solid #E7EDF0;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeEventDetailsModal()" style="font-size:11px;padding:6px 14px;">Close</button>
      </div>
    </div>
  </div>
</div>

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