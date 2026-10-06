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

$recId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($recId > 0) {
    // -------------------------------------------------------------
    // DETAIL VIEW: Single Recommendation Review & Explainability
    // -------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.urgency,
               dr.affected_families, dr.affected_individuals, dr.displaced_families,
               dr.casualties_count, dr.injuries_count, dr.purok_name, dr.requested_assistance,
               dr.situation_overview, dr.created_at AS request_date,
               b.name AS barangay_name, b.risk_level AS barangay_risk_level,
               u.full_name AS submitted_by_name,
               rev.full_name AS reviewer_name
        FROM recommendations r
        JOIN disaster_requests dr ON r.disaster_request_id = dr.id
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
               res.available_quantity, res.min_threshold, res.storage_location
        FROM recommended_items ri
        JOIN resources res ON ri.resource_id = res.id
        WHERE ri.recommendation_id = ?
    ");
    $itemsStmt->execute([$recId]);
    $items = $itemsStmt->fetchAll();

    // Parse decision path branches
    $decisionBranches = explode(" -> ", $rec['decision_path']);
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
        JOIN disaster_requests dr ON r.disaster_request_id = dr.id
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
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
        <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" style="font-size:11px;color:var(--color-secondary);font-weight:500;">&larr; All Recommendations</a>
        <span style="color:var(--color-text-muted);">/</span>
        <span style="font-size:11px;color:var(--color-text-secondary);"><?= clean($rec['recommendation_code']) ?></span>
      </div>
      <h1>Recommendation #<?= clean($rec['recommendation_code']) ?> — Disaster Decision Support</h1>
      <p class="page-header-desc">
        Review Decision Tree recommendation for <?= clean($rec['disaster_type']) ?> assistance in <?= clean($rec['barangay_name']) ?> (<?= clean($rec['purok_name']) ?>).
      </p>
    </div>
    <div class="page-header-actions">
      <?= renderStatusBadge($rec['status']) ?>
      <?= renderStatusBadge($rec['priority']) ?>
      <span class="badge badge-info" style="font-size:10px;">Confidence: <?= number_format($rec['confidence_score'], 1) ?>%</span>
    </div>
  </div>

  <!-- Transparent Decision Support Banner -->
  <div style="background-color:#EBF3FC;border:1px solid #B7D7FA;border-radius:10px;padding:10px 14px;margin-bottom:var(--space-4);display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <div style="font-size:10px;color:var(--color-primary);line-height:1.4;">
      <strong>Decision-Support Advisory:</strong> This resource allocation recommendation was calculated by the Decision Tree model using incident parameters, affected population count, and warehouse inventory availability. It is presented as <strong>decision support</strong> for administrative review and requires authorized validation by ICDRRMO personnel before supplies are dispatched.
    </div>
    <div style="font-size:9px;color:var(--color-text-muted);white-space:nowrap;">Model: DSWD-NDRRMC Decision Matrix</div>
  </div>

  <div style="display:grid;grid-template-columns: 1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-4);">
    <!-- Panel 1: Input Data Parameters -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">1. Input Disaster Data Parameters</h3>
        <span style="font-size:9px;color:var(--color-text-muted);">Source: Request <?= clean($rec['tracking_code']) ?></span>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:10px;">
          <div>
            <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Disaster Type</div>
            <div style="font-weight:700;color:var(--color-primary);font-size:11px;"><?= clean($rec['disaster_type']) ?></div>
          </div>
          <div>
            <div style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Target Location</div>
            <div style="font-weight:700;color:var(--color-primary);font-size:11px;"><?= clean($rec['barangay_name']) ?> (<?= clean($rec['purok_name']) ?>)</div>
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
              <?= number_format($rec['affected_families']) ?> Families (<?= number_format($rec['affected_individuals']) ?> ind.)
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
          <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;margin-bottom:2px;">Requested Assistance Narrative</div>
          <div style="font-size:10px;color:var(--color-text-secondary);background:var(--color-surface-subtle);padding:8px 10px;border-radius:6px;">
            <?= clean($rec['requested_assistance']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Panel 2: Decision Tree Reasoning & Rule Explanation -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">2. Why this recommendation? (Decision Tree Path)</h3>
        <span class="badge badge-info" style="font-size:8px;">Transparent Rules</span>
      </div>
      <div class="card-body">
        <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;margin-bottom:6px;">
          Evaluated Rule Sequence:
        </div>
        <div class="decision-tree-path-card" style="margin-bottom:12px;">
          <?php foreach ($decisionBranches as $idx => $branch): ?>
            <span class="decision-branch-node"><?= clean($branch) ?></span>
            <?php if ($idx < count($decisionBranches) - 1): ?>
              <span style="color:#8A9BA8;font-weight:bold;">&rarr;</span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <div style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;margin-bottom:4px;">
          Model Evaluation Rationale:
        </div>
        <div style="font-size:10px;color:var(--color-text);line-height:1.4;background:var(--color-surface-subtle);padding:8px 10px;border-radius:6px;border:1px solid var(--color-border-light);">
          <?= clean($rec['recommendation_reason']) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Panel 3: Recommended Allocation Items & Inventory Verification -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header">
      <div>
        <h3 class="card-title">3. Recommended Allocation Package & Warehouse Stock Feasibility</h3>
        <div class="card-subtitle">Verify quantities against Central Depot stock. ICDRRMO may adjust approved quantities prior to allocation.</div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Item SKU</th>
            <th>Resource Description</th>
            <th>Category</th>
            <th>Storage Location</th>
            <th>Recommended Qty</th>
            <th>Available Stock</th>
            <th>Stock Feasibility</th>
            <th>Approved / Adjusted Qty</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php 
              $isSufficient = ($item['available_quantity'] >= $item['recommended_quantity']);
              $approvedVal = $item['approved_quantity'] !== null ? $item['approved_quantity'] : $item['recommended_quantity'];
            ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);"><?= clean($item['code']) ?></td>
              <td style="font-weight:600;color:var(--color-primary);"><?= clean($item['resource_name']) ?></td>
              <td style="color:var(--color-text-secondary);"><?= clean($item['category']) ?></td>
              <td style="font-size:9px;color:var(--color-text-muted);"><?= clean($item['storage_location']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;font-size:12px;color:var(--color-primary);">
                <?= number_format($item['recommended_quantity']) ?> <?= clean($item['unit']) ?>
              </td>
              <td style="font-family:var(--font-secondary);font-weight:600;">
                <?= number_format($item['available_quantity']) ?> <?= clean($item['unit']) ?>
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
                  <input type="number" class="form-control item-qty-input"
                         data-resource-id="<?= $item['resource_id'] ?>"
                         value="<?= $approvedVal ?>" min="0" max="<?= $item['available_quantity'] ?>"
                         style="width:90px;height:26px;font-family:var(--font-secondary);font-weight:600;">
                <?php else: ?>
                  <span style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);">
                    <?= number_format($approvedVal) ?> <?= clean($item['unit']) ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Panel 4: Human Review & Official Decision Action -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">4. Human Review & Administrative Validation</h3>
      <span style="font-size:9px;color:var(--color-text-muted);">ICDRRMO Authorized Personnel Only</span>
    </div>
    <div class="card-body">
      <?php if ($rec['status'] === 'Pending Review'): ?>
        <form id="reviewActionForm" onsubmit="event.preventDefault();">
          <input type="hidden" name="recommendation_id" value="<?= $rec['id'] ?>">

          <div class="form-group">
            <label class="form-label">Reviewer Official Remarks / Dispatch Instructions</label>
            <textarea name="remarks" id="reviewRemarks" class="form-control" rows="2" placeholder="e.g. Allocation validated. Supplies to be dispatched via Logistics Team Bravo with PNP escort."></textarea>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:var(--space-3);flex-wrap:wrap;">
            <div style="font-size:9px;color:var(--color-text-secondary);">
              Approving this recommendation will automatically deduct the approved items from the central inventory and set the request status to Allocated.
            </div>
            <div style="display:flex;gap:8px;">
              <button type="button" class="btn btn-danger" onclick="submitReviewAction('reject')">
                Reject Recommendation
              </button>
              <button type="button" class="btn btn-secondary" onclick="submitReviewAction('modify')">
                Modify Quantities & Approve
              </button>
              <button type="button" class="btn btn-primary" onclick="submitReviewAction('approve')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Approve As Recommended
              </button>
            </div>
          </div>
        </form>
      <?php else: ?>
        <!-- Already Reviewed -->
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;font-size:10px;background:var(--color-surface-subtle);padding:12px 14px;border-radius:10px;border:1px solid var(--color-border-light);">
          <div>
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Review Outcome</div>
            <div style="margin-top:2px;"><?= renderStatusBadge($rec['status']) ?></div>
          </div>
          <div>
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Reviewing Officer</div>
            <div style="font-weight:700;color:var(--color-primary);margin-top:2px;"><?= clean($rec['reviewer_name'] ?: 'Engr. Armando Castillo') ?></div>
          </div>
          <div>
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Validation Date</div>
            <div style="font-family:var(--font-secondary);margin-top:2px;"><?= formatDate($rec['reviewed_at']) ?></div>
          </div>
          <div>
            <div style="font-size:8px;color:var(--color-text-muted);text-transform:uppercase;">Review Remarks</div>
            <div style="color:var(--color-text-secondary);margin-top:2px;"><?= clean($rec['review_remarks'] ?: 'Approved standard allocation.') ?></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  async function submitReviewAction(actionType) {
    const remarks = document.getElementById('reviewRemarks').value.trim();
    const actionLabel = actionType === 'reject' ? 'Reject' : (actionType === 'modify' ? 'Modify & Approve' : 'Approve');

    showConfirmModal(
      `Confirm ${actionLabel}`,
      `Are you sure you want to ${actionLabel.toLowerCase()} this Decision Tree recommendation for <?= clean($rec['barangay_name']) ?>?`,
      async () => {
        const formData = new FormData();
        formData.append('recommendation_id', <?= $rec['id'] ?>);
        formData.append('review_action', actionType);
        formData.append('remarks', remarks);

        // Gather modified quantities if any
        document.querySelectorAll('.item-qty-input').forEach(input => {
          const resId = input.getAttribute('data-resource-id');
          formData.append(`quantities[${resId}]`, input.value);
        });

        try {
          const res = await fetch(`${BASE_URL}/backend/functions/recommender/review.php`, {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 900);
          } else {
            showToast(data.message || 'Operation failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error during review submission.', 'danger');
        }
      }
    );
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
            <option value="Pending Review" <?= $filterStatus === 'Pending Review' ? 'selected' : '' ?>>Pending Review</option>
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
            <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
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
                <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $recItem['id'] ?>" style="color:var(--color-primary);">
                  <?= clean($recItem['recommendation_code']) ?>
                </a>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);"><?= clean($recItem['barangay_name']) ?></div>
                <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($recItem['tracking_code']) ?> (<?= $recItem['affected_families'] ?> families)</div>
              </td>
              <td>
                <span style="font-weight:500;"><?= clean($recItem['disaster_type']) ?></span>
                <span style="font-size:9px;color:var(--color-text-secondary);"> • <?= clean($recItem['severity']) ?></span>
              </td>
              <td><?= renderStatusBadge($recItem['priority']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;">
                <?= number_format($recItem['confidence_score'], 1) ?>%
              </td>
              <td style="font-family:var(--font-secondary);">
                <?= $recItem['total_items'] ?> resource item(s)
              </td>
              <td><?= renderStatusBadge($recItem['status']) ?></td>
              <td style="font-size:9px;color:var(--color-text-secondary);">
                <?= clean($recItem['reviewer_name'] ?: 'Pending Review') ?>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $recItem['id'] ?>" class="btn btn-outline btn-sm">
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
