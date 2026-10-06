<?php
// ============================================================================
// Views (Barangay Head): Resident Accounts Management Module
// Full feature parity with ICDRRMO User Management:
// Active & Archived Tabs, Search & Status/Purok Filters, Profile Picture Upload,
// In-Place View/Edit Mode, Activate/Deactivate, Delete to Archive, and Restore.
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
$filterPurok = $_GET['purok'] ?? '';
$search = trim($_GET['search'] ?? '');

// Counts for navigation tabs
$activeCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'resident' AND barangay_id = ? AND status != 'archived'");
$activeCountStmt->execute([$barangayId]);
$activeCount = (int)$activeCountStmt->fetchColumn();

$archivedCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'resident' AND barangay_id = ? AND status = 'archived'");
$archivedCountStmt->execute([$barangayId]);
$archivedCount = (int)$archivedCountStmt->fetchColumn();

// Query residents
$sql = "
    SELECT u.*, b.name AS barangay_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    WHERE u.role = 'resident' AND u.barangay_id = ?
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

if (!empty($filterPurok)) {
    $sql .= " AND u.purok = ?";
    $params[] = $filterPurok;
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

$sql .= " ORDER BY FIELD(u.status, 'pending', 'active', 'inactive', 'archived'), u.first_name ASC, u.last_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$residentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch local puroks for dropdowns
$purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Manage Residents — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
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

/* Ensure View Modal & Create Modal are responsive and vertically scrollable */
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

/* Custom scrollbars */
#viewUserModal .modal-body::-webkit-scrollbar,
#createUserModal .modal-body::-webkit-scrollbar {
  width: 6px;
}
#viewUserModal .modal-body::-webkit-scrollbar-thumb,
#createUserModal .modal-body::-webkit-scrollbar-thumb {
  background: #CBD5E1;
  border-radius: 4px;
}

/* Tab nav styling */
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
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Resident Accounts Registry — Barangay <?= clean($barangay['name'] ?? '') ?></h1>
    <p class="page-header-desc">
      Manage registered citizens, household profiles, zone assignments, and emergency contact registries for this barangay jurisdiction.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openCreateUserModal()">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      Register New Resident
    </button>
  </div>
</div>

<!-- Tabs Navigation -->
<div class="nav-tabs">
  <a href="?tab=active" class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
    Active Residents
    <span class="nav-tab-badge"><?= $activeCount ?></span>
  </a>
  <a href="?tab=archived" class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
    Archived Residents
    <span class="nav-tab-badge"><?= $archivedCount ?></span>
  </a>
</div>

<!-- Search & Filters -->
<div class="card" style="margin-bottom: var(--space-4);">
  <div class="card-body" style="padding:10px 14px;">
    <form method="GET" class="filter-bar" style="margin-bottom:0;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($viewTab) ?>">
      <div class="filter-group" style="flex-wrap:wrap;gap:8px;">
        <div class="search-input-wrap" style="min-width:240px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="search" class="form-control" placeholder="Search resident name, @username, phone, or email..." value="<?= clean($search) ?>">
        </div>

        <?php if ($viewTab === 'active'): ?>
          <select name="status" class="form-control" style="width:140px;">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($filterStatus === 'inactive' || $filterStatus === 'deactivated') ? 'selected' : '' ?>>Deactivated</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        <?php endif; ?>

        <select name="purok" class="form-control" style="width:160px;">
          <option value="">All Puroks</option>
          <?php foreach ($localPuroks as $p): ?>
            <option value="<?= clean($p['name']) ?>" <?= $filterPurok === $p['name'] ? 'selected' : '' ?>><?= clean($p['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-outline">Apply Filter</button>
        <?php if (!empty($search) || !empty($filterStatus) || !empty($filterPurok)): ?>
          <a href="?tab=<?= $viewTab ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </div>
      <div>
        <span style="font-size:10px;color:var(--color-text-muted);">
          Total: <?= count($residentsList) ?> Resident(s)
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Residents Table -->
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:260px;">Resident Name & Username</th>
        <th>Purok Assignment</th>
        <th>Contact Details</th>
        <th>Demographics</th>
        <th>Account Status</th>
        <th>Registration Date</th>
        <th style="text-align:right;width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($residentsList)): ?>
        <tr>
          <td colspan="7" style="text-align:center;padding:32px;color:var(--color-text-muted);">
            No resident records found in <?= $viewTab === 'archived' ? 'Archived' : 'Active' ?> registry matching your filter criteria.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($residentsList as $r): ?>
          <tr>
            <td>
              <a href="javascript:void(0)" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r)) ?>)" class="table-user-link">
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
              <span style="font-size:11px;font-weight:500;color:var(--color-text);">
                <?= clean($r['purok'] ?: 'Standard Zone') ?>
              </span>
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
              <div style="font-size:10.5px;color:var(--color-text);">
                <?= clean($r['gender'] ?: 'Unspecified') ?>
                <?php if (!empty($r['age'])): ?>
                  • <?= (int)$r['age'] ?> yrs
                <?php endif; ?>
              </div>
            </td>

            <td>
              <?= renderStatusBadge($r['status']) ?>
            </td>

            <td style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);">
              <?= formatDate($r['created_at'], 'M d, Y') ?>
            </td>

            <td style="text-align:right;">
              <div style="display:inline-flex;gap:4px;align-items:center;">
                <?php if ($r['status'] === 'archived'): ?>
                  <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-success);border-color:var(--color-success);padding:3px 8px;font-size:10.5px;" onclick="restoreUserDirect(<?= (int)$r['id'] ?>, '<?= clean($r['username']) ?>')">
                    Restore
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
                    View
                  </button>
                <?php elseif ($r['status'] === 'inactive' || $r['status'] === 'deactivated'): ?>
                  <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-success);border-color:var(--color-success);padding:3px 8px;font-size:10.5px;" onclick="reactivateUser(<?= (int)$r['id'] ?>, '<?= clean($r['username']) ?>')">
                    Reactivate
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
                    View
                  </button>
                <?php else: ?>
                  <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;" onclick="viewUserDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
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

<!-- ========================================================================= -->
<!-- Modal 1: Register New Resident Modal                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createUserModal">
  <form class="modal-dialog" id="createUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php" style="max-width:620px;">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="role" value="resident">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <h3 class="modal-title">Register Resident Account</h3>
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
          <input type="email" name="email" class="form-control" placeholder="e.g. maria@gmail.com" required>
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
          <label class="form-label form-label-required">Purok Assignment</label>
          <select name="purok" class="form-control" required>
            <option value="">Select Purok Zone</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assigned Barangay</label>
          <input type="text" class="form-control" value="Barangay <?= clean($barangay['name']) ?>" disabled>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Initial Password</label>
          <input type="password" name="password" class="form-control" value="resident123" required>
        </div>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('createUserModal')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmitCreate">Register Resident</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Resident Details                            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewUserModal">
  <form class="modal-dialog" id="viewUserForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/update.php">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="user_id" id="view_user_id">
    <input type="hidden" name="role" value="resident">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewUserModal')" title="Close">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Section 1: Resident Identity Banner -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);">
        <div id="view_profile_display_name" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
        <div id="view_profile_meta_sub" style="font-size:10px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Section 2: Heading "Resident Details" -->
      <div style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewDetailsSectionHeading" style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          Resident Details
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
          <label class="form-label form-label-required">Purok Assignment</label>
          <select name="purok" id="view_purok" class="form-control" disabled required>
            <option value="">Select Purok Zone</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
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

    <!-- Action Footer (Pinned at bottom) -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left: Delete & Restore Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Delete Button (Moves account to Archived Residents) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalDeleteBtn" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalDelete()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Delete Account
        </button>

        <!-- Restore Button (visible when viewing an archived account) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalRestoreBtn" style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalRestore()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
          Restore Resident
        </button>
      </div>

      <!-- Right: Status Toggle, Edit / Save, Cancel, Close Actions -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Deactivate / Reactivate Button -->
        <button type="button" class="btn btn-outline btn-sm" id="modalToggleStatusBtn" onclick="onModalToggleStatus()">
          Deactivate Account
        </button>

        <!-- Cancel Edit Button (visible in edit mode) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalCancelEditBtn" onclick="cancelModalEditMode()" style="display:none;">
          Cancel
        </button>

        <!-- In-Place Edit Button (visible in view mode) -->
        <button type="button" class="btn btn-primary btn-sm" id="modalEditBtn" onclick="enableModalEditMode()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit
        </button>

        <!-- Save Changes Button (visible in edit mode) -->
        <button type="submit" class="btn btn-primary btn-sm" id="modalSaveBtn" style="display:none;">
          Save Changes
        </button>

        <!-- Close Button -->
        <button type="button" class="btn btn-outline btn-sm" id="modalCloseBtn" onclick="closeModal('viewUserModal')">
          Close
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Reusable Confirmation Dialog                                     -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="confirmationModal" style="z-index: 1200 !important;">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" id="confirmModalTitle">Confirm Action</h3>
      <button class="modal-close-btn" onclick="closeModal('confirmationModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want to proceed with this operation?</p>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<script>
const CURRENT_LOGGED_IN_USER_ID = <?= $currentUserId ?>;
let currentUserInModal = null;
let isEditMode = false;

// 1. Open Create Resident Modal
function openCreateUserModal() {
  const form = document.getElementById('createUserForm');
  if (form) form.reset();
  openModal('createUserModal');
}

// 2. Open View Resident Modal (Disabled Inputs)
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

  // Section 2 Heading
  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
    Resident Details (@${escapeHtml(u.username)})
  `;
  document.getElementById('viewModeHintText').innerHTML = `(View Only Mode)`;

  // Top Profile Banner
  const displayName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);
  document.getElementById('view_profile_display_name').innerText = displayName;
  let metaParts = [];
  if (u.gender) metaParts.push(u.gender);
  if (u.age) metaParts.push(`${u.age} yrs`);
  metaParts.push('Resident');
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
  document.getElementById('view_purok').value = u.purok || '';
  document.getElementById('view_password').value = '';

  // Metadata timestamps
  document.getElementById('view_created_at_text').innerText = u.created_at || 'N/A';
  document.getElementById('view_last_login_text').innerText = u.last_login || 'Never';

  // Lock inputs to disabled
  setModalInputsDisabled(true);

  // Configure Button States
  const deleteBtn = document.getElementById('modalDeleteBtn');
  deleteBtn.style.display = 'inline-flex';
  deleteBtn.title = "Delete resident account (moves to Archived Residents tab)";

  // Restore Button
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
  document.getElementById('view_purok').disabled = disabled;
  document.getElementById('view_password').disabled = disabled;
}

// 4. Enable Edit Mode
function enableModalEditMode() {
  if (!currentUserInModal) return;
  isEditMode = true;

  setModalInputsDisabled(false);

  document.getElementById('viewDetailsSectionHeading').innerHTML = `
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
    Edit Resident Details (@${escapeHtml(currentUserInModal.username)})
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

// 5. Cancel Edit Mode
function cancelModalEditMode() {
  if (!currentUserInModal) return;
  viewUserDetails(currentUserInModal);
}

// 6. Delete Resident from Modal (Moves to Archived Residents)
function onModalDelete() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;
  const uName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.full_name || u.username);

  showConfirmModal(
    'Confirm Account Deletion',
    `Are you sure you want to delete resident account @${u.username} (${uName})? The account will be moved to Archived Residents.`,
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
        showToast(data.message || 'Resident account deleted and moved to Archived Residents.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Deletion failed.', 'danger');
      }
    }
  );
}

// 7. Restore Resident from Modal
function onModalRestore() {
  if (!currentUserInModal) return;
  restoreUserDirect(currentUserInModal.id, currentUserInModal.username);
}

// 8. Direct Restore
function restoreUserDirect(userId, username) {
  showConfirmModal(
    'Confirm Account Restoration',
    `Are you sure you want to restore resident account @${username} back to active status?`,
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
        showToast(data.message || 'Resident account restored to active.', 'success');
        closeModal('viewUserModal');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Restoration failed.', 'danger');
      }
    }
  );
}

// 9. Reactivate Resident from Table Button
function reactivateUser(userId, username) {
  showConfirmModal(
    'Confirm Account Reactivation',
    `Are you sure you want to reactivate resident account @${username}? The account will return to active status.`,
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
        showToast(`Resident account @${username} has been reactivated.`, 'success');
        setTimeout(() => window.location.reload(), 700);
      } else {
        showToast(data.message || 'Reactivation failed.', 'danger');
      }
    }
  );
}

// 10. Toggle Deactivate / Reactivate Status from Modal
function onModalToggleStatus() {
  if (!currentUserInModal) return;
  const u = currentUserInModal;

  const isCurrentlyActive = (u.status === 'active');
  const newStatus = isCurrentlyActive ? 'inactive' : 'active';
  const actionTitle = isCurrentlyActive ? 'Confirm Account Deactivation' : 'Confirm Account Reactivation';
  const actionMsg = isCurrentlyActive
    ? `Are you sure you want to deactivate resident account @${u.username}? The resident will be marked as Deactivated.`
    : `Are you sure you want to reactivate resident account @${u.username}? The account will return to active status.`;

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
        showToast(isCurrentlyActive ? 'Resident account has been deactivated.' : 'Resident account has been reactivated.', 'success');
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
