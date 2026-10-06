<?php
// ============================================================================
// Views (Barangay Head): Responder Personnel Management Module
// Full feature parity with Resident Management with added Tactical Availability:
// Active & Archived Tabs, Search, Availability & Status Filters,
// In-Place View/Edit Mode, Real-Time Availability Switch, Archive & Restore.
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
$filterPurok = $_GET['purok'] ?? '';
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

// Query responders
$sql = "
    SELECT u.*, b.name AS barangay_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
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

if (!empty($filterPurok)) {
    $sql .= " AND u.purok = ?";
    $params[] = $filterPurok;
}

if (!empty($search)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.purok LIKE ?)";
    $like = "%$search%";
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

// Fetch local puroks/teams for dropdowns
$purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Manage Responders — Barangay " . ($barangay['name'] ?? '');
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

/* Modal styling matching residents.php */
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
  padding: 14px 20px !important;
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

/* Availability Badges */
.badge-avail {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.2px;
}
.badge-avail-available {
  background: #E8F5E9;
  color: #2E7D32;
  border: 1px solid #A5D6A7;
}
.badge-avail-onduty {
  background: #E3F2FD;
  color: #1565C0;
  border: 1px solid #90CAF9;
}
.badge-avail-responding {
  background: #FFEBEE;
  color: #C62828;
  border: 1px solid #FFCDD2;
}
.badge-avail-standby {
  background: #F3E5F5;
  color: #6A1B9A;
  border: 1px solid #CE93D8;
}
.badge-avail-offduty {
  background: #ECEFF1;
  color: #546E7A;
  border: 1px solid #CFD8DC;
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  display: inline-block;
}
.badge-avail-available .status-dot { background: #2E7D32; box-shadow: 0 0 0 2px rgba(46,125,50,0.2); }
.badge-avail-onduty .status-dot { background: #1565C0; box-shadow: 0 0 0 2px rgba(21,101,192,0.2); }
.badge-avail-responding .status-dot { background: #C62828; animation: pulseRed 1.2s infinite; }
.badge-avail-standby .status-dot { background: #6A1B9A; }
.badge-avail-offduty .status-dot { background: #546E7A; }

@keyframes pulseRed {
  0% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0.6); }
  70% { box-shadow: 0 0 0 6px rgba(198, 40, 40, 0); }
  100% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0); }
}

/* Mini KPI Card Grid */
.kpi-mini-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: var(--space-4);
}
@media (max-width: 900px) {
  .kpi-mini-grid { grid-template-columns: repeat(2, 1fr); }
}
.kpi-mini-card {
  background: #FFF;
  border: 1px solid var(--color-border-light);
  border-radius: 8px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
</style>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Responder Force Registry — Barangay <?= clean($barangay['name'] ?? '') ?></h1>
      <p class="page-header-desc">
        Manage your local emergency personnel, rescue squads, medical units, and monitor real-time field mission availability.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openCreateResponderModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Register New Responder
      </button>
    </div>
  </div>

  <!-- Real-time Tactical Availability KPI Cards -->
  <div class="kpi-mini-grid">
    <div class="kpi-mini-card">
      <div>
        <div style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Total Responders</div>
        <div style="font-size:18px;font-weight:800;color:var(--color-primary);margin-top:2px;"><?= $activeCount ?></div>
      </div>
      <div style="width:34px;height:34px;border-radius:8px;background:rgba(23,50,77,0.08);color:var(--color-primary);display:flex;align-items:center;justify-content:center;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
      </div>
    </div>

    <div class="kpi-mini-card" style="border-left:3px solid #2E7D32;">
      <div>
        <div style="font-size:9.5px;color:#2E7D32;text-transform:uppercase;font-weight:700;">Available (Ready)</div>
        <div style="font-size:18px;font-weight:800;color:#2E7D32;margin-top:2px;"><?= (int)($availKpis['count_available'] ?? 0) ?></div>
      </div>
      <div style="width:34px;height:34px;border-radius:8px;background:#E8F5E9;color:#2E7D32;display:flex;align-items:center;justify-content:center;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
      </div>
    </div>

    <div class="kpi-mini-card" style="border-left:3px solid #1565C0;">
      <div>
        <div style="font-size:9.5px;color:#1565C0;text-transform:uppercase;font-weight:700;">On Duty</div>
        <div style="font-size:18px;font-weight:800;color:#1565C0;margin-top:2px;"><?= (int)($availKpis['count_onduty'] ?? 0) ?></div>
      </div>
      <div style="width:34px;height:34px;border-radius:8px;background:#E3F2FD;color:#1565C0;display:flex;align-items:center;justify-content:center;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
      </div>
    </div>

    <div class="kpi-mini-card" style="border-left:3px solid #C62828;">
      <div>
        <div style="font-size:9.5px;color:#C62828;text-transform:uppercase;font-weight:700;">Responding (Active)</div>
        <div style="font-size:18px;font-weight:800;color:#C62828;margin-top:2px;"><?= (int)($availKpis['count_responding'] ?? 0) ?></div>
      </div>
      <div style="width:34px;height:34px;border-radius:8px;background:#FFEBEE;color:#C62828;display:flex;align-items:center;justify-content:center;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
      </div>
    </div>
  </div>

  <!-- Tabs Navigation -->
  <div class="nav-tabs">
    <a href="?tab=active" class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
      Active Responders
      <span class="nav-tab-badge"><?= $activeCount ?></span>
    </a>
    <a href="?tab=archived" class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
      Archived Responders
      <span class="nav-tab-badge"><?= $archivedCount ?></span>
    </a>
  </div>

  <!-- Filter & Search Toolbar -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body" style="padding:12px 16px;">
      <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">

        <div style="flex:1;min-width:200px;">
          <input type="text" name="search" class="form-control" placeholder="Search name, callsign, unit, email, phone..." value="<?= clean($search) ?>">
        </div>

        <div style="width:160px;">
          <select name="availability" class="form-control" onchange="this.form.submit()">
            <option value="">All Availabilities</option>
            <option value="Available" <?= $filterAvailability === 'Available' ? 'selected' : '' ?>>🟢 Available</option>
            <option value="On Duty" <?= $filterAvailability === 'On Duty' ? 'selected' : '' ?>>🔵 On Duty</option>
            <option value="Responding" <?= $filterAvailability === 'Responding' ? 'selected' : '' ?>>🔴 Responding</option>
            <option value="Standby" <?= $filterAvailability === 'Standby' ? 'selected' : '' ?>>🟣 Standby</option>
            <option value="Off Duty" <?= $filterAvailability === 'Off Duty' ? 'selected' : '' ?>>⚪ Off Duty</option>
          </select>
        </div>

        <?php if ($viewTab === 'active'): ?>
        <div style="width:140px;">
          <select name="status" class="form-control" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        </div>
        <?php endif; ?>

        <div style="width:160px;">
          <select name="purok" class="form-control" onchange="this.form.submit()">
            <option value="">All Teams / Zones</option>
            <?php foreach ($localPuroks as $lp): ?>
              <option value="<?= clean($lp['name']) ?>" <?= $filterPurok === $lp['name'] ? 'selected' : '' ?>>
                <?= clean($lp['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($search) || !empty($filterStatus) || !empty($filterAvailability) || !empty($filterPurok)): ?>
          <a href="?tab=<?= clean($viewTab) ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Responders Table -->
  <div class="card">
    <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Responder Profile</th>
            <th>Availability Status</th>
            <th>Tactical Unit / Assignment</th>
            <th>Contact Details</th>
            <th>Account Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($respondersList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                <div style="font-size:24px;margin-bottom:6px;">👨‍🚒</div>
                <div style="font-weight:600;font-size:12px;">No responders found</div>
                <div style="font-size:11px;margin-top:2px;">Try adjusting your filters or register a new responder personnel.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($respondersList as $r): ?>
              <?php
                $avail = $r['availability'] ?: 'Available';
                $availClass = 'badge-avail-' . strtolower(str_replace(' ', '', $avail));
                $userJson = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
              ?>
              <tr>
                <td>
                  <a href="javascript:void(0)" onclick="openViewResponderModal(<?= $userJson ?>)" class="table-user-link">
                    <div style="display:flex;align-items:center;gap:10px;">
                      <div style="width:34px;height:34px;border-radius:8px;background:var(--color-primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;">
                        <?php if (!empty($r['image']) && file_exists(ROOT_PATH . '/' . $r['image'])): ?>
                          <img src="<?= BASE_URL ?>/<?= clean($r['image']) ?>" alt="" style="width:100%;height:100%;border-radius:8px;object-fit:cover;">
                        <?php else: ?>
                          <?= strtoupper(substr($r['first_name'] ?: $r['username'], 0, 1)) ?>
                        <?php endif; ?>
                      </div>
                      <div>
                        <div class="table-user-name" style="font-weight:700;color:var(--color-primary);font-size:11.5px;">
                          <?= clean($r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name'])) ?>
                        </div>
                        <div style="font-size:10px;color:var(--color-text-muted);">@<?= clean($r['username']) ?></div>
                      </div>
                    </div>
                  </a>
                </td>

                <!-- Operational Availability Column with Quick-Switch Dropdown -->
                <td>
                  <div style="display:flex;align-items:center;gap:6px;">
                    <span class="badge-avail <?= $availClass ?>">
                      <span class="status-dot"></span>
                      <?= clean($avail) ?>
                    </span>
                    <?php if ($viewTab === 'active'): ?>
                      <div class="dropdown" style="display:inline-block;">
                        <button class="btn btn-outline btn-sm" style="padding:1px 5px;font-size:9px;" title="Quick Switch Availability" onclick="toggleQuickAvail(event, <?= $r['id'] ?>)">
                          ⚡
                        </button>
                        <div id="quickAvailMenu_<?= $r['id'] ?>" style="display:none;position:absolute;background:#FFF;border:1px solid var(--color-border);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,0.15);padding:4px;z-index:100;min-width:130px;">
                          <?php foreach (['Available', 'On Duty', 'Responding', 'Standby', 'Off Duty'] as $st): ?>
                            <a href="javascript:void(0)" 
                               onclick="setResponderAvailability(<?= $r['id'] ?>, '<?= $st ?>')"
                               style="display:block;padding:5px 8px;font-size:10px;font-weight:600;text-decoration:none;color:var(--color-text);border-radius:4px;<?= $avail === $st ? 'background:var(--color-surface-subtle);color:var(--color-primary);font-weight:700;' : '' ?>">
                              <?= $st ?>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <div style="font-size:11px;font-weight:600;color:var(--color-primary);">
                    <?= clean($r['purok'] ?: 'General Response Squad') ?>
                  </div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);">Barangay <?= clean($barangay['name'] ?? '') ?></div>
                </td>

                <td>
                  <div style="font-size:11px;font-weight:600;"><?= clean($r['phone'] ?: 'No Phone') ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);"><?= clean($r['email']) ?></div>
                </td>

                <td>
                  <?= renderStatusBadge($r['status']) ?>
                </td>

                <td style="text-align:right;">
                  <div style="display:inline-flex;gap:4px;">
                    <button class="btn btn-outline btn-sm" style="font-size:10px;" onclick="openViewResponderModal(<?= $userJson ?>)">
                      View / Edit
                    </button>

                    <?php if ($viewTab === 'active'): ?>
                      <form method="POST" action="<?= BASE_URL ?>/backend/functions/users/archive.php" style="display:inline;" onsubmit="return confirm('Archive responder <?= clean(addslashes($r['full_name'])) ?>?');">
                        <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);font-size:10px;">Archive</button>
                      </form>
                    <?php else: ?>
                      <form method="POST" action="<?= BASE_URL ?>/backend/functions/users/restore.php" style="display:inline;" onsubmit="return confirm('Restore responder <?= clean(addslashes($r['full_name'])) ?>?');">
                        <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;">Restore</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Hidden Quick Availability Form for fast updates -->
<form id="quickAvailForm" method="POST" action="<?= BASE_URL ?>/backend/functions/responders/quick_availability.php" style="display:none;">
  <input type="hidden" name="responder_id" id="quickAvailResponderId">
  <input type="hidden" name="availability" id="quickAvailStatus">
  <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">
</form>

<!-- ========================================================================= -->
<!-- Modal: Register New Responder Personnel                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createResponderModal" onclick="if(event.target===this)closeCreateResponderModal()">
  <form class="modal-dialog" id="createResponderForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/create.php">
    <input type="hidden" name="role" value="responder">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
    <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">

    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="margin:0;font-size:14px;font-weight:700;color:var(--color-primary);">Register New Responder Personnel</h3>
        <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">Barangay <?= clean($barangay['name'] ?? '') ?> Emergency Operations Force</div>
      </div>
      <button type="button" class="btn-close" onclick="closeCreateResponderModal()">&times;</button>
    </div>

    <div class="modal-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">First Name *</label>
          <input type="text" name="first_name" class="form-control" required placeholder="e.g. Kurt">
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Last Name *</label>
          <input type="text" name="last_name" class="form-control" required placeholder="e.g. Morales">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Username *</label>
          <input type="text" name="username" class="form-control" required placeholder="e.g. responder_kurt">
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Email Address *</label>
          <input type="email" name="email" class="form-control" required placeholder="e.g. kurt.rescuer@iligan.gov.ph">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Phone / Mobile *</label>
          <input type="text" name="phone" class="form-control" required placeholder="e.g. +63 929 111 2233">
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Tactical Availability Status *</label>
          <select name="availability" class="form-control" required>
            <option value="Available" selected>🟢 Available (Standby & Ready)</option>
            <option value="On Duty">🔵 On Duty (Active Station Shift)</option>
            <option value="Responding">🔴 Responding (Deploying to Incident)</option>
            <option value="Standby">🟣 Standby (Reserve)</option>
            <option value="Off Duty">⚪ Off Duty</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Assigned Team / Unit / Purok</label>
          <input type="text" name="purok" class="form-control" placeholder="e.g. Team Alpha Rescue / Medical Unit" list="purokList">
          <datalist id="purokList">
            <option value="Team Alpha Rescue">
            <option value="Emergency Medical Team">
            <option value="Logistics & Transport Unit">
            <option value="Search and Rescue (SAR)">
            <?php foreach ($localPuroks as $lp): ?>
              <option value="<?= clean($lp['name']) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Gender</label>
          <select name="gender" class="form-control">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Age</label>
          <input type="number" name="age" class="form-control" placeholder="e.g. 28" min="18" max="75">
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Account Password *</label>
          <input type="password" name="password" class="form-control" required value="admin123" placeholder="Default: admin123">
          <small style="font-size:9px;color:var(--color-text-muted);">Default: admin123 (can be changed on first login)</small>
        </div>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="closeCreateResponderModal()">Cancel</button>
      <button type="submit" class="btn btn-primary">Create Responder</button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal: View & Edit Responder Details (In-Place Toggle)                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewResponderModal" onclick="if(event.target===this)closeViewResponderModal()">
  <form class="modal-dialog" id="viewResponderForm" method="POST" action="<?= BASE_URL ?>/backend/functions/users/update.php">
    <input type="hidden" name="user_id" id="v_user_id">
    <input type="hidden" name="role" value="responder">
    <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
    <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div id="v_avatar" style="width:36px;height:36px;border-radius:8px;background:var(--color-primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">
          R
        </div>
        <div>
          <h3 class="modal-title" id="v_modal_title" style="margin:0;font-size:14px;font-weight:700;color:var(--color-primary);">Responder Profile</h3>
          <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;" id="v_modal_subtitle">@responder</div>
        </div>
      </div>
      <button type="button" class="btn-close" onclick="closeViewResponderModal()">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Tactical Availability Header Banner -->
      <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;">
        <div>
          <span style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Tactical Availability</span>
          <div style="margin-top:4px;">
            <select name="availability" id="v_availability" class="form-control" style="font-size:11px;font-weight:700;padding:4px 8px;" disabled>
              <option value="Available">🟢 Available (Ready for Mission)</option>
              <option value="On Duty">🔵 On Duty (Active Station)</option>
              <option value="Responding">🔴 Responding (On Site / En Route)</option>
              <option value="Standby">🟣 Standby (Reserve)</option>
              <option value="Off Duty">⚪ Off Duty</option>
            </select>
          </div>
        </div>
        <div style="text-align:right;">
          <span style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Account Status</span>
          <div id="v_status_badge" style="margin-top:4px;">
            <span class="badge badge-success">Active</span>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">First Name *</label>
          <input type="text" name="first_name" id="v_first_name" class="form-control" required disabled>
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Last Name *</label>
          <input type="text" name="last_name" id="v_last_name" class="form-control" required disabled>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Username *</label>
          <input type="text" name="username" id="v_username" class="form-control" required disabled>
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Email Address *</label>
          <input type="email" name="email" id="v_email" class="form-control" required disabled>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Phone / Mobile *</label>
          <input type="text" name="phone" id="v_phone" class="form-control" required disabled>
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Assigned Team / Unit / Area</label>
          <input type="text" name="purok" id="v_purok" class="form-control" disabled list="purokList">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Gender</label>
          <select name="gender" id="v_gender" class="form-control" disabled>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:10.5px;font-weight:600;">Age</label>
          <input type="number" name="age" id="v_age" class="form-control" min="18" max="75" disabled>
        </div>
      </div>

      <div id="v_password_row" style="margin-top:12px;display:none;">
        <label class="form-label" style="font-size:10.5px;font-weight:600;">Reset Password (Optional)</label>
        <input type="password" name="password" id="v_password" class="form-control" placeholder="Leave blank to retain current password" disabled>
        <small style="font-size:9.5px;color:var(--color-text-muted);">Enter new password only if changing.</small>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
      <div id="v_left_actions">
        <!-- Optional status toggle -->
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-outline" id="v_cancel_btn" onclick="closeViewResponderModal()">Close</button>
        <button type="button" class="btn btn-secondary" id="v_edit_toggle_btn" onclick="toggleEditMode()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          Edit Responder
        </button>
        <button type="submit" class="btn btn-primary" id="v_save_btn" style="display:none;">
          Save Changes
        </button>
      </div>
    </div>
  </form>
</div>

<script>
// Modal Controls
function openCreateResponderModal() {
  document.getElementById('createResponderModal').classList.add('active');
}
function closeCreateResponderModal() {
  document.getElementById('createResponderModal').classList.remove('active');
}

let isEditMode = false;
let currentResponderData = null;

function openViewResponderModal(r) {
  currentResponderData = r;
  isEditMode = false;

  // Set values
  document.getElementById('v_user_id').value = r.id;
  document.getElementById('v_modal_title').textContent = (r.full_name || (r.first_name + ' ' + r.last_name));
  document.getElementById('v_modal_subtitle').textContent = '@' + r.username + ' • ID #' + r.id;
  document.getElementById('v_first_name').value = r.first_name || '';
  document.getElementById('v_last_name').value = r.last_name || '';
  document.getElementById('v_username').value = r.username || '';
  document.getElementById('v_email').value = r.email || '';
  document.getElementById('v_phone').value = r.phone || '';
  document.getElementById('v_purok').value = r.purok || '';
  document.getElementById('v_gender').value = r.gender || 'Male';
  document.getElementById('v_age').value = r.age || '';
  document.getElementById('v_availability').value = r.availability || 'Available';

  const avatar = document.getElementById('v_avatar');
  avatar.textContent = ((r.first_name || r.username || 'R').charAt(0)).toUpperCase();

  // Status badge
  const sBadge = document.getElementById('v_status_badge');
  sBadge.innerHTML = `<span class="badge ${r.status === 'active' ? 'badge-success' : 'badge-warning'}">${r.status || 'active'}</span>`;

  // Set inputs disabled
  setInputState(false);

  document.getElementById('viewResponderModal').classList.add('active');
}

function closeViewResponderModal() {
  document.getElementById('viewResponderModal').classList.remove('active');
}

function setInputState(editable) {
  const inputs = ['v_first_name', 'v_last_name', 'v_username', 'v_email', 'v_phone', 'v_purok', 'v_gender', 'v_age', 'v_availability', 'v_password'];
  inputs.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = !editable;
  });

  const pwdRow = document.getElementById('v_password_row');
  if (pwdRow) pwdRow.style.display = editable ? 'block' : 'none';

  const editBtn = document.getElementById('v_edit_toggle_btn');
  const saveBtn = document.getElementById('v_save_btn');
  const cancelBtn = document.getElementById('v_cancel_btn');

  if (editable) {
    editBtn.style.display = 'none';
    saveBtn.style.display = 'inline-flex';
    cancelBtn.textContent = 'Cancel Edit';
    cancelBtn.onclick = function() {
      if (currentResponderData) openViewResponderModal(currentResponderData);
    };
  } else {
    editBtn.style.display = 'inline-flex';
    saveBtn.style.display = 'none';
    cancelBtn.textContent = 'Close';
    cancelBtn.onclick = closeViewResponderModal;
  }
}

function toggleEditMode() {
  isEditMode = true;
  setInputState(true);
}

// Quick Availability Switcher
function toggleQuickAvail(event, id) {
  event.stopPropagation();
  // Close any open menus
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
