<?php
// ============================================================================
// Views (Barangay Head): Preparedness Activities & Community Events Module
// Features: Event Scheduling, Conditional Action Buttons (Not Started vs Started),
// Locked Start/End times on Edit if started, Resident Registration with live SMS dispatch.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

// Self-heal table schema if necessary
$colsAct = $db->query("SHOW COLUMNS FROM events LIKE 'is_archived'")->fetchAll();
if (empty($colsAct)) {
  $db->exec("ALTER TABLE events ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}
$colsCe = $db->query("SHOW COLUMNS FROM events LIKE 'content_execution'")->fetchAll();
if (empty($colsCe)) {
  $db->exec("ALTER TABLE events ADD COLUMN content_execution TEXT NULL AFTER evaluation_summary");
}
$colsEa = $db->query("SHOW COLUMNS FROM events LIKE 'expected_activities'")->fetchAll();
if (empty($colsEa)) {
  $db->exec("ALTER TABLE events ADD COLUMN expected_activities TEXT NULL AFTER content_execution");
}
$colsAtt = $db->query("SHOW COLUMNS FROM event_attendees LIKE 'attended'")->fetchAll();
if (empty($colsAtt)) {
  $db->exec("ALTER TABLE event_attendees ADD COLUMN attended TINYINT(1) NOT NULL DEFAULT 1 AFTER resident_name");
}
$colsTp = $db->query("SHOW COLUMNS FROM events LIKE 'target_puroks'")->fetchAll();
if (empty($colsTp)) {
  $db->exec("ALTER TABLE events ADD COLUMN target_puroks VARCHAR(255) NOT NULL DEFAULT 'All Puroks' AFTER venue");
}

$now = time();
$nowStr = date('Y-m-d H:i:s');

$viewTab = $_GET['tab'] ?? 'active';
if (!in_array($viewTab, ['active', 'archived'], true)) {
  $viewTab = 'active';
}

$search = trim($_GET['search'] ?? '');
$filterType = trim($_GET['type'] ?? '');
$filterVenue = trim($_GET['venue'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

// Count active and archived events
$activeCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE (barangay_id = ? OR barangay_id IS NULL) AND (is_archived = 0 OR is_archived IS NULL)");
$activeCountStmt->execute([$barangayId]);
$activeCount = (int) $activeCountStmt->fetchColumn();

$archivedCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE (barangay_id = ? OR barangay_id IS NULL) AND is_archived = 1");
$archivedCountStmt->execute([$barangayId]);
$archivedCount = (int) $archivedCountStmt->fetchColumn();

// Fetch designated evacuation areas for this barangay as expected venue options & venue filter options
$evacStmt = $db->prepare("
    SELECT id, name, center_type, location_address 
    FROM evacuation_areas 
    WHERE (barangay_id = ? OR barangay_id IS NULL)
    ORDER BY (barangay_id = ?) DESC, name ASC
");
$evacStmt->execute([$barangayId, $barangayId]);
$evacuationVenues = $evacStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active puroks for this barangay with official population records
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

// Fetch active household families for this barangay (to list in event overview)
$hhQueryStmt = $db->prepare("
    SELECT id, household_code, family_name, head_full_name, head_phone, purok_name, purok_id
    FROM purok_households 
    WHERE (barangay_id = ? OR barangay_id IS NULL) AND (status = 'active' OR status IS NULL)
    ORDER BY purok_name ASC, household_code ASC
");
$hhQueryStmt->execute([$barangayId]);
$barangayHouseholds = $hhQueryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active individual household members for this barangay (to list when event is set to all members)
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

// Status breakdown counts for current tab
$baseWhere = "(barangay_id = ? OR barangay_id IS NULL)" . ($viewTab === 'archived' ? " AND is_archived = 1" : " AND (is_archived = 0 OR is_archived IS NULL)");

$upcomingCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE $baseWhere AND status NOT IN ('Completed', 'Cancelled') AND start_datetime > ?");
$upcomingCountStmt->execute([$barangayId, $nowStr]);
$countUpcoming = (int) $upcomingCountStmt->fetchColumn();

$ongoingCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE $baseWhere AND status NOT IN ('Completed', 'Cancelled') AND start_datetime <= ? AND end_datetime >= ?");
$ongoingCountStmt->execute([$barangayId, $nowStr, $nowStr]);
$countOngoing = (int) $ongoingCountStmt->fetchColumn();

$completedCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE $baseWhere AND status = 'Completed'");
$completedCountStmt->execute([$barangayId]);
$countCompleted = (int) $completedCountStmt->fetchColumn();

$cancelledCountStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE $baseWhere AND status = 'Cancelled'");
$cancelledCountStmt->execute([$barangayId]);
$countCancelled = (int) $cancelledCountStmt->fetchColumn();

// Query String helper to preserve filters across actions
function getEventFilterUrl($newParams = [])
{
  $params = $_GET;
  foreach ($newParams as $k => $v) {
    if ($v === null || $v === '') {
      unset($params[$k]);
    } else {
      $params[$k] = $v;
    }
  }
  return '?' . http_build_query($params);
}

// Fetch activities for this barangay based on tab and active filters
$sql = "
    SELECT pa.*, b.name AS barangay_name, u.full_name AS creator_name 
    FROM events pa 
    LEFT JOIN barangays b ON pa.barangay_id = b.id 
    LEFT JOIN users u ON pa.created_by = u.id 
    WHERE (pa.barangay_id = ? OR pa.barangay_id IS NULL)
";
$params = [$barangayId];

if ($viewTab === 'archived') {
  $sql .= " AND pa.is_archived = 1";
} else {
  $sql .= " AND (pa.is_archived = 0 OR pa.is_archived IS NULL)";
}

if (!empty($filterType)) {
  $sql .= " AND pa.activity_type = ?";
  $params[] = $filterType;
}

if (!empty($filterVenue)) {
  $sql .= " AND pa.venue = ?";
  $params[] = $filterVenue;
}

if (!empty($filterStatus)) {
  if ($filterStatus === 'Upcoming') {
    $sql .= " AND pa.status NOT IN ('Completed', 'Cancelled') AND pa.start_datetime > ?";
    $params[] = $nowStr;
  } elseif ($filterStatus === 'Ongoing') {
    $sql .= " AND pa.status NOT IN ('Completed', 'Cancelled') AND pa.start_datetime <= ? AND pa.end_datetime >= ?";
    $params[] = $nowStr;
    $params[] = $nowStr;
  } else {
    $sql .= " AND pa.status = ?";
    $params[] = $filterStatus;
  }
}

if (!empty($search)) {
  $sql .= " AND (pa.title LIKE ? OR pa.venue LIKE ? OR pa.assigned_personnel LIKE ? OR pa.description LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY pa.start_datetime ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch registered residents of this barangay for quick selection in event registration
$resStmt = $db->prepare("
    SELECT id, first_name, last_name, full_name, phone, purok 
    FROM residents 
    WHERE barangay_id = ? AND status != 'archived'
    ORDER BY first_name ASC, last_name ASC
");
$resStmt->execute([$barangayId]);
$barangayResidents = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all attendees for events in this barangay
$attStmt = $db->prepare("
    SELECT aa.*, pa.title AS activity_title 
    FROM event_attendees aa
    JOIN events pa ON aa.activity_id = pa.id
    WHERE (pa.barangay_id = ? OR pa.barangay_id IS NULL)
    ORDER BY aa.registered_at DESC
");
$attStmt->execute([$barangayId]);
$rawAttendees = $attStmt->fetchAll(PDO::FETCH_ASSOC);

$attendeesByActivity = [];
foreach ($rawAttendees as $att) {
  $attendeesByActivity[$att['activity_id']][] = $att;
}

// Fetch recent SMS dispatch logs
$smsLogsStmt = $db->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 50");
$recentSmsLogs = $smsLogsStmt ? $smsLogsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Evacuation venues already fetched above

$defaultExpectedByType = [
  "Health and Medical" => [
    "Medical Mission",
    "Free Medical Checkup",
    "Free Dental Checkup",
    "Free Tooth Extraction (Ibot ng Ngipin)",
    "Free Circumcision (Libreng Tuli)",
    "Free Eye Checkup",
    "Free Eye Glasses Distribution",
    "Free Medicine Distribution",
    "Bloodletting Activity",
    "Free Blood Pressure Check",
    "Blood Sugar Screening",
    "Vaccination Program",
    "Immunization Program",
    "Deworming Program",
    "Family Planning Seminar",
    "Mental Health Awareness Seminar",
    "Health Awareness Seminar",
    "Free Massage",
    "Free Haircut"
  ],
  "Nutrition and Feeding" => [
    "Feeding Program",
    "Supplemental Feeding Program",
    "School Feeding Program",
    "Nutrition Month Celebration",
    "Operation Timbang",
    "Vitamin Supplementation",
    "Food Pack Distribution",
    "Milk Feeding Program"
  ],
  "Cleanliness and Environment" => [
    "Community Clean-Up Drive",
    "Coastal Clean-Up Drive",
    "Tree Planting Activity",
    "Clean and Green Program",
    "Waste Segregation Campaign",
    "Recycling Program",
    "Community Gardening",
    "Canal Cleaning",
    "Anti-Dengue Clean-Up Drive",
    "Gardening Contest"
  ],
  "Youth and Children" => [
    "Children's Day Celebration",
    "Youth Leadership Seminar",
    "Sports Festival",
    "Basketball Tournament",
    "Volleyball Tournament",
    "SK Youth Assembly",
    "Youth Leadership Training",
    "Summer Sports Clinic",
    "Educational Assistance Distribution",
    "School Supplies Distribution",
    "Back-to-School Program",
    "Art Contest",
    "Dance Contest",
    "Singing Contest",
    "Essay Writing Contest"
  ],
  "Education and Skills Training" => [
    "Scholarship Program",
    "Tutorial Program",
    "Reading Program",
    "Literacy Program",
    "Computer Literacy Training",
    "Livelihood Training",
    "Job Skills Training",
    "Financial Literacy Seminar",
    "Entrepreneurship Seminar",
    "Career Guidance Seminar",
    "TESDA Skills Training",
    "Job Fair"
  ],
  "Social Welfare and Assistance" => [
    "Financial Aid Distribution",
    "Financial Assistance Distribution",
    "Food Assistance Distribution",
    "Relief Goods Distribution",
    "Senior Citizens' Assistance",
    "PWD Assistance Program",
    "Solo Parent Assistance",
    "4Ps Assistance and Orientation",
    "Women's Welfare Program",
    "Women's Month Celebration",
    "Child Protection Seminar",
    "Gift-Giving Program",
    "Christmas Gift Distribution",
    "Birthday Gift Distribution for Senior Citizens"
  ],
  "Livelihood and Employment" => [
    "Livelihood Assistance Program",
    "Livelihood Training",
    "Small Business Seminar",
    "Cooperative Development Seminar",
    "Vegetable Seed Distribution",
    "Gardening Livelihood Program",
    "Employment Assistance Program",
    "Job Fair",
    "Free Resume Writing Workshop"
  ],
  "Sports and Recreation" => [
    "Barangay Sportsfest",
    "Inter-Purok Basketball Tournament",
    "Inter-Purok Volleyball Tournament",
    "Badminton Tournament",
    "Fun Run",
    "Zumba Session",
    "Community Aerobics",
    "Chess Tournament",
    "Traditional Filipino Games",
    "Family Day",
    "Community Picnic"
  ],
  "Disaster Preparedness and Safety" => [
    "Disaster Preparedness Seminar",
    "Earthquake Drill",
    "Fire Drill",
    "Fire Prevention Seminar",
    "First Aid Training",
    "Basic Life Support Training",
    "Flood Preparedness Seminar",
    "Emergency Evacuation Drill",
    "Disaster Risk Reduction Seminar",
    "Relief Operations",
    "Emergency Response Training"
  ],
  "Animal Welfare" => [
    "Free Anti-Rabies Vaccination",
    "Free Pet Checkup",
    "Pet Deworming",
    "Responsible Pet Ownership Seminar",
    "Pet Registration",
    "Animal Bite Prevention Seminar",
    "Spay and Neuter Program"
  ],
  "Community Celebrations and Special Events" => [
    "Barangay Fiesta",
    "Barangay Foundation Day",
    "Barangay Anniversary Celebration",
    "Independence Day Celebration",
    "Araw ng Barangay",
    "Christmas Party",
    "New Year's Celebration",
    "Family Day Celebration",
    "Flores de Mayo",
    "Buwan ng Wika Celebration",
    "Nutrition Month Celebration",
    "Valentine's Day Celebration",
    "Mother's Day Celebration",
    "Father's Day Celebration",
    "Community Concert",
    "Barangay Pageant",
    "Street Dance Competition"
  ],
  "Government and Community Services" => [
    "Barangay Assembly",
    "Purok Assembly",
    "Community Consultation",
    "Public Information Campaign",
    "Voter Education Seminar",
    "Free Legal Consultation",
    "Civil Registry Assistance",
    "PhilHealth Registration Assistance",
    "National ID Registration Assistance",
    "Police Clearance Assistance",
    "Senior Citizen ID Registration",
    "PWD ID Registration",
    "Solo Parent ID Registration"
  ],
  "Agricultural Programs" => [
    "Free Seed Distribution",
    "Seedling Distribution",
    "Organic Farming Seminar",
    "Urban Gardening Program",
    "Backyard Gardening Seminar",
    "Free Fertilizer Distribution",
    "Livestock Vaccination",
    "Animal Deworming",
    "Farmers' Training",
    "Agricultural Livelihood Seminar"
  ]
];

$filterActivityTypes = array_keys($defaultExpectedByType);
$now = time();

$pageTitle = "Manage Events — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
  /* Robust Modal Styling for Events Module with Auto-Scrollbar on Height Exceeded */
  .modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(15, 23, 42, 0.65) !important;
    backdrop-filter: blur(4px) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    z-index: 99999 !important;
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    transition: opacity 0.2s ease, visibility 0.2s ease !important;
    margin: 0 !important;
    padding: 16px !important;
    box-sizing: border-box !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
  }

  .modal-overlay.active {
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
  }

  #householdMembersModal.modal-overlay,
  #householdMembersModal.modal-overlay.active {
    z-index: 100005 !important;
  }

  .modal-dialog {
    background: #ffffff !important;
    width: 100% !important;
    max-width: 600px !important;
    max-height: calc(100vh - 36px) !important;
    border-radius: 12px !important;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    border: 1px solid var(--color-border-light, #E2E8F0) !important;
    margin: auto !important;
    position: relative !important;
  }

  /* Ensure any form inside .modal-dialog behaves as a flex column container */
  .modal-dialog form,
  .modal-dialog>form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: 100% !important;
    overflow: hidden !important;
    margin: 0 !important;
  }

  .modal-header {
    padding: 14px 18px !important;
    border-bottom: 1px solid var(--color-border-light, #E2E8F0) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: #F8FAFC !important;
    flex-shrink: 0 !important;
  }

  #evalExecutionTopBanner {
    flex-shrink: 0 !important;
  }

  .modal-body {
    padding: 18px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    -webkit-overflow-scrolling: touch !important;
  }

  .modal-footer {
    padding: 12px 18px !important;
    border-top: 1px solid var(--color-border-light, #E2E8F0) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    background: #F8FAFC !important;
    flex-shrink: 0 !important;
  }

  /* Custom sleek scrollbar for modal body & all scrollable containers */
  .modal-body::-webkit-scrollbar,
  .modal-dialog::-webkit-scrollbar,
  .modal-overlay::-webkit-scrollbar,
  #create_expected_activities_list::-webkit-scrollbar,
  #edit_expected_activities_list::-webkit-scrollbar,
  #editAttendeesListContainer::-webkit-scrollbar,
  #evalExecutionChecklistContainer::-webkit-scrollbar,
  #evalAttendeesListContainer::-webkit-scrollbar,
  #acc_content_execution_box::-webkit-scrollbar,
  #acc_attendees_preview_box::-webkit-scrollbar {
    width: 7px;
    height: 7px;
  }

  .modal-body::-webkit-scrollbar-track,
  .modal-dialog::-webkit-scrollbar-track,
  .modal-overlay::-webkit-scrollbar-track,
  #create_expected_activities_list::-webkit-scrollbar-track,
  #edit_expected_activities_list::-webkit-scrollbar-track,
  #editAttendeesListContainer::-webkit-scrollbar-track,
  #evalExecutionChecklistContainer::-webkit-scrollbar-track,
  #evalAttendeesListContainer::-webkit-scrollbar-track,
  #acc_content_execution_box::-webkit-scrollbar-track,
  #acc_attendees_preview_box::-webkit-scrollbar-track {
    background: #F1F5F9;
    border-radius: 6px;
  }

  .modal-body::-webkit-scrollbar-thumb,
  .modal-dialog::-webkit-scrollbar-thumb,
  .modal-overlay::-webkit-scrollbar-thumb,
  #create_expected_activities_list::-webkit-scrollbar-thumb,
  #edit_expected_activities_list::-webkit-scrollbar-thumb,
  #editAttendeesListContainer::-webkit-scrollbar-thumb,
  #evalExecutionChecklistContainer::-webkit-scrollbar-thumb,
  #evalAttendeesListContainer::-webkit-scrollbar-thumb,
  #acc_content_execution_box::-webkit-scrollbar-thumb,
  #acc_attendees_preview_box::-webkit-scrollbar-thumb {
    background: #94A3B8;
    border-radius: 6px;
  }

  .modal-body::-webkit-scrollbar-thumb:hover,
  .modal-dialog::-webkit-scrollbar-thumb:hover,
  .modal-overlay::-webkit-scrollbar-thumb:hover,
  #create_expected_activities_list::-webkit-scrollbar-thumb:hover,
  #edit_expected_activities_list::-webkit-scrollbar-thumb:hover,
  #editAttendeesListContainer::-webkit-scrollbar-thumb:hover,
  #evalExecutionChecklistContainer::-webkit-scrollbar-thumb:hover,
  #evalAttendeesListContainer::-webkit-scrollbar-thumb:hover,
  #acc_content_execution_box::-webkit-scrollbar-thumb:hover,
  #acc_attendees_preview_box::-webkit-scrollbar-thumb:hover {
    background: #64748B;
  }

  .modal-body,
  .modal-dialog,
  .modal-overlay {
    scrollbar-width: thin;
    scrollbar-color: #94A3B8 #F1F5F9;
  }

  .sms-preview-card {
    background: #F8FAFC;
    border: 1px solid #CBD5E1;
    border-left: 4px solid var(--color-primary, #0284C7);
    border-radius: 6px;
    padding: 12px;
    font-family: monospace, sans-serif;
    font-size: 11px;
    line-height: 1.5;
    color: #1E293B;
    white-space: pre-wrap;
    margin-top: 6px;
  }

  .purok-checkbox-item {
    transition: all 0.15s ease;
    user-select: none;
  }

  .purok-checkbox-item:hover {
    border-color: var(--color-primary, #17324D) !important;
    background: #F0FDF4 !important;
  }

  #create_purok_checkbox_list::-webkit-scrollbar,
  #edit_purok_checkbox_list::-webkit-scrollbar {
    width: 6px;
  }

  #create_purok_checkbox_list::-webkit-scrollbar-thumb,
  #edit_purok_checkbox_list::-webkit-scrollbar-thumb {
    background: #94A3B8;
    border-radius: 6px;
  }
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Manage Events — <?= clean($barangay['name'] ?? '') ?></h1>
    <p class="page-header-desc">
      Community disaster simulations, tsunami/flood drills, family preparedness orientations, and rescuer training
      events.
    </p>
  </div>
  <div class="page-header-actions" style="display:flex;gap:8px;">
    <button type="button" class="btn btn-outline" onclick="openModal('smsOutboxModal')"
      title="View recent SMS dispatches, delivery statuses, and gateway logs">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        style="margin-right:4px;">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
      </svg>
      SMS Dispatch Logs (<?= count($recentSmsLogs) ?>)
    </button>
    <button class="btn btn-primary" onclick="openCreateActivityModal()">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Schedule Barangay Drill
    </button>
  </div>
</div>

<!-- Tabs Navigation: Active Events vs Archived Events -->
<div class="nav-tabs" style="margin-bottom: var(--space-3, 12px);">
  <a href="<?= getEventFilterUrl(['tab' => 'active']) ?>"
    class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
      <line x1="16" y1="2" x2="16" y2="6"></line>
      <line x1="8" y1="2" x2="8" y2="6"></line>
      <line x1="3" y1="10" x2="21" y2="10"></line>
    </svg>
    Active Events
    <span class="nav-tab-badge"><?= $activeCount ?></span>
  </a>
  <a href="<?= getEventFilterUrl(['tab' => 'archived']) ?>"
    class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="21 8 21 21 3 21 3 8"></polyline>
      <rect x="1" y="3" width="22" height="5"></rect>
      <line x1="10" y1="12" x2="14" y2="12"></line>
    </svg>
    Archived Events
    <span class="nav-tab-badge"><?= $archivedCount ?></span>
  </a>
</div>

<!-- Quick Status Filter Selection Buttons -->
<div class="evac-filter-pills" style="margin-bottom: 12px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
  <span
    style="font-size:11px; font-weight:700; color:var(--color-text-secondary); margin-right:4px; text-transform:uppercase; letter-spacing:0.3px;">
    Quick Status:
  </span>
  <a href="<?= getEventFilterUrl(['status' => '']) ?>"
    class="evac-pill-btn <?= empty($filterStatus) ? 'active' : '' ?>">
    All Events <span class="badge <?= empty($filterStatus) ? 'badge-primary' : 'badge-neutral' ?>"
      style="font-size:9px; padding:1px 5px;"><?= $viewTab === 'archived' ? $archivedCount : $activeCount ?></span>
  </a>
  <a href="<?= getEventFilterUrl(['status' => 'Upcoming']) ?>"
    class="evac-pill-btn <?= $filterStatus === 'Upcoming' ? 'active' : '' ?>">
    Upcoming <span class="badge <?= $filterStatus === 'Upcoming' ? 'badge-primary' : 'badge-neutral' ?>"
      style="font-size:9px; padding:1px 5px;"><?= $countUpcoming ?></span>
  </a>
  <a href="<?= getEventFilterUrl(['status' => 'Ongoing']) ?>"
    class="evac-pill-btn <?= $filterStatus === 'Ongoing' ? 'active' : '' ?>">
    In Progress / Started <span class="badge <?= $filterStatus === 'Ongoing' ? 'badge-primary' : 'badge-neutral' ?>"
      style="font-size:9px; padding:1px 5px;"><?= $countOngoing ?></span>
  </a>
  <a href="<?= getEventFilterUrl(['status' => 'Completed']) ?>"
    class="evac-pill-btn <?= $filterStatus === 'Completed' ? 'active' : '' ?>">
    Completed <span class="badge <?= $filterStatus === 'Completed' ? 'badge-primary' : 'badge-neutral' ?>"
      style="font-size:9px; padding:1px 5px;"><?= $countCompleted ?></span>
  </a>
  <?php if ($countCancelled > 0): ?>
    <a href="<?= getEventFilterUrl(['status' => 'Cancelled']) ?>"
      class="evac-pill-btn <?= $filterStatus === 'Cancelled' ? 'active' : '' ?>">
      Cancelled <span class="badge <?= $filterStatus === 'Cancelled' ? 'badge-primary' : 'badge-neutral' ?>"
        style="font-size:9px; padding:1px 5px;"><?= $countCancelled ?></span>
    </a>
  <?php endif; ?>
</div>

<!-- Search & Filter Selection Form Card -->
<div class="card"
  style="margin-bottom: var(--space-4, 16px); background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px;">
  <div class="card-body" style="padding: 12px 16px;">
    <form method="GET" action="" class="filter-form"
      style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
      <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">

      <!-- Search Input -->
      <div style="flex:2; min-width:200px;">
        <label
          style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--color-text-muted); display:block; margin-bottom:4px; letter-spacing:0.3px;">
          Search Activity
        </label>
        <div style="position:relative; display:flex; align-items:center;">
          <svg style="position:absolute; left:10px; width:14px; height:14px; color:#94A3B8; pointer-events:none;"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" name="search" class="form-control" style="padding-left:32px; font-size:12px; height:36px;"
            placeholder="Search title, facilitator, keyword..." value="<?= clean($search) ?>">
        </div>
      </div>

      <!-- Activity Type Filter Selection -->
      <div style="flex:1.2; min-width:170px;">
        <label
          style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--color-text-muted); display:block; margin-bottom:4px; letter-spacing:0.3px;">
          Activity Type
        </label>
        <select name="type" class="form-control" style="font-size:12px; height:36px;">
          <option value="">All Activity Types</option>
          <?php foreach ($filterActivityTypes as $tName): ?>
            <option value="<?= clean($tName) ?>" <?= $filterType === $tName ? 'selected' : '' ?>>
              <?= clean($tName) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Venue Location Filter Selection (Strictly Evacuation Areas) -->
      <div style="flex:1.5; min-width:200px;">
        <label
          style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--color-text-muted); display:block; margin-bottom:4px; letter-spacing:0.3px;">
          Venue Location (Evacuation Areas)
        </label>
        <select name="venue" class="form-control" style="font-size:12px; height:36px;">
          <option value="">All Evacuation Areas</option>
          <?php foreach ($evacuationVenues as $ev): ?>
            <option value="<?= htmlspecialchars($ev['name']) ?>" <?= $filterVenue === $ev['name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($ev['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Status Filter Selection -->
      <div style="flex:1; min-width:130px;">
        <label
          style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--color-text-muted); display:block; margin-bottom:4px; letter-spacing:0.3px;">
          Status
        </label>
        <select name="status" class="form-control" style="font-size:12px; height:36px;">
          <option value="">All Statuses</option>
          <option value="Upcoming" <?= $filterStatus === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
          <option value="Ongoing" <?= $filterStatus === 'Ongoing' ? 'selected' : '' ?>>In Progress / Started</option>
          <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
          <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>

      <!-- Action Buttons: Filter Selection Button & Reset Button -->
      <div style="display:flex; gap:6px; align-items:center;">
        <button type="submit" class="btn btn-primary" id="btnFilterSelection"
          style="height:36px; padding:0 15px; font-size:12px; display:inline-flex; align-items:center; gap:6px; font-weight:600;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
          </svg>
          Filter Selection
        </button>
        <?php if (!empty($search) || !empty($filterType) || !empty($filterVenue) || !empty($filterStatus)): ?>
          <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline"
            style="height:36px; padding:0 12px; font-size:12px; color:var(--color-text-muted); display:inline-flex; align-items:center; gap:4px;"
            title="Reset All Filters">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
            Reset
          </a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Active Filters Summary Line if any filter is set -->
    <?php if (!empty($search) || !empty($filterType) || !empty($filterVenue) || !empty($filterStatus)): ?>
      <div
        style="margin-top:10px; padding-top:8px; border-top:1px dashed #E2E8F0; display:flex; justify-content:space-between; align-items:center; font-size:11px; flex-wrap:wrap; gap:8px;">
        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
          <span style="font-weight:600; color:var(--color-text-secondary);">Applied Filters:</span>
          <?php if (!empty($search)): ?>
            <a href="<?= getEventFilterUrl(['search' => '']) ?>" class="badge badge-neutral"
              style="text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
              Keyword: "<?= clean($search) ?>" &times;
            </a>
          <?php endif; ?>
          <?php if (!empty($filterType)): ?>
            <a href="<?= getEventFilterUrl(['type' => '']) ?>" class="badge badge-info"
              style="text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
              Type: <?= clean($filterType) ?> &times;
            </a>
          <?php endif; ?>
          <?php if (!empty($filterVenue)): ?>
            <a href="<?= getEventFilterUrl(['venue' => '']) ?>" class="badge badge-success"
              style="text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
              Venue: <?= clean($filterVenue) ?> &times;
            </a>
          <?php endif; ?>
          <?php if (!empty($filterStatus)): ?>
            <a href="<?= getEventFilterUrl(['status' => '']) ?>" class="badge badge-primary"
              style="text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
              Status: <?= clean($filterStatus) ?> &times;
            </a>
          <?php endif; ?>
          <a href="?tab=<?= clean($viewTab) ?>"
            style="font-size:10.5px; color:#DC2626; margin-left:6px; font-weight:600; text-decoration:underline;">
            Clear All
          </a>
        </div>
        <span style="color:var(--color-text-muted); font-size:10.5px; font-weight:600;">
          Showing <?= count($activities) ?> event(s)
        </span>
      </div>
    <?php else: ?>
      <div style="margin-top:6px; display:flex; justify-content:flex-end;">
        <span style="color:var(--color-text-muted); font-size:10px; font-weight:600;">
          Total: <?= count($activities) ?> event(s) in <?= $viewTab === 'archived' ? 'archives' : 'active list' ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th>Schedule Date & Time</th>
        <th>Activity Title</th>
        <th>Type</th>
        <th>Venue Location</th>
        <th>Lead Facilitator</th>
        <th>Attendance / Target</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($activities)): ?>
        <tr>
          <td colspan="8" style="text-align:center;padding:36px;">
            <div style="font-size:13px;font-weight:600;color:var(--color-text);">No preparedness events found</div>
            <div style="font-size:11px;color:var(--color-text-muted);margin-top:4px;">
              <?= (!empty($search) || !empty($filterType) || !empty($filterVenue) || !empty($filterStatus))
                ? 'No activities match the current filter selection.'
                : 'No activities found in ' . ($viewTab === 'archived' ? 'archived' : 'active') . ' list.' ?>
            </div>
            <?php if (!empty($search) || !empty($filterType) || !empty($filterVenue) || !empty($filterStatus)): ?>
              <div style="margin-top:10px;">
                <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline btn-xs"
                  style="font-size:11px;padding:4px 10px;">
                  Clear Filter Selection
                </a>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($activities as $act): ?>
          <?php
          $startTime = strtotime($act['start_datetime']);
          $endTime = strtotime($act['end_datetime']);
          $isStarted = ($now >= $startTime);
          $isCompleted = ($act['status'] === 'Completed');
          $isCancelled = ($act['status'] === 'Cancelled');
          $isArchived = !empty($act['is_archived']);
          $actAttendees = $attendeesByActivity[$act['id']] ?? [];
          ?>
          <tr>
            <td style="font-family:var(--font-secondary);font-size:10px;white-space:nowrap;">
              <div style="font-weight:600;color:var(--color-primary);"><?= formatDate($act['start_datetime'], 'M d, Y') ?>
              </div>
              <div style="font-size:9px;color:var(--color-text-muted);">
                <?= formatDate($act['start_datetime'], 'h:i A') ?> — <?= formatDate($act['end_datetime'], 'h:i A') ?>
              </div>
              <?php if ($isArchived): ?>
                <span
                  style="display:inline-block;margin-top:2px;font-size:8.5px;color:#64748B;background:#F1F5F9;padding:1px 5px;border-radius:4px;font-weight:600;">Archived</span>
              <?php elseif (!$isStarted && !$isCompleted && !$isCancelled): ?>
                <span
                  style="display:inline-block;margin-top:2px;font-size:8.5px;color:var(--color-info,#0284C7);background:#E0F2FE;padding:1px 5px;border-radius:4px;font-weight:600;">Upcoming</span>
              <?php elseif ($isStarted && !$isCompleted && !$isCancelled): ?>
                <span
                  style="display:inline-block;margin-top:2px;font-size:8.5px;color:#16A34A;background:#DCFCE7;padding:1px 5px;border-radius:4px;font-weight:600;">●
                  Started / In Progress</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-weight:600;color:var(--color-primary);">
                <?= clean(preg_replace('/^Purok\s*[\w\d-]+\s*[-–—:]*\s*/i', '', $act['title'])) ?>
              </div>
              <?php $scopePuroks = clean($act['target_puroks'] ?? 'All Puroks'); ?>
              <div
                style="font-size:9.5px;color:<?= ($scopePuroks === 'All Puroks') ? 'var(--color-teal, #2F6F73)' : '#0284C7' ?>;margin-top:3px;display:flex;align-items:center;gap:3px;font-weight:600;"
                title="Target Purok Scope: <?= $scopePuroks ?>">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                  <circle cx="12" cy="10" r="3"></circle>
                </svg>
                <span><?= ($scopePuroks === 'All Puroks') ? 'All Puroks (Barangay-Wide)' : $scopePuroks ?></span>
              </div>
            </td>
            <td><span class="badge badge-info"><?= clean($act['activity_type']) ?></span></td>
            <td><?= clean($act['venue']) ?></td>
            <td style="font-size:10px;color:var(--color-text-secondary);font-weight:500;">
              <?= clean($act['assigned_personnel']) ?>
            </td>
            <td style="font-family:var(--font-secondary);">
              <?php if ($act['status'] === 'Completed'): ?>
                <span style="font-weight:700;color:var(--color-success);"><?= (int) $act['actual_participants'] ?> /
                  <?= (int) $act['target_participants'] ?> attended</span>
              <?php else: ?>
                <div style="font-size:10.5px;font-weight:600;color:var(--color-primary);">
                  Registered: <?= count($actAttendees) ?> / <?= (int) $act['target_participants'] ?>
                </div>
                <?php if (count($actAttendees) > 0): ?>
                  <button type="button" class="btn btn-outline btn-xs" style="font-size:9.5px;padding:1px 5px;margin-top:2px;"
                    onclick='openViewAttendeesModal(<?= json_encode($act) ?>, <?= json_encode($actAttendees) ?>)'>
                    View List (<?= count($actAttendees) ?>)
                  </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isArchived): ?>
                <span class="badge badge-neutral" style="font-size:9.5px;">Archived</span>
              <?php else: ?>
                <?= renderStatusBadge($act['status']) ?>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:4px;align-items:center;">
                <button type="button" class="btn btn-primary btn-sm"
                  onclick='openAccomplishedEventModal(<?= json_encode($act) ?>, <?= json_encode($actAttendees) ?>)'
                  style="font-size:11px;padding:4px 10px;display:inline-flex;align-items:center;gap:4px;"
                  title="View Event Overview">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                  View
                </button>
                <?php if ($isArchived): ?>
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick='confirmDeleteActivity(<?= json_encode($act) ?>, <?= count($actAttendees) ?>)'
                    style="font-size:11px;padding:4px 8px;color:#DC2626;border-color:#FCA5A5;display:inline-flex;align-items:center;gap:3px;"
                    title="Permanently Delete Event and Registered Attendees">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="3 6 5 6 21 6"></polyline>
                      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                    Delete
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================================= -->
<!-- Modal 1: Schedule Barangay Drill / Event                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Schedule Barangay Drill
        / Event</h3>
      <button class="modal-close-btn" onclick="closeModal('createActivityModal')">&times;</button>
    </div>
    <form id="createActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Activity Title</label>
          <input type="text" name="title" class="form-control"
            placeholder="e.g. Flood Evacuation & Early Warning Simulation Drill" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Type</label>
          <select name="activity_type" id="create_activity_type" class="form-control"
            onchange="onActivityTypeChange('create', this.value)" required>
            <?php foreach (array_keys($defaultExpectedByType) as $idx => $tName): ?>
              <option value="<?= clean($tName) ?>" <?= $idx === 0 ? 'selected' : '' ?>><?= clean($tName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Expected Activities Checkbox List & Customize Button -->
        <div class="form-group" style="margin-top:10px;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label" style="margin:0;font-size:11.5px;font-weight:700;color:var(--color-primary);">
              Event Activities
            </label>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn btn-outline btn-xs"
                onclick="toggleExpectedActivitiesCheckboxes('create', true)"
                style="font-size:9.5px;padding:2px 7px;">Check All</button>
              <button type="button" class="btn btn-outline btn-xs"
                onclick="toggleExpectedActivitiesCheckboxes('create', false)"
                style="font-size:9.5px;padding:2px 7px;">Clear</button>
            </div>
          </div>
          <div style="font-size:10px;color:var(--color-text-muted);margin-bottom:6px;">
            Check the activities expected to be conducted during this event:
          </div>

          <div id="create_expected_activities_list"
            style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:10px;display:grid;grid-template-columns:1fr;gap:6px;max-height:190px;overflow-y:auto;">
            <!-- Populated dynamically via JS on activity type selection -->
          </div>

          <!-- Customize / Add Custom Activity Button & Input -->
          <div
            style="margin-top:8px;padding-top:8px;border-top:1px dashed #CBD5E1;display:flex;gap:6px;align-items:center;">
            <input type="text" id="create_custom_activity_input" class="form-control"
              style="font-size:11px;padding:5px 8px;" placeholder="Add custom activity expected in this event..."
              onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomExpectedActivity('create');}">
            <button type="button" class="btn btn-outline btn-xs" onclick="addCustomExpectedActivity('create')"
              style="font-size:11px;padding:5px 11px;white-space:nowrap;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);">
              + Add Activity
            </button>
          </div>
        </div>

        <!-- Target Purok Scope Selection (Single, Multi, or All Puroks) -->
        <div class="form-group" style="margin-top:10px;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label form-label-required"
              style="margin:0;font-size:11.5px;font-weight:700;color:var(--color-primary);display:flex;align-items:center;gap:5px;">
              Target Purok Scope
            </label>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="setAllPuroksScope('create', true)"
                style="font-size:9.5px;padding:2px 7px;">Select All</button>
              <button type="button" class="btn btn-outline btn-xs" onclick="setAllPuroksScope('create', false)"
                style="font-size:9.5px;padding:2px 7px;">Clear</button>
            </div>
          </div>

          <!-- Master All Puroks Toggle Card -->
          <div
            style="background:#F1F5F9;border:1px solid #CBD5E1;border-radius:6px;padding:7px 10px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
            <label
              style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;font-size:11.5px;font-weight:600;color:var(--color-primary);">
              <input type="checkbox" id="create_all_puroks_toggle"
                onchange="onAllPuroksToggleChange('create', this.checked)" checked
                style="cursor:pointer;width:15px;height:15px;accent-color:var(--color-primary);">
              <span>All Puroks (Barangay-Wide)</span>
            </label>
            <span class="badge badge-info" id="create_all_puroks_badge"
              style="font-size:9.5px;padding:2px 6px;"><?= count($barangayPuroks) ?> Puroks
              (<?= number_format($totalPurokPopulation) ?> Residents)</span>
          </div>

          <!-- Counting Basis Toggle: Household Families vs All Members -->
          <input type="hidden" name="target_type" id="create_target_type" value="household_families">
          <div
            style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:6px;padding:7px 10px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <label
              style="display:flex;align-items:center;gap:7px;margin:0;cursor:pointer;font-size:11.5px;font-weight:600;color:#0F766E;">
              <input type="checkbox" id="create_count_by_families"
                onchange="onCountingUnitCheckboxChange('create', this.checked)"
                style="cursor:pointer;width:15px;height:15px;accent-color:#0D9488;">
              <span>Count by <strong>House Families</strong> (1 per Household Representative) <span
                  style="font-size:10px;color:#64748B;font-weight:normal;">— Uncheck for all members</span></span>
            </label>
            <span id="create_counting_unit_badge" class="badge"
              style="font-size:9.5px;padding:2px 7px;background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;">
              All Members
            </span>
          </div>

          <!-- Quick Filter Input -->
          <div style="position:relative;margin-bottom:6px;">
            <input type="text" id="create_purok_search" class="form-control"
              placeholder="Search / filter puroks (e.g. Purok 1, 2a, 5b)..."
              oninput="filterPurokCheckboxes('create', this.value)"
              style="font-size:11px;padding:4px 8px;padding-left:26px;height:28px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="position:absolute;left:8px;top:8px;color:#94A3B8;pointer-events:none;">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
          </div>

          <!-- Multi-Select Checkboxes Grid -->
          <div id="create_purok_checkbox_list"
            style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px;display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:6px;max-height:140px;overflow-y:auto;">
            <?php foreach ($barangayPuroks as $p): ?>
              <?php
              $pPop = (int) ($p['population'] ?? 0);
              $pHouseholds = (int) ($p['households'] ?? 0);
              ?>
              <label class="purok-checkbox-item" data-purok="<?= clean($p['name']) ?>" data-population="<?= $pPop ?>"
                data-households="<?= $pHouseholds ?>"
                style="display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:11px;color:var(--color-text);background:#ECFDF5;padding:5px 8px;border-radius:4px;border:1px solid var(--color-primary, #17324D);margin:0;cursor:pointer;">
                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                  <input type="checkbox" class="create-purok-cb" value="<?= clean($p['name']) ?>"
                    data-population="<?= $pPop ?>" data-households="<?= $pHouseholds ?>" checked
                    onchange="onSinglePurokChange('create')"
                    style="cursor:pointer;accent-color:var(--color-primary);margin:0;flex-shrink:0;">
                  <span
                    style="user-select:none;line-height:1.2;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= clean($p['name']) ?></span>
                </div>
                <span class="create-purok-pill" data-pop="<?= $pPop ?>" data-hh="<?= $pHouseholds ?>"
                  style="flex-shrink:0;font-size:9.5px;color:var(--color-primary);font-weight:700;background:rgba(23,50,77,0.08);padding:1px 5px;border-radius:3px;"
                  title="Recorded Population">
                  <?= number_format($pPop) ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <!-- Live Selection Feedback Label & Hidden Submission Input -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px;">
            <div id="create_purok_selection_summary"
              style="font-size:10.5px;font-weight:600;color:var(--color-primary);">
              Selected: All Puroks (Barangay-Wide) • <?= number_format($totalPurokPopulation) ?> residents
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);">
              Select single, multi, or all
            </div>
          </div>
          <input type="hidden" name="target_puroks" id="create_target_puroks_hidden" value="All Puroks">
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Venue Location (Evacuation Areas)</label>
          <select name="venue" id="create_venue_select" class="form-control" required>
            <option value="" disabled selected>-- Select Evacuation Area --</option>
            <?php foreach ($evacuationVenues as $ev): ?>
              <option value="<?= htmlspecialchars($ev['name']) ?>">
                <?= htmlspecialchars($ev['name']) ?> (<?= htmlspecialchars($ev['center_type'] ?? 'Evacuation Center') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Start Date & Time</label>
            <input type="datetime-local" name="start_datetime" class="form-control" required
              value="<?= date('Y-m-d\TH:00', strtotime('+1 day')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">End Date & Time</label>
            <input type="datetime-local" name="end_datetime" class="form-control" required
              value="<?= date('Y-m-d\TH:00', strtotime('+1 day +3 hours')) ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Lead Facilitator</label>
            <input type="text" name="assigned_personnel" class="form-control"
              placeholder="e.g. Barangay Disaster Committee, CDRRMO Facilitator" required>
          </div>
          <div class="form-group">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <label class="form-label form-label-required" id="create_target_label" style="margin:0;">Target
                Residents</label>
              <span id="create_target_pop_hint"
                style="font-size:10px;font-weight:700;color:var(--color-primary);background:#EFF6FF;padding:1px 6px;border-radius:3px;border:1px solid #BFDBFE;">
                Recorded Pop: <?= number_format($totalPurokPopulation) ?>
              </span>
            </div>
            <input type="number" name="target_participants" id="create_target_participants" class="form-control" min="1"
              value="<?= (int) $totalPurokPopulation ?>" required>
            <div id="create_target_calc_subtext" style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;">
              Auto-calculated from selected purok population records
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Brief Description & Objectives</label>
          <textarea name="description" class="form-control" rows="2"
            placeholder="Drill scenarios, safety briefings, assembly sites..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('createActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Schedule Activity</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: Edit Event Modal (Times Locked if Started)                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);"
        id="editModalHeaderTitle">Edit Event Details</h3>
      <button class="modal-close-btn" onclick="closeModal('editActivityModal')">&times;</button>
    </div>
    <form id="editActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="edit_act_id">

      <div class="modal-body">
        <!-- Locked time notice banner -->
        <div id="edit_locked_time_notice"
          style="display:none;background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;padding:10px 12px;border-radius:6px;font-size:11.5px;font-weight:600;margin-bottom:12px;">
          ⚠️ This event has already started. In accordance with operational integrity, <strong>Start Date & Time and End
            Date & Time cannot be edited</strong>.
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Title</label>
          <input type="text" name="title" id="edit_act_title" class="form-control" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Type</label>
          <select name="activity_type" id="edit_act_type" class="form-control"
            onchange="onActivityTypeChange('edit', this.value)" required>
            <?php foreach (array_keys($defaultExpectedByType) as $tName): ?>
              <option value="<?= clean($tName) ?>"><?= clean($tName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Expected Activities Checkbox List & Customize Button for Edit -->
        <div class="form-group" style="margin-top:10px;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label" style="margin:0;font-size:11.5px;font-weight:700;color:var(--color-primary);">
              Event Activities
            </label>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn btn-outline btn-xs"
                onclick="toggleExpectedActivitiesCheckboxes('edit', true)"
                style="font-size:9.5px;padding:2px 7px;">Check All</button>
              <button type="button" class="btn btn-outline btn-xs"
                onclick="toggleExpectedActivitiesCheckboxes('edit', false)"
                style="font-size:9.5px;padding:2px 7px;">Clear</button>
            </div>
          </div>
          <div style="font-size:10px;color:var(--color-text-muted);margin-bottom:6px;">
            Activities expected to be executed during this event:
          </div>

          <div id="edit_expected_activities_list"
            style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:10px;display:grid;grid-template-columns:1fr;gap:6px;max-height:190px;overflow-y:auto;">
            <!-- Populated dynamically via JS -->
          </div>

          <!-- Customize / Add Custom Activity Button & Input for Edit -->
          <div
            style="margin-top:8px;padding-top:8px;border-top:1px dashed #CBD5E1;display:flex;gap:6px;align-items:center;">
            <input type="text" id="edit_custom_activity_input" class="form-control"
              style="font-size:11px;padding:5px 8px;" placeholder="Add custom activity milestone..."
              onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomExpectedActivity('edit');}">
            <button type="button" class="btn btn-outline btn-xs" onclick="addCustomExpectedActivity('edit')"
              style="font-size:11px;padding:5px 11px;white-space:nowrap;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);">
              + Add Activity
            </button>
          </div>
        </div>

        <!-- Target Purok Scope Selection for Edit (Single, Multi, or All Puroks) -->
        <div class="form-group" style="margin-top:10px;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="form-label form-label-required"
              style="margin:0;font-size:11.5px;font-weight:700;color:var(--color-primary);display:flex;align-items:center;gap:5px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
              Target Purok Scope
            </label>
            <div style="display:flex;gap:6px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="setAllPuroksScope('edit', true)"
                style="font-size:9.5px;padding:2px 7px;">Select All</button>
              <button type="button" class="btn btn-outline btn-xs" onclick="setAllPuroksScope('edit', false)"
                style="font-size:9.5px;padding:2px 7px;">Clear</button>
            </div>
          </div>

          <!-- Master All Puroks Toggle Card for Edit -->
          <div
            style="background:#F1F5F9;border:1px solid #CBD5E1;border-radius:6px;padding:7px 10px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
            <label
              style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;font-size:11.5px;font-weight:600;color:var(--color-primary);">
              <input type="checkbox" id="edit_all_puroks_toggle"
                onchange="onAllPuroksToggleChange('edit', this.checked)" checked
                style="cursor:pointer;width:15px;height:15px;accent-color:var(--color-primary);">
              <span>All Puroks (Barangay-Wide)</span>
            </label>
            <span class="badge badge-info" id="edit_all_puroks_badge"
              style="font-size:9.5px;padding:2px 6px;"><?= count($barangayPuroks) ?> Puroks
              (<?= number_format($totalPurokPopulation) ?> Residents)</span>
          </div>

          <!-- Counting Basis Toggle: Household Families vs All Members for Edit -->
          <input type="hidden" name="target_type" id="edit_target_type" value="household_families">
          <div
            style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:6px;padding:7px 10px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <label
              style="display:flex;align-items:center;gap:7px;margin:0;cursor:pointer;font-size:11.5px;font-weight:600;color:#0F766E;">
              <input type="checkbox" id="edit_count_by_families"
                onchange="onCountingUnitCheckboxChange('edit', this.checked)"
                style="cursor:pointer;width:15px;height:15px;accent-color:#0D9488;">
              <span>Count by <strong>House Families</strong> (1 per Household Representative) <span
                  style="font-size:10px;color:#64748B;font-weight:normal;">— Uncheck for all members</span></span>
            </label>
            <span id="edit_counting_unit_badge" class="badge"
              style="font-size:9.5px;padding:2px 7px;background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;">
              All Members
            </span>
          </div>

          <!-- Quick Filter Input for Edit -->
          <div style="position:relative;margin-bottom:6px;">
            <input type="text" id="edit_purok_search" class="form-control"
              placeholder="Search / filter puroks (e.g. Purok 1, 2a, 5b)..."
              oninput="filterPurokCheckboxes('edit', this.value)"
              style="font-size:11px;padding:4px 8px;padding-left:26px;height:28px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="position:absolute;left:8px;top:8px;color:#94A3B8;pointer-events:none;">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
          </div>

          <!-- Multi-Select Checkboxes Grid for Edit -->
          <div id="edit_purok_checkbox_list"
            style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px;display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:6px;max-height:140px;overflow-y:auto;">
            <?php foreach ($barangayPuroks as $p): ?>
              <?php
              $pPop = (int) ($p['population'] ?? 0);
              $pHouseholds = (int) ($p['households'] ?? 0);
              ?>
              <label class="purok-checkbox-item" data-purok="<?= clean($p['name']) ?>" data-population="<?= $pPop ?>"
                data-households="<?= $pHouseholds ?>"
                style="display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:11px;color:var(--color-text);background:#ECFDF5;padding:5px 8px;border-radius:4px;border:1px solid var(--color-primary, #17324D);margin:0;cursor:pointer;">
                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                  <input type="checkbox" class="edit-purok-cb" value="<?= clean($p['name']) ?>"
                    data-population="<?= $pPop ?>" data-households="<?= $pHouseholds ?>" checked
                    onchange="onSinglePurokChange('edit')"
                    style="cursor:pointer;accent-color:var(--color-primary);margin:0;flex-shrink:0;">
                  <span
                    style="user-select:none;line-height:1.2;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= clean($p['name']) ?></span>
                </div>
                <span class="edit-purok-pill" data-pop="<?= $pPop ?>" data-hh="<?= $pHouseholds ?>"
                  style="flex-shrink:0;font-size:9.5px;color:var(--color-primary);font-weight:700;background:rgba(23,50,77,0.08);padding:1px 5px;border-radius:3px;"
                  title="Recorded Population">
                  <?= number_format($pPop) ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <!-- Live Selection Feedback Label & Hidden Submission Input for Edit -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px;">
            <div id="edit_purok_selection_summary" style="font-size:10.5px;font-weight:600;color:var(--color-primary);">
              Selected: All Puroks (Barangay-Wide) • <?= number_format($totalPurokPopulation) ?> residents
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);">
              Select single, multi, or all
            </div>
          </div>
          <input type="hidden" name="target_puroks" id="edit_target_puroks_hidden" value="All Puroks">
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Venue Location (Evacuation Areas)</label>
          <select name="venue" id="edit_venue_select" class="form-control" required>
            <option value="" disabled>-- Select Evacuation Area --</option>
            <?php foreach ($evacuationVenues as $ev): ?>
              <option value="<?= htmlspecialchars($ev['name']) ?>">
                <?= htmlspecialchars($ev['name']) ?> (<?= htmlspecialchars($ev['center_type'] ?? 'Evacuation Center') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Start Date & Time</label>
            <input type="datetime-local" name="start_datetime" id="edit_act_start" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">End Date & Time</label>
            <input type="datetime-local" name="end_datetime" id="edit_act_end" class="form-control" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Lead Facilitator</label>
            <input type="text" name="assigned_personnel" id="edit_act_personnel" class="form-control" required>
          </div>
          <div class="form-group">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <label class="form-label form-label-required" id="edit_target_label" style="margin:0;">Target
                Residents</label>
              <span id="edit_target_pop_hint"
                style="font-size:10px;font-weight:700;color:var(--color-primary);background:#EFF6FF;padding:1px 6px;border-radius:3px;border:1px solid #BFDBFE;">
                Recorded Pop: 0
              </span>
            </div>
            <input type="number" name="target_participants" id="edit_act_target" class="form-control" min="1" required>
            <div id="edit_target_calc_subtext" style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;">
              Auto-calculated from selected purok population records
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Brief Description & Objectives</label>
          <textarea name="description" id="edit_act_desc" class="form-control" rows="2"></textarea>
        </div>

        <!-- Section: Registered Participants (Add & Remove participants when editing event) -->
        <div
          style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 14px;margin-top:14px;margin-bottom:6px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <div>
              <span style="font-size:12px;font-weight:700;color:var(--color-primary);"> Registered Participants</span>
              <div style="font-size:10px;color:var(--color-text-muted);">Manage and add attendees for this event:</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span
                style="font-size:10px;font-weight:700;color:var(--color-primary);background:#E0F2FE;padding:2px 6px;border-radius:4px;"
                id="editAttendeeCountBadge">
                0 Registered
              </span>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleEditAddParticipantPanel()"
                style="font-size:10px;padding:3px 8px;color:var(--color-primary);border-color:var(--color-primary);font-weight:600;">
                + Add Participant
              </button>
            </div>
          </div>

          <!-- Add Participant Panel for Edit Modal -->
          <div id="edit_add_participant_panel"
            style="display:none;background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
            <div
              style="font-size:11px;font-weight:700;color:var(--color-primary);margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">
              <span>➕ Add Participant</span>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleEditAddParticipantPanel(false)"
                style="font-size:9.5px;padding:1px 5px;">Close</button>
            </div>
            <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:8px;margin-bottom:8px;">
              <div style="position:relative;">
                <label style="font-size:9.5px;font-weight:600;color:#64748B;display:block;margin-bottom:2px;">Name
                  *</label>
                <input type="text" id="edit_add_part_name" class="form-control" style="font-size:11px;padding:4px 8px;"
                  placeholder="Search resident or type walk-in..." autocomplete="off"
                  oninput="onLiveResidentSearch(this.value, 'edit')"
                  onkeydown="if(event.key==='Enter'){event.preventDefault();submitAddParticipant('edit');}">
                <input type="hidden" id="edit_add_part_resident_id" value="">
                <div id="edit_add_part_suggestions"
                  style="display:none;position:absolute;top:100%;left:0;right:0;background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,0.12);max-height:150px;overflow-y:auto;z-index:200;margin-top:2px;">
                </div>
                <div id="edit_matched_resident_badge"
                  style="display:none;font-size:9.5px;color:#16A34A;font-weight:600;margin-top:2px;"></div>
              </div>
              <div>
                <label
                  style="font-size:9.5px;font-weight:600;color:#64748B;display:block;margin-bottom:2px;">Phone</label>
                <input type="text" id="edit_add_part_phone" class="form-control"
                  style="font-size:11px;padding:4px 8px;font-family:monospace;" placeholder="09XXXXXXXXX (optional)"
                  onkeydown="if(event.key==='Enter'){event.preventDefault();submitAddParticipant('edit');}">
              </div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label
                style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--color-text);cursor:pointer;margin:0;">
                <input type="checkbox" id="edit_add_part_attended" checked style="cursor:pointer;">
                <span>Attended Event ✓</span>
              </label>
              <button type="button" class="btn btn-primary btn-xs" id="edit_save_part_btn"
                onclick="submitAddParticipant('edit')" style="font-size:10.5px;padding:4px 12px;font-weight:600;">
                Add Participant
              </button>
            </div>
          </div>

          <div id="editAttendeesListContainer" style="max-height:160px;overflow-y:auto;">
            <!-- Loaded dynamically per activity attendees -->
          </div>
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
<!-- Modal 3A: View Accomplished Event Overview (Archive, Edit, Evaluate)       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="accomplishedEventModal">
  <div class="modal-dialog" style="max-width:760px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);" id="view_acc_title">
          Event Overview</h3>
        <span class="badge badge-success" style="font-size:10px;font-weight:600;" id="view_acc_status_badge">✓
          Accomplished</span>
      </div>
      <button class="modal-close-btn" onclick="closeModal('accomplishedEventModal')">&times;</button>
    </div>
    <div class="modal-body" style="padding:16px 20px;">
      <!-- Title & Type Header -->
      <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <div>
          <h4 style="font-size:14px;font-weight:700;color:var(--color-primary);margin:0 0 2px 0;" id="acc_display_title"></h4>
          <div style="font-size:11px;color:var(--color-text-secondary);" id="acc_display_type_wrap"></div>
        </div>
        <span id="acc_display_type_badge" class="badge badge-info" style="font-size:10px;padding:3px 8px;"></span>
      </div>

      <!-- Meta Information Grid (Unified Palette, Anti-Slop) -->
      <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:8px;margin-bottom:12px;">
        <!-- Card 1: Schedule -->
        <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:8px 10px;display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;border-radius:5px;background:var(--color-surface-subtle);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.3px;">Schedule</div>
            <div style="font-size:11px;font-weight:600;color:var(--color-text);margin-top:1px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="acc_display_schedule"></div>
          </div>
        </div>

        <!-- Card 2: Venue -->
        <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:8px 10px;display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;border-radius:5px;background:var(--color-surface-subtle);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.3px;">Venue & Scope</div>
            <div style="font-size:11px;font-weight:600;color:var(--color-text);margin-top:1px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="acc_display_venue"></div>
          </div>
        </div>

        <!-- Card 3: Lead Facilitator -->
        <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:8px 10px;display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;border-radius:5px;background:var(--color-surface-subtle);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.3px;">Facilitator</div>
            <div style="font-size:11px;font-weight:600;color:var(--color-text);margin-top:1px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="acc_display_facilitator"></div>
          </div>
        </div>

        <!-- Card 4: Attendance Turnout -->
        <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:8px 10px;display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;border-radius:5px;background:var(--color-surface-subtle);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.3px;">Turnout</div>
            <div style="font-size:11px;font-weight:600;color:var(--color-primary);margin-top:1px;line-height:1.3;" id="acc_display_attendance"></div>
          </div>
        </div>
      </div>

      <!-- Description -->
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label" style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:0.3px;margin-bottom:4px;">Description</label>
        <div style="font-size:11.5px;line-height:1.45;color:var(--color-text);background:#FFFFFF;padding:8px 10px;border-radius:var(--radius-sm, 6px);border:1px solid var(--color-border);" id="acc_display_description"></div>
      </div>

      <!-- Event Content Execution Milestones -->
      <div class="form-group" style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <label class="form-label" style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;margin:0;letter-spacing:0.3px;">
            Execution Milestones
          </label>
          <span style="font-size:10.5px;color:var(--color-primary);font-weight:600;" id="acc_content_count_label"></span>
        </div>
        <div id="acc_content_execution_box" style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:8px 10px;max-height:130px;overflow-y:auto;">
          <!-- Loaded dynamically -->
        </div>
      </div>

      <!-- Accomplishment Remarks (Shown when exists) -->
      <div class="form-group" id="acc_summary_group" style="margin-bottom:12px;display:none;">
        <label class="form-label" style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:0.3px;margin-bottom:4px;">
          Remarks & Outcomes
        </label>
        <div style="font-size:11.5px;line-height:1.45;color:var(--color-text);background:#FFFFFF;padding:8px 10px;border-radius:var(--radius-sm, 6px);border:1px solid var(--color-border);white-space:pre-wrap;" id="acc_display_summary"></div>
      </div>

      <!-- Participant Attendees -->
      <div class="form-group" style="margin-bottom:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;flex-wrap:wrap;">
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <label class="form-label" style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;margin:0;letter-spacing:0.3px;">
              Participant Attendees
            </label>
            <div style="display:inline-flex;border:1px solid var(--color-border);border-radius:4px;overflow:hidden;background:var(--color-surface-subtle);">
              <button type="button" id="acc_roster_tab_families" onclick="setAccomplishedRosterMode('household_families')" style="font-size:9.5px;font-weight:600;padding:2px 7px;border:none;cursor:pointer;background:var(--color-primary);color:#FFFFFF;transition:all 0.15s ease;">
                House Families
              </button>
              <button type="button" id="acc_roster_tab_members" onclick="setAccomplishedRosterMode('all_members')" style="font-size:9.5px;font-weight:500;padding:2px 7px;border:none;cursor:pointer;background:transparent;color:var(--color-text-secondary);transition:all 0.15s ease;">
                All Members
              </button>
            </div>
            <span style="font-size:10px;font-weight:600;color:var(--color-primary);background:var(--color-surface-subtle);padding:1px 6px;border-radius:4px;border:1px solid var(--color-border);" id="acc_attendees_pill"></span>
          </div>
          <div>
            <input type="text" id="acc_attendees_search" placeholder="Filter roster..." oninput="filterAccomplishedAttendees(this.value)" class="form-control" style="width:170px;height:26px;font-size:10.5px;padding:2px 6px;">
          </div>
        </div>

        <div id="acc_attendees_preview_box" style="max-height:260px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);background:#FFFFFF;">
          <!-- Loaded dynamically -->
        </div>
      </div>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
      <div id="acc_footer_left"></div>
      <div style="display:flex;gap:6px;" id="acc_footer_right"></div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3A-2: View Household Family Members                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="householdMembersModal" style="z-index: 100005 !important;">
  <div class="modal-dialog" style="max-width:620px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);" id="hh_members_modal_title">
          Family Members
        </h3>
        <span class="badge badge-neutral" style="font-size:10px;font-weight:600;" id="hh_members_badge"></span>
      </div>
      <button class="modal-close-btn" onclick="closeModal('householdMembersModal')">&times;</button>
    </div>
    <div class="modal-body" style="padding:16px 20px;">
      <!-- Household Summary Card -->
      <div style="background:#FFFFFF;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--color-primary);" id="hh_members_family_name">—</div>
          <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;" id="hh_members_rep_info">—</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:0.3px;font-weight:600;">Purok & Code</div>
          <div style="font-size:11px;font-weight:600;color:var(--color-text);" id="hh_members_purok_code">—</div>
        </div>
      </div>

      <!-- Members Table Container -->
      <div class="form-group" style="margin-bottom:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <label class="form-label" style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;margin:0;letter-spacing:0.3px;">
            Registered Family Members
          </label>
          <span style="font-size:10px;font-weight:600;color:var(--color-primary);background:var(--color-surface-subtle);padding:1px 6px;border-radius:4px;border:1px solid var(--color-border);" id="hh_members_count_pill">
            0 Members
          </span>
        </div>
        <div id="hh_members_table_box" style="max-height:260px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-sm, 6px);background:#FFFFFF;">
          <!-- Loaded dynamically -->
        </div>
      </div>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;align-items:center;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('householdMembersModal')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3B: Confirm Move Event to Archives                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="archiveActivityModal">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-warning,#D97706);">Archive Event
      </h3>
      <button class="modal-close-btn" onclick="closeModal('archiveActivityModal')">&times;</button>
    </div>
    <form id="archiveActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/index.php">
      <input type="hidden" name="action" value="archive">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="archive_act_id">
      <div class="modal-body">
        <p style="font-size:12.5px;color:var(--color-text);line-height:1.5;">
          Are you sure you want to move the event <strong id="archive_act_name"></strong> to the Archives?
        </p>
        <div
          style="font-size:11px;color:var(--color-text-muted);background:#FFFBEB;border:1px solid #FCD34D;border-radius:6px;padding:8px 10px;margin-top:10px;line-height:1.45;">
          All registered participant families and household members for this event will also be archived with it.<br><br>
          Archived events and participant records are kept for historical records and can be reviewed or restored anytime from the
          <strong>Archived Events</strong> tab.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm"
          onclick="closeModal('archiveActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-warning btn-sm"
          style="background:#D97706;border-color:#D97706;color:#fff;">Yes, Move to Archives</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3B-2: Confirm Delete Event & Attendees Permanently                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deleteActivityModal">
  <div class="modal-dialog" style="max-width:460px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:#DC2626;">Permanently Delete Event</h3>
      <button class="modal-close-btn" onclick="closeModal('deleteActivityModal')">&times;</button>
    </div>
    <form id="deleteActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/index.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="delete_act_id">
      <div class="modal-body">
        <p style="font-size:12.5px;color:var(--color-text);line-height:1.5;">
          Are you sure you want to permanently delete <strong id="delete_act_name"></strong>?
        </p>
        <div style="font-size:11px;color:#991B1B;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:6px;padding:9px 12px;margin-top:10px;line-height:1.45;">
          <strong>Warning: This action cannot be undone.</strong><br>
          This event and all its registered participant families, household members, and attendance records (<span id="delete_attendees_info">0 registered</span>) will be permanently deleted. (Barangay master household records will remain safe.)
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('deleteActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-sm" style="background:#DC2626;border-color:#DC2626;color:#fff;font-weight:600;">Yes, Delete Permanently</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3C: Evaluate Activity (Content Execution Checklist & Attendees List) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="evaluateModal">
  <div class="modal-dialog" style="max-width:720px;">
    <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div>
          <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);"
            id="evalModalHeaderTitle">Evaluate Event & Execution</h3>
          <div style="font-size:10px;color:var(--color-text-muted);margin-top:1px;" id="evalActName"></div>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('evaluateModal')">&times;</button>
    </div>

    <!-- Top Execution Completion Rate Banner (Positioned right at the top after header / navbar) -->
    <div id="evalExecutionTopBanner"
      style="background:linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%);border-bottom:1px solid #E2E8F0;padding:12px 20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div id="evalExecutionIconWrap"
            style="width:34px;height:34px;border-radius:8px;background:#ECFDF5;color:#059669;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(0,0,0,0.04);flex-shrink:0;transition:all 0.2s ease;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
          </div>
          <div>
            <div style="font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">
              Expected Activities Execution Rate</div>
            <div style="font-size:11.5px;color:#1E293B;font-weight:600;" id="evalExecutionCountSubtext">0 of 0 expected
              activities completed</div>
          </div>
        </div>
        <div style="text-align:right;">
          <span id="evalExecutionPercentageBadge"
            style="font-size:22px;font-weight:800;color:#059669;line-height:1;font-feature-settings:'tnum';transition:color 0.2s ease;">100%</span>
          <div style="font-size:9.5px;font-weight:600;color:#059669;margin-top:2px;transition:color 0.2s ease;"
            id="evalExecutionStatusLabel">Full Execution (100%) ✓</div>
        </div>
      </div>
      <!-- Dynamic Animated Progress Bar Fill -->
      <div
        style="height:7px;background:#E2E8F0;border-radius:4px;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,0.06);">
        <div id="evalExecutionProgressBar"
          style="height:100%;width:100%;background:linear-gradient(90deg, #10B981, #059669);transition:width 0.25s ease, background 0.25s ease;border-radius:4px;">
        </div>
      </div>
    </div>

    <form id="evaluateForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/evaluate.php">
      <input type="hidden" name="action" value="evaluate">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="evalActId">
      <input type="hidden" name="expected_activities" id="evalExpectedActivitiesJson">
      <input type="hidden" name="has_attendee_roster" value="1">

      <div class="modal-body" style="padding:16px 20px;">
        <!-- Status & Target Info Row -->
        <div class="form-row" style="margin-bottom:12px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label form-label-required">Activity Status</label>
            <select name="status" class="form-control" required id="evalStatusSelect">
              <option value="Completed" selected>Completed (Successfully Accomplished)</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label form-label-required">Total Actual Headcount Attended</label>
            <input type="number" name="actual_participants" id="evalActualInput" class="form-control" min="0" required
              placeholder="0">
            <span style="font-size:9.5px;color:var(--color-text-muted);">Includes registered attendees and
              walk-ins.</span>
          </div>
        </div>

        <!-- Section 1: Checkbox Event Content Execution -->
        <div
          style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 14px;margin-bottom:16px;">
          <div
            style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;border-bottom:1px solid #E2E8F0;padding-bottom:6px;">
            <div>
              <span style="font-size:12px;font-weight:700;color:var(--color-primary);">📋 Expected Activities
                Checklist</span>
              <div style="font-size:10px;color:var(--color-text-muted);">Check off the expected activities that were
                executed during this event:</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span class="badge badge-success" id="evalExecutionSectionBadge"
                style="font-size:10px;font-weight:700;">100%</span>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleExecutionCheckboxes(true)"
                style="font-size:9.5px;padding:2px 6px;">Check All</button>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleExecutionCheckboxes(false)"
                style="font-size:9.5px;padding:2px 6px;">Clear All</button>
            </div>
          </div>

          <div id="evalExecutionChecklistContainer"
            style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:8px 14px;">
            <!-- Dynamically populated execution items with checkboxes -->
          </div>

          <!-- Add custom execution milestone -->
          <div
            style="margin-top:10px;padding-top:8px;border-top:1px dashed #CBD5E1;display:flex;gap:6px;align-items:center;">
            <input type="text" id="customExecutionInput" class="form-control" style="font-size:11px;padding:4px 8px;"
              placeholder="Add custom event execution milestone..."
              onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomExecutionItem();}">
            <button type="button" class="btn btn-outline btn-xs" onclick="addCustomExecutionItem()"
              style="font-size:10.5px;padding:5px 9px;white-space:nowrap;">+ Add Item</button>
          </div>
        </div>

        <!-- Section 2: List Participants Attended -->
        <div
          style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 14px;margin-bottom:16px;">
          <div
            style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;border-bottom:1px solid #E2E8F0;padding-bottom:6px;">
            <div>
              <span style="font-size:12px;font-weight:700;color:var(--color-primary);"> Participants Attended</span>
              <div style="font-size:10px;color:var(--color-text-muted);">Check off attendees who actively attended this
                event:</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span
                style="font-size:10px;font-weight:700;color:var(--color-success);background:#DCFCE7;padding:2px 6px;border-radius:4px;"
                id="evalAttendeeCountBadge">
                Attended: 0 / 0
              </span>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleEvalAddParticipantPanel()"
                style="font-size:9.5px;padding:2px 6px;color:var(--color-primary);border-color:var(--color-primary);font-weight:600;">
                + Add Participant
              </button>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleAttendeeCheckboxes(true)"
                style="font-size:9.5px;padding:2px 6px;">Select All</button>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleAttendeeCheckboxes(false)"
                style="font-size:9.5px;padding:2px 6px;">Deselect All</button>
            </div>
          </div>

          <!-- Add Participant Panel for Evaluate Modal -->
          <div id="eval_add_participant_panel"
            style="display:none;background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
            <div
              style="font-size:11px;font-weight:700;color:var(--color-primary);margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">
              <span>➕ Add Participant</span>
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleEvalAddParticipantPanel(false)"
                style="font-size:9.5px;padding:1px 5px;">Close</button>
            </div>
            <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:8px;margin-bottom:8px;">
              <div style="position:relative;">
                <label style="font-size:9.5px;font-weight:600;color:#64748B;display:block;margin-bottom:2px;">Name
                  *</label>
                <input type="text" id="eval_add_part_name" class="form-control" style="font-size:11px;padding:4px 8px;"
                  placeholder="Search resident or type walk-in..." autocomplete="off"
                  oninput="onLiveResidentSearch(this.value, 'eval')">
                <input type="hidden" id="eval_add_part_resident_id" value="">
                <div id="eval_add_part_suggestions"
                  style="display:none;position:absolute;top:100%;left:0;right:0;background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,0.12);max-height:150px;overflow-y:auto;z-index:200;margin-top:2px;">
                </div>
                <div id="eval_matched_resident_badge"
                  style="display:none;font-size:9.5px;color:#16A34A;font-weight:600;margin-top:2px;"></div>
              </div>
              <div>
                <label
                  style="font-size:9.5px;font-weight:600;color:#64748B;display:block;margin-bottom:2px;">Phone</label>
                <input type="text" id="eval_add_part_phone" class="form-control"
                  style="font-size:11px;padding:4px 8px;font-family:monospace;" placeholder="09XXXXXXXXX (optional)">
              </div>
            </div>
            <div style="display:flex;justify-content:flex-end;">
              <button type="button" class="btn btn-primary btn-xs" id="eval_save_part_btn"
                onclick="submitAddParticipant('eval')" style="font-size:10.5px;padding:4px 12px;font-weight:600;">
                Add to List
              </button>
            </div>
          </div>

          <div id="evalAttendeesListContainer" style="max-height:180px;overflow-y:auto;">
            <!-- Loaded dynamically per activity attendees -->
          </div>
        </div>

        <!-- Section 3: Accomplishment Remarks & Outcomes -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Accomplishment Remarks & Evaluation Summary</label>
          <textarea name="evaluation_summary" id="evalSummaryTextarea" class="form-control" rows="3"
            placeholder="Summary of drill execution, community turnout, gaps observed, key takeaways..."
            required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('evaluateModal')">Cancel</button>
        <button type="submit" id="evalSubmitBtn" class="btn btn-success btn-sm"
          style="background:var(--color-success,#16A34A);border-color:var(--color-success,#16A34A);color:#fff;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="margin-right:2px;vertical-align:-1px;">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          <span id="evalSubmitBtnText">Save Evaluation & Execution</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 4: Confirm Cancel Activity                                          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="cancelActivityModal">
  <div class="modal-dialog" style="max-width:500px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <div
          style="width:28px;height:28px;border-radius:50%;background:#FEE2E2;color:#DC2626;display:flex;align-items:center;justify-content:center;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
          </svg>
        </div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-danger,#DC2626);">Cancel Event
          Confirmation</h3>
      </div>
      <button class="modal-close-btn" onclick="closeModal('cancelActivityModal')">&times;</button>
    </div>
    <form id="cancelActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/event/cancel.php"
      onsubmit="return validateCancellationForm();">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="cancel_act_id">
      <div class="modal-body" style="padding:16px 20px;">
        <!-- Alert Banner -->
        <div
          style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:12px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"
            style="flex-shrink:0;margin-top:1px;">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
          <div style="font-size:11.5px;color:#991B1B;line-height:1.45;">
            Are you sure you want to cancel <strong id="cancel_act_name" style="color:#7F1D1D;"></strong>? This will
            mark the activity as <strong>Cancelled</strong> and record the justification in official event audits.
          </div>
        </div>

        <!-- Select Selection for Possible Reason -->
        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label"
            style="font-size:11px;font-weight:700;color:var(--color-text-secondary);text-transform:uppercase;">
            Reason for Cancellation <span style="color:#DC2626;">*</span>
          </label>
          <select name="cancellation_reason_category" id="cancel_reason_select" class="form-control" required
            style="font-size:12px;cursor:pointer;" onchange="onCancelReasonSelectChange(this.value)">
            <option value="" disabled selected>-- Select a reason --</option>
            <option value="Severe Weather / Typhoon Warning / Flood Alert">Severe Weather / Typhoon Warning / Flood
              Alert</option>
            <option value="Real-time Calamity Deployment / Emergency Response">Real-time Calamity Deployment / Emergency
              Response</option>
            <option value="Venue Unavailability / Facility Hazard / Power Outage">Venue Unavailability / Facility Hazard
              / Power Outage</option>
            <option value="LGU Directive / Inter-Agency Schedule Conflict">LGU Directive / Inter-Agency Schedule
              Conflict</option>
            <option value="Lead Facilitator / Speaker Medical Emergency">Lead Facilitator / Speaker Medical Emergency
            </option>
            <option value="Insufficient Turnout / Attendance Quorum Not Met">Insufficient Turnout / Attendance Quorum
              Not Met</option>
            <option value="Post-Event Invalidation / Duplicate Entry">Post-Event Invalidation / Duplicate Entry</option>
            <option value="Other Reason">Other Reason (Please specify in textbox below)</option>
          </select>
        </div>

        <!-- Textbox / Textarea for Reason Details -->
        <div class="form-group" style="margin-bottom:4px;">
          <label class="form-label"
            style="font-size:11px;font-weight:700;color:var(--color-text-secondary);text-transform:uppercase;">
            Cancellation Remarks / Details <span style="color:#DC2626;">*</span>
          </label>
          <textarea name="cancellation_reason_details" id="cancel_reason_details" class="form-control" rows="3" required
            style="font-size:12px;line-height:1.45;resize:vertical;"
            placeholder="Provide additional explanation or justification for cancelling this event..."></textarea>
          <div style="font-size:10px;color:var(--color-text-muted);margin-top:3px;">
            This reason will be recorded on the event ledger and visible in event audits.
          </div>
        </div>
      </div>
      <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('cancelActivityModal')">No, Keep
          Event</button>
        <button type="submit" class="btn btn-danger btn-sm" style="background:#DC2626;border-color:#DC2626;color:#fff;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="margin-right:3px;vertical-align:-1px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
          </svg>
          Yes, Cancel Event
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 5: Register Resident by Mobile & Send SMS Notice                    -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="registerResidentModal">
  <div class="modal-dialog" style="max-width:580px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Register Resident &
          Send SMS Notice</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">Event has started — Register community attendees via
          mobile phone</div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('registerResidentModal')">&times;</button>
    </div>
    <form id="registerResidentForm" method="POST"
      action="<?= BASE_URL ?>/backend/functions/event/register_resident.php">
      <input type="hidden" name="action" value="register_resident">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="activity_id" id="reg_act_id">
      <input type="hidden" name="user_id" id="reg_user_id">

      <div class="modal-body">
        <!-- Event Identity Summary -->
        <div style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:12px;margin-bottom:14px;">
          <div style="font-size:13px;font-weight:700;color:#0369A1;" id="reg_display_event_title"></div>
          <div style="font-size:11px;color:#0C4A6E;margin-top:4px;" id="reg_display_event_meta"></div>
        </div>

        <!-- Quick Select Resident -->
        <div class="form-group">
          <label class="form-label">Select from Registered Barangay Residents (Optional)</label>
          <select id="reg_resident_select" class="form-control" onchange="onSelectResidentQuick(this)">
            <option value="">-- Choose Resident (Auto-fills name & mobile number) --</option>
            <?php foreach ($barangayResidents as $r): ?>
              <?php $displayName = clean($r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name'])); ?>
              <option value="<?= $r['id'] ?>" data-name="<?= $displayName ?>"
                data-phone="<?= clean($r['phone'] ?? '') ?>">
                <?= $displayName ?>   <?= !empty($r['purok']) ? '(' . clean($r['purok']) . ')' : '' ?>
                <?= !empty($r['phone']) ? '— ' . clean($r['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Resident Full Name</label>
            <input type="text" name="resident_name" id="reg_resident_name" class="form-control" required
              placeholder="e.g. Maria Santos" oninput="updateSmsPreview()">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Resident Mobile Number</label>
            <input type="text" name="phone" id="reg_phone" class="form-control" required placeholder="09XXXXXXXXX"
              oninput="updateSmsPreview()">
          </div>
        </div>

        <!-- Live SMS Notice Preview -->
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
            <span>SMS Message to be Delivered:</span>
            <span style="font-size:10px;color:var(--color-primary);font-weight:600;">📱 Live Preview</span>
          </label>
          <div class="sms-preview-card" id="reg_sms_preview_box"></div>
        </div>

        <!-- Registered Attendees Count for this Event -->
        <div style="margin-top:12px;font-size:11px;color:var(--color-text-muted);" id="reg_attendees_summary_line">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm"
          onclick="closeModal('registerResidentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 2L11 13"></path>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
          Register & Send SMS
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 6: View Registered Attendees & SMS Logs                             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewAttendeesModal">
  <div class="modal-dialog" style="max-width:620px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);"
          id="view_att_modal_title">Event Registered Attendees</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">Attendance list and SMS dispatch delivery log</div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('viewAttendeesModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="view_att_list_container"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewAttendeesModal')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 7: SMS Outbox & System Delivery Logs                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="smsOutboxModal">
  <div class="modal-dialog" style="max-width:760px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">SMS Outbox & Dispatch
          History</h3>
      </div>
      <button class="modal-close-btn" onclick="closeModal('smsOutboxModal')">&times;</button>
    </div>
    <div class="modal-body">
      <?php if (empty($recentSmsLogs)): ?>
        <div style="text-align:center;padding:32px;color:var(--color-text-muted);font-size:12px;">
          No SMS dispatch logs found yet. When an event is scheduled or a resident is registered, messages appear here.
        </div>
      <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
          <table class="data-table" style="font-size:11px;width:100%;">
            <thead>
              <tr>
                <th style="width:110px;">Date & Time</th>
                <th>Recipient</th>
                <th style="width:90px;">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentSmsLogs as $log): ?>
                <?php
                $isSent = ($log['status'] === 'sent');
                $isSimulated = (strpos($log['status'], 'simulated') !== false);
                $badgeClass = $isSent ? 'badge-success' : ($isSimulated ? 'badge-info' : 'badge-danger');
                $badgeLabel = $isSent ? 'Sent ✓' : ($isSimulated ? 'Simulated' : 'Failed ✗');
                ?>
                <tr>
                  <td style="font-size:10px;white-space:nowrap;color:var(--color-text-muted);">
                    <?= date('M d, Y', strtotime($log['created_at'])) ?><br>
                    <?= date('h:i:s A', strtotime($log['created_at'])) ?>
                  </td>
                  <td>
                    <div style="font-weight:600;color:var(--color-primary);">
                      <?= clean($log['recipient_name'] ?: 'Resident') ?>
                    </div>
                    <div style="font-family:monospace;font-size:10px;color:var(--color-text-muted);">
                      <?= clean($log['phone']) ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge <?= $badgeClass ?>" style="font-size:9.5px;" title="<?= clean($log['status']) ?>">
                      <?= $badgeLabel ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('smsOutboxModal')">Close</button>
    </div>
  </div>
</div>

<script>
  // Modal Controls
  function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.add('active');
      const body = el.querySelector('.modal-body');
      if (body) body.scrollTop = 0;
      el.scrollTop = 0;
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

  const EXPECTED_ACTIVITIES_BY_TYPE = <?= json_encode($defaultExpectedByType, JSON_UNESCAPED_UNICODE) ?>;
  const TOTAL_PUROK_POPULATION = <?= (int) $totalPurokPopulation ?>;
  const TOTAL_PUROK_HOUSEHOLDS = <?= (int) $totalPurokHouseholds ?>;
  const ALL_BARANGAY_HOUSEHOLDS = <?= json_encode($barangayHouseholds, JSON_UNESCAPED_UNICODE) ?>;
  const ALL_BARANGAY_MEMBERS = <?= json_encode($barangayMembers, JSON_UNESCAPED_UNICODE) ?>;

  function onActivityTypeChange(prefix, type) {
    const container = document.getElementById(prefix + '_expected_activities_list');
    if (!container) return;

    const items = EXPECTED_ACTIVITIES_BY_TYPE[type] || Object.values(EXPECTED_ACTIVITIES_BY_TYPE)[0] || [];
    let html = '';
    items.forEach(item => {
      html += `
      <div class="expected-activity-item" style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text);background:#fff;padding:6px 9px;border-radius:5px;border:1px solid #E2E8F0;transition:all 0.15s ease;">
        <input type="checkbox" name="content_execution[]" value="${item.replace(/"/g, '&quot;')}" checked onchange="onExpectedActivityItemCheckboxChange('${prefix}', this)" style="margin:0;cursor:pointer;">
        <span style="line-height:1.4;flex:1;cursor:pointer;" onclick="const cb = this.previousElementSibling; cb.checked = !cb.checked; onExpectedActivityItemCheckboxChange('${prefix}', cb);">${item}</span>
        <button type="button" onclick="this.closest('.expected-activity-item').remove(); onExpectedActivityItemCheckboxChange('${prefix}');" title="Delete this activity" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 5px;font-size:14px;line-height:1;border-radius:3px;display:flex;align-items:center;justify-content:center;transition:color 0.15s;" onmouseover="this.style.color='#DC2626'" onmouseout="this.style.color='#94A3B8'">
          &times;
        </button>
      </div>
    `;
    });
    container.innerHTML = html;

    // Check if Activity Type is Social Welfare and Assistance (defaults to House Families)
    const shouldDefaultFamilies = (type === 'Social Welfare and Assistance');
    const familyCb = document.getElementById(prefix + '_count_by_families');
    if (familyCb) {
      familyCb.checked = shouldDefaultFamilies;
      onCountingUnitCheckboxChange(prefix, shouldDefaultFamilies);
    }
  }

  function onExpectedActivityItemCheckboxChange(prefix, changedCb) {
    const container = document.getElementById(prefix + '_expected_activities_list');
    if (!container) return;
    const checkedBoxes = Array.from(container.querySelectorAll('input[name="content_execution[]"]:checked'));
    const hasFinancialAidChecked = checkedBoxes.some(cb => {
      const val = cb.value.toLowerCase();
      return val.includes('financial aid') || val.includes('financial assistance') || val.includes('aid distribution') || val.includes('food assistance') || val.includes('relief goods');
    });

    const familyCb = document.getElementById(prefix + '_count_by_families');
    if (familyCb) {
      if (hasFinancialAidChecked && !familyCb.checked) {
        familyCb.checked = true;
        onCountingUnitCheckboxChange(prefix, true);
      }
    }
  }

  function onCountingUnitCheckboxChange(mode, isFamilies) {
    const badge = document.getElementById(mode + '_counting_unit_badge');
    const targetLabel = document.getElementById(mode + '_target_label');
    const calcSubtext = document.getElementById(mode + '_target_calc_subtext');
    const targetTypeInput = document.getElementById(mode + '_target_type');

    if (targetTypeInput) {
      targetTypeInput.value = isFamilies ? 'household_families' : 'all_members';
    }

    if (badge) {
      if (isFamilies) {
        badge.innerHTML = ' House Families';
        badge.style.background = '#CCFBF1';
        badge.style.color = '#0F766E';
        badge.style.borderColor = '#5EEAD4';
      } else {
        badge.innerHTML = ' All Members';
        badge.style.background = '#F1F5F9';
        badge.style.color = '#475569';
        badge.style.borderColor = '#CBD5E1';
      }
    }

    if (targetLabel) {
      targetLabel.innerText = isFamilies ? 'Target Households / Families' : 'Target Residents';
    }

    if (calcSubtext) {
      calcSubtext.innerText = isFamilies
        ? 'Auto-calculated from selected purok household records (1 per household representative)'
        : 'Auto-calculated from selected purok population records (all individuals)';
    }

    // Update each purok count pill representation dynamically
    const pillEls = document.querySelectorAll('.' + mode + '-purok-pill');
    pillEls.forEach(pill => {
      if (isFamilies) {
        const hh = parseInt(pill.getAttribute('data-hh') || '0', 10);
        pill.innerHTML = ` ${hh.toLocaleString()} fam`;
        pill.title = 'Recorded Household Families';
      } else {
        const pop = parseInt(pill.getAttribute('data-pop') || '0', 10);
        pill.innerHTML = ` ${pop.toLocaleString()}`;
        pill.title = 'Recorded Population';
      }
    });

    // Re-evaluate target count and scope summary with the selected counting unit
    onSinglePurokChange(mode);
  }

  function toggleExpectedActivitiesCheckboxes(prefix, check) {
    const container = document.getElementById(prefix + '_expected_activities_list');
    if (!container) return;
    container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.checked = check;
    });
  }

  function addCustomExpectedActivity(prefix) {
    const input = document.getElementById(prefix + '_custom_activity_input');
    if (!input) return;
    const val = input.value.trim();
    if (!val) {
      input.focus();
      return;
    }

    const container = document.getElementById(prefix + '_expected_activities_list');
    if (!container) return;

    const div = document.createElement('div');
    div.className = 'expected-activity-item';
    div.style.cssText = "display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text);background:#F0FDF4;padding:6px 9px;border-radius:5px;border:1px solid #86EFAC;transition:all 0.15s ease;";
    div.innerHTML = `
    <input type="checkbox" name="content_execution[]" value="${val.replace(/"/g, '&quot;')}" checked style="margin:0;cursor:pointer;">
    <span style="line-height:1.4;flex:1;font-weight:500;cursor:pointer;" onclick="this.previousElementSibling.checked = !this.previousElementSibling.checked;">${val}</span>
    <span style="font-size:9.5px;color:#16A34A;font-weight:700;background:#DCFCE7;padding:1px 5px;border-radius:3px;">Custom</span>
    <button type="button" onclick="this.closest('.expected-activity-item').remove()" title="Delete this custom activity" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 5px;font-size:14px;line-height:1;border-radius:3px;display:flex;align-items:center;justify-content:center;transition:color 0.15s;" onmouseover="this.style.color='#DC2626'" onmouseout="this.style.color='#94A3B8'">
      &times;
    </button>
  `;
    container.appendChild(div);
    input.value = '';
    container.scrollTop = container.scrollHeight;
  }

  function onVenueSelectChange() { }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal-overlay').forEach(modal => {
      modal.addEventListener('click', function (e) {
        if (e.target === this) closeModal(this.id);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        const hhModal = document.getElementById('householdMembersModal');
        if (hhModal && hhModal.classList.contains('active')) {
          closeModal('householdMembersModal');
          return;
        }
        document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
      }
    });

    // Initialize Create Event expected activities checklist
    const initialCreateType = document.getElementById('create_activity_type')?.value || Object.keys(EXPECTED_ACTIVITIES_BY_TYPE)[0];
    onActivityTypeChange('create', initialCreateType);

    // Validate Venue selection from evacuation areas before form submission
    ['createActivityForm', 'editActivityForm'].forEach(formId => {
      const form = document.getElementById(formId);
      if (!form) return;
      form.addEventListener('submit', function (e) {
        const isCreate = (formId === 'createActivityForm');
        const sel = document.getElementById(isCreate ? 'create_venue_select' : 'edit_venue_select');
        if (sel && !sel.value) {
          e.preventDefault();
          alert('Please select a venue from the evacuation areas.');
          sel.focus();
          return false;
        }
        const hiddenPurok = document.getElementById(isCreate ? 'create_target_puroks_hidden' : 'edit_target_puroks_hidden');
        if (hiddenPurok && !hiddenPurok.value.trim()) {
          e.preventDefault();
          alert('Please select at least one purok or select All Puroks.');
          return false;
        }
      });
    });
  });

  const STANDARD_EXECUTION_ITEMS = [
    "Initial Briefing & Safety Guidelines Orientation",
    "Emergency Evacuation / Primary Drill Scenario Execution",
    "Household Go-Bag & Emergency Survival Kit Inspection",
    "Tactical First Aid & Emergency Rescuer Demonstration",
    "Vulnerable Groups Priority Assistance (PWD, Senior Citizens, Children)",
    "Early Warning Siren & Megaphone Notification Walk",
    "Evacuation Route Walking & Designated Evacuation Center Assembly",
    "Resident Roll Call & Attendance Verification",
    "Facilitator Post-Drill Debriefing & Community Q&A Open Forum",
    "Information, Education & Communication (IEC) Distribution"
  ];

  const attendeesByActivityMap = <?= json_encode($attendeesByActivity) ?>;
  const barangayResidentsList = <?= json_encode($barangayResidents) ?>;
  let currentAccomplishedAct = null;
  let currentAccomplishedAttendees = null;
  let currentEvalAct = null;
  let currentEvalAttendees = [];
  let currentEditAct = null;
  let currentEditAttendees = [];
  let currentViewAttendeesAct = null;
  let currentViewAttendeesList = [];
  let currentActiveEventForRegistration = null;

  // ============================================================================
  // Add Participant Live Autocomplete & Submission Handlers
  // ============================================================================
  function toggleEditAddParticipantPanel(force) {
    const panel = document.getElementById('edit_add_participant_panel');
    if (!panel) return;
    const isHidden = (panel.style.display === 'none' || panel.style.display === '');
    const shouldShow = (force !== undefined) ? force : isHidden;
    panel.style.display = shouldShow ? 'block' : 'none';
    if (shouldShow) {
      const input = document.getElementById('edit_add_part_name');
      if (input) input.focus();
    }
  }

  function toggleEvalAddParticipantPanel(force) {
    const panel = document.getElementById('eval_add_participant_panel');
    if (!panel) return;
    const isHidden = (panel.style.display === 'none' || panel.style.display === '');
    const shouldShow = (force !== undefined) ? force : isHidden;
    panel.style.display = shouldShow ? 'block' : 'none';
    if (shouldShow) {
      const input = document.getElementById('eval_add_part_name');
      if (input) input.focus();
    }
  }

  function onLiveResidentSearch(query, prefix) {
    const suggestionsBox = document.getElementById(`${prefix}_add_part_suggestions`);
    const matchedBadge = document.getElementById(`${prefix}_matched_resident_badge`);
    const residentIdInput = document.getElementById(`${prefix}_add_part_resident_id`);

    if (residentIdInput) residentIdInput.value = '';
    if (matchedBadge) {
      matchedBadge.style.display = 'none';
      matchedBadge.innerHTML = '';
    }

    const q = query.trim().toLowerCase();
    if (!q) {
      if (suggestionsBox) {
        suggestionsBox.style.display = 'none';
        suggestionsBox.innerHTML = '';
      }
      return;
    }

    const matches = (barangayResidentsList || []).filter(r => {
      const fullName = (r.full_name || (r.first_name + ' ' + r.last_name) || '').toLowerCase();
      const phone = (r.phone || '').toLowerCase();
      const purok = (r.purok || '').toLowerCase();
      return fullName.includes(q) || phone.includes(q) || purok.includes(q);
    }).slice(0, 8);

    if (!suggestionsBox) return;

    if (matches.length > 0) {
      let html = '';
      matches.forEach(r => {
        const name = r.full_name || (r.first_name + ' ' + r.last_name);
        const phone = r.phone || '';
        const purok = r.purok || '';
        html += `
        <div style="padding:7px 10px;border-bottom:1px solid #F1F5F9;cursor:pointer;display:flex;justify-content:space-between;align-items:center;" onmouseover="this.style.background='#F8FAFC'" onmouseout="this.style.background='#fff'" onclick="selectResidentSuggestion('${prefix}', ${r.id}, '${name.replace(/'/g, "\\'")}', '${phone.replace(/'/g, "\\'")}', '${purok.replace(/'/g, "\\'")}')">
          <div>
            <div style="font-weight:600;font-size:11.5px;color:var(--color-primary);">${name}</div>
            <div style="font-size:10px;color:var(--color-text-muted);">${purok ? purok + ' • ' : ''}${phone}</div>
          </div>
          <span style="font-size:9.5px;font-weight:600;background:#E0F2FE;color:#0369A1;padding:2px 6px;border-radius:4px;">Resident ✓</span>
        </div>
      `;
      });
      html += `
      <div style="padding:6px 10px;background:#F8FAFC;font-size:10px;color:#64748B;font-style:italic;border-top:1px dashed #CBD5E1;">
        Not in resident records? You can still add as a non-registered / walk-in participant.
      </div>
    `;
      suggestionsBox.innerHTML = html;
      suggestionsBox.style.display = 'block';
    } else {
      suggestionsBox.innerHTML = `
      <div style="padding:8px 10px;font-size:11px;color:#64748B;">
        No resident matching "<strong>${query.replace(/</g, '&lt;')}</strong>".<br>
        <span style="font-size:10px;color:#059669;font-weight:600;">✓ Will be added as a walk-in / new participant.</span>
      </div>
    `;
      suggestionsBox.style.display = 'block';
    }
  }

  function selectResidentSuggestion(prefix, id, name, phone, purok) {
    const nameInput = document.getElementById(`${prefix}_add_part_name`);
    const resIdInput = document.getElementById(`${prefix}_add_part_resident_id`);
    const phoneInput = document.getElementById(`${prefix}_add_part_phone`);
    const suggestionsBox = document.getElementById(`${prefix}_add_part_suggestions`);
    const matchedBadge = document.getElementById(`${prefix}_matched_resident_badge`);

    if (nameInput) nameInput.value = name;
    if (resIdInput) resIdInput.value = id;
    if (phoneInput && phone) phoneInput.value = phone;
    if (suggestionsBox) suggestionsBox.style.display = 'none';

    if (matchedBadge) {
      matchedBadge.innerHTML = `✓ Matched Resident: ${name} (${purok || 'Barangay Resident'})`;
      matchedBadge.style.display = 'block';
    }
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#acc_add_part_suggestions') && !e.target.closest('#acc_add_part_name')) {
      const s = document.getElementById('acc_add_part_suggestions');
      if (s) s.style.display = 'none';
    }
    if (!e.target.closest('#eval_add_part_suggestions') && !e.target.closest('#eval_add_part_name')) {
      const s = document.getElementById('eval_add_part_suggestions');
      if (s) s.style.display = 'none';
    }
    if (!e.target.closest('#edit_add_part_suggestions') && !e.target.closest('#edit_add_part_name')) {
      const s = document.getElementById('edit_add_part_suggestions');
      if (s) s.style.display = 'none';
    }
  });

  let isAddingAttendee = false;

  function submitAddParticipant(prefix) {
    if (isAddingAttendee) return;

    const nameInput = document.getElementById(`${prefix}_add_part_name`);
    const phoneInput = document.getElementById(`${prefix}_add_part_phone`);
    const resIdInput = document.getElementById(`${prefix}_add_part_resident_id`);
    const attendedCheck = document.getElementById(`${prefix}_add_part_attended`);

    const name = nameInput ? nameInput.value.trim() : '';
    const phone = phoneInput ? phoneInput.value.trim() : '';
    const residentId = resIdInput ? resIdInput.value.trim() : '';
    const isAttended = (attendedCheck && !attendedCheck.checked) ? 0 : 1;

    if (!name) {
      alert('Please enter participant name.');
      if (nameInput) nameInput.focus();
      return;
    }

    let actId = 0;
    if (prefix === 'acc' && currentAccomplishedAct) {
      actId = currentAccomplishedAct.id;
    } else if (prefix === 'eval') {
      actId = document.getElementById('evalActId').value;
    } else if (prefix === 'edit') {
      actId = document.getElementById('edit_act_id').value;
    }
    if (!actId) {
      alert('Activity ID not found.');
      return;
    }

    isAddingAttendee = true;

    const saveBtn = document.getElementById(`${prefix}_save_part_btn`);
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.innerText = 'Adding...';
    }

    const formData = new FormData();
    formData.append('action', 'add_attendee');
    formData.append('is_ajax', '1');
    formData.append('activity_id', actId);
    formData.append('resident_name', name);
    formData.append('phone', phone || 'Walk-in / No phone');
    if (residentId) formData.append('user_id', residentId);
    formData.append('attended', isAttended);

    fetch('<?= BASE_URL ?>/backend/functions/event/index.php', {
      method: 'POST',
      body: formData
    })
      .then(res => res.json())
      .then(data => {
        isAddingAttendee = false;
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerText = prefix === 'eval' ? 'Add to List' : (prefix === 'edit' ? 'Add Participant' : 'Save Participant');
        }

        if (!data.success) {
          alert(data.message || 'Failed to add participant.');
          return;
        }

        const newAttendee = data.attendee;

        if (!attendeesByActivityMap[actId]) {
          attendeesByActivityMap[actId] = [];
        }
        if (!attendeesByActivityMap[actId].some(a => a.id == newAttendee.id)) {
          attendeesByActivityMap[actId].unshift(newAttendee);
        }

        if (currentAccomplishedAct && currentAccomplishedAct.id == actId) {
          if (!currentAccomplishedAttendees) currentAccomplishedAttendees = [];
          if (!currentAccomplishedAttendees.some(a => a.id == newAttendee.id)) {
            currentAccomplishedAttendees.unshift(newAttendee);
          }
          currentAccomplishedAct.actual_participants = data.actual_participants;

          const targetPart = parseInt(currentAccomplishedAct.target_participants, 10) || 50;
          const attPill = document.getElementById('acc_display_attendance');
          if (attPill) attPill.innerText = `${data.actual_participants} attended (${targetPart} targeted)`;

          currentAccomplishedRoster = buildEventParticipantRoster(currentAccomplishedAct, currentAccomplishedAttendees);
          renderAccomplishedAttendeesTable();
        }

        if (currentEditAct && currentEditAct.id == actId) {
          if (!currentEditAttendees) currentEditAttendees = [];
          if (!currentEditAttendees.some(a => a.id == newAttendee.id)) {
            currentEditAttendees.unshift(newAttendee);
          }
          renderEditAttendeesTable();
        }

        if (currentEvalAttendees && currentEvalAct && currentEvalAct.id == actId) {
          if (!currentEvalAttendees.some(a => a.id == newAttendee.id)) {
            currentEvalAttendees.unshift(newAttendee);
          }
          renderEvaluateAttendeesTable(data.actual_participants);
        }

        nameInput.value = '';
        if (phoneInput) phoneInput.value = '';
        if (resIdInput) resIdInput.value = '';
        const badge = document.getElementById(`${prefix}_matched_resident_badge`);
        if (badge) badge.style.display = 'none';
        const suggestions = document.getElementById(`${prefix}_add_part_suggestions`);
        if (suggestions) suggestions.style.display = 'none';

        // Show inline feedback toast
        const feedbackMsg = document.createElement('div');
        feedbackMsg.style.cssText = "font-size:10.5px;color:#059669;font-weight:600;padding:4px 0;";
        feedbackMsg.innerText = `✓ Added ${newAttendee.resident_name} successfully!`;
        const panel = document.getElementById(`${prefix}_add_participant_panel`);
        if (panel) {
          panel.appendChild(feedbackMsg);
          setTimeout(() => feedbackMsg.remove(), 2500);
        }
      })
      .catch(err => {
        isAddingAttendee = false;
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerText = prefix === 'eval' ? 'Add to List' : (prefix === 'edit' ? 'Add Participant' : 'Save Participant');
        }
        console.error(err);
        alert('An error occurred while adding participant.');
      });
  }

  // Delete / Remove Attendee Handler
  function deleteEventAttendee(attendeeId, attendeeName, source) {
    if (!attendeeId) return;
    const displayName = attendeeName || 'this participant';
    if (!confirm(`Are you sure you want to remove "${displayName}" from this event?`)) {
      return;
    }

    let actId = 0;
    if (source === 'acc' && currentAccomplishedAct) {
      actId = currentAccomplishedAct.id;
    } else if (source === 'eval' && currentEvalAct) {
      actId = currentEvalAct.id;
    } else if (source === 'edit' && currentEditAct) {
      actId = currentEditAct.id;
    } else if (source === 'view' && currentViewAttendeesAct) {
      actId = currentViewAttendeesAct.id;
    }

    const formData = new FormData();
    formData.append('action', 'remove_attendee');
    formData.append('attendee_id', attendeeId);
    if (actId) formData.append('activity_id', actId);
    formData.append('is_ajax', '1');

    fetch('<?= BASE_URL ?>/backend/functions/event/index.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(res => res.json())
      .then(data => {
        if (!data.success) {
          alert(data.message || 'Failed to remove participant.');
          return;
        }

        const removedId = data.attendee_id || attendeeId;
        const resolvedActId = data.activity_id || actId;

        // Update global map
        if (resolvedActId && attendeesByActivityMap && attendeesByActivityMap[resolvedActId]) {
          attendeesByActivityMap[resolvedActId] = attendeesByActivityMap[resolvedActId].filter(a => a.id != removedId);
        }

        // Update accomplished attendees state & table
        if (currentAccomplishedAttendees) {
          currentAccomplishedAttendees = currentAccomplishedAttendees.filter(a => a.id != removedId);
          if (currentAccomplishedAct && currentAccomplishedAct.id == resolvedActId) {
            currentAccomplishedAct.actual_participants = data.actual_participants;
            const targetPart = parseInt(currentAccomplishedAct.target_participants, 10) || 50;
            const attPill = document.getElementById('acc_display_attendance');
            if (attPill) attPill.innerText = `${data.actual_participants} attended (${targetPart} targeted)`;
          }
          if (currentAccomplishedAct) {
            currentAccomplishedRoster = buildEventParticipantRoster(currentAccomplishedAct, currentAccomplishedAttendees);
          }
          renderAccomplishedAttendeesTable();
        }

        // Update edit attendees state & table
        if (currentEditAttendees) {
          currentEditAttendees = currentEditAttendees.filter(a => a.id != removedId);
          renderEditAttendeesTable();
        }

        // Update eval attendees state & table
        if (currentEvalAttendees) {
          currentEvalAttendees = currentEvalAttendees.filter(a => a.id != removedId);
          if (currentEvalAct && currentEvalAct.id == resolvedActId) {
            currentEvalAct.actual_participants = data.actual_participants;
          }
          renderEvaluateAttendeesTable(data.actual_participants);
        }

        // Update view attendees state & table
        if (currentViewAttendeesList) {
          currentViewAttendeesList = currentViewAttendeesList.filter(a => a.id != removedId);
          if (currentViewAttendeesAct) {
            openViewAttendeesModal(currentViewAttendeesAct, currentViewAttendeesList);
          }
        }
      })
      .catch(err => {
        console.error(err);
        alert('An error occurred while removing participant.');
      });
  }

  let currentAccomplishedRoster = [];
  let currentAccomplishedRosterMode = 'household_families';

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function parseTargetPuroks(targetPuroksStr) {
    if (!targetPuroksStr || typeof targetPuroksStr !== 'string') return [];
    const trimmed = targetPuroksStr.trim();
    if (!trimmed || trimmed.toLowerCase() === 'all' || trimmed.toLowerCase().includes('all purok')) {
      return [];
    }
    if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
      try {
        const parsed = JSON.parse(trimmed);
        if (Array.isArray(parsed)) {
          return parsed.map(s => String(s).trim().toLowerCase()).filter(Boolean);
        }
      } catch (e) {}
    }
    return trimmed.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
  }

  function setAccomplishedRosterMode(mode, reRender = true) {
    currentAccomplishedRosterMode = mode || 'household_families';
    const tabFamilies = document.getElementById('acc_roster_tab_families');
    const tabMembers = document.getElementById('acc_roster_tab_members');

    if (tabFamilies && tabMembers) {
      if (currentAccomplishedRosterMode === 'household_families') {
        tabFamilies.style.background = 'var(--color-primary)';
        tabFamilies.style.color = '#FFFFFF';
        tabFamilies.style.fontWeight = '600';
        tabMembers.style.background = 'transparent';
        tabMembers.style.color = 'var(--color-text-secondary)';
        tabMembers.style.fontWeight = '500';
      } else {
        tabMembers.style.background = 'var(--color-primary)';
        tabMembers.style.color = '#FFFFFF';
        tabMembers.style.fontWeight = '600';
        tabFamilies.style.background = 'transparent';
        tabFamilies.style.color = 'var(--color-text-secondary)';
        tabFamilies.style.fontWeight = '500';
      }
    }

    if (currentAccomplishedAct) {
      currentAccomplishedRoster = buildEventParticipantRoster(currentAccomplishedAct, currentAccomplishedAttendees, currentAccomplishedRosterMode);
    }

    if (reRender) {
      renderAccomplishedAttendeesTable();
    }
  }

  function buildEventParticipantRoster(act, rawAttendees, mode) {
    mode = mode || currentAccomplishedRosterMode || 'household_families';
    const targetPuroks = parseTargetPuroks(act ? act.target_puroks : '');
    const isAllPuroks = targetPuroks.length === 0;
    const rawAttList = Array.isArray(rawAttendees) ? rawAttendees : [];
    const matchedAttIds = new Set();
    const roster = [];

    if (mode === 'all_members') {
      const allMems = (typeof ALL_BARANGAY_MEMBERS !== 'undefined' && Array.isArray(ALL_BARANGAY_MEMBERS))
        ? ALL_BARANGAY_MEMBERS
        : [];

      let scopedMembers = allMems;
      if (!isAllPuroks) {
        scopedMembers = allMems.filter(m => {
          const pName = (m.purok_name || '').trim().toLowerCase();
          return targetPuroks.some(tp => pName === tp || pName.includes(tp) || tp.includes(pName));
        });
      }

      scopedMembers.forEach((m, idx) => {
        const memName = (m.full_name || '').trim().toLowerCase();
        let matchedAtt = null;
        if (rawAttList.length > 0) {
          matchedAtt = rawAttList.find(a => {
            if (matchedAttIds.has(a.id)) return false;
            const resName = (a.resident_name || '').toLowerCase();
            return memName && resName.includes(memName);
          });
        }

        if (matchedAtt) {
          matchedAttIds.add(matchedAtt.id);
        }

        const isAttended = matchedAtt ? (matchedAtt.attended == 1 || matchedAtt.attended === '1' || matchedAtt.attended === true) : false;

        roster.push({
          roster_id: idx,
          attendee_id: matchedAtt ? matchedAtt.id : null,
          member_id: m.id,
          household_id: m.household_id,
          household_code: m.household_code || '—',
          family_name: m.family_name || 'Family',
          full_name: m.full_name || 'Resident',
          relationship: m.relationship || 'Member',
          gender: m.gender || '',
          age: (m.age !== null && m.age !== undefined && m.age !== '') ? m.age : null,
          purok_name: m.purok_name || '—',
          phone: m.phone || '—',
          attended: isAttended
        });
      });

      // Append unmatched raw attendees
      rawAttList.forEach(a => {
        if (!matchedAttIds.has(a.id)) {
          const isAttended = (a.attended == 1 || a.attended === '1' || a.attended === true);
          roster.push({
            roster_id: roster.length,
            attendee_id: a.id,
            member_id: null,
            household_id: null,
            household_code: '—',
            family_name: 'Resident',
            full_name: a.resident_name || 'Participant',
            relationship: 'Attendee',
            gender: '',
            age: null,
            purok_name: act ? (act.target_puroks || '—') : '—',
            phone: a.phone || '—',
            attended: isAttended
          });
        }
      });
    } else {
      // household_families mode
      const allHh = (typeof ALL_BARANGAY_HOUSEHOLDS !== 'undefined' && Array.isArray(ALL_BARANGAY_HOUSEHOLDS)) 
        ? ALL_BARANGAY_HOUSEHOLDS 
        : [];
      
      let scopedHouseholds = allHh;
      if (!isAllPuroks) {
        scopedHouseholds = allHh.filter(hh => {
          const pName = (hh.purok_name || '').trim().toLowerCase();
          return targetPuroks.some(tp => pName === tp || pName.includes(tp) || tp.includes(pName));
        });
      }

      scopedHouseholds.forEach((hh, idx) => {
        const hhCode = (hh.household_code || '').trim().toLowerCase();
        const famName = (hh.family_name || '').trim().toLowerCase();
        const headName = (hh.head_full_name || '').trim().toLowerCase();
        const phone = (hh.head_phone || '').trim();

        let matchedAtt = null;
        if (rawAttList.length > 0) {
          matchedAtt = rawAttList.find(a => {
            if (matchedAttIds.has(a.id)) return false;
            const resName = (a.resident_name || '').toLowerCase();
            if (hhCode && resName.includes(hhCode)) return true;
            if (headName && resName.includes(headName)) return true;
            if (famName && resName.includes(famName)) return true;
            if (phone && a.phone && a.phone.trim() === phone) return true;
            return false;
          });
        }

        if (matchedAtt) {
          matchedAttIds.add(matchedAtt.id);
        }

        const isAttended = matchedAtt ? (matchedAtt.attended == 1 || matchedAtt.attended === '1' || matchedAtt.attended === true) : false;

        roster.push({
          roster_id: idx,
          attendee_id: matchedAtt ? matchedAtt.id : null,
          household_id: hh.id,
          household_code: hh.household_code || '—',
          family_name: hh.family_name || 'Family',
          head_name: hh.head_full_name || 'Family Representative',
          purok_name: hh.purok_name || '—',
          phone: (matchedAtt && matchedAtt.phone) ? matchedAtt.phone : (hh.head_phone || '—'),
          attended: isAttended
        });
      });

      // Append unmatched raw attendees
      rawAttList.forEach(a => {
        if (!matchedAttIds.has(a.id)) {
          const isAttended = (a.attended == 1 || a.attended === '1' || a.attended === true);
          roster.push({
            roster_id: roster.length,
            attendee_id: a.id,
            household_id: null,
            household_code: '—',
            family_name: a.resident_name || 'Participant',
            head_name: a.resident_name || 'Participant',
            purok_name: act ? (act.target_puroks || '—') : '—',
            phone: a.phone || '—',
            attended: isAttended
          });
        }
      });
    }

    return roster;
  }

  function renderAccomplishedAttendeesTable(filterQuery = '') {
    const attBox = document.getElementById('acc_attendees_preview_box');
    if (!attBox) return;

    if (!currentAccomplishedRoster || currentAccomplishedRoster.length === 0) {
      if (currentAccomplishedAct) {
        currentAccomplishedRoster = buildEventParticipantRoster(currentAccomplishedAct, currentAccomplishedAttendees, currentAccomplishedRosterMode);
      }
    }

    const roster = currentAccomplishedRoster || [];
    const attendedCount = roster.filter(r => r.attended).length;
    const pillEl = document.getElementById('acc_attendees_pill');
    const unitLabel = (currentAccomplishedRosterMode === 'all_members') ? 'Members' : 'Households';
    if (pillEl) {
      pillEl.innerHTML = `<strong>${attendedCount}</strong> Attended • <strong>${roster.length}</strong> ${unitLabel}`;
    }

    const q = (typeof filterQuery === 'string' ? filterQuery : (document.getElementById('acc_attendees_search')?.value || '')).trim().toLowerCase();
    let displayList = roster;
    if (q) {
      if (currentAccomplishedRosterMode === 'all_members') {
        displayList = roster.filter(item => {
          return (item.full_name && item.full_name.toLowerCase().includes(q))
            || (item.family_name && item.family_name.toLowerCase().includes(q))
            || (item.household_code && item.household_code.toLowerCase().includes(q))
            || (item.purok_name && item.purok_name.toLowerCase().includes(q))
            || (item.relationship && item.relationship.toLowerCase().includes(q));
        });
      } else {
        displayList = roster.filter(item => {
          return (item.family_name && item.family_name.toLowerCase().includes(q))
            || (item.head_name && item.head_name.toLowerCase().includes(q))
            || (item.household_code && item.household_code.toLowerCase().includes(q))
            || (item.purok_name && item.purok_name.toLowerCase().includes(q))
            || (item.phone && item.phone.toLowerCase().includes(q));
        });
      }
    }

    if (!displayList || displayList.length === 0) {
      attBox.innerHTML = `
        <div style="text-align:center;padding:22px 14px;font-size:11px;color:var(--color-text-muted);">
          ${currentAccomplishedRosterMode === 'all_members' ? 'No matching members found.' : 'No matching household families found.'}
        </div>
      `;
      return;
    }

    const isAllMembers = (currentAccomplishedRosterMode === 'all_members');

    let attHtml = `
      <table class="data-table" style="font-size:11px;width:100%;margin:0;border-collapse:collapse;">
        <thead>
          <tr style="position:sticky;top:0;background:var(--color-surface-subtle);z-index:2;border-bottom:1px solid var(--color-border);">
            <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;">
              ${isAllMembers ? 'Member Name / Family' : 'Household Family / Representative'}
            </th>
            <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:95px;">Purok</th>
            <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:115px;">
              ${isAllMembers ? 'Age / Rel' : 'Contact'}
            </th>
            <th style="padding:6px 10px;text-align:center;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:85px;">Status</th>
            <th style="padding:6px 10px;text-align:center;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:${isAllMembers ? '95px' : '145px'};">Action</th>
          </tr>
        </thead>
        <tbody>
    `;

    displayList.forEach(item => {
      const isAttended = item.attended;
      const rowId = item.roster_id;

      if (isAllMembers) {
        // All Members Mode - Clean single line, strictly NO sub-details below!
        const relText = item.relationship ? escapeHtml(item.relationship) : 'Member';
        const ageText = (item.age !== null && item.age !== undefined && item.age !== '') ? `${item.age} yrs` : '—';

        attHtml += `
          <tr id="acc_roster_row_${rowId}" style="border-bottom:1px solid var(--color-border);transition:background 0.1s ease;">
            <td style="padding:6px 10px;">
              <div style="font-weight:600;color:var(--color-text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(item.full_name)} (${escapeHtml(item.family_name)})">
                ${escapeHtml(item.full_name)} <span style="font-weight:normal;color:var(--color-text-secondary);">(${escapeHtml(item.family_name)})</span>
              </div>
            </td>
            <td style="padding:6px 10px;font-size:10.5px;color:var(--color-text-secondary);">
              ${escapeHtml(item.purok_name)}
            </td>
            <td style="padding:6px 10px;font-size:10.5px;color:var(--color-text-secondary);">
              ${ageText} • ${relText}
            </td>
            <td style="padding:6px 10px;text-align:center;" id="acc_status_badge_${rowId}">
              <span style="display:inline-block;padding:2px 6px;font-size:9.5px;font-weight:600;border-radius:3px;border:1px solid var(--color-border);${isAttended ? 'background:var(--color-surface-subtle);color:var(--color-primary);' : 'background:#FFFFFF;color:var(--color-text-muted);'}">
                ${isAttended ? 'Attended' : 'Unmarked'}
              </span>
            </td>
            <td style="padding:6px 10px;text-align:center;">
              <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                <button type="button" 
                  id="acc_btn_attend_${rowId}" 
                  onclick="toggleRosterItemAttendance(${rowId}, ${isAttended ? 0 : 1})"
                  class="btn btn-sm"
                  style="${isAttended 
                    ? 'background:#FFFFFF;color:#C62828;border:1px solid var(--color-border);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;' 
                    : 'background:var(--color-primary);color:#FFFFFF;border:1px solid var(--color-primary);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;'}"
                  title="${isAttended ? 'Click to void attendance' : 'Click to mark as attended'}">
                  ${isAttended ? 'Void' : 'Attended'}
                </button>
              </div>
            </td>
          </tr>
        `;
      } else {
        // Household Families Mode - Clean single line, strictly NO sub-details below!
        attHtml += `
          <tr id="acc_roster_row_${rowId}" style="border-bottom:1px solid var(--color-border);transition:background 0.1s ease;">
            <td style="padding:6px 10px;">
              <div style="font-weight:600;color:var(--color-text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(item.family_name)}${item.head_name && item.head_name !== '—' ? ' (' + escapeHtml(item.head_name) + ')' : ''}">
                ${escapeHtml(item.family_name)}${item.head_name && item.head_name !== '—' ? ' <span style="font-weight:normal;color:var(--color-text-secondary);">(' + escapeHtml(item.head_name) + ')</span>' : ''}
              </div>
            </td>
            <td style="padding:6px 10px;font-size:10.5px;color:var(--color-text-secondary);">
              ${escapeHtml(item.purok_name)}
            </td>
            <td style="padding:6px 10px;font-family:monospace;font-size:10.5px;color:var(--color-text-secondary);">
              ${escapeHtml(item.phone)}
            </td>
            <td style="padding:6px 10px;text-align:center;" id="acc_status_badge_${rowId}">
              <span style="display:inline-block;padding:2px 6px;font-size:9.5px;font-weight:600;border-radius:3px;border:1px solid var(--color-border);${isAttended ? 'background:var(--color-surface-subtle);color:var(--color-primary);' : 'background:#FFFFFF;color:var(--color-text-muted);'}">
                ${isAttended ? 'Attended' : 'Unmarked'}
              </span>
            </td>
            <td style="padding:6px 10px;text-align:center;">
              <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                <button type="button" 
                  id="acc_btn_attend_${rowId}" 
                  onclick="toggleRosterItemAttendance(${rowId}, ${isAttended ? 0 : 1})"
                  class="btn btn-sm"
                  style="${isAttended 
                    ? 'background:#FFFFFF;color:#C62828;border:1px solid var(--color-border);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;' 
                    : 'background:var(--color-primary);color:#FFFFFF;border:1px solid var(--color-primary);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;'}"
                  title="${isAttended ? 'Click to void attendance' : 'Click to mark as attended'}">
                  ${isAttended ? 'Void' : 'Attended'}
                </button>
                ${item.household_id ? `
                  <button type="button" 
                    onclick="openHouseholdMembersModal(${item.household_id})" 
                    class="btn btn-sm"
                    style="background:var(--color-surface-subtle);color:var(--color-text);border:1px solid var(--color-border);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;"
                    title="View family members of this household">
                    Members
                  </button>
                ` : ''}
              </div>
            </td>
          </tr>
        `;
      }
    });

    attHtml += '</tbody></table>';
    attBox.innerHTML = attHtml;
  }

  function filterAccomplishedAttendees(query) {
    renderAccomplishedAttendeesTable(query);
  }

  function toggleRosterItemAttendance(rosterIdx, targetAttended) {
    if (!currentAccomplishedAct || !currentAccomplishedRoster) return;
    const item = currentAccomplishedRoster[rosterIdx];
    if (!item) return;

    const actId = currentAccomplishedAct.id;
    const btn = document.getElementById(`acc_btn_attend_${rosterIdx}`);
    const badgeCell = document.getElementById(`acc_status_badge_${rosterIdx}`);

    // Immediate optimistic state update
    item.attended = (targetAttended === 1);

    if (btn) {
      btn.disabled = true;
      if (targetAttended === 1) {
        btn.innerText = 'Void';
        btn.style.cssText = 'background:#FFFFFF;color:#C62828;border:1px solid var(--color-border);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;';
        btn.setAttribute('onclick', `toggleRosterItemAttendance(${rosterIdx}, 0)`);
        btn.title = 'Click to void attendance';
      } else {
        btn.innerText = 'Attended';
        btn.style.cssText = 'background:var(--color-primary);color:#FFFFFF;border:1px solid var(--color-primary);font-weight:600;font-size:10.5px;padding:2px 8px;border-radius:4px;cursor:pointer;';
        btn.setAttribute('onclick', `toggleRosterItemAttendance(${rosterIdx}, 1)`);
        btn.title = 'Click to mark as attended';
      }
    }

    if (badgeCell) {
      badgeCell.innerHTML = (targetAttended === 1)
        ? '<span style="display:inline-block;padding:2px 6px;font-size:9.5px;font-weight:600;border-radius:3px;border:1px solid var(--color-border);background:var(--color-surface-subtle);color:var(--color-primary);">Attended</span>'
        : '<span style="display:inline-block;padding:2px 6px;font-size:9.5px;font-weight:500;border-radius:3px;border:1px solid var(--color-border);background:#FFFFFF;color:var(--color-text-muted);">Unmarked</span>';
    }

    // Live update turnout in pill & modal header
    const newAttendedCount = currentAccomplishedRoster.filter(r => r.attended).length;
    const pillEl = document.getElementById('acc_attendees_pill');
    const unitLabel = (currentAccomplishedRosterMode === 'all_members') ? 'Members' : 'Households';
    if (pillEl) {
      pillEl.innerHTML = `<strong>${newAttendedCount}</strong> Attended • <strong>${currentAccomplishedRoster.length}</strong> ${unitLabel}`;
    }
    const turnoutEl = document.getElementById('acc_display_attendance');
    if (turnoutEl) {
      const targetPart = parseInt(currentAccomplishedAct.target_participants, 10) || currentAccomplishedRoster.length;
      const countUnit = (currentAccomplishedRosterMode === 'all_members') ? 'residents' : 'households';
      turnoutEl.innerText = `${newAttendedCount} attended (${targetPart} targeted ${countUnit})`;
    }
    currentAccomplishedAct.actual_participants = newAttendedCount;

    // Send AJAX request to backend
    const formData = new FormData();
    formData.append('action', 'toggle_attendance');
    formData.append('activity_id', actId);
    if (item.attendee_id) {
      formData.append('attendee_id', item.attendee_id);
    }
    const residentName = (currentAccomplishedRosterMode === 'all_members')
      ? `${item.full_name} (${item.family_name}) [${item.household_code || '—'}]`
      : `${item.family_name} (Representative: ${item.head_name}) [${item.household_code}]`;
    formData.append('resident_name', residentName);
    formData.append('phone', item.phone !== '—' ? item.phone : '');
    formData.append('attended', targetAttended);
    formData.append('is_ajax', '1');

    fetch('<?= BASE_URL ?>/backend/functions/event/index.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
      if (btn) btn.disabled = false;
      if (!data.success) {
        alert(data.message || 'Failed to update attendance status.');
        item.attended = (targetAttended !== 1);
        renderAccomplishedAttendeesTable();
      } else {
        if (data.attendee_id) {
          item.attendee_id = data.attendee_id;
        }
        if (currentAccomplishedAttendees) {
          let found = currentAccomplishedAttendees.find(a => a.id == item.attendee_id);
          if (found) {
            found.attended = data.attended;
          } else if (data.attendee_id) {
            currentAccomplishedAttendees.push({
              id: data.attendee_id,
              activity_id: actId,
              resident_name: residentName,
              phone: item.phone !== '—' ? item.phone : '',
              attended: data.attended
            });
          }
        }
        if (typeof attendeesByActivityMap !== 'undefined' && attendeesByActivityMap[actId]) {
          let foundMap = attendeesByActivityMap[actId].find(a => a.id == item.attendee_id);
          if (foundMap) {
            foundMap.attended = data.attended;
          } else if (data.attendee_id) {
            attendeesByActivityMap[actId].push({
              id: data.attendee_id,
              activity_id: actId,
              resident_name: residentName,
              phone: item.phone !== '—' ? item.phone : '',
              attended: data.attended
            });
          }
        }
      }
    })
    .catch(err => {
      console.error(err);
      if (btn) btn.disabled = false;
      alert('Network error while updating attendance.');
      item.attended = (targetAttended !== 1);
      renderAccomplishedAttendeesTable();
    });
  }

  // Open Family Members Modal for a Household
  function openHouseholdMembersModal(householdId) {
    if (!householdId) return;

    const modalEl = document.getElementById('householdMembersModal');
    if (modalEl) {
      modalEl.style.zIndex = '100005';
    }

    document.getElementById('hh_members_modal_title').innerText = 'Family Members';
    document.getElementById('hh_members_badge').innerText = 'Loading...';
    document.getElementById('hh_members_family_name').innerText = 'Loading family details...';
    document.getElementById('hh_members_rep_info').innerText = 'Please wait...';
    document.getElementById('hh_members_purok_code').innerText = '—';
    document.getElementById('hh_members_count_pill').innerText = '0 Members';
    document.getElementById('hh_members_table_box').innerHTML = `
      <div style="text-align:center;padding:24px 16px;font-size:11px;color:var(--color-text-muted);">
        Loading family members...
      </div>
    `;

    openModal('householdMembersModal');

    fetch(`<?= BASE_URL ?>/backend/functions/purok_households/get.php?id=${encodeURIComponent(householdId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
      if (!data.success || !data.household) {
        document.getElementById('hh_members_table_box').innerHTML = `
          <div style="text-align:center;padding:24px 16px;font-size:11px;color:var(--color-danger);">
            ${escapeHtml(data.message || 'Unable to load household details.')}
          </div>
        `;
        return;
      }

      const hh = data.household;
      const members = hh.members || [];

      document.getElementById('hh_members_badge').innerText = hh.household_code || 'HH';
      document.getElementById('hh_members_family_name').innerText = hh.family_name || 'Family';
      document.getElementById('hh_members_rep_info').innerHTML = `Representative: <strong>${escapeHtml(hh.head_full_name || '—')}</strong> ${hh.head_phone ? '• ' + escapeHtml(hh.head_phone) : ''}`;
      document.getElementById('hh_members_purok_code').innerText = `${hh.purok_name || '—'} (${hh.household_code || '—'})`;
      document.getElementById('hh_members_count_pill').innerText = `${members.length} Members`;

      if (members.length === 0) {
        document.getElementById('hh_members_table_box').innerHTML = `
          <div style="text-align:center;padding:24px 16px;font-size:11px;color:var(--color-text-muted);">
            No individual family members recorded for this household.
          </div>
        `;
        return;
      }

      let mHtml = `
        <table class="data-table" style="font-size:11px;width:100%;margin:0;border-collapse:collapse;">
          <thead>
            <tr style="position:sticky;top:0;background:var(--color-surface-subtle);z-index:2;border-bottom:1px solid var(--color-border);">
              <th style="padding:6px 10px;text-align:center;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:35px;">#</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;">Member Name</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:95px;">Relationship</th>
              <th style="padding:6px 10px;text-align:center;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:65px;">Gender</th>
              <th style="padding:6px 10px;text-align:center;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:55px;">Age</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;color:var(--color-text-secondary);font-size:10px;text-transform:uppercase;letter-spacing:0.3px;width:110px;">Classification</th>
            </tr>
          </thead>
          <tbody>
      `;

      members.forEach((m, idx) => {
        const isRep = (m.is_authorized_rep == 1 || m.is_authorized_rep === '1' || m.is_authorized_rep === true);
        const tags = [];
        if (m.is_senior == 1 || m.is_senior === '1') tags.push('Senior');
        if (m.is_minor == 1 || m.is_minor === '1') tags.push('Minor');
        if (m.is_pwd == 1 || m.is_pwd === '1') tags.push('PWD');

        const tagsHtml = tags.length > 0 
          ? tags.map(t => `<span style="display:inline-block;padding:1px 5px;font-size:9.5px;font-weight:600;color:var(--color-primary);background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:3px;margin-right:3px;">${t}</span>`).join('')
          : '<span style="font-size:10px;color:var(--color-text-muted);">Regular</span>';

        mHtml += `
          <tr style="border-bottom:1px solid var(--color-border);">
            <td style="padding:6px 10px;text-align:center;font-size:10.5px;color:var(--color-text-secondary);">${idx + 1}</td>
            <td style="padding:6px 10px;">
              <div style="font-weight:600;color:var(--color-text);display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <span>${escapeHtml(m.full_name || '—')}</span>
                ${isRep ? '<span style="font-size:9px;color:var(--color-primary);background:var(--color-surface-subtle);border:1px solid var(--color-border);padding:1px 5px;border-radius:3px;font-weight:600;">Representative</span>' : ''}
              </div>
            </td>
            <td style="padding:6px 10px;font-size:10.5px;color:var(--color-text-secondary);">${escapeHtml(m.relationship || 'Member')}</td>
            <td style="padding:6px 10px;text-align:center;font-size:10.5px;color:var(--color-text-secondary);">${escapeHtml(m.gender || '—')}</td>
            <td style="padding:6px 10px;text-align:center;font-size:10.5px;color:var(--color-text);">${m.age !== null && m.age !== undefined ? m.age : '—'}</td>
            <td style="padding:6px 10px;">${tagsHtml}</td>
          </tr>
        `;
      });

      mHtml += '</tbody></table>';
      document.getElementById('hh_members_table_box').innerHTML = mHtml;
    })
    .catch(err => {
      console.error(err);
      document.getElementById('hh_members_table_box').innerHTML = `
        <div style="text-align:center;padding:24px 16px;font-size:11px;color:var(--color-danger);">
          Failed to load family members due to network error.
        </div>
      `;
    });
  }

  function renderEditAttendeesTable() {
    const attContainer = document.getElementById('editAttendeesListContainer');
    const badge = document.getElementById('editAttendeeCountBadge');
    const list = currentEditAttendees || [];
    if (badge) {
      badge.innerText = `${list.length} Registered`;
    }
    if (!attContainer) return;

    if (list.length === 0) {
      attContainer.innerHTML = `
      <div style="text-align:center;padding:12px;font-size:11px;color:var(--color-text-muted);">
        No participants registered yet for this event.<br>
        Click "+ Add Participant" above to add attendees.
      </div>
    `;
    } else {
      let attHtml = `
      <table class="data-table" style="font-size:11px;width:100%;margin:0;">
        <thead>
          <tr>
            <th style="padding:6px 8px;">Participant Name</th>
            <th style="padding:6px 8px;">Mobile Phone</th>
            <th style="padding:6px 8px;text-align:center;">Attendance Status</th>
            <th style="padding:6px 8px;text-align:center;width:34px;" title="Remove Participant"></th>
          </tr>
        </thead>
        <tbody>
    `;
      list.forEach(a => {
        const isAtt = (a.attended == 1 || a.attended === '1' || a.attended === true);
        const safeName = (a.resident_name || 'Participant').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        attHtml += `
        <tr>
          <td style="font-weight:600;padding:6px 8px;">${a.resident_name}</td>
          <td style="font-family:monospace;padding:6px 8px;">${a.phone}</td>
          <td style="padding:6px 8px;text-align:center;">
            <span class="badge ${isAtt ? 'badge-success' : 'badge-neutral'}" style="font-size:9px;">
              ${isAtt ? 'Attended ✓' : 'Registered / Absent'}
            </span>
          </td>
          <td style="padding:6px 8px;text-align:center;">
            <button type="button" onclick="deleteEventAttendee(${a.id}, '${safeName}', 'edit')" title="Remove ${safeName}" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 6px;font-size:16px;line-height:1;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s ease;" onmouseover="this.style.color='#DC2626';this.style.background='#FEE2E2'" onmouseout="this.style.color='#94A3B8';this.style.background='none'">
              &times;
            </button>
          </td>
        </tr>
      `;
      });
      attHtml += '</tbody></table>';
      attContainer.innerHTML = attHtml;
    }
  }

  function renderEvaluateAttendeesTable(existingActual) {
    const attContainer = document.getElementById('evalAttendeesListContainer');
    if (!currentEvalAttendees || currentEvalAttendees.length === 0) {
      attContainer.innerHTML = `
      <div style="text-align:center;padding:14px;font-size:11px;color:var(--color-text-muted);">
        No participants in the database for this event yet.<br>
        Click "+ Add Participant" above to add attendees, or enter headcount turnout above.
      </div>
    `;
      document.getElementById('evalAttendeeCountBadge').innerText = 'Attended: 0 / 0';
      if (existingActual !== undefined) {
        document.getElementById('evalActualInput').value = existingActual;
      }
    } else {
      let attHtml = `
      <table class="data-table" style="font-size:11px;width:100%;margin:0;">
        <thead>
          <tr>
            <th style="width:45px;text-align:center;padding:6px 8px;">Attended</th>
            <th style="padding:6px 8px;">Participant Name</th>
            <th style="padding:6px 8px;">Mobile Phone</th>
            <th style="padding:6px 8px;">Registration Time</th>
            <th style="padding:6px 8px;text-align:center;width:34px;" title="Remove Participant"></th>
          </tr>
        </thead>
        <tbody>
    `;
      currentEvalAttendees.forEach(a => {
        const isAttended = (a.attended === 1 || a.attended === '1' || a.attended === true || a.attended === undefined || a.attended === null);
        const safeName = (a.resident_name || 'Participant').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        attHtml += `
        <tr>
          <td style="text-align:center;padding:6px 8px;">
            <input type="checkbox" name="attended_ids[]" value="${a.id}" class="eval-attendee-checkbox" ${isAttended ? 'checked' : ''} onchange="updateEvalAttendeeStats()">
          </td>
          <td style="font-weight:600;padding:6px 8px;">${a.resident_name}</td>
          <td style="font-family:monospace;padding:6px 8px;">${a.phone}</td>
          <td style="font-size:10px;color:var(--color-text-muted);padding:6px 8px;">${a.registered_at || '—'}</td>
          <td style="padding:6px 8px;text-align:center;">
            <button type="button" onclick="deleteEventAttendee(${a.id}, '${safeName}', 'eval')" title="Remove ${safeName}" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 6px;font-size:16px;line-height:1;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s ease;" onmouseover="this.style.color='#DC2626';this.style.background='#FEE2E2'" onmouseout="this.style.color='#94A3B8';this.style.background='none'">
              &times;
            </button>
          </td>
        </tr>
      `;
      });
      attHtml += '</tbody></table>';
      attContainer.innerHTML = attHtml;
      updateEvalAttendeeStats(existingActual);
    }
  }

  // ============================================================================
  // Accomplished Event View & Action Handlers
  // ============================================================================
  function openAccomplishedEventModal(act, attendees) {
    currentAccomplishedAct = act;
    if (!attendees && attendeesByActivityMap && attendeesByActivityMap[act.id]) {
      attendees = attendeesByActivityMap[act.id];
    }
    currentAccomplishedAttendees = attendees || [];

    // Determine default mode dynamically based on event's target_type
    const defaultMode = (act.target_type === 'all_members') 
      ? 'all_members' 
      : ((act.target_type === 'household_families') 
          ? 'household_families' 
          : (parseInt(act.target_participants, 10) > TOTAL_PUROK_HOUSEHOLDS * 1.5 ? 'all_members' : 'household_families')
        );
    setAccomplishedRosterMode(defaultMode, false);
    currentAccomplishedRoster = buildEventParticipantRoster(act, currentAccomplishedAttendees, defaultMode);

    const isArchived = (act.is_archived == 1 || act.is_archived === '1' || act.is_archived === true || act.status === 'Archived');
    const isCancelled = (act.status === 'Cancelled');
    const isCompleted = (act.status === 'Completed');
    const now = new Date();
    const startDate = act.start_datetime ? new Date(act.start_datetime.replace(' ', 'T')) : null;
    const isStarted = startDate && (startDate <= now);

    // Update Modal Header Title & Status Badge
    const titleEl = document.getElementById('view_acc_title');
    const badgeEl = document.getElementById('view_acc_status_badge');
    if (isArchived) {
      if (titleEl) titleEl.innerText = 'Archived Event Overview';
      if (badgeEl) {
        badgeEl.className = 'badge badge-neutral';
        badgeEl.innerText = 'Archived';
      }
    } else if (isCancelled) {
      if (titleEl) titleEl.innerText = 'Cancelled Event Overview';
      if (badgeEl) {
        badgeEl.className = 'badge badge-danger';
        badgeEl.innerText = 'Cancelled';
      }
    } else if (isCompleted) {
      if (titleEl) titleEl.innerText = 'Accomplished Event Overview';
      if (badgeEl) {
        badgeEl.className = 'badge badge-success';
        badgeEl.innerText = 'Accomplished';
      }
    } else if (isStarted) {
      if (titleEl) titleEl.innerText = 'Ongoing Event Overview';
      if (badgeEl) {
        badgeEl.className = 'badge badge-info';
        badgeEl.innerText = 'Ongoing';
      }
    } else {
      if (titleEl) titleEl.innerText = 'Scheduled Event Overview';
      if (badgeEl) {
        badgeEl.className = 'badge badge-neutral';
        badgeEl.innerText = 'Scheduled';
      }
    }

    document.getElementById('acc_display_title').innerText = act.title || 'Event Overview';
    document.getElementById('acc_display_type_badge').innerText = act.activity_type || 'Disaster Drill';
    const purokScope = act.target_puroks || 'All Puroks';
    const venueEl = document.getElementById('acc_display_venue');
    if (venueEl) {
      venueEl.innerText = act.venue ? `${act.venue} (${purokScope})` : purokScope;
    }
    document.getElementById('acc_display_facilitator').innerText = act.assigned_personnel || 'Barangay Disaster Team';

    const startFmt = act.start_datetime ? new Date(act.start_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    const endFmt = act.end_datetime ? new Date(act.end_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    document.getElementById('acc_display_schedule').innerText = `${startFmt} — ${endFmt}`;

    const actualPart = currentAccomplishedRoster.filter(r => r.attended).length;
    const targetPart = parseInt(act.target_participants, 10) || currentAccomplishedRoster.length;
    const unitText = (currentAccomplishedRosterMode === 'all_members') ? 'residents' : 'households';
    document.getElementById('acc_display_attendance').innerText = `${actualPart} attended (${targetPart} targeted ${unitText})`;

    document.getElementById('acc_display_description').innerText = act.description || 'No description recorded for this activity.';

    const summaryGroup = document.getElementById('acc_summary_group');
    if (isCancelled && act.cancellation_reason) {
      if (summaryGroup) summaryGroup.style.display = 'block';
      document.getElementById('acc_display_summary').innerHTML = '<span style="color:#DC2626;font-weight:600;">[Cancellation Reason]</span> ' + escapeHtml(act.cancellation_reason);
    } else if (act.evaluation_summary) {
      if (summaryGroup) summaryGroup.style.display = 'block';
      document.getElementById('acc_display_summary').innerText = act.evaluation_summary;
    } else if (isCompleted) {
      if (summaryGroup) summaryGroup.style.display = 'block';
      document.getElementById('acc_display_summary').innerText = 'No evaluation remarks recorded yet.';
    } else {
      if (summaryGroup) summaryGroup.style.display = 'none';
    }

    // Render executed content checklist pills and evaluation percentage (Anti-Slop Unified Palette)
    let executedItems = [];
    if (act.content_execution) {
      try {
        executedItems = typeof act.content_execution === 'string' ? JSON.parse(act.content_execution) : act.content_execution;
      } catch (e) {
        executedItems = [act.content_execution];
      }
    }
    if (!Array.isArray(executedItems)) executedItems = [];

    let expectedItems = [];
    if (act.expected_activities) {
      try {
        expectedItems = typeof act.expected_activities === 'string' ? JSON.parse(act.expected_activities) : act.expected_activities;
      } catch (e) {
        expectedItems = [act.expected_activities];
      }
    }
    if (!Array.isArray(expectedItems) || expectedItems.length === 0) {
      expectedItems = EXPECTED_ACTIVITIES_BY_TYPE[act.activity_type] || STANDARD_EXECUTION_ITEMS;
    }

    const contentBox = document.getElementById('acc_content_execution_box');
    const contentCountLabel = document.getElementById('acc_content_count_label');

    if (isCompleted || act.status === 'Completed') {
      const totalExpected = expectedItems.length > 0 ? expectedItems.length : (executedItems.length || 1);
      const execCount = executedItems.length;
      const evalPercent = totalExpected > 0 ? Math.round((execCount / totalExpected) * 100) : 100;

      if (contentCountLabel) {
        contentCountLabel.innerHTML = `
          <span style="font-size:10px;font-weight:600;color:var(--color-primary);background:var(--color-surface-subtle);border:1px solid var(--color-border);padding:2px 7px;border-radius:4px;">
            ${execCount} of ${totalExpected} Milestones Completed (${evalPercent}%)
          </span>
        `;
      }

      let contentHtml = `
        <div style="margin-bottom:8px;">
          <div style="height:5px;background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:3px;overflow:hidden;">
            <div style="height:100%;width:${evalPercent}%;background:var(--color-primary);border-radius:2px;transition:width 0.3s ease;"></div>
          </div>
        </div>
      `;

      if (executedItems.length > 0) {
        contentHtml += '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:6px;">';
        executedItems.forEach(item => {
          contentHtml += `
            <div style="display:flex;align-items:center;gap:6px;background:#FFFFFF;border:1px solid var(--color-border);padding:5px 8px;border-radius:4px;">
              <div style="width:16px;height:16px;border-radius:3px;background:var(--color-surface-subtle);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;flex-shrink:0;">
                ✓
              </div>
              <span style="font-size:11px;font-weight:500;color:var(--color-text);line-height:1.3;flex:1;">
                ${escapeHtml(item)}
              </span>
            </div>
          `;
        });
        contentHtml += '</div>';
      } else {
        contentHtml += '<div style="font-size:11px;color:var(--color-text-muted);font-style:italic;padding:4px 0;">No milestones executed.</div>';
      }
      contentBox.innerHTML = contentHtml;
    } else {
      const plannedList = (expectedItems && expectedItems.length > 0) ? expectedItems : executedItems;
      const countPlanned = plannedList.length;

      if (contentCountLabel) {
        contentCountLabel.innerHTML = `
          <span style="font-size:10px;font-weight:600;color:var(--color-text-secondary);background:var(--color-surface-subtle);border:1px solid var(--color-border);padding:2px 7px;border-radius:4px;">
            ${countPlanned} Milestones Scheduled
          </span>
        `;
      }

      if (plannedList.length > 0) {
        let contentHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:6px;">';
        plannedList.forEach((item, idx) => {
          contentHtml += `
            <div style="display:flex;align-items:center;gap:6px;background:#FFFFFF;border:1px solid var(--color-border);padding:5px 8px;border-radius:4px;">
              <div style="width:16px;height:16px;border-radius:3px;background:var(--color-surface-subtle);color:var(--color-text-secondary);display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:600;flex-shrink:0;">
                ${idx + 1}
              </div>
              <div style="font-size:11px;font-weight:500;color:var(--color-text);line-height:1.3;flex:1;">
                ${escapeHtml(item)}
              </div>
              <span style="font-size:9px;color:var(--color-text-muted);background:var(--color-surface-subtle);padding:1px 4px;border-radius:3px;border:1px solid var(--color-border);">
                Planned
              </span>
            </div>
          `;
        });
        contentHtml += '</div>';
        contentBox.innerHTML = contentHtml;
      } else {
        contentBox.innerHTML = '<div style="font-size:11px;color:var(--color-text-muted);font-style:italic;padding:4px 0;">No specific execution milestones configured.</div>';
      }
    }

    // Reset attendees search filter
    const searchInput = document.getElementById('acc_attendees_search');
    if (searchInput) searchInput.value = '';

    // Render roster of household families in Participant Attendees
    renderAccomplishedAttendeesTable('');

    // Dynamically Configure Footer Actions
    const footerLeft = document.getElementById('acc_footer_left');
    const footerRight = document.getElementById('acc_footer_right');

    let leftHtml = '';
    if (isArchived) {
      leftHtml = `
      <div style="display:flex;gap:6px;align-items:center;">
        <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/index.php" style="margin:0;" onsubmit="return confirm('Restore this event back to active events?');">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="activity_id" value="${act.id}">
          <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
          <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 9px;color:var(--color-success);border-color:var(--color-success);" title="Restore Event to Active List">
            Restore to Active
          </button>
        </form>
        <button type="button" class="btn btn-outline btn-sm" onclick="deleteCurrentEventFromOverview()" style="font-size:11px;padding:4px 9px;color:#DC2626;border-color:#FCA5A5;display:inline-flex;align-items:center;gap:3px;" title="Permanently Delete Event">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete Permanently
        </button>
      </div>
    `;
    } else if (isCancelled || isCompleted) {
      leftHtml = `
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="archiveCurrentAccomplishedEvent()" style="color:#D97706;border-color:#FCD34D;" title="Move Event to Archives">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
          Archive
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="deleteCurrentEventFromOverview()" style="color:#DC2626;border-color:#FCA5A5;" title="Permanently Delete Event">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete
        </button>
      </div>
    `;
    } else {
      // Scheduled or Ongoing: Cancel button on left, plus Delete option
      leftHtml = `
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="cancelCurrentEventFromOverview()" style="color:var(--color-danger);border-color:var(--color-danger);" title="Cancel Event">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
          Cancel Event
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="deleteCurrentEventFromOverview()" style="color:#DC2626;border-color:#FCA5A5;" title="Permanently Delete Event">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete
        </button>
      </div>
    `;
    }
    if (footerLeft) footerLeft.innerHTML = leftHtml;

    let rightHtml = '';
    if (isArchived || isCancelled) {
      rightHtml = `<button type="button" class="btn btn-outline btn-sm" onclick="closeModal('accomplishedEventModal')">Close</button>`;
    } else if (isCompleted) {
      const isAlreadyEvaluated = !!(act.evaluation_summary || act.status === 'Completed');
      rightHtml = `
      <button type="button" class="btn btn-outline btn-sm" onclick="editCurrentAccomplishedEvent()" title="Edit Event Details (Date & Time Locked)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Edit
      </button>
      <button type="button" class="btn btn-success btn-sm" onclick="evaluateCurrentAccomplishedEvent()" style="background:var(--color-success,#16A34A);border-color:var(--color-success,#16A34A);color:#fff;" title="${isAlreadyEvaluated ? 'Review and Re-evaluate Event Execution' : 'Evaluate Event Execution'}">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
        ${isAlreadyEvaluated ? 'Re-evaluate' : 'Evaluate'}
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('accomplishedEventModal')">Close</button>
    `;
    } else if (isStarted) {
      // Ongoing: Register Resident, Done & Evaluate / Re-evaluate, Edit (time locked), Close
      const isAlreadyEvaluated = !!(act.evaluation_summary || act.status === 'Completed');
      rightHtml = `
      <button type="button" class="btn btn-primary btn-sm" onclick="registerResidentCurrentEventFromOverview()" style="font-size:11px;padding:4px 8px;" title="Register Resident & Send SMS Notice">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
        Register Resident
      </button>
      <button type="button" class="btn btn-success btn-sm" onclick="evaluateCurrentAccomplishedEvent()" style="font-size:11px;padding:4px 8px;background:var(--color-success,#16A34A);border-color:var(--color-success,#16A34A);color:#fff;" title="${isAlreadyEvaluated ? 'Review and Re-evaluate Event Execution' : 'Mark Event Done & Evaluate'}">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
        ${isAlreadyEvaluated ? 'Re-evaluate' : 'Done & Evaluate'}
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="editCurrentAccomplishedEvent()" title="Edit Event Details (Date & Time Locked)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Edit
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('accomplishedEventModal')">Close</button>
    `;
    } else {
      // Scheduled (not yet started): Register Resident, Edit (times editable), Close
      rightHtml = `
      <button type="button" class="btn btn-primary btn-sm" onclick="registerResidentCurrentEventFromOverview()" style="font-size:11px;padding:4px 8px;" title="Register Resident & Send SMS Notice">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
        Register Resident
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="editCurrentScheduledEvent()" title="Edit Event Details">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Edit
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('accomplishedEventModal')">Close</button>
    `;
    }
    if (footerRight) footerRight.innerHTML = rightHtml;

    openModal('accomplishedEventModal');
  }

  // 1. Archive Button action from Event Modal
  function archiveCurrentAccomplishedEvent() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    confirmArchiveActivity(currentAccomplishedAct);
  }

  // Confirm Archive Event Modal
  function confirmArchiveActivity(act) {
    document.getElementById('archive_act_id').value = act.id;
    document.getElementById('archive_act_name').innerText = act.title;
    openModal('archiveActivityModal');
  }

  // 1B. Delete Button action from Event Modal
  function deleteCurrentEventFromOverview() {
    if (!currentAccomplishedAct) return;
    const attendeesCount = currentAccomplishedAttendees ? currentAccomplishedAttendees.length : 0;
    closeModal('accomplishedEventModal');
    confirmDeleteActivity(currentAccomplishedAct, attendeesCount);
  }

  // Confirm Delete Event Modal
  function confirmDeleteActivity(act, attendeesCount = 0) {
    document.getElementById('delete_act_id').value = act.id;
    document.getElementById('delete_act_name').innerText = act.title;
    const countInfo = document.getElementById('delete_attendees_info');
    if (countInfo) {
      countInfo.innerText = attendeesCount + ' registered resident / family member' + (attendeesCount === 1 ? '' : 's');
    }
    openModal('deleteActivityModal');
  }

  // 2. Edit Button action (time & date CANNOT be edited)
  function editCurrentAccomplishedEvent() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    openEditActivityModal(currentAccomplishedAct, true);
  }

  // 3. Edit Button action for Scheduled Event (time & date CAN be edited)
  function editCurrentScheduledEvent() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    openEditActivityModal(currentAccomplishedAct, false);
  }

  // 4. Cancel Button action from Overview Modal
  function cancelCurrentEventFromOverview() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    confirmCancelActivity(currentAccomplishedAct);
  }

  // 5. Register Resident Button action from Overview Modal
  function registerResidentCurrentEventFromOverview() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    openRegisterResidentModal(currentAccomplishedAct, currentAccomplishedAttendees);
  }

  // 6. Evaluate Button action from Accomplished Event Modal
  function evaluateCurrentAccomplishedEvent() {
    if (!currentAccomplishedAct) return;
    closeModal('accomplishedEventModal');
    openEvaluateModal(currentAccomplishedAct, currentAccomplishedAttendees);
  }

  // ============================================================================
  // Evaluate Modal Handlers (Content Execution Checklist + Attendee Attendance)
  // ============================================================================
  function openEvaluateModal(act, attendees) {
    currentEvalAct = act;
    if (!attendees && attendeesByActivityMap && attendeesByActivityMap[act.id]) {
      attendees = attendeesByActivityMap[act.id];
    }
    currentEvalAttendees = attendees ? [...attendees] : [];

    const isAlreadyEvaluated = !!(act.evaluation_summary || act.status === 'Completed');
    const headerTitle = document.getElementById('evalModalHeaderTitle');
    if (headerTitle) {
      headerTitle.innerText = isAlreadyEvaluated ? 'Re-evaluate Event & Execution' : 'Evaluate Event & Execution';
    }
    const submitBtnText = document.getElementById('evalSubmitBtnText');
    if (submitBtnText) {
      submitBtnText.innerText = isAlreadyEvaluated ? 'Save Re-evaluation & Execution' : 'Save Evaluation & Execution';
    }

    document.getElementById('evalActId').value = act.id;
    document.getElementById('evalActName').innerText = (act.title || 'Event') + ' — ' + (act.venue || '');
    document.getElementById('evalStatusSelect').value = act.status === 'Cancelled' ? 'Cancelled' : 'Completed';
    document.getElementById('evalSummaryTextarea').value = act.evaluation_summary || '';

    // 1. Setup Expected Activities Checklist (strictly what was selected by the barangay head)
    let expectedList = [];
    if (act.expected_activities) {
      try {
        expectedList = typeof act.expected_activities === 'string' ? JSON.parse(act.expected_activities) : act.expected_activities;
      } catch (e) {
        expectedList = [act.expected_activities];
      }
    }
    if (!Array.isArray(expectedList) || expectedList.length === 0) {
      if (act.content_execution) {
        try {
          expectedList = typeof act.content_execution === 'string' ? JSON.parse(act.content_execution) : act.content_execution;
        } catch (e) {
          expectedList = [act.content_execution];
        }
      }
    }
    if (!Array.isArray(expectedList) || expectedList.length === 0) {
      expectedList = EXPECTED_ACTIVITIES_BY_TYPE[act.activity_type] || STANDARD_EXECUTION_ITEMS;
    }

    // Which items were already executed (if this completed event was already evaluated)
    let executedList = null;
    if (act.content_execution && (act.status === 'Completed' || act.evaluation_summary)) {
      try {
        executedList = typeof act.content_execution === 'string' ? JSON.parse(act.content_execution) : act.content_execution;
      } catch (e) {
        executedList = null;
      }
    }

    const executionContainer = document.getElementById('evalExecutionChecklistContainer');
    let execHtml = '';
    expectedList.forEach((item) => {
      // By default for evaluating completed event: all expected activities start checked (= 100% total)
      // If previously evaluated, reflect the executed items
      const isChecked = executedList ? executedList.includes(item) : true;
      execHtml += `
      <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:var(--color-text);cursor:pointer;background:#fff;padding:8px 10px;border-radius:6px;border:1px solid #E2E8F0;user-select:none;transition:all 0.15s ease;" onmouseover="this.style.borderColor='#94A3B8'" onmouseout="this.style.borderColor='#E2E8F0'">
        <input type="checkbox" name="content_execution[]" value="${item.replace(/"/g, '&quot;')}" ${isChecked ? 'checked' : ''} onchange="updateExecutionStats()" style="margin-top:2px;cursor:pointer;">
        <span style="line-height:1.4;">${item}</span>
      </label>
    `;
    });
    executionContainer.innerHTML = execHtml;

    // Sync hidden expected activities JSON
    const expHidden = document.getElementById('evalExpectedActivitiesJson');
    if (expHidden) {
      expHidden.value = JSON.stringify(expectedList);
    }

    // Calculate and display completion percentage immediately
    updateExecutionStats();

    // Setup Attendees Roster with Attendance Checkboxes
    renderEvaluateAttendeesTable(act.actual_participants);

    // Reset add participant panel in evaluate modal
    toggleEvalAddParticipantPanel(false);

    openModal('evaluateModal');
  }

  function updateExecutionStats() {
    const checkboxes = document.querySelectorAll('#evalExecutionChecklistContainer input[type="checkbox"]');
    const checked = document.querySelectorAll('#evalExecutionChecklistContainer input[type="checkbox"]:checked');
    const total = checkboxes.length;
    const count = checked.length;
    const percent = total > 0 ? Math.round((count / total) * 100) : 100;

    // 1. Numerical percentage badge
    const badge = document.getElementById('evalExecutionPercentageBadge');
    if (badge) {
      badge.innerText = `${percent}%`;
      if (percent === 100) {
        badge.style.color = '#059669';
      } else if (percent >= 70) {
        badge.style.color = '#2563EB';
      } else if (percent >= 40) {
        badge.style.color = '#D97706';
      } else {
        badge.style.color = '#DC2626';
      }
    }

    // 2. Count subtext
    const subtext = document.getElementById('evalExecutionCountSubtext');
    if (subtext) {
      subtext.innerText = `${count} of ${total} expected activities completed`;
    }

    // 3. Status label
    const statusLabel = document.getElementById('evalExecutionStatusLabel');
    if (statusLabel) {
      if (percent === 100) {
        statusLabel.innerText = 'Full Execution (100%) ✓';
        statusLabel.style.color = '#059669';
      } else if (percent >= 70) {
        statusLabel.innerText = `Substantial Execution (${percent}%)`;
        statusLabel.style.color = '#2563EB';
      } else if (percent >= 40) {
        statusLabel.innerText = `Partial Execution (${percent}%)`;
        statusLabel.style.color = '#D97706';
      } else {
        statusLabel.innerText = `Low Execution (${percent}%) ⚠`;
        statusLabel.style.color = '#DC2626';
      }
    }

    // 4. Icon Wrap styling
    const iconWrap = document.getElementById('evalExecutionIconWrap');
    if (iconWrap) {
      if (percent === 100) {
        iconWrap.style.background = '#ECFDF5';
        iconWrap.style.color = '#059669';
      } else if (percent >= 70) {
        iconWrap.style.background = '#EFF6FF';
        iconWrap.style.color = '#2563EB';
      } else if (percent >= 40) {
        iconWrap.style.background = '#FFFBEB';
        iconWrap.style.color = '#D97706';
      } else {
        iconWrap.style.background = '#FEF2F2';
        iconWrap.style.color = '#DC2626';
      }
    }

    // 6. Progress bar fill & gradient
    const pBar = document.getElementById('evalExecutionProgressBar');
    if (pBar) {
      pBar.style.width = `${percent}%`;
      if (percent === 100) {
        pBar.style.background = 'linear-gradient(90deg, #10B981, #059669)';
      } else if (percent >= 70) {
        pBar.style.background = 'linear-gradient(90deg, #60A5FA, #2563EB)';
      } else if (percent >= 40) {
        pBar.style.background = 'linear-gradient(90deg, #FBBF24, #D97706)';
      } else {
        pBar.style.background = 'linear-gradient(90deg, #F87171, #DC2626)';
      }
    }

    // 7. Section 1 badge pill
    const secBadge = document.getElementById('evalExecutionSectionBadge');
    if (secBadge) {
      secBadge.innerText = `${percent}%`;
      secBadge.className = 'badge ' + (percent === 100 ? 'badge-success' : (percent >= 70 ? 'badge-primary' : (percent >= 40 ? 'badge-warning' : 'badge-danger')));
    }

    // Sync all current items to hidden expected activities JSON
    const allVals = [];
    checkboxes.forEach(cb => allVals.push(cb.value));
    const expHidden = document.getElementById('evalExpectedActivitiesJson');
    if (expHidden) {
      expHidden.value = JSON.stringify(allVals);
    }
  }

  function updateEvalAttendeeStats(existingActual) {
    const checkboxes = document.querySelectorAll('.eval-attendee-checkbox');
    const checked = document.querySelectorAll('.eval-attendee-checkbox:checked');
    const badge = document.getElementById('evalAttendeeCountBadge');
    if (badge) {
      badge.innerText = `Attended: ${checked.length} / ${checkboxes.length}`;
    }
    const input = document.getElementById('evalActualInput');
    if (input) {
      if (existingActual !== undefined && parseInt(existingActual, 10) > checked.length) {
        input.value = existingActual;
      } else {
        input.value = Math.max(checked.length, parseInt(input.value || '0', 10));
      }
    }
  }

  function toggleAttendeeCheckboxes(check) {
    document.querySelectorAll('.eval-attendee-checkbox').forEach(cb => {
      cb.checked = check;
    });
    updateEvalAttendeeStats();
  }

  function toggleExecutionCheckboxes(check) {
    document.querySelectorAll('#evalExecutionChecklistContainer input[type="checkbox"]').forEach(cb => {
      cb.checked = check;
    });
    updateExecutionStats();
  }

  function addCustomExecutionItem() {
    const input = document.getElementById('customExecutionInput');
    const val = input.value.trim();
    if (!val) return;

    const container = document.getElementById('evalExecutionChecklistContainer');
    const label = document.createElement('label');
    label.style.cssText = "display:flex;align-items:flex-start;gap:8px;font-size:11px;color:var(--color-text);cursor:pointer;background:#fff;padding:8px 10px;border-radius:6px;border:1px solid #0284C7;user-select:none;transition:all 0.15s ease;";
    label.onmouseover = function () { this.style.borderColor = '#0369A1'; };
    label.onmouseout = function () { this.style.borderColor = '#0284C7'; };
    label.innerHTML = `
    <input type="checkbox" name="content_execution[]" value="${val.replace(/"/g, '&quot;')}" checked onchange="updateExecutionStats()" style="margin-top:2px;cursor:pointer;">
    <span style="line-height:1.4;">${val}</span>
  `;
    container.appendChild(label);
    input.value = '';
    updateExecutionStats();
  }

  // ============================================================================
  // Edit Event Modal (Locked times if event started or accomplished)
  // ============================================================================
  function openEditActivityModal(act, isLockedTime) {
    document.getElementById('edit_act_id').value = act.id;
    document.getElementById('edit_act_title').value = act.title || '';
    document.getElementById('edit_act_type').value = act.activity_type || 'Disaster Drill';
    document.getElementById('edit_act_personnel').value = act.assigned_personnel || '';
    document.getElementById('edit_act_target').value = act.target_participants || 50;
    document.getElementById('edit_act_desc').value = act.description || '';

    // Venue handling (select strictly from evacuation areas)
    const editVenueSelect = document.getElementById('edit_venue_select');
    if (editVenueSelect) {
      editVenueSelect.value = act.venue || '';
      if (act.venue && editVenueSelect.value !== act.venue) {
        const opt = document.createElement('option');
        opt.value = act.venue;
        opt.textContent = act.venue;
        editVenueSelect.appendChild(opt);
        editVenueSelect.value = act.venue;
      }
    }

    // Initialize Target Purok Scope for Edit Modal
    const isFamiliesEdit = act.target_type 
      ? (act.target_type === 'household_families') 
      : (act.activity_type === 'Social Welfare and Assistance' || parseInt(act.target_participants, 10) <= TOTAL_PUROK_HOUSEHOLDS);
    const editFamilyCb = document.getElementById('edit_count_by_families');
    if (editFamilyCb) {
      editFamilyCb.checked = isFamiliesEdit;
      onCountingUnitCheckboxChange('edit', isFamiliesEdit);
    }
    initPurokScopeForEdit(act.target_puroks, act.target_participants);

    // Populate expected activities checklist for edit
    let savedExecution = [];
    if (act.content_execution) {
      try {
        savedExecution = typeof act.content_execution === 'string' ? JSON.parse(act.content_execution) : act.content_execution;
      } catch (e) {
        savedExecution = [act.content_execution];
      }
    }
    if (!Array.isArray(savedExecution)) savedExecution = [];

    const defaultList = EXPECTED_ACTIVITIES_BY_TYPE[act.activity_type] || Object.values(EXPECTED_ACTIVITIES_BY_TYPE)[0] || [];
    const combinedItems = Array.from(new Set([...defaultList, ...savedExecution]));
    const editContainer = document.getElementById('edit_expected_activities_list');
    if (editContainer) {
      let eHtml = '';
      combinedItems.forEach(item => {
        const isChecked = savedExecution.length > 0 ? savedExecution.includes(item) : true;
        eHtml += `
        <div class="expected-activity-item" style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text);background:#fff;padding:6px 9px;border-radius:5px;border:1px solid #E2E8F0;transition:all 0.15s ease;">
          <input type="checkbox" name="content_execution[]" value="${item.replace(/"/g, '&quot;')}" ${isChecked ? 'checked' : ''} style="margin:0;cursor:pointer;">
          <span style="line-height:1.4;flex:1;cursor:pointer;" onclick="this.previousElementSibling.checked = !this.previousElementSibling.checked;">${item}</span>
          <button type="button" onclick="this.closest('.expected-activity-item').remove()" title="Delete this activity" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 5px;font-size:14px;line-height:1;border-radius:3px;display:flex;align-items:center;justify-content:center;transition:color 0.15s;" onmouseover="this.style.color='#DC2626'" onmouseout="this.style.color='#94A3B8'">
            &times;
          </button>
        </div>
      `;
      });
      editContainer.innerHTML = eHtml;
    }

    const startInput = document.getElementById('edit_act_start');
    const endInput = document.getElementById('edit_act_end');
    const lockedNotice = document.getElementById('edit_locked_time_notice');

    // Format datetime strings to HTML datetime-local: YYYY-MM-DDTHH:MM
    if (act.start_datetime) {
      startInput.value = act.start_datetime.replace(' ', 'T').substring(0, 16);
    }
    if (act.end_datetime) {
      endInput.value = act.end_datetime.replace(' ', 'T').substring(0, 16);
    }

    if (isLockedTime) {
      // If event has started or is accomplished: start time and end time CANNOT be edited
      startInput.setAttribute('disabled', 'disabled');
      endInput.setAttribute('disabled', 'disabled');
      startInput.setAttribute('readonly', 'readonly');
      endInput.setAttribute('readonly', 'readonly');
      lockedNotice.style.display = 'block';
      lockedNotice.innerHTML = '⚠️ <strong>Start Date & Time and End Date & Time cannot be edited</strong> because this event has already started or is accomplished.';
      document.getElementById('editModalHeaderTitle').innerText = 'Edit Event (Start & End Times Locked)';
    } else {
      // If event has NOT yet started: start time and end time CAN be edited
      startInput.removeAttribute('disabled');
      endInput.removeAttribute('disabled');
      startInput.removeAttribute('readonly');
      endInput.removeAttribute('readonly');
      lockedNotice.style.display = 'none';
      document.getElementById('editModalHeaderTitle').innerText = 'Edit Event Details';
    }

    currentEditAct = act;
    currentEditAttendees = (attendeesByActivityMap && attendeesByActivityMap[act.id])
      ? [...attendeesByActivityMap[act.id]]
      : [];
    renderEditAttendeesTable();
    toggleEditAddParticipantPanel(false);

    openModal('editActivityModal');
  }

  // Confirm Cancel Event Modal
  function confirmCancelActivity(act) {
    document.getElementById('cancel_act_id').value = act.id;
    document.getElementById('cancel_act_name').innerText = act.title;
    const sel = document.getElementById('cancel_reason_select');
    if (sel) sel.selectedIndex = 0;
    const det = document.getElementById('cancel_reason_details');
    if (det) det.value = '';
    openModal('cancelActivityModal');
  }

  function onCancelReasonSelectChange(val) {
    const detailsInput = document.getElementById('cancel_reason_details');
    if (!detailsInput) return;
    if (val === 'Other Reason') {
      if (detailsInput.value.startsWith('Cancelled due to:')) detailsInput.value = '';
      detailsInput.focus();
    } else if (val) {
      if (!detailsInput.value || detailsInput.value.startsWith('Cancelled due to:')) {
        detailsInput.value = `Cancelled due to: ${val}. `;
      }
    }
  }

  function validateCancellationForm() {
    const sel = document.getElementById('cancel_reason_select');
    const det = document.getElementById('cancel_reason_details');
    if (!sel || !sel.value) {
      alert('Please select a reason for cancellation from the dropdown.');
      if (sel) sel.focus();
      return false;
    }
    if (!det || !det.value.trim()) {
      alert('Please provide cancellation remarks or explanation in the textbox.');
      if (det) det.focus();
      return false;
    }
    return true;
  }

  // Open Register Resident Modal with live SMS preview
  function openRegisterResidentModal(act, attendees) {
    currentActiveEventForRegistration = act;
    const form = document.getElementById('registerResidentForm');
    if (form) form.reset();

    document.getElementById('reg_act_id').value = act.id;
    document.getElementById('reg_user_id').value = '';
    document.getElementById('reg_display_event_title').innerText = act.title;

    const startFmt = new Date(act.start_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    const endFmt = new Date(act.end_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    document.getElementById('reg_display_event_meta').innerText = `Venue: ${act.venue} • Starts: ${startFmt} • Ends: ${endFmt} • Facilitator: ${act.assigned_personnel}`;

    const attCount = attendees ? attendees.length : (act.actual_participants || 0);
    document.getElementById('reg_attendees_summary_line').innerText = `Total Residents Registered: ${attCount} attendee(s)`;

    updateSmsPreview();
    openModal('registerResidentModal');
  }

  // Quick Select Resident from registered list
  function onSelectResidentQuick(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
      document.getElementById('reg_user_id').value = '';
      return;
    }
    document.getElementById('reg_user_id').value = opt.value;
    document.getElementById('reg_resident_name').value = opt.getAttribute('data-name') || '';
    document.getElementById('reg_phone').value = opt.getAttribute('data-phone') || '';
    updateSmsPreview();
  }

  // Update live SMS text preview dynamically
  function updateSmsPreview() {
    if (!currentActiveEventForRegistration) return;
    const act = currentActiveEventForRegistration;
    const residentName = document.getElementById('reg_resident_name').value.trim() || '[Resident Name]';

    const startFmt = new Date(act.start_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    const endFmt = new Date(act.end_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    const facilitator = act.assigned_personnel || 'Barangay Disaster Team';

    const rawBrgy = act.barangay_name || "<?= addslashes(clean($barangay['name'] ?? '')) ?>";
    const formattedBrgy = rawBrgy ? rawBrgy.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ') : '';
    const committeeHeader = formattedBrgy
      ? `BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE (${formattedBrgy})`
      : `BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE`;

    const preview = `${committeeHeader}\n` +
      `Hi ${residentName}!\n` +
      `You are registered for: ${act.title}\n` +
      `Venue: ${act.venue}\n` +
      `Start: ${startFmt}\n` +
      `End: ${endFmt}\n` +
      `Lead Facilitator: ${facilitator}\n` +
      `Details: ${act.description || 'Community disaster preparedness activity.'}\n` +
      `Thank you for participating!`;

    document.getElementById('reg_sms_preview_box').innerText = preview;
  }

  // View Attendees Modal & SMS Log Modal
  function openViewAttendeesModal(act, attendees) {
    currentViewAttendeesAct = act;
    if (!attendees && attendeesByActivityMap && attendeesByActivityMap[act.id]) {
      attendees = attendeesByActivityMap[act.id];
    }
    currentViewAttendeesList = attendees || [];

    document.getElementById('view_att_modal_title').innerText = `Attendees: ${act.title}`;
    const container = document.getElementById('view_att_list_container');

    if (!currentViewAttendeesList || currentViewAttendeesList.length === 0) {
      container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:12px;">No attendees registered yet for this event.</div>';
    } else {
      let html = `
      <div style="font-size:11px;font-weight:600;margin-bottom:8px;color:var(--color-primary);">
        Total Registered: ${currentViewAttendeesList.length} resident(s)
      </div>
      <table class="data-table" style="font-size:11px;width:100%;">
        <thead>
          <tr>
            <th>Resident Name</th>
            <th>Mobile Number</th>
            <th>Registration Date</th>
            <th>Attendance</th>
            <th>SMS Delivery</th>
            <th style="padding:6px 8px;text-align:center;width:34px;" title="Remove Participant"></th>
          </tr>
        </thead>
        <tbody>
    `;
      currentViewAttendeesList.forEach(a => {
        const isAtt = (a.attended == 1 || a.attended === '1' || a.attended === true);
        const safeName = (a.resident_name || 'Resident').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        html += `
        <tr>
          <td style="font-weight:600;">${a.resident_name || 'Resident'}</td>
          <td style="font-family:monospace;">${a.phone}</td>
          <td style="font-size:10px;color:var(--color-text-muted);">${a.registered_at || '—'}</td>
          <td>
            <span class="badge ${isAtt ? 'badge-success' : 'badge-neutral'}" style="font-size:9px;">
              ${isAtt ? 'Attended ✓' : 'Absent / Registered'}
            </span>
          </td>
          <td>
            <span class="badge ${a.sms_status === 'sent' || a.sms_status === 'simulated' ? 'badge-success' : 'badge-warning'}" style="font-size:9px;">
              ${a.sms_status === 'sent' || a.sms_status === 'simulated' ? 'SMS Sent ✓' : a.sms_status}
            </span>
          </td>
          <td style="padding:6px 8px;text-align:center;">
            <button type="button" onclick="deleteEventAttendee(${a.id}, '${safeName}', 'view')" title="Remove ${safeName}" style="background:none;border:none;color:#94A3B8;cursor:pointer;padding:2px 6px;font-size:16px;line-height:1;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s ease;" onmouseover="this.style.color='#DC2626';this.style.background='#FEE2E2'" onmouseout="this.style.color='#94A3B8';this.style.background='none'">
              &times;
            </button>
          </td>
        </tr>
      `;
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    }

    openModal('viewAttendeesModal');
  }

  // ============================================================================
  // Target Purok Scope Handlers (Single, Multi, or All Puroks)
  // ============================================================================
  function openCreateActivityModal() {
    const actType = document.getElementById('create_activity_type')?.value || '';
    const isWelfare = (actType === 'Social Welfare and Assistance');
    const familyCb = document.getElementById('create_count_by_families');
    if (familyCb) {
      familyCb.checked = isWelfare;
      onCountingUnitCheckboxChange('create', isWelfare);
    } else {
      setAllPuroksScope('create', true);
    }
    const searchInput = document.getElementById('create_purok_search');
    if (searchInput) {
      searchInput.value = '';
      filterPurokCheckboxes('create', '');
    }
    openModal('createActivityModal');
  }

  function setAllPuroksScope(mode, selectAll) {
    const allToggle = document.getElementById(mode + '_all_puroks_toggle');
    if (allToggle) allToggle.checked = selectAll;

    const familyCb = document.getElementById(mode + '_count_by_families');
    const isFamilies = familyCb ? familyCb.checked : false;
    const unitWord = isFamilies ? 'house families' : 'residents';
    const unitLabel = isFamilies ? 'Families' : 'Residents';

    let totalCount = 0;
    const checkboxes = document.querySelectorAll('.' + mode + '-purok-cb');
    checkboxes.forEach(cb => {
      cb.checked = selectAll;
      const count = isFamilies
        ? parseInt(cb.getAttribute('data-households') || '0', 10)
        : parseInt(cb.getAttribute('data-population') || '0', 10);
      if (selectAll) totalCount += count;
      const label = cb.closest('.purok-checkbox-item');
      if (label) {
        if (selectAll) {
          label.classList.add('is-checked');
          label.style.borderColor = 'var(--color-primary, #17324D)';
          label.style.background = '#ECFDF5';
        } else {
          label.classList.remove('is-checked');
          label.style.borderColor = '#E2E8F0';
          label.style.background = '#FFFFFF';
        }
      }
    });

    const hiddenInput = document.getElementById(mode + '_target_puroks_hidden');
    const summaryEl = document.getElementById(mode + '_purok_selection_summary');
    const targetInput = document.getElementById(mode === 'create' ? 'create_target_participants' : 'edit_act_target');
    const popHint = document.getElementById(mode + '_target_pop_hint');
    const allPuroksBadge = document.getElementById(mode + '_all_puroks_badge');

    // Dynamically update target residents input to the selected count
    if (targetInput) {
      targetInput.value = totalCount;
    }
    if (popHint) {
      popHint.innerHTML = totalCount > 0
        ? `Recorded ${isFamilies ? 'Families' : 'Pop'}: <strong>${totalCount.toLocaleString()}</strong>`
        : `Recorded ${isFamilies ? 'Families' : 'Pop'}: 0`;
    }
    if (allPuroksBadge) {
      allPuroksBadge.innerHTML = `<?= count($barangayPuroks) ?> Puroks (${totalCount.toLocaleString()} ${unitLabel})`;
    }

    if (selectAll) {
      if (hiddenInput) hiddenInput.value = 'All Puroks';
      if (summaryEl) {
        summaryEl.style.color = 'var(--color-primary, #17324D)';
        summaryEl.innerHTML = `Selected: All Puroks (Barangay-Wide) • <strong>${totalCount.toLocaleString()}</strong> ${unitWord}`;
      }
    } else {
      if (hiddenInput) hiddenInput.value = '';
      if (summaryEl) {
        summaryEl.style.color = '#DC2626';
        summaryEl.innerText = '⚠️ Please select at least one purok or choose All Puroks.';
      }
    }
  }

  function onAllPuroksToggleChange(mode, isChecked) {
    setAllPuroksScope(mode, isChecked);
  }

  function onSinglePurokChange(mode, skipTargetOverride) {
    const checkboxes = document.querySelectorAll('.' + mode + '-purok-cb');
    const checkedBoxes = Array.from(checkboxes).filter(cb => cb.checked);
    const checkedVals = checkedBoxes.map(cb => cb.value);
    const allToggle = document.getElementById(mode + '_all_puroks_toggle');
    const hiddenInput = document.getElementById(mode + '_target_puroks_hidden');
    const summaryEl = document.getElementById(mode + '_purok_selection_summary');
    const targetInput = document.getElementById(mode === 'create' ? 'create_target_participants' : 'edit_act_target');
    const popHint = document.getElementById(mode + '_target_pop_hint');
    const allPuroksBadge = document.getElementById(mode + '_all_puroks_badge');

    const familyCb = document.getElementById(mode + '_count_by_families');
    const isFamilies = familyCb ? familyCb.checked : false;
    const unitWord = isFamilies ? 'house families' : 'residents';
    const unitLabel = isFamilies ? 'Families' : 'Residents';

    let totalCount = 0;
    checkboxes.forEach(cb => {
      const count = isFamilies
        ? parseInt(cb.getAttribute('data-households') || '0', 10)
        : parseInt(cb.getAttribute('data-population') || '0', 10);
      if (cb.checked) totalCount += count;
      const label = cb.closest('.purok-checkbox-item');
      if (label) {
        if (cb.checked) {
          label.classList.add('is-checked');
          label.style.borderColor = 'var(--color-primary, #17324D)';
          label.style.background = '#ECFDF5';
        } else {
          label.classList.remove('is-checked');
          label.style.borderColor = '#E2E8F0';
          label.style.background = '#FFFFFF';
        }
      }
    });

    if (allPuroksBadge) {
      const fullCount = isFamilies ? TOTAL_PUROK_HOUSEHOLDS : TOTAL_PUROK_POPULATION;
      allPuroksBadge.innerHTML = `<?= count($barangayPuroks) ?> Puroks (${fullCount.toLocaleString()} ${unitLabel})`;
    }

    // Dynamically update target residents input to the selected count
    if (!skipTargetOverride && targetInput) {
      targetInput.value = totalCount;
    }
    if (popHint) {
      popHint.innerHTML = totalCount > 0
        ? `Recorded ${isFamilies ? 'Families' : 'Pop'}: <strong>${totalCount.toLocaleString()}</strong>`
        : `Recorded ${isFamilies ? 'Families' : 'Pop'}: 0`;
    }

    if (checkedVals.length === checkboxes.length) {
      if (allToggle) allToggle.checked = true;
      if (hiddenInput) hiddenInput.value = 'All Puroks';
      if (summaryEl) {
        summaryEl.style.color = 'var(--color-primary, #17324D)';
        summaryEl.innerHTML = `Selected: All Puroks (Barangay-Wide) • <strong>${totalCount.toLocaleString()}</strong> ${unitWord}`;
      }
    } else if (checkedVals.length === 0) {
      if (allToggle) allToggle.checked = false;
      if (hiddenInput) hiddenInput.value = '';
      if (summaryEl) {
        summaryEl.style.color = '#DC2626';
        summaryEl.innerText = '⚠️ Please select at least one purok or choose All Puroks.';
      }
    } else if (checkedVals.length === 1) {
      if (allToggle) allToggle.checked = false;
      if (hiddenInput) hiddenInput.value = checkedVals[0];
      if (summaryEl) {
        summaryEl.style.color = 'var(--color-primary, #17324D)';
        summaryEl.innerHTML = `Selected: 1 Purok (${checkedVals[0]}) • <strong>${totalCount.toLocaleString()}</strong> ${unitWord}`;
      }
    } else {
      if (allToggle) allToggle.checked = false;
      if (hiddenInput) hiddenInput.value = checkedVals.join(', ');
      const displayNames = checkedVals.length <= 3 ? checkedVals.join(', ') : checkedVals.slice(0, 3).join(', ') + ' +' + (checkedVals.length - 3) + ' more';
      if (summaryEl) {
        summaryEl.style.color = 'var(--color-primary, #17324D)';
        summaryEl.innerHTML = `Selected: ${checkedVals.length} Puroks (${displayNames}) • <strong>${totalCount.toLocaleString()}</strong> ${unitWord}`;
      }
    }
  }

  function filterPurokCheckboxes(mode, term) {
    const list = document.getElementById(mode + '_purok_checkbox_list');
    if (!list) return;
    const filter = (term || '').trim().toLowerCase();
    const items = list.querySelectorAll('.purok-checkbox-item');
    items.forEach(item => {
      const pName = (item.getAttribute('data-purok') || item.textContent || '').toLowerCase();
      if (!filter || pName.includes(filter)) {
        item.style.display = 'flex';
      } else {
        item.style.display = 'none';
      }
    });
  }

  function initPurokScopeForEdit(targetPuroks, existingTargetCount) {
    const raw = (targetPuroks || 'All Puroks').trim();
    const editSearch = document.getElementById('edit_purok_search');
    if (editSearch) {
      editSearch.value = '';
      filterPurokCheckboxes('edit', '');
    }

    if (raw === 'All Puroks' || raw === 'All' || raw === '') {
      setAllPuroksScope('edit', true);
      if (existingTargetCount && parseInt(existingTargetCount, 10) > 0) {
        const targetInput = document.getElementById('edit_act_target');
        if (targetInput) targetInput.value = existingTargetCount;
      }
    } else {
      const selectedList = raw.split(',').map(s => s.trim().toLowerCase());
      const checkboxes = document.querySelectorAll('.edit-purok-cb');
      checkboxes.forEach(cb => {
        cb.checked = selectedList.includes(cb.value.trim().toLowerCase());
      });
      const hasExisting = existingTargetCount && parseInt(existingTargetCount, 10) > 0;
      onSinglePurokChange('edit', hasExisting);
      if (hasExisting) {
        const targetInput = document.getElementById('edit_act_target');
        if (targetInput) targetInput.value = existingTargetCount;
      }
    }
  }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>