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

$pageTitle = "Manage Resources — ICDRRMO Admin";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$activeTab = in_array($_GET['tab'] ?? '', ['allocations', 'audits'], true) ? $_GET['tab'] : 'allocations';

$filterPurok = trim($_GET['purok'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterAction = trim($_GET['action_type'] ?? '');
$search = trim($_GET['search'] ?? '');

// Focus exclusively on Hinaplanon (Barangay ID 12)
$brgyStmt = $db->query("SELECT id, name FROM barangays WHERE id = 12");
$allBarangays = $brgyStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($allBarangays)) {
    $allBarangays = [['id' => 12, 'name' => 'Hinaplanon']];
}

// Fetch Hinaplanon Puroks with household/family counts
$purokStmt = $db->query("
    SELECT p.id, p.name, p.population,
           (SELECT COUNT(*) FROM purok_households h WHERE (h.purok_id = p.id OR h.purok_name = p.name) AND h.barangay_id = 12 AND h.status = 'active') AS household_count
    FROM puroks p 
    WHERE p.barangay_id = 12 
    ORDER BY p.name ASC
");
$hinaplanonPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

$totalHinaplanonFamilies = 0;
foreach ($hinaplanonPuroks as $p) {
    $totalHinaplanonFamilies += (int)$p['household_count'];
}

// Metrics (Strictly Hinaplanon)
$totalAllocationsCount = (int)$db->query("SELECT COUNT(*) FROM financial_aid_allocations WHERE barangay_id = 12")->fetchColumn();
$totalBudgetSum = (float)$db->query("SELECT COALESCE(SUM(total_budget), 0) FROM financial_aid_allocations WHERE barangay_id = 12 AND status != 'Archived'")->fetchColumn();
$totalDisbursedSum = (float)$db->query("SELECT COALESCE(SUM(disbursed_budget), 0) FROM financial_aid_allocations WHERE barangay_id = 12 AND status != 'Archived'")->fetchColumn();
$totalRemainingSum = (float)$db->query("SELECT COALESCE(SUM(remaining_budget), 0) FROM financial_aid_allocations WHERE barangay_id = 12 AND status != 'Archived'")->fetchColumn();
$totalBeneficiariesClaimed = (int)$db->query("SELECT COUNT(*) FROM financial_aid_distributions WHERE barangay_id = 12 AND claim_status = 'Claimed'")->fetchColumn();

// Fetch Allocations Query (Strictly Hinaplanon)
$allocSql = "
    SELECT a.*, b.name AS barangay_name, u.full_name AS allocator_name,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id) AS registered_count,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Claimed') AS claimed_count
    FROM financial_aid_allocations a
    JOIN barangays b ON a.barangay_id = b.id
    LEFT JOIN users u ON a.allocated_by = u.id
    WHERE a.barangay_id = 12
";
$allocParams = [];

if (!empty($filterPurok)) {
    $allocSql .= " AND (a.target_purok = ? OR a.target_purok = 'All Puroks' OR a.notes LIKE ?)";
    $allocParams[] = $filterPurok;
    $allocParams[] = "%{$filterPurok}%";
}
if (!empty($filterStatus)) {
    $allocSql .= " AND a.status = ?";
    $allocParams[] = $filterStatus;
}
if (!empty($search)) {
    $allocSql .= " AND (a.allocation_code LIKE ? OR a.program_name LIKE ? OR a.target_purok LIKE ? OR a.funding_source LIKE ?)";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
    $allocParams[] = "%{$search}%";
}
$allocSql .= " ORDER BY a.created_at DESC";

$allocStmt = $db->prepare($allocSql);
$allocStmt->execute($allocParams);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Audits Query (Strictly Hinaplanon audit records)
$auditSql = "
    SELECT aud.*, b.name AS barangay_name, a.allocation_code, a.program_name
    FROM financial_aid_audits aud
    JOIN barangays b ON aud.barangay_id = b.id
    LEFT JOIN financial_aid_allocations a ON aud.allocation_id = a.id
    WHERE aud.barangay_id = 12
";
$auditParams = [];

if (!empty($filterPurok)) {
    $auditSql .= " AND aud.details LIKE ?";
    $auditParams[] = "%{$filterPurok}%";
}
if (!empty($filterAction)) {
    $auditSql .= " AND aud.action = ?";
    $auditParams[] = $filterAction;
}
if (!empty($search)) {
    $auditSql .= " AND (aud.details LIKE ? OR aud.actor_name LIKE ? OR a.allocation_code LIKE ?)";
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
      <h1>Manage Resources</h1>
      <p class="page-header-desc">
        Centralized disaster cash assistance programming, Hinaplanon purok fund allocation, and distribution audits.
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
      <div class="kpi-sub">Remaining balance for Barangay Hinaplanon</div>
    </div>
  </div>

    <!-- Allocations Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div class="table-responsive">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 1px solid var(--color-border); background: #FAFCFC;">
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Code</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Program & Disaster</th>
              <th style="padding: 10px 14px; text-align: left; font-size: 10.5px; font-weight: 600; color: var(--color-text-secondary);">Target Purok (Hinaplanon)</th>
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
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                      <div style="font-weight: 600; color: var(--color-text); font-size: 11px;"><?= htmlspecialchars($row['program_name']) ?></div>
                      <?php if (($row['added_type'] ?? 'ai_recommended') === 'added_by_admin'): ?>
                        <span class="badge" style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;font-size:9px;padding:1px 6px;font-weight:600;">👤 Added by Admin</span>
                      <?php else: ?>
                        <span class="badge" style="background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0;font-size:9px;padding:1px 6px;font-weight:600;">🤖 AI Recommended</span>
                      <?php endif; ?>
                    </div>
                    <div style="font-size: 10px; color: var(--color-text-muted); margin-top:2px;">
                      <?= htmlspecialchars($row['disaster_type'] ?: 'General Incident') ?> &bull; <?= htmlspecialchars($row['funding_source']) ?>
                    </div>
                  </td>
                  <td style="padding: 10px 14px; font-size: 11px; font-weight: 500; color: var(--color-text);">
                    <div style="font-weight: 600; color: var(--color-primary);"><?= htmlspecialchars($row['target_purok'] ?: 'All Puroks') ?></div>
                    <div style="font-size: 9.5px; color: var(--color-text-muted);">Brgy. <?= htmlspecialchars($row['barangay_name']) ?></div>
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
                    <?php if (($row['received_status'] ?? 'Received') === 'Pending'): ?>
                      <div style="margin-top: 3px;">
                        <span class="badge" style="background:#FFFBEB;color:#B45309;border:1px solid #FDE68A;font-size:9px;padding:1px 5px;font-weight:600;">⏳ Pending Receipt</span>
                      </div>
                    <?php elseif (($row['received_status'] ?? 'Received') === 'Received'): ?>
                      <div style="margin-top: 3px;">
                        <span class="badge" style="background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;font-size:9px;padding:1px 5px;font-weight:600;">✓ Received by Brgy</span>
                      </div>
                    <?php elseif (($row['received_status'] ?? '') === 'Not Received'): ?>
                      <div style="margin-top: 3px;">
                        <span class="badge" style="background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;font-size:9px;padding:1px 5px;font-weight:600;" title="<?= htmlspecialchars($row['receipt_remarks'] ?? 'Reported not received by barangay') ?>">✗ Not Received</span>
                      </div>
                    <?php endif; ?>
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
      </div>
    </div>
</main>

<!-- Modal: Create New Allocation -->
<div class="modal" id="createAllocationModal">
  <div class="modal-dialog" style="max-width: 680px; max-height: 92vh; display: flex; flex-direction: column;">
    <div class="modal-header" style="flex-shrink: 0;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: rgba(23, 50, 77, 0.08); color: var(--color-primary); font-size: 14px;">💰</span>
        <div>
          <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--color-primary);">Create Financial Aid Allocation</h3>
          <p style="margin: 2px 0 0 0; font-size: 11px; color: var(--color-text-muted);">Allocate emergency funds to target puroks in Hinaplanon with automated family count and AI budget recommendation</p>
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeCreateAllocationModal()">&times;</button>
    </div>
    
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/allocate.php" onsubmit="return validateAllocationForm()" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="recommendation_id" id="alloc_recommendation_id" value="">
      <input type="hidden" name="barangay_id" id="alloc_barangay_id" value="12">
      <input type="hidden" name="target_purok" id="alloc_target_purok_hidden" value="All Puroks">
      <input type="hidden" name="target_beneficiaries" id="alloc_target_beneficiaries" value="<?= $totalHinaplanonFamilies ?>">
      <input type="hidden" name="added_type" id="alloc_added_type" value="added_by_admin">

      <div class="modal-body" style="overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 14px;">
        
        <!-- Program Name -->
        <div>
          <label class="form-label form-label-required" style="font-size: 11px; font-weight: 700; margin-bottom: 4px; display: block; color: var(--color-primary);">Program Name</label>
          <input type="text" name="program_name" id="alloc_program_name" class="form-control" placeholder="e.g. Typhoon Kristine Emergency Cash Relief" required style="font-size: 11.5px; height: 34px;">
        </div>

        <!-- Target Purok Scope (Multi-Select, Select All, Disabled Total Family Button) -->
        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
            <label class="form-label form-label-required" style="font-size: 11.5px; font-weight: 700; color: var(--color-primary); margin: 0; display: flex; align-items: center; gap: 6px;">
              <span>Target Purok Selection (Hinaplanon)</span>
            </label>
            
            <div style="display: flex; align-items: center; gap: 8px;">
              <!-- Disabled button: Automated total families based on selected puroks -->
              <button type="button" class="btn" id="btn_automated_total_families" disabled title="Automated total families in selected puroks"
                style="font-size: 11px; padding: 4px 10px; background: #FFFFFF; color: #1E293B; border: 1.5px solid #CBD5E1; border-radius: 6px; font-weight: 600; cursor: not-allowed; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <span style="color: #64748B;">👨‍👩‍👧‍👦 Total Families:</span>
                <span id="lbl_automated_total_families" style="color: var(--color-primary); font-size: 12.5px; font-weight: 700; font-family: var(--font-secondary);"><?= number_format($totalHinaplanonFamilies) ?></span>
              </button>

              <div style="display: flex; gap: 4px;">
                <button type="button" class="btn btn-outline btn-xs" onclick="setAllocAllPuroksScope(true)" style="font-size: 9.5px; padding: 3px 8px; font-weight: 600;">Select All</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="setAllocAllPuroksScope(false)" style="font-size: 9.5px; padding: 3px 8px; font-weight: 600;">Clear</button>
              </div>
            </div>
          </div>

          <!-- Master Checkbox Toggle for All Puroks -->
          <div style="background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 6px; padding: 7px 10px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
            <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; font-size: 11.5px; font-weight: 600; color: var(--color-primary);">
              <input type="checkbox" id="alloc_all_puroks_toggle" onchange="onAllocAllPuroksToggleChange(this.checked)" checked style="cursor: pointer; width: 15px; height: 15px; accent-color: var(--color-primary);">
              <span>All Puroks (Barangay-Wide Hinaplanon)</span>
            </label>
            <span class="badge" id="alloc_all_puroks_badge" style="font-size: 10px; padding: 2px 7px; background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; font-weight: 600;">
              <?= count($hinaplanonPuroks) ?> Puroks (<?= number_format($totalHinaplanonFamilies) ?> Families)
            </span>
          </div>

          <!-- Quick Search Filter for Puroks -->
          <div style="position: relative; margin-bottom: 8px;">
            <input type="text" id="alloc_purok_search" class="form-control" placeholder="Search / filter puroks (e.g. Purok 1, 2a, Maharlika)..." oninput="filterAllocPurokCheckboxes(this.value)" style="font-size: 11px; padding: 4px 8px 4px 28px; height: 28px; border-radius: 5px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 9px; top: 8px; color: #94A3B8; pointer-events: none;">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
          </div>

          <!-- Purok Checkbox Grid -->
          <div id="alloc_purok_checkbox_list" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px; display: grid; grid-template-columns: repeat(auto-fill, minmax(145px, 1fr)); gap: 6px; max-height: 140px; overflow-y: auto;">
            <?php foreach ($hinaplanonPuroks as $p): ?>
              <?php 
                $hhCount = (int)($p['household_count'] ?? 0);
                $pName = clean($p['name']);
              ?>
              <label class="alloc-purok-item" data-purok="<?= $pName ?>" data-families="<?= $hhCount ?>"
                style="display: flex; align-items: center; justify-content: space-between; gap: 6px; font-size: 11px; color: var(--color-text); background: #ECFDF5; padding: 5px 8px; border-radius: 4px; border: 1px solid var(--color-primary, #17324D); margin: 0; cursor: pointer; transition: all 0.15s ease;">
                <div style="display: flex; align-items: center; gap: 6px; min-width: 0;">
                  <input type="checkbox" class="alloc-purok-cb" value="<?= $pName ?>" data-families="<?= $hhCount ?>" checked onchange="onAllocSinglePurokChange()" style="cursor: pointer; accent-color: var(--color-primary); margin: 0; flex-shrink: 0;">
                  <span style="user-select: none; line-height: 1.2; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= $pName ?></span>
                </div>
                <span class="alloc-purok-pill" data-families="<?= $hhCount ?>" style="flex-shrink: 0; font-size: 9.5px; color: var(--color-primary); font-weight: 700; background: rgba(23,50,77,0.08); padding: 1px 5px; border-radius: 3px;" title="<?= number_format($hhCount) ?> Registered Household Families">
                  <?= number_format($hhCount) ?> fam
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <!-- Selection Summary Row -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: 10.5px;">
            <div id="alloc_purok_selection_summary" style="font-weight: 600; color: var(--color-primary);">
              Selected: All Puroks (Hinaplanon) • <?= number_format($totalHinaplanonFamilies) ?> Families
            </div>
            <div style="color: var(--color-text-muted); font-size: 10px;">
              Multi-purok or all coverage
            </div>
          </div>
        </div>

        <!-- Disaster Type & Funding Source Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <label class="form-label form-label-required" style="font-size: 11px; font-weight: 700; margin-bottom: 4px; display: block; color: var(--color-primary);">Disaster / Calamity Type</label>
            <select name="disaster_type" id="alloc_disaster_type" class="form-control" style="font-size: 11px; height: 34px;" onchange="onDisasterTypeChange()">
              <option value="Typhoon">Typhoon</option>
              <option value="Flood">Flood</option>
              <option value="Landslide">Landslide</option>
              <option value="Fire">Fire</option>
              <option value="Earthquake">Earthquake</option>
              <option value="General Calamity">General Calamity</option>
            </select>
          </div>
          <div>
            <label class="form-label form-label-required" style="font-size: 11px; font-weight: 700; margin-bottom: 4px; display: block; color: var(--color-primary);">Funding Source</label>
            <input type="text" name="funding_source" id="alloc_funding_source" class="form-control" value="LGU Calamity Fund" placeholder="e.g. Quick Response Fund (QRF)" required style="font-size: 11px; height: 34px;">
          </div>
        </div>

        <!-- Total Budget & Amount per Family Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 700; margin: 0; color: var(--color-primary);">Total Budget (PHP)</label>
              <span id="alloc_budget_mode_label" style="font-size: 9.5px; color: var(--color-text-muted);">Manual or AI</span>
            </div>
            <input type="number" step="0.01" min="1" name="total_budget" id="alloc_total_budget" class="form-control" placeholder="e.g. 500000.00" required style="font-size: 11.5px; height: 34px; font-weight: 600;" oninput="onBudgetManualInput()">
          </div>
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 700; margin: 0; color: var(--color-primary);">Amount per Family (PHP)</label>
              <span id="alloc_rate_mode_label" style="font-size: 9.5px; color: var(--color-text-muted);">Auto / Per Beneficiary</span>
            </div>
            <input type="number" step="0.01" min="1" name="amount_per_beneficiary" id="alloc_rate_budget" class="form-control" placeholder="e.g. 3000.00" required style="font-size: 11.5px; height: 34px; font-weight: 600;" oninput="onRateManualInput()">
          </div>
        </div>

        <!-- AI Recommendation Trigger Button Section (Replacing Budget Source Type) -->
        <div style="background: linear-gradient(135deg, #F8FAFC 0%, #EEF2F6 100%); border: 1.5px dashed #93C5FD; border-radius: 8px; padding: 10px 14px;">
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div>
              <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--color-primary);">Smart Budget Allocation</span>
                <span id="ai_calc_status_badge" class="badge" style="display: none; font-size: 9.5px; padding: 1px 7px; background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; font-weight: 700;">✨ AI Active</span>
              </div>
              <div style="font-size: 10.5px; color: var(--color-text-secondary); margin-top: 2px;">
                Click to auto-calculate budget, or divide your entered Total Budget across selected families
              </div>
            </div>

            <!-- Prominent AI RECOMMENDATION Button -->
            <button type="button" class="btn" id="btn_ai_recommendation" onclick="triggerAiRecommendation()"
              style="background: linear-gradient(135deg, #17324D 0%, #2563EB 100%); color: #FFFFFF; border: none; font-size: 11px; font-weight: 700; letter-spacing: 0.4px; padding: 7px 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 7px; cursor: pointer; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25); transition: transform 0.15s ease, box-shadow 0.15s ease;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M12 6v6l4 2"></path>
              </svg>
              <span>🤖 AI RECOMMENDATION</span>
            </button>
          </div>

          <!-- Dynamic AI Info / Result Alert Box -->
          <div id="ai_recommendation_alert" style="display: none; margin-top: 8px; background: #FFFFFF; border-radius: 6px; padding: 8px 12px; border: 1px solid #CBD5E1; font-size: 11px;">
            <div style="display: flex; align-items: flex-start; gap: 8px;">
              <span id="ai_alert_icon" style="font-size: 14px; line-height: 1.2;">🤖</span>
              <div style="flex: 1; line-height: 1.4;">
                <div id="ai_alert_title" style="font-weight: 700; margin-bottom: 2px; color: var(--color-primary);">AI Recommendation Ready</div>
                <div id="ai_alert_msg" style="color: var(--color-text-secondary); font-size: 10.5px;">Click AI Recommendation to compute amount per family.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Computed Beneficiary & Capacity Summary Card -->
        <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 9px 14px; display: flex; justify-content: space-between; align-items: center;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 10.5px; color: var(--color-text-secondary);">Calculated Beneficiary Capacity:</span>
            <span id="alloc_target_preview" style="font-size: 12px; font-weight: 700; color: var(--color-primary); font-family: var(--font-secondary);">0 families</span>
          </div>
          <div style="font-size: 10px; color: var(--color-text-muted);">
            Target Families in Scope: <strong id="alloc_scope_families_count" style="color: var(--color-primary);"><?= number_format($totalHinaplanonFamilies) ?></strong>
          </div>
        </div>

        <!-- Administrative Notes -->
        <div>
          <label class="form-label" style="font-size: 11px; font-weight: 700; margin-bottom: 4px; display: block; color: var(--color-primary);">Administrative Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Eligibility guidelines, distribution instructions, payout schedule, or administrative remarks..." style="font-size: 11px;"></textarea>
        </div>

      </div>

      <div class="modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--color-border-light); display: flex; justify-content: space-between; align-items: center; background: #FAFBFD;">
        <div style="font-size: 10.5px; color: var(--color-text-muted);">
          All allocations are monitored under ICDRRMO Central Audit
        </div>
        <div style="display: flex; gap: 8px;">
          <button type="button" class="btn btn-outline" onclick="closeCreateAllocationModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" style="padding: 7px 18px; font-weight: 600;">Allocate Fund</button>
        </div>
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
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; border-bottom: 1px solid var(--color-border-light); padding-bottom: 10px;">
          <div>
            <div style="font-size: 10px; color: var(--color-text-muted);">PROGRAM</div>
            <div id="view_alloc_program" style="font-size: 12px; font-weight: 600; color: var(--color-primary);"></div>
          </div>
          <div>
            <div style="font-size: 10px; color: var(--color-text-muted);">BARANGAY</div>
            <div id="view_alloc_barangay" style="font-size: 12px; font-weight: 600; color: var(--color-primary);"></div>
          </div>
          <div>
            <div style="font-size: 10px; color: var(--color-text-muted);">TARGET PUROK</div>
            <div id="view_alloc_purok" style="font-size: 12px; font-weight: 600; color: var(--color-primary);"></div>
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
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Budget Source Type:</span>
            <span id="view_alloc_source_type"></span>
          </div>
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Status:</span>
            <span id="view_alloc_status" style="font-size: 11px; font-weight: 600;"></span>
          </div>
          <div>
            <span style="font-size: 10px; color: var(--color-text-muted); display: block;">Barangay Reception:</span>
            <span id="view_alloc_received_status"></span>
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

// Purok Scope & Families Helpers
function getSelectedAllocFamiliesCount() {
  const checkboxes = document.querySelectorAll('.alloc-purok-cb:checked');
  let sum = 0;
  checkboxes.forEach(cb => {
    sum += parseInt(cb.getAttribute('data-families') || '0', 10);
  });
  return sum;
}

function updateAllocPurokDisplay() {
  const allCheckboxes = document.querySelectorAll('.alloc-purok-cb');
  const checkedCheckboxes = document.querySelectorAll('.alloc-purok-cb:checked');
  const totalCount = allCheckboxes.length;
  const checkedCount = checkedCheckboxes.length;
  const totalFamilies = getSelectedAllocFamiliesCount();

  // Update disabled button display
  const disabledBtnCount = document.getElementById('lbl_automated_total_families');
  if (disabledBtnCount) {
    disabledBtnCount.textContent = totalFamilies.toLocaleString();
  }

  // Update scope families count in summary card
  const scopeFamilies = document.getElementById('alloc_scope_families_count');
  if (scopeFamilies) {
    scopeFamilies.textContent = totalFamilies.toLocaleString();
  }

  // Update hidden target beneficiaries input
  const hiddenBeneficiaries = document.getElementById('alloc_target_beneficiaries');
  if (hiddenBeneficiaries) {
    hiddenBeneficiaries.value = totalFamilies;
  }

  // Update master toggle and hidden target_purok
  const masterToggle = document.getElementById('alloc_all_puroks_toggle');
  const hiddenPurok = document.getElementById('alloc_target_purok_hidden');
  const summaryLabel = document.getElementById('alloc_purok_selection_summary');

  // Style individual checkbox items
  allCheckboxes.forEach(cb => {
    const parent = cb.closest('.alloc-purok-item');
    if (parent) {
      if (cb.checked) {
        parent.style.background = '#ECFDF5';
        parent.style.borderColor = 'var(--color-primary, #17324D)';
        parent.style.opacity = '1';
      } else {
        parent.style.background = '#F8FAFC';
        parent.style.borderColor = '#E2E8F0';
        parent.style.opacity = '0.7';
      }
    }
  });

  if (checkedCount === totalCount && totalCount > 0) {
    if (masterToggle) masterToggle.checked = true;
    if (hiddenPurok) hiddenPurok.value = 'All Puroks';
    if (summaryLabel) {
      summaryLabel.innerHTML = 'Selected: <strong>All Puroks (Hinaplanon)</strong> • ' + totalFamilies.toLocaleString() + ' Families';
    }
  } else if (checkedCount > 0) {
    if (masterToggle) masterToggle.checked = false;
    const selectedNames = Array.from(checkedCheckboxes).map(cb => cb.value.trim());
    if (hiddenPurok) hiddenPurok.value = selectedNames.join(', ');
    if (summaryLabel) {
      summaryLabel.innerHTML = 'Selected: <strong>' + checkedCount + ' of ' + totalCount + ' Puroks</strong> • ' + totalFamilies.toLocaleString() + ' Families';
    }
  } else {
    if (masterToggle) masterToggle.checked = false;
    if (hiddenPurok) hiddenPurok.value = '';
    if (summaryLabel) {
      summaryLabel.innerHTML = '<span style="color:#DC2626;font-weight:700;">⚠️ No Purok selected (Please select at least 1 purok)</span>';
    }
  }

  updateTargetCountPreview();
}

function setAllocAllPuroksScope(selectAll) {
  const checkboxes = document.querySelectorAll('.alloc-purok-cb');
  checkboxes.forEach(cb => {
    cb.checked = selectAll;
  });
  updateAllocPurokDisplay();
}

function onAllocAllPuroksToggleChange(checked) {
  setAllocAllPuroksScope(checked);
}

function onAllocSinglePurokChange() {
  updateAllocPurokDisplay();
}

function filterAllocPurokCheckboxes(query) {
  const q = (query || '').toLowerCase().trim();
  const items = document.querySelectorAll('.alloc-purok-item');
  items.forEach(item => {
    const purokName = (item.getAttribute('data-purok') || '').toLowerCase();
    if (!q || purokName.includes(q)) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
}

function onDisasterTypeChange() {
  const addedType = document.getElementById('alloc_added_type').value;
  const budgetVal = parseFloat(document.getElementById('alloc_total_budget').value) || 0;
  if (addedType === 'ai_recommended' && budgetVal > 0) {
    triggerAiRecommendation();
  }
}

function onBudgetManualInput() {
  const budgetInput = document.getElementById('alloc_total_budget');
  const typeHidden = document.getElementById('alloc_added_type');
  const budgetModeLabel = document.getElementById('alloc_budget_mode_label');
  const statusBadge = document.getElementById('ai_calc_status_badge');

  if (typeHidden && typeHidden.value === 'ai_recommended') {
    typeHidden.value = 'added_by_admin';
    if (statusBadge) statusBadge.style.display = 'none';
  }
  if (budgetModeLabel) {
    budgetModeLabel.textContent = budgetInput.value ? 'Manual Input' : 'Manual or AI';
  }
  updateTargetCountPreview();
}

function onRateManualInput() {
  const typeHidden = document.getElementById('alloc_added_type');
  const rateModeLabel = document.getElementById('alloc_rate_mode_label');
  const statusBadge = document.getElementById('ai_calc_status_badge');

  if (typeHidden && typeHidden.value === 'ai_recommended') {
    typeHidden.value = 'added_by_admin';
    if (statusBadge) statusBadge.style.display = 'none';
  }
  if (rateModeLabel) {
    rateModeLabel.textContent = 'Manual Input';
  }
  updateTargetCountPreview();
}

function triggerAiRecommendation() {
  const totalFamilies = getSelectedAllocFamiliesCount();
  if (totalFamilies <= 0) {
    alert('Please select at least one purok with registered families before running AI Recommendation.');
    return;
  }

  const budgetInput = document.getElementById('alloc_total_budget');
  const rateInput = document.getElementById('alloc_rate_budget');
  const disasterSelect = document.getElementById('alloc_disaster_type');
  const disasterType = disasterSelect ? disasterSelect.value : 'General Calamity';
  const typeHidden = document.getElementById('alloc_added_type');
  const statusBadge = document.getElementById('ai_calc_status_badge');
  const alertBox = document.getElementById('ai_recommendation_alert');
  const alertTitle = document.getElementById('ai_alert_title');
  const alertMsg = document.getElementById('ai_alert_msg');
  const budgetModeLabel = document.getElementById('alloc_budget_mode_label');
  const rateModeLabel = document.getElementById('alloc_rate_mode_label');

  const manualTotalBudget = parseFloat(budgetInput.value) || 0;

  if (manualTotalBudget > 0) {
    // Mode 1: User entered manual Total Budget -> Automatically divide Total Budget by Total Families
    const amountPerFamily = Math.round((manualTotalBudget / totalFamilies) * 100) / 100;
    rateInput.value = amountPerFamily.toFixed(2);
    typeHidden.value = 'ai_recommended';

    if (budgetModeLabel) budgetModeLabel.textContent = 'User Budget';
    if (rateModeLabel) rateModeLabel.textContent = 'AI Calculated Rate';
    if (statusBadge) statusBadge.style.display = 'inline-flex';

    if (alertBox) {
      alertBox.style.display = 'block';
      alertBox.style.background = '#EFF6FF';
      alertBox.style.borderColor = '#BFDBFE';
      alertBox.style.color = '#1E40AF';
      alertTitle.textContent = 'AI Calculation: Budget Divided Across Selected Families';
      alertMsg.innerHTML = 'Total Budget <strong>₱' + manualTotalBudget.toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong> automatically divided across <strong>' + totalFamilies.toLocaleString() + ' selected families</strong> = <strong>₱' + amountPerFamily.toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong> per family.';
    }
  } else {
    // Mode 2: User has NOT entered manual Total Budget -> Automated benchmark rate and total budget
    let benchmarkRate = 3000;
    if (disasterType === 'Fire' || disasterType === 'Earthquake') {
      benchmarkRate = 5000;
    } else if (disasterType === 'Typhoon' || disasterType === 'Landslide') {
      benchmarkRate = 4000;
    } else {
      benchmarkRate = 3000;
    }

    const calculatedTotal = totalFamilies * benchmarkRate;
    budgetInput.value = calculatedTotal.toFixed(2);
    rateInput.value = benchmarkRate.toFixed(2);
    typeHidden.value = 'ai_recommended';

    if (budgetModeLabel) budgetModeLabel.textContent = 'AI Calculated Total';
    if (rateModeLabel) rateModeLabel.textContent = 'AI Calamity Rate';
    if (statusBadge) statusBadge.style.display = 'inline-flex';

    if (alertBox) {
      alertBox.style.display = 'block';
      alertBox.style.background = '#F0FDF4';
      alertBox.style.borderColor = '#BBF7D0';
      alertBox.style.color = '#166534';
      alertTitle.textContent = 'AI Calamity Relief Benchmark Applied';
      alertMsg.innerHTML = 'Automated standard rate of <strong>₱' + benchmarkRate.toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong> per family (' + disasterType + ') × <strong>' + totalFamilies.toLocaleString() + ' families</strong> = <strong>₱' + calculatedTotal.toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong> total budget.';
    }
  }

  updateTargetCountPreview();
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

function validateAllocationForm() {
  const targetPurok = (document.getElementById('alloc_target_purok_hidden').value || '').trim();
  if (!targetPurok) {
    alert('Please select at least one target purok before allocating funds.');
    return false;
  }

  const total = parseFloat(document.getElementById('alloc_total_budget').value) || 0;
  const rate = parseFloat(document.getElementById('alloc_rate_budget').value) || 0;

  if (total <= 0) {
    alert('Please enter a valid Total Budget or use the AI Recommendation button.');
    document.getElementById('alloc_total_budget').focus();
    return false;
  }

  if (rate <= 0) {
    alert('Please specify a valid Amount per Family or click AI Recommendation.');
    document.getElementById('alloc_rate_budget').focus();
    return false;
  }

  if (rate > total) {
    alert('Amount per family cannot exceed the total budget.');
    document.getElementById('alloc_rate_budget').focus();
    return false;
  }

  return true;
}

function viewAllocationDetails(data) {
  document.getElementById('view_alloc_code_title').textContent = data.allocation_code + ' — Details';
  document.getElementById('view_alloc_program').textContent = data.program_name;
  document.getElementById('view_alloc_barangay').textContent = data.barangay_name;
  document.getElementById('view_alloc_purok').textContent = data.target_purok || 'All Puroks';
  document.getElementById('view_alloc_total').textContent = '₱' + Number(data.total_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_disbursed').textContent = '₱' + Number(data.disbursed_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_balance').textContent = '₱' + Number(data.remaining_budget).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_rate').textContent = '₱' + Number(data.amount_per_beneficiary).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('view_alloc_target').textContent = Number(data.target_beneficiaries).toLocaleString() + ' families';
  document.getElementById('view_alloc_funding').textContent = data.funding_source;
  const isCustom = data.added_type === 'added_by_admin';
  document.getElementById('view_alloc_source_type').innerHTML = isCustom 
    ? '<span class="badge" style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;font-size:9.5px;padding:2px 7px;font-weight:600;">👤 Added by Admin</span>' 
    : '<span class="badge" style="background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0;font-size:9.5px;padding:2px 7px;font-weight:600;">🤖 AI Recommended</span>';
  document.getElementById('view_alloc_status').textContent = data.status;
  const recStatus = data.received_status || 'Received';
  let recBadge = '';
  if (recStatus === 'Pending') {
    recBadge = '<span class="badge" style="background:#FFFBEB;color:#B45309;border:1px solid #FDE68A;font-size:9.5px;padding:2px 7px;font-weight:600;">⏳ Pending Receipt</span>';
  } else if (recStatus === 'Not Received') {
    recBadge = '<span class="badge" style="background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;font-size:9.5px;padding:2px 7px;font-weight:600;">✗ Not Received</span>' + (data.receipt_remarks ? '<div style="font-size:9.5px;color:#991B1B;margin-top:2px;">Reason: ' + data.receipt_remarks + '</div>' : '');
  } else {
    recBadge = '<span class="badge" style="background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;font-size:9.5px;padding:2px 7px;font-weight:600;">✓ Received by Barangay</span>';
  }
  document.getElementById('view_alloc_received_status').innerHTML = recBadge;
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
  // Initialize purok selection display
  updateAllocPurokDisplay();

  const params = new URLSearchParams(window.location.search);
  if (params.get('action') === 'new') {
    const progInput = document.querySelector('input[name="program_name"]');
    const brgyInput = document.getElementById('alloc_barangay_id');
    const disasterSelect = document.querySelector('select[name="disaster_type"]');
    const budgetInput = document.getElementById('alloc_total_budget');
    const rateInput = document.getElementById('alloc_rate_budget');
    const fundingInput = document.querySelector('input[name="funding_source"]');
    const typeHidden = document.getElementById('alloc_added_type');
    const recInput = document.getElementById('alloc_recommendation_id');

    if (brgyInput) brgyInput.value = '12';
    if (params.get('program_name') && progInput) progInput.value = params.get('program_name');
    if (params.get('disaster_type') && disasterSelect) disasterSelect.value = params.get('disaster_type');
    if (params.get('funding_source') && fundingInput) fundingInput.value = params.get('funding_source');
    if (params.get('recommendation_id') && recInput) recInput.value = params.get('recommendation_id');

    // Handle target_purok parameter
    const paramPurok = params.get('target_purok');
    if (paramPurok) {
      if (paramPurok === 'All Puroks' || paramPurok === 'All') {
        setAllocAllPuroksScope(true);
      } else {
        const targetList = paramPurok.split(',').map(s => s.trim().toLowerCase());
        const checkboxes = document.querySelectorAll('.alloc-purok-cb');
        checkboxes.forEach(cb => {
          cb.checked = targetList.includes(cb.value.trim().toLowerCase());
        });
        updateAllocPurokDisplay();
      }
    }

    if (params.get('budget') && budgetInput) budgetInput.value = parseFloat(params.get('budget')).toFixed(2);
    if (params.get('per_family') && rateInput) rateInput.value = parseFloat(params.get('per_family')).toFixed(2);
    if (params.get('added_type') && typeHidden) typeHidden.value = params.get('added_type');

    if (params.get('budget') || params.get('per_family')) {
      if (typeHidden) typeHidden.value = 'ai_recommended';
      const statusBadge = document.getElementById('ai_calc_status_badge');
      if (statusBadge) statusBadge.style.display = 'inline-flex';
    }

    updateTargetCountPreview();
    openCreateAllocationModal();
  }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
