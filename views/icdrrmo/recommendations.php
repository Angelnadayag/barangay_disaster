<?php
// ============================================================================
// Views (ICDRRMO): Decision Tree Recommendations & Human Review Interface
// Decision-support decision tree transparency adhering to Anti-Slop Guidelines
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Decision Tree Recommender";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$recId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($recId > 0) {
  // -------------------------------------------------------------
  // DETAIL VIEW: Single Recommendation Review & Explainability
  // -------------------------------------------------------------
  $stmt = $db->prepare("
        SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.urgency,
               dr.affected_families, dr.affected_individuals, dr.displaced_families,
               dr.casualties_count, dr.injuries_count, dr.purok_name, dr.requested_assistance,
               dr.situation_overview, dr.created_at AS request_date,
               b.id AS barangay_id, b.name AS barangay_name, b.risk_level AS barangay_risk_level,
               u.full_name AS submitted_by_name,
               rev.full_name AS reviewer_name
        FROM recommendations r
        JOIN disaster_reports dr ON r.disaster_request_id = dr.id
        JOIN barangays b ON dr.barangay_id = b.id
        JOIN users u ON dr.submitted_by = u.id
        LEFT JOIN users rev ON r.reviewed_by = rev.id
        WHERE r.id = ?
    ");
  $stmt->execute([$recId]);
  $rec = $stmt->fetch();

  if (!$rec) {
    echo '<main class="main-content"><div class="empty-state"><div class="empty-state-title">Recommendation Not Found</div><p class="empty-state-desc">The requested recommendation could not be located.</p><a href="' . BASE_URL . '/views/icdrrmo/recommendations.php" class="btn btn-primary">Back to Recommendations</a></div></main>';
    require_once __DIR__ . '/../layouts/footer.php';
    exit;
  }

  // Fetch Recommended Items with live warehouse availability
  $itemsStmt = $db->prepare("
        SELECT ri.*, res.code, res.name AS resource_name, res.category, res.unit,
               res.total_quantity, res.available_quantity, res.min_threshold
        FROM recommended_items ri
        JOIN resources res ON ri.resource_id = res.id
        WHERE ri.recommendation_id = ?
    ");
  $itemsStmt->execute([$recId]);
  $items = $itemsStmt->fetchAll();

  // Fetch available Central Depot resources for optional addition by ICDRRMO
  $depotResStmt = $db->query("
        SELECT id, code, name, category, unit, total_quantity, available_quantity, min_threshold
        FROM resources
        WHERE barangay_id IS NULL AND (status = 'active' OR status IS NULL)
        ORDER BY category ASC, name ASC
    ");
  $depotResources = $depotResStmt->fetchAll();

  // Parse decision path branches into structured visual node objects
  $rawBranches = explode(" -> ", $rec['decision_path']);
  $parsedBranches = [];
  foreach ($rawBranches as $stepIdx => $rawBranch) {
    $stepNum = $stepIdx + 1;
    $raw = trim($rawBranch);
    $type = 'branch';
    $title = "Step $stepNum";
    $icon = '🔹';
    $val = $raw;
    $desc = '';
    $cardClass = 'branch-node';

    if (stripos($raw, 'Root [') === 0) {
      $type = 'root';
      $title = "1. Root Disaster";
      $icon = '📍';
      $cardClass = 'root-node';
      if (preg_match('/Root\s*\[(?:Disaster:\s*)?(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Initial incident classification";
    } elseif (stripos($raw, 'Severity') !== false) {
      $type = 'severity';
      $title = "$stepNum. Severity Branch";
      $icon = '⚠️';
      $cardClass = 'severity-node';
      if (preg_match('/\[Severity:\s*(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Hazard intensity evaluation";
    } elseif (stripos($raw, 'Affected:') !== false || stripos($raw, 'Population:') !== false) {
      $type = 'impact';
      $title = "$stepNum. Population Scale";
      $icon = '👨‍👩‍👧‍👦';
      $cardClass = 'impact-node';
      if (preg_match('/\[(?:Affected|Population):\s*(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Evacuee & displaced populace";
    } elseif (stripos($raw, 'Urgency') !== false) {
      $type = 'urgency';
      $title = "$stepNum. Response Urgency";
      $icon = '⚡';
      $cardClass = 'urgency-node';
      if (preg_match('/\[Urgency:\s*(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Dispatch response priority";
    } elseif (stripos($raw, 'Stock Feasibility') !== false) {
      $type = 'stock';
      $title = "$stepNum. Depot Feasibility";
      $icon = '📦';
      $cardClass = 'stock-node';
      if (preg_match('/Stock Feasibility\s*(?:Check\s*)?\[(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Central Depot inventory verification";
    } elseif (stripos($raw, 'Ayuda') !== false) {
      $type = 'ayuda';
      $title = "$stepNum. Cash Aid (Ayuda)";
      $icon = '💰';
      $cardClass = 'ayuda-node';
      if (preg_match('/Ayuda (?:Feasibility|Evaluation)\s*\[(.*?)\]/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "LGU calamity financial grant";
    } elseif (stripos($raw, 'Outcome:') !== false) {
      $type = 'outcome';
      $title = "$stepNum. Recommended Tier";
      $icon = '🎯';
      $cardClass = 'outcome-node';
      if (preg_match('/Outcome:\s*(.*)/i', $raw, $m)) {
        $val = $m[1];
      }
      $desc = "Standard intervention protocol";
    } else {
      if (preg_match('/\[(.*?)\]/', $raw, $m)) {
        $val = $m[1];
      }
    }

    $parsedBranches[] = [
      'type' => $type,
      'title' => $title,
      'icon' => $icon,
      'val' => $val,
      'desc' => $desc,
      'class' => $cardClass
    ];
  }
} else {
  // -------------------------------------------------------------
  // LIST VIEW: All Recommendations
  // -------------------------------------------------------------
  $filterStatus = $_GET['status'] ?? '';
  $filterPriority = $_GET['priority'] ?? '';

  $sql = "
        SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.affected_families,
               b.name AS barangay_name, rev.full_name AS reviewer_name,
               (SELECT COUNT(*) FROM recommended_items WHERE recommendation_id = r.id) AS total_items
        FROM recommendations r
        JOIN disaster_reports dr ON r.disaster_request_id = dr.id
        JOIN barangays b ON dr.barangay_id = b.id
        LEFT JOIN users rev ON r.reviewed_by = rev.id
        WHERE 1=1
    ";
  $params = [];

  if (!empty($filterStatus)) {
    $sql .= " AND r.status = ?";
    $params[] = $filterStatus;
  }

  if (!empty($filterPriority)) {
    $sql .= " AND r.priority = ?";
    $params[] = $filterPriority;
  }

  $sql .= " ORDER BY FIELD(r.status, 'Pending Review', 'Modified', 'Approved', 'Rejected'), r.created_at DESC";
  $listStmt = $db->prepare($sql);
  $listStmt->execute($params);
  $allRecommendations = $listStmt->fetchAll();
}
?>

<main class="main-content">
  <?php if ($recId > 0): ?>
    <!-- ========================================================================= -->
    <!-- RECOMMENDATION DETAIL & HUMAN REVIEW PAGE                                 -->
    <!-- ========================================================================= -->

    <div class="page-header">
      <div class="page-header-title-wrap">
        <h1>Recommendation #<?= clean($rec['recommendation_code']) ?></h1>
        <p class="page-header-desc">
          Recommendation for <?= clean($rec['disaster_type']) ?> assistance in
          <?= clean($rec['barangay_name']) ?> (<?= clean($rec['purok_name']) ?>).
        </p>
      </div>
      <div class="page-header-actions">
        <?= renderStatusBadge($rec['status']) ?>
        <?= renderStatusBadge($rec['priority']) ?>
        <?= renderConfidenceBadge($rec['confidence_score'], $rec['severity'], $rec['decision_path'], $rec['modality'] ?? null, 'badge-info', 'font-size:10px;padding:4px 9px;') ?>
        <button type="button" class="btn btn-outline btn-sm"
          style="font-size:10px;padding:3px 9px;display:inline-flex;align-items:center;gap:4px;" onclick="openDssExecutionModal(<?= htmlspecialchars(json_encode([
            'id' => $rec['disaster_request_id'],
            'tracking_code' => $rec['tracking_code'],
            'barangay_name' => $rec['barangay_name'],
            'purok_name' => $rec['purok_name'],
            'disaster_type' => $rec['disaster_type'],
            'severity' => $rec['severity'],
            'urgency' => $rec['urgency'],
            'affected_families' => $rec['affected_families'],
            'displaced_families' => $rec['displaced_families']
          ]), ENT_QUOTES, 'UTF-8') ?>, () => { window.dssReloadOnClose = true; })"
          title="Re-run recommendation engine with modality selection">
          ↻ Re-run Engine
        </button>
      </div>
    </div>

    <div style="margin-bottom:var(--space-4);">
      <!-- Panel 1: Input Data Parameters -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3 class="card-title">1. Input Disaster Data Parameters</h3>
        </div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:10px;">
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Disaster Type</div>
              <div style="font-weight:700;color:var(--color-primary);font-size:11px;"><?= clean($rec['disaster_type']) ?>
              </div>
            </div>
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Target Location</div>
              <div style="font-weight:700;color:var(--color-primary);font-size:11px;"><?= clean($rec['barangay_name']) ?>
                (<?= clean($rec['purok_name']) ?>)</div>
            </div>
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Assessed Severity</div>
              <div><?= renderStatusBadge($rec['severity']) ?></div>
            </div>
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Operational Urgency</div>
              <div><?= renderStatusBadge($rec['urgency']) ?></div>
            </div>
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Affected Families</div>
              <div style="font-family:var(--font-secondary);font-weight:700;font-size:13px;color:var(--color-primary);">
                <?= number_format($rec['affected_families']) ?> Families
                (<?= number_format($rec['affected_individuals']) ?> ind.)
              </div>
            </div>
            <div>
              <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Displaced in Camps</div>
              <div style="font-family:var(--font-secondary);font-weight:700;font-size:13px;color:var(--color-danger);">
                <?= number_format($rec['displaced_families']) ?> Families
              </div>
            </div>
          </div>

          <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--color-border-light);">
            <div
              style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;margin-bottom:2px;">
              Requested Assistance Narrative</div>
            <div
              style="font-size:10px;color:var(--color-text-secondary);background:var(--color-surface-subtle);padding:8px 10px;border-radius:6px;">
              <?= clean($rec['requested_assistance']) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Panel 3: Recommended Allocation Items & Inventory Verification -->
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
          <h3 class="card-title">2. Recommended Allocation Package & Warehouse Stock Feasibility</h3>
          <div class="card-subtitle">Verify quantities against Central Depot stock. ICDRRMO may adjust approved quantities
            prior to allocation.</div>
        </div>
        <?php if ($rec['status'] === 'Pending Review'): ?>
          <button type="button" class="btn btn-outline btn-sm" onclick="openAddResourceModal()"
            style="display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:11px;padding:6px 14px;border-color:var(--color-primary);color:var(--color-primary);">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
              stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span>Add Resource</span>
          </button>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Item SKU</th>
              <th>Resource Description</th>
              <th>Category</th>
              <th>Source / Type</th>
              <th>Quantity</th>
              <th>Recommended Qty</th>
              <th>Available Stock</th>
              <th>Stock Feasibility</th>
              <th>Approved / Adjusted Qty</th>
            </tr>
          </thead>
          <tbody id="recommendedItemsTbody">
            <?php if (empty($items)): ?>
              <tr id="emptyItemsRow">
                <td colspan="9" style="text-align:center;padding:24px;color:var(--color-text-secondary);">
                  <em>No physical relief supplies allocated for this recommendation (Emergency Financial Aid / Ayuda
                    modality selected). Central warehouse inventory is preserved. Click <strong>+ Add Resource</strong>
                    above to include depot supplies.</em>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $item): ?>
                <?php
                $isSufficient = ($item['available_quantity'] >= $item['recommended_quantity']);
                $approvedVal = $item['approved_quantity'] !== null ? $item['approved_quantity'] : $item['recommended_quantity'];
                $itemAddedType = $item['added_type'] ?? 'ai_recommended';
                ?>
                <tr id="rec-item-row-<?= $item['id'] ?>">
                  <td style="font-weight:600;font-family:var(--font-secondary);"><?= clean($item['code']) ?></td>
                  <td style="font-weight:600;color:var(--color-primary);"><?= clean($item['resource_name']) ?></td>
                  <td style="color:var(--color-text-secondary);"><?= clean($item['category']) ?></td>
                  <td>
                    <?php if ($itemAddedType === 'added_by_admin'): ?>
                      <span class="badge"
                        style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;font-size:9.5px;padding:2px 7px;font-weight:600;display:inline-flex;align-items:center;gap:3px;"
                        title="Manually added by ICDRRMO operations officer">
                        <span>👤 Added by Admin</span>
                      </span>
                    <?php else: ?>
                      <span class="badge"
                        style="background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0;font-size:9.5px;padding:2px 7px;font-weight:600;display:inline-flex;align-items:center;gap:3px;"
                        title="Generated by algorithmic decision tree matrix">
                        <span>🤖 AI Recommended</span>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="font-family:var(--font-secondary);font-weight:600;color:var(--color-text);">
                    <?= number_format($item['total_quantity'] ?? $item['available_quantity']) ?>       <?= clean($item['unit']) ?>
                  </td>
                  <td style="font-family:var(--font-secondary);font-weight:700;font-size:12px;color:var(--color-primary);">
                    <?= number_format($item['recommended_quantity']) ?>       <?= clean($item['unit']) ?>
                  </td>
                  <td style="font-family:var(--font-secondary);font-weight:600;">
                    <?= number_format($item['available_quantity']) ?>       <?= clean($item['unit']) ?>
                  </td>
                  <td>
                    <?php if ($isSufficient): ?>
                      <span class="badge badge-success">Sufficient Stock</span>
                    <?php else: ?>
                      <span class="badge badge-danger">Shortfall Deficit</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($rec['status'] === 'Pending Review'): ?>
                      <div style="display:flex;align-items:center;gap:6px;">
                        <input type="number" class="form-control item-qty-input" data-resource-id="<?= $item['resource_id'] ?>"
                          value="<?= $approvedVal ?>" min="0" max="<?= $item['available_quantity'] ?>"
                          style="width:85px;height:26px;font-family:var(--font-secondary);font-weight:600;">
                        <button type="button" class="btn btn-outline btn-sm"
                          onclick="removeRecommendedItem(<?= $item['id'] ?>, '<?= clean($item['resource_name']) ?>')"
                          title="Remove from allocation package"
                          style="padding:2px 7px;height:26px;line-height:1;color:var(--color-danger);border-color:#FCA5A5;font-size:13px;font-weight:700;">
                          &times;
                        </button>
                      </div>
                    <?php else: ?>
                      <span style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);">
                        <?= number_format($approvedVal) ?>         <?= clean($item['unit']) ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Panel 4: Emergency Financial Aid (Ayuda) DSS Recommendation & Calamity Budget -->
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
          <h3 class="card-title">3. Emergency Financial Aid (Ayuda) Recommendation & Calamity Cash Budget</h3>
          <div class="card-subtitle">Algorithmic cash assistance estimation based on DSWD Emergency Cash Transfer (ECT)
            and LGU Calamity Fund guidelines.</div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
          <?php if (($rec['financial_aid_added_type'] ?? 'ai_recommended') === 'added_by_admin'): ?>
            <span class="badge"
              style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;font-size:9.5px;font-weight:700;padding:4px 8px;">
              👤 Budget Added by Admin
            </span>
          <?php else: ?>
            <span class="badge"
              style="background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0;font-size:9.5px;font-weight:700;padding:4px 8px;">
              🤖 AI Recommended Budget
            </span>
          <?php endif; ?>
          <?php if ($rec['status'] === 'Pending Review' && (float) ($rec['financial_aid_recommended_budget'] ?? 0) > 0): ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="openAdjustAyudaBudgetModal()"
              style="font-size:10px;padding:3px 9px;display:inline-flex;align-items:center;gap:4px;border-color:var(--color-border);"
              title="Adjust or customize calamity cash grant budget">
              <span>Change Budget</span>
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ((float) ($rec['financial_aid_recommended_budget'] ?? 0) <= 0): ?>
          <div
            style="background:var(--color-surface-subtle);padding:16px 20px;border-radius:8px;border:1px dashed var(--color-border);text-align:center;color:var(--color-text-secondary);font-size:11px;">
            <em>Emergency Financial Aid (Ayuda) cash grant was deferred for this evaluation (Physical Relief Resources
              modality selected). You can re-run the engine with Dual Support or Ayuda Focus to compute cash grant
              allocations.</em>
          </div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;margin-bottom:14px;">
            <div
              style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:6px;border:1px solid var(--color-border-light);">
              <div
                style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;display:flex;justify-content:space-between;">
                <span>Calamity Budget</span>
                <span
                  style="font-size:8.5px;color:<?= ($rec['financial_aid_added_type'] ?? '') === 'added_by_admin' ? '#1D4ED8' : '#15803D' ?>;font-weight:600;">
                  <?= ($rec['financial_aid_added_type'] ?? '') === 'added_by_admin' ? 'Admin Modified' : 'AI Estimate' ?>
                </span>
              </div>
              <div
                style="font-size:16px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);margin-top:2px;">
                ₱<?= number_format((float) ($rec['financial_aid_recommended_budget'] ?? 0), 2) ?>
              </div>
            </div>
            <div
              style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:6px;border:1px solid var(--color-border-light);">
              <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Rate per
                Beneficiary Family</div>
              <div style="font-size:16px;font-weight:700;color:#047857;font-family:var(--font-secondary);margin-top:2px;">
                ₱<?= number_format((float) ($rec['financial_aid_per_family'] ?? 3000), 2) ?>
              </div>
            </div>
            <div
              style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:6px;border:1px solid var(--color-border-light);">
              <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Target
                Beneficiary Capacity</div>
              <div
                style="font-size:16px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);margin-top:2px;">
                <?= number_format((int) ($rec['financial_aid_target_families'] ?? $rec['affected_families'])) ?> Families
              </div>
            </div>
            <div
              style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:6px;border:1px solid var(--color-border-light);">
              <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Authorized
                Funding Source</div>
              <div style="font-size:12.5px;font-weight:600;color:var(--color-text);margin-top:4px;">
                <?= clean($rec['financial_aid_source'] ?? 'LGU Calamity Fund (QRF)') ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Panel 5: Human Review & Official Decision Action -->
    <div class="card review-deck-card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title" style="margin:0;">4. Human Review &amp; Administrative Validation</h3>
      </div>
      <div class="card-body">
        <?php if ($rec['status'] === 'Pending Review'): ?>
          <form id="reviewActionForm" onsubmit="event.preventDefault();">
            <input type="hidden" name="recommendation_id" value="<?= $rec['id'] ?>">

            <div class="form-group" style="margin-bottom:14px;">
              <label class="form-label" style="font-weight:600;font-size:11px;margin-bottom:4px;display:block;">
                Reviewer Remarks / Dispatch Instructions
              </label>
              <textarea name="remarks" id="reviewRemarks" class="form-control" rows="2" style="font-size:11.5px;"
                placeholder="Enter remarks or convoy dispatch instructions (optional)..."></textarea>
            </div>

            <!-- Direct Action Buttons: Approve or Reject -->
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;" id="reviewBtnGroup">
              <button type="button" class="btn btn-success" id="btnApproveRec" onclick="submitReviewAction('approve')"
                style="padding:8px 20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;background:#059669;border-color:#059669;color:#FFFFFF;box-shadow:0 1px 3px rgba(5,150,105,0.25);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <span>Approve Recommendation</span>
              </button>
              <button type="button" class="btn btn-outline" id="btnRejectRec" onclick="submitReviewAction('reject')"
                style="padding:8px 18px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;color:#DC2626;border-color:#FCA5A5;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
                <span>Reject Recommendation</span>
              </button>
            </div>
          </form>
        <?php else: ?>
          <!-- Already Reviewed -->
          <div
            style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;font-size:10.5px;background:#F8FAFC;padding:14px 16px;border-radius:8px;border:1px solid #E2E8F0;">
            <div>
              <div style="font-size:8.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">
                Validation Outcome</div>
              <div style="margin-top:4px;"><?= renderStatusBadge($rec['status']) ?></div>
            </div>
            <div>
              <div style="font-size:8.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">
                Validating Officer</div>
              <div style="font-weight:700;color:var(--color-primary);margin-top:4px;font-size:11.5px;">
                <?= clean($rec['reviewer_name'] ?: 'Engr. Armando Castillo') ?>
              </div>
            </div>
            <div>
              <div style="font-size:8.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">
                Validation Timestamp</div>
              <div style="font-family:var(--font-secondary);margin-top:4px;color:var(--color-text);">
                <?= formatDate($rec['reviewed_at']) ?>
              </div>
            </div>
            <div>
              <div style="font-size:8.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Official
                Audit Remarks</div>
              <div style="color:var(--color-text-secondary);margin-top:4px;line-height:1.35;">
                <?= clean($rec['review_remarks'] ?: 'Allocation verified and authorized.') ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <style>
      .depot-resource-row:hover {
        background-color: #F8FAFC !important;
      }

      .depot-resource-row.active {
        background-color: #F0FDF4 !important;
        box-shadow: inset 3px 0 0 #047857 !important;
      }
    </style>

    <!-- Modal: Add Resource to Allocation Package -->
    <div class="modal-overlay" id="addResourceModal" style="z-index: 1250 !important;">
      <div class="modal-dialog" style="max-width: 620px;">
        <div class="modal-header">
          <div>
            <h3 class="modal-title"
              style="margin:0;font-size:15px;color:var(--color-primary);display:flex;align-items:center;gap:6px;">
              <span>Add Depot Resource to Allocation</span>
            </h3>
            <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;">Select an available Central
              Depot resource and set quantity to include in this recommendation.</div>
          </div>
          <button type="button" class="modal-close-btn" onclick="closeModal('addResourceModal')">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px;max-height:calc(85vh - 120px);overflow-y:auto;">
          <form id="addResourceForm" onsubmit="event.preventDefault(); submitAddResource();">
            <input type="hidden" name="recommendation_id" value="<?= $rec['id'] ?>">
            <input type="hidden" id="selectedResourceId" name="resource_id" value="">

            <!-- Search & Filter Controls -->
            <div style="margin-bottom:10px;">
              <div style="display:flex;gap:8px;align-items:center;">
                <div style="position:relative;flex:1;">
                  <input type="text" id="resourceSearchInput" class="form-control"
                    placeholder="Search by resource name, SKU, or category..." oninput="filterDepotResources()"
                    style="font-size:11.5px;padding-left:28px;height:32px;">
                  <span
                    style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--color-text-muted);font-size:12px;pointer-events:none;">🔍</span>
                </div>
                <select id="resourceCategoryFilter" class="form-control" onchange="filterDepotResources()"
                  style="width:160px;font-size:11px;height:32px;">
                  <option value="">All Categories</option>
                  <?php
                  $cats = array_unique(array_filter(array_column($depotResources, 'category')));
                  sort($cats);
                  foreach ($cats as $cat): ?>
                    <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Scrollable Resource Selection List -->
            <div
              style="font-size:10px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase;margin-bottom:6px;display:flex;justify-content:space-between;">
              <span>Available Central Depot Resources</span>
              <span id="resourceCountBadge"
                style="color:var(--color-text-muted);font-family:var(--font-secondary);"><?= count($depotResources) ?>
                items</span>
            </div>

            <div id="depotResourceListContainer"
              style="border:1px solid var(--color-border);border-radius:6px;max-height:220px;overflow-y:auto;background:var(--color-surface);margin-bottom:12px;">
              <?php foreach ($depotResources as $dRes): ?>
                <?php
                $avail = (int) $dRes['available_quantity'];
                $isLow = ($avail <= (int) $dRes['min_threshold']);
                $badgeClass = $avail <= 0 ? 'badge-danger' : ($isLow ? 'badge-warning' : 'badge-success');
                $badgeText = $avail <= 0 ? 'Out of Stock' : number_format($avail) . ' ' . clean($dRes['unit']) . ' in stock';
                ?>
                <div class="depot-resource-row" onclick="selectResourceItem(this)" data-id="<?= $dRes['id'] ?>"
                  data-sku="<?= clean($dRes['code']) ?>" data-name="<?= clean($dRes['name']) ?>"
                  data-category="<?= clean($dRes['category']) ?>" data-unit="<?= clean($dRes['unit']) ?>"
                  data-available="<?= $avail ?>"
                  style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--color-border-light);cursor:pointer;transition:background 0.15s ease;">
                  <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
                    <input type="radio" name="resource_radio" value="<?= $dRes['id'] ?>"
                      style="pointer-events:none;margin:0;">
                    <div style="min-width:0;">
                      <div class="res-item-name"
                        style="font-size:11.5px;font-weight:600;color:var(--color-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= clean($dRes['name']) ?>
                      </div>
                      <div style="font-size:9.5px;color:var(--color-text-secondary);margin-top:2px;">
                        <span class="res-item-sku"
                          style="font-family:var(--font-secondary);font-weight:600;color:var(--color-text);"><?= clean($dRes['code']) ?></span>
                        &bull; <span class="res-item-cat"><?= clean($dRes['category']) ?></span>
                      </div>
                    </div>
                  </div>
                  <div style="margin-left:10px;flex-shrink:0;">
                    <span class="badge <?= $badgeClass ?>"
                      style="font-size:9.5px;font-family:var(--font-secondary);font-weight:600;">
                      <?= $badgeText ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
              <div id="noResourcesMatchMsg"
                style="display:none;padding:24px;text-align:center;color:var(--color-text-secondary);font-size:11px;">
                <em>No depot resources match your search criteria.</em>
              </div>
            </div>

            <!-- Selected Resource Confirmation & Quantity Input -->
            <div id="selectedResourceBox"
              style="display:none;background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:6px;padding:12px;margin-bottom:6px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                <div>
                  <span
                    style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">Selected
                    Item</span>
                  <div id="selectedResourceTitle"
                    style="font-size:12.5px;font-weight:700;color:var(--color-primary);margin-top:2px;"></div>
                  <div id="selectedResourceMeta" style="font-size:10px;color:var(--color-text-secondary);margin-top:2px;">
                  </div>
                </div>
                <span id="selectedResourceStockBadge" class="badge badge-success" style="font-size:9.5px;"></span>
              </div>

              <div class="form-group" style="margin-bottom:0;margin-top:10px;">
                <label class="form-label"
                  style="font-size:11px;font-weight:600;margin-bottom:4px;display:flex;justify-content:space-between;">
                  <span>Quantity to Allocate <span style="color:var(--color-danger);">*</span></span>
                  <span id="selectedResourceStockHint"
                    style="font-size:9.5px;color:var(--color-text-muted);font-weight:normal;"></span>
                </label>
                <div style="display:flex;align-items:center;gap:8px;">
                  <input type="number" id="modalResourceQty" name="quantity" class="form-control" min="1" step="1"
                    placeholder="Enter quantity" required
                    style="font-family:var(--font-secondary);font-size:13px;font-weight:600;flex:1;height:34px;">
                  <span id="modalResourceUnitSpan"
                    style="font-size:12px;color:var(--color-primary);font-weight:700;min-width:60px;">units</span>
                </div>
              </div>
            </div>

            <div id="unselectedResourcePrompt"
              style="padding:10px;background:#F8FAFC;border:1px dashed var(--color-border);border-radius:6px;text-align:center;font-size:11px;color:var(--color-text-secondary);">
              👆 <em>Click any resource from the list above to choose it and enter the quantity.</em>
            </div>
          </form>
        </div>

        <div class="modal-footer"
          style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid var(--color-border);">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('addResourceModal')">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm" id="modalAddResourceSubmitBtn"
            onclick="submitAddResource()" disabled style="display:inline-flex;align-items:center;gap:6px;">
            <span>Add to Allocation Table</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: Adjust Emergency Financial Aid (Ayuda) Budget -->
    <div class="modal-overlay" id="adjustAyudaBudgetModal" style="z-index: 1250 !important;">
      <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
          <div>
            <h3 class="modal-title"
              style="margin:0;font-size:15px;color:var(--color-primary);display:flex;align-items:center;gap:6px;">
              <span>Adjust Financial Aid (Ayuda) Budget</span>
            </h3>
            <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;">
              Modifying the budget will set the funding source type to <strong>Added by Admin</strong>.
            </div>
          </div>
          <button type="button" class="modal-close-btn" onclick="closeModal('adjustAyudaBudgetModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding:16px;">
          <form id="adjustAyudaBudgetForm" onsubmit="event.preventDefault(); submitAdjustAyudaBudget();">
            <input type="hidden" name="recommendation_id" value="<?= $rec['id'] ?>">

            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" style="font-size:11px;font-weight:600;margin-bottom:4px;display:block;">
                Total Calamity Budget (PHP) <span style="color:var(--color-danger);">*</span>
              </label>
              <input type="number" step="0.01" min="1" id="ayudaModalBudget" name="total_budget" class="form-control"
                value="<?= (float) ($rec['financial_aid_recommended_budget'] ?? 0) ?>" required
                style="font-family:var(--font-secondary);font-size:13px;font-weight:600;height:34px;"
                oninput="updateAyudaModalCapacity()">
            </div>

            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" style="font-size:11px;font-weight:600;margin-bottom:4px;display:block;">
                Rate per Beneficiary Family (PHP)
              </label>
              <input type="number" step="0.01" min="1" id="ayudaModalPerFamily" name="per_family" class="form-control"
                value="<?= (float) ($rec['financial_aid_per_family'] ?? 3000) ?>"
                style="font-family:var(--font-secondary);font-size:12px;font-weight:600;height:32px;"
                oninput="updateAyudaModalCapacity()">
            </div>

            <!-- Capacity Preview -->
            <div
              style="background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:6px;padding:8px 12px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10.5px;color:var(--color-text-secondary);">Target Beneficiary Families:</span>
              <span id="ayudaModalTargetPreview"
                style="font-size:12px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">
                <?= number_format((int) ($rec['financial_aid_target_families'] ?? 0)) ?> Families
              </span>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="font-size:11px;font-weight:600;margin-bottom:4px;display:block;">
                Authorized Funding Source
              </label>
              <input type="text" id="ayudaModalFundingSource" name="funding_source" class="form-control"
                value="<?= clean($rec['financial_aid_source'] ?? 'LGU Calamity Fund (QRF)') ?>"
                placeholder="e.g. LGU Calamity Fund (QRF)" style="font-size:11px;height:32px;">
            </div>
          </form>
        </div>
        <div class="modal-footer"
          style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid var(--color-border);">
          <button type="button" class="btn btn-outline btn-sm"
            onclick="closeModal('adjustAyudaBudgetModal')">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm" id="btnSaveAyudaBudget" onclick="submitAdjustAyudaBudget()"
            style="display:inline-flex;align-items:center;gap:6px;">
            <span>Save &amp; Update Budget</span>
          </button>
        </div>
      </div>
    </div>

    <script>
      function openAddResourceModal() {
        const form = document.getElementById('addResourceForm');
        if (form) form.reset();

        document.getElementById('selectedResourceId').value = '';
        document.getElementById('selectedResourceBox').style.display = 'none';
        document.getElementById('unselectedResourcePrompt').style.display = 'block';

        const submitBtn = document.getElementById('modalAddResourceSubmitBtn');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Add to Allocation Table';
        }

        // Clear selection highlight
        document.querySelectorAll('.depot-resource-row').forEach(row => {
          row.classList.remove('active');
          const radio = row.querySelector('input[type="radio"]');
          if (radio) radio.checked = false;
        });

        filterDepotResources();
        openModal('addResourceModal');
      }

      function filterDepotResources() {
        const search = (document.getElementById('resourceSearchInput')?.value || '').toLowerCase().trim();
        const cat = (document.getElementById('resourceCategoryFilter')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.depot-resource-row');
        let matchCount = 0;

        rows.forEach(row => {
          const name = (row.getAttribute('data-name') || '').toLowerCase();
          const sku = (row.getAttribute('data-sku') || '').toLowerCase();
          const category = (row.getAttribute('data-category') || '').toLowerCase();

          const matchesSearch = !search || name.includes(search) || sku.includes(search) || category.includes(search);
          const matchesCat = !cat || category === cat;

          if (matchesSearch && matchesCat) {
            row.style.display = 'flex';
            matchCount++;
          } else {
            row.style.display = 'none';
          }
        });

        const countBadge = document.getElementById('resourceCountBadge');
        if (countBadge) countBadge.textContent = `${matchCount} items`;

        const emptyMsg = document.getElementById('noResourcesMatchMsg');
        if (emptyMsg) emptyMsg.style.display = matchCount === 0 ? 'block' : 'none';
      }

      function selectResourceItem(rowEl) {
        // Clear previous active rows
        document.querySelectorAll('.depot-resource-row').forEach(r => {
          r.classList.remove('active');
          const radio = r.querySelector('input[type="radio"]');
          if (radio) radio.checked = false;
        });

        // Highlight clicked row
        rowEl.classList.add('active');
        const radio = rowEl.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;

        const id = rowEl.getAttribute('data-id');
        const name = rowEl.getAttribute('data-name');
        const sku = rowEl.getAttribute('data-sku');
        const category = rowEl.getAttribute('data-category');
        const available = parseInt(rowEl.getAttribute('data-available') || '0', 10);
        const unit = rowEl.getAttribute('data-unit') || 'units';

        document.getElementById('selectedResourceId').value = id;
        document.getElementById('selectedResourceTitle').textContent = `${name} (${sku})`;
        document.getElementById('selectedResourceMeta').textContent = category;

        const stockBadge = document.getElementById('selectedResourceStockBadge');
        if (stockBadge) {
          if (available <= 0) {
            stockBadge.className = 'badge badge-danger';
            stockBadge.textContent = 'Out of Stock';
          } else if (available < 20) {
            stockBadge.className = 'badge badge-warning';
            stockBadge.textContent = `${available.toLocaleString()} ${unit} (Low Stock)`;
          } else {
            stockBadge.className = 'badge badge-success';
            stockBadge.textContent = `${available.toLocaleString()} ${unit} Available`;
          }
        }

        const hint = document.getElementById('selectedResourceStockHint');
        if (hint) {
          hint.textContent = `Depot stock: ${available.toLocaleString()} ${unit}`;
        }

        const unitSpan = document.getElementById('modalResourceUnitSpan');
        if (unitSpan) unitSpan.textContent = unit;

        const qtyInput = document.getElementById('modalResourceQty');
        if (qtyInput) {
          qtyInput.value = available > 0 ? Math.min(50, available) : 10;
          qtyInput.focus();
        }

        document.getElementById('unselectedResourcePrompt').style.display = 'none';
        document.getElementById('selectedResourceBox').style.display = 'block';

        const submitBtn = document.getElementById('modalAddResourceSubmitBtn');
        if (submitBtn) {
          submitBtn.disabled = false;
        }
      }

      async function submitAddResource() {
        const resourceId = parseInt(document.getElementById('selectedResourceId')?.value, 10);
        const qtyInput = document.getElementById('modalResourceQty');
        const submitBtn = document.getElementById('modalAddResourceSubmitBtn');

        const qty = parseInt(qtyInput?.value, 10);

        if (!resourceId || isNaN(resourceId)) {
          showToast('Please click on a resource item from the list to select it.', 'danger');
          return;
        }
        if (!qty || isNaN(qty) || qty <= 0) {
          showToast('Please enter a valid quantity greater than zero.', 'danger');
          return;
        }

        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Adding to Package...';
        }

        try {
          const formData = new FormData();
          formData.append('is_ajax', '1');
          formData.append('recommendation_id', <?= $rec['id'] ?>);
          formData.append('resource_id', resourceId);
          formData.append('quantity', qty);

          const res = await fetch(`${BASE_URL}/backend/functions/recommender/add_item.php`, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: formData
          });
          const data = await res.json();

          if (data.success) {
            showToast(data.message, 'success');
            closeModal('addResourceModal');
            setTimeout(() => {
              window.location.reload();
            }, 600);
          } else {
            showToast(data.message || 'Failed to add resource.', 'danger');
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Add to Allocation Table';
            }
          }
        } catch (err) {
          console.error('Error adding resource:', err);
          showToast('Network error while adding resource.', 'danger');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Add to Allocation Table';
          }
        }
      }

      async function removeRecommendedItem(itemId, itemName) {
        if (!confirm(`Are you sure you want to remove "${itemName}" from this allocation package?`)) {
          return;
        }

        try {
          const formData = new FormData();
          formData.append('is_ajax', '1');
          formData.append('recommendation_id', <?= $rec['id'] ?>);
          formData.append('item_id', itemId);

          const res = await fetch(`${BASE_URL}/backend/functions/recommender/remove_item.php`, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: formData
          });
          const data = await res.json();

          if (data.success) {
            showToast(data.message, 'success');
            const row = document.getElementById(`rec-item-row-${itemId}`);
            if (row) row.remove();
            setTimeout(() => {
              window.location.reload();
            }, 600);
          } else {
            showToast(data.message || 'Failed to remove item.', 'danger');
          }
        } catch (err) {
          console.error('Error removing item:', err);
          showToast('Network error while removing item.', 'danger');
        }
      }
      function openAdjustAyudaBudgetModal() {
        openModal('adjustAyudaBudgetModal');
        updateAyudaModalCapacity();
      }

      function updateAyudaModalCapacity() {
        const budget = parseFloat(document.getElementById('ayudaModalBudget')?.value || 0);
        const rate = parseFloat(document.getElementById('ayudaModalPerFamily')?.value || 3000);
        const targetPreview = document.getElementById('ayudaModalTargetPreview');
        if (targetPreview) {
          const cap = (rate > 0 && budget > 0) ? Math.floor(budget / rate) : 0;
          targetPreview.textContent = `${cap.toLocaleString()} Families`;
        }
      }

      async function submitAdjustAyudaBudget() {
        const budget = parseFloat(document.getElementById('ayudaModalBudget')?.value || 0);
        const perFamily = parseFloat(document.getElementById('ayudaModalPerFamily')?.value || 0);
        const source = document.getElementById('ayudaModalFundingSource')?.value || '';
        const btn = document.getElementById('btnSaveAyudaBudget');

        if (budget <= 0) {
          showToast('Please enter a valid budget greater than zero.', 'danger');
          return;
        }

        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Updating...';
        }

        try {
          const formData = new FormData();
          formData.append('is_ajax', '1');
          formData.append('recommendation_id', <?= $rec['id'] ?>);
          formData.append('total_budget', budget);
          formData.append('per_family', perFamily);
          formData.append('funding_source', source);

          const res = await fetch(`${BASE_URL}/backend/functions/recommender/update_ayuda_budget.php`, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: formData
          });
          const data = await res.json();

          if (data.success) {
            showToast(data.message, 'success');
            closeModal('adjustAyudaBudgetModal');
            setTimeout(() => window.location.reload(), 600);
          } else {
            showToast(data.message || 'Failed to update budget.', 'danger');
            if (btn) {
              btn.disabled = false;
              btn.textContent = 'Save & Update Budget';
            }
          }
        } catch (err) {
          console.error('Error updating budget:', err);
          showToast('Network error while updating budget.', 'danger');
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save & Update Budget';
          }
        }
      }

      async function submitReviewAction(actionType) {
        const remarksInput = document.getElementById('reviewRemarks');
        const remarks = remarksInput ? remarksInput.value.trim() : '';
        const actionLabel = actionType === 'reject' ? 'Reject' : 'Approve';

        const executeSubmission = async () => {
          const formData = new FormData();
          formData.append('is_ajax', '1');
          formData.append('recommendation_id', <?= $rec['id'] ?>);
          formData.append('review_action', actionType);
          formData.append('remarks', remarks);

          // Gather approved / adjusted quantities from the table
          document.querySelectorAll('.item-qty-input').forEach(input => {
            const resId = input.getAttribute('data-resource-id');
            if (resId) {
              formData.append(`quantities[${resId}]`, input.value);
            }
          });

          const btnGroup = document.getElementById('reviewBtnGroup');
          if (btnGroup) {
            btnGroup.style.opacity = '0.5';
            btnGroup.style.pointerEvents = 'none';
          }

          try {
            const res = await fetch(`${BASE_URL}/backend/functions/recommender/review.php`, {
              method: 'POST',
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
              },
              body: formData
            });
            const data = await res.json();
            if (data.success) {
              showToast(data.message, 'success');
              setTimeout(() => window.location.reload(), 800);
            } else {
              showToast(data.message || 'Operation failed.', 'danger');
              if (btnGroup) {
                btnGroup.style.opacity = '1';
                btnGroup.style.pointerEvents = 'auto';
              }
            }
          } catch (err) {
            console.error('Review submission error:', err);
            showToast('Network error during review submission.', 'danger');
            if (btnGroup) {
              btnGroup.style.opacity = '1';
              btnGroup.style.pointerEvents = 'auto';
            }
          }
        };

        if (typeof showConfirmModal === 'function' && document.getElementById('confirmationModal')) {
          showConfirmModal(
            `Confirm ${actionLabel}`,
            `Are you sure you want to ${actionLabel.toLowerCase()} this recommendation for <?= clean($rec['barangay_name']) ?>?`,
            executeSubmission
          );
        } else if (confirm(`Are you sure you want to ${actionLabel.toLowerCase()} this recommendation for <?= clean($rec['barangay_name']) ?>?`)) {
          executeSubmission();
        }
      }
    </script>

  <?php else: ?>
    <!-- ========================================================================= -->
    <!-- RECOMMENDATIONS LIST VIEW                                                 -->
    <!-- ========================================================================= -->

    <div class="page-header">
      <div class="page-header-title-wrap">
        <h1>Decision Tree Recommendations Directory</h1>
        <p class="page-header-desc">
          Review generated disaster relief recommendations, confidence ratings, and official human review status.
        </p>
      </div>
    </div>

    <!-- Filters -->
    <div class="card" style="margin-bottom: var(--space-4);">
      <div class="card-body" style="padding:10px 14px;">
        <form method="GET" class="filter-bar" style="margin-bottom:0;">
          <div class="filter-group">
            <select name="status" class="form-control" style="width:140px;">
              <option value="">All Review Statuses</option>
              <option value="Pending Review" <?= $filterStatus === 'Pending Review' ? 'selected' : '' ?>>Pending Review
              </option>
              <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
              <option value="Modified" <?= $filterStatus === 'Modified' ? 'selected' : '' ?>>Modified</option>
              <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>

            <select name="priority" class="form-control" style="width:130px;">
              <option value="">All Priorities</option>
              <option value="Critical" <?= $filterPriority === 'Critical' ? 'selected' : '' ?>>Critical</option>
              <option value="High" <?= $filterPriority === 'High' ? 'selected' : '' ?>>High</option>
              <option value="Medium" <?= $filterPriority === 'Medium' ? 'selected' : '' ?>>Medium</option>
              <option value="Low" <?= $filterPriority === 'Low' ? 'selected' : '' ?>>Low</option>
            </select>

            <button type="submit" class="btn btn-outline">Apply Filter</button>
            <?php if (!empty($filterStatus) || !empty($filterPriority)): ?>
              <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-outline"
                style="color:var(--color-text-muted);">Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Code</th>
            <th>Request & Location</th>
            <th>Disaster Profile</th>
            <th>Priority</th>
            <th>Confidence</th>
            <th>Items Count</th>
            <th>Review Status</th>
            <th>Reviewer</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allRecommendations)): ?>
            <tr>
              <td colspan="9" style="text-align:center;padding:36px;">
                <div class="empty-state">
                  <div class="empty-state-title">No Recommendations Available</div>
                  <div class="empty-state-desc">No Decision Tree recommendations match your filter criteria.</div>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($allRecommendations as $recItem): ?>
              <tr>
                <td style="font-weight:600;font-family:var(--font-secondary);">
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $recItem['id'] ?>"
                    style="color:var(--color-primary);">
                    <?= clean($recItem['recommendation_code']) ?>
                  </a>
                </td>
                <td>
                  <div style="font-weight:600;color:var(--color-primary);"><?= clean($recItem['barangay_name']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($recItem['tracking_code']) ?>
                    (<?= $recItem['affected_families'] ?> families)</div>
                </td>
                <td>
                  <span style="font-weight:500;"><?= clean($recItem['disaster_type']) ?></span>
                  <span style="font-size:9px;color:var(--color-text-secondary);"> • <?= clean($recItem['severity']) ?></span>
                </td>
                <td><?= renderStatusBadge($recItem['priority']) ?></td>
                <td style="font-family:var(--font-secondary);font-weight:700;">
                  <?= renderConfidenceBadge($recItem['confidence_score'], $recItem['severity'], $recItem['decision_path'] ?? '', $recItem['modality'] ?? null, 'badge-info', 'font-size:10px;padding:3px 7px;', '') ?>
                </td>
                <td style="font-family:var(--font-secondary);">
                  <?= $recItem['total_items'] ?> resource item(s)
                </td>
                <td><?= renderStatusBadge($recItem['status']) ?></td>
                <td style="font-size:9px;color:var(--color-text-secondary);">
                  <?= clean($recItem['reviewer_name'] ?: 'Pending Review') ?>
                </td>
                <td>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $recItem['id'] ?>"
                    class="btn btn-outline btn-sm">
                    <?= $recItem['status'] === 'Pending Review' ? 'Review & Decide' : 'View Details' ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>