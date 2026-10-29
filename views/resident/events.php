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
$userPurok = trim($user['purok'] ?? '');
$db = getDBConnection();

// Fetch Resident's Barangay and Purok coordinates for 100% accurate origin in venue navigation
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

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

$filterTab = $_GET['tab'] ?? 'upcoming';
if (!in_array($filterTab, ['upcoming', 'attending', 'missed', 'completed'], true)) {
  $filterTab = 'upcoming';
}
$searchQuery = trim($_GET['q'] ?? '');

// Fetch Resident Details & Registered / Attended Event IDs
$userPhone = trim($user['phone'] ?? '');
$userName = trim($user['full_name'] ?? '');

$myAttendedStmt = $db->prepare("
    SELECT activity_id 
    FROM event_attendees 
    WHERE (is_archived = 0 OR is_archived IS NULL)
      AND attended = 1
      AND (
          (user_id = ? AND (resident_name = ? OR (phone != '' AND phone = ?)))
          OR (phone != '' AND phone = ?)
      )
");
$myAttendedStmt->execute([$userId, $userName, $userPhone, $userPhone]);
$myAttendedEventIds = array_map('intval', $myAttendedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$myRsvpStmt = $db->prepare("
    SELECT activity_id 
    FROM event_attendees 
    WHERE (is_archived = 0 OR is_archived IS NULL)
      AND (
          (user_id = ? AND (resident_name = ? OR (phone != '' AND phone = ?)))
          OR (phone != '' AND phone = ?)
      )
");
$myRsvpStmt->execute([$userId, $userName, $userPhone, $userPhone]);
$myRegisteredEventIds = array_map('intval', $myRsvpStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

// Base Query: Dynamic Purok Scoping & Barangay Scoping
// If All Puroks -> visible to all residents; if specific puroks -> only residents whose purok is included
$purokClause = "
    e.target_puroks IS NULL
    OR TRIM(e.target_puroks) = ''
    OR LOWER(TRIM(e.target_puroks)) IN ('all puroks', 'all')
    OR LOWER(e.target_puroks) LIKE '%all purok%'
";
$baseParams = [$barangayId];
if (!empty($userPurok)) {
  $purokClause .= " OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(REPLACE(e.target_puroks, ', ', ','), ' ,', ','))) > 0";
  $baseParams[] = $userPurok;
}
if (!empty($myRegisteredEventIds)) {
  $inRsvpPlaceholders = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
  $purokClause .= " OR e.id IN ($inRsvpPlaceholders)";
  $baseParams = array_merge($baseParams, $myRegisteredEventIds);
}

$baseWhere = "
    (e.barangay_id = ? OR e.barangay_id IS NULL)
    AND (e.is_archived = 0 OR e.is_archived IS NULL)
    AND ({$purokClause})
";

// 1. All Upcoming Events Count
$countUpcomingStmt = $db->prepare("
    SELECT COUNT(*) FROM events e 
    LEFT JOIN users u ON e.created_by = u.id 
    WHERE {$baseWhere}
      AND (e.start_datetime >= NOW() OR e.status = 'Scheduled') 
      AND e.status != 'Cancelled'
");
$countUpcomingStmt->execute($baseParams);
$countUpcoming = (int) $countUpcomingStmt->fetchColumn();

// 2. Attending Count (Upcoming registered events only)
$countAttending = 0;
if (!empty($myRegisteredEventIds)) {
  $inAttending = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
  $countAttStmt = $db->prepare("
      SELECT COUNT(*) FROM events e 
      LEFT JOIN users u ON e.created_by = u.id 
      WHERE {$baseWhere}
        AND e.id IN ($inAttending)
        AND (e.start_datetime >= NOW() OR e.status = 'Scheduled') 
        AND e.status != 'Cancelled'
  ");
  $countAttStmt->execute(array_merge($baseParams, $myRegisteredEventIds));
  $countAttending = (int) $countAttStmt->fetchColumn();
}

// 3. Missed Events Count & ALL Missed Events (100% dynamic, all missed events included)
$missedWhere = "
    {$baseWhere} 
    AND (e.start_datetime < NOW() OR e.end_datetime < NOW() OR e.status IN ('Completed', 'Ended', 'Finished', 'Concluded')) 
    AND e.status != 'Cancelled'
";
$missedParams = $baseParams;
if (!empty($myAttendedEventIds)) {
  $inMissedRsvp = implode(',', array_fill(0, count($myAttendedEventIds), '?'));
  $missedWhere .= " AND e.id NOT IN ($inMissedRsvp)";
  $missedParams = array_merge($missedParams, $myAttendedEventIds);
}

$countMissedStmt = $db->prepare("
    SELECT COUNT(*) FROM events e 
    LEFT JOIN users u ON e.created_by = u.id 
    WHERE {$missedWhere}
");
$countMissedStmt->execute($missedParams);
$countMissed = (int) $countMissedStmt->fetchColumn();

// Fetch ALL missed events dynamically (no LIMIT 1) so resident sees all missed events
$allMissedEvents = [];
if ($countMissed > 0) {
  $allMissedStmt = $db->prepare("
      SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name
      FROM events e
      LEFT JOIN barangays b ON e.barangay_id = b.id
      LEFT JOIN users u ON e.created_by = u.id
      WHERE {$missedWhere}
      ORDER BY e.start_datetime DESC
  ");
  $allMissedStmt->execute($missedParams);
  $allMissedEvents = $allMissedStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. Completed Events Count (Past attended events)
$countCompleted = 0;
if (!empty($myAttendedEventIds)) {
  $inCompleted = implode(',', array_fill(0, count($myAttendedEventIds), '?'));
  $countCompStmt = $db->prepare("
      SELECT COUNT(*) FROM events e 
      LEFT JOIN users u ON e.created_by = u.id 
      WHERE {$baseWhere}
        AND e.id IN ($inCompleted)
        AND (e.start_datetime < NOW() OR e.end_datetime < NOW() OR e.status IN ('Completed', 'Ended', 'Finished', 'Concluded'))
        AND e.status != 'Cancelled'
  ");
  $countCompStmt->execute(array_merge($baseParams, $myAttendedEventIds));
  $countCompleted = (int) $countCompStmt->fetchColumn();
}

// Build Active Feed Query according to filter tab
$feedSql = "
    SELECT e.*, b.name AS barangay_name, u.full_name AS organizer_name
    FROM events e
    LEFT JOIN barangays b ON e.barangay_id = b.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE {$baseWhere}
";
$feedParams = $baseParams;

if ($filterTab === 'upcoming') {
  $feedSql .= " AND (e.start_datetime >= NOW() OR e.status = 'Scheduled') AND e.status != 'Cancelled' ORDER BY e.start_datetime ASC";
} elseif ($filterTab === 'attending') {
  if (!empty($myRegisteredEventIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($myRegisteredEventIds), '?'));
    $feedSql .= " AND e.id IN ($inPlaceholders) AND (e.start_datetime >= NOW() OR e.status = 'Scheduled') AND e.status != 'Cancelled' ORDER BY e.start_datetime ASC";
    $feedParams = array_merge($feedParams, $myRegisteredEventIds);
  } else {
    $feedSql .= " AND 1=0";
  }
} elseif ($filterTab === 'missed') {
  $feedSql .= " AND (e.start_datetime < NOW() OR e.end_datetime < NOW() OR e.status IN ('Completed', 'Ended', 'Finished', 'Concluded')) AND e.status != 'Cancelled'";
  if (!empty($myAttendedEventIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($myAttendedEventIds), '?'));
    $feedSql .= " AND e.id NOT IN ($inPlaceholders)";
    $feedParams = array_merge($feedParams, $myAttendedEventIds);
  }
  $feedSql .= " ORDER BY e.start_datetime DESC";
} elseif ($filterTab === 'completed') {
  if (!empty($myAttendedEventIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($myAttendedEventIds), '?'));
    $feedSql .= " AND e.id IN ($inPlaceholders) AND (e.start_datetime < NOW() OR e.end_datetime < NOW() OR e.status IN ('Completed', 'Ended', 'Finished', 'Concluded')) AND e.status != 'Cancelled' ORDER BY e.start_datetime DESC";
    $feedParams = array_merge($feedParams, $myAttendedEventIds);
  } else {
    $feedSql .= " AND 1=0";
  }
}

if (!empty($searchQuery)) {
  $feedSql .= " AND (e.title LIKE ? OR e.venue LIKE ? OR e.activity_type LIKE ?)";
  $like = "%{$searchQuery}%";
  $feedParams[] = $like;
  $feedParams[] = $like;
  $feedParams[] = $like;
}

$stmt = $db->prepare($feedSql);
$stmt->execute($feedParams);
$eventsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Community Events & Drills";
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Map Dependencies for Venue Navigation -->
<link rel="stylesheet" href="<?= BASE_URL ?>/frontend/vendor/leaflet/leaflet.css" />
<script src="<?= BASE_URL ?>/frontend/vendor/leaflet/leaflet.js"></script>

<style>
  /* ==========================================================================
   Resident Events & Drills: Mobile-First Priority & Clean Design System
   Strict 10px radius, standard Poppins/Roboto typography, crisp color tokens
   ========================================================================== */
  .resident-events-wrap {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 12px);
    width: 100%;
  }

  /* Missed Events Alert Container & Cards */
  .missed-events-container {
    background: #FFFDF5;
    border: 1px solid #FFE69C;
    border-radius: var(--radius-primary, 10px);
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.08);
  }

  .missed-events-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    border-bottom: 1px solid #FDF0CD;
    padding-bottom: 8px;
  }

  .missed-events-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    max-height: 145px;
    overflow-y: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: #F59E0B #FFF8E7;
  }

  .missed-events-list::-webkit-scrollbar {
    width: 6px;
  }

  .missed-events-list::-webkit-scrollbar-track {
    background: #FFF8E7;
    border-radius: 4px;
  }

  .missed-events-list::-webkit-scrollbar-thumb {
    background: #F59E0B;
    border-radius: 4px;
  }

  .missed-events-list::-webkit-scrollbar-thumb:hover {
    background: #D97706;
  }

  .missed-alert-item {
    background: #FFFFFF;
    border: 1px solid #FFE69C;
    border-left: 4px solid #F59E0B;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    cursor: pointer;
    min-height: 54px;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  }

  .missed-alert-item:hover {
    transform: translateY(-1px);
    border-color: #F59E0B;
    box-shadow: 0 3px 10px rgba(245, 158, 11, 0.12);
  }

  .missed-alert-item.single-item {
    grid-column: 1 / -1;
  }

  @media (max-width: 680px) {
    .missed-events-list {
      grid-template-columns: 1fr;
      max-height: 155px;
    }
  }

  /* Backwards compatibility for single banner */
  .missed-alert-banner {
    background: #FFFDF5;
    border: 1px solid #FFE69C;
    border-left: 4px solid #F59E0B;
    border-radius: var(--radius-primary, 10px);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.08);
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }

  .missed-alert-banner:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
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
    transition: all 0.15s ease;
  }

  .event-pill-link:hover {
    border-color: var(--color-primary, #17324D);
    color: var(--color-primary, #17324D);
  }

  .event-pill-link.active {
    background: var(--color-primary, #17324D);
    border-color: var(--color-primary, #17324D);
    color: #FFFFFF;
    font-weight: 600;
  }

  .event-pill-link.pill-missed.active {
    background: #856404;
    border-color: #856404;
    color: #FFFFFF;
  }

  /* Events Feed Container */
  .event-layout-split {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 12px);
    width: 100%;
  }

  /* Event Card Item - Distinct Clickable Card */
  .resident-event-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-primary, 10px);
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s ease, border-color 0.18s ease;
    cursor: pointer;
    position: relative;
    user-select: none;
  }

  .resident-event-card:hover {
    border-color: var(--color-primary, #17324D);
    box-shadow: 0 6px 18px rgba(23, 50, 77, 0.09);
    transform: translateY(-2px);
  }

  .resident-event-card:active {
    transform: translateY(0);
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

  .event-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 2px;
  }

  .event-title {
    font-family: var(--font-primary, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    color: var(--color-primary, #17324D);
    margin: 0;
    line-height: 1.35;
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

  /* Action Bar with Venue Navigation & RSVP Button */
  .event-action-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--color-border-light, #E7EDF0);
    padding-top: 10px;
    margin-top: 4px;
    gap: 8px;
    flex-wrap: wrap;
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
    gap: 6px;
    min-height: 34px;
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

  /* Cancel Join Button - Restrained, clean, accessible */
  .btn-cancel-join {
    font-family: var(--font-primary, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 600;
    border-radius: var(--radius-sm, 6px);
    padding: 6px 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 34px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    color: #C62828;
    transition: all 0.15s ease;
  }

  .btn-cancel-join:hover {
    background: #FEF2F2;
    border-color: #F87171;
    color: #991B1B;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(198, 40, 40, 0.12);
  }

  .btn-cancel-join:active {
    transform: translateY(0);
  }

  .btn-cancel-join:disabled {
    opacity: 0.6;
    pointer-events: none;
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
    gap: 6px;
    min-height: 34px;
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

  /* Clean Modal Overlay & Backdrop - Unified System Architecture */
  .custom-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: modalFadeIn 0.2s ease;
  }

  .custom-modal-dialog {
    position: relative;
    width: 100%;
    max-width: 600px;
    background: #FFFFFF;
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-primary, 10px);
    box-shadow: 0 20px 48px rgba(15, 23, 42, 0.35);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 90vh;
  }

  @keyframes modalFadeIn {
    from {
      opacity: 0;
    }

    to {
      opacity: 1;
    }
  }

  @keyframes modalSlideUp {
    from {
      opacity: 0;
      transform: translateY(16px) scale(0.98);
    }

    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }

  .custom-modal-header {
    padding: 12px 18px;
    background: #F8FAFB;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .custom-modal-title {
    margin: 0;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--color-primary, #17324D);
  }

  .custom-modal-close-btn {
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: var(--color-text-muted, #8A96A0);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    transition: color 0.15s ease;
  }

  .custom-modal-close-btn:hover {
    color: var(--color-danger, #C62828);
  }

  /* Venue Map Wrap */
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
    width: 30px;
    height: 30px;
    border: 3px solid #D9E0E3;
    border-top-color: var(--color-secondary, #2F6F73);
    border-radius: 50%;
    animation: venueSpin 0.7s linear infinite;
  }
</style>

<div class="resident-events-wrap">

  <!-- Attention Notice for Missed Community Activities: Shows ALL missed events dynamically -->
  <?php if ($countMissed > 0 && !empty($allMissedEvents)): ?>
    <div class="missed-events-container">
      <div class="missed-events-header">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span class="badge"
            style="background:#FFF3CD;color:#856404;border:1px solid #FFEEBA;font-weight:700;font-size:10px;padding:3px 8px;white-space:nowrap;">
            MISSED ACTIVITIES (<?= $countMissed ?>)
          </span>
          <span style="font-size:12px;font-weight:600;color:#24313A;">
            You missed <?= $countMissed ?> concluded <?= $countMissed === 1 ? 'activity' : 'activities' ?> scheduled for
            <?= !empty($userPurok) ? clean($userPurok) : 'your barangay' ?>:
          </span>
        </div>
        <a href="events.php?tab=missed" class="btn btn-sm"
          style="font-size:10.5px;padding:3px 10px;background:#FFFFFF;border:1px solid #D9E0E3;color:var(--color-primary);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
          View in Missed Tab
        </a>
      </div>

      <div class="missed-events-list">
        <?php foreach ($allMissedEvents as $mEvt):
          $mStart = strtotime($mEvt['start_datetime']);
          $mEnd = strtotime($mEvt['end_datetime']);
          ?>
          <div class="missed-alert-item <?= count($allMissedEvents) === 1 ? 'single-item' : '' ?>"
            onclick="openEventDetailsModal(this)" data-id="<?= (int) $mEvt['id'] ?>"
            data-title="<?= htmlspecialchars($mEvt['title'], ENT_QUOTES) ?>"
            data-type="<?= htmlspecialchars($mEvt['activity_type'] ?? 'Disaster Drill', ENT_QUOTES) ?>"
            data-status="Missed Event" data-is-missed="1" data-is-past="1"
            data-date="<?= htmlspecialchars(date('M d, Y', $mStart), ENT_QUOTES) ?>"
            data-time="<?= htmlspecialchars(date('h:i A', $mStart) . ' – ' . date('h:i A', $mEnd), ENT_QUOTES) ?>"
            data-venue="<?= htmlspecialchars($mEvt['venue'], ENT_QUOTES) ?>"
            data-barangay="<?= htmlspecialchars($mEvt['barangay_name'] ?? '', ENT_QUOTES) ?>"
            data-lat="<?= (float) ($mEvt['latitude'] ?? 0) ?>" data-lng="<?= (float) ($mEvt['longitude'] ?? 0) ?>"
            data-organizer="<?= htmlspecialchars($mEvt['organizer_name'] ?? 'Hon. Rodrigo Almonte (Barangay Head)', ENT_QUOTES) ?>"
            data-turnout="<?= number_format((int) $mEvt['actual_participants']) ?> / <?= number_format(max(1, (int) $mEvt['target_participants'])) ?> Registered"
            data-attendance="Missed (Concluded)" data-desc="<?= htmlspecialchars($mEvt['description'] ?? '', ENT_QUOTES) ?>"
            role="button" tabindex="0">

            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
              <span class="badge"
                style="background:#FFF3CD;color:#856404;border:1px solid #FFEEBA;font-weight:700;font-size:9px;padding:2px 6px;white-space:nowrap;flex-shrink:0;">
                MISSED
              </span>
              <div style="font-size:11.5px;color:#24313A;line-height:1.35;overflow:hidden;min-width:0;flex:1;">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                  title="<?= htmlspecialchars($mEvt['title'], ENT_QUOTES) ?>">
                  <?= clean($mEvt['title']) ?>
                </div>
                <div style="color:#66737D;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  Scope: <strong><?= clean($mEvt['target_puroks'] ?: 'All Puroks') ?></strong> &bull;
                  <?= date('M d, Y', $mStart) ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

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
      <a href="events.php?tab=attending" class="event-pill-link <?= ($filterTab === 'attending') ? 'active' : '' ?>">
        Attending (<?= $countAttending ?>)
      </a>
      <a href="events.php?tab=missed"
        class="event-pill-link pill-missed <?= ($filterTab === 'missed') ? 'active' : '' ?>">
        Missed Events (<?= $countMissed ?>)
      </a>
      <a href="events.php?tab=completed" class="event-pill-link <?= ($filterTab === 'completed') ? 'active' : '' ?>">
        Completed Events (<?= $countCompleted ?>)
      </a>
    </div>
  </div>

  <!-- Responsive Layout Split -->
  <div class="event-layout-split">

    <!-- Left Column: Events Feed -->
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php if (empty($eventsList)): ?>
        <?php if ($filterTab === 'upcoming'): ?>
          <div class="resident-event-card"
            style="padding:36px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px;">
            <div style="font-size:14px;font-weight:700;color:var(--color-primary);">No Upcoming Events</div>
            <div style="font-size:11.5px;color:var(--color-text-secondary);max-width:440px;line-height:1.5;">
              There are currently no upcoming preparedness activities or aid distributions scheduled for your purok. Check
              back soon!
            </div>
          </div>
        <?php elseif ($filterTab === 'attending'): ?>
          <div class="resident-event-card"
            style="padding:36px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px;">
            <div style="font-size:14px;font-weight:700;color:var(--color-primary);">No Attending Events</div>
            <div style="font-size:11.5px;color:var(--color-text-secondary);max-width:440px;line-height:1.5;">
              You have not joined any upcoming drills or activities yet. You can browse and join events in the Upcoming
              Events tab.
            </div>
            <a href="events.php?tab=upcoming" class="btn btn-primary btn-sm"
              style="font-size:11px;padding:7px 18px;margin-top:6px;display:inline-flex;align-items:center;gap:6px;">
              Browse Upcoming Events &rarr;
            </a>
          </div>
        <?php elseif ($filterTab === 'missed'): ?>
          <div class="resident-event-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No missed events recorded. You are all caught up with your barangay drills!
          </div>
        <?php elseif ($filterTab === 'completed'): ?>
          <div class="resident-event-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No completed past events recorded yet.
          </div>
        <?php else: ?>
          <div class="resident-event-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No events match your current filter.
          </div>
        <?php endif; ?>
      <?php else: ?>
        <?php foreach ($eventsList as $act):
          $eventId = (int) $act['id'];
          $isAttending = in_array($eventId, $myRegisteredEventIds, true);
          $isAttended = in_array($eventId, $myAttendedEventIds, true);
          $startDt = strtotime($act['start_datetime']);
          $endDt = strtotime($act['end_datetime']);
          $target = max(1, (int) $act['target_participants']);
          $actual = (int) $act['actual_participants'];
          $pctAtt = min(100, round(($actual / $target) * 100));
          $isPast = ($startDt < time() || $endDt < time() || in_array($act['status'], ['Completed', 'Ended', 'Finished', 'Concluded'], true));
          $isCancelled = ($act['status'] === 'Cancelled');
          $isMissed = ($isPast && !$isAttended && !$isCancelled);

          $statusLabel = $isCancelled ? 'Cancelled' : ($isMissed ? 'Missed Event' : ($isAttended ? 'Completed' : ($isPast ? 'Concluded' : 'Upcoming')));
          $attendanceLabel = $isMissed ? 'Missed (Concluded)' : ($isAttended ? 'Attended (Completed)' : ($isAttending ? 'Registered' : 'Not Registered'));
          ?>
          <div class="resident-event-card" id="event-card-<?= $eventId ?>" onclick="openEventDetailsModal(this)"
            data-id="<?= $eventId ?>" data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>"
            data-type="<?= htmlspecialchars($act['activity_type'], ENT_QUOTES) ?>" data-status="<?= $statusLabel ?>"
            data-is-missed="<?= $isMissed ? '1' : '0' ?>" data-is-past="<?= $isPast ? '1' : '0' ?>"
            data-date="<?= htmlspecialchars(date('M d, Y', $startDt), ENT_QUOTES) ?>"
            data-time="<?= htmlspecialchars(date('h:i A', $startDt) . ' – ' . date('h:i A', $endDt), ENT_QUOTES) ?>"
            data-venue="<?= htmlspecialchars($act['venue'], ENT_QUOTES) ?>"
            data-barangay="<?= htmlspecialchars($act['barangay_name'] ?? '', ENT_QUOTES) ?>"
            data-organizer="<?= htmlspecialchars($act['organizer_name'] ?? 'Hon. Rodrigo Almonte (Barangay Head)', ENT_QUOTES) ?>"
            data-turnout="<?= number_format($actual) ?> / <?= number_format($target) ?> Registered"
            data-attendance="<?= $attendanceLabel ?>"
            data-desc="<?= htmlspecialchars($act['description'] ?? '', ENT_QUOTES) ?>" role="button" tabindex="0"
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
                <div class="event-title-row">
                  <h3 class="event-title"><?= clean($act['title']) ?></h3>
                  <div>
                    <?php if ($isMissed): ?>
                      <span class="badge"
                        style="font-size:10px;padding:3px 7px;background:#FFF3CD;color:#856404;font-weight:600;border:1px solid #FFEEBA;white-space:nowrap;">Missed
                        Event</span>
                    <?php elseif ($isAttended): ?>
                      <span class="badge"
                        style="font-size:10px;padding:3px 7px;background:#E8F5E9;color:#2E7D32;font-weight:600;border:1px solid #C8E6C9;white-space:nowrap;">Completed</span>
                    <?php elseif ($isAttending): ?>
                      <span class="badge"
                        style="font-size:10px;padding:3px 7px;background:#EAF4EB;color:#2E7D32;font-weight:600;border:1px solid #BCE3C1;white-space:nowrap;">Attending</span>
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
                data-date="<?= htmlspecialchars(date('M d, Y', $startDt), ENT_QUOTES) ?>" title="Navigate to Venue on Map">
                <span class="btn-nav-icon">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="3 11 22 2 13 21 11 13 3 11" />
                  </svg>
                </span>
                <span class="btn-nav-label">Navigate to Venue</span>
              </button>

              <div style="display:flex;align-items:center;gap:8px;">
                <span
                  style="font-size:10.5px;color:var(--color-secondary);font-weight:600;display:inline-flex;align-items:center;gap:3px;"
                  onclick="event.stopPropagation(); openEventDetailsModal(document.getElementById('event-card-<?= $eventId ?>'))">
                  View Details &rarr;
                </span>

                <?php if ($isMissed): ?>
                  <span
                    style="font-size:10.5px;color:#856404;font-weight:600;padding:4px 8px;background:#FFF3CD;border-radius:4px;border:1px solid #FFEEBA;">Missed
                    Activity</span>
                <?php elseif ($isCancelled): ?>
                  <span class="badge badge-neutral" style="font-size:10px;">Event Cancelled</span>
                <?php elseif ($isAttended): ?>
                  <span class="badge"
                    style="font-size:10px;background:#E8F5E9;color:#2E7D32;border:1px solid #C8E6C9;padding:3px 8px;font-weight:600;">Completed
                    Activity</span>
                <?php elseif ($isAttending): ?>
                  <button type="button" class="btn-cancel-join" id="cancel-btn-<?= $eventId ?>"
                    onclick="event.stopPropagation(); promptCancelJoin(<?= $eventId ?>, '<?= htmlspecialchars(addslashes($act['title']), ENT_QUOTES) ?>')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                      <line x1="18" y1="6" x2="6" y2="18"></line>
                      <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Cancel Join
                  </button>
                <?php else: ?>
                  <button type="button" class="btn btn-primary btn-sm" id="join-btn-<?= $eventId ?>"
                    style="font-size:10px;padding:5px 12px;border-radius:6px;"
                    onclick="event.stopPropagation(); handleResidentJoin(<?= $eventId ?>, this)">
                    Join Event
                  </button>
                <?php endif; ?>
              </div>
            </div>

          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================================ -->
<!-- Event Details Modal (Direct, Clean, Focused, Restrained Single Palette)       -->
<!-- ============================================================================ -->
<div id="eventDetailsModal" class="custom-modal-overlay" onclick="handleOverlayClick(event, 'eventDetailsModal')"
  role="dialog" aria-modal="true" aria-labelledby="detailsModalHeading">
  <div class="custom-modal-dialog" style="max-width:540px;" onclick="event.stopPropagation()">
    <!-- Modal Header -->
    <div class="custom-modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-primary"
          style="font-size:9.5px;padding:3px 7px;background:var(--color-primary);color:#FFF;">EVENT DETAILS</span>
        <h3 id="detailsModalHeading" class="custom-modal-title" style="font-size:13px;font-weight:600;margin:0;">Full
          Information</h3>
      </div>
      <button type="button" class="custom-modal-close-btn" onclick="closeEventDetailsModal()"
        aria-label="Close Modal">&times;</button>
    </div>

    <!-- Modal Content -->
    <div
      style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;background:#FFFFFF;overflow-y:auto;max-height:calc(85vh - 100px);">
      <!-- Title & Type Header -->
      <div>
        <h2 id="detailsModalTitle"
          style="font-family:var(--font-primary);font-size:15px;font-weight:700;color:var(--color-primary);line-height:1.35;margin:0 0 6px 0;">
        </h2>
        <div
          style="font-size:11px;color:var(--color-text-secondary);display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <span id="detailsModalType" style="font-weight:600;color:var(--color-primary);"></span>
          <span>&bull;</span>
          <span id="detailsModalStatusBadge"
            style="font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600;"></span>
        </div>
      </div>

      <!-- Essential Key Facts (Clean 2-column grid, direct) -->
      <div
        style="background:#F8FAFB;border:1px solid #E7EDF0;border-radius:8px;padding:12px 14px;display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;font-size:11px;">
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Date
            &amp; Schedule</div>
          <div id="detailsModalDateTime" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Location
          </div>
          <div id="detailsModalVenue" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">
            Organizer</div>
          <div id="detailsModalOrganizer" style="font-weight:600;color:#17324D;"></div>
        </div>
        <div>
          <div style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:600;margin-bottom:2px;">Your
            Status</div>
          <div id="detailsModalAttendance" style="font-weight:600;"></div>
        </div>
      </div>

      <!-- Description / Direct Guidelines -->
      <div>
        <div
          style="font-size:10px;color:#71828D;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;margin-bottom:4px;">
          Details &amp; Guidelines</div>
        <div id="detailsModalDesc" style="font-size:11.5px;line-height:1.5;color:#2C3E50;white-space:pre-line;"></div>
      </div>

      <!-- Footer Action -->
      <div
        style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid #E7EDF0;flex-wrap:wrap;gap:8px;">
        <div id="detailsModalActionSlot"></div>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeEventDetailsModal()"
          style="font-size:11px;padding:6px 16px;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================================ -->
<!-- Venue Navigation Leaflet Modal                                               -->
<!-- ============================================================================ -->
<div id="venueNavModal" class="custom-modal-overlay" onclick="handleOverlayClick(event, 'venueNavModal')" role="dialog"
  aria-modal="true" aria-labelledby="venueModalTitle">
  <div class="custom-modal-dialog" style="max-width:720px;" onclick="event.stopPropagation()">
    <div class="custom-modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-primary"
          style="font-size:9.5px;padding:3px 7px;background:var(--color-primary);color:#FFF;">VENUE NAVIGATION</span>
        <h3 id="venueModalTitle" class="custom-modal-title">Venue Route &amp; Directions</h3>
      </div>
      <button type="button" class="custom-modal-close-btn" onclick="closeVenueModal()"
        aria-label="Close Modal">&times;</button>
    </div>

    <!-- Venue & Origin Details Bar -->
    <div
      style="padding:10px 18px;background:#FFFFFF;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--color-primary);" id="venueModalVenueName">Venue Name
        </div>
        <div style="font-size:10.5px;color:var(--color-text-secondary);" id="venueModalEventSub">Event Title &bull;
          Schedule</div>
      </div>
      <div>
        <span
          style="font-size:10px;background:#F0F4F8;border:1px solid #D9E2EC;padding:3px 8px;border-radius:4px;font-weight:600;color:#17324D;"
          id="venueModalOriginLabel">
          Origin:
          <?= !empty($residentPurokName) ? 'Purok ' . htmlspecialchars($residentPurokName, ENT_QUOTES) . ', ' : '' ?>Brgy.
          <?= htmlspecialchars($barangay['name'] ?? 'Hinaplanon', ENT_QUOTES) ?>
        </span>
      </div>
    </div>

    <!-- Turn-by-Turn Route Navigation Stats Bar -->
    <div id="venueNavStatsBar"
      style="padding:8px 18px;background:#F8FAFB;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;flex-direction:column;">
          <span style="font-size:9px;color:#71828D;text-transform:uppercase;font-weight:700;">Street Distance</span>
          <span style="font-size:12.5px;font-weight:700;color:var(--color-primary);"
            id="venueRouteDist">Calculating...</span>
        </div>
        <div style="width:1px;height:22px;background:#E2E8F0;"></div>
        <div style="display:flex;flex-direction:column;">
          <span style="font-size:9px;color:#71828D;text-transform:uppercase;font-weight:700;">Est. Walk</span>
          <span style="font-size:12.5px;font-weight:700;color:var(--color-secondary);" id="venueRouteWalk">—</span>
        </div>
        <div style="width:1px;height:22px;background:#E2E8F0;"></div>
        <div style="display:flex;flex-direction:column;">
          <span style="font-size:9px;color:#71828D;text-transform:uppercase;font-weight:700;">Est. Drive</span>
          <span style="font-size:12.5px;font-weight:700;color:#17324D;" id="venueRouteDrive">—</span>
        </div>
        <div
          style="display:flex;align-items:center;gap:3px;padding:2px 7px;background:#E0F2F1;color:#00796B;border-radius:4px;font-size:9.5px;font-weight:600;"
          id="venueRouteBadge">
          <span>✓ 100% Street Route</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:6px;">
        <button type="button" id="venueGpsBtn" class="btn btn-outline btn-sm"
          style="font-size:9.5px;padding:3px 8px;border-radius:4px;" onclick="locateResidentForVenue()">
          📍 Use GPS
        </button>
        <button type="button" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:3px 8px;border-radius:4px;"
          onclick="fitVenueRoute()">
          🔍 Fit Path
        </button>
      </div>
    </div>

    <!-- Leaflet Map Container -->
    <div class="venue-map-wrap">
      <div id="venueLeafletMap" style="width:100%;height:380px;"></div>
      <div id="venueMapLoading" class="venue-map-loading-overlay">
        <div class="venue-spinner"></div>
        <span style="font-size:11px;font-weight:600;color:var(--color-primary);margin-top:8px;">Calculating 100%
          Accurate Route...</span>
      </div>
    </div>
  </div>
</div>

<script>
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Dynamic AJAX Cancel Attendance RSVP Handler
  let pendingCancelEventId = null;
  let pendingCancelEventTitle = '';

  function promptCancelJoin(eventId, eventTitle) {
    pendingCancelEventId = eventId;
    pendingCancelEventTitle = eventTitle;

    const modalTitle = document.getElementById('confirmModalTitle');
    const modalMsg = document.getElementById('confirmModalMessage');
    const submitBtn = document.getElementById('confirmModalSubmitBtn');

    if (modalTitle) modalTitle.textContent = 'Cancel Event Attendance';
    if (modalMsg) {
      modalMsg.innerHTML = `Are you sure you want to cancel your attendance for <strong>${escapeHtml(eventTitle)}</strong>?<br><span style="color:var(--color-text-secondary);font-size:11px;margin-top:4px;display:inline-block;">You can rejoin this event anytime from the Home dashboard.</span>`;
    }
    if (submitBtn) {
      submitBtn.className = 'btn btn-danger btn-sm';
      submitBtn.textContent = 'Yes, Cancel Join';
      submitBtn.onclick = executeCancelJoin;
    }

    if (typeof openModal === 'function') {
      openModal('confirmationModal');
    } else {
      const modal = document.getElementById('confirmationModal');
      if (modal) modal.classList.add('active');
    }
  }

  function executeCancelJoin() {
    if (!pendingCancelEventId) return;
    const eventId = pendingCancelEventId;

    if (typeof closeModal === 'function') {
      closeModal('confirmationModal');
    } else {
      const modal = document.getElementById('confirmationModal');
      if (modal) modal.classList.remove('active');
    }

    const cancelBtn = document.getElementById(`cancel-btn-${eventId}`);
    if (cancelBtn) {
      cancelBtn.disabled = true;
      cancelBtn.textContent = 'Cancelling...';
    }

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
        if (data.success && !data.is_attending) {
          const urlParams = new URLSearchParams(window.location.search);
          const currentTab = urlParams.get('tab') || 'upcoming';
          const card = document.getElementById(`event-card-${eventId}`);

          if (currentTab === 'attending') {
            if (card) {
              card.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
              card.style.opacity = '0';
              card.style.transform = 'scale(0.96) translateY(-8px)';
              setTimeout(() => {
                card.remove();
                const feedCards = document.querySelectorAll('.resident-events-wrap .resident-event-card');
                if (feedCards.length === 0) {
                  location.reload();
                }
              }, 300);
            }
          } else {
            // Swap Cancel Join back to Join Event button in place
            if (cancelBtn) {
              cancelBtn.outerHTML = `
                <button type="button" class="btn btn-primary btn-sm" id="join-btn-${eventId}" style="font-size:10px;padding:5px 12px;border-radius:6px;"
                  onclick="event.stopPropagation(); handleResidentJoin(${eventId}, this)">
                  Join Event
                </button>
              `;
            }
            if (card) {
              card.setAttribute('data-attendance', 'Not Registered');
              const statusEl = card.querySelector('.event-status-badge');
              if (statusEl) {
                statusEl.className = 'badge badge-outline event-status-badge';
                statusEl.textContent = 'Upcoming';
              }
            }
          }

          // Update attendee count meter if present
          const actualEl = document.getElementById(`actual-count-${eventId}`);
          if (actualEl && data.actual_participants !== undefined) {
            const m = actualEl.textContent.match(/\/ (\d+) Registered/);
            const target = m ? parseInt(m[1], 10) : 50;
            actualEl.textContent = `${data.actual_participants} / ${target} Registered`;
            const fillEl = document.getElementById(`attendee-fill-${eventId}`);
            if (fillEl && target > 0) {
              fillEl.style.width = Math.min(100, Math.round((data.actual_participants / target) * 100)) + '%';
            }
          }

          // Decrement Attending count pill
          const attPill = document.querySelector('.event-pills-row a[href*="tab=attending"]');
          if (attPill) {
            const m = attPill.textContent.match(/\((\d+)\)/);
            if (m) {
              const newCnt = Math.max(0, parseInt(m[1], 10) - 1);
              attPill.innerHTML = `Attending (${newCnt})`;
            }
          }

          // Close details modal if open
          closeEventDetailsModal();

          if (typeof showToast === 'function') {
            showToast(data.message || 'Cancelled attendance successfully.', 'info');
          } else {
            alert(data.message || 'Cancelled attendance successfully.');
          }
        } else {
          if (cancelBtn) {
            cancelBtn.disabled = false;
            cancelBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Cancel Join';
          }
          if (typeof showToast === 'function') {
            showToast(data.message || 'Failed to cancel attendance.', 'danger');
          } else {
            alert(data.message || 'Failed to cancel attendance.');
          }
        }
      })
      .catch(err => {
        if (cancelBtn) {
          cancelBtn.disabled = false;
          cancelBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Cancel Join';
        }
        console.error(err);
      });
  }

  function handleResidentJoin(eventId, btnElem) {
    const origHtml = btnElem.innerHTML;
    btnElem.disabled = true;
    btnElem.textContent = 'Joining...';

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
        if (data.success && data.is_attending) {
          const card = document.getElementById(`event-card-${eventId}`);
          const title = card ? card.getAttribute('data-title') : 'Event';

          btnElem.outerHTML = `
            <button type="button" class="btn-cancel-join" id="cancel-btn-${eventId}"
              onclick="event.stopPropagation(); promptCancelJoin(${eventId}, '${escapeHtml(title).replace(/'/g, "\\'")}')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
              Cancel Join
            </button>
          `;

          if (card) {
            card.setAttribute('data-attendance', 'Registered (Joined)');
            const statusEl = card.querySelector('.event-status-badge');
            if (statusEl) {
              statusEl.className = 'badge badge-primary event-status-badge';
              statusEl.textContent = '✓ Attending';
            }
          }

          // Update attendee count meter if present
          const actualEl = document.getElementById(`actual-count-${eventId}`);
          if (actualEl && data.actual_participants !== undefined) {
            const m = actualEl.textContent.match(/\/ (\d+) Registered/);
            const target = m ? parseInt(m[1], 10) : 50;
            actualEl.textContent = `${data.actual_participants} / ${target} Registered`;
            const fillEl = document.getElementById(`attendee-fill-${eventId}`);
            if (fillEl && target > 0) {
              fillEl.style.width = Math.min(100, Math.round((data.actual_participants / target) * 100)) + '%';
            }
          }

          // Increment Attending count pill
          const attPill = document.querySelector('.event-pills-row a[href*="tab=attending"]');
          if (attPill) {
            const m = attPill.textContent.match(/\((\d+)\)/);
            if (m) {
              const newCnt = parseInt(m[1], 10) + 1;
              attPill.innerHTML = `Attending (${newCnt})`;
            }
          }

          if (typeof showToast === 'function') {
            showToast(data.message || 'Successfully joined event!', 'success');
          } else {
            alert(data.message || 'Successfully joined event!');
          }
        } else {
          btnElem.disabled = false;
          btnElem.innerHTML = origHtml;
          if (typeof showToast === 'function') {
            showToast(data.message || 'Could not join event.', 'danger');
          } else {
            alert(data.message || 'Could not join event.');
          }
        }
      })
      .catch(err => {
        btnElem.disabled = false;
        btnElem.innerHTML = origHtml;
        console.error(err);
      });
  }
  window.handleResidentJoin = handleResidentJoin;

  function handleResidentJoinFromModal(eventId, btnElem) {
    handleResidentJoin(eventId, btnElem);
    closeEventDetailsModal();
  }
  window.handleResidentJoinFromModal = handleResidentJoinFromModal;

  // ============================================================================
  // Venue Navigation Leaflet Mapping Tool Engine & Modality Logic
  // ============================================================================
  let venueMapInstance = null;
  let venueMarker = null;
  let venueOriginMarker = null;
  let venueRouteGlow = null;
  let venueRouteLine = null;
  let activeVenueTarget = null;

  let residentCoords = [<?= (float) $residentLat ?>, <?= (float) $residentLng ?>];
  const residentPurokName = <?= json_encode($residentPurokName) ?>;
  const residentBrgyName = <?= json_encode($barangay['name'] ?? 'Hinaplanon') ?>;

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
      console.warn('Venue route proxy error, attempting direct OSRM:', err);
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
      console.warn('Direct OSRM route fallback error:', err);
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

  function openVenueModal(btn) {
    if (!btn) return;

    const isBtn = btn && (btn.tagName === 'BUTTON' || (btn.classList && (btn.classList.contains('btn') || btn.classList.contains('btn-missed-route'))));
    const originalHtml = isBtn ? btn.innerHTML : '';
    if (isBtn) {
      btn.classList.add('is-loading');
      btn.innerHTML = `
        <svg class="spin-loader" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle>
          <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor"></path>
        </svg>
        <span>Locating...</span>
      `;
    }

    const title = btn.getAttribute('data-title') || 'Community Event';
    const venue = btn.getAttribute('data-venue') || 'Venue';
    const barangay = btn.getAttribute('data-barangay') || '';
    let lat = parseFloat(btn.getAttribute('data-lat'));
    let lng = parseFloat(btn.getAttribute('data-lng'));
    const schedule = btn.getAttribute('data-time') || '';
    const dateStr = btn.getAttribute('data-date') || '';

    // Default fallback if coordinates not set (Hinaplanon covered court)
    if (!lat || !lng || isNaN(lat) || isNaN(lng)) {
      lat = 8.245800;
      lng = 124.260000;
    }

    activeVenueTarget = { lat, lng, venue, title, barangay };

    // Populate Modal Text safely
    const vNameEl = document.getElementById('venueModalVenueName');
    if (vNameEl) vNameEl.textContent = venue;

    const vSubEl = document.getElementById('venueModalEventSub');
    if (vSubEl) vSubEl.textContent = `${title} • ${dateStr} (${schedule})`;

    // Reset stats bar to loading
    const distEl = document.getElementById('venueRouteDist');
    if (distEl) distEl.textContent = 'Calculating...';
    const walkEl = document.getElementById('venueRouteWalk');
    if (walkEl) walkEl.textContent = '...';
    const driveEl = document.getElementById('venueRouteDrive');
    if (driveEl) driveEl.textContent = '...';

    // Show Modal Overlay
    const modal = document.getElementById('venueNavModal');
    if (modal) modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Loading overlay inside map container
    const loaderOverlay = document.getElementById('venueMapLoading');
    if (loaderOverlay) loaderOverlay.style.display = 'flex';

    setTimeout(async () => {
      await initVenueMap(lat, lng, venue, title, barangay);
      if (loaderOverlay) loaderOverlay.style.display = 'none';
      if (isBtn) {
        btn.classList.remove('is-loading');
        btn.innerHTML = originalHtml;
      }
    }, 250);
  }

  async function initVenueMap(targetLat, targetLng, venueName, eventTitle, brgyName) {
    const mapContainer = document.getElementById('venueLeafletMap');
    if (!mapContainer) return;

    if (typeof L === 'undefined') {
      mapContainer.innerHTML = `
        <div style="padding:28px;text-align:center;color:var(--color-primary);font-size:12px;">
          <strong>${escapeHtml(venueName)}</strong><br>
          <span style="color:var(--color-text-secondary);font-size:11px;">Brgy. ${escapeHtml(brgyName)} (Coordinates: ${targetLat}, ${targetLng})</span>
        </div>
      `;
      return;
    }

    if (venueMapInstance) {
      venueMapInstance.remove();
      venueMapInstance = null;
      venueOriginMarker = null;
      venueMarker = null;
      venueRouteGlow = null;
      venueRouteLine = null;
    }

    venueMapInstance = L.map('venueLeafletMap', {
      center: [targetLat, targetLng],
      zoom: 15,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(venueMapInstance);

    // 1. Origin Marker (Resident Location)
    const originIcon = L.divIcon({
      className: 'origin-user-pin',
      html: `
        <div style="background:#17324D;color:#FFF;width:28px;height:28px;border-radius:50%;border:2px solid #FFF;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;box-shadow:0 3px 8px rgba(0,0,0,0.35);">📍</div>
      `,
      iconSize: [28, 28],
      iconAnchor: [14, 14]
    });
    venueOriginMarker = L.marker(residentCoords, { icon: originIcon }).addTo(venueMapInstance);
    venueOriginMarker.bindPopup(`
      <div style="font-family:var(--font-primary, sans-serif);padding:3px;font-size:11px;">
        <strong style="color:#17324D;">Your Location</strong><br>
        <span style="font-size:10px;color:#666;">${residentPurokName ? 'Purok ' + escapeHtml(residentPurokName) + ', ' : ''}Brgy. ${escapeHtml(residentBrgyName)}</span>
      </div>
    `);

    // 2. Destination Marker (Venue)
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
    `);

    // 3. Compute and render 100% accurate turn-by-turn road route
    const routeData = await computeAccurateStreetRoute(residentCoords[0], residentCoords[1], targetLat, targetLng);

    // Draw dual-layer street route (navy glow background + sharp teal road line)
    venueRouteGlow = L.polyline(routeData.points, {
      color: '#17324D',
      weight: 6,
      opacity: 0.35,
      lineCap: 'round',
      lineJoin: 'round'
    }).addTo(venueMapInstance);

    venueRouteLine = L.polyline(routeData.points, {
      color: '#2F6F73',
      weight: 4,
      opacity: 0.95,
      lineCap: 'round',
      lineJoin: 'round'
    }).addTo(venueMapInstance);

    // Fit bounds to entire route
    venueMapInstance.fitBounds(venueRouteLine.getBounds(), { padding: [45, 45] });
    venueMarker.openPopup();

    // 4. Update Navigation Stats Bar
    const distKm = routeData.distanceKm;
    const walkMin = Math.max(1, Math.round((distKm / 4.2) * 60)); // ~4.2 km/h walking speed along actual streets
    const driveMin = Math.max(1, routeData.durationMins || Math.round((distKm / 28) * 60));

    const distEl = document.getElementById('venueRouteDist');
    if (distEl) distEl.textContent = `${distKm.toFixed(2)} km`;
    const walkEl = document.getElementById('venueRouteWalk');
    if (walkEl) walkEl.textContent = `${walkMin} min`;
    const driveEl = document.getElementById('venueRouteDrive');
    if (driveEl) driveEl.textContent = `${driveMin} min`;

    setTimeout(() => {
      if (venueMapInstance) venueMapInstance.invalidateSize();
    }, 200);
  }

  function fitVenueRoute() {
    if (venueMapInstance && venueRouteLine) {
      venueMapInstance.fitBounds(venueRouteLine.getBounds(), { padding: [45, 45] });
    }
  }
  window.fitVenueRoute = fitVenueRoute;

  function locateResidentForVenue() {
    if (!navigator.geolocation) {
      if (typeof showToast === 'function') showToast('Geolocation is not supported by your browser.', 'danger');
      return;
    }
    const btn = document.getElementById('venueGpsBtn');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Locating...';
    }

    navigator.geolocation.getCurrentPosition(
      async pos => {
        residentCoords = [pos.coords.latitude, pos.coords.longitude];
        const originLabel = document.getElementById('venueModalOriginLabel');
        if (originLabel) {
          originLabel.textContent = `Origin: Live GPS (~${Math.round(pos.coords.accuracy)}m)`;
        }
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'GPS Active';
        }
        if (activeVenueTarget) {
          await initVenueMap(activeVenueTarget.lat, activeVenueTarget.lng, activeVenueTarget.venue, activeVenueTarget.title, activeVenueTarget.barangay);
        }
      },
      err => {
        if (btn) {
          btn.disabled = false;
          btn.textContent = '📍 Use GPS';
        }
        if (typeof showToast === 'function') showToast('Could not retrieve GPS location.', 'danger');
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  }
  window.locateResidentForVenue = locateResidentForVenue;

  function closeVenueModal() {
    const modal = document.getElementById('venueNavModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  // ============================================================================
  // Event Details Modal Logic (Direct, Focused, Restrained Single Palette)
  // ============================================================================
  let currentDetailsCard = null;

  function openEventDetailsModal(cardEl) {
    if (!cardEl) return;
    currentDetailsCard = cardEl;

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

    const eventId = cardEl.getAttribute('data-id');
    const actionSlot = document.getElementById('detailsModalActionSlot');
    if (actionSlot) {
      if (isMissed) {
        actionSlot.innerHTML = `
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="badge" style="background:#FFF3CD;color:#856404;border:1px solid #FFEEBA;padding:5px 10px;font-size:10.5px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
              Missed Activity
            </span>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:10.5px;padding:5px 12px;border-radius:6px;display:inline-flex;align-items:center;gap:4px;" onclick="closeEventDetailsModal(); openVenueModal(currentDetailsCard)">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
              Route to Venue
            </button>
          </div>
        `;
      } else if (attendance.includes('Registered') && !attendance.includes('Not') && !isPast) {
        actionSlot.innerHTML = `
          <button type="button" class="btn-cancel-join" onclick="promptCancelJoin(${eventId}, '${escapeHtml(title).replace(/'/g, "\\'")}')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            Cancel Join
          </button>
        `;
      } else if (!isPast && !status.includes('Cancelled') && !status.includes('Ended')) {
        actionSlot.innerHTML = `
          <button type="button" class="btn btn-primary btn-sm" style="font-size:10.5px;padding:5px 14px;border-radius:6px;" onclick="handleResidentJoinFromModal(${eventId}, this)">
            Join Event
          </button>
        `;
      } else {
        actionSlot.innerHTML = '';
      }
    }

    const modal = document.getElementById('eventDetailsModal');
    if (modal) {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  }

  function openEventDetailsModalById(eventId) {
    const card = document.getElementById(`event-card-${eventId}`) || document.querySelector(`[data-id="${eventId}"]`);
    if (card) {
      openEventDetailsModal(card);
    }
  }

  function closeEventDetailsModal() {
    const modal = document.getElementById('eventDetailsModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  function handleOverlayClick(event, modalId) {
    if (event.target.id === modalId || event.target.classList.contains('custom-modal-overlay')) {
      if (modalId === 'venueNavModal') closeVenueModal();
      else if (modalId === 'eventDetailsModal') closeEventDetailsModal();
    }
  }

  // Bind click event listeners directly to cards
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.resident-event-card').forEach(card => {
      card.addEventListener('click', function (e) {
        if (e.target.closest('button') || e.target.closest('a')) {
          return;
        }
        openEventDetailsModal(this);
      });
    });
  });

  // ESC key to close active modal
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeVenueModal();
      closeEventDetailsModal();
    }
  });

  // Attach global functions to window
  window.openVenueModal = openVenueModal;
  window.closeVenueModal = closeVenueModal;
  window.openEventDetailsModal = openEventDetailsModal;
  window.openEventDetailsModalById = openEventDetailsModalById;
  window.closeEventDetailsModal = closeEventDetailsModal;
  window.handleOverlayClick = handleOverlayClick;
  window.promptCancelJoin = promptCancelJoin;
  window.executeCancelJoin = executeCancelJoin;
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>