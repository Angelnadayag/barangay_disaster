<?php
// ============================================================================
// Views (ICDRRMO): Resource Inventory Interface
// Stock Monitoring and Adjustment (Damaged / Returned write-offs and Restock)
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Resource Inventory — ICDRRMO";
require_once __DIR__ . '/../layouts/header.php';

// Active tab
$activeTab = in_array($_GET['tab'] ?? '', ['inventory', 'transactions', 'allocations']) ? $_GET['tab'] : 'inventory';

// Filter parameters
$filterCategory = $_GET['category'] ?? '';
$filterStockLevel = $_GET['stock'] ?? '';
$search = trim($_GET['search'] ?? '');

// Summary Metrics
$totalItemsCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE status = 'active'")->fetchColumn();
$foodPacksAvailable = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND code LIKE 'RES-FOOD%'")->fetchColumn();
$medicalKitsAvailable = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active' AND code LIKE 'RES-MED%'")->fetchColumn();
$lowStockCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE status = 'active' AND available_quantity <= min_threshold")->fetchColumn();

// Fetch Active Resources for Inventory
$invSql = "SELECT * FROM resources WHERE status = 'active'";
$invParams = [];

if (!empty($filterCategory)) {
    $invSql .= " AND category = ?";
    $invParams[] = $filterCategory;
}

if ($filterStockLevel === 'low') {
    $invSql .= " AND available_quantity <= min_threshold";
}

if (!empty($search)) {
    $invSql .= " AND (code LIKE ? OR name LIKE ? OR storage_location LIKE ?)";
    $like = "%$search%";
    $invParams[] = $like;
    $invParams[] = $like;
    $invParams[] = $like;
}

$invSql .= " ORDER BY (available_quantity <= min_threshold) DESC, category ASC, name ASC";
$invStmt = $db->prepare($invSql);
$invStmt->execute($invParams);
$resources = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock Transactions for Tab 2
$trxStmt = $db->query("
    SELECT rt.*, res.code, res.name AS resource_name, res.unit, u.full_name AS performed_by_name
    FROM resource_transactions rt
    JOIN resources res ON rt.resource_id = res.id
    LEFT JOIN users u ON rt.performed_by = u.id
    ORDER BY rt.created_at DESC
    LIMIT 50
");
$transactions = $trxStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Allocations for Tab 3
$allocStmt = $db->query("
    SELECT ri.*, res.code, res.name AS resource_name, res.unit,
           r.recommendation_code, dr.tracking_code, b.name AS barangay_name, dr.disaster_type, r.reviewed_at
    FROM recommended_items ri
    JOIN resources res ON ri.resource_id = res.id
    JOIN recommendations r ON ri.recommendation_id = r.id
    JOIN disaster_requests dr ON r.disaster_request_id = dr.id
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE ri.status = 'Allocated'
    ORDER BY r.reviewed_at DESC
    LIMIT 50
");
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    'Relief Goods',
    'Medical Supplies',
    'Rescue Equipment',
    'Emergency Supplies',
    'Shelter & Sanitation'
];
?>

<style>
/* Robust Modal Styling for Resource Inventory */
#restockModal.modal-overlay,
#adjustModal.modal-overlay {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  background-color: rgba(15, 23, 42, 0.55) !important;
  backdrop-filter: blur(3px) !important;
  -webkit-backdrop-filter: blur(3px) !important;
  display: none;
  align-items: center !important;
  justify-content: center !important;
  z-index: 1050 !important;
  padding: 16px !important;
  overflow-y: auto !important;
  box-sizing: border-box !important;
}

#restockModal.modal-overlay.active,
#adjustModal.modal-overlay.active {
  display: flex !important;
}

#restockModal .modal-dialog,
#adjustModal .modal-dialog {
  max-width: 540px !important;
  width: 100% !important;
  margin: auto !important;
  display: flex !important;
  flex-direction: column !important;
  background-color: var(--color-surface, #FFFFFF) !important;
  border-radius: var(--radius-primary, 10px) !important;
  border: 1px solid var(--color-border, #CBD5E1) !important;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22) !important;
  overflow: hidden !important;
}

#restockModal .modal-header,
#adjustModal .modal-header {
  padding: 14px 20px !important;
  border-bottom: 1px solid var(--color-border, #E2E8F0) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  background: #FFFFFF !important;
}

#restockModal .modal-body,
#adjustModal .modal-body {
  padding: 18px 20px !important;
}

#restockModal .modal-footer,
#adjustModal .modal-footer {
  padding: 12px 20px !important;
  border-top: 1px solid var(--color-border, #E2E8F0) !important;
  background: #F8FAFC !important;
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Resource Inventory</h1>
    <p class="page-header-desc">
      Monitor warehouse stock availability, track logistical movements, and adjust commodity counts.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-outline" onclick="openRestockModal()" style="display:inline-flex;align-items:center;gap:6px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
      Restock Supplies
    </button>
  </div>
</div>

<!-- Stock Overview Metrics -->
<div class="metrics-grid">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Registered Resource SKUs</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($totalItemsCount) ?></div>
    <div class="metric-meta">Across Active Logistics Categories</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Food Packs Available</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l10 7-10 7-10-7 10-7z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($foodPacksAvailable) ?></div>
    <div class="metric-meta">Prepositioned at Central Depot</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Medical & First Aid Kits</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($medicalKitsAvailable) ?></div>
    <div class="metric-meta">Medical Response Packs</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Low Stock Alerts</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
    </div>
    <div class="metric-value" style="color: <?= $lowStockCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($lowStockCount) ?>
    </div>
    <div class="metric-meta">Below Safety Threshold</div>
  </div>
</div>

<!-- Tab Navigation Segmented Bar -->
<div style="display:flex;gap:6px;margin-bottom:var(--space-4);border-bottom:1px solid var(--color-border);padding-bottom:var(--space-2);flex-wrap:wrap;">
  <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php?tab=inventory" class="btn <?= $activeTab === 'inventory' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    Warehouse Stock Inventory
  </a>
  <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php?tab=transactions" class="btn <?= $activeTab === 'transactions' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    Stock Movements Log (<?= count($transactions) ?>)
  </a>
  <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php?tab=allocations" class="btn <?= $activeTab === 'allocations' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    Allocated to Barangays (<?= count($allocations) ?>)
  </a>
</div>

<?php if ($activeTab === 'inventory'): ?>
  <!-- Inventory Filter Bar -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="inventory">
        <div class="search-input-wrap" style="flex:1;min-width:200px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="search" class="form-control" placeholder="Search SKU, name, or depot..." value="<?= clean($search) ?>">
        </div>

        <select name="category" class="form-control" style="width:160px;">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= clean($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= clean($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="stock" class="form-control" style="width:140px;">
          <option value="">All Stock Levels</option>
          <option value="low" <?= $filterStockLevel === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
        </select>

        <button type="submit" class="btn btn-outline">Apply Filter</button>
        <?php if (!empty($search) || !empty($filterCategory) || !empty($filterStockLevel)): ?>
          <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Resource Inventory Table (Stock Monitoring & Adjust only) -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>SKU Code</th>
          <th>Resource Name</th>
          <th>Category</th>
          <th>Condition</th>
          <th>Expiry Date</th>
          <th>Available</th>
          <th>In-Use / Dispatched</th>
          <th>Damaged</th>
          <th>Total Stock</th>
          <th>Min Threshold</th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resources)): ?>
          <tr>
            <td colspan="11" style="text-align:center;padding:36px;color:var(--color-text-muted);">
              No active resources match your filter criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($resources as $res): ?>
            <?php 
              $isLow = ($res['available_quantity'] <= $res['min_threshold']);
              $hasExpiry = !empty($res['expiry_date']);
              $isExpired = false;
              $isExpiringSoon = false;
              $diffDays = null;
              if ($hasExpiry) {
                  $today = new DateTime();
                  $exp = new DateTime($res['expiry_date']);
                  $diff = $today->diff($exp);
                  $diffDays = (int)$diff->format("%r%a");
                  if ($diffDays < 0) {
                      $isExpired = true;
                  } elseif ($diffDays <= 30) {
                      $isExpiringSoon = true;
                  }
              }

              $cond = $res['item_condition'] ?? 'New';
              $condBadgeClass = 'badge-neutral';
              if ($cond === 'New') $condBadgeClass = 'badge-success';
              elseif ($cond === 'Good') $condBadgeClass = 'badge-info';
              elseif ($cond === 'Fair') $condBadgeClass = 'badge-warning';
              elseif ($cond === 'Poor' || $cond === 'Expired') $condBadgeClass = 'badge-danger';
            ?>
            <tr style="<?= ($isLow || $isExpired) ? 'background-color: #FFFDF8;' : '' ?>">
              <td style="font-weight:600;font-family:var(--font-secondary);white-space:nowrap;">
                <?= clean($res['code']) ?>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);font-size:12px;"><?= clean($res['name']) ?></div>
              </td>
              <td><span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($res['category']) ?></span></td>
              <td><span class="badge <?= $condBadgeClass ?>" style="font-size:9.5px;"><?= clean($cond) ?></span></td>
              <td style="font-family:var(--font-secondary);font-size:10.5px;white-space:nowrap;">
                <?php if ($hasExpiry): ?>
                  <div><?= htmlspecialchars($res['expiry_date']) ?></div>
                  <?php if ($isExpired): ?>
                    <span class="badge badge-danger" style="font-size:8px;padding:1px 4px;margin-top:2px;display:inline-block;">Expired</span>
                  <?php elseif ($isExpiringSoon): ?>
                    <span class="badge badge-warning" style="font-size:8px;padding:1px 4px;margin-top:2px;display:inline-block;">Exp in <?= $diffDays ?>d</span>
                  <?php else: ?>
                    <span class="badge badge-success" style="font-size:8px;padding:1px 4px;margin-top:2px;display:inline-block;">Valid</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--color-text-muted);font-size:10px;">N/A (Non-perishable)</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-secondary);font-weight:700;font-size:12px;color: <?= $isLow ? 'var(--color-danger)' : 'var(--color-primary)' ?>;">
                <?= number_format($res['available_quantity']) ?> <?= clean($res['unit']) ?>
                <?php if ($isLow): ?>
                  <span class="badge badge-warning" style="display:block;margin-top:2px;font-size:8px;">Low Stock</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-secondary);color:var(--color-text-muted);"><?= number_format($res['in_use_quantity']) ?> <?= clean($res['unit']) ?></td>
              <td style="font-family:var(--font-secondary);color:var(--color-danger);"><?= number_format($res['damaged_quantity']) ?> <?= clean($res['unit']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:600;"><?= number_format($res['total_quantity']) ?> <?= clean($res['unit']) ?></td>
              <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-muted);"><?= number_format($res['min_threshold']) ?> <?= clean($res['unit']) ?></td>
              <td style="text-align:right;">
                <button class="btn btn-outline btn-sm" onclick="openAdjustModal(<?= htmlspecialchars(json_encode($res)) ?>)" title="Adjust Stock / Record Damaged">
                  Adjust
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($activeTab === 'transactions'): ?>
  <!-- Stock Movement Log Table -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Transaction Type</th>
          <th>Resource Item</th>
          <th>Quantity</th>
          <th>Remarks / Authorization</th>
          <th>Processed By</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;">No stock transactions logged yet.</td></tr>
        <?php else: ?>
          <?php foreach ($transactions as $t): ?>
            <tr>
              <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-muted);">
                <?= formatDate($t['created_at']) ?>
              </td>
              <td>
                <span class="badge <?= in_array($t['transaction_type'], ['Restock', 'Returned', 'New Product']) ? 'badge-success' : ($t['transaction_type'] === 'Allocation' ? 'badge-info' : 'badge-warning') ?>">
                  <?= clean($t['transaction_type']) ?>
                </span>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);"><?= clean($t['resource_name']) ?></div>
                <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($t['code']) ?></div>
              </td>
              <td style="font-family:var(--font-secondary);font-weight:700;">
                <?= number_format($t['quantity']) ?> <?= clean($t['unit']) ?>
              </td>
              <td style="font-size:10px;color:var(--color-text-secondary);max-width:320px;">
                <?= clean($t['remarks']) ?>
              </td>
              <td style="font-size:10px;color:var(--color-text-secondary);">
                <?= clean($t['performed_by_name'] ?: 'System Operation') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($activeTab === 'allocations'): ?>
  <!-- Allocations Table -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Disaster Request</th>
          <th>Target Barangay</th>
          <th>Disaster Event</th>
          <th>Allocated Resource</th>
          <th>Dispatched Quantity</th>
          <th>Recommendation Ref</th>
          <th>Approval Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($allocations)): ?>
          <tr><td colspan="7" style="text-align:center;padding:24px;">No resource allocations dispatched yet.</td></tr>
        <?php else: ?>
          <?php foreach ($allocations as $a): ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);">
                <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php?search=<?= urlencode($a['tracking_code']) ?>"><?= clean($a['tracking_code']) ?></a>
              </td>
              <td style="font-weight:600;color:var(--color-primary);"><?= clean($a['barangay_name']) ?></td>
              <td><?= clean($a['disaster_type']) ?></td>
              <td>
                <div style="font-weight:600;"><?= clean($a['resource_name']) ?></div>
                <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['code']) ?></div>
              </td>
              <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);">
                <?= number_format($a['allocated_quantity']) ?> <?= clean($a['unit']) ?>
              </td>
              <td style="font-family:var(--font-secondary);font-size:10px;">
                <?= clean($a['recommendation_code']) ?>
              </td>
              <td style="font-size:10px;color:var(--color-text-muted);">
                <?= formatDate($a['reviewed_at']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: Restock Supplies                                                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="restockModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Restock Warehouse Supplies</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('restockModal')">&times;</button>
    </div>
    <form id="restockForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/restock.php">
      <input type="hidden" name="action" value="restock">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Select Resource Item</label>
          <select name="resource_id" id="restock_resource_id" class="form-control" required>
            <?php foreach ($resources as $res): ?>
              <option value="<?= $res['id'] ?>">
                <?= clean($res['code']) ?> — <?= clean($res['name']) ?> (Current Available: <?= number_format($res['available_quantity']) ?> <?= clean($res['unit']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Restock Quantity to Add</label>
          <input type="number" name="quantity" class="form-control" min="1" value="100" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Delivery Reference / Remarks</label>
          <input type="text" name="remarks" class="form-control" placeholder="e.g. Delivery Batch #OCD-2026-X, DSWD replenishment" required>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('restockModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitRestock">Record Restock</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Adjust Stock / Record Damaged                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="adjustModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="adjustModalTitle">Adjust Stock / Record Damaged</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('adjustModal')">&times;</button>
    </div>
    <form id="adjustForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/adjust.php">
      <input type="hidden" name="action" value="adjust">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="resource_id" id="adjustResId">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <div style="font-size:12px;font-weight:700;color:var(--color-primary);" id="adjustResName"></div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Adjustment Type</label>
          <select name="transaction_type" class="form-control" required>
            <option value="New Product">New Product (Add to Available Stock +)</option>
            <option value="Damaged">Damaged / Expired Write-Off (Deduct from Available −)</option>
            <option value="Returned">Returned from Field (Return to Available +)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Quantity</label>
          <input type="number" name="quantity" class="form-control" min="1" value="5" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Official Remarks / Justification</label>
          <input type="text" name="remarks" class="form-control" placeholder="e.g. Water damage during deployment, or returned unused after drill" required>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('adjustModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitAdjust">Confirm Adjustment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('active');
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active');
    el.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function openRestockModal() {
  const form = document.getElementById('restockForm');
  if (form) form.reset();
  openModal('restockModal');
}

function openAdjustModal(res) {
  document.getElementById('adjustResId').value = res.id;
  document.getElementById('adjustResName').innerText = `${res.code} — ${res.name} (Available: ${res.available_quantity} ${res.unit})`;
  openModal('adjustModal');
}

// Close modals when clicking backdrop
document.addEventListener('DOMContentLoaded', () => {
  ['restockModal', 'adjustModal'].forEach(id => {
    const m = document.getElementById(id);
    if (m) {
      m.addEventListener('click', (e) => {
        if (e.target === m) {
          closeModal(id);
        }
      });
    }
  });
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
