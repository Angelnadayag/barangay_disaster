<?php
// ============================================================================
// Views (ICDRRMO): Financial Aid Allocation Module
// Centralized calamity cash assistance allocation, budget monitoring,
// and cross-barangay financial distribution audit records.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

$pageTitle = "Financial Aid Allocation — ICDRRMO Admin";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$activeTab = in_array($_GET['tab'] ?? '', ['allocations', 'audits'], true) ? $_GET['tab'] : 'allocations';

$filterBarangay = !empty($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$filterStatus = trim($_GET['status'] ?? '');
$filterAction = trim($_GET['action_type'] ?? '');
$search = trim($_GET['search'] ?? '');

// Fetch all active barangays for filter & creation dropdowns
$brgyStmt = $db->query("SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC");
$allBarangays = $brgyStmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalAllocationsCount = (int)$db->query("SELECT COUNT(*) FROM financial_aid_allocations")->fetchColumn();
$totalBudgetSum = (float)$db->query("SELECT COALESCE(SUM(total_budget), 0) FROM financial_aid_allocations WHERE status != 'Archived'")->fetchColumn();
$totalDisbursedSum = (float)$db->query("SELECT COALESCE(SUM(disbursed_budget), 0) FROM financial_aid_allocations WHERE status != 'Archived'")->fetchColumn();
$totalRemainingSum = (float)$db->query("SELECT COALESCE(SUM(remaining_budget), 0) FROM financial_aid_allocations WHERE status != 'Archived'")->fetchColumn();
$totalBeneficiariesClaimed = (int)$db->query("SELECT COUNT(*) FROM financial_aid_distributions WHERE claim_status = 'Claimed'")->fetchColumn();

// Fetch Allocations Query
$allocSql = "
    SELECT a.*, b.name AS barangay_name, u.full_name AS allocator_name,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id) AS registered_count,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Claimed') AS claimed_count
    FROM financial_aid_allocations a
    JOIN barangays b ON a.barangay_id = b.id
    LEFT JOIN users u ON a.allocated_by = u.id
    WHERE 1=1
";
$allocParams = [];

if ($filterBarangay > 0) {
    $allocSql .= " AND a.barangay_id = ?";
    $allocParams[] = $filterBarangay;
}
if (!empty($filterStatus)) {
    $allocSql .= " AND a.status = ?";
    $allocParams[] = $filterStatus;
}
if (!empty($search)) {
    $allocSql .= " AND (a.allocation_code LIKE ? OR a.program_name LIKE ? OR b.name LIKE ? OR a.funding_source LIKE ?)";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
}
$allocSql .= " ORDER BY a.created_at DESC";

$allocStmt = $db->prepare($allocSql);
$allocStmt->execute($allocParams);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Audits Query (Cross-barangay audit records)
$auditSql = "
    SELECT aud.*, b.name AS barangay_name, a.allocation_code, a.program_name
    FROM financial_aid_audits aud
    JOIN barangays b ON aud.barangay_id = b.id
    LEFT JOIN financial_aid_allocations a ON aud.allocation_id = a.id
    WHERE 1=1
";
$auditParams = [];

if ($filterBarangay > 0) {
    $auditSql .= " AND aud.barangay_id = ?";
    $auditParams[] = $filterBarangay;
}
if (!empty($filterAction)) {
    $auditSql .= " AND aud.action = ?";
    $auditParams[] = $filterAction;
}
if (!empty($search)) {
    $auditSql .= " AND (aud.details LIKE ? OR aud.actor_name LIKE ? OR b.name LIKE ? OR a.allocation_code LIKE ?)";
    $auditParams[] = "%{$search}%";
    $auditParams[] = "%{$search}%";
    $auditParams[] = "%{$search}%";
    $auditParams[] = "%{$search}%";
}
$auditSql .= " ORDER BY aud.created_at DESC LIMIT 200";

$auditStmt = $db->prepare($auditSql);
$auditStmt->execute($auditParams);
$audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Anti-slop, clean typographic & table styling */
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
  border-bottom-color: var(--color-primary);
  background-color: var(--color-surface);
}
.nav-tab-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: var(--radius-full);
  font-size: 9.5px;
  font-weight: 700;
  background-color: var(--color-surface-subtle);
  color: var(--color-text-muted);
}
.nav-tab-item.active .nav-tab-badge {
  background-color: var(--color-primary);
  color: #FFFFFF;
}

.kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}
.kpi-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-primary);
  padding: var(--space-4);
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.kpi-title {
  font-size: 10px;
  font-weight: 600;
  color: var(--color-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.kpi-val {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  font-family: var(--font-secondary);
}
.kpi-sub {
  font-size: 10px;
  color: var(--color-text-muted);
}

.table-progress-bar {
  width: 100%;
  height: 6px;
  background: var(--color-surface-subtle);
  border-radius: 999px;
  overflow: hidden;
  margin-top: 4px;
}
.table-progress-fill {
  height: 100%;
  background: var(--color-primary);
  border-radius: 999px;
}

.status-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.status-badge.active {
  background: #EBF3FC;
  color: #17324D;
  border: 1px solid #B7D7FA;
}
.status-badge.completed {
  background: #EAF4EB;
  color: #2E7D32;
  border: 1px solid #BCE3C1;
}
.status-badge.archived {
  background: #F0F3F4;
  color: #66737D;
  border: 1px solid #D9E0E3;
}

.action-badge {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 9.5px;
  font-weight: 600;
}
.action-allocated { background: #EBF3FC; color: #17324D; }
.action-disbursed { background: #EAF4EB; color: #2E7D32; }
.action-status { background: #FEF8E8; color: #8A6402; }
.action-voided { background: #FDE8E8; color: #C62828; }

/* Modal Customization */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(16, 35, 54, 0.45);
  backdrop-filter: blur(2px);
  z-index: 1050;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal.show { display: flex; }
.modal-dialog {
  background: var(--color-surface);
  border-radius: var(--radius-primary);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-modal);
  width: 100%;
  max-width: 640px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}
.modal-header {
  padding: 14px 18px;
  border-bottom: 1px solid var(--color-border-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #FAFCFC;
}
.modal-header h3 {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0;
}
.modal-close {
  background: transparent;
  border: none;
  font-size: 16px;
  cursor: pointer;
  color: var(--color-text-muted);
  line-height: 1;
}
.modal-close:hover { color: var(--color-primary); }
.modal-body {
  padding: 18px;
  overflow-y: auto;
  font-size: 11px;
}
.modal-footer {
  padding: 12px 18px;
  border-top: 1px solid var(--color-border-light);
  background: #FAFCFC;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Financial Aid Allocation</h1>
      <p class="page-header-desc">
        Centralized disaster cash assistance programming, barangay fund allocation, and cross-barangay distribution audits.
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-primary" onclick="openCreateAllocationModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        New Allocation
      </button>
    </div>
  </div>

  <!-- KPI Telemetry Cards -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-title">Active Program Budget</div>
      <div class="kpi-val">&#8369;<?= number_format($totalBudgetSum, 2) ?></div>
      <div class="kpi-sub"><?= number_format($totalAllocationsCount) ?> registered program allocations</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Disbursed</div>
      <div class="kpi-val">&#8369;<?= number_format($totalDisbursedSum, 2) ?></div>
      <div class="kpi-sub"><?= number_format($totalBeneficiariesClaimed) ?> beneficiaries released</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Undisbursed Balance</div>
      <div class="kpi-val">&#8369;<?= number_format($totalRemainingSum, 2) ?></div>
      <div class="kpi-sub">Remaining balance across all barangays</div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="nav-tabs">
    <a href="?tab=allocations<?= $filterBarangay ? '&barangay_id='.$filterBarangay : '' ?>" class="nav-tab-item <?= $activeTab === 'allocations' ? 'active' : '' ?>">
      Barangay Allocations
      <span class="nav-tab-badge"><?= count($allocations) ?></span>
    </a>
    <a href="?tab=audits<?= $filterBarangay ? '&barangay_id='.$filterBarangay : '' ?>" class="nav-tab-item <?= $activeTab === 'audits' ? 'active' : '' ?>">
      Distribution Audit Records
      <span class="nav-tab-badge"><?= count($audits) ?></span>
    </a>
  </div>

  <!-- Filter Toolbar -->
  <div class="card" style="margin-bottom: var(--space-4); padding: 12px 16px;">
    <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      
      <div style="flex: 1; min-width: 220px;">
        <input type="text" name="search" class="form-control" placeholder="Search by program, code, or barangay..." value="<?= htmlspecialchars($search) ?>" style="font-size: 11px;">
      </div>

      <div style="min-width: 170px;">
        <select name="barangay_id" class="form-control" style="font-size: 11px;">
          <option value="">All Barangays</option>
          <?php foreach ($allBarangays as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $filterBarangay === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($activeTab === 'allocations'): ?>
        <div style="min-width: 130px;">
          <select name="status" class="form-control" style="font-size: 11px;">
            <option value="">All Statuses</option>
            <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Archived" <?= $filterStatus === 'Archived' ? 'selected' : '' ?>>Archived</option>
          </select>
        </div>
      <?php else: ?>
        <div style="min-width: 140px;">
          <select name="action_type" class="form-control" style="font-size: 11px;">
            <option value="">All Actions</option>
            <option value="Allocated" <?= $filterAction === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
            <option value="Disbursed" <?= $filterAction === 'Disbursed' ? 'selected' : '' ?>>Disbursed</option>
            <option value="Status Change" <?= $filterAction === 'Status Change' ? 'selected' : '' ?>>Status Change</option>
            <option value="Voided" <?= $filterAction === 'Voided' ? 'selected' : '' ?>>Voided</option>
          </select>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-outline" style="font-size: 11px; padding: 7px 12px;">Filter</button>
      <?php if (!empty($search) || !empty($filterBarangay) || !empty($filterStatus) || !empty($filterAction)): ?>
        <a href="?tab=<?= $activeTab ?>" class="btn btn-outline" style="font-size: 11px; padding: 7px 12px; color: var(--color-text-muted);">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($activeTab === 'allocations'): ?>
    <!-- Allocations Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div class="table-responsive">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 1px solid var(--color-border); background: #FAFCFC;">
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Code</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Program & Disaster</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Target Barangay</th>
              <th style="padding: 10px 14px; text-align: right; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Total Fund</th>
              <th style="padding: 10px 14px; text-align: right; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Rate / Family</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 170px;">Disbursement Progress</th>
              <th style="padding: 10px 14px; text-align: center; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Status</th>
              <th style="padding: 10px 14px; text-align: center; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 100px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allocations)): ?>
              <tr>
                <td colspan="8" style="padding: 30px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
                  No financial aid allocations found matching your filter criteria.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($allocations as $row): 
                $pct = ($row['total_budget'] > 0) ? min(100, round(($row['disbursed_budget'] / $row['total_budget']) * 100, 1)) : 0;
              ?>
                <tr style="border-bottom: 1px solid var(--color-border-light);">
                  <td style="padding: 10px 14px; font-family: var(--font-secondary); font-weight: 600; font-size: 11px; color: var(--color-primary);">
                    <?= htmlspecialchars($row['allocation_code']) ?>
                  </td>
                  <td style="padding: 10px 14px;">
                    <div style="font-weight: 600; color: var(--color-text); font-size: 11px;"><?= htmlspecialchars($row['program_name']) ?></div>
                    <div style="font-size: 10px; color: var(--color-text-muted);">
                      <?= htmlspecialchars($row['disaster_type'] ?: 'General Incident') ?> &bull; <?= htmlspecialchars($row['funding_source']) ?>
                    </div>
                  </td>
                  <td style="padding: 10px 14px; font-size: 11px; font-weight: 500; color: var(--color-text);">
                    <?= htmlspecialchars($row['barangay_name']) ?>
                  </td>
                  <td style="padding: 10px 14px; text-align: right; font-family: var(--font-secondary); font-weight: 600; font-size: 11px; color: var(--color-text);">
                    &#8369;<?= number_format($row['total_budget'], 2) ?>
                  </td>
                  <td style="padding: 10px 14px; text-align: right; font-family: var(--font-secondary); font-size: 11px; color: var(--color-text-secondary);">
                    &#8369;<?= number_format($row['amount_per_beneficiary'], 2) ?>
                  </td>
                  <td style="padding: 10px 14px;">
                    <div style="display: flex; justify-content: space-between; font-size: 10px; color: var(--color-text-secondary); margin-bottom: 2px;">
                      <span>&#8369;<?= number_format($row['disbursed_budget'], 2) ?></span>
                      <span><?= $pct ?>%</span>
                    </div>
                    <div class="table-progress-bar">
                      <div class="table-progress-fill" style="width: <?= $pct ?>%;"></div>
                    </div>
                    <div style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 2px;">
                      <?= $row['claimed_count'] ?> of <?= $row['target_beneficiaries'] ?> families served
                    </div>
                  </td>
                  <td style="padding: 10px 14px; text-align: center;">
                    <span class="status-badge <?= strtolower($row['status']) ?>">
                      <?= htmlspecialchars($row['status']) ?>
                    </span>
                  </td>
                  <td style="padding: 10px 14px; text-align: center;">
                    <div style="display: inline-flex; gap: 4px;">
                      <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 10px;" onclick="viewAllocationDetails(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)" title="View Details">
                        View
                      </button>
                      <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 10px;" onclick="openStatusModal(<?= $row['id'] ?>, '<?= $row['status'] ?>')" title="Update Status">
                        Status
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php else: ?>
    <!-- Distribution Audit Records Tab -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div style="padding: 12px 16px; border-bottom: 1px solid var(--color-border-light); background: #FAFCFC; display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 11px; font-weight: 600; color: var(--color-primary);">City-Wide Financial Aid Disbursement Audit Ledger</span>
        <span style="font-size: 10px; color: var(--color-text-muted);">Immutable chronological log</span>
      </div>
      <div class="table-responsive">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 1px solid var(--color-border); background: #FAFCFC;">
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 130px;">Date & Time</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 120px;">Barangay</th>
              <th style="padding: 10px 14px; text-align: center; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 100px;">Action</th>
              <th style="padding: 10px 14px; text-align: right; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 100px;">Amount</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Audit Details</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary); width: 140px;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($audits)): ?>
              <tr>
                <td colspan="6" style="padding: 30px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
                  No financial aid audit events recorded.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($audits as $aud): 
                $actClass = 'action-' . strtolower(str_replace(' ', '', $aud['action']));
              ?>
                <tr style="border-bottom: 1px solid var(--color-border-light);">
                  <td style="padding: 10px 14px; font-size: 10.5px; color: var(--color-text-muted); font-family: var(--font-secondary); white-space: nowrap;">
                    <?= date('Y-m-d H:i', strtotime($aud['created_at'])) ?>
                  </td>
                  <td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: var(--color-primary);">
                    <?= htmlspecialchars($aud['barangay_name']) ?>
                  </td>
                  <td style="padding: 10px 14px; text-align: center;">
                    <span class="action-badge <?= $actClass ?>">
                      <?= htmlspecialchars($aud['action']) ?>
                    </span>
                  </td>
                  <td style="padding: 10px 14px; text-align: right; font-family: var(--font-secondary); font-weight: 600; font-size: 11px; color: var(--color-text);">
                    <?= ($aud['amount'] > 0) ? '&#8369;' . number_format($aud['amount'], 2) : '&mdash;' ?>
                  </td>
                  <td style="padding: 10px 14px; font-size: 11px; color: var(--color-text); line-height: 1.4;">
                    <?= htmlspecialchars($aud['details']) ?>
                  </td>
                  <td style="padding: 10px 14px; font-size: 10.5px;">
                    <div style="font-weight: 500; color: var(--color-text);"><?= htmlspecialchars($aud['actor_name']) ?></div>
                    <div style="color: var(--color-text-muted); font-size: 9.5px;"><?= strtoupper(htmlspecialchars($aud['actor_role'])) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</main>

<!-- Modal: Create New Allocation -->
<div class="modal" id="createAllocationModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Create Financial Aid Allocation</h3>
      <button type="button" class="modal-close" onclick="closeCreateAllocationModal()">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/allocate.php">
      <input type="hidden" name="action" value="create">
      <div class="modal-body">
        <div style="display: flex; flex-direction: column; gap: 12px;">
          
          <div>
            <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Program Name</label>
            <input type="text" name="program_name" class="form-control" placeholder="e.g. Typhoon Kristine Emergency Cash Relief" required style="font-size: 11px;">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Target Barangay</label>
              <select name="barangay_id" class="form-control" required style="font-size: 11px;">
                <option value="">-- Select Barangay --</option>
                <?php foreach ($allBarangays as $b): ?>
                  <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Disaster / Calamity Type</label>
              <select name="disaster_type" class="form-control" style="font-size: 11px;">
                <option value="Typhoon">Typhoon</option>
                <option value="Flood">Flood</option>
                <option value="Landslide">Landslide</option>
                <option value="Fire">Fire</option>
                <option value="Earthquake">Earthquake</option>
                <option value="General Calamity">General Calamity</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Total Budget (PHP)</label>
              <input type="number" step="0.01" min="1" name="total_budget" id="alloc_total_budget" class="form-control" placeholder="e.g. 500000.00" required style="font-size: 11px;" oninput="updateTargetCountPreview()">
            </div>
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Amount per Family (PHP)</label>
              <input type="number" step="0.01" min="1" name="amount_per_beneficiary" id="alloc_rate_budget" class="form-control" placeholder="e.g. 3000.00" required style="font-size: 11px;" oninput="updateTargetCountPreview()">
            </div>
          </div>

          <!-- Computed Preview Card -->
          <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 8px 12px; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 10.5px; color: var(--color-text-secondary);">Calculated Beneficiary Capacity:</span>
            <span id="alloc_target_preview" style="font-size: 12px; font-weight: 700; color: var(--color-primary); font-family: var(--font-secondary);">0 families</span>
          </div>

          <div>
            <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Funding Source</label>
            <input type="text" name="funding_source" class="form-control" value="LGU Calamity Fund" placeholder="e.g. Quick Response Fund (QRF)" style="font-size: 11px;">
          </div>

          <div>
            <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Administrative Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Eligibility conditions, allocation guidelines, or remarks..." style="font-size: 11px;"></textarea>
          </div>

        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeCreateAllocationModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Allocate Fund</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: View Allocation Details -->
<div class="modal" id="viewAllocationModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 id="view_alloc_code_title">Allocation Details</h3>
      <button type="button" class="modal-close" onclick="closeViewAllocationModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="display: flex; flex-direction: column; gap: 12px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; border-bottom: 1px solid var(--color-border-light); padding-bottom: 10px;">
          <div>
            <div style="font-size: 10px; color: var(--color-text-muted);">PROGRAM</div>
            <div id="view_alloc_program" style="font-size: 12px; font-weight: 600; color: var(--color-primary);"></div>
          </div>
          <div>
            <div style="font-size: 10px; color: var(--color-text-muted);">BARANGAY</div>
            <div id="view_alloc_barangay" style="font-size: 12px; font-weight: 600; color: var(--color-primary);"></div>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center;">
          <div style="background: var(--color-surface-subtle); padding: 8px; border-radius: 6px;">
            <div style="font-size: 9.5px; color: var(--color-text-muted);">TOTAL BUDGET</div>
            <div id="view_alloc_total" style="font-size: 13px; font-weight: 700; font-family: var(--font-secondary);"></div>
          </div>
          <div style="background: var(--color-surface-subtle); padding: 8px; border-radius: 6px;">
            <div style="font-size: 9.5px; color: var(--color-text-muted);">DISBURSED</div>
            <div id="view_alloc_disbursed" style="font-size: 13px; font-weight: 700; font-family: var(--font-secondary); color: var(--color-success);"></div>
          </div>
          <div style="background: var(--color-surface-subtle); padding: 8px; border-radius: 6px;">
            <div style="font-size: 9.5px; color: var(--color-text-muted);">BALANCE</div>
            <div id="view_alloc_balance" style="font-size: 13px; font-weight: 700; font-family: var(--font-secondary);"></div>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 4px;">
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Rate per Family:</span>
            <span id="view_alloc_rate" style="font-size: 11px; font-weight: 600;"></span>
          </div>
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Target Beneficiaries:</span>
            <span id="view_alloc_target" style="font-size: 11px; font-weight: 600;"></span>
          </div>
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Funding Source:</span>
            <span id="view_alloc_funding" style="font-size: 11px;"></span>
          </div>
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Status:</span>
            <span id="view_alloc_status" style="font-size: 11px; font-weight: 600;"></span>
          </div>
        </div>

        <div>
          <span style="font-size: 10px; color: var(--color-text-muted); display: block; margin-bottom: 2px;">Notes / Remarks:</span>
          <div id="view_alloc_notes" style="font-size: 11px; background: #FAFCFC; border: 1px solid var(--color-border-light); padding: 8px; border-radius: 6px; min-height: 40px; color: var(--color-text);"></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeViewAllocationModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal: Status Change -->
<div class="modal" id="statusModal">
  <div class="modal-dialog" style="max-width: 380px;">
    <div class="modal-header">
      <h3>Update Allocation Status</h3>
      <button type="button" class="modal-close" onclick="closeStatusModal()">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/allocate.php">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" id="status_alloc_id" value="">
      <div class="modal-body">
        <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 6px; display: block;">Select New Status</label>
        <select name="status" id="status_alloc_select" class="form-control" style="font-size: 11px;">
          <option value="Active">Active (Open for distribution)</option>
          <option value="Completed">Completed (Fully disbursed / closed)</option>
          <option value="Archived">Archived (Stored in records)</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Status</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateAllocationModal() {
  document.getElementById('createAllocationModal').classList.add('show');
}
function closeCreateAllocationModal() {
  document.getElementById('createAllocationModal').classList.remove('show');
}

function updateTargetCountPreview() {
  const total = parseFloat(document.getElementById('alloc_total_budget').value) || 0;
  const rate = parseFloat(document.getElementById('alloc_rate_budget').value) || 0;
  const preview = document.getElementById('alloc_target_preview');
  if (rate > 0 && total >= rate) {
    const count = Math.floor(total / rate);
    preview.textContent = count.toLocaleString() + ' families';
  } else {
    preview.textContent = '0 families';
  }
}

function viewAllocationDetails(data) {
  document.getElementById('view_alloc_code_title').textContent = data.allocation_code + ' — Details';
  document.getElementById('view_alloc_program').textContent = data.program_name;
  document.getElementById('view_alloc_barangay').textContent = data.barangay_name;
  document.getElementById('view_alloc_total').textContent = '₱' + Number(data.total_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_disbursed').textContent = '₱' + Number(data.disbursed_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_balance').textContent = '₱' + Number(data.remaining_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_rate').textContent = '₱' + Number(data.amount_per_beneficiary).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_target').textContent = Number(data.target_beneficiaries).toLocaleString() + ' families';
  document.getElementById('view_alloc_funding').textContent = data.funding_source;
  document.getElementById('view_alloc_status').textContent = data.status;
  document.getElementById('view_alloc_notes').textContent = data.notes || 'No administrative notes specified.';
  document.getElementById('viewAllocationModal').classList.add('show');
}
function closeViewAllocationModal() {
  document.getElementById('viewAllocationModal').classList.remove('show');
}

function openStatusModal(id, currentStatus) {
  document.getElementById('status_alloc_id').value = id;
  document.getElementById('status_alloc_select').value = currentStatus;
  document.getElementById('statusModal').classList.add('show');
}
function closeStatusModal() {
  document.getElementById('statusModal').classList.remove('show');
}

// Close modals when clicking backdrop
window.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.classList.remove('show');
  }
});

// Auto-open and prefill if action=new is present from DSS Recommendation
document.addEventListener('DOMContentLoaded', function() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('action') === 'new') {
    const progInput = document.querySelector('input[name="program_name"]');
    const brgySelect = document.querySelector('select[name="barangay_id"]');
    const disasterSelect = document.querySelector('select[name="disaster_type"]');
    const budgetInput = document.getElementById('alloc_total_budget');
    const rateInput = document.getElementById('alloc_rate_budget');

    if (params.get('program_name') && progInput) progInput.value = params.get('program_name');
    if (params.get('barangay_id') && brgySelect) brgySelect.value = params.get('barangay_id');
    if (params.get('disaster_type') && disasterSelect) disasterSelect.value = params.get('disaster_type');
    if (params.get('budget') && budgetInput) budgetInput.value = parseFloat(params.get('budget')).toFixed(2);
    if (params.get('per_family') && rateInput) rateInput.value = parseFloat(params.get('per_family')).toFixed(2);

    updateTargetCountPreview();
    openCreateAllocationModal();
  }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
