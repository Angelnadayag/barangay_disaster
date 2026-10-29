<?php
// ============================================================================
// Views (ICDRRMO): System Audit Trail & Event Logs Module
// Immutable audit record of administrative, recommendation, and operational actions
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$filterModule = $_GET['module'] ?? '';
$filterRole = $_GET['role'] ?? '';
$search = trim($_GET['search'] ?? '');

if ($filterModule === 'Manage Resources' || $filterModule === 'Resources') {
    $pageTitle = "Resources Logs — ICDRRMO Admin";
    $pageHeaderTitle = "Resources Logs";
    $pageHeaderDesc = "Audit trail of resource fund allocations, calamity cash assistance, and disbursement events.";
} elseif ($filterModule === 'Supplies') {
    $pageTitle = "Supplies Logs — ICDRRMO Admin";
    $pageHeaderTitle = "Supplies Logs";
    $pageHeaderDesc = "Audit trail of commodity additions, stock adjustments, requisitions, reviews, and supply receipts.";
} elseif ($filterModule === 'Disaster Reports') {
    $pageTitle = "Disaster Reports Logs — ICDRRMO Admin";
    $pageHeaderTitle = "Disaster Reports Logs";
    $pageHeaderDesc = "Operational audit trail of incident creations, field assessments, responder updates, and status conclusions.";
} elseif ($filterModule === 'Manage User' || $filterModule === 'Users' || $filterModule === 'Residents') {
    $pageTitle = "Manage User Logs — ICDRRMO Admin";
    $pageHeaderTitle = "Manage User Logs";
    $pageHeaderDesc = "Operational audit trail of account registrations, profile modifications, status switches, role transitions, and account deletions.";
} else {
    $pageTitle = "System Audit Logs — ICDRRMO Admin";
    $pageHeaderTitle = "System Audit Logs";
    $pageHeaderDesc = "Operational audit trail recording authentication, recommendations, and administrative actions.";
}

if (!empty($customLogTitle)) {
    $pageHeaderTitle = $customLogTitle;
    $pageTitle = $customLogTitle . " — ICDRRMO Admin";
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'system-logs.php');
$isDisasterLogs = ($filterModule === 'Disaster Reports' || $currentPage === 'disaster-reports-logs.php');
$isUserLogs = ($filterModule === 'Manage User' || $filterModule === 'Users' || $filterModule === 'Residents' || $currentPage === 'manage-user-logs.php' || $currentPage === 'users-logs.php');
$isResourcesLogs = ($filterModule === 'Manage Resources' || $currentPage === 'resources-logs.php');
$isSuppliesLogs = ($filterModule === 'Supplies' || $currentPage === 'supplies-logs.php');

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$filterAction = trim($_GET['action_filter'] ?? '');

$sql = "SELECT * FROM system_logs WHERE 1=1";
$params = [];

if ($isResourcesLogs) {
    $sql .= " AND (module = 'Manage Resources' OR module = 'Financial Aid')";
} elseif ($isSuppliesLogs) {
    $sql .= " AND (module = 'Supplies' OR module = 'Manage Supplies' OR module = 'Resources')";
} elseif ($isDisasterLogs) {
    $sql .= " AND (module = 'Disaster Reports' OR module = 'Responder Field')";
} elseif ($isUserLogs) {
    $sql .= " AND (module = 'Users' OR module = 'Residents' OR module = 'Manage User' OR module = 'Manage Users' OR module = 'Auth')";
} elseif (!empty($filterModule)) {
    $sql .= " AND module = ?";
    $params[] = $filterModule;
}

if ($isDisasterLogs) {
    if ($filterAction === 'create' || $filterAction === 'new_reports') {
        $sql .= " AND action = 'CREATE_DISASTER_REQUEST'";
    } elseif ($filterAction === 'responder' || $filterAction === 'responder_updates') {
        $sql .= " AND (action = 'SUBMIT_FIELD_REPORT' OR action = 'FIELD_STATUS_TOGGLE' OR role = 'responder')";
    } elseif ($filterAction === 'reassessments' || $filterAction === 'edited') {
        $sql .= " AND (action = 'UPDATE_DISASTER_REQUEST' AND (role != 'responder' OR role IS NULL))";
    } elseif ($filterAction === 'status' || $filterAction === 'status_updates') {
        $sql .= " AND action = 'UPDATE_REQUEST_STATUS'";
    } elseif ($filterAction === 'ongoing') {
        $sql .= " AND (details LIKE '%Ongoing%' OR action = 'CREATE_DISASTER_REQUEST')";
    } elseif ($filterAction === 'completed') {
        $sql .= " AND (details LIKE '%Completed%' OR (action = 'UPDATE_REQUEST_STATUS' AND details LIKE '%Completed%'))";
    } elseif ($filterAction === 'deleted') {
        $sql .= " AND action = 'DELETE_DISASTER_REQUEST'";
    }
} elseif ($isUserLogs) {
    if ($filterAction === 'create' || $filterAction === 'new_users') {
        $sql .= " AND (action = 'CREATE_USER' OR action = 'CREATE_RESIDENT' OR action LIKE '%CREATE%')";
    } elseif ($filterAction === 'status' || $filterAction === 'status_changes') {
        $sql .= " AND (action = 'UPDATE_USER_STATUS' OR action = 'UPDATE_RESIDENT_STATUS' OR action LIKE '%STATUS%')";
    } elseif ($filterAction === 'edited' || $filterAction === 'updated') {
        $sql .= " AND (action = 'UPDATE_USER' OR action = 'UPDATE_RESIDENT' OR (action LIKE '%UPDATE%' AND action NOT LIKE '%STATUS%'))";
    } elseif ($filterAction === 'archived') {
        $sql .= " AND (action = 'ARCHIVE_USER' OR action = 'ARCHIVE_RESIDENT' OR action LIKE '%ARCHIVE%')";
    } elseif ($filterAction === 'restored') {
        $sql .= " AND (action = 'RESTORE_USER' OR action = 'RESTORE_RESIDENT' OR action LIKE '%RESTORE%')";
    } elseif ($filterAction === 'deleted' || $filterAction === 'purged') {
        $sql .= " AND (action = 'DELETE_USER_ARCHIVE' OR action = 'PURGE_USER' OR action LIKE '%DELETE%' OR action LIKE '%PURGE%')";
    } elseif ($filterAction === 'auth' || $filterAction === 'role_switch') {
        $sql .= " AND (action = 'DEMO_ROLE_SWITCH' OR action LIKE '%SWITCH%' OR module = 'Auth')";
    }
} else {
    if ($filterAction === 'received') {
        $sql .= " AND (action LIKE '%RECEIVED%' AND action NOT LIKE '%NOT_RECEIVED%')";
    } elseif ($filterAction === 'not_received') {
        $sql .= " AND (action LIKE '%NOT_RECEIVED%' OR action LIKE '%REJECT%' OR action LIKE '%VOID%')";
    } elseif ($filterAction === 'ayuda_release') {
        $sql .= " AND ((action LIKE '%AYUDA%' AND action NOT LIKE '%VOID%' AND action NOT LIKE '%NOT_RECEIVED%') OR action = 'RECEIVED_RESOURCE_AID')";
    } elseif ($filterAction === 'voided_ayuda') {
        $sql .= " AND ((action LIKE '%AYUDA%' AND (action LIKE '%VOID%' OR action LIKE '%NOT_RECEIVED%')) OR action = 'NOT_RECEIVED_RESOURCE_AID' OR (action LIKE '%VOID%' AND module = 'Manage Resources'))";
    } elseif ($filterAction === 'request') {
        $sql .= " AND action LIKE '%REQUEST%'";
    } elseif ($filterAction === 'create') {
        $sql .= " AND (action LIKE '%CREATE%' OR action LIKE '%CATALOG%')";
    } elseif ($filterAction === 'approved') {
        $sql .= " AND action LIKE '%APPROVE%'";
    } elseif ($filterAction === 'allocation') {
        $sql .= " AND action LIKE '%ALLOCAT%'";
    } elseif ($filterAction === 'edited') {
        $sql .= " AND (action LIKE '%UPDATE%' OR action LIKE '%EDIT%' OR action LIKE '%ADJUST%')";
    } elseif ($filterAction === 'archived') {
        $sql .= " AND (action LIKE '%ARCHIVE%' OR action LIKE '%RESTORE%')";
    } elseif ($filterAction === 'deleted') {
        $sql .= " AND action LIKE '%DELETE%'";
    }
}

if (!empty($filterRole)) {
    $sql .= " AND role = ?";
    $params[] = $filterRole;
}

if (!empty($search)) {
    $sql .= " AND (user_name LIKE ? OR action LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC LIMIT 300";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct modules for filter
$modules = $db->query("SELECT DISTINCT module FROM system_logs ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);
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
      <a href="<?= BASE_URL ?>/views/icdrrmo/event-logs.php" class="logs-tab-pill">
        Event Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="logs-tab-pill <?= (empty($filterModule) && $currentPage === 'system-logs.php') ? 'active' : '' ?>">
        All System Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/manage-user-logs.php" class="logs-tab-pill <?= ($isUserLogs) ? 'active' : '' ?>" title="View Manage User Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
        Manage User Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports-logs.php" class="logs-tab-pill <?= ($filterModule === 'Disaster Reports' || $currentPage === 'disaster-reports-logs.php') ? 'active' : '' ?>" title="View Disaster Reports Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        Disaster Reports Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/resources-logs.php" class="logs-tab-pill <?= ($filterModule === 'Manage Resources' || $filterModule === 'Resources' || $currentPage === 'resources-logs.php') ? 'active' : '' ?>" title="View Resources Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        Resources Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/supplies-logs.php" class="logs-tab-pill <?= ($filterModule === 'Supplies' || $currentPage === 'supplies-logs.php') ? 'active' : '' ?>" title="View Supplies Logs">
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
    </div>
  </div>

  <div class="page-header" style="margin-bottom: 14px;">
    <div class="page-header-title-wrap">
      <h1><?= clean($pageHeaderTitle) ?></h1>
      <p class="page-header-desc">
        <?= clean($pageHeaderDesc) ?>
      </p>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom: 14px;">
    <div class="card-body" style="padding: 10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
        <div class="search-input-wrap" style="flex:1; min-width:200px;">
          <input type="text" name="search" class="form-control" placeholder="<?= $isDisasterLogs ? 'Search tracking code, purok, hazard, user, status...' : ($isUserLogs ? 'Search username, full name, role, details...' : 'Search user, action, details...') ?>" value="<?= clean($search) ?>" style="font-family:'Roboto',sans-serif; font-size:10.5px; height:32px;">
        </div>

        <?php if ($currentPage === 'disaster-reports-logs.php'): ?>
          <input type="hidden" name="module" value="Disaster Reports">
        <?php elseif ($currentPage === 'manage-user-logs.php' || $currentPage === 'users-logs.php'): ?>
          <input type="hidden" name="module" value="Manage User">
        <?php elseif ($currentPage === 'resources-logs.php'): ?>
          <input type="hidden" name="module" value="Manage Resources">
        <?php elseif ($currentPage === 'supplies-logs.php'): ?>
          <input type="hidden" name="module" value="Supplies">
        <?php else: ?>
          <select name="module" class="form-control" style="width:160px; font-family:'Roboto',sans-serif; font-size:10.5px; height:32px;">
            <option value="">All Modules</option>
            <option value="Manage User" <?= ($filterModule === 'Manage User' || $filterModule === 'Users') ? 'selected' : '' ?>>Manage User</option>
            <option value="Disaster Reports" <?= $filterModule === 'Disaster Reports' ? 'selected' : '' ?>>Disaster Reports</option>
            <option value="Manage Resources" <?= $filterModule === 'Manage Resources' ? 'selected' : '' ?>>Manage Resources</option>
            <option value="Supplies" <?= $filterModule === 'Supplies' ? 'selected' : '' ?>>Supplies</option>
            <?php foreach ($modules as $m): ?>
              <?php if (!in_array($m, ['Manage User', 'Users', 'Residents', 'Disaster Reports', 'Manage Resources', 'Supplies', 'Financial Aid', 'Resources'], true)): ?>
                <option value="<?= clean($m) ?>" <?= $filterModule === $m ? 'selected' : '' ?>><?= clean($m) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <select name="role" class="form-control" style="width:140px; font-family:'Roboto',sans-serif; font-size:10.5px; height:32px;">
          <option value="">All Roles</option>
          <option value="icdrrmo" <?= $filterRole === 'icdrrmo' ? 'selected' : '' ?>>ICDRRMO Admin</option>
          <option value="barangay_head" <?= $filterRole === 'barangay_head' ? 'selected' : '' ?>>Barangay Head</option>
          <option value="responder" <?= $filterRole === 'responder' ? 'selected' : '' ?>>Responder</option>
          <option value="resident" <?= $filterRole === 'resident' ? 'selected' : '' ?>>Resident</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm" style="font-family:'Poppins',sans-serif; font-size:10.5px; padding:5px 12px; height:32px;">
          Filter
        </button>
        <?php if (!empty($search) || !empty($filterRole) || !empty($filterAction) || (!empty($filterModule) && $currentPage === 'system-logs.php')): ?>
          <?php 
            $subPages = ['disaster-reports-logs.php', 'manage-user-logs.php', 'users-logs.php', 'resources-logs.php', 'supplies-logs.php'];
            $resetFile = in_array($currentPage, $subPages, true) ? $currentPage : 'system-logs.php';
          ?>
          <a href="<?= BASE_URL ?>/views/icdrrmo/<?= $resetFile ?>" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif; font-size:10.5px; padding:5px 10px; height:32px;">
            Reset
          </a>
        <?php endif; ?>

        <div style="margin-left:auto;">
          <span style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-muted);">
            <?= count($logs) ?> entries
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Action Quick Filters -->
  <?php
    $baseUrlWithParams = function($actFilter) use ($filterModule, $filterRole, $search, $currentPage) {
        $q = [];
        if (!empty($filterModule) && !in_array($currentPage, ['resources-logs.php', 'supplies-logs.php', 'disaster-reports-logs.php', 'manage-user-logs.php', 'users-logs.php'], true)) {
            $q['module'] = $filterModule;
        }
        if (!empty($filterRole)) $q['role'] = $filterRole;
        if (!empty($search)) $q['search'] = $search;
        if (!empty($actFilter)) $q['action_filter'] = $actFilter;
        $file = in_array($currentPage, ['resources-logs.php', 'supplies-logs.php', 'disaster-reports-logs.php', 'manage-user-logs.php', 'users-logs.php'], true) ? $currentPage : 'system-logs.php';
        return BASE_URL . "/views/icdrrmo/" . $file . (!empty($q) ? '?' . http_build_query($q) : '');
    };
  ?>
  <div style="display:flex; gap:6px; margin-bottom: 14px; flex-wrap:wrap; align-items:center;">
    <span style="font-family:'Poppins',sans-serif; font-size:10px; font-weight:600; color:var(--color-text-secondary); text-transform:uppercase; margin-right:4px;">
      <?= $isDisasterLogs ? 'Incident Action Filter:' : ($isUserLogs ? 'User Action Filter:' : 'Filter Action:') ?>
    </span>
    <a href="<?= $baseUrlWithParams('') ?>" class="btn btn-sm <?= empty($filterAction) ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
      <?= $isDisasterLogs ? 'All Incident Logs' : ($isUserLogs ? 'All User Logs' : 'All Actions') ?>
    </a>
    <?php if ($isDisasterLogs): ?>
      <a href="<?= $baseUrlWithParams('create') ?>" class="btn btn-sm <?= ($filterAction === 'create' || $filterAction === 'new_reports') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        New Reports Filed
      </a>
      <a href="<?= $baseUrlWithParams('responder') ?>" class="btn btn-sm <?= ($filterAction === 'responder' || $filterAction === 'responder_updates') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#93C5FD; color:<?= ($filterAction === 'responder' || $filterAction === 'responder_updates') ? '#fff' : '#1D4ED8' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polygon points="12 2 19 21 12 17 5 21 12 2"></polygon></svg>
        Responder Updates
      </a>
      <a href="<?= $baseUrlWithParams('reassessments') ?>" class="btn btn-sm <?= ($filterAction === 'reassessments' || $filterAction === 'edited') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#BAE6FD; color:<?= ($filterAction === 'reassessments' || $filterAction === 'edited') ? '#fff' : '#0369A1' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Reassessments
      </a>
      <a href="<?= $baseUrlWithParams('status') ?>" class="btn btn-sm <?= ($filterAction === 'status' || $filterAction === 'status_updates') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#A7F3D0; color:<?= ($filterAction === 'status' || $filterAction === 'status_updates') ? '#fff' : '#047857' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        Status Conclusions
      </a>
      <a href="<?= $baseUrlWithParams('ongoing') ?>" class="btn btn-sm <?= $filterAction === 'ongoing' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDE68A; color:<?= $filterAction === 'ongoing' ? '#fff' : '#B45309' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
        Ongoing Incidents
      </a>
      <a href="<?= $baseUrlWithParams('completed') ?>" class="btn btn-sm <?= $filterAction === 'completed' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#86EFAC; color:<?= $filterAction === 'completed' ? '#fff' : '#15803D' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
        Completed Incidents
      </a>
      <a href="<?= $baseUrlWithParams('deleted') ?>" class="btn btn-sm <?= $filterAction === 'deleted' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDA4AF; color:<?= $filterAction === 'deleted' ? '#fff' : '#BE123C' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        Deleted
      </a>
    <?php elseif ($isUserLogs): ?>
      <a href="<?= $baseUrlWithParams('create') ?>" class="btn btn-sm <?= ($filterAction === 'create' || $filterAction === 'new_users') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
        Created / Registered
      </a>
      <a href="<?= $baseUrlWithParams('edited') ?>" class="btn btn-sm <?= ($filterAction === 'edited' || $filterAction === 'updated') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#BAE6FD; color:<?= ($filterAction === 'edited' || $filterAction === 'updated') ? '#fff' : '#0369A1' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Profile Updates
      </a>
      <a href="<?= $baseUrlWithParams('status') ?>" class="btn btn-sm <?= ($filterAction === 'status' || $filterAction === 'status_changes') ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#A7F3D0; color:<?= ($filterAction === 'status' || $filterAction === 'status_changes') ? '#fff' : '#047857' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
        Status Changes
      </a>
      <a href="<?= $baseUrlWithParams('archived') ?>" class="btn btn-sm <?= $filterAction === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#CBD5E1; color:<?= $filterAction === 'archived' ? '#fff' : '#475569' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
        Archived Users
      </a>
      <a href="<?= $baseUrlWithParams('restored') ?>" class="btn btn-sm <?= $filterAction === 'restored' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#86EFAC; color:<?= $filterAction === 'restored' ? '#fff' : '#15803D' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
        Restored Users
      </a>
      <a href="<?= $baseUrlWithParams('deleted') ?>" class="btn btn-sm <?= $filterAction === 'deleted' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDA4AF; color:<?= $filterAction === 'deleted' ? '#fff' : '#BE123C' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        Deleted / Purged
      </a>
      <a href="<?= $baseUrlWithParams('auth') ?>" class="btn btn-sm <?= $filterAction === 'auth' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#DDD6FE; color:<?= $filterAction === 'auth' ? '#fff' : '#6D28D9' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path></svg>
        Role Switches / Auth
      </a>
    <?php elseif ($isResourcesLogs): ?>
      <a href="<?= $baseUrlWithParams('ayuda_release') ?>" class="btn btn-sm <?= $filterAction === 'ayuda_release' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#86EFAC; color:<?= $filterAction === 'ayuda_release' ? '#fff' : '#15803D' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
        Ayuda Released
      </a>
      <a href="<?= $baseUrlWithParams('voided_ayuda') ?>" class="btn btn-sm <?= $filterAction === 'voided_ayuda' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FCA5A5; color:<?= $filterAction === 'voided_ayuda' ? '#fff' : '#B91C1C' ?>;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-right:2px;"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
        Voided Ayuda
      </a>
      <a href="<?= $baseUrlWithParams('allocation') ?>" class="btn btn-sm <?= $filterAction === 'allocation' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Allocations
      </a>
      <a href="<?= $baseUrlWithParams('create') ?>" class="btn btn-sm <?= $filterAction === 'create' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Creation / Plans
      </a>
      <a href="<?= $baseUrlWithParams('request') ?>" class="btn btn-sm <?= $filterAction === 'request' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Beneficiaries
      </a>
      <a href="<?= $baseUrlWithParams('edited') ?>" class="btn btn-sm <?= $filterAction === 'edited' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#BAE6FD; color:<?= $filterAction === 'edited' ? '#fff' : '#0369A1' ?>;">
        Edited
      </a>
      <a href="<?= $baseUrlWithParams('archived') ?>" class="btn btn-sm <?= $filterAction === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#CBD5E1; color:<?= $filterAction === 'archived' ? '#fff' : '#475569' ?>;">
        Archived
      </a>
      <a href="<?= $baseUrlWithParams('deleted') ?>" class="btn btn-sm <?= $filterAction === 'deleted' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDA4AF; color:<?= $filterAction === 'deleted' ? '#fff' : '#BE123C' ?>;">
        Deleted
      </a>
    <?php elseif ($isSuppliesLogs): ?>
      <a href="<?= $baseUrlWithParams('create') ?>" class="btn btn-sm <?= $filterAction === 'create' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Commodity Catalog
      </a>
      <a href="<?= $baseUrlWithParams('request') ?>" class="btn btn-sm <?= $filterAction === 'request' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Requisitions
      </a>
      <a href="<?= $baseUrlWithParams('approved') ?>" class="btn btn-sm <?= $filterAction === 'approved' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Approved
      </a>
      <a href="<?= $baseUrlWithParams('received') ?>" class="btn btn-sm <?= $filterAction === 'received' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#86EFAC; color:<?= $filterAction === 'received' ? '#fff' : '#15803D' ?>;">
        Delivery Received
      </a>
      <a href="<?= $baseUrlWithParams('not_received') ?>" class="btn btn-sm <?= $filterAction === 'not_received' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FCA5A5; color:<?= $filterAction === 'not_received' ? '#fff' : '#B91C1C' ?>;">
        Not Received
      </a>
      <a href="<?= $baseUrlWithParams('edited') ?>" class="btn btn-sm <?= $filterAction === 'edited' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#BAE6FD; color:<?= $filterAction === 'edited' ? '#fff' : '#0369A1' ?>;">
        Stock Adjustments
      </a>
      <a href="<?= $baseUrlWithParams('archived') ?>" class="btn btn-sm <?= $filterAction === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#CBD5E1; color:<?= $filterAction === 'archived' ? '#fff' : '#475569' ?>;">
        Archived
      </a>
      <a href="<?= $baseUrlWithParams('deleted') ?>" class="btn btn-sm <?= $filterAction === 'deleted' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDA4AF; color:<?= $filterAction === 'deleted' ? '#fff' : '#BE123C' ?>;">
        Deleted
      </a>
    <?php else: ?>
      <a href="<?= $baseUrlWithParams('create') ?>" class="btn btn-sm <?= $filterAction === 'create' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Creation
      </a>
      <a href="<?= $baseUrlWithParams('request') ?>" class="btn btn-sm <?= $filterAction === 'request' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Requests
      </a>
      <a href="<?= $baseUrlWithParams('approved') ?>" class="btn btn-sm <?= $filterAction === 'approved' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Approved
      </a>
      <a href="<?= $baseUrlWithParams('received') ?>" class="btn btn-sm <?= $filterAction === 'received' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#86EFAC; color:<?= $filterAction === 'received' ? '#fff' : '#15803D' ?>;">
        Received / Ayuda
      </a>
      <a href="<?= $baseUrlWithParams('not_received') ?>" class="btn btn-sm <?= $filterAction === 'not_received' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FCA5A5; color:<?= $filterAction === 'not_received' ? '#fff' : '#B91C1C' ?>;">
        Not Received / Voided
      </a>
      <a href="<?= $baseUrlWithParams('allocation') ?>" class="btn btn-sm <?= $filterAction === 'allocation' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px;">
        Allocations
      </a>
      <a href="<?= $baseUrlWithParams('edited') ?>" class="btn btn-sm <?= $filterAction === 'edited' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#BAE6FD; color:<?= $filterAction === 'edited' ? '#fff' : '#0369A1' ?>;">
        Edited
      </a>
      <a href="<?= $baseUrlWithParams('archived') ?>" class="btn btn-sm <?= $filterAction === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#CBD5E1; color:<?= $filterAction === 'archived' ? '#fff' : '#475569' ?>;">
        Archived
      </a>
      <a href="<?= $baseUrlWithParams('deleted') ?>" class="btn btn-sm <?= $filterAction === 'deleted' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:10px; padding:3px 9px; border-color:#FDA4AF; color:<?= $filterAction === 'deleted' ? '#fff' : '#BE123C' ?>;">
        Deleted
      </a>
    <?php endif; ?>
  </div>

  <!-- Logs Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th style="min-width:130px;">Timestamp</th>
            <th style="min-width:140px;">User</th>
            <th style="min-width:110px;">Role</th>
            <th style="min-width:110px;">Module</th>
            <th style="min-width:120px;">Action Code</th>
            <th>Details</th>
            <th style="min-width:90px;">IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:32px 16px;">
                <div style="font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; color:var(--color-text);">No logs found</div>
                <div style="font-family:'Roboto',sans-serif; font-size:10.5px; color:var(--color-text-secondary); margin-top:2px;">No system audit events match your filter criteria.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-muted); white-space:nowrap;">
                  <?= formatDate($log['created_at'], 'Y-m-d H:i:s') ?>
                </td>
                <td style="font-family:'Roboto',sans-serif; font-weight:600; font-size:10.5px; color:var(--color-primary);">
                  <?= clean($log['user_name']) ?>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-family:'Poppins',sans-serif; font-size:9px; padding:2px 6px;">
                    <?= clean(formatRoleName($log['role'])) ?>
                  </span>
                </td>
                <td style="font-family:'Roboto',sans-serif; font-size:10.5px;">
                  <?php if ($log['module'] === 'Manage Resources' || $log['module'] === 'Financial Aid'): ?>
                    <span class="badge" style="background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; font-size:9.5px; padding:2px 7px; font-weight:600; display:inline-flex; align-items:center; gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                      Manage Resources
                    </span>
                  <?php elseif ($log['module'] === 'Supplies' || $log['module'] === 'Resources'): ?>
                    <span class="badge" style="background:#EBF5FF; color:#1E429F; border:1px solid #C3DDFD; font-size:9.5px; padding:2px 7px; font-weight:600; display:inline-flex; align-items:center; gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                      Supplies
                    </span>
                  <?php elseif ($log['module'] === 'Disaster Reports' || $log['module'] === 'Responder Field'): ?>
                    <span class="badge" style="background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; font-size:9.5px; padding:2px 7px; font-weight:600; display:inline-flex; align-items:center; gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                      Disaster Reports
                    </span>
                  <?php elseif ($log['module'] === 'Users' || $log['module'] === 'Residents' || $log['module'] === 'Manage User' || $log['module'] === 'Manage Users' || $log['module'] === 'Auth'): ?>
                    <span class="badge" style="background:#EDE9FE; color:#5B21B6; border:1px solid #DDD6FE; font-size:9.5px; padding:2px 7px; font-weight:600; display:inline-flex; align-items:center; gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                      Manage User
                    </span>
                  <?php else: ?>
                    <span style="font-weight:500; color:var(--color-secondary);"><?= clean($log['module']) ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-family:'Roboto',sans-serif; font-weight:600; font-size:10px; white-space:nowrap;">
                  <?php
                    $act = strtoupper($log['action']);
                    if ($act === 'CREATE_DISASTER_REQUEST') {
                        echo '<span class="badge" style="background:#DBEAFE; color:#1D4ED8; border:1px solid #93C5FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                NEW REPORT
                              </span>';
                    } elseif ($act === 'SUBMIT_FIELD_REPORT' || $act === 'FIELD_STATUS_TOGGLE') {
                        echo '<span class="badge" style="background:#E0E7FF; color:#4338CA; border:1px solid #C7D2FE; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 19 21 12 17 5 21 12 2"></polygon></svg>
                                FIELD UPDATE
                              </span>';
                    } elseif ($act === 'UPDATE_REQUEST_STATUS') {
                        echo '<span class="badge" style="background:#DCFCE7; color:#15803D; border:1px solid #86EFAC; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                STATUS UPDATE
                              </span>';
                    } elseif ($act === 'UPDATE_DISASTER_REQUEST') {
                        echo '<span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                REASSESSMENT
                              </span>';
                    } elseif ($act === 'DELETE_DISASTER_REQUEST') {
                        echo '<span class="badge" style="background:#FFE4E6; color:#BE123C; border:1px solid #FDA4AF; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                DELETED
                              </span>';
                    } elseif ($act === 'CREATE_USER' || $act === 'CREATE_RESIDENT') {
                        echo '<span class="badge" style="background:#DBEAFE; color:#1D4ED8; border:1px solid #93C5FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                                USER CREATED
                              </span>';
                    } elseif ($act === 'UPDATE_USER_STATUS' || $act === 'UPDATE_RESIDENT_STATUS') {
                        echo '<span class="badge" style="background:#FEF3C7; color:#B45309; border:1px solid #FCD34D; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                STATUS CHANGED
                              </span>';
                    } elseif ($act === 'UPDATE_USER' || $act === 'UPDATE_RESIDENT') {
                        echo '<span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                USER UPDATED
                              </span>';
                    } elseif ($act === 'ARCHIVE_USER' || $act === 'ARCHIVE_RESIDENT') {
                        echo '<span class="badge" style="background:#F1F5F9; color:#475569; border:1px solid #CBD5E1; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                                USER ARCHIVED
                              </span>';
                    } elseif ($act === 'RESTORE_USER' || $act === 'RESTORE_RESIDENT') {
                        echo '<span class="badge" style="background:#D1FAE5; color:#047857; border:1px solid #6EE7B7; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                USER RESTORED
                              </span>';
                    } elseif ($act === 'DELETE_USER_ARCHIVE' || $act === 'PURGE_USER') {
                        echo '<span class="badge" style="background:#FFE4E6; color:#BE123C; border:1px solid #FDA4AF; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                PURGED / DELETED
                              </span>';
                    } elseif ($act === 'DEMO_ROLE_SWITCH') {
                        echo '<span class="badge" style="background:#EDE9FE; color:#6D28D9; border:1px solid #DDD6FE; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path></svg>
                                ROLE SWITCH
                              </span>';
                    } elseif (strpos($act, 'AYUDA') !== false && (strpos($act, 'VOID') !== false || strpos($act, 'NOT_RECEIVED') !== false)) {
                        echo '<span class="badge" style="background:#FEE2E2; color:#B91C1C; border:1px solid #FCA5A5; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                                VOIDED AYUDA
                              </span>';
                    } elseif (strpos($act, 'AYUDA') !== false || $act === 'RECEIVED_RESOURCE_AID') {
                        echo '<span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #6EE7B7; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                RELEASE AYUDA
                              </span>';
                    } elseif (strpos($act, 'RECEIVED') !== false && strpos($act, 'NOT_RECEIVED') === false) {
                        echo '<span class="badge" style="background:#DCFCE7; color:#15803D; border:1px solid #86EFAC; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                RECEIVED
                              </span>';
                    } elseif (strpos($act, 'NOT_RECEIVED') !== false) {
                        echo '<span class="badge" style="background:#FEE2E2; color:#B91C1C; border:1px solid #FCA5A5; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                NOT RECEIVED
                              </span>';
                    } elseif (strpos($act, 'REQUEST') !== false) {
                        echo '<span class="badge" style="background:#FEF3C7; color:#B45309; border:1px solid #FCD34D; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                REQUEST
                              </span>';
                    } elseif (strpos($act, 'CREATE') !== false || strpos($act, 'CATALOG') !== false) {
                        echo '<span class="badge" style="background:#DBEAFE; color:#1D4ED8; border:1px solid #93C5FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                CREATION
                              </span>';
                    } elseif (strpos($act, 'APPROVE') !== false) {
                        echo '<span class="badge" style="background:#CCFBF1; color:#0F766E; border:1px solid #5EEAD4; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                APPROVED
                              </span>';
                    } elseif (strpos($act, 'REJECT') !== false || strpos($act, 'DECLINE') !== false) {
                        echo '<span class="badge" style="background:#FFEDD5; color:#C2410C; border:1px solid #FDBA74; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                DECLINED
                              </span>';
                    } elseif (strpos($act, 'ALLOCAT') !== false) {
                        echo '<span class="badge" style="background:#F3E8FF; color:#7E22CE; border:1px solid #D8B4FE; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                                ALLOCATION
                              </span>';
                    } elseif (strpos($act, 'ADJUST') !== false || strpos($act, 'RESTOCK') !== false) {
                        echo '<span class="badge" style="background:#E0E7FF; color:#4338CA; border:1px solid #A5B4FC; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                                STOCK ADJUST
                              </span>';
                    } elseif (strpos($act, 'ARCHIVE') !== false) {
                        echo '<span class="badge" style="background:#F1F5F9; color:#475569; border:1px solid #CBD5E1; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                                ARCHIVED
                              </span>';
                    } elseif (strpos($act, 'RESTORE') !== false) {
                        echo '<span class="badge" style="background:#D1FAE5; color:#047857; border:1px solid #6EE7B7; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                RESTORED
                              </span>';
                    } elseif (strpos($act, 'DELETE') !== false) {
                        echo '<span class="badge" style="background:#FFE4E6; color:#BE123C; border:1px solid #FDA4AF; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                DELETED
                              </span>';
                    } elseif (strpos($act, 'UPDATE') !== false || strpos($act, 'EDIT') !== false) {
                        echo '<span class="badge" style="background:#E0F2FE; color:#0369A1; border:1px solid #BAE6FD; font-weight:700; font-size:9.5px; padding:2px 7px; display:inline-flex; align-items:center; gap:3px;">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                EDITED
                              </span>';
                    } else {
                        echo clean($log['action']);
                    }
                  ?>
                </td>
                <td style="font-family:'Roboto',sans-serif; font-size:10.5px; color:var(--color-text); line-height:1.4;">
                  <?= clean($log['details']) ?>
                </td>
                <td style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-muted); white-space:nowrap;">
                  <?= clean($log['ip_address']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
