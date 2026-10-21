<?php
// ============================================================================
// Views (Barangay Head): Financial Aid Distribution Module
// Dual-Table Dynamic Architecture:
// 1. Table 1 (Left): Financial Aid Received (Allocations from ICDRRMO)
// 2. Table 2 (Right): Disbursement Registry & Beneficiary Ledger (Dynamic per batch)
// Live auto-computation of remaining balance, capacity, and recipient claims.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    die("Barangay jurisdiction not assigned.");
}

$pageTitle = "Financial Aid Distribution — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Fetch all active/completed allocations received for this barangay
$allocStmt = $db->prepare("
    SELECT a.*,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id) AS total_recipients,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Claimed') AS claimed_recipients,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Pending') AS pending_recipients
    FROM financial_aid_allocations a
    WHERE a.barangay_id = ? AND a.status != 'Archived'
    ORDER BY a.created_at DESC
");
$allocStmt->execute([$barangayId]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine initial selected allocation ID (from query or first available)
$selectedAllocId = !empty($_GET['selected_id']) ? (int)$_GET['selected_id'] : (!empty($allocations) ? (int)$allocations[0]['id'] : 0);

// Fetch all beneficiary distributions for this barangay (with household details)
$distStmt = $db->prepare("
    SELECT d.*, a.allocation_code, a.program_name, a.amount_per_beneficiary, u.full_name AS disbursing_officer,
           h.household_code, h.family_name, h.pwd_count, h.seniors_count
    FROM financial_aid_distributions d
    JOIN financial_aid_allocations a ON d.allocation_id = a.id
    LEFT JOIN users u ON d.disbursed_by = u.id
    LEFT JOIN purok_households h ON d.household_id = h.id
    WHERE d.barangay_id = ?
    ORDER BY d.created_at DESC
");
$distStmt->execute([$barangayId]);
$allDistributions = $distStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangay puroks for beneficiary entry and filtering
$purokStmt = $db->prepare("SELECT name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$barangayPuroks = $purokStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch active household families with 1 authorized representative and PWD / demographic status
$hhStmt = $db->prepare("
    SELECT h.id, h.purok_id, h.purok_name, h.household_code, h.family_name, 
           h.head_full_name, h.head_phone, h.head_relation, h.economic_status,
           h.evacuation_priority, h.pwd_count, h.seniors_count, h.minors_count,
           h.total_members, h.authorized_id_type, h.authorized_id_number,
           r.username AS resident_username
    FROM purok_households h
    LEFT JOIN residents r ON h.head_resident_id = r.id
    WHERE h.barangay_id = ? AND h.status = 'active'
    ORDER BY h.purok_name ASC, h.family_name ASC
");
$hhStmt->execute([$barangayId]);
$householdFamilies = $hhStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch registered residents for fallback autofill
$resStmt = $db->prepare("
    SELECT id, full_name, purok, phone 
    FROM residents 
    WHERE barangay_id = ? AND status = 'active'
    ORDER BY full_name ASC
");
$resStmt->execute([$barangayId]);
$residentList = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// Global Telemetry Metrics
$totalAllocatedSum = 0.0;
$totalDisbursedSum = 0.0;
$totalBalanceSum = 0.0;
$totalClaimedCount = 0;

foreach ($allocations as $al) {
    $totalAllocatedSum += (float)$al['total_budget'];
    $totalDisbursedSum += (float)$al['disbursed_budget'];
    $totalBalanceSum += (float)$al['remaining_budget'];
    $totalClaimedCount += (int)$al['claimed_recipients'];
}

// Fetch Barangay Distribution Audits
$auditStmt = $db->prepare("
    SELECT aud.*, a.allocation_code, a.program_name
    FROM financial_aid_audits aud
    LEFT JOIN financial_aid_allocations a ON aud.allocation_id = a.id
    WHERE aud.barangay_id = ?
    ORDER BY aud.created_at DESC 
    LIMIT 200
");
$auditStmt->execute([$barangayId]);
$audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Clean Anti-slop 2-Table Master-Detail Architecture */
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

/* Two-Table Master-Detail Layout */
.dual-table-container {
  display: grid;
  grid-template-columns: 460px 1fr;
  gap: var(--space-4);
  align-items: start;
}
@media (max-width: 1200px) {
  .dual-table-container {
    grid-template-columns: 1fr;
  }
}

.table-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-primary);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.table-card-header {
  padding: 12px 16px;
  background: #FAFCFC;
  border-bottom: 1px solid var(--color-border-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.table-card-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Table 1: Master Allocation Rows */
.alloc-row {
  cursor: pointer;
  border-bottom: 1px solid var(--color-border-light);
  transition: background 0.15s ease, border-color 0.15s ease;
}
.alloc-row:hover {
  background: #F8FAFC;
}
.alloc-row.active {
  background: #F0F4F8;
  border-left: 3px solid var(--color-primary);
}
.alloc-row.active td {
  color: var(--color-text);
}

/* Table 2: Detail Telemetry Sub-bar */
.detail-telemetry-bar {
  padding: 10px 16px;
  background: #F8FAFC;
  border-bottom: 1px solid var(--color-border-light);
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 12px;
  align-items: center;
}
.detail-telemetry-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.detail-telemetry-label {
  font-size: 9.5px;
  color: var(--color-text-secondary);
  text-transform: uppercase;
  font-weight: 600;
}
.detail-telemetry-val {
  font-size: 13px;
  font-weight: 700;
  font-family: var(--font-secondary);
  color: var(--color-primary);
}

/* Status Badges */
.status-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.status-badge.claimed {
  background: #EAF4EB;
  color: #2E7D32;
  border: 1px solid #BCE3C1;
}
.status-badge.pending {
  background: #FEF8E8;
  color: #8A6402;
  border: 1px solid #FCE6AA;
}
.status-badge.unclaimed {
  background: #F0F3F4;
  color: #66737D;
  border: 1px solid #D9E0E3;
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

.sms-badge {
  display: inline-block;
  font-size: 9px;
  font-weight: 600;
  padding: 1px 5px;
  border-radius: 3px;
}
.sms-sent { background: #EAF4EB; color: #2E7D32; }
.sms-failed { background: #FDE8E8; color: #C62828; }
.sms-not_sent { background: #F0F3F4; color: #8A96A0; }

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

/* Modals */
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
  max-width: 620px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}
.modal-dialog.modal-lg { max-width: 840px; }
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
      <h1>Financial Aid Distribution — Barangay <?= clean($barangay['name']) ?></h1>
      <p class="page-header-desc">
        Select an allocated calamity aid fund from ICDRRMO to dynamically inspect and manage recipient distributions, track balances, and audit releases.
      </p>
    </div>
    <div class="page-header-actions" style="display: flex; gap: 8px; align-items: center;">
      <!-- Direct Audit Records Button -->
      <button type="button" class="btn btn-outline" onclick="openAuditRecordsModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
        Audit Records
      </button>

      <!-- Primary Action: Distribute Aid -->
      <button type="button" class="btn btn-primary" onclick="openDistributeModal()" <?= empty($allocations) ? 'disabled title="No active aid allocation from ICDRRMO"' : '' ?>>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Distribute Aid
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Row -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-title">Total Calamity Aid Received</div>
      <div class="kpi-val">&#8369;<?= number_format($totalAllocatedSum, 2) ?></div>
      <div class="kpi-sub"><?= count($allocations) ?> program batches from ICDRRMO</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Disbursed to Residents</div>
      <div class="kpi-val">&#8369;<?= number_format($totalDisbursedSum, 2) ?></div>
      <div class="kpi-sub"><?= number_format($totalClaimedCount) ?> completed beneficiary payouts</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Overall Remaining Balance</div>
      <div class="kpi-val">&#8369;<?= number_format($totalBalanceSum, 2) ?></div>
      <div class="kpi-sub">Undisbursed funds across all programs</div>
    </div>
  </div>

  <?php if (empty($allocations)): ?>
    <div class="card" style="padding: 18px; margin-bottom: var(--space-4); background: #FEF8E8; border: 1px solid #FCE6AA;">
      <div style="font-weight: 600; color: #8A6402; font-size: 12px;">No Financial Aid Allocation Received Yet</div>
      <div style="font-size: 11px; color: #8A6402; margin-top: 2px;">
        There are currently no financial assistance allocations assigned to Barangay <?= clean($barangay['name']) ?> by ICDRRMO. Once an allocation is issued, it will appear here for beneficiary distribution.
      </div>
    </div>
  <?php else: ?>

    <!-- Two-Table Dynamic Master-Detail Layout -->
    <div class="dual-table-container">

      <!-- Table 1 (Left): Financial Aid Received -->
      <div class="table-card">
        <div class="table-card-header">
          <div class="table-card-title">
            <span>Financial Aid Received</span>
            <span style="font-size: 10px; font-weight: 700; background: #EBF3FC; color: #17324D; padding: 2px 7px; border-radius: 999px;">
              <?= count($allocations) ?>
            </span>
          </div>
          <span style="font-size: 10px; color: var(--color-text-muted);">Click a row to inspect</span>
        </div>

        <div style="padding: 8px 12px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF;">
          <input type="text" id="allocSearchInput" class="form-control" placeholder="Search aid programs..." style="font-size: 10.5px; padding: 5px 9px;" oninput="filterAllocationsTable(this.value)">
        </div>

        <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
          <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
                <th style="padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary);">Program & Code</th>
                <th style="padding: 9px 12px; text-align: right; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 110px;">Received Fund</th>
                <th style="padding: 9px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 75px;">Status</th>
              </tr>
            </thead>
            <tbody id="allocationsTableBody">
              <?php foreach ($allocations as $idx => $row): 
                $isSelected = ($row['id'] === $selectedAllocId);
              ?>
                <tr class="alloc-row <?= $isSelected ? 'active' : '' ?>" id="alloc_row_<?= $row['id'] ?>" onclick="selectAllocation(<?= $row['id'] ?>)">
                  <td style="padding: 10px 12px;">
                    <div style="font-weight: 600; font-size: 11px; color: var(--color-primary); line-height: 1.3;">
                      <?= htmlspecialchars($row['program_name']) ?>
                    </div>
                    <div style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 2px; font-family: var(--font-secondary);">
                      <?= htmlspecialchars($row['allocation_code']) ?> &bull; &#8369;<?= number_format($row['amount_per_beneficiary'], 2) ?>/family
                    </div>
                  </td>
                  <td style="padding: 10px 12px; text-align: right;">
                    <div style="font-family: var(--font-secondary); font-weight: 700; font-size: 11.5px; color: var(--color-text);">
                      &#8369;<?= number_format($row['total_budget'], 2) ?>
                    </div>
                    <div style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 2px;">
                      Rem: &#8369;<?= number_format($row['remaining_budget'], 2) ?>
                    </div>
                  </td>
                  <td style="padding: 10px 12px; text-align: center;">
                    <span class="status-badge <?= strtolower($row['status']) ?>">
                      <?= htmlspecialchars($row['status']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table 2 (Right): Disbursement Registry & Beneficiary Ledger -->
      <div class="table-card">
        
        <!-- Table 2 Header with Dynamic Program Title & Add Button -->
        <div class="table-card-header">
          <div>
            <div class="table-card-title">
              <span id="detailProgramTitle">Disbursement Registry & Beneficiary Ledger</span>
            </div>
            <div id="detailProgramCode" style="font-size: 10px; color: var(--color-text-muted); margin-top: 2px; font-family: var(--font-secondary);">
              Loading selected allocation...
            </div>
          </div>
          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-outline" onclick="openEligibleFamiliesModal()" style="padding: 5px 10px; font-size: 10.5px;">
              Eligible Families Roster
            </button>
            <button type="button" class="btn btn-primary" onclick="openDistributeModalForCurrent()" style="padding: 5px 10px; font-size: 10.5px;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              + Distribute Aid
            </button>
          </div>
        </div>

        <!-- Dynamic Live Auto-Computed Telemetry Sub-bar -->
        <div class="detail-telemetry-bar">
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Received Budget</span>
            <span class="detail-telemetry-val" id="detailTotalBudget">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Total Disbursed</span>
            <span class="detail-telemetry-val" id="detailDisbursedBudget" style="color: var(--color-success);">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Remaining Balance</span>
            <span class="detail-telemetry-val" id="detailRemainingBudget">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Beneficiaries Served</span>
            <span class="detail-telemetry-val" id="detailBeneficiariesRatio">0 / 0</span>
          </div>
        </div>

        <!-- Filter bar for beneficiaries inside this program -->
        <div style="padding: 8px 14px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
          <div style="flex: 1; min-width: 170px;">
            <input type="text" id="beneficiarySearchInput" class="form-control" placeholder="Search beneficiary by name, ref no, or phone..." style="font-size: 10.5px; padding: 5px 9px;" oninput="filterBeneficiariesTable()">
          </div>
          <div style="width: 140px;">
            <select id="beneficiaryPurokFilter" class="form-control" style="font-size: 10.5px; padding: 5px 8px;" onchange="filterBeneficiariesTable()">
              <option value="">All Puroks</option>
              <?php foreach ($barangayPuroks as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="width: 130px;">
            <select id="beneficiaryStatusFilter" class="form-control" style="font-size: 10.5px; padding: 5px 8px;" onchange="filterBeneficiariesTable()">
              <option value="">All Statuses</option>
              <option value="Claimed">Claimed</option>
              <option value="Pending">Pending</option>
              <option value="Unclaimed">Unclaimed</option>
            </select>
          </div>
        </div>

        <!-- Beneficiaries Table -->
        <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
          <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
                <th style="padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 130px;">Reference No</th>
                <th style="padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary);">Beneficiary & Purok</th>
                <th style="padding: 9px 12px; text-align: right; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 100px;">Amount</th>
                <th style="padding: 9px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 85px;">Status</th>
                <th style="padding: 9px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 65px;">SMS</th>
                <th style="padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 120px;">Claim Date</th>
                <th style="padding: 9px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 110px;">Actions</th>
              </tr>
            </thead>
            <tbody id="beneficiariesTableBody">
              <!-- Dynamically rendered via JavaScript based on selected allocation -->
            </tbody>
          </table>
        </div>

      </div>

    </div>

  <?php endif; ?>
</main>

<!-- Modal: Distribute Financial Aid -->
<div class="modal" id="distributeModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Distribute Financial Assistance</h3>
      <button type="button" class="modal-close" onclick="closeDistributeModal()">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/distribute.php" id="distributeForm">
      <input type="hidden" name="action" value="add_beneficiary">
      <input type="hidden" name="resident_id" id="dist_resident_id" value="">
      <input type="hidden" name="household_id" id="dist_household_id" value="">
      <input type="hidden" name="return_url" id="dist_return_url" value="">

      <div class="modal-body">
        <div style="display: flex; flex-direction: column; gap: 12px;">

          <!-- Program Selection -->
          <div>
            <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Select Financial Aid Program</label>
            <select name="allocation_id" id="dist_alloc_select" class="form-control" required style="font-size: 11px;" onchange="onProgramSelected(this)">
              <option value="">-- Choose Active Aid Allocation --</option>
              <?php foreach ($allocations as $a): ?>
                <option value="<?= $a['id'] ?>" data-rate="<?= $a['amount_per_beneficiary'] ?>" data-balance="<?= $a['remaining_budget'] ?>" <?= $a['id'] === $selectedAllocId ? 'selected' : '' ?>>
                  <?= htmlspecialchars($a['allocation_code'] . ' — ' . $a['program_name']) ?> (Avail: &#8369;<?= number_format($a['remaining_budget'], 2) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Select Household Family na Pwede Bigyan ng Ayuda -->
          <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 10px 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <label class="form-label" style="font-size: 10.5px; font-weight: 600; color: var(--color-primary); margin-bottom: 0;">
                Select Household Family (Pamilyang Pwede Bigyan ng Ayuda)
              </label>
              <span id="modalFamilyCounter" style="font-size: 9.5px; color: var(--color-text-muted);">
                <?= count($householdFamilies) ?> families
              </span>
            </div>

            <!-- Two compact filters: Purok & Priority/PWD -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
              <div>
                <label style="font-size: 9.5px; font-weight: 600; color: var(--color-text-secondary); display: block; margin-bottom: 2px;">Filter Purok</label>
                <select id="dist_family_purok_filter" class="form-control" style="font-size: 10px; height: 28px; padding: 3px 6px;" onchange="updateModalFamilyOptions()">
                  <option value="">All Puroks</option>
                  <?php foreach ($barangayPuroks as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label style="font-size: 9.5px; font-weight: 600; color: var(--color-text-secondary); display: block; margin-bottom: 2px;">Priority / PWD Filter</label>
                <select id="dist_family_priority_filter" class="form-control" style="font-size: 10px; height: 28px; padding: 3px 6px;" onchange="updateModalFamilyOptions()">
                  <option value="">All Families</option>
                  <option value="pwd">With PWD Members (Priority)</option>
                  <option value="seniors">With Senior Citizens</option>
                  <option value="indigent">Indigent / Low Income</option>
                  <option value="unclaimed">Not Yet Claimed (This Program)</option>
                </select>
              </div>
            </div>

            <select id="dist_family_select" class="form-control" style="font-size: 10.5px;" onchange="onFamilySelected(this)">
              <option value="">-- Choose Household Family (Auto-fills details) --</option>
              <?php foreach ($householdFamilies as $fam): 
                $demoBadges = [];
                if ($fam['pwd_count'] > 0) $demoBadges[] = "PWD: {$fam['pwd_count']}";
                if ($fam['seniors_count'] > 0) $demoBadges[] = "Seniors: {$fam['seniors_count']}";
                $demoStr = !empty($demoBadges) ? ' [' . implode(', ', $demoBadges) . ']' : '';
              ?>
                <option value="<?= $fam['id'] ?>" 
                        data-purok="<?= htmlspecialchars($fam['purok_name']) ?>" 
                        data-head="<?= htmlspecialchars($fam['head_full_name']) ?>" 
                        data-phone="<?= htmlspecialchars($fam['head_phone']) ?>" 
                        data-code="<?= htmlspecialchars($fam['household_code']) ?>"
                        data-name="<?= htmlspecialchars($fam['family_name']) ?>"
                        data-pwd="<?= (int)$fam['pwd_count'] ?>"
                        data-seniors="<?= (int)$fam['seniors_count'] ?>">
                  <?= htmlspecialchars($fam['family_name']) ?> — Rep: <?= htmlspecialchars($fam['head_full_name']) ?> (<?= htmlspecialchars($fam['purok_name']) ?>)<?= $demoStr ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div id="familyClaimWarning" style="display:none;margin-top:6px;padding:6px 8px;background:#FEF8E8;border:1px solid #FCE6AA;border-radius:4px;font-size:9.5px;color:#8A6402;">
              <strong>Notice:</strong> This family already has a recorded disbursement in this program.
            </div>
          </div>

          <!-- Beneficiary Details -->
          <div>
            <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Beneficiary Full Name (Authorized Representative)</label>
            <input type="text" name="beneficiary_name" id="dist_beneficiary_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required style="font-size: 11px;">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Purok / Zone</label>
              <input type="text" name="purok" id="dist_purok" class="form-control" list="purok_suggestions" placeholder="e.g. Purok 1" required style="font-size: 11px;">
              <datalist id="purok_suggestions">
                <?php foreach ($barangayPuroks as $p): ?>
                  <option value="<?= htmlspecialchars($p) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Mobile Number</label>
              <input type="text" name="phone" id="dist_phone" class="form-control" placeholder="09xxxxxxxxx" required style="font-size: 11px;">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Payout Amount (PHP)</label>
              <input type="number" step="0.01" min="1" name="amount" id="dist_amount" class="form-control" placeholder="0.00" required style="font-size: 11px;">
            </div>
            <div>
              <label class="form-label form-label-required" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Distribution Action</label>
              <select name="claim_status" class="form-control" style="font-size: 11px;">
                <option value="Claimed" selected>Direct Payout (Claimed Now)</option>
                <option value="Pending">Queue Schedule (Pending)</option>
              </select>
            </div>
          </div>

          <!-- SMS dispatch checkbox -->
          <div style="display: flex; align-items: center; gap: 8px; padding-top: 4px;">
            <input type="checkbox" name="send_sms" id="dist_send_sms" value="1" checked style="cursor: pointer;">
            <label for="dist_send_sms" style="font-size: 11px; color: var(--color-text); cursor: pointer; user-select: none;">
              Send official SMS payout notification to beneficiary
            </label>
          </div>

          <div>
            <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Remarks / Notes</label>
            <input type="text" name="remarks" class="form-control" placeholder="ID presented, family representative, or notes..." style="font-size: 11px;">
          </div>

        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeDistributeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Process Disbursement</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Eligible Families (Ayuda Roster) -->
<div class="modal" id="eligibleFamiliesModal">
  <div class="modal-dialog modal-lg" style="max-width: 920px;">
    <div class="modal-header">
      <div>
        <h3 style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary);">
          Eligible Families Roster (Pamilyang Pwede Bigyan ng Ayuda) — <span id="efModalProgramBadge" style="font-weight:600;color:var(--color-text-secondary);"></span>
        </h3>
        <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">
          Master directory of household families with 1 Authorized Representative, PWD count, and payout eligibility.
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeEligibleFamiliesModal()">&times;</button>
    </div>

    <!-- Telemetry Sub-bar -->
    <div class="detail-telemetry-bar" style="grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));">
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Total Families</span>
        <span class="detail-telemetry-val" id="efTotalFamiliesCount">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Families With PWD</span>
        <span class="detail-telemetry-val" id="efPwdFamiliesCount" style="color: #8A6402;">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Senior Families</span>
        <span class="detail-telemetry-val" id="efSeniorFamiliesCount">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Claimed This Batch</span>
        <span class="detail-telemetry-val" id="efClaimedCount" style="color: var(--color-success);">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Unclaimed (Eligible)</span>
        <span class="detail-telemetry-val" id="efUnclaimedCount" style="color: var(--color-primary);">0</span>
      </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding: 10px 16px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
      <div style="flex: 1; min-width: 170px;">
        <input type="text" id="efSearchInput" class="form-control" placeholder="Search family, head, or code..." style="font-size: 10px; height: 28px; padding: 4px 8px;" oninput="renderEligibleFamiliesTable()">
      </div>
      <div style="width: 135px;">
        <select id="efPurokFilter" class="form-control" style="font-size: 10px; height: 28px; padding: 4px 8px;" onchange="renderEligibleFamiliesTable()">
          <option value="">All Puroks</option>
          <?php foreach ($barangayPuroks as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="width: 145px;">
        <select id="efPriorityFilter" class="form-control" style="font-size: 10px; height: 28px; padding: 4px 8px;" onchange="renderEligibleFamiliesTable()">
          <option value="">All Families</option>
          <option value="pwd">With PWD Members</option>
          <option value="seniors">With Senior Citizens</option>
          <option value="indigent">Indigent / Low Income</option>
        </select>
      </div>
      <div style="width: 135px;">
        <select id="efClaimFilter" class="form-control" style="font-size: 10px; height: 28px; padding: 4px 8px;" onchange="renderEligibleFamiliesTable()">
          <option value="">All Claim Status</option>
          <option value="unclaimed" selected>Not Yet Claimed</option>
          <option value="claimed">Already Claimed</option>
        </select>
      </div>
    </div>

    <!-- Table Body -->
    <div class="modal-body" style="padding: 0; max-height: 480px; overflow-y: auto;">
      <table class="data-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
        <thead>
          <tr style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 2;">
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; width: 90px;">HH Code</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600;">Family Name</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600;">1 Authorized Rep & Contact</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; width: 85px;">Purok</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: center; width: 120px;">Demographics</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: center; width: 110px;">Aid Status</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: right; width: 110px;">Action</th>
          </tr>
        </thead>
        <tbody id="efTableBody">
          <!-- Dynamically populated via renderEligibleFamiliesTable() -->
        </tbody>
      </table>
    </div>

    <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
      <span style="font-size: 10px; color: var(--color-text-muted);" id="efFooterSummary"></span>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeEligibleFamiliesModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal: Audit Records Ledger -->
<div class="modal" id="auditRecordsModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-header">
      <h3>Financial Aid Audit Records — Barangay <?= clean($barangay['name']) ?></h3>
      <button type="button" class="modal-close" onclick="closeAuditRecordsModal()">&times;</button>
    </div>
    <div class="modal-body" style="padding: 0;">
      <div style="padding: 10px 16px; background: #FAFCFC; border-bottom: 1px solid var(--color-border-light); font-size: 10px; color: var(--color-text-secondary); display: flex; justify-content: space-between;">
        <span>Chronological record of allocations, disbursements, and voided transactions.</span>
        <span><?= count($audits) ?> logged entries</span>
      </div>
      <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 120px;">Timestamp</th>
              <th style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 90px;">Action</th>
              <th style="padding: 8px 12px; text-align: right; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 90px;">Amount</th>
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary);">Audit Details</th>
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 120px;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($audits)): ?>
              <tr>
                <td colspan="5" style="padding: 24px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
                  No audit logs recorded for this barangay yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($audits as $a): 
                $actClass = 'action-' . strtolower(str_replace(' ', '', $a['action']));
              ?>
                <tr style="border-bottom: 1px solid var(--color-border-light);">
                  <td style="padding: 8px 12px; font-size: 10px; color: var(--color-text-muted); font-family: var(--font-secondary); white-space: nowrap;">
                    <?= date('Y-m-d H:i', strtotime($a['created_at'])) ?>
                  </td>
                  <td style="padding: 8px 12px; text-align: center;">
                    <span class="action-badge <?= $actClass ?>">
                      <?= htmlspecialchars($a['action']) ?>
                    </span>
                  </td>
                  <td style="padding: 8px 12px; text-align: right; font-family: var(--font-secondary); font-size: 10.5px; font-weight: 600; color: var(--color-text);">
                    <?= ($a['amount'] > 0) ? '&#8369;' . number_format($a['amount'], 2) : '&mdash;' ?>
                  </td>
                  <td style="padding: 8px 12px; font-size: 10.5px; color: var(--color-text); line-height: 1.4;">
                    <?= htmlspecialchars($a['details']) ?>
                  </td>
                  <td style="padding: 8px 12px; font-size: 10px; color: var(--color-text-secondary);">
                    <?= htmlspecialchars($a['actor_name']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeAuditRecordsModal()">Close</button>
    </div>
  </div>
</div>

<script>
// Data payloads for zero-lag instant dynamic switching
const rawAllocations = <?= json_encode($allocations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const rawDistributions = <?= json_encode($allDistributions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const rawHouseholds = <?= json_encode($householdFamilies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const baseUrl = '<?= BASE_URL ?>';

let selectedAllocationId = <?= (int)$selectedAllocId ?>;

function formatMoney(amount) {
  return '₱' + Number(amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function selectAllocation(allocId) {
  selectedAllocationId = Number(allocId);

  // Update active styling on Left Table rows
  document.querySelectorAll('.alloc-row').forEach(row => {
    row.classList.remove('active');
  });
  const targetRow = document.getElementById('alloc_row_' + selectedAllocationId);
  if (targetRow) {
    targetRow.classList.add('active');
  }

  // Update URL history without page reload
  const newUrl = new URL(window.location);
  newUrl.searchParams.set('selected_id', selectedAllocationId);
  window.history.replaceState({}, '', newUrl);

  // Update return_url on modal form
  const returnUrlElem = document.getElementById('dist_return_url');
  if (returnUrlElem) {
    returnUrlElem.value = newUrl.toString();
  }

  // Find allocation data
  const alloc = rawAllocations.find(a => Number(a.id) === selectedAllocationId);
  if (!alloc) return;

  // Filter distributions for this allocation
  const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);

  // Auto-compute dynamic metrics live
  const totalBudget = Number(alloc.total_budget || 0);
  const totalDisbursed = allocDistributions
    .filter(d => d.claim_status === 'Claimed')
    .reduce((sum, d) => sum + Number(d.amount || 0), 0);
  const remainingBalance = Math.max(0, totalBudget - totalDisbursed);
  const claimedCount = allocDistributions.filter(d => d.claim_status === 'Claimed').length;
  const targetBeneficiaries = Number(alloc.target_beneficiaries || 0);

  // Update Table 2 Header & Telemetry
  document.getElementById('detailProgramTitle').textContent = alloc.program_name;
  document.getElementById('detailProgramCode').textContent = alloc.allocation_code + ' • Rate: ' + formatMoney(alloc.amount_per_beneficiary) + '/family • Funding: ' + (alloc.funding_source || 'LGU');
  
  document.getElementById('detailTotalBudget').textContent = formatMoney(totalBudget);
  document.getElementById('detailDisbursedBudget').textContent = formatMoney(totalDisbursed);
  document.getElementById('detailRemainingBudget').textContent = formatMoney(remainingBalance);
  document.getElementById('detailBeneficiariesRatio').textContent = claimedCount + ' of ' + targetBeneficiaries + ' served';

  // Synchronize modal dropdown
  const distSelect = document.getElementById('dist_alloc_select');
  if (distSelect) {
    distSelect.value = selectedAllocationId;
    onProgramSelected(distSelect);
  }

  // Update modal family options for this allocation
  updateModalFamilyOptions();

  // Render Table 2 Beneficiaries
  renderBeneficiariesTable(allocDistributions);
}

function renderBeneficiariesTable(list) {
  const tbody = document.getElementById('beneficiariesTableBody');
  if (!tbody) return;

  const searchVal = (document.getElementById('beneficiarySearchInput')?.value || '').trim().toLowerCase();
  const statusFilter = (document.getElementById('beneficiaryStatusFilter')?.value || '').trim();
  const purokFilter = (document.getElementById('beneficiaryPurokFilter')?.value || '').trim().toLowerCase();

  // Apply search/status/purok filter
  const filtered = list.filter(item => {
    const matchSearch = !searchVal || 
      (item.beneficiary_name && item.beneficiary_name.toLowerCase().includes(searchVal)) ||
      (item.reference_no && item.reference_no.toLowerCase().includes(searchVal)) ||
      (item.purok && item.purok.toLowerCase().includes(searchVal)) ||
      (item.family_name && item.family_name.toLowerCase().includes(searchVal)) ||
      (item.household_code && item.household_code.toLowerCase().includes(searchVal)) ||
      (item.phone && item.phone.toLowerCase().includes(searchVal));
    const matchStatus = !statusFilter || item.claim_status === statusFilter;
    const matchPurok = !purokFilter || (item.purok && item.purok.toLowerCase() === purokFilter);
    return matchSearch && matchStatus && matchPurok;
  });

  if (filtered.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" style="padding: 30px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
          ${list.length === 0 ? 'No resident beneficiaries recorded yet for this financial aid batch. Click <strong>+ Distribute Aid</strong> to release funds.' : 'No beneficiaries match the current filter criteria.'}
        </td>
      </tr>
    `;
    return;
  }

  let html = '';
  filtered.forEach(row => {
    const isClaimed = (row.claim_status === 'Claimed');
    const isPending = (row.claim_status === 'Pending');
    const statusClass = (row.claim_status || 'unclaimed').toLowerCase();
    const smsClass = 'sms-' + (row.sms_status || 'not_sent');
    const smsLabel = row.sms_status === 'sent' ? 'Sent' : (row.sms_status === 'failed' ? 'Failed' : 'None');

    const claimDateStr = row.claim_date 
      ? `<div style="font-family: var(--font-secondary);">${row.claim_date.substring(0, 16)}</div><div style="font-size: 9.5px; color: var(--color-text-muted);">${escapeHtml(row.disbursing_officer || 'Barangay Office')}</div>` 
      : '<span style="color: var(--color-text-muted); font-style: italic;">Unclaimed</span>';

    // Demographic indicators (PWD / Senior)
    let demoBadges = '';
    const hasPwd = Number(row.pwd_count) > 0 || (row.remarks && row.remarks.toLowerCase().includes('pwd'));
    const hasSenior = Number(row.seniors_count) > 0 || (row.remarks && row.remarks.toLowerCase().includes('senior'));
    if (hasPwd) {
      demoBadges += '<span class="badge badge-warning" style="font-size:8.5px;margin-left:4px;padding:1px 4px;">PWD</span>';
    }
    if (hasSenior) {
      demoBadges += '<span class="badge badge-neutral" style="font-size:8.5px;margin-left:4px;padding:1px 4px;">Senior</span>';
    }

    const hhCodeInfo = row.household_code ? `<span style="font-family:var(--font-secondary);color:var(--color-primary);font-weight:600;">[${escapeHtml(row.household_code)}]</span> ` : '';

    html += `
      <tr style="border-bottom: 1px solid var(--color-border-light);">
        <td style="padding: 9px 12px; font-family: var(--font-secondary); font-size: 10.5px; font-weight: 600; color: var(--color-primary);">
          ${escapeHtml(row.reference_no || 'PENDING-CLAIM')}
        </td>
        <td style="padding: 9px 12px;">
          <div style="font-weight: 600; font-size: 11px; color: var(--color-text);">
            ${hhCodeInfo}${escapeHtml(row.beneficiary_name)} ${demoBadges}
          </div>
          <div style="font-size: 9.5px; color: var(--color-text-muted);">
            ${escapeHtml(row.purok)} • ${escapeHtml(row.phone)} ${row.family_name ? '• ' + escapeHtml(row.family_name) : ''}
          </div>
        </td>
        <td style="padding: 9px 12px; text-align: right; font-family: var(--font-secondary); font-weight: 700; font-size: 11px; color: var(--color-text);">
          ${formatMoney(row.amount)}
        </td>
        <td style="padding: 9px 12px; text-align: center;">
          <span class="status-badge ${statusClass}">${escapeHtml(row.claim_status)}</span>
        </td>
        <td style="padding: 9px 12px; text-align: center;">
          <span class="sms-badge ${smsClass}">${smsLabel}</span>
        </td>
        <td style="padding: 9px 12px; font-size: 10px;">
          ${claimDateStr}
        </td>
        <td style="padding: 9px 12px; text-align: center;">
          <div style="display: inline-flex; gap: 4px;">
            ${isPending ? `
              <button type="button" class="btn btn-primary" style="padding: 2px 7px; font-size: 9.5px;" onclick="claimPayout(${row.id}, '${escapeJs(row.beneficiary_name)}', ${row.amount})" title="Release Cash Aid">
                Release
              </button>
            ` : ''}
            <button type="button" class="btn btn-outline" style="padding: 2px 7px; font-size: 9.5px;" onclick="resendSms(${row.id})" title="Send SMS Notice">
              SMS
            </button>
            ${isClaimed ? `
              <button type="button" class="btn btn-outline" style="padding: 2px 7px; font-size: 9.5px; color: var(--color-danger);" onclick="voidPayout(${row.id}, '${escapeJs(row.beneficiary_name)}')" title="Void Disbursement">
                Void
              </button>
            ` : ''}
          </div>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

function filterBeneficiariesTable() {
  const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);
  renderBeneficiariesTable(allocDistributions);
}

function filterAllocationsTable(term) {
  const query = (term || '').toLowerCase();
  document.querySelectorAll('#allocationsTableBody tr').forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

function updateModalFamilyOptions() {
  const purokFilter = (document.getElementById('dist_family_purok_filter')?.value || '').trim().toLowerCase();
  const priorityFilter = (document.getElementById('dist_family_priority_filter')?.value || '').trim();
  const selectElem = document.getElementById('dist_family_select');
  const counterElem = document.getElementById('modalFamilyCounter');
  if (!selectElem) return;

  const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);
  const claimedHhIds = new Set(allocDistributions.map(d => Number(d.household_id)).filter(id => id > 0));
  const claimedNames = new Set(allocDistributions.map(d => (d.beneficiary_name || '').toLowerCase()));

  const filtered = rawHouseholds.filter(fam => {
    // Match Purok
    const matchPurok = !purokFilter || (fam.purok_name && fam.purok_name.toLowerCase() === purokFilter);
    if (!matchPurok) return false;

    // Match Priority / PWD
    if (priorityFilter === 'pwd') {
      return Number(fam.pwd_count) > 0;
    } else if (priorityFilter === 'seniors') {
      return Number(fam.seniors_count) > 0;
    } else if (priorityFilter === 'indigent') {
      return fam.economic_status && fam.economic_status.toLowerCase().includes('indigent');
    } else if (priorityFilter === 'unclaimed') {
      return !claimedHhIds.has(Number(fam.id)) && !claimedNames.has((fam.head_full_name || '').toLowerCase());
    }
    return true;
  });

  if (counterElem) {
    counterElem.textContent = `${filtered.length} families available`;
  }

  let html = '<option value="">-- Choose Household Family (Auto-fills details) --</option>';
  filtered.forEach(fam => {
    const isClaimed = claimedHhIds.has(Number(fam.id)) || claimedNames.has((fam.head_full_name || '').toLowerCase());
    const demoBadges = [];
    if (Number(fam.pwd_count) > 0) demoBadges.push(`PWD: ${fam.pwd_count}`);
    if (Number(fam.seniors_count) > 0) demoBadges.push(`Seniors: ${fam.seniors_count}`);
    const demoStr = demoBadges.length > 0 ? ' [' + demoBadges.join(', ') + ']' : '';
    const claimTag = isClaimed ? ' ⚠️ (Already Claimed)' : '';

    html += `<option value="${fam.id}" 
                     data-purok="${escapeHtml(fam.purok_name)}" 
                     data-head="${escapeHtml(fam.head_full_name)}" 
                     data-phone="${escapeHtml(fam.head_phone || '')}" 
                     data-code="${escapeHtml(fam.household_code)}"
                     data-name="${escapeHtml(fam.family_name)}"
                     data-pwd="${fam.pwd_count}"
                     data-seniors="${fam.seniors_count}">
              ${escapeHtml(fam.family_name)} — Rep: ${escapeHtml(fam.head_full_name)} (${escapeHtml(fam.purok_name)})${demoStr}${claimTag}
             </option>`;
  });

  selectElem.innerHTML = html;
}

function onFamilySelected(selectElem) {
  const selectedOption = selectElem.options[selectElem.selectedIndex];
  const warningElem = document.getElementById('familyClaimWarning');
  if (warningElem) warningElem.style.display = 'none';

  if (!selectedOption || !selectedOption.value) {
    return;
  }

  const hhId = Number(selectedOption.value);
  const headName = selectedOption.dataset.head || '';
  const purokName = selectedOption.dataset.purok || '';
  const phone = selectedOption.dataset.phone || '';
  const code = selectedOption.dataset.code || '';
  const famName = selectedOption.dataset.name || '';
  const pwdCount = Number(selectedOption.dataset.pwd || 0);
  const seniorsCount = Number(selectedOption.dataset.seniors || 0);

  document.getElementById('dist_household_id').value = hhId;
  document.getElementById('dist_beneficiary_name').value = headName;
  document.getElementById('dist_purok').value = purokName;
  document.getElementById('dist_phone').value = phone;

  const remarksInput = document.querySelector('#distributeForm input[name="remarks"]');
  if (remarksInput) {
    let tag = `[${code}] ${famName}`;
    if (pwdCount > 0) tag += ` • ${pwdCount} PWD`;
    if (seniorsCount > 0) tag += ` • ${seniorsCount} Senior`;
    remarksInput.value = tag;
  }

  // Check if already claimed in this allocation
  const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);
  const alreadyClaimed = allocDistributions.find(d => 
    Number(d.household_id) === hhId || (d.beneficiary_name && d.beneficiary_name.toLowerCase() === headName.toLowerCase())
  );
  if (alreadyClaimed && warningElem) {
    warningElem.innerHTML = `<strong>Notice:</strong> This family already has a recorded disbursement (Ref: ${escapeHtml(alreadyClaimed.reference_no || 'Pending')}, Status: ${escapeHtml(alreadyClaimed.claim_status)}).`;
    warningElem.style.display = 'block';
  }
}

function openDistributeModalForCurrent() {
  const distSelect = document.getElementById('dist_alloc_select');
  if (distSelect && selectedAllocationId) {
    distSelect.value = selectedAllocationId;
    onProgramSelected(distSelect);
  }
  updateModalFamilyOptions();
  openDistributeModal();
}

function openDistributeModal() {
  const returnUrlElem = document.getElementById('dist_return_url');
  if (returnUrlElem) {
    returnUrlElem.value = window.location.href;
  }
  updateModalFamilyOptions();
  document.getElementById('distributeModal').classList.add('show');
}
function closeDistributeModal() {
  document.getElementById('distributeModal').classList.remove('show');
}

function openEligibleFamiliesModal() {
  const alloc = rawAllocations.find(a => Number(a.id) === selectedAllocationId);
  const badge = document.getElementById('efModalProgramBadge');
  if (badge && alloc) {
    badge.textContent = `${alloc.program_name} (${formatMoney(alloc.amount_per_beneficiary)}/family)`;
  }
  renderEligibleFamiliesTable();
  document.getElementById('eligibleFamiliesModal').classList.add('show');
}

function closeEligibleFamiliesModal() {
  document.getElementById('eligibleFamiliesModal').classList.remove('show');
}

function renderEligibleFamiliesTable() {
  const tbody = document.getElementById('efTableBody');
  if (!tbody) return;

  const searchVal = (document.getElementById('efSearchInput')?.value || '').trim().toLowerCase();
  const purokFilter = (document.getElementById('efPurokFilter')?.value || '').trim().toLowerCase();
  const priorityFilter = (document.getElementById('efPriorityFilter')?.value || '').trim();
  const claimFilter = (document.getElementById('efClaimFilter')?.value || '').trim();

  const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);
  const distByHhId = {};
  const distByName = {};
  allocDistributions.forEach(d => {
    if (d.household_id) distByHhId[Number(d.household_id)] = d;
    if (d.beneficiary_name) distByName[d.beneficiary_name.toLowerCase()] = d;
  });

  // Calculate telemetry counts
  let totalFamilies = 0;
  let pwdFamilies = 0;
  let seniorFamilies = 0;
  let claimedCount = 0;
  let unclaimedCount = 0;

  // Pre-scan for current purok filter
  rawHouseholds.forEach(fam => {
    const matchPurok = !purokFilter || (fam.purok_name && fam.purok_name.toLowerCase() === purokFilter);
    if (!matchPurok) return;

    totalFamilies++;
    if (Number(fam.pwd_count) > 0) pwdFamilies++;
    if (Number(fam.seniors_count) > 0) seniorFamilies++;

    const isClaimed = !!(distByHhId[Number(fam.id)] || distByName[(fam.head_full_name || '').toLowerCase()]);
    if (isClaimed) claimedCount++;
    else unclaimedCount++;
  });

  document.getElementById('efTotalFamiliesCount').textContent = totalFamilies;
  document.getElementById('efPwdFamiliesCount').textContent = pwdFamilies;
  document.getElementById('efSeniorFamiliesCount').textContent = seniorFamilies;
  document.getElementById('efClaimedCount').textContent = claimedCount;
  document.getElementById('efUnclaimedCount').textContent = unclaimedCount;

  // Filter list
  const filtered = rawHouseholds.filter(fam => {
    // Search
    const matchSearch = !searchVal ||
      (fam.family_name && fam.family_name.toLowerCase().includes(searchVal)) ||
      (fam.head_full_name && fam.head_full_name.toLowerCase().includes(searchVal)) ||
      (fam.household_code && fam.household_code.toLowerCase().includes(searchVal)) ||
      (fam.purok_name && fam.purok_name.toLowerCase().includes(searchVal));
    if (!matchSearch) return false;

    // Purok
    const matchPurok = !purokFilter || (fam.purok_name && fam.purok_name.toLowerCase() === purokFilter);
    if (!matchPurok) return false;

    // Priority
    if (priorityFilter === 'pwd' && Number(fam.pwd_count) <= 0) return false;
    if (priorityFilter === 'seniors' && Number(fam.seniors_count) <= 0) return false;
    if (priorityFilter === 'indigent' && (!fam.economic_status || !fam.economic_status.toLowerCase().includes('indigent'))) return false;

    // Claim
    const dist = distByHhId[Number(fam.id)] || distByName[(fam.head_full_name || '').toLowerCase()];
    if (claimFilter === 'claimed' && !dist) return false;
    if (claimFilter === 'unclaimed' && dist) return false;

    return true;
  });

  const footerSummary = document.getElementById('efFooterSummary');
  if (footerSummary) {
    footerSummary.textContent = `Showing ${filtered.length} of ${totalFamilies} household families ${purokFilter ? 'in ' + escapeHtml(document.getElementById('efPurokFilter').value) : 'across Hinaplanon'}`;
  }

  if (filtered.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" style="padding: 30px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
          No household families match the selected filters.
        </td>
      </tr>
    `;
    return;
  }

  let html = '';
  filtered.forEach(fam => {
    const dist = distByHhId[Number(fam.id)] || distByName[(fam.head_full_name || '').toLowerCase()];
    const isClaimed = !!dist;

    let demoBadges = [];
    if (Number(fam.pwd_count) > 0) {
      demoBadges.push(`<span class="badge badge-warning" style="font-size:9px;">${fam.pwd_count} PWD</span>`);
    }
    if (Number(fam.seniors_count) > 0) {
      demoBadges.push(`<span class="badge badge-neutral" style="font-size:9px;">${fam.seniors_count} Senior</span>`);
    }
    if (demoBadges.length === 0) {
      demoBadges.push(`<span style="color:var(--color-text-muted);font-size:9.5px;">Standard</span>`);
    }

    const statusHtml = isClaimed
      ? `<span class="status-badge claimed">${escapeHtml(dist.claim_status || 'Claimed')}</span><div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;font-family:var(--font-secondary);">${escapeHtml(dist.reference_no || '')}</div>`
      : `<span class="status-badge active">Eligible</span>`;

    const actionHtml = isClaimed
      ? `<span style="font-size:9.5px;color:var(--color-text-muted);">Payout Complete</span>`
      : `<button type="button" class="btn btn-primary btn-sm" style="font-size:9.5px;padding:3px 8px;" onclick="selectFamilyForAyuda(${fam.id})">Give Ayuda</button>`;

    html += `
      <tr style="border-bottom: 1px solid var(--color-border-light);">
        <td style="padding: 8px 12px; font-family: var(--font-secondary); font-size: 10px; font-weight: 600; color: var(--color-primary);">
          ${escapeHtml(fam.household_code)}
        </td>
        <td style="padding: 8px 12px;">
          <div style="font-weight: 600; font-size: 10.5px; color: var(--color-text);">${escapeHtml(fam.family_name)}</div>
          <div style="font-size: 9px; color: var(--color-text-muted);">${escapeHtml(fam.economic_status || 'Indigent')}</div>
        </td>
        <td style="padding: 8px 12px;">
          <div style="font-weight: 600; font-size: 10.5px; color: var(--color-primary);">${escapeHtml(fam.head_full_name)}</div>
          <div style="font-size: 9.5px; color: var(--color-text-muted); font-family: var(--font-secondary);">${escapeHtml(fam.head_phone || '—')}</div>
        </td>
        <td style="padding: 8px 12px; font-size: 10px; color: var(--color-text);">
          ${escapeHtml(fam.purok_name)}
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          <div style="display:flex;gap:3px;justify-content:center;flex-wrap:wrap;">
            ${demoBadges.join(' ')}
          </div>
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          ${statusHtml}
        </td>
        <td style="padding: 8px 12px; text-align: right;">
          ${actionHtml}
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

function selectFamilyForAyuda(famId) {
  closeEligibleFamiliesModal();
  openDistributeModalForCurrent();

  // Pick family in select
  const fam = rawHouseholds.find(f => Number(f.id) === Number(famId));
  if (fam) {
    const purokFilter = document.getElementById('dist_family_purok_filter');
    if (purokFilter) {
      purokFilter.value = fam.purok_name;
      updateModalFamilyOptions();
    }
    const selectElem = document.getElementById('dist_family_select');
    if (selectElem) {
      selectElem.value = fam.id;
      onFamilySelected(selectElem);
    }
  }
}

function openAuditRecordsModal() {
  document.getElementById('auditRecordsModal').classList.add('show');
}
function closeAuditRecordsModal() {
  document.getElementById('auditRecordsModal').classList.remove('show');
}

function onProgramSelected(selectElem) {
  const selectedOption = selectElem.options[selectElem.selectedIndex];
  if (selectedOption && selectedOption.dataset.rate) {
    document.getElementById('dist_amount').value = selectedOption.dataset.rate;
  }
  updateModalFamilyOptions();
}

function autofillResident(selectElem) {
  const selectedOption = selectElem.options[selectElem.selectedIndex];
  if (selectedOption && selectedOption.value) {
    document.getElementById('dist_resident_id').value = selectedOption.value;
    document.getElementById('dist_beneficiary_name').value = selectedOption.dataset.name || '';
    document.getElementById('dist_purok').value = selectedOption.dataset.purok || '';
    document.getElementById('dist_phone').value = selectedOption.dataset.phone || '';
  }
}

function claimPayout(distributionId, beneficiaryName, amount) {
  if (!confirm('Confirm releasing ' + formatMoney(amount) + ' cash aid to ' + beneficiaryName + '?')) {
    return;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = baseUrl + '/backend/functions/financial_aid/distribute.php';

  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'claim';
  form.appendChild(actionInput);

  const idInput = document.createElement('input');
  idInput.type = 'hidden';
  idInput.name = 'distribution_id';
  idInput.value = distributionId;
  form.appendChild(idInput);

  const retInput = document.createElement('input');
  retInput.type = 'hidden';
  retInput.name = 'return_url';
  retInput.value = window.location.href;
  form.appendChild(retInput);

  const smsInput = document.createElement('input');
  smsInput.type = 'hidden';
  smsInput.name = 'send_sms';
  smsInput.value = '1';
  form.appendChild(smsInput);

  document.body.appendChild(form);
  form.submit();
}

function resendSms(distributionId) {
  if (!confirm('Dispatch SMS notification to this beneficiary?')) {
    return;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = baseUrl + '/backend/functions/financial_aid/distribute.php';

  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'send_sms';
  form.appendChild(actionInput);

  const idInput = document.createElement('input');
  idInput.type = 'hidden';
  idInput.name = 'distribution_id';
  idInput.value = distributionId;
  form.appendChild(idInput);

  const retInput = document.createElement('input');
  retInput.type = 'hidden';
  retInput.name = 'return_url';
  retInput.value = window.location.href;
  form.appendChild(retInput);

  document.body.appendChild(form);
  form.submit();
}

function voidPayout(distributionId, beneficiaryName) {
  if (!confirm('Are you sure you want to void disbursement for ' + beneficiaryName + '? The amount will be restored to the remaining balance.')) {
    return;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = baseUrl + '/backend/functions/financial_aid/distribute.php';

  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'void';
  form.appendChild(actionInput);

  const idInput = document.createElement('input');
  idInput.type = 'hidden';
  idInput.name = 'distribution_id';
  idInput.value = distributionId;
  form.appendChild(idInput);

  const retInput = document.createElement('input');
  retInput.type = 'hidden';
  retInput.name = 'return_url';
  retInput.value = window.location.href;
  form.appendChild(retInput);

  document.body.appendChild(form);
  form.submit();
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeJs(str) {
  if (!str) return '';
  return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// Initialize on page load with selected allocation
document.addEventListener('DOMContentLoaded', function() {
  if (selectedAllocationId) {
    selectAllocation(selectedAllocationId);
  }
});

// Close modals when clicking backdrop
window.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.classList.remove('show');
  }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
