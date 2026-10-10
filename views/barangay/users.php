<?php
// ============================================================================
// Views (Barangay Head): Consolidated User Management Module
// Unified management of Barangay Responders, Personnel, and Citizen Residents.
// Seamless tab switching (Responders, Residents, Archived), In-Place Edit/View,
// Tactical Readiness Tracking, Connected Agency integrations, and Archival/Restore.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$currentUserId = (int)$user['id'];
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    die("Barangay record not found.");
}

// Active Tab: 'responders', 'residents', or 'archived'
$activeTab = $_GET['tab'] ?? 'responders';
if (!in_array($activeTab, ['responders', 'residents', 'archived'], true)) {
    $activeTab = 'responders';
}

$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterAvailability = trim($_GET['availability'] ?? '');
$filterAgency = !empty($_GET['agency_id']) ? (int)$_GET['agency_id'] : 0;
$filterPurok = trim($_GET['purok'] ?? '');
$filterArchivedType = trim($_GET['type'] ?? ''); // 'all', 'responder', 'resident'

// Counts for navigation tabs
$activeRespondersCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'responder' AND barangay_id = {$barangayId} AND status != 'archived'")->fetchColumn();
$activeResidentsCount = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id = {$barangayId} AND status != 'archived'")->fetchColumn();
$archivedRespondersCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'responder' AND barangay_id = {$barangayId} AND status = 'archived'")->fetchColumn();
$archivedResidentsCount = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id = {$barangayId} AND status = 'archived'")->fetchColumn();
$totalArchivedCount = $archivedRespondersCount + $archivedResidentsCount;
$totalActiveUsers = $activeRespondersCount + $activeResidentsCount;

// Fetch active agencies for responder selection & filters
$agencyStmt = $db->prepare("
    SELECT id, name, agency_type, barangay_id 
    FROM agencies 
    WHERE status = 'active'
    ORDER BY (barangay_id = ?) DESC, agency_type DESC, name ASC
");
$agencyStmt->execute([$barangayId]);
$availableAgencies = $agencyStmt->fetchAll(PDO::FETCH_ASSOC);

$defaultAgencyId = null;
foreach ($availableAgencies as $ag) {
    if ((int)$ag['barangay_id'] === $barangayId) {
        $defaultAgencyId = (int)$ag['id'];
        break;
    }
}
if (!$defaultAgencyId && !empty($availableAgencies)) {
    $defaultAgencyId = (int)$availableAgencies[0]['id'];
}

// Fetch local puroks for resident dropdowns & filters
$purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Responder Readiness KPIs
$availCountsStmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN availability = 'Available' THEN 1 ELSE 0 END) AS count_available,
        SUM(CASE WHEN availability = 'On Duty' THEN 1 ELSE 0 END) AS count_onduty,
        SUM(CASE WHEN availability = 'Responding' THEN 1 ELSE 0 END) AS count_responding,
        SUM(CASE WHEN availability IN ('Standby', 'Off Duty') THEN 1 ELSE 0 END) AS count_standby
    FROM users 
    WHERE role = 'responder' AND barangay_id = ? AND status != 'archived'
");
$availCountsStmt->execute([$barangayId]);
$availKpis = $availCountsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'count_available' => 0, 'count_onduty' => 0, 'count_responding' => 0, 'count_standby' => 0
];

// Query Data based on Active Tab
$respondersList = [];
$residentsList = [];
$archivedList = [];

if ($activeTab === 'responders') {
    $sql = "
        SELECT u.*, b.name AS barangay_name, a.name AS agency_name, a.agency_type, a.contact_number AS agency_phone
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.id 
        LEFT JOIN agencies a ON u.agency_id = a.id
        WHERE u.role = 'responder' AND u.barangay_id = ? AND u.status != 'archived'
    ";
    $params = [$barangayId];

    if (!empty($filterStatus)) {
        $sql .= " AND u.status = ?";
        $params[] = $filterStatus;
    }
    if (!empty($filterAvailability)) {
        $sql .= " AND u.availability = ?";
        $params[] = $filterAvailability;
    }
    if (!empty($filterAgency)) {
        $sql .= " AND u.agency_id = ?";
        $params[] = $filterAgency;
    }
    if (!empty($search)) {
        $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.position LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }
    $sql .= " ORDER BY FIELD(u.status, 'active', 'pending', 'inactive'), FIELD(u.availability, 'Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'), u.first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $respondersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($activeTab === 'residents') {
    $sql = "
        SELECT r.*, b.name AS barangay_name 
        FROM residents r 
        LEFT JOIN barangays b ON r.barangay_id = b.id 
        WHERE r.barangay_id = ? AND r.status != 'archived'
    ";
    $params = [$barangayId];

    if (!empty($filterStatus)) {
        $sql .= " AND r.status = ?";
        $params[] = $filterStatus;
    }
    if (!empty($filterPurok)) {
        $sql .= " AND r.purok = ?";
        $params[] = $filterPurok;
    }
    if (!empty($search)) {
        $sql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR r.username LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    $sql .= " ORDER BY FIELD(r.status, 'active', 'pending', 'inactive'), r.first_name ASC, r.last_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $residentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($activeTab === 'archived') {
    // Combine archived responders and residents
    $archivedUsers = [];
    if (empty($filterArchivedType) || $filterArchivedType === 'responder') {
        $uSql = "
            SELECT u.id, u.username, u.first_name, u.last_name, u.full_name, u.email, u.phone, u.gender, u.age,
                   u.status, u.created_at, u.position, 'responder' AS user_type, a.name AS agency_name, NULL AS purok
            FROM users u
            LEFT JOIN agencies a ON u.agency_id = a.id
            WHERE u.role = 'responder' AND u.barangay_id = ? AND u.status = 'archived'
        ";
        $uParams = [$barangayId];
        if (!empty($search)) {
            $uSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            $like = "%$search%";
            $uParams = array_merge($uParams, [$like, $like, $like, $like, $like]);
        }
        $uStmt = $db->prepare($uSql);
        $uStmt->execute($uParams);
        $archivedUsers = array_merge($archivedUsers, $uStmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if (empty($filterArchivedType) || $filterArchivedType === 'resident') {
        $rSql = "
            SELECT r.id, r.username, r.first_name, r.last_name, r.full_name, r.email, r.phone, r.gender, r.age,
                   r.status, r.created_at, NULL AS position, 'resident' AS user_type, NULL AS agency_name, r.purok
            FROM residents r
            WHERE r.barangay_id = ? AND r.status = 'archived'
        ";
        $rParams = [$barangayId];
        if (!empty($search)) {
            $rSql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR r.username LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)";
            $like = "%$search%";
            $rParams = array_merge($rParams, [$like, $like, $like, $like, $like]);
        }
        $rStmt = $db->prepare($rSql);
        $rStmt->execute($rParams);
        $archivedUsers = array_merge($archivedUsers, $rStmt->fetchAll(PDO::FETCH_ASSOC));
    }

    usort($archivedUsers, function($a, $b) {
        return strcmp($a['first_name'] . ' ' . $a['last_name'], $b['first_name'] . ' ' . $b['last_name']);
    });
    $archivedList = $archivedUsers;
}

$pageTitle = "Manage User — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<style>
/* High-clarity disabled inputs for View Mode */
.form-control:disabled,
select.form-control:disabled {
  background-color: var(--color-surface-subtle, #F0F3F4) !important;
  color: var(--color-text, #24313A) !important;
  border-color: var(--color-border-light, #E7EDF0) !important;
  cursor: default !important;
  opacity: 0.96 !important;
  -webkit-text-fill-color: var(--color-text, #24313A) !important;
}

.table-user-link {
  text-decoration: none;
  display: block;
}
.table-user-link:hover .table-user-name {
  color: var(--color-secondary, #2F6F73);
  text-decoration: underline;
}

/* Modal layout */
#viewUserModal.modal-overlay,
#createUserModal.modal-overlay {
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

#viewUserModal.modal-overlay.active,
#createUserModal.modal-overlay.active {
  display: flex !important;
}

#viewUserModal .modal-dialog,
#createUserModal .modal-dialog {
  max-width: 660px !important;
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

#viewUserModal .modal-header,
#createUserModal .modal-header {
  flex-shrink: 0 !important;
  padding: 12px 18px !important;
  border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
  background-color: var(--color-surface, #FFFFFF) !important;
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
}

#viewUserModal .modal-body,
#createUserModal .modal-body {
  flex: 1 1 auto !important;
  padding: 18px 20px !important;
  overflow-y: auto !important;
  overscroll-behavior: contain !important;
}

#viewUserModal .modal-footer,
#createUserModal .modal-footer {
  flex-shrink: 0 !important;
  padding: 12px 20px !important;
  border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
  background-color: #FAFCFC !important;
}

/* Tab Navigation */
.nav-tabs {
  display: flex;
  gap: 6px;
  border-bottom: 1px solid var(--color-border);
  margin-bottom: var(--space-4);
}
.nav-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 11px;
  font-weight: 600;
  text-decoration: none;
  color: var(--color-text-secondary);
  border-bottom: 2px solid transparent;
  transition: all 0.15s ease;
}
.nav-tab-item:hover {
  color: var(--color-primary);
  background-color: var(--color-surface-subtle);
}
.nav-tab-item.active {
  color: var(--color-primary);
  border-bottom-color: var(--color-secondary);
  background-color: var(--color-surface);
}
.nav-tab-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 9.5px;
  font-weight: 700;
  background-color: var(--color-surface-subtle);
  color: var(--color-text-muted);
}
.nav-tab-item.active .nav-tab-badge {
  background-color: var(--color-primary);
  color: #FFF;
}

/* KPI Banner */
.readiness-kpi-bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
  margin-bottom: var(--space-4);
}
.kpi-pill {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-pill:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(0,0,0,0.05);
}
.kpi-pill-title {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-text-muted);
  letter-spacing: 0.4px;
  margin-bottom: 4px;
}
.kpi-pill-val {
  font-family: var(--font-secondary);
  font-size: 18px;
  font-weight: 800;
  color: var(--color-primary);
}
.kpi-pill-val.green { color: #16A34A; }
.kpi-pill-val.blue { color: #0284C7; }
.kpi-pill-val.amber { color: #D97706; }
.kpi-pill-val.purple { color: #7C3AED; }

/* Role selector pill toggle in create modal */
.role-toggle-group {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  background: var(--color-surface-subtle);
  padding: 4px;
  border-radius: 8px;
  border: 1px solid var(--color-border-light);
}
.role-toggle-btn {
  flex: 1;
  text-align: center;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  background: transparent;
  color: var(--color-text-secondary);
  transition: all 0.2s ease;
}
.role-toggle-btn.active {
  background: var(--color-surface);
  color: var(--color-primary);
  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Manage User — Barangay <?= clean($barangay['name'] ?? '') ?></h1>
    <p class="page-header-desc">
      Consolidated registry for Barangay Responders, field personnel, and registered citizen residents.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openCreateUserModal('<?= $activeTab === 'residents' ? 'resident' : 'responder' ?>')">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      Register New User
    </button>
  </div>
</div>

<!-- Tabs Navigation -->
<div class="nav-tabs">
  <a href="?tab=responders" class="nav-tab-item <?= $activeTab === 'responders' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
    Responders Force
    <span class="nav-tab-badge"><?= $activeRespondersCount ?></span>
  </a>
  <a href="?tab=residents" class="nav-tab-item <?= $activeTab === 'residents' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
    Resident Citizens
    <span class="nav-tab-badge"><?= $activeResidentsCount ?></span>
  </a>
  <a href="?tab=archived" class="nav-tab-item <?= $activeTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
    Archived Accounts
    <span class="nav-tab-badge"><?= $totalArchivedCount ?></span>
  </a>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: RESPONDERS FORCE                                                   -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'responders'): ?>

  <!-- Tactical Readiness KPI Bar -->
  <div class="readiness-kpi-bar">
    <div class="kpi-pill">
      <div class="kpi-pill-title">Available for Callout</div>
      <div class="kpi-pill-val green"><?= (int)($availKpis['count_available'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">On Duty</div>
      <div class="kpi-pill-val blue"><?= (int)($availKpis['count_onduty'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Actively Responding</div>
      <div class="kpi-pill-val amber"><?= (int)($availKpis['count_responding'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Standby / Off Duty</div>
      <div class="kpi-pill-val purple"><?= (int)($availKpis['count_standby'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Total Responders</div>
      <div class="kpi-pill-val"><?= $activeRespondersCount ?></div>
    </div>
  </div>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="responders">

        <div style="flex:2;min-width:180px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Responder</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone, position..." value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:130px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Tactical Availability</label>
          <select name="availability" class="form-control">
            <option value="">All Availabilities</option>
            <option value="Available" <?= $filterAvailability === 'Available' ? 'selected' : '' ?>>Available</option>
            <option value="On Duty" <?= $filterAvailability === 'On Duty' ? 'selected' : '' ?>>On Duty</option>
            <option value="Responding" <?= $filterAvailability === 'Responding' ? 'selected' : '' ?>>Responding</option>
            <option value="Standby" <?= $filterAvailability === 'Standby' ? 'selected' : '' ?>>Standby</option>
            <option value="Off Duty" <?= $filterAvailability === 'Off Duty' ? 'selected' : '' ?>>Off Duty</option>
          </select>
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Connected Agency</label>
          <select name="agency_id" class="form-control">
            <option value="">All Connected Agencies</option>
            <?php foreach ($availableAgencies as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= $filterAgency === (int)$ag['id'] ? 'selected' : '' ?>>
                <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:1;min-width:110px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Account Status</label>
          <select name="status" class="form-control">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        </div>

        <div style="display:flex;gap:6px;">
          <button type="submit" class="btn btn-primary" style="height:36px;">Filter</button>
          <a href="?tab=responders" class="btn btn-outline" style="height:36px;">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Responders Data Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Active Responder Personnel (<?= count($respondersList) ?>)</h3>
      <span style="font-size:10px;color:var(--color-text-muted);">Jurisdiction: Brgy. <?= clean($barangay['name']) ?></span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Responder Name</th>
            <th>Role & Position</th>
            <th>Connected Agency</th>
            <th>Tactical Availability</th>
            <th>Status</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($respondersList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">No Responders Found</div>
                <div style="font-size:11px;">No active responder accounts match your current filters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($respondersList as $r): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>, 'responder')" class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                      <?= clean($r['first_name'] . ' ' . $r['last_name']) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($r['username']) ?> &bull; <?= clean($r['phone'] ?: 'No Phone') ?></div>
                  </a>
                </td>
                <td>
                  <span style="font-weight:600;color:var(--color-text);font-size:11px;"><?= clean($r['position'] ?: 'Responder') ?></span>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($r['gender'] ?: 'Unspecified') ?>, <?= $r['age'] ? clean($r['age']) . ' yrs' : '' ?></div>
                </td>
                <td>
                  <span class="badge badge-info" style="font-size:9px;"><?= clean($r['agency_name'] ?: 'BDRRMC ' . $barangay['name']) ?></span>
                </td>
                <td>
                  <!-- Tactical Availability Quick Switcher -->
                  <select onchange="updateResponderAvailability(<?= $r['id'] ?>, this.value)" style="font-size:10px;padding:3px 6px;border-radius:4px;border:1px solid var(--color-border);background:var(--color-surface);font-weight:600;cursor:pointer;color:var(--color-text);">
                    <option value="Available" <?= ($r['availability'] ?? 'Available') === 'Available' ? 'selected' : '' ?>>🟢 Available</option>
                    <option value="On Duty" <?= ($r['availability'] ?? '') === 'On Duty' ? 'selected' : '' ?>>🔵 On Duty</option>
                    <option value="Responding" <?= ($r['availability'] ?? '') === 'Responding' ? 'selected' : '' ?>>🟠 Responding</option>
                    <option value="Standby" <?= ($r['availability'] ?? '') === 'Standby' ? 'selected' : '' ?>>🟣 Standby</option>
                    <option value="Off Duty" <?= ($r['availability'] ?? '') === 'Off Duty' ? 'selected' : '' ?>>⚪ Off Duty</option>
                  </select>
                </td>
                <td>
                  <?= renderStatusBadge($r['status']) ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>, 'responder')" style="padding:4px 10px;font-size:10.5px;">
                    View
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- ========================================================================= -->
<!-- TAB 2: RESIDENT CITIZENS                                                  -->
<!-- ========================================================================= -->
<?php elseif ($activeTab === 'residents'): ?>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="residents">

        <div style="flex:2;min-width:200px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Resident</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone..." value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Purok Zone</label>
          <select name="purok" class="form-control">
            <option value="">All Puroks</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>" <?= $filterPurok === $p['name'] ? 'selected' : '' ?>>
                <?= clean($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:1;min-width:120px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Account Status</label>
          <select name="status" class="form-control">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        </div>

        <div style="display:flex;gap:6px;">
          <button type="submit" class="btn btn-primary" style="height:36px;">Filter</button>
          <a href="?tab=residents" class="btn btn-outline" style="height:36px;">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Residents Data Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Registered Citizen Residents (<?= count($residentsList) ?>)</h3>
      <span style="font-size:10px;color:var(--color-text-muted);">Jurisdiction: Brgy. <?= clean($barangay['name']) ?></span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Resident Name</th>
            <th>Purok Zone</th>
            <th>Contact Details</th>
            <th>Demographics</th>
            <th>Status</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($residentsList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">No Residents Found</div>
                <div style="font-size:11px;">No active citizen resident accounts match your current filters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($residentsList as $res): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($res), ENT_QUOTES, 'UTF-8') ?>, 'resident')" class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                      <?= clean($res['first_name'] . ' ' . $res['last_name']) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($res['username']) ?></div>
                  </a>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($res['purok'] ?: 'Unassigned') ?></span>
                </td>
                <td>
                  <div style="font-size:11px;font-weight:600;color:var(--color-text);"><?= clean($res['phone'] ?: 'No Phone') ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($res['email']) ?></div>
                </td>
                <td>
                  <div style="font-size:10.5px;color:var(--color-text);"><?= clean($res['gender'] ?: 'Unspecified') ?>, <?= $res['age'] ? clean($res['age']) . ' yrs' : '' ?></div>
                </td>
                <td>
                  <?= renderStatusBadge($res['status']) ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($res), ENT_QUOTES, 'UTF-8') ?>, 'resident')" style="padding:4px 10px;font-size:10.5px;">
                    View
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- ========================================================================= -->
<!-- TAB 3: ARCHIVED ACCOUNTS                                                  -->
<!-- ========================================================================= -->
<?php elseif ($activeTab === 'archived'): ?>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="archived">

        <div style="flex:2;min-width:200px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Archived Account</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone..." value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Account Type</label>
          <select name="type" class="form-control">
            <option value="">All Account Types</option>
            <option value="responder" <?= $filterArchivedType === 'responder' ? 'selected' : '' ?>>Responders Only</option>
            <option value="resident" <?= $filterArchivedType === 'resident' ? 'selected' : '' ?>>Residents Only</option>
          </select>
        </div>

        <div style="display:flex;gap:6px;">
          <button type="submit" class="btn btn-primary" style="height:36px;">Filter</button>
          <a href="?tab=archived" class="btn btn-outline" style="height:36px;">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Archived Data Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Archived Personnel & Citizen Records (<?= count($archivedList) ?>)</h3>
      <span style="font-size:10px;color:var(--color-text-muted);">Accounts here are retained in audit archive with 1-click restoration</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Account Name</th>
            <th>Account Type</th>
            <th>Contact Details</th>
            <th>Assignment</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($archivedList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">Archive Is Empty</div>
                <div style="font-size:11px;">No deleted or archived accounts in this barangay jurisdiction.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($archivedList as $arc): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($arc), ENT_QUOTES, 'UTF-8') ?>, '<?= $arc['user_type'] ?>')" class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-text);font-size:11.5px;">
                      <?= clean($arc['first_name'] . ' ' . $arc['last_name']) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($arc['username']) ?></div>
                  </a>
                </td>
                <td>
                  <?php if ($arc['user_type'] === 'responder'): ?>
                    <span class="badge badge-info" style="font-size:9px;">Responder</span>
                  <?php else: ?>
                    <span class="badge badge-primary" style="font-size:9px;">Resident</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-size:11px;"><?= clean($arc['phone'] ?: 'No Phone') ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($arc['email']) ?></div>
                </td>
                <td>
                  <?php if ($arc['user_type'] === 'responder'): ?>
                    <span style="font-size:10.5px;color:var(--color-text);"><?= clean($arc['position'] ?: 'Responder') ?></span>
                  <?php else: ?>
                    <span style="font-size:10.5px;color:var(--color-text-muted);">Purok <?= clean($arc['purok'] ?: 'N/A') ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-size:9px;">Archived</span>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm" onclick="restoreUserDirect(<?= $arc['id'] ?>, '<?= clean($arc['username']) ?>', '<?= $arc['user_type'] ?>')" style="color:var(--color-success);border-color:var(--color-success);padding:4px 8px;font-size:10px;margin-right:4px;">
                    Restore
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($arc), ENT_QUOTES, 'UTF-8') ?>, '<?= $arc['user_type'] ?>')" style="padding:4px 8px;font-size:10px;">
                    Details
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL 1: REGISTER NEW USER (Dynamic Role Switcher: Responder / Resident)  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createUserModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="createUserModalTitle" style="margin:0;font-size:13px;font-weight:700;">Register New User</h3>
        <p style="margin:2px 0 0;font-size:10px;color:var(--color-text-muted);">Add field personnel or citizen to Barangay <?= clean($barangay['name']) ?></p>
      </div>
      <button class="modal-close-btn" onclick="closeModal('createUserModal')">&times;</button>
    </div>
    
    <form id="createUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php">
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/barangay/users.php?tab=<?= $activeTab ?>">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
      <input type="hidden" name="role" id="create_form_role" value="responder">

      <div class="modal-body">
        <!-- Account Type Pill Selector -->
        <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:6px;">Select Account Type</label>
        <div class="role-toggle-group">
          <button type="button" class="role-toggle-btn active" id="btnRoleResponder" onclick="switchCreateRole('responder')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            Responder Force
          </button>
          <button type="button" class="role-toggle-btn" id="btnRoleResident" onclick="switchCreateRole('resident')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            Citizen Resident
          </button>
        </div>

        <!-- Name Inputs -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">First Name <span style="color:var(--color-danger);">*</span></label>
            <input type="text" name="first_name" class="form-control" required placeholder="e.g. Juan">
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Last Name <span style="color:var(--color-danger);">*</span></label>
            <input type="text" name="last_name" class="form-control" required placeholder="e.g. Dela Cruz">
          </div>
        </div>

        <!-- Username & Password -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Username <span style="color:var(--color-danger);">*</span></label>
            <input type="text" name="username" class="form-control" required placeholder="e.g. juan_delacruz">
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Password <span style="color:var(--color-danger);">*</span></label>
            <input type="password" name="password" class="form-control" required placeholder="Min. 6 characters">
          </div>
        </div>

        <!-- Contact & Demographics -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Email Address <span style="color:var(--color-danger);">*</span></label>
            <input type="email" name="email" class="form-control" required placeholder="juan@gmail.com">
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Phone Number</label>
            <input type="text" name="phone" class="form-control" placeholder="09123456789">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Gender</label>
            <select name="gender" class="form-control">
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Age</label>
            <input type="number" name="age" class="form-control" min="1" max="120" placeholder="e.g. 28">
          </div>
        </div>

        <!-- RESPONDER SPECIFIC FIELDS -->
        <div id="responderSpecificFields">
          <div style="border-top:1px dashed var(--color-border);padding-top:12px;margin-top:6px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:8px;">Tactical Deployment Attributes</div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
              <div>
                <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Connected Agency <span style="color:var(--color-danger);">*</span></label>
                <select name="agency_id" class="form-control">
                  <?php foreach ($availableAgencies as $ag): ?>
                    <option value="<?= $ag['id'] ?>" <?= ((int)$ag['id'] === $defaultAgencyId) ? 'selected' : '' ?>>
                      <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Position / Tactical Role</label>
                <input type="text" name="position" class="form-control" placeholder="e.g. Team Lead, First Aider, Search & Rescue">
              </div>
            </div>

            <div>
              <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Initial Tactical Availability</label>
              <select name="availability" class="form-control">
                <option value="Available" selected>Available (Ready for Callout)</option>
                <option value="On Duty">On Duty (Stationed)</option>
                <option value="Responding">Responding (Field Deployed)</option>
                <option value="Standby">Standby (Reserve)</option>
                <option value="Off Duty">Off Duty</option>
              </select>
            </div>
          </div>
        </div>

        <!-- RESIDENT SPECIFIC FIELDS -->
        <div id="residentSpecificFields" style="display:none;">
          <div style="border-top:1px dashed var(--color-border);padding-top:12px;margin-top:6px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:8px;">Citizen Purok Zone</div>
            <div>
              <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Assigned Purok Zone</label>
              <select name="purok" class="form-control">
                <option value="">-- Select Purok --</option>
                <?php foreach ($localPuroks as $p): ?>
                  <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('createUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitCreateUser">Register User</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: VIEW / IN-PLACE EDIT USER DETAILS                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewUserModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <h3 class="modal-title" id="viewModalTitle" style="margin:0;font-size:13px;font-weight:700;">User Details</h3>
        <span id="viewModalRoleBadge" class="badge badge-info" style="font-size:9px;">Responder</span>
        <span id="viewModalStatusBadge"></span>
      </div>
      <button class="modal-close-btn" onclick="closeModal('viewUserModal')">&times;</button>
    </div>

    <form id="viewUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/update.php">
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/barangay/users.php?tab=<?= $activeTab ?>">
      <input type="hidden" name="user_id" id="view_user_id" value="">
      <input type="hidden" name="user_type" id="view_user_type" value="user">
      <input type="hidden" name="role" id="view_role" value="responder">

      <div class="modal-body">
        <!-- Top Profile Banner -->
        <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--color-surface-subtle);border-radius:8px;border:1px solid var(--color-border-light);margin-bottom:14px;">
          <div style="width:40px;height:40px;border-radius:50%;background:var(--color-primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;" id="view_avatar_initials">
            U
          </div>
          <div style="flex:1;">
            <div style="font-size:12px;font-weight:700;color:var(--color-primary);" id="view_display_name">User Name</div>
            <div style="font-size:10px;color:var(--color-text-muted);" id="view_meta_sub">Responder &bull; Brgy. <?= clean($barangay['name']) ?></div>
          </div>
          <div style="text-align:right;font-size:10px;color:var(--color-text-muted);" id="view_edit_mode_indicator">
            (View Only Mode)
          </div>
        </div>

        <!-- Name Inputs -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">First Name</label>
            <input type="text" name="first_name" id="view_first_name" class="form-control" required disabled>
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Last Name</label>
            <input type="text" name="last_name" id="view_last_name" class="form-control" required disabled>
          </div>
        </div>

        <!-- Username & Email -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Username</label>
            <input type="text" name="username" id="view_username" class="form-control" required disabled>
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Email</label>
            <input type="email" name="email" id="view_email" class="form-control" required disabled>
          </div>
        </div>

        <!-- Contact & Demographics -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Phone Number</label>
            <input type="text" name="phone" id="view_phone" class="form-control" disabled>
          </div>
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Gender</label>
            <select name="gender" id="view_gender" class="form-control" disabled>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Age</label>
            <input type="number" name="age" id="view_age" class="form-control" disabled>
          </div>
          <div id="view_purok_wrap">
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Purok Zone</label>
            <select name="purok" id="view_purok" class="form-control" disabled>
              <option value="">-- No Purok --</option>
              <?php foreach ($localPuroks as $p): ?>
                <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- RESPONDER FIELDS IN VIEW MODAL -->
        <div id="view_responder_fields_wrap">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
              <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Connected Agency</label>
              <select name="agency_id" id="view_agency_id" class="form-control" disabled>
                <?php foreach ($availableAgencies as $ag): ?>
                  <option value="<?= $ag['id'] ?>">
                    <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Position / Role Title</label>
              <input type="text" name="position" id="view_position" class="form-control" disabled>
            </div>
          </div>

          <div style="margin-bottom:12px;">
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Tactical Availability</label>
            <select name="availability" id="view_availability" class="form-control" disabled>
              <option value="Available">Available (Ready for Callout)</option>
              <option value="On Duty">On Duty (Stationed)</option>
              <option value="Responding">Responding (Field Deployed)</option>
              <option value="Standby">Standby (Reserve)</option>
              <option value="Off Duty">Off Duty</option>
            </select>
          </div>
        </div>

        <!-- Optional Password Reset (shown in edit mode) -->
        <div id="view_password_wrap" style="display:none;margin-top:10px;padding-top:10px;border-top:1px dashed var(--color-border);">
          <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">
            New Password <span style="font-size:9.5px;color:var(--color-text-muted);font-weight:normal;">(Leave blank to keep unchanged)</span>
          </label>
          <input type="password" name="password" id="view_password" class="form-control" placeholder="Enter new password">
        </div>

        <!-- Audit Footprint -->
        <div style="margin-top:12px;font-size:9.5px;color:var(--color-text-muted);display:flex;justify-content:space-between;">
          <span>Registered: <strong id="view_created_at_val">-</strong></span>
          <span>Account ID: #<strong id="view_id_val">-</strong></span>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <!-- Left: Delete & Restore Actions -->
        <div style="display:flex;gap:6px;align-items:center;">
          <button type="button" class="btn btn-outline btn-sm" id="modalDeleteBtn" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalDelete()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            Delete Account
          </button>

          <button type="button" class="btn btn-outline btn-sm" id="modalRestoreBtn" style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalRestore()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
            Restore Account
          </button>
        </div>

        <!-- Right: Status Toggle, Edit / Save, Cancel, Close Actions -->
        <div style="display:flex;gap:6px;align-items:center;">
          <button type="button" class="btn btn-outline btn-sm" id="modalToggleStatusBtn" onclick="onModalToggleStatus()">
            Deactivate Account
          </button>

          <button type="button" class="btn btn-outline btn-sm" id="modalCancelEditBtn" onclick="cancelModalEditMode()" style="display:none;">
            Cancel
          </button>

          <button type="button" class="btn btn-primary btn-sm" id="modalEditBtn" onclick="enableModalEditMode()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit
          </button>

          <button type="submit" class="btn btn-primary btn-sm" id="modalSaveBtn" style="display:none;">
            Save Changes
          </button>

          <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewUserModal')">
            Close
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: REUSABLE CONFIRMATION DIALOG                                     -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="confirmationModal" style="z-index: 1200 !important;">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" id="confirmModalTitle">Confirm Operation</h3>
      <button class="modal-close-btn" onclick="closeModal('confirmationModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want to proceed?</p>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<script>
let currentUserInModal = null;
let currentUserTypeInModal = 'responder'; // 'responder' or 'resident'

// 1. Open Create User Modal with Role Switcher
function openCreateUserModal(defaultRole = 'responder') {
  const form = document.getElementById('createUserForm');
  if (form) form.reset();
  switchCreateRole(defaultRole);
  openModal('createUserModal');
}

function switchCreateRole(role) {
  const btnResp = document.getElementById('btnRoleResponder');
  const btnRes = document.getElementById('btnRoleResident');
  const respFields = document.getElementById('responderSpecificFields');
  const resFields = document.getElementById('residentSpecificFields');
  const roleInput = document.getElementById('create_form_role');
  const modalTitle = document.getElementById('createUserModalTitle');

  if (role === 'resident') {
    btnResp.classList.remove('active');
    btnRes.classList.add('active');
    respFields.style.display = 'none';
    resFields.style.display = 'block';
    roleInput.value = 'resident';
    modalTitle.innerText = 'Register New Citizen Resident';
  } else {
    btnRes.classList.remove('active');
    btnResp.classList.add('active');
    respFields.style.display = 'block';
    resFields.style.display = 'none';
    roleInput.value = 'responder';
    modalTitle.innerText = 'Register New Responder Personnel';
  }
}

// 2. Open View User Modal
function viewUserDetails(u, userType) {
  currentUserInModal = u;
  currentUserTypeInModal = (userType === 'resident') ? 'resident' : 'responder';

  const isResident = (currentUserTypeInModal === 'resident');

  // Title and Role Badges
  document.getElementById('viewModalRoleBadge').innerText = isResident ? 'Citizen Resident' : 'Responder Force';
  document.getElementById('viewModalRoleBadge').className = isResident ? 'badge badge-primary' : 'badge badge-info';

  let badgeClass = (u.status === 'active') ? 'badge-success' : ((u.status === 'archived') ? 'badge-neutral' : 'badge-danger');
  let badgeLabel = (u.status === 'active') ? 'Active' : ((u.status === 'archived') ? 'Archived' : 'Deactivated');
  document.getElementById('viewModalStatusBadge').innerHTML = `<span class="badge ${badgeClass}">${escapeHtml(badgeLabel)}</span>`;

  // Banner details
  const displayName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);
  document.getElementById('view_display_name').innerText = displayName;
  document.getElementById('view_avatar_initials').innerText = (u.first_name ? u.first_name[0] : 'U').toUpperCase();
  document.getElementById('view_meta_sub').innerText = (isResident ? 'Resident' : (u.position || 'Responder')) + ' • @' + u.username;
  document.getElementById('view_edit_mode_indicator').innerHTML = '(View Only Mode)';

  // Hidden Inputs
  document.getElementById('view_user_id').value = u.id;
  document.getElementById('view_user_type').value = isResident ? 'resident' : 'user';
  document.getElementById('view_role').value = isResident ? 'resident' : 'responder';

  // Populate Field Values
  document.getElementById('view_first_name').value = u.first_name || '';
  document.getElementById('view_last_name').value = u.last_name || '';
  document.getElementById('view_username').value = u.username || '';
  document.getElementById('view_email').value = u.email || '';
  document.getElementById('view_phone').value = u.phone || '';
  document.getElementById('view_gender').value = u.gender || 'Male';
  document.getElementById('view_age').value = u.age || '';

  // Toggle Visibility for Role-Specific Elements
  const respFieldsWrap = document.getElementById('view_responder_fields_wrap');
  const purokWrap = document.getElementById('view_purok_wrap');

  if (isResident) {
    respFieldsWrap.style.display = 'none';
    purokWrap.style.display = 'block';
    document.getElementById('view_purok').value = u.purok || '';
  } else {
    respFieldsWrap.style.display = 'block';
    purokWrap.style.display = 'none';
    document.getElementById('view_position').value = u.position || '';
    if (u.agency_id) {
      document.getElementById('view_agency_id').value = u.agency_id;
    }
    document.getElementById('view_availability').value = u.availability || 'Available';
  }

  // Audit
  document.getElementById('view_created_at_val').innerText = u.created_at || 'N/A';
  document.getElementById('view_id_val').innerText = u.id;

  // Lock inputs
  setModalInputsDisabled(true);

  // Configure Button States
  const restoreBtn = document.getElementById('modalRestoreBtn');
  const deleteBtn = document.getElementById('modalDeleteBtn');
  const toggleBtn = document.getElementById('modalToggleStatusBtn');

  if (u.status === 'archived') {
    restoreBtn.style.display = 'inline-flex';
    deleteBtn.style.display = 'none';
    toggleBtn.style.display = 'none';
  } else {
    restoreBtn.style.display = 'none';
    deleteBtn.style.display = 'inline-flex';
    toggleBtn.style.display = 'inline-flex';
    toggleBtn.innerText = (u.status === 'active') ? 'Deactivate Account' : 'Reactivate Account';
  }

  // Reset Edit Mode Buttons
  document.getElementById('modalEditBtn').style.display = (u.status === 'archived') ? 'none' : 'inline-flex';
  document.getElementById('modalSaveBtn').style.display = 'none';
  document.getElementById('modalCancelEditBtn').style.display = 'none';
  document.getElementById('view_password_wrap').style.display = 'none';

  openModal('viewUserModal');
}

// 3. In-Place Edit Mode
function enableModalEditMode() {
  setModalInputsDisabled(false);
  document.getElementById('view_edit_mode_indicator').innerHTML = '<span style="color:var(--color-primary);font-weight:700;">(Editing Mode)</span>';
  document.getElementById('modalEditBtn').style.display = 'none';
  document.getElementById('modalSaveBtn').style.display = 'inline-flex';
  document.getElementById('modalCancelEditBtn').style.display = 'inline-flex';
  document.getElementById('view_password_wrap').style.display = 'block';
  document.getElementById('view_first_name').focus();
}

function cancelModalEditMode() {
  if (currentUserInModal) {
    viewUserDetails(currentUserInModal, currentUserTypeInModal);
  }
}

function setModalInputsDisabled(state) {
  const fields = [
    'view_first_name', 'view_last_name', 'view_username', 'view_email',
    'view_phone', 'view_gender', 'view_age', 'view_purok',
    'view_agency_id', 'view_position', 'view_availability'
  ];
  fields.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = state;
  });
}

// 4. Quick Availability Switch for Responders
async function updateResponderAvailability(responderId, newAvailability) {
  try {
    const fd = new FormData();
    fd.append('responder_id', responderId);
    fd.append('availability', newAvailability);

    const res = await fetch(`${BASE_URL}/backend/functions/responders/quick_availability.php`, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd
    });
    const data = await res.json();
    if (data.success) {
      showToast(`Availability updated to ${newAvailability}`, 'success');
      setTimeout(() => window.location.reload(), 600);
    } else {
      showToast(data.message || 'Failed to update availability.', 'danger');
    }
  } catch (err) {
    showToast('Network error while updating availability.', 'danger');
  }
}

// 5. Account Deletion (Move to Archive)
function onModalDelete() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;
  const isResident = (currentUserTypeInModal === 'resident');
  const userType = isResident ? 'resident' : 'user';

  showConfirmModal(
    'Confirm Account Archival',
    `Are you sure you want to delete and archive @${u.username}? The record will be moved to Archived Accounts.`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', u.id);
      fd.append('user_type', userType);

      const res = await fetch(`${BASE_URL}/backend/functions/users/archive.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast('Account moved to archive.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Archival failed.', 'danger');
      }
    }
  );
}

// 6. Account Restore
function onModalRestore() {
  if (!currentUserInModal) return;
  restoreUserDirect(currentUserInModal.id, currentUserInModal.username, currentUserTypeInModal);
}

function restoreUserDirect(userId, username, userType) {
  const isResident = (userType === 'resident');
  showConfirmModal(
    'Confirm Account Restoration',
    `Are you sure you want to restore @${username} back to active records?`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('user_type', isResident ? 'resident' : 'user');

      const res = await fetch(`${BASE_URL}/backend/functions/users/restore.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast('Account restored to active.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Restoration failed.', 'danger');
      }
    }
  );
}

// 7. Toggle Deactivate / Reactivate Status
function onModalToggleStatus() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;
  const isResident = (currentUserTypeInModal === 'resident');
  const isCurrentlyActive = (u.status === 'active');
  const newStatus = isCurrentlyActive ? 'inactive' : 'active';

  showConfirmModal(
    isCurrentlyActive ? 'Confirm Deactivation' : 'Confirm Reactivation',
    `Are you sure you want to ${isCurrentlyActive ? 'deactivate' : 'reactivate'} @${u.username}?`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', u.id);
      fd.append('user_type', isResident ? 'resident' : 'user');
      fd.append('status', newStatus);

      const res = await fetch(`${BASE_URL}/backend/functions/users/update_status.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(`Account status updated to ${newStatus}.`, 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Status toggle failed.', 'danger');
      }
    }
  );
}

// Confirmation Dialog helper
function showConfirmModal(title, message, onConfirm) {
  document.getElementById('confirmModalTitle').innerText = title;
  document.getElementById('confirmModalMessage').innerText = message;
  const submitBtn = document.getElementById('confirmModalSubmitBtn');

  const newBtn = submitBtn.cloneNode(true);
  submitBtn.parentNode.replaceChild(newBtn, submitBtn);

  newBtn.addEventListener('click', async () => {
    closeModal('confirmationModal');
    if (typeof onConfirm === 'function') {
      await onConfirm();
    }
  });

  openModal('confirmationModal');
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
