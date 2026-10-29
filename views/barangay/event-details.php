<?php
// ============================================================================
// Views (Barangay Head): Dedicated Event Details & Participant Roster Page
// Full-page view replacing the former overview modal.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

// Fetch Event ID
$eventId = (int) ($_GET['id'] ?? 0);
if ($eventId <= 0) {
  redirectWithFlash(BASE_URL . '/views/barangay/events.php', 'error', 'Invalid event ID.');
}

// Self-heal table schema if necessary
$colsEvalAt = $db->query("SHOW COLUMNS FROM events LIKE 'evaluated_at'")->fetchAll();
if (empty($colsEvalAt)) {
    $db->exec("ALTER TABLE events ADD COLUMN evaluated_at DATETIME NULL AFTER evaluation_summary");
}

// Auto-sync event statuses: Scheduled events whose starting time has arrived transition to Ongoing
syncEventStatuses($db);

// Fetch Event Details
$stmt = $db->prepare("
    SELECT pa.*, b.name AS barangay_name, u.full_name AS creator_name 
    FROM events pa 
    LEFT JOIN barangays b ON pa.barangay_id = b.id 
    LEFT JOIN users u ON pa.created_by = u.id 
    WHERE pa.id = ? AND (pa.barangay_id = ? OR pa.barangay_id IS NULL)
");
$stmt->execute([$eventId, $barangayId]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
  redirectWithFlash(BASE_URL . '/views/barangay/events.php', 'error', 'Event record not found or access denied.');
}

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Evacuation Areas for Venue Options
$evacStmt = $db->prepare("
    SELECT id, name, center_type, location_address 
    FROM evacuation_areas 
    WHERE (barangay_id = ? OR barangay_id IS NULL)
    ORDER BY (barangay_id = ?) DESC, name ASC
");
$evacStmt->execute([$barangayId, $barangayId]);
$evacuationVenues = $evacStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Puroks for this Barangay
$purokStmt = $db->prepare("
    SELECT id, name, population, households 
    FROM puroks 
    WHERE (barangay_id = ? OR barangay_id IS NULL) AND (status = 'active' OR status IS NULL)
    ORDER BY name ASC
");
$purokStmt->execute([$barangayId]);
$barangayPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPurokPopulation = 0;
$totalPurokHouseholds = 0;
foreach ($barangayPuroks as $p) {
  $totalPurokPopulation += (int) ($p['population'] ?? 0);
  $totalPurokHouseholds += (int) ($p['households'] ?? 0);
}

// Fetch Active Household Families for this Barangay
$hhQueryStmt = $db->prepare("
    SELECT id, household_code, family_name, head_full_name, head_phone, purok_name, purok_id
    FROM purok_households 
    WHERE (barangay_id = ? OR barangay_id IS NULL) AND (status = 'active' OR status IS NULL)
    ORDER BY purok_name ASC, household_code ASC
");
$hhQueryStmt->execute([$barangayId]);
$barangayHouseholds = $hhQueryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Household Members for this Barangay
$memQueryStmt = $db->prepare("
    SELECT hm.id, hm.household_id, hm.full_name, hm.relationship, hm.gender, hm.age, hm.is_authorized_rep,
           ph.family_name, ph.household_code, ph.purok_name, ph.purok_id, COALESCE(ph.head_phone, '') AS phone
    FROM household_members hm
    INNER JOIN purok_households ph ON hm.household_id = ph.id
    WHERE (ph.barangay_id = ? OR ph.barangay_id IS NULL) AND (ph.status = 'active' OR ph.status IS NULL)
    ORDER BY ph.purok_name ASC, ph.family_name ASC, hm.is_authorized_rep DESC, hm.age DESC
");
$memQueryStmt->execute([$barangayId]);
$barangayMembers = $memQueryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Attendees registered specifically for this activity
$attStmt = $db->prepare("
    SELECT ea.* 
    FROM event_attendees ea 
    WHERE ea.activity_id = ? AND (ea.is_archived = 0 OR ea.is_archived IS NULL)
    ORDER BY ea.id ASC
");
$attStmt->execute([$eventId]);
$actAttendees = $attStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Residents for Quick Registration
$rStmt = $db->prepare("
    SELECT id, first_name, last_name, full_name, phone, purok, email 
    FROM residents 
    WHERE (barangay_id = ? OR barangay_id IS NULL) AND (status = 'active' OR status IS NULL)
    ORDER BY first_name ASC, last_name ASC
");
$rStmt->execute([$barangayId]);
$barangayResidents = $rStmt->fetchAll(PDO::FETCH_ASSOC);

// Default activities dictionary by activity type
$defaultExpectedByType = [
  "Health and Medical" => [
    "Medical Mission", "Free Medical Checkup", "Free Dental Checkup", "Free Tooth Extraction",
    "Free Circumcision", "Free Eye Checkup", "Free Eye Glasses Distribution", "Free Medicine Distribution",
    "Bloodletting Activity", "Free Blood Pressure Check", "Blood Sugar Screening", "Vaccination Program",
    "Immunization Program", "Deworming Program", "Maternal and Child Health Seminar"
  ],
  "Nutrition and Feeding" => [
    "Barangay Feeding Program", "Nutritious Meal Distribution", "Supplemental Feeding for Malnourished Children",
    "Food Pack Distribution", "Nutrition Awareness and Education Campaign"
  ],
  "Cleanliness and Environment" => [
    "Barangay Clean-up Drive", "Kalinisan Day Activity", "Coastal / River / Canal Clean-up",
    "Solid Waste Management Orientation", "Tree Planting Activity", "Dengue Prevention Walk"
  ],
  "Youth and Children" => [
    "Barangay Sports League", "Youth Leadership Training", "Anti-Drug Abuse Campaign (BADAC)",
    "Children's Month Celebration", "Arts and Talent Workshop", "Mental Health Awareness Talk"
  ],
  "Education and Skills Training" => [
    "Livelihood Skills Training Seminar", "Technical Skills Training (TESDA Partnership)",
    "Computer Literacy Training", "Alternative Learning System (ALS) Program", "Financial Literacy Seminar"
  ],
  "Social Welfare and Assistance" => [
    "Financial Aid Distribution (AICS / TUPAD)", "Relief Goods Distribution", "Senior Citizen Assistance",
    "PWD Benefits & ID Registration", "Solo Parents Welfare Seminar", "Cash-for-Work Program"
  ],
  "Livelihood and Employment" => [
    "Barangay Job Fair / Special Recruitment Activity", "Small Business Entrepreneurship Seminar",
    "Food Processing and Cooking Demonstration", "Handicrafts and Product Making Workshop"
  ],
  "Sports and Recreation" => [
    "Inter-Purok Basketball League", "Volleyball Tournament", "Zumba and Community Fitness Session",
    "Barangay Sports Fest", "Traditional Filipino Games (Palarong Pinoy)"
  ],
  "Disaster Preparedness and Safety" => [
    "Earthquake Drill (ShakeOut)", "Fire Drill and Evacuation Simulation", "Flood Evacuation and Rescue Drill",
    "Basic Life Support and First Aid Training", "Disaster Preparedness Awareness Seminar",
    "Emergency Go-Bag Preparation Orientation", "Incident Command System (ICS) Orientation"
  ],
  "Animal Welfare" => [
    "Free Anti-Rabies Vaccination for Dogs and Cats", "Spay and Neuter (Kapon) Activity",
    "Pet Care and Responsible Pet Ownership Seminar"
  ],
  "Community Celebrations and Special Events" => [
    "Barangay Fiesta Celebration", "Araw ng Barangay Celebration", "Christmas Party and Gift-giving",
    "Palaro at Parada ng Barangay", "Community Recognition and Awards Night"
  ],
  "Government and Community Services" => [
    "Barangay Assembly Meeting", "Community Town Hall Consultation", "Free Legal Aid Consultation",
    "National ID (PhilSys) Registration Assistance", "Voter Registration Information Drive"
  ],
  "Agricultural Programs" => [
    "Urban Gardening Workshop", "Free Vegetable Seeds and Seedlings Distribution",
    "Organic Farming and Composting Seminar", "Backyard Poultry and Livestock Seminar"
  ]
];

$now = time();
$startTime = strtotime($act['start_datetime']);
$endTime = strtotime($act['end_datetime']);
$isStarted = ($now >= $startTime);
$isEnded = ($now >= $endTime);
$isCompleted = ($act['status'] === 'Completed');
$isCancelled = ($act['status'] === 'Cancelled');
$isArchived = !empty($act['is_archived']);

// Evaluation status & history tracking
$evalTimestamp = !empty($act['evaluated_at']) ? $act['evaluated_at'] : null;
$evalLogsCount = 0;
try {
  $evalLogsStmt = $db->prepare("SELECT created_at FROM system_logs WHERE action = 'EVALUATE_ACTIVITY' AND details LIKE ? ORDER BY id DESC");
  $evalLogsStmt->execute(['%#' . (int)$act['id'] . '%']);
  $evalLogs = $evalLogsStmt->fetchAll(PDO::FETCH_ASSOC);
  $evalLogsCount = count($evalLogs);
  if (empty($evalTimestamp) && !empty($evalLogs[0]['created_at'])) {
    $evalTimestamp = $evalLogs[0]['created_at'];
  }
} catch (Exception $e) {}

$isEvaluated = !empty($evalTimestamp) || !empty($act['evaluation_summary']) || $isCompleted;
$isReevaluated = ($evalLogsCount > 1);

// Execution milestones data
$executedItems = [];
if (!empty($act['content_execution'])) {
  $decoded = json_decode($act['content_execution'], true);
  $executedItems = is_array($decoded) ? $decoded : [$act['content_execution']];
}

$expectedItems = [];
if (!empty($act['expected_activities'])) {
  $decoded = json_decode($act['expected_activities'], true);
  $expectedItems = is_array($decoded) ? $decoded : [$act['expected_activities']];
}
if (empty($expectedItems)) {
  $expectedItems = $defaultExpectedByType[$act['activity_type']] ?? [
    "Initial Briefing & Safety Orientation",
    "Drill / Event Scenario Execution",
    "Attendance & Roll Call Verification",
    "Post-Activity Debriefing & Feedback"
  ];
}

$totalExpected = count($expectedItems);
$execCount = count($executedItems);
$evalPercent = $totalExpected > 0 ? round(($execCount / $totalExpected) * 100) : 100;

// Target Puroks array
$targetPuroksRaw = trim($act['target_puroks'] ?? '');
$targetPuroksList = [];
if (!empty($targetPuroksRaw) && strtolower($targetPuroksRaw) !== 'all puroks') {
  $targetPuroksList = array_map('trim', explode(',', $targetPuroksRaw));
}
$isAllPuroksScope = empty($targetPuroksList);

// Attendees count
$attendedCount = 0;
foreach ($actAttendees as $att) {
  if (!empty($att['attended'])) {
    $attendedCount++;
  }
}
$targetCount = (int) ($act['target_participants'] ?? 0);
if ($targetCount <= 0) {
  $targetCount = count($actAttendees) > 0 ? count($actAttendees) : 1;
}
$turnoutPercent = round(($attendedCount / max(1, $targetCount)) * 100);

$pageTitle = clean($act['title']) . " — Event Details";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<style>
  .event-details-hero {
    background: #FFFFFF;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-primary, 10px);
    padding: 20px 24px;
    margin-bottom: var(--space-4);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  }

  .event-meta-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: var(--space-4);
  }

  @media (max-width: 992px) {
    .event-meta-cards-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  @media (max-width: 576px) {
    .event-meta-cards-grid {
      grid-template-columns: 1fr;
    }
  }

  .event-kpi-card {
    background: #FFFFFF;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm, 8px);
    padding: 14px 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
  }

  .event-kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--color-surface-subtle);
    color: var(--color-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .event-kpi-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-text-secondary);
    margin-bottom: 2px;
  }

  .event-kpi-value {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1.3;
  }

  .event-kpi-sub {
    font-size: 10px;
    color: var(--color-text-muted);
    margin-top: 2px;
  }

  .content-two-col {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 16px;
    align-items: start;
    margin-bottom: var(--space-4);
  }

  @media (max-width: 1080px) {
    .content-two-col {
      grid-template-columns: 1fr;
    }
  }

  .milestone-badge-check {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #FFFFFF;
    border: 1px solid var(--color-border);
    padding: 7px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    color: var(--color-text);
  }

  .milestone-badge-check.done {
    border-color: #BBF7D0;
    background: #F0FDF4;
  }

  /* Modal Layout */
  .modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.48) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }

  .modal-overlay.active {
    display: flex !important;
  }

  .modal-dialog {
    max-width: 680px !important;
    width: 100% !important;
    max-height: calc(100vh - 36px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
    position: relative !important;
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid var(--color-border);
  }

  .modal-body {
    padding: 18px 20px;
    overflow-y: auto;
  }

  .modal-footer {
    padding: 12px 20px;
    border-top: 1px solid var(--color-border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    background: var(--color-surface-subtle);
  }

  .modal-close-btn {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: var(--color-text-muted);
  }

  .action-toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
  }

  @media print {
    .sidebar, .nav-tabs, .page-header-actions, .action-toolbar-btn, .modal-overlay {
      display: none !important;
    }
    .main-content {
      margin: 0 !important;
      padding: 0 !important;
    }
  }
</style>

<!-- Breadcrumb Navigation -->
<div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
  <div style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--color-text-muted);">
    <a href="<?= BASE_URL ?>/views/barangay/dashboard.php" style="color: var(--color-text-muted); text-decoration: none;">Dashboard</a>
    <span>/</span>
    <a href="<?= BASE_URL ?>/views/barangay/events.php" style="color: var(--color-text-muted); text-decoration: none;">Manage Events</a>
    <span>/</span>
    <span style="color: var(--color-primary); font-weight: 600;">Event Details</span>
  </div>

  <a href="<?= BASE_URL ?>/views/barangay/events.php" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; padding: 4px 10px;">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="15 18 9 12 15 6"></polyline>
    </svg>
    Back to Manage Events
  </a>
</div>

<!-- Hero Card: Event Title, Type, Status & Actions Toolbar -->
<div class="event-details-hero">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 280px;">
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
        <span class="badge badge-info" style="font-size: 10px; font-weight: 600; padding: 3px 8px;">
          <?= clean($act['activity_type']) ?>
        </span>

        <?php if ($isArchived): ?>
          <span class="badge badge-neutral" style="font-size: 10px; font-weight: 600;">Archived</span>
        <?php elseif ($isCancelled): ?>
          <span class="badge badge-danger" style="font-size: 10px; font-weight: 600;">Cancelled</span>
        <?php elseif ($isCompleted): ?>
          <span class="badge badge-success" style="font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;">
            ✓ Accomplished
          </span>
        <?php elseif ($isEnded || $act['status'] === 'Ended' || $act['status'] === 'Finished'): ?>
          <span class="badge badge-warning" style="font-size: 10px; font-weight: 600; background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;">
            ⏹ Ended &bull; Ready for Evaluation
          </span>
        <?php elseif ($act['status'] === 'Ongoing' || $isStarted): ?>
          <span class="badge badge-warning" style="font-size: 10px; font-weight: 600; background: #DCFCE7; color: #16A34A; border: 1px solid #BBF7D0; display: inline-flex; align-items: center; gap: 4px;">
            <span style="font-size: 8px;">●</span> Ongoing Live
          </span>
        <?php else: ?>
          <span class="badge badge-info" style="font-size: 10px; font-weight: 600;">Scheduled</span>
        <?php endif; ?>

        <?php $scopeText = clean($act['target_puroks'] ?? 'All Puroks'); ?>
        <span style="font-size: 10.5px; font-weight: 600; color: #0284C7; display: inline-flex; align-items: center; gap: 3px;">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          <?= ($scopeText === 'All Puroks') ? 'All Puroks (Barangay-Wide)' : $scopeText ?>
        </span>
      </div>

      <h1 style="font-size: 20px; font-weight: 700; color: var(--color-primary); margin: 0 0 4px 0; line-height: 1.3;">
        <?= clean($act['title']) ?>
      </h1>

      <div style="font-size: 11px; color: var(--color-text-secondary);">
        Organized by <strong><?= clean($act['creator_name'] ?: 'Barangay Head') ?></strong> &bull;
        Barangay <?= clean($barangay['name'] ?? '') ?> &bull;
        Created <?= formatDate($act['created_at'], 'M d, Y h:i A') ?>
      </div>
    </div>

    <!-- Actions Toolbar -->
    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
      <?php if ($isArchived): ?>
        <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/index.php" style="margin:0;" onsubmit="return confirm('Restore this event back to active events?');">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
          <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
          <button type="submit" class="action-toolbar-btn btn-outline" style="color:var(--color-success); border-color:var(--color-success);">
            Restore to Active
          </button>
        </form>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('deleteActivityModal')" style="color:#DC2626; border-color:#FCA5A5;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete
        </button>

      <?php elseif ($isCancelled): ?>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('archiveActivityModal')" style="color:#D97706; border-color:#FCD34D;">
          Archive
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('deleteActivityModal')" style="color:#DC2626; border-color:#FCA5A5;">
          Delete
        </button>

      <?php elseif ($isCompleted): ?>
        <button type="button" class="action-toolbar-btn btn-success" onclick="openModal('evaluateModal')" style="background:var(--color-success,#16A34A); border-color:var(--color-success,#16A34A); color:#fff;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
          Re-evaluate
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('editActivityModal')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('archiveActivityModal')" style="color:#D97706; border-color:#FCD34D;">
          Archive
        </button>

      <?php elseif ($isEnded || $act['status'] === 'Ended' || $act['status'] === 'Finished'): ?>
        <button type="button" class="action-toolbar-btn btn-success" onclick="openModal('evaluateModal')" style="background:var(--color-success,#16A34A); border-color:var(--color-success,#16A34A); color:#fff;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
          Evaluate Event
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('editActivityModal')">
          Edit Details
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('cancelActivityModal')" style="color:var(--color-danger); border-color:var(--color-danger);">
          Cancel
        </button>

      <?php elseif ($isStarted): ?>
        <button type="button" class="action-toolbar-btn btn-primary" onclick="openModal('registerResidentModal')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
          Register Resident
        </button>
        <button type="button" class="action-toolbar-btn btn-success" onclick="openModal('evaluateModal')" style="background:var(--color-success,#16A34A); border-color:var(--color-success,#16A34A); color:#fff;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Done & Evaluate
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('editActivityModal')">
          Edit
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('cancelActivityModal')" style="color:var(--color-danger); border-color:var(--color-danger);">
          Cancel
        </button>

      <?php else: ?>
        <button type="button" class="action-toolbar-btn btn-primary" onclick="openModal('registerResidentModal')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
          Register Resident
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('editActivityModal')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit Details
        </button>
        <button type="button" class="action-toolbar-btn btn-outline" onclick="openModal('cancelActivityModal')" style="color:var(--color-danger); border-color:var(--color-danger);">
          Cancel
        </button>
      <?php endif; ?>

      <button type="button" class="action-toolbar-btn btn-outline" onclick="window.print()" title="Print Summary Report">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 6 2 18 2 18 9"></polyline>
          <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
          <rect x="6" y="14" width="12" height="8"></rect>
        </svg>
        Print
      </button>
    </div>
  </div>
</div>

<!-- Meta KPI Cards Grid (4 Cards) -->
<div class="event-meta-cards-grid">
  <!-- Card 1: Schedule Date & Time -->
  <div class="event-kpi-card">
    <div class="event-kpi-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
        <line x1="16" y1="2" x2="16" y2="6"></line>
        <line x1="8" y1="2" x2="8" y2="6"></line>
        <line x1="3" y1="10" x2="21" y2="10"></line>
      </svg>
    </div>
    <div style="flex:1;min-width:0;">
      <div class="event-kpi-label">Schedule Date & Time</div>
      <div class="event-kpi-value"><?= formatDate($act['start_datetime'], 'M d, Y') ?></div>
      <div class="event-kpi-sub">
        <?= formatDate($act['start_datetime'], 'h:i A') ?> — <?= formatDate($act['end_datetime'], 'h:i A') ?>
      </div>
    </div>
  </div>

  <!-- Card 2: Venue & Location -->
  <div class="event-kpi-card">
    <div class="event-kpi-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
        <circle cx="12" cy="10" r="3"></circle>
      </svg>
    </div>
    <div style="flex:1;min-width:0;">
      <div class="event-kpi-label">Venue Location</div>
      <div class="event-kpi-value" title="<?= clean($act['venue']) ?>"><?= clean($act['venue']) ?></div>
      <div class="event-kpi-sub"><?= ($scopeText === 'All Puroks') ? 'Barangay-Wide Coverage' : 'Scope: ' . $scopeText ?></div>
    </div>
  </div>

  <!-- Card 3: Lead Facilitator -->
  <div class="event-kpi-card">
    <div class="event-kpi-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
        <circle cx="12" cy="7" r="4"></circle>
      </svg>
    </div>
    <div style="flex:1;min-width:0;">
      <div class="event-kpi-label">Lead Facilitator</div>
      <div class="event-kpi-value" title="<?= clean($act['assigned_personnel']) ?>"><?= clean($act['assigned_personnel'] ?: 'Barangay Disaster Team') ?></div>
      <div class="event-kpi-sub">Barangay Operations Staff</div>
    </div>
  </div>

  <!-- Card 4: Attendance Turnout -->
  <div class="event-kpi-card">
    <div class="event-kpi-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
        <circle cx="9" cy="7" r="4"></circle>
      </svg>
    </div>
    <div style="flex:1;min-width:0;">
      <div class="event-kpi-label">Attendance Turnout</div>
      <div class="event-kpi-value" style="color:var(--color-primary);">
        <?= $attendedCount ?> / <?= $targetCount ?>
        <span style="font-size:11px;font-weight:600;color:<?= $turnoutPercent >= 75 ? 'var(--color-success)' : 'var(--color-warning)' ?>;">(<?= $turnoutPercent ?>%)</span>
      </div>
      <div class="event-kpi-sub"><?= count($actAttendees) ?> registered participants</div>
    </div>
  </div>
</div>


<!-- Two-Column Layout: Left (Overview & Milestones) | Right (Full Roster) -->
<div class="content-two-col">

  <!-- Left Column: Description, Cancellation/Evaluation, Milestones -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Cancellation Reason Banner (If Cancelled) -->
    <?php if ($isCancelled && !empty($act['cancellation_reason'])): ?>
      <div class="card" style="border-left: 4px solid var(--color-danger); background: #FEF2F2;">
        <div class="card-body" style="padding: 14px 16px;">
          <div style="font-size: 11px; font-weight: 700; color: var(--color-danger); text-transform: uppercase; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            Cancellation Notice
          </div>
          <div style="font-size: 12px; color: #991B1B; line-height: 1.45;">
            <?= clean($act['cancellation_reason']) ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Evaluation Remarks & Outcomes -->
    <div class="card" style="border-left: 4px solid <?= $isEvaluated ? 'var(--color-success)' : 'var(--color-border)' ?>; background: <?= $isEvaluated ? '#F0FDF4' : 'var(--color-surface, #ffffff)' ?>;">
      <div class="card-body" style="padding: 14px 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
          <div style="font-size: 11px; font-weight: 700; color: <?= $isEvaluated ? 'var(--color-success)' : 'var(--color-text-muted)' ?>; text-transform: uppercase; display: flex; align-items: center; gap: 5px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <?php if ($isEvaluated): ?>
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>
              <?php else: ?>
                <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
              <?php endif; ?>
            </svg>
            Evaluation Remarks &amp; Outcomes
          </div>

          <?php if ($isEvaluated): ?>
            <div style="display: flex; align-items: center; gap: 5px; font-size: 11px; color: #166534; font-weight: 600;" title="<?= $isReevaluated ? 'Re-evaluated date and time' : 'Evaluation date and time' ?>">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
              <span><?= $isReevaluated ? 'Re-evaluated' : 'Evaluated' ?>: <?= !empty($evalTimestamp) ? (formatDate($evalTimestamp, 'M d, Y') . ' at ' . formatDate($evalTimestamp, 'h:i A')) : 'Recorded' ?></span>
            </div>
          <?php else: ?>
            <span class="badge badge-neutral" style="font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #F1F5F9; color: #64748B; border: 1px solid #CBD5E1; display: inline-flex; align-items: center; gap: 4px;">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
              Not yet evaluated
            </span>
          <?php endif; ?>
        </div>

        <?php if ($isEvaluated): ?>
          <div style="font-size: 12px; color: #166534; line-height: 1.5; white-space: pre-wrap;">
            <?= !empty($act['evaluation_summary']) ? clean($act['evaluation_summary']) : '<span style="color:var(--color-text-muted);font-style:italic;">No detailed evaluation remarks entered. Click Re-evaluate at the top toolbar to add notes.</span>' ?>
          </div>
        <?php else: ?>
          <div style="font-size: 12px; color: var(--color-text-muted); line-height: 1.5; font-style: italic;">
            Not yet evaluated. Evaluation remarks, outcomes, and assessment findings will appear here once the event is evaluated.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Event Description Card -->
    <div class="card">
      <div class="card-header" style="padding:12px 16px;">
        <h3 class="card-title" style="margin:0;font-size:13px;">Event Description</h3>
      </div>
      <div class="card-body" style="padding:14px 16px;">
        <div style="font-size:12px;line-height:1.5;color:var(--color-text);">
          <?= !empty($act['description']) ? nl2br(clean($act['description'])) : '<span style="color:var(--color-text-muted);font-style:italic;">No description provided.</span>' ?>
        </div>
      </div>
    </div>

    <!-- Execution Milestones Progress Card -->
    <div class="card">
      <div class="card-header" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title" style="margin:0;font-size:13px;">Execution Milestones</h3>
        <span class="badge <?= $isCompleted ? 'badge-success' : 'badge-neutral' ?>" style="font-size:10px;">
          <?= $execCount ?> of <?= $totalExpected ?> Done (<?= $evalPercent ?>%)
        </span>
      </div>
      <div class="card-body" style="padding:14px 16px;">
        <!-- Progress Bar -->
        <div style="height:6px;background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:4px;overflow:hidden;margin-bottom:12px;">
          <div style="height:100%;width:<?= $evalPercent ?>%;background:var(--color-primary);border-radius:3px;transition:width 0.3s ease;"></div>
        </div>

        <!-- Milestones Checklist -->
        <div style="display:flex;flex-direction:column;gap:6px;max-height:300px;overflow-y:auto;">
          <?php foreach ($expectedItems as $idx => $item): ?>
            <?php $isDone = in_array($item, $executedItems, true); ?>
            <div class="milestone-badge-check <?= $isDone ? 'done' : '' ?>">
              <div style="width:18px;height:18px;border-radius:4px;background:<?= $isDone ? 'var(--color-success)' : 'var(--color-surface-subtle)' ?>;color:<?= $isDone ? '#fff' : 'var(--color-text-muted)' ?>;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">
                <?= $isDone ? '✓' : ($idx + 1) ?>
              </div>
              <div style="flex:1;min-width:0;line-height:1.3;<?= $isDone ? 'font-weight:600;color:#166534;' : 'color:var(--color-text);' ?>">
                <?= clean($item) ?>
              </div>
              <span style="font-size:9.5px;color:<?= $isDone ? 'var(--color-success)' : 'var(--color-text-muted)' ?>;font-weight:600;">
                <?= $isDone ? 'Executed' : 'Planned' ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Right Column: Participant Attendees & Household Roster -->
  <div class="card">
    <div class="card-header" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <h3 class="card-title" style="margin:0;font-size:13px;">Participant Attendees &amp; Roster</h3>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px;">
          Manage attendance and family member records for this event
        </div>
      </div>

      <!-- Mode Selector: House Families vs All Members vs Registered -->
      <div style="display:inline-flex;border:1px solid var(--color-border);border-radius:6px;overflow:hidden;background:var(--color-surface-subtle);">
        <button type="button" id="tabModeFamilies" onclick="switchRosterMode('household_families')" style="font-size:10px;font-weight:700;padding:4px 10px;border:none;cursor:pointer;background:var(--color-primary);color:#FFFFFF;transition:all 0.15s ease;">
          House Families
        </button>
        <button type="button" id="tabModeMembers" onclick="switchRosterMode('all_members')" style="font-size:10px;font-weight:500;padding:4px 10px;border:none;cursor:pointer;background:transparent;color:var(--color-text-secondary);transition:all 0.15s ease;">
          All Members
        </button>
        <button type="button" id="tabModeDirect" onclick="switchRosterMode('direct_attendees')" style="font-size:10px;font-weight:500;padding:4px 10px;border:none;cursor:pointer;background:transparent;color:var(--color-text-secondary);transition:all 0.15s ease;">
          Registered (<?= count($actAttendees) ?>)
        </button>
      </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding:10px 16px;background:var(--color-surface-subtle);border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
      <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:240px;">
        <input type="text" id="rosterSearchInput" oninput="filterRoster(this.value)" class="form-control" placeholder="Search by name, household code, purok..." style="font-size:11px;height:32px;">
      </div>

      <div style="display:flex;align-items:center;gap:8px;">
        <span id="rosterCountPill" class="badge badge-info" style="font-size:10.5px;padding:4px 9px;">
          Loading Roster...
        </span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('registerResidentModal')" style="font-size:11px;height:32px;display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Add Attendee
        </button>
      </div>
    </div>

    <!-- Roster Table Container -->
    <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
      <table class="data-table" id="rosterTable">
        <thead>
          <tr id="rosterTableHeader">
            <th>Family / Resident Name</th>
            <th>Purok Zone</th>
            <th>Household Code</th>
            <th>Head / Rep</th>
            <th>Phone</th>
            <th>Attendance</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody id="rosterTableBody">
          <!-- Rendered via JS -->
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ========================================================================= -->
<!-- MODAL: Edit Activity                                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Edit Event Details</h3>
      <button class="modal-close-btn" onclick="closeModal('editActivityModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/update.php" id="editActivityForm">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body">
        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Event Title *</label>
          <input type="text" name="title" class="form-control" value="<?= clean($act['title']) ?>" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">Activity Type *</label>
            <select name="activity_type" class="form-control" required>
              <?php foreach (array_keys($defaultExpectedByType) as $tName): ?>
                <option value="<?= clean($tName) ?>" <?= $act['activity_type'] === $tName ? 'selected' : '' ?>><?= clean($tName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">Target Participants *</label>
            <input type="number" name="target_participants" class="form-control" value="<?= (int) $act['target_participants'] ?>" min="1" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">
              Start Date & Time * <?= $isStarted ? '<span style="color:#D97706;font-size:10px;">(Locked: Already Started)</span>' : '' ?>
            </label>
            <input type="datetime-local" name="start_datetime" class="form-control"
              value="<?= date('Y-m-d\TH:i', strtotime($act['start_datetime'])) ?>"
              <?= $isStarted ? 'readonly style="background:var(--color-surface-subtle);cursor:not-allowed;"' : 'required' ?>>
          </div>

          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">
              End Date & Time * <?= $isStarted ? '<span style="color:#D97706;font-size:10px;">(Locked: Already Started)</span>' : '' ?>
            </label>
            <input type="datetime-local" name="end_datetime" class="form-control"
              value="<?= date('Y-m-d\TH:i', strtotime($act['end_datetime'])) ?>"
              <?= $isStarted ? 'readonly style="background:var(--color-surface-subtle);cursor:not-allowed;"' : 'required' ?>>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Venue Location *</label>
          <select name="venue" class="form-control" required>
            <option value="">Select Designated Evacuation Area / Venue</option>
            <?php foreach ($evacuationVenues as $v): ?>
              <option value="<?= clean($v['name']) ?>" <?= $act['venue'] === $v['name'] ? 'selected' : '' ?>>
                <?= clean($v['name']) ?> (<?= clean($v['center_type'] ?: 'Center') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Lead Facilitator / Assigned Personnel</label>
          <input type="text" name="assigned_personnel" class="form-control" value="<?= clean($act['assigned_personnel']) ?>" placeholder="e.g. Barangay Disaster Operations Team">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Description</label>
          <textarea name="description" class="form-control" rows="3"><?= clean($act['description']) ?></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('editActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Evaluate Event                                                     -->
<!-- ========================================================================= -->
<?php $isAlreadyEvaluated = !empty($act['evaluation_summary']) || $isCompleted; ?>
<div class="modal-overlay" id="evaluateModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">
        <?= $isAlreadyEvaluated ? 'Re-evaluate Event Execution & Milestones' : 'Evaluate Event Execution & Milestones' ?>
      </h3>
      <button class="modal-close-btn" onclick="closeModal('evaluateModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/evaluate.php">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body">
        <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border);padding:10px 14px;border-radius:6px;margin-bottom:14px;">
          <div style="font-size:13px;font-weight:700;color:var(--color-primary);"><?= clean($act['title']) ?></div>
          <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;">
            Turnout: <?= $attendedCount ?> of <?= $targetCount ?> (<?= $turnoutPercent ?>%)
          </div>
        </div>

        <div class="form-group" style="margin-bottom:14px;">
          <label class="form-label" style="font-size:11px;font-weight:700;margin-bottom:6px;">Milestones Completed During Activity</label>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:200px;overflow-y:auto;border:1px solid var(--color-border);padding:8px 10px;border-radius:6px;background:#fff;">
            <?php foreach ($expectedItems as $item): ?>
              <?php $isChecked = in_array($item, $executedItems, true); ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:11px;cursor:pointer;margin:0;">
                <input type="checkbox" name="content_execution[]" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>" <?= $isChecked ? 'checked' : '' ?> style="margin:0;">
                <span><?= clean($item) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Evaluation Remarks &amp; Outcomes</label>
          <textarea name="evaluation_summary" class="form-control" rows="4" placeholder="Enter findings, recommendations, and execution outcomes..." required><?= clean($act['evaluation_summary'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('evaluateModal')">Cancel</button>
        <button type="submit" class="btn btn-success btn-sm">
          <?= $isAlreadyEvaluated ? 'Save Re-evaluation' : 'Submit Evaluation & Complete' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Quick Register Resident & SMS Notice                               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="registerResidentModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Register Resident for Event</h3>
      <button class="modal-close-btn" onclick="closeModal('registerResidentModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/register_resident.php">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body">
        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Select Registered Barangay Resident</label>
          <select id="regResidentSelect" onchange="onSelectResidentQuick(this)" class="form-control">
            <option value="">-- Choose Existing Resident --</option>
            <?php foreach ($barangayResidents as $r): ?>
              <?php $rName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['full_name'] ?? ''); ?>
              <option value="<?= $r['id'] ?>" data-name="<?= clean($rName) ?>" data-phone="<?= clean($r['phone'] ?? '') ?>">
                <?= clean($rName) ?> &bull; <?= clean($r['purok'] ?: 'Unassigned') ?> (<?= clean($r['phone'] ?: 'No Phone') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">Resident Full Name *</label>
            <input type="text" name="resident_name" id="reg_resident_name" class="form-control" required placeholder="Full Name">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label" style="font-size:11px;font-weight:700;">Phone Number</label>
            <input type="text" name="phone" id="reg_phone" class="form-control" placeholder="09xxxxxxxxx">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;cursor:pointer;">
            <input type="checkbox" name="send_sms" value="1" checked style="margin:0;">
            <span>Send SMS Invitation Notice to Resident</span>
          </label>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('registerResidentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Confirm Registration</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Cancel Event                                                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="cancelActivityModal">
  <div class="modal-dialog" style="max-width:500px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-danger);">Cancel Event</h3>
      <button class="modal-close-btn" onclick="closeModal('cancelActivityModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/cancel.php">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body">
        <p style="font-size:12px;color:var(--color-text);margin-top:0;">
          Are you sure you want to cancel <strong><?= clean($act['title']) ?></strong>? This will mark the activity as Cancelled.
        </p>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:11px;font-weight:700;">Reason for Cancellation *</label>
          <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="Provide reason (e.g. Severe Weather, Rescheduled, Venue Unavailable)..." required></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('cancelActivityModal')">Go Back</button>
        <button type="submit" class="btn btn-danger btn-sm">Confirm Cancellation</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Archive Event                                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="archiveActivityModal">
  <div class="modal-dialog" style="max-width:480px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Move to Archives</h3>
      <button class="modal-close-btn" onclick="closeModal('archiveActivityModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/archive.php">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/barangay/events.php">

      <div class="modal-body">
        <p style="font-size:12px;color:var(--color-text);margin:0;">
          Are you sure you want to archive <strong><?= clean($act['title']) ?></strong>? The record can be restored anytime from the Archives tab.
        </p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('archiveActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#D97706;border-color:#D97706;">Move to Archive</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Delete Event                                                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deleteActivityModal">
  <div class="modal-dialog" style="max-width:480px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:#DC2626;">Permanently Delete Event</h3>
      <button class="modal-close-btn" onclick="closeModal('deleteActivityModal')">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/delete.php">
      <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/barangay/events.php">

      <div class="modal-body">
        <p style="font-size:12px;color:var(--color-text);margin:0;">
          Are you sure you want to permanently delete <strong><?= clean($act['title']) ?></strong>?
          This action <strong>cannot be undone</strong> and will remove all attendee records associated with this event.
        </p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('deleteActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm" style="background:#DC2626;border-color:#DC2626;">Permanently Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Household Members Viewer                                           -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="householdMembersModal">
  <div class="modal-dialog" style="max-width:600px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);" id="hh_title">Household Members</h3>
        <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;" id="hh_sub">Family Details</div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('householdMembersModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="hh_members_list" style="display:flex;flex-direction:column;gap:8px;">
        <!-- Loaded dynamically -->
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('householdMembersModal')">Close</button>
    </div>
  </div>
</div>

<script>
  // Modal utilities
  function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('active');
      if (!document.querySelector('.modal-overlay.active')) {
        document.body.style.overflow = '';
      }
    }
  }

  // Resident quick select helper
  function onSelectResidentQuick(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
      document.getElementById('reg_resident_name').value = opt.getAttribute('data-name') || '';
      document.getElementById('reg_phone').value = opt.getAttribute('data-phone') || '';
    }
  }

  // Raw data from server
  const CURRENT_ACT = <?= json_encode($act, JSON_UNESCAPED_UNICODE) ?>;
  const RAW_ATTENDEES = <?= json_encode($actAttendees, JSON_UNESCAPED_UNICODE) ?>;
  const BARANGAY_HOUSEHOLDS = <?= json_encode($barangayHouseholds, JSON_UNESCAPED_UNICODE) ?>;
  const BARANGAY_MEMBERS = <?= json_encode($barangayMembers, JSON_UNESCAPED_UNICODE) ?>;
  const TARGET_PUROKS = <?= json_encode($targetPuroksList, JSON_UNESCAPED_UNICODE) ?>;
  const IS_ALL_PUROKS = <?= $isAllPuroksScope ? 'true' : 'false' ?>;

  let currentMode = 'household_families'; // 'household_families', 'all_members', 'direct_attendees'
  let currentRoster = [];

  function parseTargetPuroksMatch(purokName) {
    if (IS_ALL_PUROKS) return true;
    if (!purokName) return false;
    const p = purokName.trim().toLowerCase();
    return TARGET_PUROKS.some(tp => {
      const t = tp.trim().toLowerCase();
      return p === t || p.includes(t) || t.includes(p);
    });
  }

  function buildRoster() {
    const matchedAttIds = new Set();
    const result = [];

    if (currentMode === 'household_families') {
      const scopedHouseholds = BARANGAY_HOUSEHOLDS.filter(h => parseTargetPuroksMatch(h.purok_name));

      scopedHouseholds.forEach(h => {
        const famName = (h.family_name || '').trim().toLowerCase();
        const repName = (h.head_full_name || '').trim().toLowerCase();

        let matchedAtt = RAW_ATTENDEES.find(a => {
          if (matchedAttIds.has(a.id)) return false;
          const aName = (a.resident_name || '').toLowerCase();
          return (famName && aName.includes(famName)) || (repName && aName.includes(repName));
        });

        if (matchedAtt) {
          matchedAttIds.add(matchedAtt.id);
        }

        result.push({
          household_id: h.id,
          attendee_id: matchedAtt ? matchedAtt.id : null,
          family_name: h.family_name || 'Family',
          display_name: `${h.family_name || 'Family'} Family`,
          rep_name: h.head_full_name || 'Household Head',
          household_code: h.household_code || '—',
          purok_name: h.purok_name || 'Unassigned',
          phone: h.head_phone || (matchedAtt ? matchedAtt.phone : '') || '—',
          attended: matchedAtt ? (matchedAtt.attended == 1) : false,
          is_registered: !!matchedAtt
        });
      });

    } else if (currentMode === 'all_members') {
      const scopedMembers = BARANGAY_MEMBERS.filter(m => parseTargetPuroksMatch(m.purok_name));

      scopedMembers.forEach(m => {
        const mName = (m.full_name || '').trim().toLowerCase();

        let matchedAtt = RAW_ATTENDEES.find(a => {
          if (matchedAttIds.has(a.id)) return false;
          const aName = (a.resident_name || '').toLowerCase();
          return mName && aName.includes(mName);
        });

        if (matchedAtt) {
          matchedAttIds.add(matchedAtt.id);
        }

        result.push({
          member_id: m.id,
          household_id: m.household_id,
          attendee_id: matchedAtt ? matchedAtt.id : null,
          display_name: m.full_name,
          rep_name: `${m.relationship || 'Member'} &bull; ${m.family_name} Family`,
          household_code: m.household_code || '—',
          purok_name: m.purok_name || 'Unassigned',
          phone: m.phone || (matchedAtt ? matchedAtt.phone : '') || '—',
          attended: matchedAtt ? (matchedAtt.attended == 1) : false,
          is_registered: !!matchedAtt
        });
      });

    } else {
      // Direct registered attendees
      RAW_ATTENDEES.forEach(a => {
        result.push({
          attendee_id: a.id,
          display_name: a.resident_name || 'Resident',
          rep_name: 'Registered Participant',
          household_code: '—',
          purok_name: '—',
          phone: a.phone || '—',
          attended: (a.attended == 1),
          is_registered: true
        });
      });
    }

    currentRoster = result;
    return result;
  }

  function switchRosterMode(mode) {
    currentMode = mode;
    ['tabModeFamilies', 'tabModeMembers', 'tabModeDirect'].forEach(btnId => {
      const b = document.getElementById(btnId);
      if (b) {
        b.style.background = 'transparent';
        b.style.color = 'var(--color-text-secondary)';
        b.style.fontWeight = '500';
      }
    });

    const activeBtn = mode === 'household_families' ? 'tabModeFamilies' : (mode === 'all_members' ? 'tabModeMembers' : 'tabModeDirect');
    const b = document.getElementById(activeBtn);
    if (b) {
      b.style.background = 'var(--color-primary)';
      b.style.color = '#FFFFFF';
      b.style.fontWeight = '700';
    }

    buildRoster();
    filterRoster(document.getElementById('rosterSearchInput')?.value || '');
  }

  function filterRoster(query) {
    const q = (query || '').trim().toLowerCase();
    const tbody = document.getElementById('rosterTableBody');
    if (!tbody) return;

    let list = currentRoster;
    if (q) {
      list = currentRoster.filter(item => {
        return (item.display_name && item.display_name.toLowerCase().includes(q))
          || (item.rep_name && item.rep_name.toLowerCase().includes(q))
          || (item.household_code && item.household_code.toLowerCase().includes(q))
          || (item.purok_name && item.purok_name.toLowerCase().includes(q))
          || (item.phone && item.phone.toLowerCase().includes(q));
      });
    }

    const attendedCount = list.filter(i => i.attended).length;
    const pill = document.getElementById('rosterCountPill');
    if (pill) {
      pill.innerHTML = `<strong>${attendedCount}</strong> Attended &bull; <strong>${list.length}</strong> Total`;
    }

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" style="text-align:center;padding:32px;color:var(--color-text-muted);">
            <div style="font-size:12px;font-weight:600;">No records found matching current criteria.</div>
          </td>
        </tr>
      `;
      return;
    }

    let html = '';
    list.forEach(item => {
      const isAttended = item.attended;
      const toggleLabel = isAttended ? 'Void Attendance' : 'Mark Attended';
      const toggleClass = isAttended ? 'btn-outline' : 'btn-success';

      let membersBtn = '';
      if (item.household_id) {
        membersBtn = `
          <button type="button" class="btn btn-outline btn-xs" onclick="viewHouseholdMembers(${item.household_id})" style="font-size:10px;padding:3px 7px;" title="View Family Members">
            Members
          </button>
        `;
      }

      html += `
        <tr>
          <td>
            <div style="font-weight:600;color:var(--color-primary);font-size:11.5px;">${escapeHtml(item.display_name)}</div>
            <div style="font-size:10px;color:var(--color-text-muted);">${escapeHtml(item.rep_name)}</div>
          </td>
          <td>
            <span class="badge badge-neutral" style="font-size:9.5px;">${escapeHtml(item.purok_name)}</span>
          </td>
          <td style="font-family:var(--font-secondary);font-size:11px;font-weight:600;">
            ${escapeHtml(item.household_code)}
          </td>
          <td style="font-size:11px;color:var(--color-text);">
            ${escapeHtml(item.rep_name)}
          </td>
          <td style="font-size:11px;color:var(--color-text);">
            ${escapeHtml(item.phone)}
          </td>
          <td>
            ${isAttended 
              ? '<span class="badge badge-success" style="font-size:9.5px;font-weight:600;">✓ Attended</span>'
              : (item.is_registered 
                  ? '<span class="badge badge-info" style="font-size:9.5px;">Registered</span>'
                  : '<span class="badge badge-neutral" style="font-size:9.5px;">Unmarked</span>')
            }
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex;gap:4px;align-items:center;">
              <button type="button" class="btn ${toggleClass} btn-xs" onclick="toggleAttendance(${item.attendee_id || 'null'}, '${escapeHtml(item.display_name).replace(/'/g, "\\'")}', '${escapeHtml(item.phone).replace(/'/g, "\\'")}')" style="font-size:10px;padding:3px 8px;">
                ${toggleLabel}
              </button>
              ${item.attendee_id ? `
                <button type="button" class="btn btn-outline btn-xs" onclick="voidAttendee(${item.attendee_id}, '${escapeHtml(item.display_name).replace(/'/g, "\\'")}')" style="font-size:10px;padding:3px 7px;color:#B91C1C;border-color:#FECACA;" title="Void / Remove Attendee Record">
                  Void Attendee
                </button>
              ` : ''}
              ${membersBtn}
            </div>
          </td>
        </tr>
      `;
    });

    tbody.innerHTML = html;
  }

  async function toggleAttendance(attendeeId, residentName, phone) {
    try {
      const fd = new FormData();
      fd.append('activity_id', CURRENT_ACT.id);
      if (attendeeId) fd.append('attendee_id', attendeeId);
      fd.append('resident_name', residentName);
      fd.append('phone', phone);
      fd.append('is_ajax', '1');

      const res = await fetch('<?= BASE_URL ?>/backend/functions/event/toggle_attendance.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        window.location.reload();
      } else {
        alert(data.message || 'Unable to update attendance.');
      }
    } catch (e) {
      console.error(e);
      alert('Network error while toggling attendance.');
    }
  }

  async function voidAttendee(attendeeId, residentName) {
    if (!confirm(`Are you sure you want to void / remove "${residentName}" from this event's attendee roster?`)) return;
    try {
      const fd = new FormData();
      fd.append('action', 'remove_attendee');
      fd.append('activity_id', CURRENT_ACT.id);
      fd.append('attendee_id', attendeeId);
      fd.append('is_ajax', '1');

      const res = await fetch('<?= BASE_URL ?>/backend/functions/event/remove_attendee.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        window.location.reload();
      } else {
        alert(data.message || 'Unable to void attendee.');
      }
    } catch (e) {
      console.error(e);
      alert('Network error while voiding attendee.');
    }
  }

  function viewHouseholdMembers(householdId) {
    const hh = BARANGAY_HOUSEHOLDS.find(h => h.id == householdId);
    const members = BARANGAY_MEMBERS.filter(m => m.household_id == householdId);

    document.getElementById('hh_title').innerText = hh ? `${hh.family_name} Family` : 'Household Members';
    document.getElementById('hh_sub').innerText = hh ? `Code: ${hh.household_code} &bull; Purok: ${hh.purok_name}` : '';

    const container = document.getElementById('hh_members_list');
    if (!members || members.length === 0) {
      container.innerHTML = '<div style="font-size:12px;color:var(--color-text-muted);font-style:italic;">No registered members found for this household.</div>';
    } else {
      let html = '';
      members.forEach(m => {
        html += `
          <div style="display:flex;justify-content:space-between;align-items:center;background:#FFFFFF;border:1px solid var(--color-border);padding:8px 12px;border-radius:6px;">
            <div>
              <div style="font-size:12px;font-weight:600;color:var(--color-primary);">${escapeHtml(m.full_name)}</div>
              <div style="font-size:10.5px;color:var(--color-text-muted);">${escapeHtml(m.relationship || 'Member')} &bull; ${escapeHtml(m.gender || '')} &bull; ${m.age ? m.age + ' yrs' : ''}</div>
            </div>
            ${m.is_authorized_rep == 1 ? '<span class="badge badge-info" style="font-size:9.5px;">Authorized Rep</span>' : ''}
          </div>
        `;
      });
      container.innerHTML = html;
    }

    openModal('householdMembersModal');
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }

  // Initialize roster on page load
  document.addEventListener('DOMContentLoaded', function () {
    switchRosterMode('household_families');
    <?php if (($_GET['action'] ?? '') === 'evaluate'): ?>
      openModal('evaluateModal');
    <?php endif; ?>
  });
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
