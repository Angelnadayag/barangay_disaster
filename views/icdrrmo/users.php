<?php
// ============================================================================
// Views (ICDRRMO): User Management & RBAC Module
// Name, Role, Barangay, Contact, Status, Action
// Attributes: image (nullable), gender, age, full_name, username, email, phone, role
// Features: Create, View (Disabled Inputs), In-Place Edit, Activate/Deactivate,
//           Automatic Archival on Delete, Reactivate Button on Deactivated Rows,
//           Scrollable & Responsive Modal with Transferred User Details Heading
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$currentUserId = (int)$user['id'];
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "System User Management";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$viewTab = $_GET['tab'] ?? 'active';
if (!in_array($viewTab, ['active', 'archived'], true)) {
    $viewTab = 'active';
}

$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterBarangay = $_GET['barangay'] ?? '';
$search = trim($_GET['search'] ?? '');

// Counts for navigation tabs
$activeCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE status != 'archived'")->fetchColumn();
$archivedCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'archived'")->fetchColumn();

$sql = "
    SELECT u.*, b.name AS barangay_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    WHERE 1=1
";
$params = [];

if ($viewTab === 'archived') {
    $sql .= " AND u.status = 'archived'";
} else {
    $sql .= " AND u.status != 'archived'";
    if (!empty($filterStatus)) {
        $sql .= " AND u.status = ?";
        $params[] = $filterStatus;
    }
}

if (!empty($filterRole)) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}
if (!empty($filterBarangay)) {
    $sql .= " AND u.barangay_id = ?";
    $params[] = $filterBarangay;
}

if (!empty($search)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(u.status, 'pending', 'active', 'inactive', 'archived'), u.role ASC, u.first_name ASC, u.last_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

/* Ensure View Modal & Create Modal are fully responsive, vertically scrollable, and fit any viewport height */
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

#viewUserModal .modal-header,
#createUserModal .modal-header {
  flex-shrink: 0 !important;
  padding: 12px 18px !important;
  border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
  background-color: var(--color-surface, #FFFFFF) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  min-height: 44px !important;
}

#viewUserModal .modal-body,
#createUserModal .modal-body {
  flex: 1 1 auto !important;
  min-height: 0 !important;
  max-height: calc(100vh - 150px) !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  -webkit-overflow-scrolling: touch !important;
  overscroll-behavior: contain !important;
  padding: 16px 20px !important;
  scroll-behavior: smooth !important;
}

#viewUserModal .modal-footer,
#createUserModal .modal-footer {
  flex-shrink: 0 !important;
  background-color: var(--color-surface-subtle, #F0F3F4) !important;
  padding: 10px 18px !important;
  border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
}

/* Custom sleek scrollbar for modal body so users clearly see and can drag the scrollbar */
#viewUserModal .modal-body::-webkit-scrollbar,
#createUserModal .modal-body::-webkit-scrollbar {
  width: 7px;
}
#viewUserModal .modal-body::-webkit-scrollbar-track,
#createUserModal .modal-body::-webkit-scrollbar-track {
  background: var(--color-surface-subtle, #F0F3F4);
  border-radius: 4px;
}
#viewUserModal .modal-body::-webkit-scrollbar-thumb,
#createUserModal .modal-body::-webkit-scrollbar-thumb {
  background: var(--color-secondary-light, #3D8C91);
  border-radius: 4px;
}
#viewUserModal .modal-body::-webkit-scrollbar-thumb:hover,
#createUserModal .modal-body::-webkit-scrollbar-thumb:hover {
  background: var(--color-secondary, #2F6F73);
}

/* Low viewport height responsiveness (e.g. 593px laptop window) */
@media (max-height: 680px) {
  #viewUserModal.modal-overlay,
  #createUserModal.modal-overlay {
    align-items: flex-start !important;
    padding: 8px !important;
  }
  #viewUserModal .modal-dialog,
  #createUserModal .modal-dialog {
    max-height: calc(100vh - 16px) !important;
  }
  #viewUserModal .modal-body,
  #createUserModal .modal-body {
    max-height: calc(100vh - 110px) !important;
    padding: 10px 14px !important;
  }
  #viewUserModal .modal-header,
  #createUserModal .modal-header,
  #viewUserModal .modal-footer,
  #createUserModal .modal-footer {
    padding: 8px 14px !important;
  }
}

@media (max-width: 540px) {
  #viewUserModal .form-row,
  #createUserModal .form-row {
    flex-direction: column !important;
    gap: 8px !important;
  }
  #viewUserModal .form-row .form-group,
  #createUserModal .form-row .form-group {
    min-width: 100% !important;
  }
  #viewUserModal .modal-footer {
    flex-direction: column !important;
    align-items: stretch !important;
  }
  #viewUserModal .modal-footer > div {
    justify-content: flex-end !important;
  }
}
</style>

<div class="page-header">
    <div class="page-header-title-wrap">
      <h1>System User & Role Management</h1>
      <p class="page-header-desc">
        Manage authorized personnel, role permissions, demographic attributes, account statuses, and system archives.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openModal('createUserModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add User Account
      </button>
    </div>
  </div>

  <!-- Navigation Tabs: Active vs Archived -->
  <div style="display:flex;gap:8px;margin-bottom:var(--space-3);align-items:center;">
    <a href="<?= BASE_URL ?>/views/icdrrmo/users.php?tab=active" class="btn btn-sm <?= $viewTab === 'active' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
      Active Accounts (<?= $activeCount ?>)
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/users.php?tab=archived" class="btn btn-sm <?= $viewTab === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;<?= $viewTab === 'archived' ? '' : 'color:var(--color-text-muted);' ?>">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
      Archived Accounts (<?= $archivedCount ?>)
    </a>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">

        <div class="filter-group">
          <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search name, username, email..." value="<?= clean($search) ?>">
          </div>

          <select name="role" class="form-control" style="width:140px;">
            <option value="">All Roles</option>
            <option value="icdrrmo" <?= $filterRole === 'icdrrmo' ? 'selected' : '' ?>>ICDRRMO Admin</option>
            <option value="barangay_head" <?= $filterRole === 'barangay_head' ? 'selected' : '' ?>>Barangay Head</option>
            <option value="responder" <?= $filterRole === 'responder' ? 'selected' : '' ?>>Responder</option>
            <option value="resident" <?= $filterRole === 'resident' ? 'selected' : '' ?>>Resident</option>
          </select>

          <select name="barangay" class="form-control" style="width:140px;">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <?php if ($viewTab !== 'archived'): ?>
            <select name="status" class="form-control" style="width:130px;">
              <option value="">All Statuses</option>
              <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
              <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline">Apply Filter</button>
          <?php if (!empty($search) || !empty($filterRole) || !empty($filterStatus) || !empty($filterBarangay)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/users.php?tab=<?= clean($viewTab) ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10px;color:var(--color-text-muted);">
            Total: <?= count($usersList) ?> User(s)
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Users DataTable -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Role</th>
          <th>Barangay</th>
          <th>Contact</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($usersList)): ?>
          <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--color-text-muted);">
            <?= $viewTab === 'archived' ? 'No archived user accounts found.' : 'No user accounts found matching your query.' ?>
          </td></tr>
        <?php else: ?>
          <?php foreach ($usersList as $u): ?>
            <tr>
              <td>
                <div>
                  <a href="javascript:void(0)" class="table-user-link" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($u)) ?>)" title="Click to view user details">
                    <div class="table-user-name" style="font-weight:600;color:var(--color-primary);font-size:12.5px;">
                      <?= clean(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['full_name'] ?? '')) ?>
                    </div>
                    <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;">
                      @<?= clean($u['username']) ?> &bull; <?= clean($u['gender'] ?: 'Male') ?><?= !empty($u['age']) ? ', ' . clean($u['age']) . ' yrs' : '' ?>
                    </div>
                  </a>
                </div>
              </td>
              <td>
                <span class="badge badge-neutral" style="font-size:8px;">
                  <?= clean(formatRoleName($u['role'])) ?>
                </span>
              </td>
              <td>
                <div><?= clean($u['barangay_name'] ?: 'City Central') ?></div>
                <?php if (!empty($u['purok'])): ?>
                  <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($u['purok']) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:10px;">
                <div><?= clean($u['email']) ?></div>
                <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($u['phone']) ?></div>
              </td>
              <td><?= renderStatusBadge($u['status']) ?></td>
              <td>
                <div style="display:flex;gap:4px;">
                  <?php if ($viewTab === 'archived' || $u['status'] === 'archived'): ?>
                    <button class="btn btn-outline btn-sm" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($u)) ?>)">
                      View
                    </button>
                    <button class="btn btn-outline btn-sm" style="color:var(--color-success);border-color:var(--color-success);" onclick="restoreUserDirect(<?= $u['id'] ?>, '<?= clean($u['username']) ?>')">
                      Restore
                    </button>
                  <?php elseif ($u['status'] === 'inactive'): ?>
                    <!-- If account is deactivated, change button from View to Reactivate -->
                    <button class="btn btn-outline btn-sm" style="color:var(--color-success);border-color:var(--color-success);font-weight:600;" onclick="reactivateUser(<?= $u['id'] ?>, '<?= clean($u['username']) ?>')">
                      Reactivate
                    </button>
                  <?php else: ?>
                    <button class="btn btn-outline btn-sm" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($u)) ?>)">
                      View
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

<!-- Modal: Create User (Responsive & Scrollable) -->
<div class="modal-overlay" id="createUserModal">
  <form class="modal-dialog" id="createUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php" style="max-width:620px;">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <div class="modal-header">
      <h3 class="modal-title">Create User Account</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('createUserModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">First Name</label>
          <input type="text" name="first_name" class="form-control" placeholder="e.g. Maria" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Last Name</label>
          <input type="text" name="last_name" class="form-control" placeholder="e.g. Santos" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Username</label>
          <input type="text" name="username" class="form-control" placeholder="e.g. maria_santos" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="e.g. maria@iligan.gov.ph" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="text" name="phone" class="form-control" placeholder="+63 9XX XXX XXXX">
        </div>
        <div class="form-group">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-control">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Age</label>
          <input type="number" name="age" min="1" max="120" class="form-control" placeholder="e.g. 32">
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Assigned System Role</label>
          <select name="role" class="form-control" required>
            <option value="resident">Resident</option>
            <option value="responder">Responder / Field Crew</option>
            <option value="barangay_head">Barangay Head</option>
            <option value="icdrrmo">ICDRRMO Officer / Admin</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assigned Barangay</label>
          <select name="barangay_id" class="form-control">
            <option value="">Central Office / All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Purok / Unit</label>
          <input type="text" name="purok" class="form-control" placeholder="e.g. Purok Riverside 1">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Initial Password</label>
          <input type="password" name="password" class="form-control" value="admin123" required>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('createUserModal')">Cancel</button>
      <button type="submit" class="btn btn-primary">Create Account</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal: View User Details (100% Scrollable & Responsive)                   -->
<!-- Headings 'User Details' transferred strictly to Section 2 after Avatar    -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewUserModal">
  <form class="modal-dialog" id="viewUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/update.php">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="user_id" id="view_user_id">

    <!-- Modal Header -->
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewUserModal')" title="Close">&times;</button>
    </div>

    <!-- Modal Body (Vertically Scrollable with Custom Scrollbar) -->
    <div class="modal-body">
      <!-- Section 1: User Identity Banner -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);">
        <div id="view_profile_display_name" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
        <div id="view_profile_meta_sub" style="font-size:10px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Section 2: Heading "User Details" -->
      <div style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading" style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          User Details
        </h4>
        <span id="viewModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:500;">(View Only Mode)</span>
      </div>

      <!-- Form Input Fields (Disabled on View, Enabled on Edit) -->
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
          <label class="form-label">Gender</label>
          <select name="gender" id="view_gender" class="form-control" disabled>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Age</label>
          <input type="number" name="age" id="view_age" min="1" max="120" class="form-control" placeholder="e.g. 35" disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">System Role</label>
          <select name="role" id="view_role" class="form-control" required disabled>
            <option value="resident">Resident</option>
            <option value="responder">Responder / Field Crew</option>
            <option value="barangay_head">Barangay Head</option>
            <option value="icdrrmo">ICDRRMO Officer / Admin</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assigned Barangay</label>
          <select name="barangay_id" id="view_barangay_id" class="form-control" disabled>
            <option value="">Central Office / All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Purok / Unit</label>
          <input type="text" name="purok" id="view_purok" class="form-control" placeholder="e.g. Purok Riverside 1" disabled>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Password <span id="view_password_label_hint" style="font-size:9.5px;color:var(--color-text-muted);font-weight:normal;">(Encrypted)</span></label>
          <input type="password" name="password" id="view_password" class="form-control" placeholder="(Leave blank to keep current)" disabled>
          <small id="view_password_help" style="font-size:9px;color:var(--color-text-muted);display:none;margin-top:2px;">Leave blank if not changing the password.</small>
        </div>
      </div>

      <!-- Metadata footer info strip -->
      <div style="font-size:10px;color:var(--color-text-muted);margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border-light);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
        <div><strong style="color:var(--color-text);">Registered:</strong> <span id="view_created_at_text"></span></div>
        <div><strong style="color:var(--color-text);">Last Login:</strong> <span id="view_last_login_text"></span></div>
      </div>
    </div>

    <!-- Action Footer (Pinned at bottom, never cut off) -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left: Delete & Restore Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Delete Button (Automatically stores account into archives) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalDeleteBtn" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalDelete()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete Account
        </button>

        <!-- Restore Button (visible when viewing an archived account) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalRestoreBtn" style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalRestore()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
          Restore Account
        </button>
      </div>

      <!-- Right: Status Toggle, Edit / Save, Cancel, Close Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Deactivate / Reactivate Button -->
        <button type="button" class="btn btn-outline btn-sm" id="modalToggleStatusBtn" onclick="onModalToggleStatus()">
          Deactivate Account
        </button>

        <!-- Edit Button (removes disabled from inputs so user can edit na) -->
        <button type="button" class="btn btn-secondary btn-sm" id="modalEditBtn" onclick="enableModalEditMode()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit
        </button>

        <!-- Cancel Edit Button (restores disabled and original values) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalCancelEditBtn" style="display:none;" onclick="cancelModalEditMode()">
          Cancel Edit
        </button>

        <!-- Save Changes Button (submits the form) -->
        <button type="submit" form="viewUserForm" class="btn btn-primary btn-sm" id="modalSaveBtn" style="display:none;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
          Save Changes
        </button>

        <!-- Close Modal Button -->
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewUserModal')">
          Close
        </button>
      </div>
    </div>
  </form>
</div>

<script>
const CURRENT_LOGGED_IN_USER_ID = <?= $currentUserId ?>;
let currentUserInModal = null;
let isEditMode = false;

// Format Role Display Name
function formatRoleName(role) {
  if (role === 'icdrrmo') return 'ICDRRMO Admin';
  if (role === 'barangay_head') return 'Barangay Head';
  if (role === 'responder') return 'Responder';
  if (role === 'resident') return 'Resident';
  return role ? role.replace('_', ' ') : '';
}

// 1. Create User
async function handleUserCreate(e) {
  e.preventDefault();
  const form = document.getElementById('createUserForm');
  const res = await fetch(`${BASE_URL}/backend/functions/users/create.php`, {
    method: 'POST',
    body: new FormData(form)
  });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    closeModal('createUserModal');
    setTimeout(() => window.location.reload(), 700);
  } else {
    showToast(data.message || 'Creation failed.', 'danger');
  }
}

// 2. Open View Modal with Disabled Form Inputs
function viewUserDetails(u) {
  currentUserInModal = u;
  isEditMode = false;

  // Header Status Badge
  let badgeClass = 'badge-neutral';
  let badgeLabel = u.status;
  if (u.status === 'active') {
    badgeClass = 'badge-success';
    badgeLabel = 'Active';
  } else if (u.status === 'inactive' || u.status === 'deactivated') {
    badgeClass = 'badge-danger';
    badgeLabel = 'Deactivated';
  } else if (u.status === 'pending') {
    badgeClass = 'badge-warning';
    badgeLabel = 'Pending';
  } else if (u.status === 'archived') {
    badgeClass = 'badge-neutral';
    badgeLabel = 'Archived';
  }
  document.getElementById('viewModalStatusBadge').innerHTML = `<span class="badge ${badgeClass}" style="text-transform:capitalize;">${escapeHtml(badgeLabel)}</span>`;

  // Transferred Section 2 Heading
  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
    User Details (@${escapeHtml(u.username)})
  `;
  document.getElementById('viewModeHintText').innerHTML = `(View Only Mode)`;

  // Top Profile Banner
  const displayName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);
  document.getElementById('view_profile_display_name').innerText = displayName;
  let metaParts = [];
  if (u.gender) metaParts.push(u.gender);
  if (u.age) metaParts.push(`${u.age} yrs`);
  metaParts.push(formatRoleName(u.role));
  document.getElementById('view_profile_meta_sub').innerText = metaParts.join(' • ');

  // Populate Input Values
  document.getElementById('view_user_id').value = u.id;
  document.getElementById('view_first_name').value = u.first_name || '';
  document.getElementById('view_last_name').value = u.last_name || '';
  document.getElementById('view_username').value = u.username;
  document.getElementById('view_email').value = u.email;
  document.getElementById('view_phone').value = u.phone || '';
  document.getElementById('view_gender').value = u.gender || 'Male';
  document.getElementById('view_age').value = u.age || '';
  document.getElementById('view_role').value = u.role;
  document.getElementById('view_barangay_id').value = u.barangay_id || '';
  document.getElementById('view_purok').value = u.purok || '';
  document.getElementById('view_password').value = '';

  // Metadata timestamps
  document.getElementById('view_created_at_text').innerText = u.created_at || 'N/A';
  document.getElementById('view_last_login_text').innerText = u.last_login || 'Never';

  // Lock all inputs to disabled on view
  setModalInputsDisabled(true);

  // Configure Button States
  const isSelf = (parseInt(u.id) === CURRENT_LOGGED_IN_USER_ID);

  // Delete Button is ALWAYS displayed
  const deleteBtn = document.getElementById('modalDeleteBtn');
  deleteBtn.style.display = 'inline-flex';
  if (isSelf) {
    deleteBtn.title = "Cannot delete your own active session account.";
    deleteBtn.style.opacity = '0.5';
  } else {
    deleteBtn.title = "Delete this user account (automatically moves to Archived Accounts)";
    deleteBtn.style.opacity = '1';
  }

  // Restore Button (visible only when viewing archived accounts)
  const restoreBtn = document.getElementById('modalRestoreBtn');
  if (u.status === 'archived') {
    restoreBtn.style.display = 'inline-flex';
  } else {
    restoreBtn.style.display = 'none';
  }

  // Deactivate / Reactivate Button
  const toggleStatusBtn = document.getElementById('modalToggleStatusBtn');
  if (u.status === 'archived') {
    toggleStatusBtn.style.display = 'none';
  } else {
    toggleStatusBtn.style.display = 'inline-flex';
    if (isSelf) {
      toggleStatusBtn.title = "Cannot deactivate your own active session account.";
      toggleStatusBtn.disabled = true;
      toggleStatusBtn.style.opacity = '0.5';
    } else {
      toggleStatusBtn.disabled = false;
      toggleStatusBtn.style.opacity = '1';
      if (u.status === 'active') {
        toggleStatusBtn.innerText = 'Deactivate Account';
        toggleStatusBtn.style.color = 'var(--color-danger)';
        toggleStatusBtn.style.borderColor = 'var(--color-danger)';
      } else {
        toggleStatusBtn.innerText = 'Reactivate Account';
        toggleStatusBtn.style.color = 'var(--color-success)';
        toggleStatusBtn.style.borderColor = 'var(--color-success)';
      }
    }
  }

  // Edit / Save Buttons State
  document.getElementById('modalEditBtn').style.display = 'inline-flex';
  document.getElementById('modalSaveBtn').style.display = 'none';
  document.getElementById('modalCancelEditBtn').style.display = 'none';
  document.getElementById('view_password_help').style.display = 'none';
  document.getElementById('view_password_label_hint').innerText = '(Encrypted)';

  openModal('viewUserModal');
  const vBody = document.querySelector('#viewUserModal .modal-body');
  if (vBody) vBody.scrollTop = 0;
}

// 3. Helper: Toggle disabled state on all modal inputs
function setModalInputsDisabled(disabled) {
  document.getElementById('view_first_name').disabled = disabled;
  document.getElementById('view_last_name').disabled = disabled;
  document.getElementById('view_username').disabled = disabled;
  document.getElementById('view_email').disabled = disabled;
  document.getElementById('view_phone').disabled = disabled;
  document.getElementById('view_gender').disabled = disabled;
  document.getElementById('view_age').disabled = disabled;
  document.getElementById('view_role').disabled = disabled;
  document.getElementById('view_barangay_id').disabled = disabled;
  document.getElementById('view_purok').disabled = disabled;
  document.getElementById('view_password').disabled = disabled;
}

// 4. Enable Edit Mode (Removes disabled state from inputs so user can edit na)
function enableModalEditMode() {
  if (!currentUserInModal) return;
  isEditMode = true;

  // Remove disabled from inputs
  setModalInputsDisabled(false);

  // Update header and helper hints
  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
    Edit User Details (@${escapeHtml(currentUserInModal.username)})
  `;
  document.getElementById('viewModeHintText').innerHTML = `<span style="color:var(--color-primary);font-weight:600;">(Editing Mode)</span>`;
  document.getElementById('view_password_help').style.display = 'block';
  document.getElementById('view_password_label_hint').innerText = '(Leave blank to keep current)';
  document.getElementById('view_password').placeholder = 'Enter new password (optional)';

  // Switch button visibility
  document.getElementById('modalEditBtn').style.display = 'none';
  document.getElementById('modalSaveBtn').style.display = 'inline-flex';
  document.getElementById('modalCancelEditBtn').style.display = 'inline-flex';

  // Focus first editable input
  document.getElementById('view_first_name').focus();
}

// 5. Cancel Edit Mode (Restores original values and re-enables disabled state)
function cancelModalEditMode() {
  if (!currentUserInModal) return;
  viewUserDetails(currentUserInModal);
}

// 6. Handle User Update (Save Changes)
async function handleUserUpdate(e) {
  e.preventDefault();
  const form = document.getElementById('viewUserForm');
  const res = await fetch(`${BASE_URL}/backend/functions/users/update.php`, {
    method: 'POST',
    body: new FormData(form)
  });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    closeModal('viewUserModal');
    setTimeout(() => window.location.reload(), 700);
  } else {
    showToast(data.message || 'Update failed.', 'danger');
  }
}

// 7. Delete User from Modal (Automatically stores into Archived Accounts)
function onModalDelete() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;
  const isSelf = (parseInt(u.id) === CURRENT_LOGGED_IN_USER_ID);

  if (isSelf) {
    showToast('You cannot delete your own administrative session account.', 'warning');
    return;
  }

  const uName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);
  showConfirmModal(
    'Confirm Account Deletion',
    `Are you sure you want to delete user account @${u.username} (${uName})? The account will be automatically stored in Archived Accounts.`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', u.id);

      const res = await fetch(`${BASE_URL}/backend/functions/users/delete.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'User account deleted and moved to Archived Accounts.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Deletion failed.', 'danger');
      }
    }
  );
}

// 8. Restore User from Modal
function onModalRestore() {
  if (!currentUserInModal) return;
  restoreUserDirect(currentUserInModal.id, currentUserInModal.username);
}

// 9. Direct Restore (used by table button and modal button)
function restoreUserDirect(userId, username) {
  showConfirmModal(
    'Confirm Account Restoration',
    `Are you sure you want to restore user account @${username} back to active status?`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', userId);

      const res = await fetch(`${BASE_URL}/backend/functions/users/restore.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'User account restored to active.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Restoration failed.', 'danger');
      }
    }
  );
}

// 10. Reactivate User from Table Button
function reactivateUser(userId, username) {
  showConfirmModal(
    'Confirm Account Reactivation',
    `Are you sure you want to reactivate user account @${username}? The account will return to active status and be able to log in.`,
    async () => {
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('status', 'active');

      const res = await fetch(`${BASE_URL}/backend/functions/users/update_status.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(`User account @${username} has been reactivated.`, 'success');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Reactivation failed.', 'danger');
      }
    }
  );
}

// 11. Toggle User Deactivate / Reactivate Status from Modal
function onModalToggleStatus() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;
  const isSelf = (parseInt(u.id) === CURRENT_LOGGED_IN_USER_ID);

  if (isSelf) {
    showToast('You cannot modify your own administrative account status.', 'warning');
    return;
  }

  const isCurrentlyActive = (u.status === 'active');
  const newStatus = isCurrentlyActive ? 'inactive' : 'active';
  const actionTitle = isCurrentlyActive ? 'Confirm Account Deactivation' : 'Confirm Account Reactivation';
  const actionMsg = isCurrentlyActive
    ? `Are you sure you want to deactivate user account @${u.username}? The user will be marked as Deactivated and the View button on the list will change into Reactivate.`
    : `Are you sure you want to reactivate user account @${u.username}? The account will return to active status.`;

  showConfirmModal(
    actionTitle,
    actionMsg,
    async () => {
      const fd = new FormData();
      fd.append('user_id', u.id);
      fd.append('status', newStatus);

      const res = await fetch(`${BASE_URL}/backend/functions/users/update_status.php`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(isCurrentlyActive ? 'User account has been deactivated.' : 'User account has been reactivated.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Status toggle failed.', 'danger');
      }
    }
  );
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
