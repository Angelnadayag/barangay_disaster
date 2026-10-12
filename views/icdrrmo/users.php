<?php
// ============================================================================
// Views (ICDRRMO): Consolidated User Management & RBAC Module
// Unified City-Wide Management of Barangay Officials, Responders Force, 
// Citizen Residents across all barangays, and ICDRRMO Central Command Personnel.
// Features: Seamless Tab Switching (Officials, Responders, Residents, Archived),
// In-Place View/Edit Modes, Tactical Readiness KPI Tracking, Quick Availability
// Switcher, Dynamic Role Registration (4 Roles), Multi-Jurisdiction Barangay & Agency
// Filtering, and 1-Click Archival / Restoration.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$currentUserId = (int) $user['id'];
$db = getDBConnection();

// Active Tab: 'officials', 'responders', 'residents', or 'archived'
$activeTab = $_GET['tab'] ?? 'officials';
if (!in_array($activeTab, ['officials', 'responders', 'residents', 'archived'], true)) {
  $activeTab = 'officials';
}

// Request filters
$search = trim($_GET['search'] ?? '');
$filterBarangay = trim($_GET['barangay_id'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterAvailability = trim($_GET['availability'] ?? '');
$filterAgency = !empty($_GET['agency_id']) ? (int) $_GET['agency_id'] : 0;
$filterPurok = trim($_GET['purok'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$filterArchivedType = trim($_GET['type'] ?? ''); // 'all', 'officials', 'responder', 'resident'

// Global Counts for navigation tabs (independent of filters)
$activeOfficialsCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role IN ('barangay_head', 'icdrrmo') AND status != 'archived'")->fetchColumn();
$activeRespondersCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'responder' AND status != 'archived'")->fetchColumn();
$activeResidentsCount = (int) $db->query("SELECT COUNT(*) FROM residents WHERE status != 'archived'")->fetchColumn();

$archivedUsersCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE status = 'archived'")->fetchColumn();
$archivedResidentsCount = (int) $db->query("SELECT COUNT(*) FROM residents WHERE status = 'archived'")->fetchColumn();
$totalArchivedCount = $archivedUsersCount + $archivedResidentsCount;
$totalActiveUsers = $activeOfficialsCount + $activeRespondersCount + $activeResidentsCount;

// Fetch all barangays for filters and dropdowns
$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active agencies for responder selection & filters
$availableAgencies = $db->query("
    SELECT a.id, a.name, a.agency_type, a.barangay_id, b.name AS barangay_name 
    FROM agencies a 
    LEFT JOIN barangays b ON a.barangay_id = b.id 
    WHERE a.status = 'active'
    ORDER BY (a.agency_type = 'ICDRRMO') DESC, a.agency_type ASC, a.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all distinct puroks mapping for dynamic dropdowns
$purokRows = $db->query("SELECT id, barangay_id, name FROM puroks ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Tactical Availability KPIs for Responders (filtered by barangay if selected, or city-wide)
$kpiSql = "
    SELECT 
        SUM(CASE WHEN availability = 'Available' THEN 1 ELSE 0 END) AS count_available,
        SUM(CASE WHEN availability = 'On Duty' THEN 1 ELSE 0 END) AS count_onduty,
        SUM(CASE WHEN availability = 'Responding' THEN 1 ELSE 0 END) AS count_responding,
        SUM(CASE WHEN availability IN ('Standby', 'Off Duty') THEN 1 ELSE 0 END) AS count_standby,
        COUNT(*) AS total_responders
    FROM users 
    WHERE role = 'responder' AND status != 'archived'
";
$kpiParams = [];
if (!empty($filterBarangay)) {
  $kpiSql .= " AND barangay_id = ?";
  $kpiParams[] = (int) $filterBarangay;
}
$kpiStmt = $db->prepare($kpiSql);
$kpiStmt->execute($kpiParams);
$availKpis = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [
  'count_available' => 0,
  'count_onduty' => 0,
  'count_responding' => 0,
  'count_standby' => 0,
  'total_responders' => 0
];

// Query Data based on Active Tab
$officialsList = [];
$respondersList = [];
$residentsList = [];
$archivedList = [];

if ($activeTab === 'officials') {
  $sql = "
        SELECT u.*, b.name AS barangay_name, 'user' AS user_type
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.id 
        WHERE u.role IN ('barangay_head', 'icdrrmo') AND u.status != 'archived'
    ";
  $params = [];

  if (!empty($filterBarangay)) {
    if ($filterBarangay === 'central') {
      $sql .= " AND (u.barangay_id IS NULL OR u.role = 'icdrrmo')";
    } else {
      $sql .= " AND u.barangay_id = ?";
      $params[] = (int) $filterBarangay;
    }
  }
  if (!empty($filterRole)) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
  }
  if (!empty($filterStatus)) {
    $sql .= " AND u.status = ?";
    $params[] = $filterStatus;
  }
  if (!empty($search)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.position LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
  }
  $sql .= " ORDER BY FIELD(u.role, 'barangay_head', 'icdrrmo'), FIELD(u.status, 'active', 'pending', 'inactive'), u.first_name ASC";
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $officialsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($activeTab === 'responders') {
  $sql = "
        SELECT u.*, b.name AS barangay_name, a.name AS agency_name, a.agency_type, a.contact_number AS agency_phone, 'user' AS user_type
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.id 
        LEFT JOIN agencies a ON u.agency_id = a.id
        WHERE u.role = 'responder' AND u.status != 'archived'
    ";
  $params = [];

  if (!empty($filterBarangay)) {
    $sql .= " AND u.barangay_id = ?";
    $params[] = (int) $filterBarangay;
  }
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
    $params[] = (int) $filterAgency;
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
        SELECT r.*, b.name AS barangay_name, 'resident' AS user_type, 'resident' AS role
        FROM residents r 
        LEFT JOIN barangays b ON r.barangay_id = b.id 
        WHERE r.status != 'archived'
    ";
  $params = [];

  if (!empty($filterBarangay)) {
    $sql .= " AND r.barangay_id = ?";
    $params[] = (int) $filterBarangay;
  }
  if (!empty($filterStatus)) {
    $sql .= " AND r.status = ?";
    $params[] = $filterStatus;
  }
  if (!empty($filterPurok)) {
    $sql .= " AND r.purok LIKE ?";
    $params[] = "%$filterPurok%";
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
  // Combine archived users and archived residents
  $archivedUsers = [];

  if (empty($filterArchivedType) || in_array($filterArchivedType, ['officials', 'responder'], true)) {
    $uSql = "
            SELECT u.id, u.username, u.first_name, u.last_name, u.full_name, u.email, u.phone, u.gender, u.age,
                   u.role, u.status, u.created_at, u.position, 'user' AS user_type, u.barangay_id,
                   b.name AS barangay_name, a.name AS agency_name, NULL AS purok
            FROM users u
            LEFT JOIN barangays b ON u.barangay_id = b.id
            LEFT JOIN agencies a ON u.agency_id = a.id
            WHERE u.status = 'archived'
        ";
    $uParams = [];

    if ($filterArchivedType === 'officials') {
      $uSql .= " AND u.role IN ('barangay_head', 'icdrrmo')";
    } elseif ($filterArchivedType === 'responder') {
      $uSql .= " AND u.role = 'responder'";
    }

    if (!empty($filterBarangay)) {
      $uSql .= " AND u.barangay_id = ?";
      $uParams[] = (int) $filterBarangay;
    }
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
                   'resident' AS role, r.status, r.created_at, NULL AS position, 'resident' AS user_type, r.barangay_id,
                   b.name AS barangay_name, NULL AS agency_name, r.purok
            FROM residents r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            WHERE r.status = 'archived'
        ";
    $rParams = [];

    if (!empty($filterBarangay)) {
      $rSql .= " AND r.barangay_id = ?";
      $rParams[] = (int) $filterBarangay;
    }
    if (!empty($search)) {
      $rSql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR r.username LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)";
      $like = "%$search%";
      $rParams = array_merge($rParams, [$like, $like, $like, $like, $like]);
    }
    $rStmt = $db->prepare($rSql);
    $rStmt->execute($rParams);
    $archivedUsers = array_merge($archivedUsers, $rStmt->fetchAll(PDO::FETCH_ASSOC));
  }

  usort($archivedUsers, function ($a, $b) {
    return strcmp(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''), ($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
  });
  $archivedList = $archivedUsers;
}

$pageTitle = "Consolidated User Management — ICDRRMO Central Command";
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
    overflow-x: auto;
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
    white-space: nowrap;
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
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05);
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

  .kpi-pill-val.green {
    color: #16A34A;
  }

  .kpi-pill-val.blue {
    color: #0284C7;
  }

  .kpi-pill-val.amber {
    color: #D97706;
  }

  .kpi-pill-val.purple {
    color: #7C3AED;
  }

  /* Role selector pill toggle in create modal */
  .role-toggle-group {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
    background: var(--color-surface-subtle);
    padding: 4px;
    border-radius: 8px;
    border: 1px solid var(--color-border-light);
    flex-wrap: wrap;
  }

  .role-toggle-btn {
    flex: 1;
    min-width: 120px;
    text-align: center;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    background: transparent;
    color: var(--color-text-secondary);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
  }

  .role-toggle-btn.active {
    background: var(--color-surface);
    color: var(--color-primary);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
  }
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Consolidated User Management — ICDRRMO Central Command</h1>
    <p class="page-header-desc">
      City-wide central registry for Barangay Officials, Responders Force, and Citizen Residents across all 15 barangays.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary"
      onclick="openCreateUserModal('<?= $activeTab === 'residents' ? 'resident' : ($activeTab === 'responders' ? 'responder' : 'barangay_head') ?>')">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        style="margin-right:4px;">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Register New User
    </button>
  </div>
</div>

<!-- Tabs Navigation -->
<div class="nav-tabs">
  <a href="?tab=officials" class="nav-tab-item <?= $activeTab === 'officials' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M3 21h18M3 7v1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7H3l2-4h14l2 4M5 21V10.85M19 21V10.85M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"></path>
    </svg>
    Officials & Staff
    <span class="nav-tab-badge"><?= $activeOfficialsCount ?></span>
  </a>
  <a href="?tab=responders" class="nav-tab-item <?= $activeTab === 'responders' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
    </svg>
    Responders Force
    <span class="nav-tab-badge"><?= $activeRespondersCount ?></span>
  </a>
  <a href="?tab=residents" class="nav-tab-item <?= $activeTab === 'residents' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
      <circle cx="9" cy="7" r="4"></circle>
      <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
      <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
    </svg>
    Resident Citizens
    <span class="nav-tab-badge"><?= $activeResidentsCount ?></span>
  </a>
  <a href="?tab=archived" class="nav-tab-item <?= $activeTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="21 8 21 21 3 21 3 8"></polyline>
      <rect x="1" y="3" width="22" height="5"></rect>
      <line x1="10" y1="12" x2="14" y2="12"></line>
    </svg>
    Archived Accounts
    <span class="nav-tab-badge"><?= $totalArchivedCount ?></span>
  </a>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: OFFICIALS & STAFF (Barangay Heads & ICDRRMO)                      -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'officials'): ?>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="officials">

        <div style="flex:2;min-width:180px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Official / Personnel</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone, position..."
            value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Barangay Jurisdiction</label>
          <select name="barangay_id" class="form-control">
            <option value="">All Jurisdictions</option>
            <option value="central" <?= $filterBarangay === 'central' ? 'selected' : '' ?>>City Central / ICDRRMO</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:1;min-width:120px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Role</label>
          <select name="role" class="form-control">
            <option value="">All Roles</option>
            <option value="barangay_head" <?= $filterRole === 'barangay_head' ? 'selected' : '' ?>>Barangay Head</option>
            <option value="icdrrmo" <?= $filterRole === 'icdrrmo' ? 'selected' : '' ?>>ICDRRMO Admin / Staff</option>
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
          <a href="?tab=officials" class="btn btn-outline" style="height:36px;">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Officials Data Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Active Officials & Administration Personnel (<?= count($officialsList) ?>)</h3>
      <span style="font-size:10px;color:var(--color-text-muted);">City-wide command coverage</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Official Name</th>
            <th>Role & Position</th>
            <th>Assigned Jurisdiction</th>
            <th>Contact Details</th>
            <th>Status</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($officialsList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">No Officials Found</div>
                <div style="font-size:11px;">No active personnel accounts match your current filters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($officialsList as $off): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($off), ENT_QUOTES, 'UTF-8') ?>, '<?= $off['role'] ?>')"
                    class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                      <?= clean(trim(($off['first_name'] ?? '') . ' ' . ($off['last_name'] ?? '')) ?: ($off['full_name'] ?? '')) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($off['username']) ?> &bull;
                      <?= clean($off['phone'] ?: 'No Phone') ?>
                    </div>
                  </a>
                </td>
                <td>
                  <div>
                    <?php if ($off['role'] === 'barangay_head'): ?>
                      <span class="badge badge-warning" style="font-size:9px;">Barangay Head</span>
                    <?php else: ?>
                      <span class="badge badge-secondary" style="font-size:9px;">ICDRRMO Officer</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:10.5px;color:var(--color-text);margin-top:2px;font-weight:500;">
                    <?= clean($off['position'] ?: ($off['role'] === 'barangay_head' ? 'Punong Barangay' : 'Operations Head')) ?>
                  </div>
                </td>
                <td>
                  <span style="font-size:11px;font-weight:600;color:var(--color-primary);">
                    <?= clean($off['barangay_name'] ? 'Brgy. ' . $off['barangay_name'] : 'Central Command / ICDRRMO') ?>
                  </span>
                </td>
                <td>
                  <div style="font-size:10.5px;color:var(--color-text);"><?= clean($off['email']) ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($off['gender'] ?: 'Unspecified') ?><?= $off['age'] ? ', ' . clean($off['age']) . ' yrs' : '' ?></div>
                </td>
                <td>
                  <?= renderStatusBadge($off['status']) ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($off), ENT_QUOTES, 'UTF-8') ?>, '<?= $off['role'] ?>')"
                    style="padding:4px 10px;font-size:10.5px;">
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
  <!-- TAB 2: RESPONDERS FORCE                                                   -->
  <!-- ========================================================================= -->
<?php elseif ($activeTab === 'responders'): ?>

  <!-- Tactical Readiness KPI Bar -->
  <div class="readiness-kpi-bar">
    <div class="kpi-pill">
      <div class="kpi-pill-title">Available for Callout</div>
      <div class="kpi-pill-val green"><?= (int) ($availKpis['count_available'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">On Duty</div>
      <div class="kpi-pill-val blue"><?= (int) ($availKpis['count_onduty'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Actively Responding</div>
      <div class="kpi-pill-val amber"><?= (int) ($availKpis['count_responding'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Standby / Off Duty</div>
      <div class="kpi-pill-val purple"><?= (int) ($availKpis['count_standby'] ?? 0) ?></div>
    </div>
    <div class="kpi-pill">
      <div class="kpi-pill-title">Total Responders</div>
      <div class="kpi-pill-val"><?= (int) ($availKpis['total_responders'] ?? 0) ?></div>
    </div>
  </div>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="responders">

        <div style="flex:2;min-width:180px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Responder</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone, position..."
            value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:130px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Barangay</label>
          <select name="barangay_id" class="form-control">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
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
              <option value="<?= $ag['id'] ?>" <?= $filterAgency === (int) $ag['id'] ? 'selected' : '' ?>>
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
      <span style="font-size:10px;color:var(--color-text-muted);">Real-time readiness and agency callouts</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Responder Name</th>
            <th>Role & Position</th>
            <th>Barangay & Agency</th>
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
                  <a href="javascript:void(0)"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>, 'responder')"
                    class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                      <?= clean(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['full_name'] ?? '')) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($r['username']) ?> &bull;
                      <?= clean($r['phone'] ?: 'No Phone') ?>
                    </div>
                  </a>
                </td>
                <td>
                  <span style="font-weight:600;color:var(--color-text);font-size:11px;"><?= clean($r['position'] ?: 'Responder') ?></span>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($r['gender'] ?: 'Unspecified') ?><?= $r['age'] ? ', ' . clean($r['age']) . ' yrs' : '' ?></div>
                </td>
                <td>
                  <div style="font-size:11px;font-weight:600;color:var(--color-primary);">
                    <?= clean($r['barangay_name'] ? 'Brgy. ' . $r['barangay_name'] : 'Central Command') ?>
                  </div>
                  <span class="badge badge-info" style="font-size:9px;margin-top:2px;">
                    <?= clean($r['agency_name'] ?: 'City Operations') ?>
                  </span>
                </td>
                <td>
                  <!-- Tactical Availability Quick Switcher -->
                  <select onchange="updateResponderAvailability(<?= $r['id'] ?>, this.value)"
                    style="font-size:10px;padding:3px 6px;border-radius:4px;border:1px solid var(--color-border);background:var(--color-surface);font-weight:600;cursor:pointer;color:var(--color-text);">
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
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>, 'responder')"
                    style="padding:4px 10px;font-size:10.5px;">
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
  <!-- TAB 3: RESIDENT CITIZENS                                                  -->
  <!-- ========================================================================= -->
<?php elseif ($activeTab === 'residents'): ?>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="residents">

        <div style="flex:2;min-width:200px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Resident</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone..."
            value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Barangay</label>
          <select name="barangay_id" class="form-control">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:1;min-width:130px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Purok Zone</label>
          <input type="text" name="purok" class="form-control" placeholder="Purok name..." value="<?= clean($filterPurok) ?>">
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
          <a href="?tab=residents" class="btn btn-outline" style="height:36px;">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Residents Data Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Registered Citizen Residents (<?= count($residentsList) ?>)</h3>
      <span style="font-size:10px;color:var(--color-text-muted);">Verified local residents across all barangays</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Resident Name</th>
            <th>Barangay Jurisdiction</th>
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
              <td colspan="7" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">No Residents Found</div>
                <div style="font-size:11px;">No active citizen resident accounts match your current filters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($residentsList as $res): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($res), ENT_QUOTES, 'UTF-8') ?>, 'resident')"
                    class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:11.5px;">
                      <?= clean(trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? '')) ?: ($res['full_name'] ?? '')) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($res['username']) ?></div>
                  </a>
                </td>
                <td>
                  <span style="font-size:11px;font-weight:600;color:var(--color-text);">
                    <?= clean($res['barangay_name'] ? 'Brgy. ' . $res['barangay_name'] : 'Unassigned') ?>
                  </span>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-size:9.5px;">
                    <?= clean($res['purok'] ?: 'Unassigned') ?>
                  </span>
                </td>
                <td>
                  <div style="font-size:11px;font-weight:600;color:var(--color-text);"><?= clean($res['phone'] ?: 'No Phone') ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($res['email']) ?></div>
                </td>
                <td>
                  <div style="font-size:10.5px;color:var(--color-text);"><?= clean($res['gender'] ?: 'Unspecified') ?><?= $res['age'] ? ', ' . clean($res['age']) . ' yrs' : '' ?></div>
                </td>
                <td>
                  <?= renderStatusBadge($res['status']) ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($res), ENT_QUOTES, 'UTF-8') ?>, 'resident')"
                    style="padding:4px 10px;font-size:10.5px;">
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
  <!-- TAB 4: ARCHIVED ACCOUNTS                                                  -->
  <!-- ========================================================================= -->
<?php elseif ($activeTab === 'archived'): ?>

  <!-- Search & Filters -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body">
      <form method="GET" action="" class="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="archived">

        <div style="flex:2;min-width:200px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Search Archived Account</label>
          <input type="text" name="search" class="form-control" placeholder="Name, username, email, phone..."
            value="<?= clean($search) ?>">
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Barangay</label>
          <select name="barangay_id" class="form-control">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:1;min-width:140px;">
          <label style="font-size:9.5px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:3px;">Account Type</label>
          <select name="type" class="form-control">
            <option value="">All Account Types</option>
            <option value="officials" <?= $filterArchivedType === 'officials' ? 'selected' : '' ?>>Officials & Staff</option>
            <option value="responder" <?= $filterArchivedType === 'responder' ? 'selected' : '' ?>>Responders Force</option>
            <option value="resident" <?= $filterArchivedType === 'resident' ? 'selected' : '' ?>>Citizen Residents</option>
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
            <th>Barangay Jurisdiction</th>
            <th>Contact Details</th>
            <th>Assignment</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($archivedList)): ?>
            <tr>
              <td colspan="7" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;">Archive Is Empty</div>
                <div style="font-size:11px;">No deleted or archived accounts in the city-wide system.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($archivedList as $arc): ?>
              <tr>
                <td>
                  <a href="javascript:void(0)"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($arc), ENT_QUOTES, 'UTF-8') ?>, '<?= $arc['role'] ?? $arc['user_type'] ?>')"
                    class="table-user-link">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-text);font-size:11.5px;">
                      <?= clean(trim(($arc['first_name'] ?? '') . ' ' . ($arc['last_name'] ?? '')) ?: ($arc['full_name'] ?? '')) ?>
                    </div>
                    <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($arc['username']) ?></div>
                  </a>
                </td>
                <td>
                  <?php if ($arc['role'] === 'barangay_head'): ?>
                    <span class="badge badge-warning" style="font-size:9px;">Barangay Head</span>
                  <?php elseif ($arc['role'] === 'icdrrmo'): ?>
                    <span class="badge badge-secondary" style="font-size:9px;">ICDRRMO Officer</span>
                  <?php elseif ($arc['role'] === 'responder'): ?>
                    <span class="badge badge-info" style="font-size:9px;">Responder</span>
                  <?php else: ?>
                    <span class="badge badge-primary" style="font-size:9px;">Resident</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="font-size:10.5px;color:var(--color-text);">
                    <?= clean($arc['barangay_name'] ? 'Brgy. ' . $arc['barangay_name'] : 'City Central') ?>
                  </span>
                </td>
                <td>
                  <div style="font-size:11px;"><?= clean($arc['phone'] ?: 'No Phone') ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($arc['email']) ?></div>
                </td>
                <td>
                  <?php if ($arc['role'] === 'responder'): ?>
                    <span style="font-size:10.5px;color:var(--color-text);"><?= clean($arc['position'] ?: 'Responder') ?></span>
                  <?php elseif ($arc['role'] === 'resident'): ?>
                    <span style="font-size:10.5px;color:var(--color-text-muted);"><?= clean($arc['purok'] ?: 'No Purok') ?></span>
                  <?php else: ?>
                    <span style="font-size:10.5px;color:var(--color-text);"><?= clean($arc['position'] ?: formatRoleName($arc['role'])) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-size:9px;">Archived</span>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick="restoreUserDirect(<?= $arc['id'] ?>, '<?= clean($arc['username']) ?>', '<?= $arc['user_type'] ?>')"
                    style="color:var(--color-success);border-color:var(--color-success);padding:4px 8px;font-size:10px;margin-right:4px;">
                    Restore
                  </button>
                  <button type="button" class="btn btn-outline btn-sm"
                    onclick="viewUserDetails(<?= htmlspecialchars(json_encode($arc), ENT_QUOTES, 'UTF-8') ?>, '<?= $arc['role'] ?? $arc['user_type'] ?>')"
                    style="padding:4px 8px;font-size:10px;">
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
<!-- MODAL 1: REGISTER NEW USER (Dynamic Role Switcher: 4 Roles)               -->
<!-- Merged inputs dynamically adapting to selected role                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createUserModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="createUserModalTitle" style="margin:0;font-size:13px;font-weight:700;">Register New User</h3>
        <p style="margin:2px 0 0;font-size:10px;color:var(--color-text-muted);" id="createUserModalSubtitle">
          Create personnel, official, or resident citizen record in the city-wide system.
        </p>
      </div>
      <button class="modal-close-btn" onclick="closeModal('createUserModal')">&times;</button>
    </div>

    <form id="createUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php">
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/icdrrmo/users.php?tab=<?= $activeTab ?>">
      <input type="hidden" name="role" id="create_form_role" value="barangay_head">
      <input type="hidden" name="user_type" id="create_form_user_type" value="user">

      <div class="modal-body">
        <!-- Account Type 4-Pill Selector -->
        <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);display:block;margin-bottom:6px;">
          Select Account Classification
        </label>
        <div class="role-toggle-group">
          <button type="button" class="role-toggle-btn active" id="btnRoleBarangayHead" onclick="switchCreateRole('barangay_head')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 21h18M3 7v1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7H3l2-4h14l2 4M5 21V10.85M19 21V10.85M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"></path>
            </svg>
            Barangay Head
          </button>
          <button type="button" class="role-toggle-btn" id="btnRoleResponder" onclick="switchCreateRole('responder')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            Responder Force
          </button>
          <button type="button" class="role-toggle-btn" id="btnRoleResident" onclick="switchCreateRole('resident')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
            </svg>
            Citizen Resident
          </button>
          <button type="button" class="role-toggle-btn" id="btnRoleIcdrmo" onclick="switchCreateRole('icdrrmo')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
            </svg>
            ICDRRMO Officer
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
            <input type="email" name="email" class="form-control" required placeholder="juan@iligan.gov.ph">
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

        <!-- Merged Jurisdiction: Assigned Barangay (Applicable to Barangay Head, Responder, Resident) -->
        <div id="createBarangaySection" style="margin-bottom:12px;">
          <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;" id="createBarangayLabel">
            Assigned Barangay Jurisdiction <span style="color:var(--color-danger);">*</span>
          </label>
          <select name="barangay_id" id="create_barangay_id" class="form-control" onchange="onBarangaySelectionChanged(this.value, 'create_purok')">
            <option value="">-- Select Barangay Jurisdiction --</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Merged Role-Specific Section: Official / Responder Position -->
        <div id="createPositionSection" style="margin-bottom:12px;">
          <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;" id="createPositionLabel">
            Position / Title
          </label>
          <input type="text" name="position" id="create_position" class="form-control" placeholder="e.g. Punong Barangay">
        </div>

        <!-- RESPONDER SPECIFIC FIELDS: Agency & Availability -->
        <div id="createResponderSpecificFields" style="display:none;">
          <div style="border-top:1px dashed var(--color-border);padding-top:12px;margin-top:6px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:8px;">
              Tactical Deployment Attributes
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
              <div>
                <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Connected Agency <span style="color:var(--color-danger);">*</span></label>
                <select name="agency_id" id="create_agency_id" class="form-control">
                  <?php foreach ($availableAgencies as $ag): ?>
                    <option value="<?= $ag['id'] ?>">
                      <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
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
        </div>

        <!-- RESIDENT SPECIFIC FIELDS: Purok Zone -->
        <div id="createResidentSpecificFields" style="display:none;">
          <div style="border-top:1px dashed var(--color-border);padding-top:12px;margin-top:6px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:8px;">
              Citizen Residential Zone
            </div>
            <div>
              <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Purok Zone / Neighborhood Unit</label>
              <input type="text" name="purok" id="create_purok" class="form-control" list="purokListSuggestions" placeholder="e.g. Purok Riverside 1">
              <datalist id="purokListSuggestions">
                <?php foreach ($purokRows as $p): ?>
                  <option value="<?= clean($p['name']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
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
<!-- Exact Barangay Head UI with Merged Inputs for all Roles                   -->
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
      <input type="hidden" name="return_url" value="<?= BASE_URL ?>/views/icdrrmo/users.php?tab=<?= $activeTab ?>">
      <input type="hidden" name="user_id" id="view_user_id" value="">
      <input type="hidden" name="user_type" id="view_user_type" value="user">
      <input type="hidden" name="role" id="view_role" value="responder">

      <div class="modal-body">
        <!-- Top Profile Banner -->
        <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--color-surface-subtle);border-radius:8px;border:1px solid var(--color-border-light);margin-bottom:14px;">
          <div style="width:40px;height:40px;border-radius:50%;background:var(--color-primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;"
            id="view_avatar_initials">
            U
          </div>
          <div style="flex:1;">
            <div style="font-size:12px;font-weight:700;color:var(--color-primary);" id="view_display_name">User Name</div>
            <div style="font-size:10px;color:var(--color-text-muted);" id="view_meta_sub">Role &bull; Jurisdiction</div>
          </div>
          <div id="view_mode_badge" style="font-size:9.5px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px;">
            (View Only)
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
          <div id="view_barangay_wrap">
            <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Assigned Barangay</label>
            <select name="barangay_id" id="view_barangay_id" class="form-control" disabled onchange="onBarangaySelectionChanged(this.value, 'view_purok')">
              <option value="">Central Command / All Jurisdictions</option>
              <?php foreach ($barangays as $b): ?>
                <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- RESIDENT SPECIFIC FIELDS: Purok Zone -->
        <div id="view_purok_wrap" style="margin-bottom:12px;display:none;">
          <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Purok Zone</label>
          <input type="text" name="purok" id="view_purok" class="form-control" list="purokListSuggestions" disabled placeholder="e.g. Purok Riverside 1">
        </div>

        <!-- POSITION / TITLE FOR OFFICIALS AND RESPONDERS -->
        <div id="view_position_wrap" style="margin-bottom:12px;">
          <label style="font-size:10.5px;font-weight:600;display:block;margin-bottom:4px;">Position / Designation Title</label>
          <input type="text" name="position" id="view_position" class="form-control" disabled placeholder="e.g. Punong Barangay / Operations Officer">
        </div>

        <!-- RESPONDER SPECIFIC FIELDS: Agency & Availability -->
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
          <button type="button" class="btn btn-outline btn-sm" id="modalDeleteBtn"
            style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalDelete()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:3px;vertical-align:-1px;">
              <polyline points="3 6 5 6 21 6"></polyline>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            </svg>
            Delete Account
          </button>

          <button type="button" class="btn btn-outline btn-sm" id="modalRestoreBtn"
            style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalRestore()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:3px;vertical-align:-1px;">
              <polyline points="1 4 1 10 7 10"></polyline>
              <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
            </svg>
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
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:3px;vertical-align:-1px;">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
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
  let currentUserTypeInModal = 'user'; // 'user' or 'resident'
  let currentUserRoleInModal = 'barangay_head';

  const allPuroks = <?= json_encode($purokRows) ?>;

  // 1. Open Create User Modal with Role Switcher
  function openCreateUserModal(defaultRole = 'barangay_head') {
    const form = document.getElementById('createUserForm');
    if (form) form.reset();
    switchCreateRole(defaultRole);
    openModal('createUserModal');
  }

  function switchCreateRole(role) {
    const roleButtons = {
      'barangay_head': document.getElementById('btnRoleBarangayHead'),
      'responder': document.getElementById('btnRoleResponder'),
      'resident': document.getElementById('btnRoleResident'),
      'icdrrmo': document.getElementById('btnRoleIcdrmo')
    };

    // Toggle active classes on pill buttons
    Object.keys(roleButtons).forEach(r => {
      if (roleButtons[r]) {
        if (r === role) {
          roleButtons[r].classList.add('active');
        } else {
          roleButtons[r].classList.remove('active');
        }
      }
    });

    const roleInput = document.getElementById('create_form_role');
    const userTypeInput = document.getElementById('create_form_user_type');
    const modalTitle = document.getElementById('createUserModalTitle');
    const modalSubtitle = document.getElementById('createUserModalSubtitle');

    const barangaySection = document.getElementById('createBarangaySection');
    const barangayLabel = document.getElementById('createBarangayLabel');
    const barangaySelect = document.getElementById('create_barangay_id');

    const positionSection = document.getElementById('createPositionSection');
    const positionLabel = document.getElementById('createPositionLabel');
    const positionInput = document.getElementById('create_position');

    const responderSpecific = document.getElementById('createResponderSpecificFields');
    const residentSpecific = document.getElementById('createResidentSpecificFields');

    roleInput.value = role;
    userTypeInput.value = (role === 'resident') ? 'resident' : 'user';

    if (role === 'barangay_head') {
      modalTitle.innerText = 'Register New Barangay Head';
      modalSubtitle.innerText = 'Authorize a Punong Barangay or Head Official for barangay-level administration.';
      barangaySection.style.display = 'block';
      barangayLabel.innerHTML = 'Assigned Barangay Jurisdiction <span style="color:var(--color-danger);">*</span>';
      barangaySelect.required = true;
      positionSection.style.display = 'block';
      positionLabel.innerText = 'Official Position / Title';
      positionInput.placeholder = 'e.g. Punong Barangay, Barangay Kagawad';
      positionInput.value = 'Punong Barangay';
      responderSpecific.style.display = 'none';
      residentSpecific.style.display = 'none';

    } else if (role === 'responder') {
      modalTitle.innerText = 'Register New Responder Personnel';
      modalSubtitle.innerText = 'Deploy emergency rescue crew, field first aiders, and BDRRMC/CDRRMO task forces.';
      barangaySection.style.display = 'block';
      barangayLabel.innerHTML = 'Assigned Barangay Jurisdiction <span style="font-weight:normal;color:var(--color-text-muted);">(Optional for City-Wide)</span>';
      barangaySelect.required = false;
      positionSection.style.display = 'block';
      positionLabel.innerText = 'Position / Tactical Role';
      positionInput.placeholder = 'e.g. Team Lead, Search & Rescue, First Aider';
      positionInput.value = '';
      responderSpecific.style.display = 'block';
      residentSpecific.style.display = 'none';

    } else if (role === 'resident') {
      modalTitle.innerText = 'Register New Citizen Resident';
      modalSubtitle.innerText = 'Register a local resident citizen into the disaster preparedness registry.';
      barangaySection.style.display = 'block';
      barangayLabel.innerHTML = 'Assigned Barangay Jurisdiction <span style="color:var(--color-danger);">*</span>';
      barangaySelect.required = true;
      positionSection.style.display = 'none';
      responderSpecific.style.display = 'none';
      residentSpecific.style.display = 'block';

    } else if (role === 'icdrrmo') {
      modalTitle.innerText = 'Register New ICDRRMO Officer';
      modalSubtitle.innerText = 'Create Central Command admin, emergency dispatcher, or disaster operations officer account.';
      barangaySection.style.display = 'none';
      barangaySelect.required = false;
      barangaySelect.value = '';
      positionSection.style.display = 'block';
      positionLabel.innerText = 'Office Designation / Role Title';
      positionInput.placeholder = 'e.g. Operations Officer, Emergency Dispatcher';
      positionInput.value = 'ICDRRMO Officer';
      responderSpecific.style.display = 'none';
      residentSpecific.style.display = 'none';
    }
  }

  // 2. Open View / In-Place Edit Modal
  function viewUserDetails(u, roleOrType) {
    currentUserInModal = u;
    const isResident = (u.user_type === 'resident' || roleOrType === 'resident' || u.role === 'resident');
    currentUserTypeInModal = isResident ? 'resident' : 'user';
    currentUserRoleInModal = isResident ? 'resident' : (u.role || roleOrType || 'responder');

    const roleBadge = document.getElementById('viewModalRoleBadge');
    if (isResident) {
      roleBadge.innerText = 'Citizen Resident';
      roleBadge.className = 'badge badge-primary';
    } else if (currentUserRoleInModal === 'barangay_head') {
      roleBadge.innerText = 'Barangay Head';
      roleBadge.className = 'badge badge-warning';
    } else if (currentUserRoleInModal === 'responder') {
      roleBadge.innerText = 'Responder Force';
      roleBadge.className = 'badge badge-info';
    } else {
      roleBadge.innerText = 'ICDRRMO Officer';
      roleBadge.className = 'badge badge-secondary';
    }

    let badgeClass = (u.status === 'active') ? 'badge-success' : ((u.status === 'archived') ? 'badge-neutral' : 'badge-danger');
    let badgeLabel = (u.status === 'active') ? 'Active' : ((u.status === 'archived') ? 'Archived' : 'Deactivated');
    document.getElementById('viewModalStatusBadge').innerHTML = `<span class="badge ${badgeClass}">${escapeHtml(badgeLabel)}</span>`;

    // Banner Details
    const displayName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);
    document.getElementById('view_display_name').innerText = displayName;
    document.getElementById('view_avatar_initials').innerText = (u.first_name ? u.first_name[0] : (u.username ? u.username[0] : 'U')).toUpperCase();
    
    const jurisdictionLabel = u.barangay_name ? ('Brgy. ' + u.barangay_name) : 'Central Command';
    const roleText = isResident ? 'Resident' : (u.position || formatRoleName(currentUserRoleInModal));
    document.getElementById('view_meta_sub').innerText = `${roleText} • ${jurisdictionLabel} • @${u.username}`;
    document.getElementById('view_mode_badge').innerText = '(View Only)';

    // Hidden Inputs
    document.getElementById('view_user_id').value = u.id;
    document.getElementById('view_user_type').value = isResident ? 'resident' : 'user';
    document.getElementById('view_role').value = currentUserRoleInModal;

    // Populate Common Fields
    document.getElementById('view_first_name').value = u.first_name || '';
    document.getElementById('view_last_name').value = u.last_name || '';
    document.getElementById('view_username').value = u.username || '';
    document.getElementById('view_email').value = u.email || '';
    document.getElementById('view_phone').value = u.phone || '';
    document.getElementById('view_gender').value = u.gender || 'Male';
    document.getElementById('view_age').value = u.age || '';
    document.getElementById('view_barangay_id').value = u.barangay_id || '';

    // Role-specific sections
    const purokWrap = document.getElementById('view_purok_wrap');
    const positionWrap = document.getElementById('view_position_wrap');
    const respWrap = document.getElementById('view_responder_fields_wrap');

    if (isResident) {
      purokWrap.style.display = 'block';
      document.getElementById('view_purok').value = u.purok || '';
      positionWrap.style.display = 'none';
      respWrap.style.display = 'none';
    } else if (currentUserRoleInModal === 'responder') {
      purokWrap.style.display = 'none';
      positionWrap.style.display = 'block';
      document.getElementById('view_position').value = u.position || '';
      respWrap.style.display = 'block';
      if (u.agency_id) {
        document.getElementById('view_agency_id').value = u.agency_id;
      }
      document.getElementById('view_availability').value = u.availability || 'Available';
    } else {
      // Barangay Head or ICDRRMO
      purokWrap.style.display = 'none';
      positionWrap.style.display = 'block';
      document.getElementById('view_position').value = u.position || (currentUserRoleInModal === 'barangay_head' ? 'Punong Barangay' : 'ICDRRMO Officer');
      respWrap.style.display = 'none';
    }

    // Audit Info
    document.getElementById('view_created_at_val').innerText = u.created_at || 'N/A';
    document.getElementById('view_id_val').innerText = u.id;

    // Lock inputs
    setModalInputsDisabled(true);

    // Button States
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
    document.getElementById('view_password').value = '';

    openModal('viewUserModal');
  }

  // 3. In-Place Edit Mode
  function enableModalEditMode() {
    setModalInputsDisabled(false);
    document.getElementById('modalEditBtn').style.display = 'none';
    document.getElementById('modalSaveBtn').style.display = 'inline-flex';
    document.getElementById('modalCancelEditBtn').style.display = 'inline-flex';
    document.getElementById('view_password_wrap').style.display = 'block';
    document.getElementById('view_mode_badge').innerText = '(Editing Mode)';
    document.getElementById('view_first_name').focus();
  }

  function cancelModalEditMode() {
    if (currentUserInModal) {
      viewUserDetails(currentUserInModal, currentUserRoleInModal);
    }
  }

  function setModalInputsDisabled(state) {
    const fields = [
      'view_first_name', 'view_last_name', 'view_username', 'view_email',
      'view_phone', 'view_gender', 'view_age', 'view_barangay_id',
      'view_purok', 'view_position', 'view_agency_id', 'view_availability'
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

  // 8. Reusable Confirmation Modal Helper
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

  // 9. Dynamic Purok List updating when barangay changes
  function onBarangaySelectionChanged(barangayId, inputElementId) {
    const datalist = document.getElementById('purokListSuggestions');
    if (!datalist) return;
    datalist.innerHTML = '';

    const bId = parseInt(barangayId, 10);
    const filtered = bId ? allPuroks.filter(p => parseInt(p.barangay_id, 10) === bId) : allPuroks;

    filtered.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.name;
      datalist.appendChild(opt);
    });
  }

  // Helper function to escape HTML in JS strings
  function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function formatRoleName(role) {
    switch (role) {
      case 'icdrrmo': return 'ICDRRMO Officer';
      case 'barangay_head': return 'Barangay Head';
      case 'responder': return 'Responder';
      case 'resident': return 'Resident';
      default: return role || '';
    }
  }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
