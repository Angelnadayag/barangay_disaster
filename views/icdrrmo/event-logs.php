<?php
// ============================================================================
// Views (ICDRRMO): Barangay Event Logs & Audit Trail
// Audit log of barangay preparedness activities: CRUD, attendees, evaluations, archives & restores
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Barangay Event Logs";
$currentPage = 'event-logs.php';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$search = trim($_GET['search'] ?? '');
$filterAction = trim($_GET['action_type'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

function getLogsFilterUrl($newParams = []) {
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

// Base query for event-related audit logs
$sql = "
    SELECT sl.*, u.barangay_id, b.name AS barangay_name, u.email 
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN barangays b ON u.barangay_id = b.id
    WHERE (
        sl.module IN ('Preparedness', 'Events', 'Event') 
        OR sl.action LIKE '%ACTIVITY%' 
        OR sl.action LIKE '%EVENT%' 
        OR sl.action LIKE '%ATTENDEE%' 
        OR sl.action LIKE '%ATTENDANCE%'
    )
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (sl.user_name LIKE ? OR sl.action LIKE ? OR sl.details LIKE ? OR sl.ip_address LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (!empty($filterAction)) {
    if ($filterAction === 'ARCHIVE_RESTORE_ALL') {
        $sql .= " AND sl.action IN ('ARCHIVE_ACTIVITY', 'RESTORE_ACTIVITY')";
    } elseif ($filterAction === 'ATTENDEE_ALL') {
        $sql .= " AND sl.action IN ('ADD_ATTENDEE', 'REGISTER_RESIDENT_EVENT', 'VOID_ATTENDEE', 'MARK_ATTENDANCE', 'VOID_ATTENDANCE')";
    } elseif ($filterAction === 'ATTENDANCE_ALL') {
        $sql .= " AND sl.action IN ('MARK_ATTENDANCE', 'VOID_ATTENDANCE')";
    } elseif ($filterAction === 'VOID_ALL') {
        $sql .= " AND sl.action IN ('VOID_ATTENDANCE', 'VOID_ATTENDEE')";
    } elseif ($filterAction === 'EVALUATION_ALL') {
        $sql .= " AND sl.action IN ('EVALUATE_ACTIVITY', 'REEVALUATE_ACTIVITY')";
    } else {
        $sql .= " AND sl.action = ?";
        $params[] = $filterAction;
    }
}

if (!empty($startDate)) {
    $sql .= " AND sl.created_at >= ?";
    $params[] = $startDate . " 00:00:00";
}

if (!empty($endDate)) {
    $sql .= " AND sl.created_at <= ?";
    $params[] = $endDate . " 23:59:59";
}

$sql .= " ORDER BY sl.created_at DESC LIMIT 250";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Quick Statistics
$statTotalStmt = $db->query("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN action = 'ARCHIVE_ACTIVITY' THEN 1 ELSE 0 END) AS archive_count,
        SUM(CASE WHEN action = 'RESTORE_ACTIVITY' THEN 1 ELSE 0 END) AS restore_count,
        SUM(CASE WHEN action IN ('EVALUATE_ACTIVITY', 'REEVALUATE_ACTIVITY') THEN 1 ELSE 0 END) AS eval_count,
        SUM(CASE WHEN action IN ('ADD_ATTENDEE', 'REGISTER_RESIDENT_EVENT', 'VOID_ATTENDEE', 'MARK_ATTENDANCE', 'VOID_ATTENDANCE') THEN 1 ELSE 0 END) AS attendee_ops_count,
        SUM(CASE WHEN action = 'MARK_ATTENDANCE' THEN 1 ELSE 0 END) AS mark_att_count,
        SUM(CASE WHEN action IN ('VOID_ATTENDANCE', 'VOID_ATTENDEE') THEN 1 ELSE 0 END) AS void_att_count,
        SUM(CASE WHEN action IN ('SCHEDULE_ACTIVITY', 'UPDATE_ACTIVITY', 'ARCHIVE_ACTIVITY', 'RESTORE_ACTIVITY', 'CANCEL_ACTIVITY', 'DELETE_ACTIVITY') THEN 1 ELSE 0 END) AS lifecycle_count
    FROM system_logs
    WHERE module IN ('Preparedness', 'Events', 'Event') 
       OR action LIKE '%ACTIVITY%' 
       OR action LIKE '%EVENT%' 
       OR action LIKE '%ATTENDEE%' 
       OR action LIKE '%ATTENDANCE%'
");
$stats = $statTotalStmt->fetch(PDO::FETCH_ASSOC);

// Semantic status badges adhering strictly to the design system palette
function renderActionBadge($action) {
    switch ($action) {
        case 'ARCHIVE_ACTIVITY':
            return ['label' => 'Archived', 'bg' => 'var(--color-surface-subtle, #F0F3F4)', 'color' => 'var(--color-primary, #17324D)', 'border' => 'var(--color-border, #D9E0E3)'];
        case 'RESTORE_ACTIVITY':
            return ['label' => 'Restored', 'bg' => 'var(--color-success-bg, #EAF4EB)', 'color' => 'var(--color-success, #2E7D32)', 'border' => 'var(--color-success-border, #BCE3C1)'];
        case 'SCHEDULE_ACTIVITY':
        case 'CREATE_EVENT':
            return ['label' => 'Scheduled', 'bg' => 'var(--color-info-bg, #EBF3FC)', 'color' => 'var(--color-info, #1565C0)', 'border' => 'var(--color-info-border, #B7D7FA)'];
        case 'UPDATE_ACTIVITY':
        case 'EDIT_EVENT':
            return ['label' => 'Updated', 'bg' => 'var(--color-warning-bg, #FEF8E8)', 'color' => 'var(--color-warning, #B78103)', 'border' => 'var(--color-warning-border, #FCE6AA)'];
        case 'EVALUATE_ACTIVITY':
            return ['label' => 'Evaluated', 'bg' => 'var(--color-success-bg, #EAF4EB)', 'color' => 'var(--color-success, #2E7D32)', 'border' => 'var(--color-success-border, #BCE3C1)'];
        case 'REEVALUATE_ACTIVITY':
            return ['label' => 'Re-evaluated', 'bg' => 'var(--color-surface-subtle, #F0F3F4)', 'color' => 'var(--color-secondary, #2F6F73)', 'border' => 'var(--color-border, #D9E0E3)'];
        case 'ADD_ATTENDEE':
        case 'REGISTER_RESIDENT_EVENT':
            return ['label' => 'Attendee Added', 'bg' => 'var(--color-info-bg, #EBF3FC)', 'color' => 'var(--color-info, #1565C0)', 'border' => 'var(--color-info-border, #B7D7FA)'];
        case 'VOID_ATTENDEE':
            return ['label' => 'Attendee Voided', 'bg' => 'var(--color-danger-bg, #FDE8E8)', 'color' => 'var(--color-danger, #C62828)', 'border' => 'var(--color-danger-border, #F8B4B4)'];
        case 'MARK_ATTENDANCE':
            return ['label' => 'Attended', 'bg' => 'var(--color-success-bg, #EAF4EB)', 'color' => 'var(--color-success, #2E7D32)', 'border' => 'var(--color-success-border, #BCE3C1)'];
        case 'VOID_ATTENDANCE':
            return ['label' => 'Attendance Voided', 'bg' => 'var(--color-danger-bg, #FDE8E8)', 'color' => 'var(--color-danger, #C62828)', 'border' => 'var(--color-danger-border, #F8B4B4)'];
        case 'CANCEL_ACTIVITY':
            return ['label' => 'Cancelled', 'bg' => 'var(--color-danger-bg, #FDE8E8)', 'color' => 'var(--color-danger, #C62828)', 'border' => 'var(--color-danger-border, #F8B4B4)'];
        case 'DELETE_ACTIVITY':
            return ['label' => 'Deleted', 'bg' => 'var(--color-danger-bg, #FDE8E8)', 'color' => 'var(--color-danger, #C62828)', 'border' => 'var(--color-danger-border, #F8B4B4)'];
        case 'RSVP_EVENT':
            return ['label' => 'RSVP', 'bg' => 'var(--color-info-bg, #EBF3FC)', 'color' => 'var(--color-info, #1565C0)', 'border' => 'var(--color-info-border, #B7D7FA)'];
        default:
            return ['label' => ucwords(strtolower(str_replace('_', ' ', $action))), 'bg' => 'var(--color-surface-subtle, #F0F3F4)', 'color' => 'var(--color-text-secondary, #66737D)', 'border' => 'var(--color-border, #D9E0E3)'];
    }
}
?>

<style>
  .system-logs-container {
    font-family: 'Roboto', sans-serif;
    color: var(--color-text, #24313A);
  }
  .system-logs-container h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--color-primary, #17324D);
    margin: 0;
  }
  .system-logs-container .page-header-desc {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text-secondary, #66737D);
    margin: 3px 0 0 0;
  }
  .kpi-stat-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: var(--radius-sm, 6px);
    padding: 10px 12px;
    display: flex;
    align-items: center;
    transition: border-color 0.15s ease;
  }
  .kpi-stat-card:hover {
    border-color: var(--color-primary, #17324D);
  }
  .kpi-card-title {
    font-family: 'Poppins', sans-serif;
    font-size: 10px;
    font-weight: 600;
    color: var(--color-text-secondary, #66737D);
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  .kpi-card-value {
    font-family: 'Poppins', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: var(--color-primary, #17324D);
    line-height: 1.2;
    margin-top: 2px;
  }
  .kpi-card-sub {
    font-family: 'Roboto', sans-serif;
    font-size: 9.5px;
    color: var(--color-text-muted, #8A96A0);
    margin-top: 2px;
  }
  .logs-tab-pill {
    padding: 5px 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 500;
    border-radius: var(--radius-sm, 6px);
    text-decoration: none;
    display: inline-block;
    transition: all 0.15s ease;
  }
  .logs-tab-pill.active {
    background: var(--color-primary, #17324D);
    color: #FFFFFF;
  }
  .logs-tab-pill:not(.active) {
    background: var(--color-surface, #FFFFFF);
    color: var(--color-text-secondary, #66737D);
    border: 1px solid var(--color-border, #D9E0E3);
  }
  .logs-tab-pill:not(.active):hover {
    background: var(--color-surface-subtle, #F0F3F4);
    color: var(--color-primary, #17324D);
  }
  .action-pill {
    display: inline-block;
    font-family: 'Roboto', sans-serif;
    font-size: 9.5px;
    font-weight: 500;
    padding: 2px 7px;
    border-radius: var(--radius-sm, 6px);
    line-height: 1.3;
    white-space: nowrap;
  }
  .system-logs-container .data-table th {
    font-family: 'Poppins', sans-serif;
    font-size: 10px;
    font-weight: 600;
    color: var(--color-text-secondary, #66737D);
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  .system-logs-container .data-table td {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text, #24313A);
  }
</style>

<main class="main-content system-logs-container">
  <!-- Top Navigation Subtabs -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 14px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/views/icdrrmo/event-logs.php" class="logs-tab-pill active">
        Event Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="logs-tab-pill">
        All System Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/resources-logs.php" class="logs-tab-pill" title="View Resources Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        Resources Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/supplies-logs.php" class="logs-tab-pill" title="View Supplies Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
        Supplies Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=movements" class="logs-tab-pill" title="View Stock Movements & Adjustments Ledger">
        Stock Movements
      </a>
    </div>
    <div style="display:flex; align-items:center; gap:6px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" style="font-family:'Poppins',sans-serif; font-size:10px; padding:4px 10px;">
        Print
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="exportLogsToCSV()" style="font-family:'Poppins',sans-serif; font-size:10px; padding:4px 10px;">
        Export CSV
      </button>
    </div>
  </div>

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom: 14px;">
    <div class="page-header-title-wrap">
      <h1>Barangay Event Logs</h1>
      <p class="page-header-desc">
        Audit trail of barangay preparedness activity updates, attendee registrations, and evaluations.
      </p>
    </div>
  </div>

  <!-- 5 KPI Stat Cards Overview -->
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px;">
    <!-- Card 1: Total Logs -->
    <a href="<?= getLogsFilterUrl(['action_type' => '']) ?>" class="kpi-stat-card" style="text-decoration:none; color:inherit;">
      <div>
        <div class="kpi-card-title">Total Logs</div>
        <div class="kpi-card-value"><?= number_format((int)($stats['total_count'] ?? 0)) ?></div>
        <div class="kpi-card-sub">Recorded events</div>
      </div>
    </a>

    <!-- Card 2: Archived & Restored -->
    <a href="<?= getLogsFilterUrl(['action_type' => 'ARCHIVE_RESTORE_ALL']) ?>" class="kpi-stat-card" style="text-decoration:none; color:inherit; border-color:<?= in_array($filterAction, ['ARCHIVE_RESTORE_ALL', 'ARCHIVE_ACTIVITY', 'RESTORE_ACTIVITY'], true) ? 'var(--color-primary, #17324D)' : 'var(--color-border, #D9E0E3)' ?>;">
      <div>
        <div class="kpi-card-title">Archived &amp; Restored</div>
        <div class="kpi-card-value">
          <?= number_format((int)($stats['archive_count'] ?? 0)) ?> <span style="font-size:11px; font-weight:500; color:var(--color-text-secondary);">/ <?= number_format((int)($stats['restore_count'] ?? 0)) ?> restored</span>
        </div>
        <div class="kpi-card-sub">Archive operations</div>
      </div>
    </a>

    <!-- Card 3: Evaluations -->
    <a href="<?= getLogsFilterUrl(['action_type' => 'EVALUATION_ALL']) ?>" class="kpi-stat-card" style="text-decoration:none; color:inherit; border-color:<?= $filterAction === 'EVALUATION_ALL' ? 'var(--color-primary, #17324D)' : 'var(--color-border, #D9E0E3)' ?>;">
      <div>
        <div class="kpi-card-title">Evaluations</div>
        <div class="kpi-card-value"><?= number_format((int)($stats['eval_count'] ?? 0)) ?></div>
        <div class="kpi-card-sub">Accomplished reviews</div>
      </div>
    </a>

    <!-- Card 4: Attendee Operations -->
    <a href="<?= getLogsFilterUrl(['action_type' => 'ATTENDEE_ALL']) ?>" class="kpi-stat-card" style="text-decoration:none; color:inherit; border-color:<?= in_array($filterAction, ['ATTENDEE_ALL', 'MARK_ATTENDANCE', 'VOID_ATTENDANCE', 'VOID_ATTENDEE', 'VOID_ALL', 'ADD_ATTENDEE', 'REGISTER_RESIDENT_EVENT'], true) ? 'var(--color-primary, #17324D)' : 'var(--color-border, #D9E0E3)' ?>;">
      <div>
        <div class="kpi-card-title">Attendee Operations</div>
        <div class="kpi-card-value"><?= number_format((int)($stats['attendee_ops_count'] ?? 0)) ?></div>
        <div class="kpi-card-sub"><?= (int)($stats['mark_att_count'] ?? 0) ?> attended &bull; <?= (int)($stats['void_att_count'] ?? 0) ?> voided</div>
      </div>
    </a>

    <!-- Card 5: Attendance Check-ins -->
    <a href="<?= getLogsFilterUrl(['action_type' => 'ATTENDANCE_ALL']) ?>" class="kpi-stat-card" style="text-decoration:none; color:inherit; border-color:<?= in_array($filterAction, ['ATTENDANCE_ALL', 'MARK_ATTENDANCE', 'VOID_ATTENDANCE', 'VOID_ALL'], true) ? 'var(--color-primary, #17324D)' : 'var(--color-border, #D9E0E3)' ?>;">
      <div>
        <div class="kpi-card-title">Attendance Check-ins</div>
        <div class="kpi-card-value">
          <?= number_format((int)($stats['mark_att_count'] ?? 0)) ?> <span style="font-size:11px; font-weight:500; color:var(--color-text-secondary);">/ <?= number_format((int)($stats['void_att_count'] ?? 0)) ?> void</span>
        </div>
        <div class="kpi-card-sub">Turnout checks</div>
      </div>
    </a>
  </div>

  <!-- Quick Action Filter Selection Pills -->
  <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
    <span style="font-family:'Poppins',sans-serif; font-size:10px; font-weight:600; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:0.3px; margin-right:4px;">Filter:</span>
    <a href="<?= getLogsFilterUrl(['action_type' => '']) ?>" class="btn btn-xs <?= empty($filterAction) ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      All
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'ARCHIVE_ACTIVITY']) ?>" class="btn btn-xs <?= $filterAction === 'ARCHIVE_ACTIVITY' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Archived (<?= !empty($stats['archive_count']) ? $stats['archive_count'] : '0' ?>)
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'RESTORE_ACTIVITY']) ?>" class="btn btn-xs <?= $filterAction === 'RESTORE_ACTIVITY' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Restored (<?= !empty($stats['restore_count']) ? $stats['restore_count'] : '0' ?>)
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'ATTENDEE_ALL']) ?>" class="btn btn-xs <?= $filterAction === 'ATTENDEE_ALL' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Attendee Ops (<?= !empty($stats['attendee_ops_count']) ? $stats['attendee_ops_count'] : '0' ?>)
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'MARK_ATTENDANCE']) ?>" class="btn btn-xs <?= $filterAction === 'MARK_ATTENDANCE' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Attended (<?= !empty($stats['mark_att_count']) ? $stats['mark_att_count'] : '0' ?>)
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'VOID_ALL']) ?>" class="btn btn-xs <?= $filterAction === 'VOID_ALL' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Voided (<?= !empty($stats['void_att_count']) ? $stats['void_att_count'] : '0' ?>)
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'SCHEDULE_ACTIVITY']) ?>" class="btn btn-xs <?= $filterAction === 'SCHEDULE_ACTIVITY' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Scheduled
    </a>
    <a href="<?= getLogsFilterUrl(['action_type' => 'EVALUATION_ALL']) ?>" class="btn btn-xs <?= $filterAction === 'EVALUATION_ALL' ? 'btn-primary' : 'btn-outline' ?>" style="font-family:'Poppins',sans-serif; font-size:10px; padding:3px 8px;">
      Evaluations
    </a>
  </div>

  <!-- Filter Card -->
  <div class="card" style="margin-bottom: 14px;">
    <div class="card-body" style="padding: 10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
        
        <!-- Search Keyword -->
        <div class="search-input-wrap" style="flex:1; min-width:200px;">
          <input type="text" name="search" class="form-control" placeholder="Search user, event, details, IP..." value="<?= clean($search) ?>" style="font-family:'Roboto',sans-serif; font-size:10.5px; height:32px;">
        </div>

        <!-- Action Category Filter -->
        <div style="min-width:180px;">
          <select name="action_type" class="form-control" style="font-family:'Roboto',sans-serif; font-size:10.5px; height:32px;">
            <option value="">All Action Types</option>
            <optgroup label="Archive &amp; Restore">
              <option value="ARCHIVE_RESTORE_ALL" <?= $filterAction === 'ARCHIVE_RESTORE_ALL' ? 'selected' : '' ?>>All Archive &amp; Restore</option>
              <option value="ARCHIVE_ACTIVITY" <?= $filterAction === 'ARCHIVE_ACTIVITY' ? 'selected' : '' ?>>Event Archived</option>
              <option value="RESTORE_ACTIVITY" <?= $filterAction === 'RESTORE_ACTIVITY' ? 'selected' : '' ?>>Event Restored</option>
            </optgroup>
            <optgroup label="Attendee Operations">
              <option value="ATTENDEE_ALL" <?= $filterAction === 'ATTENDEE_ALL' ? 'selected' : '' ?>>All Attendee Operations</option>
              <option value="MARK_ATTENDANCE" <?= $filterAction === 'MARK_ATTENDANCE' ? 'selected' : '' ?>>Marked Attended</option>
              <option value="VOID_ALL" <?= $filterAction === 'VOID_ALL' ? 'selected' : '' ?>>All Voided (Absent &amp; Removed)</option>
              <option value="VOID_ATTENDANCE" <?= $filterAction === 'VOID_ATTENDANCE' ? 'selected' : '' ?>>Attendance Voided (Absent)</option>
              <option value="VOID_ATTENDEE" <?= $filterAction === 'VOID_ATTENDEE' ? 'selected' : '' ?>>Attendee Voided (Removed)</option>
              <option value="ADD_ATTENDEE" <?= $filterAction === 'ADD_ATTENDEE' ? 'selected' : '' ?>>Attendee Added</option>
              <option value="REGISTER_RESIDENT_EVENT" <?= $filterAction === 'REGISTER_RESIDENT_EVENT' ? 'selected' : '' ?>>Resident Registered</option>
            </optgroup>
            <optgroup label="Core Events">
              <option value="SCHEDULE_ACTIVITY" <?= $filterAction === 'SCHEDULE_ACTIVITY' ? 'selected' : '' ?>>Event Scheduled</option>
              <option value="UPDATE_ACTIVITY" <?= $filterAction === 'UPDATE_ACTIVITY' ? 'selected' : '' ?>>Event Updated</option>
              <option value="CANCEL_ACTIVITY" <?= $filterAction === 'CANCEL_ACTIVITY' ? 'selected' : '' ?>>Event Cancelled</option>
              <option value="DELETE_ACTIVITY" <?= $filterAction === 'DELETE_ACTIVITY' ? 'selected' : '' ?>>Event Deleted</option>
            </optgroup>
            <optgroup label="Evaluations">
              <option value="EVALUATION_ALL" <?= $filterAction === 'EVALUATION_ALL' ? 'selected' : '' ?>>All Evaluations</option>
              <option value="EVALUATE_ACTIVITY" <?= $filterAction === 'EVALUATE_ACTIVITY' ? 'selected' : '' ?>>Evaluated</option>
              <option value="REEVALUATE_ACTIVITY" <?= $filterAction === 'REEVALUATE_ACTIVITY' ? 'selected' : '' ?>>Re-evaluated</option>
            </optgroup>
          </select>
        </div>

        <!-- Date Range Filter -->
        <div style="display:flex; align-items:center; gap:4px;">
          <input type="date" name="start_date" class="form-control" value="<?= clean($startDate) ?>" style="font-family:'Roboto',sans-serif; font-size:10.5px; width:120px; height:32px;">
          <span style="font-size:10px; color:var(--color-text-muted);">to</span>
          <input type="date" name="end_date" class="form-control" value="<?= clean($endDate) ?>" style="font-family:'Roboto',sans-serif; font-size:10.5px; width:120px; height:32px;">
        </div>

        <!-- Submit & Reset Buttons -->
        <div style="display:flex; align-items:center; gap:4px;">
          <button type="submit" class="btn btn-primary btn-sm" style="font-family:'Poppins',sans-serif; font-size:10.5px; padding:5px 12px; height:32px;">
            Filter
          </button>
          <?php if (!empty($search) || !empty($filterAction) || !empty($startDate) || !empty($endDate)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/event-logs.php" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif; font-size:10.5px; padding:5px 10px; height:32px;">
              Reset
            </a>
          <?php endif; ?>
        </div>

        <div style="margin-left:auto;">
          <span style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-muted);">
            <?= count($logs) ?> entries
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Logs Table Card -->
  <div class="card">
    <div class="table-responsive" style="max-height: 650px; overflow-y:auto;">
      <table class="data-table" id="eventLogsTable">
        <thead>
          <tr>
            <th style="min-width:130px;">Timestamp</th>
            <th style="min-width:120px;">Action</th>
            <th style="min-width:150px;">User</th>
            <th style="min-width:120px;">Barangay</th>
            <th style="min-width:260px;">Details</th>
            <th style="min-width:85px;">IP Address</th>
            <th style="text-align:right; min-width:70px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:32px 16px;">
                <div style="font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; color:var(--color-text);">No logs found</div>
                <div style="font-family:'Roboto',sans-serif; font-size:10.5px; color:var(--color-text-secondary); margin-top:2px;">No event audit records match the selected filters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): ?>
              <?php 
                $badge = renderActionBadge($log['action']);
                $eventId = null;
                if (preg_match('/#(\d+)/', $log['details'], $m)) {
                    $eventId = (int)$m[1];
                }
              ?>
              <tr class="log-row">
                <!-- Timestamp -->
                <td style="white-space:nowrap;">
                  <div style="font-family:'Roboto',sans-serif; font-size:10.5px; font-weight:500; color:var(--color-text);">
                    <?= formatDate($log['created_at'], 'M d, Y') ?>
                  </div>
                  <div style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-muted);">
                    <?= formatDate($log['created_at'], 'h:i:s A') ?>
                  </div>
                </td>

                <!-- Action Badge -->
                <td>
                  <span class="action-pill" style="background:<?= $badge['bg'] ?>; color:<?= $badge['color'] ?>; border:1px solid <?= $badge['border'] ?>;">
                    <?= clean($badge['label']) ?>
                  </span>
                </td>

                <!-- Performed By User -->
                <td>
                  <div style="font-family:'Roboto',sans-serif; font-size:10.5px; font-weight:600; color:var(--color-primary);">
                    <?= clean($log['user_name']) ?>
                  </div>
                  <div style="font-family:'Roboto',sans-serif; font-size:9px; color:var(--color-text-muted);">
                    <?= clean(formatRoleName($log['role'])) ?>
                  </div>
                </td>

                <!-- Barangay -->
                <td>
                  <?php if (!empty($log['barangay_name'])): ?>
                    <span style="font-family:'Roboto',sans-serif; font-size:10.5px; color:var(--color-secondary);">
                      <?= clean($log['barangay_name']) ?>
                    </span>
                  <?php else: ?>
                    <span style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-muted);">City / ICDRRMO</span>
                  <?php endif; ?>
                </td>

                <!-- Event Context & Audit Details -->
                <td style="font-family:'Roboto',sans-serif; font-size:10.5px; line-height:1.4; color:var(--color-text);">
                  <?php if ($eventId): ?>
                    <a href="<?= BASE_URL ?>/views/icdrrmo/event-details.php?id=<?= $eventId ?>" 
                       style="font-family:'Roboto',sans-serif; font-size:9.5px; font-weight:700; color:var(--color-primary); text-decoration:none; margin-right:4px;" 
                       title="View Event Details #<?= $eventId ?>">
                      #<?= $eventId ?>
                    </a>
                  <?php endif; ?>
                  <span><?= clean($log['details']) ?></span>
                </td>

                <!-- IP Address -->
                <td style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-muted); white-space:nowrap;">
                  <?= clean($log['ip_address'] ?? '127.0.0.1') ?>
                </td>

                <!-- Details Action Button -->
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-xs" 
                    onclick='showLogDetailModal(<?= json_encode($log, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                    style="font-family:'Poppins',sans-serif; font-size:9.5px; padding:2px 6px;">
                    Inspect
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- MODAL: Inspect Single Audit Log Entry -->
<div class="modal-overlay" id="logDetailModal">
  <div class="modal-dialog">
    <div class="modal-header" style="padding:12px 16px;">
      <h3 class="modal-title" style="font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; color:var(--color-primary);">
        Audit Log Record
      </h3>
      <button class="modal-close-btn" onclick="closeModal('logDetailModal')">&times;</button>
    </div>
    <div class="modal-body" style="padding:14px 16px;">
      <div style="background:var(--color-surface-subtle); border:1px solid var(--color-border); border-radius:var(--radius-sm,6px); padding:10px 12px; margin-bottom:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
          <span id="modalActionBadge" class="action-pill"></span>
          <span id="modalTimestamp" style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-muted);"></span>
        </div>
        <div id="modalDetailsText" style="font-family:'Roboto',sans-serif; font-size:11px; font-weight:500; color:var(--color-text); line-height:1.4;"></div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-family:'Roboto',sans-serif; font-size:10.5px;">
        <div>
          <div style="color:var(--color-text-muted); font-size:9.5px; font-weight:600; text-transform:uppercase;">User</div>
          <div id="modalUserName" style="font-weight:600; color:var(--color-primary); margin-top:2px;"></div>
          <div id="modalUserRole" style="font-size:9.5px; color:var(--color-text-secondary);"></div>
        </div>

        <div>
          <div style="color:var(--color-text-muted); font-size:9.5px; font-weight:600; text-transform:uppercase;">Barangay</div>
          <div id="modalBarangay" style="font-weight:600; color:var(--color-secondary); margin-top:2px;"></div>
        </div>

        <div>
          <div style="color:var(--color-text-muted); font-size:9.5px; font-weight:600; text-transform:uppercase;">Module</div>
          <div id="modalModule" style="margin-top:2px;"></div>
        </div>

        <div>
          <div style="color:var(--color-text-muted); font-size:9.5px; font-weight:600; text-transform:uppercase;">IP Address</div>
          <div id="modalIp" style="margin-top:2px; color:var(--color-text-muted);"></div>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="padding:10px 16px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('logDetailModal')" style="font-family:'Poppins',sans-serif; font-size:10.5px; padding:4px 12px;">Close</button>
    </div>
  </div>
</div>

<script>
function showLogDetailModal(log) {
  if (!log) return;
  document.getElementById('modalTimestamp').textContent = log.created_at;
  document.getElementById('modalDetailsText').textContent = log.details;
  document.getElementById('modalUserName').textContent = log.user_name || 'System';
  document.getElementById('modalUserRole').textContent = log.role ? (log.role.replace('_', ' ').toUpperCase()) : 'System';
  document.getElementById('modalBarangay').textContent = log.barangay_name ? log.barangay_name : 'City / ICDRRMO';
  document.getElementById('modalModule').textContent = log.module || 'Preparedness';
  document.getElementById('modalIp').textContent = log.ip_address || '127.0.0.1';

  const badgeEl = document.getElementById('modalActionBadge');
  badgeEl.textContent = log.action.replace('_', ' ');
  if (log.action === 'ARCHIVE_ACTIVITY') {
    badgeEl.textContent = 'Archived';
    badgeEl.style.background = 'var(--color-surface-subtle, #F0F3F4)';
    badgeEl.style.color = 'var(--color-primary, #17324D)';
    badgeEl.style.border = '1px solid var(--color-border, #D9E0E3)';
  } else if (log.action === 'RESTORE_ACTIVITY' || log.action === 'MARK_ATTENDANCE' || log.action === 'EVALUATE_ACTIVITY') {
    badgeEl.textContent = log.action === 'MARK_ATTENDANCE' ? 'Attended' : (log.action === 'RESTORE_ACTIVITY' ? 'Restored' : 'Evaluated');
    badgeEl.style.background = 'var(--color-success-bg, #EAF4EB)';
    badgeEl.style.color = 'var(--color-success, #2E7D32)';
    badgeEl.style.border = '1px solid var(--color-success-border, #BCE3C1)';
  } else if (log.action === 'VOID_ATTENDANCE' || log.action === 'VOID_ATTENDEE' || log.action === 'CANCEL_ACTIVITY' || log.action === 'DELETE_ACTIVITY') {
    badgeEl.textContent = log.action === 'VOID_ATTENDANCE' ? 'Attendance Voided' : (log.action === 'VOID_ATTENDEE' ? 'Attendee Voided' : (log.action === 'CANCEL_ACTIVITY' ? 'Cancelled' : 'Deleted'));
    badgeEl.style.background = 'var(--color-danger-bg, #FDE8E8)';
    badgeEl.style.color = 'var(--color-danger, #C62828)';
    badgeEl.style.border = '1px solid var(--color-danger-border, #F8B4B4)';
  } else {
    badgeEl.style.background = 'var(--color-info-bg, #EBF3FC)';
    badgeEl.style.color = 'var(--color-info, #1565C0)';
    badgeEl.style.border = '1px solid var(--color-info-border, #B7D7FA)';
  }

  openModal('logDetailModal');
}

function exportLogsToCSV() {
  const table = document.getElementById('eventLogsTable');
  if (!table) return;

  let csv = [];
  const rows = table.querySelectorAll('tr');
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const cols = row.querySelectorAll('th, td');
    let rowData = [];
    const limit = cols.length - 1;
    for (let j = 0; j < limit; j++) {
      let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
      rowData.push('"' + text + '"');
    }
    csv.push(rowData.join(','));
  }

  const csvContent = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv.join('\n'));
  const link = document.createElement('a');
  link.setAttribute('href', csvContent);
  link.setAttribute('download', 'barangay_event_logs_' + new Date().toISOString().slice(0, 10) + '.csv');
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
