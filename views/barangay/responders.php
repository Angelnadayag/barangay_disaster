<?php
// ============================================================================
// Views (Barangay Head): Responder Force Management Module
// Exact design system parity with Manage Residents (residents.php)
// Connected Agency FK, Position Attribute, Tactical Availability, In-Place Edit.
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

$viewTab = $_GET['tab'] ?? 'active';
if (!in_array($viewTab, ['active', 'archived'], true)) {
    $viewTab = 'active';
}

$filterStatus = $_GET['status'] ?? '';
$filterAvailability = $_GET['availability'] ?? '';
$filterAgency = !empty($_GET['agency_id']) ? (int)$_GET['agency_id'] : 0;
$search = trim($_GET['search'] ?? '');

// Counts for navigation tabs
$activeCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'responder' AND barangay_id = ? AND status != 'archived'");
$activeCountStmt->execute([$barangayId]);
$activeCount = (int)$activeCountStmt->fetchColumn();

$archivedCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'responder' AND barangay_id = ? AND status = 'archived'");
$archivedCountStmt->execute([$barangayId]);
$archivedCount = (int)$archivedCountStmt->fetchColumn();

// Availability KPI Counts (Active responders only)
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

// Fetch active agencies for selection and filter
$agencyStmt = $db->prepare("
    SELECT id, name, agency_type, barangay_id 
    FROM agencies 
    WHERE status = 'active'
    ORDER BY (barangay_id = ?) DESC, agency_type DESC, name ASC
");
$agencyStmt->execute([$barangayId]);
$availableAgencies = $agencyStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine default agency ID for this barangay
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

// Query responders with connected agency join
$sql = "
    SELECT u.*, b.name AS barangay_name, a.name AS agency_name, a.agency_type, a.contact_number AS agency_phone
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    LEFT JOIN agencies a ON u.agency_id = a.id
    WHERE u.role = 'responder' AND u.barangay_id = ?
";
$params = [$barangayId];

if ($viewTab === 'archived') {
    $sql .= " AND u.status = 'archived'";
} else {
    $sql .= " AND u.status != 'archived'";
    if (!empty($filterStatus)) {
        $sql .= " AND u.status = ?";
        $params[] = $filterStatus;
    }
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
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.position LIKE ? OR a.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(u.availability, 'Responding', 'On Duty', 'Available', 'Standby', 'Off Duty'), FIELD(u.status, 'active', 'pending', 'inactive', 'archived'), u.first_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$respondersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Manage Responders — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* High-clarity disabled inputs for View Mode matching residents.php */
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

/* Ensure View Modal & Create Modal are responsive and vertically scrollable matching residents.php */
#viewResponderModal.modal-overlay,
#createResponderModal.modal-overlay {
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

#viewResponderModal.modal-overlay.active,
#createResponderModal.modal-overlay.active {
  display: flex !important;
}

#viewResponderModal .modal-dialog,
#createResponderModal .modal-dialog {
  max-width: 640px !important;
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

#viewResponderModal .modal-header,
#createResponderModal .modal-header {
  flex-shrink: 0 !important;
  padding: 12px 18px !important;
  border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
  background-color: var(--color-surface, #FFFFFF) !important;
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
}

#viewResponderModal .modal-body,
#createResponderModal .modal-body {
  flex: 1 1 auto !important;
  padding: 18px 20px !important;
  overflow-y: auto !important;
  overscroll-behavior: contain !important;
}

#viewResponderModal .modal-footer,
#createResponderModal .modal-footer {
  flex-shrink: 0 !important;
  padding: 12px 20px !important;
  border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
  background-color: #FAFCFC !important;
}

/* Tab nav styling matching residents.php */
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
  padding: 2px 6px;
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

/* Availability Status Badges */
.badge-avail-available {
  background-color: var(--color-success-bg, #EAF4EB);
  color: var(--color-success, #2E7D32);
  border: 1px solid var(--color-success-border, #BCE3C1);
}
.badge-avail-onduty {
  background-color: var(--color-info-bg, #EBF3FC);
  color: var(--color-info, #1565C0);
  border: 1px solid var(--color-info-border, #B7D7FA);
}
.badge-avail-responding {
  background-color: var(--color-danger-bg, #FDE8E8);
  color: var(--color-danger, #C62828);
  border: 1px solid var(--color-danger-border, #F8B4B4);
  animation: pulseRes 1.5s infinite;
}
.badge-avail-standby {
  background-color: var(--color-warning-bg, #FEF8E8);
  color: var(--color-warning, #B78103);
  border: 1px solid var(--color-warning-border, #FCE6AA);
}
.badge-avail-offduty {
  background-color: var(--color-surface-subtle, #F0F3F4);
  color: var(--color-text-secondary, #66737D);
  border: 1px solid var(--color-border, #D9E0E3);
}

@keyframes pulseRes {
  0% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0.4); }
  70% { box-shadow: 0 0 0 4px rgba(198, 40, 40, 0); }
  100% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0); }
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}
@media (max-width: 600px) {
  .form-row { grid-template-columns: 1fr; }
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Responder Force Registry — Barangay <?= clean($barangay['name'] ?? '') ?></h1>
    <p class="page-header-desc">
      Manage registered emergency responders, rescue personnel, tactical positions, affiliated response agencies, and operational availability.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openModal('createResponderModal')">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      Register New Responder
    </button>
  </div>
</div>

<!-- Tabs Navigation matching residents.php -->
<div class="nav-tabs">
  <a href="?tab=active" class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
    Active Personnel
    <span class="nav-tab-badge"><?= $activeCount ?></span>
  </a>
  <a href="?tab=archived" class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
    Archived Personnel
    <span class="nav-tab-badge"><?= $archivedCount ?></span>
  </a>
</div>

<!-- Search & Filters matching residents.php -->
<div class="card" style="margin-bottom: var(--space-4);">
  <div class="card-body" style="padding:10px 14px;">
    <form method="GET" class="filter-bar" style="margin-bottom:0;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($viewTab) ?>">
      <div class="filter-group" style="flex-wrap:wrap;gap:8px;">
        <div class="search-input-wrap" style="min-width:240px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="search" class="form-control" placeholder="Search responder name, @username, position, phone..." value="<?= clean($search) ?>">
        </div>

        <select name="availability" class="form-control" style="width:150px;">
          <option value="">All Availabilities</option>
          <option value="Available" <?= $filterAvailability === 'Available' ? 'selected' : '' ?>>🟢 Available</option>
          <option value="On Duty" <?= $filterAvailability === 'On Duty' ? 'selected' : '' ?>>🔵 On Duty</option>
          <option value="Responding" <?= $filterAvailability === 'Responding' ? 'selected' : '' ?>>🔴 Responding</option>
          <option value="Standby" <?= $filterAvailability === 'Standby' ? 'selected' : '' ?>>🟣 Standby</option>
          <option value="Off Duty" <?= $filterAvailability === 'Off Duty' ? 'selected' : '' ?>>⚪ Off Duty</option>
        </select>

        <select name="agency_id" class="form-control" style="width:190px;">
          <option value="">All Connected Agencies</option>
          <?php foreach ($availableAgencies as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>" <?= $filterAgency === (int)$ag['id'] ? 'selected' : '' ?>>
              <?= clean($ag['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php if ($viewTab === 'active'): ?>
          <select name="status" class="form-control" style="width:130px;">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($filterStatus === 'inactive' || $filterStatus === 'deactivated') ? 'selected' : '' ?>>Deactivated</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-outline">Apply Filter</button>
        <?php if (!empty($search) || !empty($filterStatus) || !empty($filterAvailability) || !empty($filterAgency)): ?>
          <a href="?tab=<?= $viewTab ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </div>
      <div>
        <span style="font-size:10px;color:var(--color-text-muted);">
          Total: <?= count($respondersList) ?> Responder(s)
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Responders Data Table (Matches residents.php data-table structure & proportions) -->
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:230px;">Responder Name & Username</th>
        <th style="width:190px;">Position / Title</th>
        <th style="width:190px;">Connected Agency</th>
        <th style="width:140px;">Availability</th>
        <th style="width:170px;">Contact Details</th>
        <th style="width:90px;">Account Status</th>
        <th style="text-align:right;width:140px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($respondersList)): ?>
        <tr>
          <td colspan="7" style="text-align:center;padding:32px;color:var(--color-text-muted);">
            No responder personnel records found in <?= $viewTab === 'archived' ? 'Archived' : 'Active' ?> registry matching your filter criteria.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($respondersList as $r): ?>
          <?php
            $avail = $r['availability'] ?: 'Available';
            $availClass = 'badge-avail-' . strtolower(str_replace(' ', '', $avail));
            $userJson = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
            $pos = trim($r['position'] ?? '');
            if (empty($pos)) {
                $pos = 'Field Responder';
            }
          ?>
          <tr>
            <td>
              <a href="javascript:void(0)" onclick="viewResponderDetails(<?= $userJson ?>)" class="table-user-link">
                <div>
                  <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:12px;line-height:1.2;">
                    <?= clean(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['full_name'] ?? '')) ?>
                  </div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:1px;">
                    @<?= clean($r['username']) ?>
                  </div>
                </div>
              </a>
            </td>

            <td>
              <span style="font-size:11px;font-weight:600;color:var(--color-primary);">
                <?= clean($pos) ?>
              </span>
            </td>

            <td>
              <div>
                <span style="font-size:11px;font-weight:500;color:var(--color-text);">
                  <?= clean($r['agency_name'] ?: 'No Agency Linked') ?>
                </span>
                <?php if (!empty($r['agency_type'])): ?>
                  <span class="badge badge-neutral" style="font-size:8.5px;margin-left:4px;padding:1px 5px;"><?= clean($r['agency_type']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <td>
              <div style="display:inline-flex;align-items:center;gap:4px;">
                <span class="badge <?= $availClass ?>" style="font-weight:600;">
                  <?= clean($avail) ?>
                </span>
                <?php if ($viewTab === 'active'): ?>
                  <div class="dropdown" style="display:inline-block;position:relative;">
                    <button type="button" class="btn btn-outline btn-sm" style="padding:1px 5px;font-size:9px;line-height:1;height:20px;" title="Quick Switch Availability" onclick="toggleQuickAvail(event, <?= $r['id'] ?>)">⚡</button>
                    <div id="quickAvailMenu_<?= $r['id'] ?>" style="display:none;position:absolute;left:0;top:100%;margin-top:2px;background:#FFF;border:1px solid var(--color-border);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,0.15);padding:4px;z-index:100;min-width:130px;">
                      <?php foreach (['Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'] as $st): ?>
                        <a href="javascript:void(0)" 
                           onclick="setResponderAvailability(<?= $r['id'] ?>, '<?= $st ?>')"
                           style="display:block;padding:5px 8px;font-size:10.5px;font-weight:600;text-decoration:none;color:var(--color-text);border-radius:4px;<?= $avail === $st ? 'background:var(--color-surface-subtle);color:var(--color-primary);font-weight:700;' : '' ?>">
                          <?= $st ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </td>

            <td>
              <div style="font-size:11px;font-family:var(--font-secondary);color:var(--color-text);font-weight:500;">
                <?= clean($r['phone'] ?: 'None listed') ?>
              </div>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:1px;">
                <?= clean($r['email']) ?>
              </div>
            </td>

            <td>
              <?= renderStatusBadge($r['status']) ?>
            </td>

            <td style="text-align:right;">
              <div style="display:inline-flex;gap:4px;align-items:center;">
                <?php if ($r['status'] === 'archived'): ?>
                  <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-success);border-color:var(--color-success);padding:3px 8px;font-size:10.5px;" onclick="restoreResponderDirect(<?= (int)$r['id'] ?>, '<?= clean($r['username']) ?>')">
                    Restore
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;" onclick="viewResponderDetails(<?= $userJson ?>)">
                    View
                  </button>
                <?php else: ?>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;" onclick="viewResponderDetails(<?= $userJson ?>)">
                    View
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-danger);border-color:var(--color-danger);padding:3px 8px;font-size:10.5px;" onclick="archiveResponderDirect(<?= (int)$r['id'] ?>, '<?= clean($r['username']) ?>')">
                    Archive
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

<!-- Hidden Quick Availability Form for fast updates -->
<form id="quickAvailForm" method="POST" action="<?= BASE_URL ?>/backend/functions/responders/quick_availability.php" style="display:none;">
  <input type="hidden" name="responder_id" id="quickAvailResponderId">
  <input type="hidden" name="availability" id="quickAvailStatus">
  <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
</form>

<!-- Datalist for Position Titles -->
<datalist id="positionSuggestions">
  <option value="Team Alpha Rescue Leader">
  <option value="Search and Rescue (SAR) Operative">
  <option value="Emergency Medical Technician (EMT)">
  <option value="Paramedic / First Responder">
  <option value="Fire & Hazard Specialist">
  <option value="Disaster Evacuation Marshal">
  <option value="Incident Command Liaison">
  <option value="Operations & Logistics Officer">
  <option value="Safety & Communications Lead">
  <option value="BDRRMC Quick Reaction Force">
</datalist>

<!-- ========================================================================= -->
<!-- Modal 1: Register New Responder Modal (Matches residents.php createUserModal) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createResponderModal">
  <form class="modal-dialog" id="createResponderForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php" style="max-width:620px;">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="role" value="responder">
    <input type="hidden" name="user_type" value="user">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <h3 class="modal-title">Register Responder Personnel</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('createResponderModal')">&times;</button>
    </div>

    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">First Name</label>
          <input type="text" name="first_name" class="form-control" placeholder="e.g. Kurt" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Last Name</label>
          <input type="text" name="last_name" class="form-control" placeholder="e.g. Morales" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Username</label>
          <input type="text" name="username" class="form-control" placeholder="e.g. responder_kurt" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="e.g. kurt@rescue.gov.ph" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="text" name="phone" class="form-control" placeholder="+63 9XX XXX XXXX">
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Tactical Availability</label>
          <select name="availability" class="form-control" required>
            <option value="Available" selected>🟢 Available (Standby & Ready)</option>
            <option value="On Duty">🔵 On Duty (Active Shift)</option>
            <option value="Responding">🔴 Responding (On Mission)</option>
            <option value="Standby">🟣 Standby (Reserve)</option>
            <option value="Off Duty">⚪ Off Duty</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Position / Role Title</label>
          <input type="text" name="position" class="form-control" placeholder="e.g. Team Alpha Rescue Leader" list="positionSuggestions" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Connected Agency</label>
          <select name="agency_id" class="form-control" required>
            <?php foreach ($availableAgencies as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>" <?= ((int)$ag['id'] === $defaultAgencyId) ? 'selected' : '' ?>>
                <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-control">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Age</label>
          <input type="number" name="age" min="18" max="75" class="form-control" placeholder="e.g. 28">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assigned Barangay</label>
          <input type="text" class="form-control" value="Barangay <?= clean($barangay['name']) ?>" disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Initial Password</label>
          <input type="password" name="password" class="form-control" value="admin123" required>
        </div>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('createResponderModal')">Cancel</button>
      <button type="submit" class="btn btn-primary">Register Responder</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Responder Details (Matches residents.php viewUserModal) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewResponderModal">
  <form class="modal-dialog" id="viewResponderForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/update.php">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="user_id" id="view_user_id">
    <input type="hidden" name="role" value="responder">
    <input type="hidden" name="user_type" value="user">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewResponderModal')" title="Close">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Section 1: Responder Identity Banner -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);">
        <div id="view_profile_display_name" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
        <div id="view_profile_meta_sub" style="font-size:10px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Section 2: Heading "Responder Details" -->
      <div style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading" style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          Responder Details
        </h4>
        <span id="viewModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:500;">(View Only Mode)</span>
      </div>

      <!-- Form Inputs (Disabled on View, Enabled on Edit) -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">First Name</label>
          <input type="text" name="first_name" id="view_first_name" class="form-control" required disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Last Name</label>
          <input type="text" name="last_name" id="view_last_name" class="form-control" required disabled>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Username</label>
          <input type="text" name="username" id="view_username" class="form-control" required disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Email Address</label>
          <input type="email" name="email" id="view_email" class="form-control" required disabled>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="text" name="phone" id="view_phone" class="form-control" disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Tactical Availability</label>
          <select name="availability" id="view_availability" class="form-control" disabled required>
            <option value="Available">🟢 Available (Ready for Mission)</option>
            <option value="On Duty">🔵 On Duty (Active Station Shift)</option>
            <option value="Responding">🔴 Responding (Deployed)</option>
            <option value="Standby">🟣 Standby (Reserve)</option>
            <option value="Off Duty">⚪ Off Duty</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Position / Role Title</label>
          <input type="text" name="position" id="view_position" class="form-control" list="positionSuggestions" required disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Connected Agency</label>
          <select name="agency_id" id="view_agency_id" class="form-control" disabled required>
            <?php foreach ($availableAgencies as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>">
                <?= clean($ag['name']) ?> (<?= clean($ag['agency_type']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Gender</label>
          <select name="gender" id="view_gender" class="form-control" disabled>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Age</label>
          <input type="number" name="age" id="view_age" min="18" max="75" class="form-control" disabled>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Barangay</label>
          <input type="text" class="form-control" value="Barangay <?= clean($barangay['name']) ?>" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Password <span id="view_password_label_hint" style="font-size:9.5px;color:var(--color-text-muted);font-weight:normal;">(Encrypted)</span></label>
          <input type="password" name="password" id="view_password" class="form-control" placeholder="(Leave blank to keep current)" disabled>
          <small id="view_password_help" style="font-size:9px;color:var(--color-text-muted);display:none;margin-top:2px;">Leave blank if not changing the password.</small>
        </div>
      </div>

      <!-- Metadata footer -->
      <div style="font-size:10px;color:var(--color-text-muted);margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border-light);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
        <div><strong style="color:var(--color-text);">Registered:</strong> <span id="view_created_at_text"></span></div>
        <div><strong style="color:var(--color-text);">Last Login:</strong> <span id="view_last_login_text"></span></div>
      </div>
    </div>

    <!-- Action Footer (Matches residents.php view modal footer) -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left: Delete & Restore Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" id="modalDeleteBtn" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalDelete()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Archive Account
        </button>

        <button type="button" class="btn btn-outline btn-sm" id="modalRestoreBtn" style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalRestore()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
          Restore Personnel
        </button>
      </div>

      <!-- Right: Status Toggle, Edit / Save, Cancel, Close Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" id="modalToggleStatusBtn" onclick="onModalToggleStatus()">
          Deactivate Account
        </button>

        <button type="button" class="btn btn-secondary btn-sm" id="modalEditBtn" onclick="enableModalEditMode()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit Details
        </button>

        <button type="submit" class="btn btn-primary btn-sm" id="modalSaveBtn" style="display:none;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
          Save Changes
        </button>

        <button type="button" class="btn btn-outline btn-sm" id="modalCancelEditBtn" style="display:none;" onclick="cancelModalEditMode()">
          Cancel Edit
        </button>

        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewResponderModal')">
          Close
        </button>
      </div>
    </div>
  </form>
</div>

<script>
let currentResponderInModal = null;
let isEditMode = false;

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatDateDisplay(dt) {
  if (!dt) return 'Never / Not logged';
  const d = new Date(dt);
  if (isNaN(d.getTime())) return dt;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function viewResponderDetails(r) {
  currentResponderInModal = r;
  isEditMode = false;

  setModalInputsDisabled(true);

  // Identity Banner
  const displayName = ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || (r.full_name || r.username);
  document.getElementById('view_profile_display_name').innerText = displayName;
  document.getElementById('view_profile_meta_sub').innerHTML = `
    <strong>@${escapeHtml(r.username)}</strong> &bull;
    Personnel ID #${r.id} &bull;
    Position: <strong>${escapeHtml(r.position || 'Field Responder')}</strong> &bull;
    Agency: <strong>${escapeHtml(r.agency_name || 'BDRRMC')}</strong>
  `;

  // Status Badge
  const badgeWrap = document.getElementById('viewModalStatusBadge');
  if (r.status === 'active') {
    badgeWrap.innerHTML = '<span class="badge badge-success">Active Account</span>';
  } else if (r.status === 'archived') {
    badgeWrap.innerHTML = '<span class="badge badge-neutral">Archived Personnel</span>';
  } else if (r.status === 'inactive' || r.status === 'deactivated') {
    badgeWrap.innerHTML = '<span class="badge badge-danger">Deactivated</span>';
  } else {
    badgeWrap.innerHTML = `<span class="badge badge-warning">${escapeHtml(r.status || 'Pending')}</span>`;
  }

  // Populate form fields
  document.getElementById('view_user_id').value = r.id;
  document.getElementById('view_first_name').value = r.first_name || '';
  document.getElementById('view_last_name').value = r.last_name || '';
  document.getElementById('view_username').value = r.username || '';
  document.getElementById('view_email').value = r.email || '';
  document.getElementById('view_phone').value = r.phone || '';
  document.getElementById('view_availability').value = r.availability || 'Available';
  document.getElementById('view_position').value = r.position || '';
  if (r.agency_id) {
    document.getElementById('view_agency_id').value = r.agency_id;
  }
  document.getElementById('view_gender').value = r.gender || 'Male';
  document.getElementById('view_age').value = r.age || '';
  document.getElementById('view_password').value = '';

  // Metadata
  document.getElementById('view_created_at_text').innerText = formatDateDisplay(r.created_at);
  document.getElementById('view_last_login_text').innerText = formatDateDisplay(r.last_login);

  // Section Heading
  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
    Responder Details
  `;
  document.getElementById('viewModeHintText').innerHTML = `(View Only Mode)`;

  // Delete & Restore buttons state
  const delBtn = document.getElementById('modalDeleteBtn');
  const restBtn = document.getElementById('modalRestoreBtn');
  if (r.status === 'archived') {
    delBtn.style.display = 'none';
    restBtn.style.display = 'inline-flex';
  } else {
    delBtn.style.display = 'inline-flex';
    restBtn.style.display = 'none';
  }

  // Toggle Status button
  const toggleStatusBtn = document.getElementById('modalToggleStatusBtn');
  if (r.status === 'archived') {
    toggleStatusBtn.style.display = 'none';
  } else {
    toggleStatusBtn.style.display = 'inline-flex';
    if (r.status === 'active') {
      toggleStatusBtn.innerText = 'Deactivate Account';
      toggleStatusBtn.style.color = 'var(--color-danger)';
      toggleStatusBtn.style.borderColor = 'var(--color-danger)';
    } else {
      toggleStatusBtn.innerText = 'Reactivate Account';
      toggleStatusBtn.style.color = 'var(--color-success)';
      toggleStatusBtn.style.borderColor = 'var(--color-success)';
    }
  }

  // Edit / Save Buttons State
  document.getElementById('modalEditBtn').style.display = 'inline-flex';
  document.getElementById('modalSaveBtn').style.display = 'none';
  document.getElementById('modalCancelEditBtn').style.display = 'none';
  document.getElementById('view_password_help').style.display = 'none';
  document.getElementById('view_password_label_hint').innerText = '(Encrypted)';

  openModal('viewResponderModal');
  const vBody = document.querySelector('#viewResponderModal .modal-body');
  if (vBody) vBody.scrollTop = 0;
}

function setModalInputsDisabled(disabled) {
  document.getElementById('view_first_name').disabled = disabled;
  document.getElementById('view_last_name').disabled = disabled;
  document.getElementById('view_username').disabled = disabled;
  document.getElementById('view_email').disabled = disabled;
  document.getElementById('view_phone').disabled = disabled;
  document.getElementById('view_availability').disabled = disabled;
  document.getElementById('view_position').disabled = disabled;
  document.getElementById('view_agency_id').disabled = disabled;
  document.getElementById('view_gender').disabled = disabled;
  document.getElementById('view_age').disabled = disabled;
  document.getElementById('view_password').disabled = disabled;
}

function enableModalEditMode() {
  if (!currentResponderInModal) return;
  isEditMode = true;
  setModalInputsDisabled(false);

  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
    Edit Responder Details (@${escapeHtml(currentResponderInModal.username)})
  `;
  document.getElementById('viewModeHintText').innerHTML = `<span style="color:var(--color-primary);font-weight:600;">(Editing Mode)</span>`;
  document.getElementById('view_password_help').style.display = 'block';
  document.getElementById('view_password_label_hint').innerText = '(Leave blank to keep current)';
  document.getElementById('view_password').placeholder = 'Enter new password (optional)';

  document.getElementById('modalEditBtn').style.display = 'none';
  document.getElementById('modalSaveBtn').style.display = 'inline-flex';
  document.getElementById('modalCancelEditBtn').style.display = 'inline-flex';

  document.getElementById('view_first_name').focus();
}

function cancelModalEditMode() {
  if (!currentResponderInModal) return;
  viewResponderDetails(currentResponderInModal);
}

function onModalDelete() {
  if (!currentResponderInModal) return;
  archiveResponderDirect(currentResponderInModal.id, currentResponderInModal.username);
}

function onModalRestore() {
  if (!currentResponderInModal) return;
  restoreResponderDirect(currentResponderInModal.id, currentResponderInModal.username);
}

function archiveResponderDirect(userId, username) {
  showConfirmModal(
    'Confirm Account Archival',
    `Are you sure you want to archive responder account @${username}? The personnel will be moved to Archived Personnel.`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('user_type', 'user');

      const res = await fetch(`${BASE_URL}/backend/functions/users/archive.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'Responder account archived successfully.', 'success');
        closeModal('viewResponderModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Archival failed.', 'danger');
      }
    }
  );
}

function restoreResponderDirect(userId, username) {
  showConfirmModal(
    'Confirm Account Restoration',
    `Are you sure you want to restore responder account @${username} back to active personnel status?`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('user_type', 'user');

      const res = await fetch(`${BASE_URL}/backend/functions/users/restore.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'Responder account restored to active.', 'success');
        closeModal('viewResponderModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Restoration failed.', 'danger');
      }
    }
  );
}

function onModalToggleStatus() {
  if (!currentResponderInModal) return;
  const u = currentResponderInModal;

  const isCurrentlyActive = (u.status === 'active');
  const newStatus = isCurrentlyActive ? 'inactive' : 'active';
  const actionTitle = isCurrentlyActive ? 'Confirm Account Deactivation' : 'Confirm Account Reactivation';
  const actionMsg = isCurrentlyActive
    ? `Are you sure you want to deactivate responder account @${u.username}?`
    : `Are you sure you want to reactivate responder account @${u.username}?`;

  showConfirmModal(
    actionTitle,
    actionMsg,
    async () => {
      const fd = new FormData();
      fd.append('user_id', u.id);
      fd.append('user_type', 'user');
      fd.append('status', newStatus);

      const res = await fetch(`${BASE_URL}/backend/functions/users/update_status.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(isCurrentlyActive ? 'Responder account has been deactivated.' : 'Responder account has been reactivated.', 'success');
        closeModal('viewResponderModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Status toggle failed.', 'danger');
      }
    }
  );
}

// Quick Availability Switcher
function toggleQuickAvail(event, id) {
  event.stopPropagation();
  document.querySelectorAll('[id^="quickAvailMenu_"]').forEach(el => {
    if (el.id !== 'quickAvailMenu_' + id) el.style.display = 'none';
  });

  const menu = document.getElementById('quickAvailMenu_' + id);
  if (menu) {
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
  }
}

document.addEventListener('click', function() {
  document.querySelectorAll('[id^="quickAvailMenu_"]').forEach(el => el.style.display = 'none');
});

function setResponderAvailability(responderId, status) {
  document.getElementById('quickAvailResponderId').value = responderId;
  document.getElementById('quickAvailStatus').value = status;
  document.getElementById('quickAvailForm').submit();
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
