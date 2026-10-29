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
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
  die("Barangay jurisdiction not assigned.");
}

$pageTitle = "Manage Resources — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Auto-sync any existing or newly scheduled Social Welfare & Financial Aid events for this barangay
syncEventFinancialAidAllocation($db, null, $barangayId);

// Fetch pending incoming allocations from ICDRRMO awaiting Barangay Head confirmation
$pendingAllocStmt = $db->prepare("
    SELECT a.*, u.full_name AS allocator_name
    FROM financial_aid_allocations a
    LEFT JOIN users u ON a.allocated_by = u.id
    WHERE a.barangay_id = ? 
      AND a.received_status = 'Pending' 
      AND a.status != 'Archived'
    ORDER BY a.created_at DESC
");
$pendingAllocStmt->execute([$barangayId]);
$pendingAllocations = $pendingAllocStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all confirmed received allocations for this barangay (prioritizing event-linked programs)
$allocStmt = $db->prepare("
    SELECT a.*,
           e.title AS event_title, e.activity_type AS event_activity_type, e.status AS event_status,
           e.target_puroks AS event_target_puroks,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id) AS total_recipients,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Claimed') AS claimed_recipients,
           (SELECT COUNT(*) FROM financial_aid_distributions d WHERE d.allocation_id = a.id AND d.claim_status = 'Pending') AS pending_recipients
    FROM financial_aid_allocations a
    LEFT JOIN events e ON a.event_id = e.id
    WHERE a.barangay_id = ? 
      AND (a.received_status = 'Received' OR (a.event_id IS NOT NULL AND a.received_status != 'Not Received'))
      AND a.status != 'Archived'
    ORDER BY (a.event_id IS NOT NULL) DESC, a.created_at DESC
");
$allocStmt->execute([$barangayId]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine initial selected allocation ID (from query or first available)
$selectedAllocId = !empty($_GET['selected_id']) ? (int) $_GET['selected_id'] : (!empty($allocations) ? (int) $allocations[0]['id'] : 0);

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
  $isDist = (!empty($al['is_distributed']) || (float) $al['total_budget'] > 0);
  if ($isDist) {
    $totalAllocatedSum += (float) $al['total_budget'];
    $totalDisbursedSum += (float) $al['disbursed_budget'];
    $totalBalanceSum += (float) $al['remaining_budget'];
    $totalClaimedCount += (int) $al['claimed_recipients'];
  }
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
  /* Standardized Typography & Layout: Primary = Roboto, Secondary = Poppins */
  .main-content,
  .financial-aid-page,
  .financial-aid-page * {
    box-sizing: border-box;
  }

  .financial-aid-page {
    font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: var(--color-text);
  }

  /* Headings & Titles: Strictly 14px to 16px, Font Family: Poppins */
  .financial-aid-page h1,
  .financial-aid-page h2,
  .financial-aid-page h3,
  .financial-aid-page .page-header h1,
  .financial-aid-page .table-card-title,
  .financial-aid-page .modal-header h3 {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    margin: 0;
  }

  .page-header h1 {
    font-size: 16px;
    font-weight: 600;
    color: var(--color-primary);
  }

  /* Paragraphs, details, descriptions: Strictly 9px to 11px, Font Family: Roboto */
  .page-header-desc {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text-secondary);
    margin: 3px 0 0 0;
  }

  /* Telemetry KPI Summary Row */
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
    font-family: 'Poppins', sans-serif;
    font-size: 10px;
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }

  .kpi-val {
    font-family: 'Poppins', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: var(--color-primary);
  }

  .kpi-sub {
    font-family: 'Roboto', sans-serif;
    font-size: 9.5px;
    color: var(--color-text-muted);
  }

  /* Two-Table Master-Detail Architecture */
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
    padding: 10px 16px;
    background: #FAFCFC;
    border-bottom: 1px solid var(--color-border-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
  }

  .table-card-title {
    font-size: 14px;
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
    font-family: 'Poppins', sans-serif;
    font-size: 9.5px;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    font-weight: 600;
  }

  .detail-telemetry-val {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--color-primary);
  }

  /* Standardized Status Badges: Strictly 9px to 9.5px, Roboto */
  .status-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'Roboto', sans-serif;
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    line-height: 1.3;
  }

  .status-badge.claimed {
    background: var(--color-success-bg);
    color: var(--color-success);
    border: 1px solid var(--color-success-border);
  }

  .status-badge.pending {
    background: var(--color-warning-bg);
    color: var(--color-warning);
    border: 1px solid var(--color-warning-border);
  }

  .status-badge.unclaimed {
    background: var(--color-surface-subtle);
    color: var(--color-text-secondary);
    border: 1px solid var(--color-border);
  }

  .status-badge.active {
    background: var(--color-info-bg);
    color: var(--color-primary);
    border: 1px solid var(--color-info-border);
  }

  .status-badge.completed {
    background: var(--color-success-bg);
    color: var(--color-success);
    border: 1px solid var(--color-success-border);
  }

  .sms-badge {
    display: inline-block;
    font-family: 'Roboto', sans-serif;
    font-size: 9px;
    font-weight: 600;
    padding: 1px 5px;
    border-radius: 3px;
  }

  .sms-sent {
    background: var(--color-success-bg);
    color: var(--color-success);
    border: 1px solid var(--color-success-border);
  }

  .sms-failed {
    background: var(--color-danger-bg);
    color: var(--color-danger);
    border: 1px solid var(--color-danger-border);
  }

  .sms-not_sent {
    background: var(--color-surface-subtle);
    color: var(--color-text-muted);
  }

  .action-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'Roboto', sans-serif;
    font-size: 9.5px;
    font-weight: 600;
  }

  .action-allocated {
    background: var(--color-info-bg);
    color: var(--color-primary);
  }

  .action-disbursed {
    background: var(--color-success-bg);
    color: var(--color-success);
  }

  .action-status {
    background: var(--color-warning-bg);
    color: var(--color-warning);
  }

  .action-voided {
    background: var(--color-danger-bg);
    color: var(--color-danger);
  }

  /* Tables: Headers Poppins 10px, Body Roboto 10.5px */
  table.data-table th {
    font-family: 'Poppins', sans-serif;
    font-size: 10px;
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  table.data-table td {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text);
  }

  /* Form Elements & Inputs */
  .form-control,
  .form-select,
  input.form-control,
  select.form-control,
  textarea.form-control {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text);
  }

  .form-label {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--color-primary);
  }

  /* Buttons */
  .btn {
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    font-weight: 500;
  }

  .btn-sm {
    font-size: 10px;
    padding: 4px 8px;
  }

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

  .modal.show {
    display: flex;
  }

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

  .modal-dialog.modal-lg {
    max-width: 840px;
  }

  .modal-header {
    padding: 12px 18px;
    border-bottom: 1px solid var(--color-border-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #FAFCFC;
  }

  .modal-header h3 {
    font-size: 14px;
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

  .modal-close:hover {
    color: var(--color-primary);
  }

  .modal-body {
    padding: 16px 18px;
    overflow-y: auto;
    font-family: 'Roboto', sans-serif;
    font-size: 10.5px;
    color: var(--color-text);
  }

  .modal-footer {
    padding: 10px 18px;
    border-top: 1px solid var(--color-border-light);
    background: #FAFCFC;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
</style>

<main class="main-content financial-aid-page">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Manage Resources — Barangay <?= clean($barangay['name']) ?></h1>
      <p class="page-header-desc">
        Calamity assistance programs and beneficiary disbursement ledger.
      </p>
    </div>
    <div class="page-header-actions" style="display: flex; gap: 8px; align-items: center;">
      <button type="button" class="btn btn-outline" onclick="openAuditRecordsModal()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        Audit Records
      </button>
    </div>
  </div>

  <!-- Telemetry KPI Summary Row -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-title">Total Calamity Aid Received</div>
      <div class="kpi-val">&#8369;<?= number_format($totalAllocatedSum, 2) ?></div>
      <div class="kpi-sub"><?= count($allocations) ?> programs from ICDRRMO</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Disbursed to Residents</div>
      <div class="kpi-val">&#8369;<?= number_format($totalDisbursedSum, 2) ?></div>
      <div class="kpi-sub"><?= number_format($totalClaimedCount) ?> completed beneficiary payouts</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Overall Remaining Balance</div>
      <div class="kpi-val">&#8369;<?= number_format($totalBalanceSum, 2) ?></div>
      <div class="kpi-sub">Undisbursed program funds</div>
    </div>
  </div>

  <!-- Section 2: Incoming Resource Allocations from ICDRRMO -->
  <div class="table-card" style="margin-bottom: var(--space-4);">
    <div class="table-card-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <h2 class="table-card-title" style="margin: 0;">Incoming Allocations from ICDRRMO</h2>
        <?php if (!empty($pendingAllocations)): ?>
          <span class="status-badge pending">
            <?= count($pendingAllocations) ?> Pending
          </span>
        <?php else: ?>
          <span class="status-badge" style="background: var(--color-surface-subtle); color: var(--color-text-secondary); border: 1px solid var(--color-border);">
            Confirmed
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($pendingAllocations)): ?>
      <div style="padding: 12px 16px; font-size: 10.5px; color: var(--color-text-secondary); display: flex; justify-content: space-between; align-items: center;">
        <span>No pending incoming allocations awaiting confirmation.</span>
        <span style="font-size: 10px; color: var(--color-text-muted);"><?= count($allocations) ?> Active Programs</span>
      </div>
    <?php else: ?>
      <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="padding: 8px 12px; text-align: left;">Program &amp; Calamity</th>
              <th style="padding: 8px 12px; text-align: left;">Target Scope</th>
              <th style="padding: 8px 12px; text-align: right;">Total Fund</th>
              <th style="padding: 8px 12px; text-align: right;">Amount / Family</th>
              <th style="padding: 8px 12px; text-align: center;">Source</th>
              <th style="padding: 8px 12px; text-align: center; width: 190px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingAllocations as $pa): ?>
              <tr style="border-bottom: 1px solid var(--color-border-light);">
                <td style="padding: 9px 12px;">
                  <div style="font-weight: 600; color: var(--color-primary); font-size: 11px;"><?= htmlspecialchars($pa['program_name']) ?></div>
                  <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                    <span class="status-badge active" style="font-size: 9px; padding: 1px 5px;"><?= htmlspecialchars($pa['allocation_code']) ?></span>
                    <span style="font-size: 9.5px; color: var(--color-text-muted);"><?= htmlspecialchars($pa['disaster_type'] ?? 'General Calamity') ?> &bull; <?= date('M d, Y', strtotime($pa['created_at'])) ?></span>
                  </div>
                </td>
                <td style="padding: 9px 12px;">
                  <div style="font-weight: 500; font-size: 10.5px; color: var(--color-text);"><?= htmlspecialchars($pa['target_purok'] ?? 'All Puroks') ?></div>
                  <div style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 1px;">
                    <?= number_format((int)$pa['target_beneficiaries']) ?> families
                  </div>
                </td>
                <td style="padding: 9px 12px; text-align: right;">
                  <div style="font-weight: 600; font-size: 11px; color: var(--color-text);">
                    &#8369;<?= number_format((float)$pa['total_budget'], 2) ?>
                  </div>
                  <div style="font-size: 9.5px; color: var(--color-text-muted);"><?= htmlspecialchars($pa['funding_source']) ?></div>
                </td>
                <td style="padding: 9px 12px; text-align: right;">
                  <div style="font-weight: 600; font-size: 11px; color: var(--color-text);">
                    &#8369;<?= number_format((float)$pa['amount_per_beneficiary'], 2) ?>
                  </div>
                  <div style="font-size: 9.5px; color: var(--color-text-muted);">per family</div>
                </td>
                <td style="padding: 9px 12px; text-align: center;">
                  <?php if (($pa['added_type'] ?? '') === 'ai_recommended'): ?>
                    <span class="status-badge active" style="font-size: 9px;">AI Recommended</span>
                  <?php else: ?>
                    <span class="status-badge unclaimed" style="font-size: 9px;">Admin Added</span>
                  <?php endif; ?>
                </td>
                <td style="padding: 9px 12px; text-align: center;">
                  <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/confirm_receipt.php" style="margin: 0;" onsubmit="return confirm('Confirm receipt of allocation <?= htmlspecialchars($pa['allocation_code']) ?> (₱<?= number_format((float)$pa['total_budget'], 2) ?>)?');">
                      <input type="hidden" name="allocation_id" value="<?= $pa['id'] ?>">
                      <input type="hidden" name="receipt_status" value="Received">
                      <button type="submit" class="btn btn-sm btn-primary" style="font-size: 10.5px; padding: 4px 10px;">
                        Receive
                      </button>
                    </form>

                    <button type="button" class="btn btn-sm btn-outline" onclick="openNotReceivedAidModal(<?= $pa['id'] ?>, '<?= htmlspecialchars(addslashes($pa['allocation_code'])) ?>', '<?= htmlspecialchars(addslashes($pa['program_name'])) ?>', '<?= number_format((float)$pa['total_budget'], 2) ?>')" style="font-size: 10.5px; padding: 4px 10px; color: var(--color-danger); border-color: var(--color-danger-border);">
                      Not Received
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (empty($allocations)): ?>
    <div class="card"
      style="padding: 30px 20px; text-align: center; background: #FFFFFF; border: 1px solid var(--color-border); border-radius: var(--radius-primary); margin-bottom: var(--space-4);">
      <h3 style="font-size: 14px; font-weight: 600; color: var(--color-primary); margin-bottom: 6px;">No Financial Aid Programs Scheduled</h3>
      <p style="font-size: 10.5px; color: var(--color-text-secondary); max-width: 480px; margin: 0 auto 16px;">
        Schedule an assistance event in Manage Events to configure and disburse calamity aid.
      </p>
      <div>
        <a href="<?= BASE_URL ?>/views/barangay/events.php?create=1&type=Social+Welfare+and+Assistance"
          class="btn btn-primary" style="font-size: 11px; padding: 6px 14px;">
          + Schedule Event
        </a>
      </div>
    </div>
  <?php else: ?>

    <!-- Two-Table Dynamic Master-Detail Layout -->
    <div class="dual-table-container">

      <!-- Table 1 (Left): Financial Aid Received -->
      <div class="table-card">
        <div class="table-card-header">
          <div class="table-card-title">
            <span>Financial Aid Programs</span>
            <span
              style="font-size: 10px; font-weight: 700; background: #EBF3FC; color: #17324D; padding: 2px 7px; border-radius: 999px;">
              <?= count($allocations) ?>
            </span>
          </div>
          <span style="font-size: 9.5px; color: var(--color-text-muted);">Select program</span>
        </div>

        <div style="padding: 8px 12px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF;">
          <input type="text" id="allocSearchInput" class="form-control" placeholder="Search programs..."
            style="font-size: 10.5px; padding: 5px 9px;" oninput="filterAllocationsTable(this.value)">
        </div>

        <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
          <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr
                style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
                <th
                  style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary);">
                  Program &amp; Scope</th>
                <th
                  style="padding: 8px 12px; text-align: right; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 110px;">
                  Program Fund</th>
                <th
                  style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 75px;">
                  Status</th>
              </tr>
            </thead>
            <tbody id="allocationsTableBody">
              <?php foreach ($allocations as $idx => $row):
                $isSelected = ($row['id'] === $selectedAllocId);
                $isInitiated = ((float) $row['total_budget'] > 0 || !empty($row['is_distributed']));
                ?>
                <tr class="alloc-row <?= $isSelected ? 'active' : '' ?>" id="alloc_row_<?= $row['id'] ?>"
                  onclick="selectAllocation(<?= $row['id'] ?>)">
                  <td style="padding: 9px 12px;">
                    <div
                      style="font-weight: 600; font-size: 11px; color: var(--color-primary); line-height: 1.3; display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                      <span><?= htmlspecialchars($row['program_name']) ?></span>
                      <?php if (!empty($row['event_id'])): ?>
                        <span
                          style="font-size: 8.5px; font-weight: 700; background: var(--color-info-bg); color: var(--color-primary); padding: 1px 5px; border-radius: 3px;">EVENT</span>
                      <?php endif; ?>
                    </div>
                    <div
                      style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 2px;">
                      <?= htmlspecialchars($row['allocation_code']) ?> &bull;
                      <?= htmlspecialchars($row['target_purok'] ?? 'All Puroks') ?> &bull;
                      <?= $isInitiated ? ('&#8369;' . number_format($row['amount_per_beneficiary'], 2) . '/family') : 'Awaiting Setup' ?>
                    </div>
                  </td>
                  <td style="padding: 9px 12px; text-align: right;">
                    <div
                      style="font-weight: 700; font-size: 11px; color: var(--color-text);"
                      id="alloc_fund_<?= $row['id'] ?>">
                      &#8369;<?= $isInitiated ? number_format($row['total_budget'], 2) : '0.00' ?>
                    </div>
                    <div style="font-size: 9.5px; color: var(--color-text-muted); margin-top: 1px;"
                      id="alloc_rem_<?= $row['id'] ?>">
                      Rem: &#8369;<?= $isInitiated ? number_format($row['remaining_budget'], 2) : '0.00' ?>
                    </div>
                  </td>
                  <td style="padding: 9px 12px; text-align: center;">
                    <?php if ($isInitiated): ?>
                      <span class="status-badge <?= strtolower($row['status']) ?>">
                        <?= htmlspecialchars($row['status']) ?>
                      </span>
                    <?php else: ?>
                      <span class="status-badge unclaimed">
                        Pending
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table 2 (Right): Disbursement Registry & Beneficiary Ledger -->
      <div class="table-card">

        <!-- Table 2 Header -->
        <div class="table-card-header">
          <div>
            <div class="table-card-title">
              <span id="detailProgramTitle">Beneficiary Ledger</span>
            </div>
            <div id="detailProgramCode"
              style="font-size: 10px; color: var(--color-text-secondary); margin-top: 2px;">
              Loading selected allocation...
            </div>
          </div>
          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="openDistributeModalForCurrent()"
              style="padding: 5px 12px; font-size: 10.5px;">
              Distribute Aid
            </button>
          </div>
        </div>

        <!-- Telemetry Sub-bar -->
        <div class="detail-telemetry-bar">
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Received Budget</span>
            <span class="detail-telemetry-val" id="detailTotalBudget">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Total Disbursed</span>
            <span class="detail-telemetry-val" id="detailDisbursedBudget"
              style="color: var(--color-success);">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Remaining Balance</span>
            <span class="detail-telemetry-val" id="detailRemainingBudget">&#8369;0.00</span>
          </div>
          <div class="detail-telemetry-item">
            <span class="detail-telemetry-label">Released Families</span>
            <span class="detail-telemetry-val" id="detailBeneficiariesRatio">0 / 0</span>
          </div>
        </div>

        <!-- Notice Banner when program aid distribution is not yet initiated -->
        <div id="uninitiatedNoticeBanner"
          style="display: none; margin: 10px 14px 0; padding: 8px 12px; background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: 6px; font-size: 10.5px; color: var(--color-text-secondary); display: flex; align-items: center; justify-content: space-between; gap: 8px;">
          <span>Distribution plan not initialized for this program.</span>
          <button type="button" class="btn btn-primary btn-sm" onclick="openDistributeModalForCurrent()"
            style="padding: 3px 8px; font-size: 10px; white-space: nowrap;">
            Distribute Aid
          </button>
        </div>

        <!-- Filter bar for beneficiaries inside this program -->
        <div
          style="padding: 8px 14px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
          <div style="flex: 1; min-width: 170px;">
            <input type="text" id="beneficiarySearchInput" class="form-control"
              placeholder="Search families, ref no, or contact..."
              style="font-size: 10.5px; padding: 5px 9px;" oninput="filterBeneficiariesTable()">
          </div>
          <div style="width: 150px;">
            <select id="beneficiaryPurokFilter" class="form-control" style="font-size: 10.5px; padding: 5px 8px;"
              onchange="filterBeneficiariesTable()">
              <option value="">Target Scope</option>
            </select>
          </div>
          <div style="width: 125px;">
            <select id="beneficiaryStatusFilter" class="form-control" style="font-size: 10.5px; padding: 5px 8px;"
              onchange="filterBeneficiariesTable()">
              <option value="">All Statuses</option>
              <option value="Claimed">Released</option>
              <option value="Unclaimed">Unreleased</option>
            </select>
          </div>
          <div style="width: 135px;">
            <select id="beneficiaryPriorityFilter" class="form-control" style="font-size: 10.5px; padding: 5px 8px;"
              onchange="filterBeneficiariesTable()">
              <option value="">All Families</option>
              <option value="pwd">With PWD</option>
              <option value="seniors">With Seniors</option>
              <option value="indigent">Indigent</option>
            </select>
          </div>
        </div>

        <!-- Beneficiaries Table -->
        <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
          <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr
                style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
                <th
                  style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 125px;">
                  Reference No</th>
                <th
                  style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary);">
                  Household Family / Representative</th>
                <th
                  style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 85px;">
                  Purok</th>
                <th
                  style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 110px;">
                  Family Members</th>
                <th
                  style="padding: 8px 12px; text-align: right; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 95px;">
                  Aid Amount</th>
                <th
                  style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 85px;">
                  Status</th>
                <th
                  style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 60px;">
                  SMS</th>
                <th
                  style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 115px;">
                  Release Date</th>
                <th
                  style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); width: 115px;">
                  Action</th>
              </tr>
            </thead>
            <tbody id="beneficiariesTableBody">
              <!-- Dynamically rendered via JavaScript based on selected allocation and households -->
            </tbody>
          </table>
        </div>

      </div>

    </div>

  <?php endif; ?>
</main>

<!-- Modal: Create Financial Aid Distribution -->
<div class="modal" id="distributeModal">
  <div class="modal-dialog" style="max-width: 600px;">
    <div class="modal-header">
      <h3>Create Financial Aid Distribution</h3>
      <button type="button" class="modal-close" onclick="closeDistributeModal()">&times;</button>
    </div>
    <?php if (empty($allocations)): ?>
      <div class="modal-body" style="padding: 24px; text-align: center;">
        <div style="font-size: 13px; font-weight: 600; color: var(--color-primary); margin-bottom: 6px;">No Financial Aid Program Created</div>
        <p style="font-size: 10.5px; color: var(--color-text-secondary); max-width: 380px; margin: 0 auto 16px;">
          To distribute assistance, please schedule an event in Manage Events first.
        </p>
        <a href="<?= BASE_URL ?>/views/barangay/events.php?create=1&type=Social+Welfare+and+Assistance"
          class="btn btn-primary btn-sm" style="font-size: 10.5px; padding: 5px 12px;">
          + Schedule Event
        </a>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeDistributeModal()">Close</button>
      </div>
    <?php else: ?>
      <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/distribute.php" id="distributeForm"
        onsubmit="return onDistributeFormSubmit(this)">
        <input type="hidden" name="action" value="save_distribution_plan">
        <input type="hidden" name="return_url" id="dist_return_url" value="">
        <input type="hidden" name="allocation_id" id="dist_allocation_id" value="<?= (int) $selectedAllocId ?>">
        <input type="hidden" name="custom_target_purok" id="dist_custom_target_purok" value="">

        <div class="modal-body">
          <div style="display: flex; flex-direction: column; gap: 10px;">

            <div>
              <label class="form-label form-label-required" style="margin-bottom: 3px; display: block;">Program Name</label>
              <input type="text" name="program_name" id="dist_program_name" class="form-control"
                placeholder="e.g. Typhoon Relief Cash Assistance" required style="padding: 6px 9px;">
            </div>

            <div>
              <label class="form-label" style="margin-bottom: 3px; display: block;">Disaster / Calamity Type</label>
              <select name="disaster_type" id="dist_disaster_type" class="form-control" style="padding: 6px 9px;">
                <option value="Typhoon">Typhoon</option>
                <option value="Flood">Flood</option>
                <option value="Landslide">Landslide</option>
                <option value="Fire">Fire</option>
                <option value="Earthquake">Earthquake</option>
                <option value="General Calamity">General Calamity</option>
              </select>
            </div>

            <div>
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <label class="form-label form-label-required" style="margin-bottom: 0;">Distribution Puroks</label>
                <span id="dist_purok_family_counter" class="status-badge active" style="font-size: 9px;">0 families</span>
              </div>
              <input type="hidden" name="target_purok" id="dist_target_purok" value="All Puroks">

              <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: 6px; padding: 8px 10px; max-height: 120px; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 8px;">
                  <?php foreach ($barangayPuroks as $p): ?>
                    <label style="font-size: 10px; display: flex; align-items: center; gap: 5px; margin-bottom: 0; color: var(--color-text); cursor: not-allowed;">
                      <input type="checkbox" class="dist-event-purok-cb" value="<?= htmlspecialchars($p) ?>" disabled>
                      <span><?= htmlspecialchars($p) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="font-size: 9px; color: var(--color-text-muted); margin-top: 2px;">
                Configured from event scope.
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
              <div>
                <label class="form-label form-label-required" style="margin-bottom: 3px; display: block;">Total Budget (PHP)</label>
                <input type="number" step="0.01" min="1" name="total_budget" id="dist_total_budget" class="form-control"
                  placeholder="500000.00" required style="padding: 6px 9px;" oninput="onCustomBudgetInput()">
              </div>
              <div>
                <label class="form-label form-label-required" style="margin-bottom: 3px; display: block;">Amount per Family (PHP)</label>
                <input type="number" step="0.01" min="1" name="amount_per_beneficiary" id="dist_rate_budget"
                  class="form-control" placeholder="3000.00" required style="padding: 6px 9px;" oninput="onCustomRateInput()">
              </div>
            </div>

            <!-- AI Action Buttons -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <button type="button" class="btn btn-outline btn-sm" onclick="aiSuggestBudgetAndRate()">
                AI Suggest Budget
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="aiDivideBudgetByHouseholds()">
                AI Divide by Families
              </button>
            </div>

            <!-- Calculated Preview Card -->
            <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: 6px; padding: 7px 12px; display: flex; justify-content: space-between; align-items: center;">
              <span style="font-size: 10.5px; color: var(--color-text-secondary);">Calculated Capacity:</span>
              <span id="dist_target_preview" style="font-size: 11px; font-weight: 700; color: var(--color-primary);">0 families</span>
            </div>

            <div id="dist_ai_recommend_badge" style="display: none; padding: 5px 8px; border-radius: 4px; font-size: 9.5px; line-height: 1.4;"></div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
              <div>
                <label class="form-label" style="margin-bottom: 3px; display: block;">Funding Source</label>
                <input type="text" name="funding_source" id="dist_funding_source" class="form-control"
                  value="LGU Calamity Fund" placeholder="Funding source" style="padding: 6px 9px;">
              </div>
              <div>
                <label class="form-label" style="margin-bottom: 3px; display: block;">Budget Source Type</label>
                <select name="added_type" id="dist_added_type" class="form-control" style="padding: 6px 9px;">
                  <option value="ai_recommended">AI Recommended</option>
                  <option value="added_by_admin">Admin Configured</option>
                </select>
              </div>
            </div>

            <div>
              <label class="form-label" style="margin-bottom: 3px; display: block;">Administrative Notes</label>
              <textarea name="notes" id="dist_notes" class="form-control" rows="2"
                placeholder="Optional notes or allocation guidelines..." style="padding: 6px 9px;"></textarea>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeDistributeModal()">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm" id="btn_submit_distribution">Save Distribution</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: Eligible Families -->
<div class="modal" id="eligibleFamiliesModal">
  <div class="modal-dialog modal-lg" style="max-width: 880px;">
    <div class="modal-header">
      <div>
        <h3>Eligible Families Directory &mdash; <span id="efModalProgramBadge" style="font-weight: 400; color: var(--color-text-secondary);"></span></h3>
        <div style="font-size: 10px; color: var(--color-text-muted); margin-top: 2px;">
          Household families and distribution eligibility status.
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeEligibleFamiliesModal()">&times;</button>
    </div>

    <!-- Telemetry Sub-bar -->
    <div class="detail-telemetry-bar">
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Total Families</span>
        <span class="detail-telemetry-val" id="efTotalFamiliesCount">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">With PWD</span>
        <span class="detail-telemetry-val" id="efPwdFamiliesCount" style="color: var(--color-warning);">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">With Seniors</span>
        <span class="detail-telemetry-val" id="efSeniorFamiliesCount">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Released</span>
        <span class="detail-telemetry-val" id="efClaimedCount" style="color: var(--color-success);">0</span>
      </div>
      <div class="detail-telemetry-item">
        <span class="detail-telemetry-label">Unreleased</span>
        <span class="detail-telemetry-val" id="efUnclaimedCount">0</span>
      </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding: 8px 16px; border-bottom: 1px solid var(--color-border-light); background: #FFFFFF; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
      <div style="flex: 1; min-width: 170px;">
        <input type="text" id="efSearchInput" class="form-control" placeholder="Search family, head, or code..."
          style="font-size: 10px; padding: 4px 8px;" oninput="renderEligibleFamiliesTable()">
      </div>
      <div style="width: 135px;">
        <select id="efPurokFilter" class="form-control" style="font-size: 10px; padding: 4px 8px;"
          onchange="renderEligibleFamiliesTable()">
          <option value="">All Puroks</option>
          <?php foreach ($barangayPuroks as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="width: 145px;">
        <select id="efPriorityFilter" class="form-control" style="font-size: 10px; padding: 4px 8px;"
          onchange="renderEligibleFamiliesTable()">
          <option value="">All Families</option>
          <option value="pwd">With PWD Members</option>
          <option value="seniors">With Senior Citizens</option>
          <option value="indigent">Indigent</option>
        </select>
      </div>
      <div style="width: 135px;">
        <select id="efClaimFilter" class="form-control" style="font-size: 10px; padding: 4px 8px;"
          onchange="renderEligibleFamiliesTable()">
          <option value="">All Statuses</option>
          <option value="unclaimed" selected>Unreleased</option>
          <option value="claimed">Released</option>
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
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600;">Representative &amp; Contact</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; width: 85px;">Purok</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: center; width: 110px;">Demographics</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: center; width: 100px;">Aid Status</th>
            <th style="padding: 8px 12px; font-size: 10px; font-weight: 600; text-align: right; width: 100px;">Action</th>
          </tr>
        </thead>
        <tbody id="efTableBody">
          <!-- Populated via JS -->
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
      <h3>Financial Aid Audit Records &mdash; Barangay <?= clean($barangay['name']) ?></h3>
      <button type="button" class="modal-close" onclick="closeAuditRecordsModal()">&times;</button>
    </div>
    <div class="modal-body" style="padding: 0;">
      <div style="padding: 8px 16px; background: #FAFCFC; border-bottom: 1px solid var(--color-border-light); font-size: 10px; color: var(--color-text-secondary); display: flex; justify-content: space-between;">
        <span>Chronological record of allocations and disbursements.</span>
        <span><?= count($audits) ?> logged entries</span>
      </div>
      <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="position: sticky; top: 0; background: #FAFCFC; border-bottom: 1px solid var(--color-border); z-index: 1;">
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; width: 120px;">Timestamp</th>
              <th style="padding: 8px 12px; text-align: center; font-size: 10px; font-weight: 600; width: 90px;">Action</th>
              <th style="padding: 8px 12px; text-align: right; font-size: 10px; font-weight: 600; width: 90px;">Amount</th>
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600;">Details</th>
              <th style="padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; width: 120px;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($audits)): ?>
              <tr>
                <td colspan="5" style="padding: 24px; text-align: center; color: var(--color-text-muted); font-size: 10.5px;">
                  No audit logs recorded for this barangay yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($audits as $a):
                $actClass = 'action-' . strtolower(str_replace(' ', '', $a['action']));
                ?>
                <tr style="border-bottom: 1px solid var(--color-border-light);">
                  <td style="padding: 8px 12px; font-size: 10px; color: var(--color-text-muted); white-space: nowrap;">
                    <?= date('Y-m-d H:i', strtotime($a['created_at'])) ?>
                  </td>
                  <td style="padding: 8px 12px; text-align: center;">
                    <span class="action-badge <?= $actClass ?>">
                      <?= htmlspecialchars($a['action']) ?>
                    </span>
                  </td>
                  <td style="padding: 8px 12px; text-align: right; font-size: 10.5px; font-weight: 600; color: var(--color-text);">
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
      <button type="button" class="btn btn-outline btn-sm" onclick="closeAuditRecordsModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal: View Household Family Members -->
<div class="modal" id="householdMembersModal">
  <div class="modal-dialog" style="max-width: 600px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <h3>Family Members</h3>
        <span class="status-badge unclaimed" id="fam_members_badge"></span>
      </div>
      <button type="button" class="modal-close" onclick="closeHouseholdMembersModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 6px; padding: 9px 12px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
        <div>
          <div style="font-size: 12px; font-weight: 600; color: var(--color-primary);" id="fam_members_family_name">&mdash;</div>
          <div style="font-size: 10.5px; color: var(--color-text-secondary); margin-top: 1px;" id="fam_members_rep_info">&mdash;</div>
        </div>
        <div style="text-align: right;">
          <div style="font-size: 9px; color: var(--color-text-secondary); text-transform: uppercase; font-weight: 600;">Purok &amp; Code</div>
          <div style="font-size: 10.5px; font-weight: 600; color: var(--color-text);" id="fam_members_purok_code">&mdash;</div>
        </div>
      </div>

      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
          <label style="font-size: 10px; font-weight: 600; color: var(--color-text-secondary); text-transform: uppercase; margin: 0;">
            Registered Household Members
          </label>
          <span style="font-size: 9.5px; color: var(--color-primary); background: var(--color-surface-subtle); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--color-border);" id="fam_members_count_pill">
            0 Members
          </span>
        </div>
        <div id="fam_members_table_box" style="max-height: 260px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 6px; background: #FFFFFF;">
          <!-- Loaded dynamically -->
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeHouseholdMembersModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal: Report Aid Allocation Not Received -->
<div class="modal" id="notReceivedAidModal">
  <div class="modal-dialog" style="max-width: 480px;">
    <div class="modal-header">
      <h3 style="color: var(--color-danger);">Report Aid Not Received</h3>
      <button type="button" class="modal-close" onclick="closeNotReceivedAidModal()">&times;</button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/backend/functions/financial_aid/confirm_receipt.php">
      <input type="hidden" name="allocation_id" id="not_received_alloc_id" value="">
      <input type="hidden" name="receipt_status" value="Not Received">
      <div class="modal-body">
        <div style="background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); border-radius: 6px; padding: 9px 12px; margin-bottom: 12px; font-size: 10.5px; color: var(--color-danger); line-height: 1.4;">
          Confirming this allocation as not received will record an audit entry and notify ICDRRMO Central Depot.
        </div>

        <div style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: 6px; padding: 9px 12px; margin-bottom: 12px; font-size: 10.5px; display: flex; flex-direction: column; gap: 3px;">
          <div><strong style="color: var(--color-text-secondary);">Program:</strong> <span id="not_received_program_name" style="color: var(--color-primary); font-weight: 600;">&mdash;</span></div>
          <div><strong style="color: var(--color-text-secondary);">Code:</strong> <span id="not_received_alloc_code" style="font-weight: 600;">&mdash;</span></div>
          <div><strong style="color: var(--color-text-secondary);">Fund:</strong> <span id="not_received_budget" style="font-weight: 600; color: var(--color-primary);">&mdash;</span></div>
        </div>

        <div>
          <label class="form-label form-label-required" style="margin-bottom: 3px; display: block;">Reason for Non-Delivery</label>
          <textarea name="receipt_remarks" class="form-control" rows="3" placeholder="Specify reason for non-delivery..." required style="padding: 6px 9px;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeNotReceivedAidModal()">Cancel</button>
        <button type="submit" class="btn btn-sm btn-outline" style="color: var(--color-danger); border-color: var(--color-danger-border); font-weight: 600;">Confirm Not Received</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Data payloads for zero-lag instant dynamic switching
  const rawAllocations = <?= json_encode($allocations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const rawDistributions = <?= json_encode($allDistributions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const rawHouseholds = <?= json_encode($householdFamilies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const baseUrl = '<?= BASE_URL ?>';

  let selectedAllocationId = <?= (int) $selectedAllocId ?>;

  function formatMoney(amount) {
    return '₱' + Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
    const distByHhId = {};
    allocDistributions.forEach(d => {
      if (d.household_id && Number(d.household_id) > 0 && d.claim_status === 'Claimed') {
        distByHhId[Number(d.household_id)] = d;
      }
    });

    // Calculate target scoped families
    const targetPurokStr = (alloc.target_purok || 'All Puroks').trim();
    const isAllPuroksScope = (targetPurokStr === 'All Puroks' || targetPurokStr === 'All' || targetPurokStr === '');
    const targetPurokSet = isAllPuroksScope ? null : new Set(targetPurokStr.split(',').map(s => s.trim().toLowerCase()));

    const scopedFamilies = rawHouseholds.filter(fam => {
      if (isAllPuroksScope) return true;
      return targetPurokSet.has((fam.purok_name || '').toLowerCase());
    });

    const totalFamilies = scopedFamilies.length;
    const releasedFamiliesCount = scopedFamilies.filter(f => distByHhId[f.id]).length;

    const isInitiated = Number(alloc.total_budget || 0) > 0 || Boolean(alloc.is_distributed == 1 || alloc.is_distributed === '1' || alloc.is_distributed === true);

    const totalBudget = isInitiated ? Number(alloc.total_budget || 0) : 0;
    const totalDisbursed = isInitiated ? allocDistributions
      .filter(d => d.claim_status === 'Claimed')
      .reduce((sum, d) => sum + Number(d.amount || 0), 0) : 0;
    const remainingBalance = isInitiated ? Math.max(0, totalBudget - totalDisbursed) : 0;

    // Update Left Table amounts dynamically
    const allocFundEl = document.getElementById('alloc_fund_' + selectedAllocationId);
    if (allocFundEl) allocFundEl.textContent = formatMoney(totalBudget);
    const allocRemEl = document.getElementById('alloc_rem_' + selectedAllocationId);
    if (allocRemEl) allocRemEl.textContent = 'Rem: ' + formatMoney(remainingBalance);

    // Update Table 2 Header & Telemetry
    document.getElementById('detailProgramTitle').textContent = alloc.program_name;
    const scopeBadge = isAllPuroksScope ? 'Barangay-wide (All Puroks)' : 'Scope: ' + alloc.target_purok;
    const rateLabel = isInitiated ? `${formatMoney(alloc.amount_per_beneficiary)}/family` : 'Awaiting Distribution Setup';
    document.getElementById('detailProgramCode').textContent = `${alloc.allocation_code} • ${rateLabel} • ${scopeBadge} (${totalFamilies} families in scope)`;

    document.getElementById('detailTotalBudget').textContent = formatMoney(totalBudget);
    document.getElementById('detailDisbursedBudget').textContent = formatMoney(totalDisbursed);
    document.getElementById('detailRemainingBudget').textContent = formatMoney(remainingBalance);
    const percentReleased = (isInitiated && totalFamilies > 0) ? Math.round((releasedFamiliesCount / totalFamilies) * 100) : 0;
    document.getElementById('detailBeneficiariesRatio').textContent = `${isInitiated ? releasedFamiliesCount : 0} of ${totalFamilies} families (${percentReleased}%)`;

    // Toggle uninitiated notice banner
    const uninitBanner = document.getElementById('uninitiatedNoticeBanner');
    if (uninitBanner) {
      uninitBanner.style.display = isInitiated ? 'none' : 'flex';
    }

    // Synchronize modal fields with the selected program
    loadAllocationToDistributeModal(selectedAllocationId);

    // Populate Scope / Purok filter options for Table 2
    populatePurokFilter(alloc);

    // Render Table 2 Beneficiaries
    renderBeneficiariesTable();
  }

  function populatePurokFilter(alloc) {
    const purokSelect = document.getElementById('beneficiaryPurokFilter');
    if (!purokSelect) return;

    const currentVal = purokSelect.value;
    const targetPurokStr = (alloc.target_purok || 'All Puroks').trim();
    const isAllPuroksScope = (targetPurokStr === 'All Puroks' || targetPurokStr === 'All' || targetPurokStr === '');

    let optionsHtml = '';
    if (isAllPuroksScope) {
      optionsHtml += '<option value="">All Puroks (Barangay-wide)</option>';
      const uniquePuroks = [...new Set(rawHouseholds.map(h => h.purok_name).filter(Boolean))].sort();
      uniquePuroks.forEach(p => {
        optionsHtml += `<option value="${escapeHtml(p.toLowerCase())}">${escapeHtml(p)}</option>`;
      });
    } else {
      const targetList = targetPurokStr.split(',').map(s => s.trim()).filter(Boolean);
      optionsHtml += `<option value="">Target Scope (${targetList.length} Puroks)</option>`;
      targetList.forEach(p => {
        optionsHtml += `<option value="${escapeHtml(p.toLowerCase())}">${escapeHtml(p)}</option>`;
      });
      optionsHtml += '<option value="__all__">(Show All Hinaplanon Puroks)</option>';
    }

    purokSelect.innerHTML = optionsHtml;
    if (currentVal) {
      purokSelect.value = currentVal;
    }
  }

  function renderBeneficiariesTable() {
    const tbody = document.getElementById('beneficiariesTableBody');
    if (!tbody) return;

    const alloc = rawAllocations.find(a => Number(a.id) === selectedAllocationId);
    if (!alloc) {
      tbody.innerHTML = `<tr><td colspan="8" style="padding: 24px; text-align: center; color: var(--color-text-muted);">Please select a Financial Aid Program on the left.</td></tr>`;
      return;
    }

    const allocDistributions = rawDistributions.filter(d => Number(d.allocation_id) === selectedAllocationId);
    const distByHhId = {};
    const distByName = {};
    allocDistributions.forEach(d => {
      if (d.household_id && Number(d.household_id) > 0) {
        distByHhId[Number(d.household_id)] = d;
      }
      if (d.beneficiary_name) {
        distByName[d.beneficiary_name.toLowerCase()] = d;
      }
    });

    const searchVal = (document.getElementById('beneficiarySearchInput')?.value || '').trim().toLowerCase();
    const statusFilter = (document.getElementById('beneficiaryStatusFilter')?.value || '').trim();
    const purokFilter = (document.getElementById('beneficiaryPurokFilter')?.value || '').trim().toLowerCase();
    const priorityFilter = (document.getElementById('beneficiaryPriorityFilter')?.value || '').trim();

    // Target Purok Scope of the program
    const targetPurokStr = (alloc.target_purok || 'All Puroks').trim();
    const isAllPuroksScope = (targetPurokStr === 'All Puroks' || targetPurokStr === 'All' || targetPurokStr === '');
    const targetPurokSet = isAllPuroksScope ? null : new Set(targetPurokStr.split(',').map(s => s.trim().toLowerCase()));

    // Filter household families
    const filtered = rawHouseholds.filter(fam => {
      const famPurok = (fam.purok_name || '').toLowerCase();

      // Scope & Purok filter
      if (purokFilter === '__all__') {
        // User requested all barangay puroks
      } else if (purokFilter) {
        if (famPurok !== purokFilter) return false;
      } else if (!isAllPuroksScope) {
        // Default to program's target purok scope
        if (!targetPurokSet.has(famPurok)) return false;
      }

      const dist = distByHhId[Number(fam.id)] || distByName[(fam.head_full_name || '').toLowerCase()];
      const refNo = dist ? (dist.reference_no || '') : '';

      // Search filter
      if (searchVal) {
        const matchSearch =
          (fam.family_name && fam.family_name.toLowerCase().includes(searchVal)) ||
          (fam.head_full_name && fam.head_full_name.toLowerCase().includes(searchVal)) ||
          (fam.household_code && fam.household_code.toLowerCase().includes(searchVal)) ||
          (fam.purok_name && fam.purok_name.toLowerCase().includes(searchVal)) ||
          (fam.head_phone && fam.head_phone.toLowerCase().includes(searchVal)) ||
          refNo.toLowerCase().includes(searchVal);
        if (!matchSearch) return false;
      }

      // Status filter
      const isClaimed = (dist && dist.claim_status === 'Claimed');
      if (statusFilter === 'Claimed' && !isClaimed) return false;
      if (statusFilter === 'Unclaimed' && isClaimed) return false;

      // Priority filter
      if (priorityFilter === 'pwd' && Number(fam.pwd_count || 0) <= 0) return false;
      if (priorityFilter === 'seniors' && Number(fam.seniors_count || 0) <= 0) return false;
      if (priorityFilter === 'indigent' && (!fam.economic_status || !fam.economic_status.toLowerCase().includes('indigent'))) return false;

      return true;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = `
      <tr>
        <td colspan="9" style="padding: 30px; text-align: center; color: var(--color-text-muted); font-size: 11px;">
          No household families found matching the selected filters.
        </td>
      </tr>
    `;
      return;
    }

    let html = '';
    filtered.forEach(fam => {
      const dist = distByHhId[Number(fam.id)] || distByName[(fam.head_full_name || '').toLowerCase()];
      const isClaimed = (dist && dist.claim_status === 'Claimed');
      const isInitiated = Number(alloc.total_budget || 0) > 0 || Boolean(alloc.is_distributed == 1 || alloc.is_distributed === '1' || alloc.is_distributed === true);
      const rateAmount = isInitiated ? Number(alloc.amount_per_beneficiary || 0) : 0;
      const amount = dist ? Number(dist.amount || rateAmount) : rateAmount;

      // Guaranteed Reference Number (Real, Meaningful Data for every household)
      const cleanAllocCode = (alloc.allocation_code || ('EVT-' + (alloc.event_id || alloc.id))).replace(/[^a-zA-Z0-9-]/g, '');
      const cleanHhCode = (fam.household_code || ('HH-' + fam.id)).trim();
      const effectiveRefNo = (dist && dist.reference_no)
        ? dist.reference_no
        : `REF-${cleanAllocCode.replace('AID-', '')}-${cleanHhCode}`;

      let demoBadges = '';
      if (Number(fam.pwd_count) > 0) {
        demoBadges += `<span class="status-badge pending" style="font-size:9px;margin-left:4px;padding:1px 4px;">${fam.pwd_count} PWD</span>`;
      }
      if (Number(fam.seniors_count) > 0) {
        demoBadges += `<span class="status-badge" style="font-size:9px;margin-left:4px;padding:1px 4px;background:var(--color-surface-subtle);color:var(--color-text-secondary);border:1px solid var(--color-border);">${fam.seniors_count} Senior</span>`;
      }

      const claimDateStr = isClaimed && dist.claim_date
        ? `<div>${dist.claim_date.substring(0, 16)}</div><div style="font-size: 9.5px; color: var(--color-text-muted);">${escapeHtml(dist.disbursing_officer || 'Barangay Office')}</div>`
        : '<span style="color: var(--color-text-muted);">Pending</span>';

      // Status Badge
      const statusBadge = isClaimed
        ? `<span class="status-badge claimed">Released</span>`
        : (isInitiated
          ? `<span class="status-badge unclaimed">Unreleased</span>`
          : `<span class="status-badge unclaimed">Pending</span>`
        );

      // Reference Number Cell HTML
      const refNoHtml = `
      <span style="font-size: 10px; font-weight: 600; color: var(--color-primary);">
        ${escapeHtml(effectiveRefNo)}
      </span>
    `;

      // SMS Column Button & Delivery Status
      const smsStatus = dist ? (dist.sms_status || 'not_sent') : 'not_sent';
      let smsHtml = '';
      if (smsStatus === 'sent') {
        smsHtml = `
        <div style="display: flex; flex-direction: column; align-items: center; gap: 2px;">
          <span class="status-badge claimed" style="font-size: 8.5px; padding: 1px 5px;">Sent</span>
          <button type="button" class="btn btn-outline" id="btn_sms_${fam.id}" style="padding: 1px 5px; font-size: 8.5px; color: var(--color-text-secondary); border-color: var(--color-border); cursor: pointer;" onclick="sendSmsToHousehold(${fam.id}, '${escapeJs(fam.family_name)}', '${escapeJs(fam.head_full_name)}', '${escapeJs(fam.head_phone || '')}', '${escapeJs(effectiveRefNo)}')" title="Resend SMS Notification">
            Resend
          </button>
        </div>
      `;
      } else if (smsStatus === 'failed') {
        smsHtml = `
        <div style="display: flex; flex-direction: column; align-items: center; gap: 2px;">
          <span class="status-badge" style="font-size: 8.5px; padding: 1px 5px; background: var(--color-danger-bg); color: var(--color-danger); border: 1px solid var(--color-danger-border);">
            Failed
          </span>
          <button type="button" class="btn btn-outline" id="btn_sms_${fam.id}" style="padding: 1px 5px; font-size: 8.5px; color: var(--color-danger); border-color: var(--color-danger-border); cursor: pointer;" onclick="sendSmsToHousehold(${fam.id}, '${escapeJs(fam.family_name)}', '${escapeJs(fam.head_full_name)}', '${escapeJs(fam.head_phone || '')}', '${escapeJs(effectiveRefNo)}')" title="Retry Sending SMS">
            Retry
          </button>
        </div>
      `;
      } else {
        if (fam.head_phone && fam.head_phone.trim() !== '') {
          smsHtml = `
          <button type="button" class="btn btn-outline" id="btn_sms_${fam.id}" style="padding: 2px 7px; font-size: 9.5px; color: var(--color-primary); border-color: var(--color-border); cursor: pointer; white-space: nowrap;" onclick="sendSmsToHousehold(${fam.id}, '${escapeJs(fam.family_name)}', '${escapeJs(fam.head_full_name)}', '${escapeJs(fam.head_phone || '')}', '${escapeJs(effectiveRefNo)}')" title="Send SMS Notification to ${escapeHtml(fam.head_phone)}">
            Send SMS
          </button>
        `;
        } else {
          smsHtml = `<span style="font-size: 9px; color: var(--color-text-muted);">&mdash;</span>`;
        }
      }

      const actionHtml = isClaimed
        ? `
        <div style="display: inline-flex; gap: 4px; align-items: center; justify-content: center;">
          <button type="button" class="btn btn-outline" style="padding: 2px 6px; font-size: 9.5px; color: var(--color-danger); border-color: var(--color-danger-border);" onclick="voidHouseholdAid(${fam.id}, ${dist.id}, '${escapeJs(fam.family_name)}')" title="Void Release">
            Void
          </button>
          <button type="button" class="btn btn-outline" style="padding: 2px 6px; font-size: 9.5px;" onclick="resendSms(${dist.id})" title="Send SMS">
            SMS
          </button>
        </div>
      `
        : (isInitiated
          ? `
            <button type="button" class="btn btn-primary" id="btn_release_${fam.id}" style="padding: 3px 8px; font-size: 10px; font-weight: 500;" onclick="releaseAidToHousehold(${fam.id}, '${escapeJs(fam.family_name)}', '${escapeJs(fam.head_full_name)}', '${escapeJs(fam.purok_name)}', '${escapeJs(fam.head_phone || '')}', ${rateAmount}, '${escapeJs(effectiveRefNo)}')" title="Release ${formatMoney(rateAmount)} Aid">
              Release Aid
            </button>
          `
          : `
            <button type="button" class="btn btn-outline" style="padding: 3px 8px; font-size: 10px; color: var(--color-primary); border-color: var(--color-border); font-weight: 500;" onclick="openDistributeModalForCurrent()" title="Configure budget first">
              Set Budget
            </button>
          `
        );

      html += `
      <tr style="border-bottom: 1px solid var(--color-border-light);">
        <td style="padding: 8px 12px;">
          ${refNoHtml}
        </td>
        <td style="padding: 8px 12px;">
          <div style="font-weight: 600; font-size: 11px; color: var(--color-primary); line-height: 1.3;">
            [${escapeHtml(fam.household_code)}] ${escapeHtml(fam.family_name)} ${demoBadges}
          </div>
          <div style="font-size: 9.5px; color: var(--color-text-secondary); margin-top: 1px;">
            Rep: <strong>${escapeHtml(fam.head_full_name)}</strong> &bull; ${escapeHtml(fam.head_phone || 'No Contact')}
          </div>
        </td>
        <td style="padding: 8px 12px; font-size: 10.5px; color: var(--color-text);">
          ${escapeHtml(fam.purok_name)}
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          <button type="button" class="btn btn-outline" style="padding: 2px 6px; font-size: 9.5px; color: var(--color-primary); border-color: var(--color-border); cursor: pointer; white-space: nowrap;" onclick="viewHouseholdMembers(${fam.id})">
            ${Number(fam.total_members || 1)} Members
          </button>
        </td>
        <td style="padding: 8px 12px; text-align: right; font-weight: 600; font-size: 11px; color: var(--color-text);">
          ${isInitiated ? formatMoney(amount) : '<span style="color:var(--color-text-muted);">&mdash;</span>'}
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          ${statusBadge}
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          ${smsHtml}
        </td>
        <td style="padding: 8px 12px; font-size: 9.5px;">
          ${claimDateStr}
        </td>
        <td style="padding: 8px 12px; text-align: center;">
          ${actionHtml}
        </td>
      </tr>
    `;
    });

    tbody.innerHTML = html;
  }

  function filterBeneficiariesTable() {
    renderBeneficiariesTable();
  }

  async function releaseAidToHousehold(famId, famName, headName, purokName, phone, amount, effectiveRefNo) {
    const btn = document.getElementById('btn_release_' + famId);
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Releasing...';
    }

    const formData = new FormData();
    formData.append('action', 'add_beneficiary');
    formData.append('allocation_id', selectedAllocationId);
    formData.append('household_id', famId);
    formData.append('beneficiary_name', headName || (famName + ' Head'));
    formData.append('purok', purokName || 'Purok');
    formData.append('phone', phone || '09000000000');
    formData.append('amount', amount || 5000);
    formData.append('reference_no', effectiveRefNo || '');
    formData.append('claim_status', 'Claimed');
    formData.append('send_sms', '1');
    formData.append('remarks', '[' + famId + '] ' + famName);

    try {
      const resp = await fetch(baseUrl + '/backend/functions/financial_aid/distribute.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });
      const res = await resp.json();
      if (!res.success) {
        throw new Error(res.message || 'Failed to release aid.');
      }

      // Update or insert into rawDistributions in memory
      const existingIdx = rawDistributions.findIndex(d =>
        Number(d.allocation_id) === Number(selectedAllocationId) && Number(d.household_id) === Number(famId)
      );

      const updatedDist = {
        id: res.distribution_id,
        allocation_id: Number(selectedAllocationId),
        household_id: Number(famId),
        beneficiary_name: headName || famName,
        purok: purokName,
        phone: phone,
        amount: amount || 5000,
        claim_status: 'Claimed',
        claim_date: res.claim_date || new Date().toISOString().replace('T', ' ').substring(0, 19),
        reference_no: res.reference_no,
        sms_status: res.sms_status || 'sent',
        disbursing_officer: 'Barangay Office'
      };

      if (existingIdx >= 0) {
        rawDistributions[existingIdx] = Object.assign({}, rawDistributions[existingIdx], updatedDist);
      } else {
        rawDistributions.unshift(updatedDist);
      }

      // Update allocation in rawAllocations
      const alloc = rawAllocations.find(a => Number(a.id) === Number(selectedAllocationId));
      if (alloc) {
        alloc.disbursed_budget = Number(alloc.disbursed_budget || 0) + Number(amount || 5000);
        alloc.remaining_budget = Math.max(0, Number(alloc.total_budget || 0) - Number(alloc.disbursed_budget));
        alloc.claimed_recipients = Number(alloc.claimed_recipients || 0) + 1;
      }

      // Refresh UI
      selectAllocation(selectedAllocationId);
    } catch (err) {
      alert('Error releasing aid: ' + err.message);
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Release Aid';
      }
    }
  }

  async function voidHouseholdAid(famId, distId, famName) {
    if (!confirm('Are you sure you want to void disbursement for ' + famName + '? The aid funds will be returned to the remaining allocation budget.')) {
      return;
    }

    const formData = new FormData();
    formData.append('action', 'void');
    formData.append('distribution_id', distId);

    try {
      const resp = await fetch(baseUrl + '/backend/functions/financial_aid/distribute.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });
      const res = await resp.json();
      if (!res.success) {
        throw new Error(res.message || 'Failed to void aid.');
      }

      // Update distribution in rawDistributions
      const dist = rawDistributions.find(d => Number(d.id) === Number(distId));
      const voidedAmount = dist ? Number(dist.amount || 5000) : 5000;
      if (dist) {
        dist.claim_status = 'Unclaimed';
      }

      // Update allocation in rawAllocations
      const alloc = rawAllocations.find(a => Number(a.id) === Number(selectedAllocationId));
      if (alloc) {
        alloc.disbursed_budget = Math.max(0, Number(alloc.disbursed_budget || 0) - voidedAmount);
        alloc.remaining_budget = Number(alloc.remaining_budget || 0) + voidedAmount;
        alloc.claimed_recipients = Math.max(0, Number(alloc.claimed_recipients || 0) - 1);
      }

      // Refresh UI
      selectAllocation(selectedAllocationId);
    } catch (err) {
      alert('Error voiding disbursement: ' + err.message);
    }
  }

  async function sendSmsToHousehold(famId, famName, headName, phone, referenceNo) {
    if (!phone || phone.trim() === '') {
      alert(`No contact number registered for ${headName || famName}.`);
      return;
    }

    const btn = document.getElementById('btn_sms_' + famId);
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `<span style="font-size: 8.5px;">Sending...</span>`;
    }

    const formData = new FormData();
    formData.append('action', 'send_sms');
    formData.append('allocation_id', selectedAllocationId);
    formData.append('household_id', famId);
    formData.append('beneficiary_name', headName || (famName + ' Head'));
    formData.append('phone', phone);
    formData.append('reference_no', referenceNo || '');

    const existingDist = rawDistributions.find(d =>
      Number(d.allocation_id) === Number(selectedAllocationId) && Number(d.household_id) === Number(famId)
    );
    if (existingDist && existingDist.id) {
      formData.append('distribution_id', existingDist.id);
    }

    try {
      const resp = await fetch(baseUrl + '/backend/functions/financial_aid/distribute.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });
      const res = await resp.json();
      if (!res.success) {
        throw new Error(res.message || 'Failed to send SMS.');
      }

      const distId = res.distribution_id || (existingDist ? existingDist.id : null);
      const updatedDist = {
        id: distId,
        allocation_id: Number(selectedAllocationId),
        household_id: Number(famId),
        beneficiary_name: headName || famName,
        phone: phone,
        reference_no: res.reference_no || referenceNo,
        sms_status: res.sms_status || 'sent',
        claim_status: existingDist ? existingDist.claim_status : 'Unclaimed'
      };

      if (existingDist) {
        Object.assign(existingDist, updatedDist);
      } else {
        rawDistributions.push(updatedDist);
      }

      renderBeneficiariesTable();
      alert(`SMS dispatched successfully.\nRecipient: ${headName} (${phone})\nRef No: ${res.reference_no || referenceNo}\nStatus: ${res.sms_status}`);
    } catch (err) {
      alert('SMS Notification Error: ' + err.message);
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = origText || 'Send SMS';
      }
    }
  }

  async function resendSms(distId) {
    const dist = rawDistributions.find(d => Number(d.id) === Number(distId));
    if (!dist) {
      alert('Distribution record not found.');
      return;
    }
    return sendSmsToHousehold(dist.household_id, dist.beneficiary_name, dist.beneficiary_name, dist.phone, dist.reference_no);
  }

  function filterAllocationsTable(term) {
    const query = (term || '').toLowerCase();
    document.querySelectorAll('#allocationsTableBody tr').forEach(row => {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(query) ? '' : 'none';
    });
  }

  // AI Rate Benchmarks by Disaster / Calamity Type (PHP per Family)
  const DISASTER_RATE_BENCHMARKS = {
    'Typhoon': 3000,
    'Flood': 4000,
    'Landslide': 5000,
    'Fire': 10000,
    'Earthquake': 7000,
    'General Calamity': 3000
  };

  function getScopedFamilyCount(scopeStr) {
    const cleanScope = (scopeStr || 'All Puroks').trim();
    if (cleanScope === 'All Puroks' || cleanScope === 'All' || cleanScope === '' || cleanScope.toLowerCase().includes('all puroks')) {
      return rawHouseholds.length;
    }
    const targetSet = new Set(cleanScope.split(',').map(s => s.trim().toLowerCase()).filter(Boolean));
    return rawHouseholds.filter(fam => targetSet.has((fam.purok_name || '').toLowerCase())).length;
  }

  // Gets total households for the current allocation/event
  function getCurrentEventTotalHouseholds() {
    const alloc = rawAllocations.find(a => Number(a.id) === Number(selectedAllocationId)) || rawAllocations[0];
    const targetPurokStr = (alloc?.target_purok || alloc?.event_target_puroks || 'All Puroks').trim();
    return getScopedFamilyCount(targetPurokStr);
  }

  // Dynamically sets the disabled purok checklist based on the event's selected puroks
  function syncEventPurokChecklist(alloc) {
    const targetPurokStr = (alloc?.target_purok || alloc?.event_target_puroks || 'All Puroks').trim();
    const isAll = (targetPurokStr === 'All Puroks' || targetPurokStr === 'All' || targetPurokStr === '' || targetPurokStr.toLowerCase().includes('all puroks'));
    const targetSet = isAll ? null : new Set(targetPurokStr.split(',').map(s => s.trim().toLowerCase()).filter(Boolean));

    let checkedCount = 0;
    document.querySelectorAll('.dist-event-purok-cb').forEach(cb => {
      const pName = cb.value.toLowerCase();
      const isTarget = isAll || (targetSet && targetSet.has(pName));
      cb.checked = isTarget;
      cb.disabled = true; // Disabled - cannot be unchecked by user!
      if (isTarget) checkedCount++;
    });

    // Store in hidden input so it is submitted with the form
    const hiddenTarget = document.getElementById('dist_target_purok');
    if (hiddenTarget) {
      hiddenTarget.value = targetPurokStr;
    }

    // Calculate and display total households in scope
    const totalHh = isAll ? rawHouseholds.length : rawHouseholds.filter(fam => targetSet && targetSet.has((fam.purok_name || '').toLowerCase())).length;
    const counter = document.getElementById('dist_purok_family_counter');
    if (counter) {
      counter.textContent = `${totalHh.toLocaleString()} families (${isAll ? 'All Puroks' : checkedCount + ' Puroks'})`;
    }

    return totalHh;
  }

  // First Button AI: Suggest total budget and amount per family based on disaster type and total households
  function aiSuggestBudgetAndRate() {
    const disasterSelect = document.getElementById('dist_disaster_type');
    const disasterType = disasterSelect ? disasterSelect.value : 'Typhoon';
    const rate = DISASTER_RATE_BENCHMARKS[disasterType] || 3000;

    const totalHouseholds = getCurrentEventTotalHouseholds();
    const totalBudget = totalHouseholds * rate;

    const rateInput = document.getElementById('dist_rate_budget');
    if (rateInput) rateInput.value = rate.toFixed(2);

    const budgetInput = document.getElementById('dist_total_budget');
    if (budgetInput) budgetInput.value = totalBudget.toFixed(2);

    const typeSelect = document.getElementById('dist_added_type');
    if (typeSelect) typeSelect.value = 'ai_recommended';

    updateTargetCountPreview();

    const badge = document.getElementById('dist_ai_recommend_badge');
    if (badge) {
      badge.style.display = 'block';
      badge.style.background = 'var(--color-surface-subtle)';
      badge.style.border = '1px solid var(--color-border)';
      badge.style.color = 'var(--color-primary)';
      badge.innerHTML = `<strong>AI Suggestion Applied:</strong> ₱${rate.toLocaleString()}/family benchmark for <strong>${escapeHtml(disasterType)}</strong> &times; <strong>${totalHouseholds.toLocaleString()} event households</strong> = <strong>₱${totalBudget.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong> total budget.`;
    }
  }

  // Second Button AI: The user sets total budget then AI divides total budget / total households -> sets amount per family
  function aiDivideBudgetByHouseholds() {
    const budgetInput = document.getElementById('dist_total_budget');
    const totalBudget = parseFloat(budgetInput?.value) || 0;
    const totalHouseholds = getCurrentEventTotalHouseholds();

    if (totalHouseholds <= 0) {
      alert('No households found in the selected event puroks.');
      return;
    }

    if (totalBudget <= 0) {
      alert(`Please enter a Total Budget (PHP) first. The AI will then divide it across the ${totalHouseholds.toLocaleString()} households in the selected event puroks.`);
      if (budgetInput) budgetInput.focus();
      return;
    }

    const calculatedRate = totalBudget / totalHouseholds;

    const rateInput = document.getElementById('dist_rate_budget');
    if (rateInput) rateInput.value = calculatedRate.toFixed(2);

    const typeSelect = document.getElementById('dist_added_type');
    if (typeSelect) typeSelect.value = 'ai_recommended';

    updateTargetCountPreview();

    const badge = document.getElementById('dist_ai_recommend_badge');
    if (badge) {
      badge.style.display = 'block';
      badge.style.background = 'var(--color-surface-subtle)';
      badge.style.border = '1px solid var(--color-border)';
      badge.style.color = 'var(--color-primary)';
      badge.innerHTML = `<strong>AI Division Applied:</strong> ₱${totalBudget.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} total budget &divide; <strong>${totalHouseholds.toLocaleString()} total households</strong> = <strong>₱${calculatedRate.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong> per family.`;
    }
  }

  function onCustomBudgetInput() {
    const typeSelect = document.getElementById('dist_added_type');
    if (typeSelect) typeSelect.value = 'added_by_admin';
    updateTargetCountPreview();
    const badge = document.getElementById('dist_ai_recommend_badge');
    if (badge) {
      badge.style.display = 'block';
      badge.style.background = 'var(--color-surface-subtle)';
      badge.style.border = '1px solid var(--color-border)';
      badge.style.color = 'var(--color-text-secondary)';
      badge.innerHTML = `<strong>Manual Entry:</strong> Total budget adjusted by administrator.`;
    }
  }

  function onCustomRateInput() {
    const typeSelect = document.getElementById('dist_added_type');
    if (typeSelect) typeSelect.value = 'added_by_admin';
    updateTargetCountPreview();
    const badge = document.getElementById('dist_ai_recommend_badge');
    if (badge) {
      badge.style.display = 'block';
      badge.style.background = 'var(--color-surface-subtle)';
      badge.style.border = '1px solid var(--color-border)';
      badge.style.color = 'var(--color-text-secondary)';
      badge.innerHTML = `<strong>Manual Entry:</strong> Rate per family adjusted. Capacity recalculated.`;
    }
  }

  function updateTargetCountPreview() {
    const total = parseFloat(document.getElementById('dist_total_budget')?.value) || 0;
    const rate = parseFloat(document.getElementById('dist_rate_budget')?.value) || 0;
    const preview = document.getElementById('dist_target_preview');
    if (!preview) return;

    if (rate > 0 && total >= rate) {
      const count = Math.floor(total / rate);
      preview.textContent = count.toLocaleString() + ' families';
    } else {
      preview.textContent = '0 families';
    }
  }

  function loadAllocationToDistributeModal(allocId) {
    const alloc = rawAllocations.find(a => Number(a.id) === Number(allocId)) || rawAllocations[0];
    if (!alloc) return;

    const progInput = document.getElementById('dist_program_name');
    if (progInput) progInput.value = alloc.program_name || '';

    const allocIdInput = document.getElementById('dist_allocation_id');
    if (allocIdInput) allocIdInput.value = alloc.id;

    const disasterSelect = document.getElementById('dist_disaster_type');
    if (disasterSelect) {
      const dtype = alloc.disaster_type || 'Typhoon';
      let matched = false;
      for (let opt of disasterSelect.options) {
        if (opt.value.toLowerCase() === dtype.toLowerCase()) {
          disasterSelect.value = opt.value;
          matched = true;
          break;
        }
      }
      if (!matched) disasterSelect.value = 'Typhoon';
    }

    // Dynamically set disabled purok checklist based on created event's selected puroks
    syncEventPurokChecklist(alloc);

    const fundingInput = document.getElementById('dist_funding_source');
    if (fundingInput) fundingInput.value = alloc.funding_source || 'LGU Calamity Fund';

    const typeSelect = document.getElementById('dist_added_type');
    if (typeSelect) typeSelect.value = alloc.added_type || 'ai_recommended';

    const notesInput = document.getElementById('dist_notes');
    if (notesInput) notesInput.value = alloc.notes || '';

    const rateInput = document.getElementById('dist_rate_budget');
    const budgetInput = document.getElementById('dist_total_budget');

    const isInitiated = Number(alloc.total_budget || 0) > 0 || Boolean(alloc.is_distributed == 1 || alloc.is_distributed === '1' || alloc.is_distributed === true);

    if (isInitiated) {
      if (budgetInput) budgetInput.value = parseFloat(alloc.total_budget).toFixed(2);
      if (rateInput) rateInput.value = parseFloat(alloc.amount_per_beneficiary || 3000).toFixed(2);
      updateTargetCountPreview();
      const badge = document.getElementById('dist_ai_recommend_badge');
      if (badge) {
        badge.style.display = 'block';
        badge.style.background = 'var(--color-surface-subtle)';
        badge.style.border = '1px solid var(--color-border)';
        badge.style.color = 'var(--color-text)';
        badge.innerHTML = `<strong>Saved Configuration:</strong> Total budget ₱${Number(alloc.total_budget).toLocaleString('en-US', { minimumFractionDigits: 2 })} (₱${Number(alloc.amount_per_beneficiary).toLocaleString('en-US', { minimumFractionDigits: 2 })}/family).`;
      }
    } else {
      // Uninitiated program: fields start empty/zero awaiting user input or AI button recommendation
      if (budgetInput) budgetInput.value = '';
      if (rateInput) rateInput.value = '';
      const badge = document.getElementById('dist_ai_recommend_badge');
      if (badge) {
        badge.style.display = 'none';
        badge.innerHTML = '';
      }
      updateTargetCountPreview();
    }
  }

  function onDistributeFormSubmit(form) {
    return true;
  }

  function openDistributeModal() {
    const returnUrlElem = document.getElementById('dist_return_url');
    if (returnUrlElem) {
      returnUrlElem.value = window.location.href;
    }
    loadAllocationToDistributeModal(selectedAllocationId);
    document.getElementById('distributeModal').classList.add('show');
  }

  function openDistributeModalForCurrent() {
    openDistributeModal();
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
        demoBadges.push(`<span class="status-badge pending" style="font-size: 9px; padding: 1px 5px;">${fam.pwd_count} PWD</span>`);
      }
      if (Number(fam.seniors_count) > 0) {
        demoBadges.push(`<span class="status-badge unclaimed" style="font-size: 9px; padding: 1px 5px;">${fam.seniors_count} Senior</span>`);
      }
      if (demoBadges.length === 0) {
        demoBadges.push(`<span style="color:var(--color-text-muted);font-size:9.5px;">Standard</span>`);
      }

      const statusHtml = isClaimed
        ? `<span class="status-badge claimed">${escapeHtml(dist.claim_status || 'Claimed')}</span><div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;">${escapeHtml(dist.reference_no || '')}</div>`
        : `<span class="status-badge unclaimed">Eligible</span>`;

      const actionHtml = isClaimed
        ? `<span style="font-size:9.5px;color:var(--color-text-muted);">Payout Complete</span>`
        : `<button type="button" class="btn btn-primary" style="font-size:9.5px;padding:3px 8px;" onclick="selectFamilyForAyuda(${fam.id})">Release Aid</button>`;

      html += `
      <tr style="border-bottom: 1px solid var(--color-border-light);">
        <td style="padding: 8px 12px; font-size: 10px; font-weight: 600; color: var(--color-primary);">
          ${escapeHtml(fam.household_code)}
        </td>
        <td style="padding: 8px 12px;">
          <div style="font-weight: 600; font-size: 10.5px; color: var(--color-text);">${escapeHtml(fam.family_name)}</div>
          <div style="font-size: 9px; color: var(--color-text-muted);">${escapeHtml(fam.economic_status || 'Indigent')}</div>
        </td>
        <td style="padding: 8px 12px;">
          <div style="font-weight: 600; font-size: 10.5px; color: var(--color-primary);">${escapeHtml(fam.head_full_name)}</div>
          <div style="font-size: 9.5px; color: var(--color-text-muted);">${escapeHtml(fam.head_phone || '—')}</div>
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

  async function selectFamilyForAyuda(famId) {
    const fam = rawHouseholds.find(f => Number(f.id) === Number(famId));
    if (!fam) return;

    const alloc = rawAllocations.find(a => Number(a.id) === Number(selectedAllocationId));
    const rateAmount = Number(alloc?.amount_per_beneficiary || 5000);

    if (!confirm(`Confirm releasing ${formatMoney(rateAmount)} financial assistance to ${fam.family_name} (Representative: ${fam.head_full_name})?`)) {
      return;
    }

    const cleanAllocCode = (alloc?.allocation_code || ('EVT-' + (alloc?.event_id || alloc?.id || 1))).replace(/[^a-zA-Z0-9-]/g, '');
    const cleanHhCode = (fam.household_code || ('HH-' + fam.id)).trim();
    const effectiveRefNo = `REF-${cleanAllocCode.replace('AID-', '')}-${cleanHhCode}`;

    await releaseAidToHousehold(fam.id, fam.family_name, fam.head_full_name, fam.purok_name, fam.head_phone, rateAmount, effectiveRefNo);
    renderEligibleFamiliesTable();
  }

  function openAuditRecordsModal() {
    document.getElementById('auditRecordsModal').classList.add('show');
  }
  function closeAuditRecordsModal() {
    document.getElementById('auditRecordsModal').classList.remove('show');
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

  async function resendSms(distributionId) {
    if (!confirm('Dispatch SMS notification to this beneficiary?')) {
      return;
    }

    const formData = new FormData();
    formData.append('action', 'send_sms');
    formData.append('distribution_id', distributionId);

    try {
      const resp = await fetch(baseUrl + '/backend/functions/financial_aid/distribute.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });
      const res = await resp.json();
      if (res.success) {
        const dist = rawDistributions.find(d => Number(d.id) === Number(distributionId));
        if (dist) dist.sms_status = 'sent';
        selectAllocation(selectedAllocationId);
        alert(res.message);
      } else {
        alert(res.message || 'Failed to dispatch SMS.');
      }
    } catch (err) {
      alert('Error sending SMS: ' + err.message);
    }
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

  function viewHouseholdMembers(householdId) {
    const fam = rawHouseholds.find(f => Number(f.id) === Number(householdId));

    document.getElementById('fam_members_badge').textContent = fam ? (fam.household_code || 'HH') : 'HH';
    document.getElementById('fam_members_family_name').textContent = fam ? (fam.family_name || 'Family') : 'Loading...';
    document.getElementById('fam_members_rep_info').innerHTML = fam
      ? `Representative: <strong>${escapeHtml(fam.head_full_name || '—')}</strong> ${fam.head_phone ? '• ' + escapeHtml(fam.head_phone) : ''}`
      : 'Please wait...';
    document.getElementById('fam_members_purok_code').textContent = fam
      ? `${fam.purok_name || '—'} (${fam.household_code || '—'})`
      : '—';
    document.getElementById('fam_members_count_pill').textContent = `${fam?.total_members || 0} Members`;
    document.getElementById('fam_members_table_box').innerHTML = `
      <div style="text-align: center; padding: 24px 16px; font-size: 11px; color: var(--color-text-muted);">
        Loading family members...
      </div>
    `;

    document.getElementById('householdMembersModal').classList.add('show');

    fetch(`${baseUrl}/backend/functions/purok_households/get.php?id=${encodeURIComponent(householdId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(res => res.json())
      .then(data => {
        if (!data.success || !data.household) {
          document.getElementById('fam_members_table_box').innerHTML = `
            <div style="text-align: center; padding: 24px 16px; font-size: 11px; color: var(--color-danger);">
              ${escapeHtml(data.message || 'Unable to load household details.')}
            </div>
          `;
          return;
        }

        const hh = data.household;
        const members = hh.members || [];

        document.getElementById('fam_members_badge').textContent = hh.household_code || 'HH';
        document.getElementById('fam_members_family_name').textContent = hh.family_name || 'Family';
        document.getElementById('fam_members_rep_info').innerHTML = `Representative: <strong>${escapeHtml(hh.head_full_name || '—')}</strong> ${hh.head_phone ? '• ' + escapeHtml(hh.head_phone) : ''}`;
        document.getElementById('fam_members_purok_code').textContent = `${hh.purok_name || '—'} (${hh.household_code || '—'})`;
        document.getElementById('fam_members_count_pill').textContent = `${members.length} Members`;

        if (members.length === 0) {
          document.getElementById('fam_members_table_box').innerHTML = `
            <div style="text-align: center; padding: 24px 16px; font-size: 11px; color: var(--color-text-muted);">
              No individual family members recorded for this household.
            </div>
          `;
          return;
        }

        let mHtml = `
          <table class="data-table" style="font-size: 11px; width: 100%; margin: 0; border-collapse: collapse;">
            <thead>
              <tr style="position: sticky; top: 0; background: var(--color-surface-subtle); z-index: 2; border-bottom: 1px solid var(--color-border);">
                <th style="padding: 6px 10px; text-align: center; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; width: 35px;">#</th>
                <th style="padding: 6px 10px; text-align: left; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px;">Member Name</th>
                <th style="padding: 6px 10px; text-align: left; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; width: 95px;">Relationship</th>
                <th style="padding: 6px 10px; text-align: center; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; width: 65px;">Gender</th>
                <th style="padding: 6px 10px; text-align: center; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; width: 55px;">Age</th>
                <th style="padding: 6px 10px; text-align: left; font-family: var(--font-secondary); font-weight: 600; color: var(--color-text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; width: 110px;">Classification</th>
              </tr>
            </thead>
            <tbody>
        `;

        members.forEach((m, idx) => {
          const isRep = (m.is_authorized_rep == 1 || m.is_authorized_rep === '1' || m.is_authorized_rep === true);
          const tags = [];
          if (m.is_senior == 1 || m.is_senior === '1') tags.push('Senior');
          if (m.is_minor == 1 || m.is_minor === '1') tags.push('Minor');
          if (m.is_pwd == 1 || m.is_pwd === '1') tags.push('PWD');

          const tagsHtml = tags.length > 0
            ? tags.map(t => `<span class="status-badge unclaimed" style="font-size: 8.5px; padding: 1px 4px; margin-right: 2px;">${t}</span>`).join('')
            : '<span style="font-size:9.5px;color:var(--color-text-muted);">Regular</span>';

          mHtml += `
            <tr style="border-bottom: 1px solid var(--color-border-light);">
              <td style="padding: 6px 10px; text-align: center; font-size: 10.5px; color: var(--color-text-secondary);">${idx + 1}</td>
              <td style="padding: 6px 10px;">
                <div style="font-weight: 600; color: var(--color-text); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                  <span>${escapeHtml(m.full_name || '—')}</span>
                  ${isRep ? '<span class="status-badge claimed" style="font-size: 8.5px; padding: 1px 4px;">Authorized Rep</span>' : ''}
                </div>
              </td>
              <td style="padding: 6px 10px; font-size: 10.5px; color: var(--color-text-secondary);">${escapeHtml(m.relationship || 'Member')}</td>
              <td style="padding: 6px 10px; text-align: center; font-size: 10.5px; color: var(--color-text-secondary);">${escapeHtml(m.gender || '—')}</td>
              <td style="padding: 6px 10px; text-align: center; font-size: 10.5px; color: var(--color-text);">${m.age !== null && m.age !== undefined ? m.age : '—'}</td>
              <td style="padding: 6px 10px;">${tagsHtml}</td>
            </tr>
          `;
        });

        mHtml += '</tbody></table>';
        document.getElementById('fam_members_table_box').innerHTML = mHtml;
      })
      .catch(err => {
        console.error(err);
        document.getElementById('fam_members_table_box').innerHTML = `
          <div style="text-align: center; padding: 24px 16px; font-size: 11px; color: var(--color-danger);">
            Failed to load family members due to network error.
          </div>
        `;
      });
  }

  function closeHouseholdMembersModal() {
    document.getElementById('householdMembersModal').classList.remove('show');
  }

  function openNotReceivedAidModal(id, code, program, budget) {
    document.getElementById('not_received_alloc_id').value = id;
    document.getElementById('not_received_alloc_code').textContent = code;
    document.getElementById('not_received_program_name').textContent = program;
    document.getElementById('not_received_budget').textContent = '₱' + budget;
    document.getElementById('notReceivedAidModal').classList.add('show');
  }

  function closeNotReceivedAidModal() {
    document.getElementById('notReceivedAidModal').classList.remove('show');
  }

  // Initialize on page load with selected allocation
  document.addEventListener('DOMContentLoaded', function () {
    if (selectedAllocationId) {
      selectAllocation(selectedAllocationId);
    }
  });

  // Close modals when clicking backdrop
  window.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
      e.target.classList.remove('show');
    }
  });
</script><?php require_once __DIR__ . '/../layouts/footer.php'; ?>