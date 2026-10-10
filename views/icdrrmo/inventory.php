<?php
// ============================================================================
// Views (ICDRRMO): Central Warehouse Inventory
// Real-Time Stock Monitoring, In-Place Dynamic Restock, Adjustments, and Audit Logs
// Fully Dynamic — Real-time DOM updates via AJAX with zero page reloads
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Central Warehouse Inventory — ICDRRMO";
require_once __DIR__ . '/../layouts/header.php';

// Active tab
$activeTab = in_array($_GET['tab'] ?? '', ['catalog', 'movements', 'dispatched']) ? $_GET['tab'] : 'catalog';

// Filter parameters
$filterCategory = trim($_GET['category'] ?? '');
$filterStockLevel = trim($_GET['stock'] ?? '');
$search = trim($_GET['search'] ?? '');

// Metrics for Central Depot (items where barangay_id IS NULL)
$totalDepotStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE barangay_id IS NULL AND status = 'active'")->fetchColumn();
$totalSkus = (int)$db->query("SELECT COUNT(*) FROM resources WHERE barangay_id IS NULL AND status = 'active'")->fetchColumn();
$lowStockCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE barangay_id IS NULL AND status = 'active' AND available_quantity <= min_threshold")->fetchColumn();
$damagedStockCount = (int)$db->query("SELECT COALESCE(SUM(damaged_quantity), 0) FROM resources WHERE barangay_id IS NULL AND status = 'active'")->fetchColumn();
$inUseStockCount = (int)$db->query("SELECT COALESCE(SUM(in_use_quantity), 0) FROM resources WHERE barangay_id IS NULL AND status = 'active'")->fetchColumn();

// Fetch Central Depot Resources
$invSql = "SELECT * FROM resources WHERE barangay_id IS NULL AND status = 'active'";
$invParams = [];

if (!empty($filterCategory)) {
    $invSql .= " AND category = ?";
    $invParams[] = $filterCategory;
}

if ($filterStockLevel === 'low') {
    $invSql .= " AND available_quantity <= min_threshold";
} elseif ($filterStockLevel === 'out') {
    $invSql .= " AND available_quantity = 0";
} elseif ($filterStockLevel === 'damaged') {
    $invSql .= " AND damaged_quantity > 0";
}

if (!empty($search)) {
    $invSql .= " AND (code LIKE ? OR name LIKE ? OR storage_location LIKE ? OR supplier_donor LIKE ? OR brand LIKE ?)";
    $like = "%$search%";
    $invParams[] = $like;
    $invParams[] = $like;
    $invParams[] = $like;
    $invParams[] = $like;
    $invParams[] = $like;
}

$invSql .= " ORDER BY (available_quantity <= min_threshold) DESC, category ASC, name ASC";
$invStmt = $db->prepare($invSql);
$invStmt->execute($invParams);
$resources = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Resources for Dropdown selections in modals
$allCatalogStmt = $db->query("SELECT id, code, name, category, unit, available_quantity, total_quantity, min_threshold FROM resources WHERE barangay_id IS NULL AND status = 'active' ORDER BY name ASC");
$allCatalog = $allCatalogStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock Movements Log (Tab 2)
$trxStmt = $db->query("
    SELECT rt.*, res.code, res.name AS resource_name, res.unit, u.full_name AS performed_by_name
    FROM resource_transactions rt
    JOIN resources res ON rt.resource_id = res.id
    LEFT JOIN users u ON rt.performed_by = u.id
    WHERE res.barangay_id IS NULL
    ORDER BY rt.created_at DESC
    LIMIT 100
");
$transactions = $trxStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dispatched Allocations to Barangays (Tab 3)
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
    LIMIT 100
");
$dispatchedAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    'Relief Goods',
    'Medical Supplies',
    'Rescue Equipment',
    'Emergency Supplies',
    'Shelter & Sanitation'
];
?>

<style>
/* Inventory Specific Styles */
.inv-metric-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.inv-metric-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
.row-updated-highlight {
  animation: invHighlightFade 2.4s ease forwards !important;
}
@keyframes invHighlightFade {
  0% { background-color: rgba(34, 197, 94, 0.28) !important; }
  100% { background-color: transparent !important; }
}
.modal-tab-btn {
  padding: 8px 14px;
  font-size: 11.5px;
  font-weight: 600;
  border-radius: 6px;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
  color: var(--color-text-muted);
  transition: all 0.15s ease;
}
.modal-tab-btn.active {
  background: #FFFFFF;
  color: var(--color-primary);
  border-color: #CBD5E1;
  box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Central Warehouse Inventory</h1>
    <p class="page-header-desc">
      Real-time stock monitoring, instant dynamic restock, quarantine write-offs, and logistics audit trail.
    </p>
  </div>
  <div class="page-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
    <button type="button" class="btn btn-outline" onclick="openGlobalRestockModal()" style="display:inline-flex;align-items:center;gap:6px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
      + Restock Supplies
    </button>
    <button type="button" class="btn btn-primary" onclick="openCreateResourceModal()" style="display:inline-flex;align-items:center;gap:6px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      + Add New Resource
    </button>
  </div>
</div>

<!-- Stock Overview Metrics -->
<div class="metrics-grid">
  <div class="metric-card inv-metric-card">
    <div class="metric-header">
      <span class="metric-label">Central Depot Available Stock</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    </div>
    <div class="metric-value" id="kpiTotalStock" style="color:var(--color-success);"><?= number_format($totalDepotStock) ?></div>
    <div class="metric-meta">Ready Units Available for Deployment</div>
  </div>

  <div class="metric-card inv-metric-card">
    <div class="metric-header">
      <span class="metric-label">Registered Resource SKUs</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l10 7-10 7-10-7 10-7z"></path></svg>
    </div>
    <div class="metric-value" id="kpiTotalSkus"><?= number_format($totalSkus) ?></div>
    <div class="metric-meta">Active Catalog Commodities</div>
  </div>

  <div class="metric-card inv-metric-card">
    <div class="metric-header">
      <span class="metric-label">Safety Threshold Alerts</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
    </div>
    <div class="metric-value" id="kpiLowStock" style="color: <?= $lowStockCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($lowStockCount) ?>
    </div>
    <div class="metric-meta">Items Below Minimum Safe Buffer</div>
  </div>

  <div class="metric-card inv-metric-card">
    <div class="metric-header">
      <span class="metric-label">Damaged / Quarantine Stock</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
    </div>
    <div class="metric-value" id="kpiDamagedStock" style="color:var(--color-text-muted);"><?= number_format($damagedStockCount) ?></div>
    <div class="metric-meta">Written-Off / Under Inspection</div>
  </div>
</div>

<!-- Tab Navigation -->
<div style="display:flex;gap:8px;margin-bottom:var(--space-3);align-items:center;flex-wrap:wrap;">
  <a href="<?= BASE_URL ?>/views/icdrrmo/inventory.php?tab=catalog" class="btn btn-sm <?= $activeTab === 'catalog' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    Depot Stock Catalog (<?= count($resources) ?>)
  </a>
  <a href="<?= BASE_URL ?>/views/icdrrmo/inventory.php?tab=movements" class="btn btn-sm <?= $activeTab === 'movements' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;<?= $activeTab === 'movements' ? '' : 'color:var(--color-text-muted);' ?>">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
    Stock Movements Log (<?= count($transactions) ?>)
  </a>
  <a href="<?= BASE_URL ?>/views/icdrrmo/inventory.php?tab=dispatched" class="btn btn-sm <?= $activeTab === 'dispatched' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;<?= $activeTab === 'dispatched' ? '' : 'color:var(--color-text-muted);' ?>">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
    Dispatched Relief to Barangays (<?= count($dispatchedAllocations) ?>)
  </a>
</div>

<?php if ($activeTab === 'catalog'): ?>
  <!-- Instant Search & Filter Bar -->
  <div class="card" style="margin-bottom:var(--space-3);">
    <div class="card-body" style="padding:10px 14px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <div class="search-input-wrap" style="flex:1;min-width:220px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" id="invSearchInput" class="form-control" placeholder="Search SKU, item name, brand, or location in real-time..." oninput="filterInventoryTable()">
        </div>

        <select id="invCategoryFilter" class="form-control" style="width:160px;" onchange="filterInventoryTable()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <select id="invStockFilter" class="form-control" style="width:150px;" onchange="filterInventoryTable()">
          <option value="">All Stock Levels</option>
          <option value="low">Low Stock Only</option>
          <option value="out">Out of Stock</option>
          <option value="adequate">Adequate Stock</option>
          <option value="damaged">Has Damaged Stock</option>
        </select>

        <button type="button" class="btn btn-outline btn-sm" onclick="resetInventoryFilters()" style="color:var(--color-text-muted);">
          Reset Filters
        </button>
      </div>
    </div>
  </div>

  <!-- Central Warehouse Catalog Table -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="table-responsive">
      <table class="data-table" id="invCatalogTable">
        <thead>
          <tr>
            <th>SKU Code</th>
            <th>Resource Commodity</th>
            <th>Category</th>
            <th>Depot Stock Available</th>
            <th>In-Use / Dispatched</th>
            <th>Damaged</th>
            <th>Total Stock</th>
            <th>Condition</th>
            <th>Expiry Date</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody id="invTableBody">
          <?php if (empty($resources)): ?>
            <tr id="noInvRow"><td colspan="10" style="text-align:center;padding:32px;color:var(--color-text-muted);">No resources found in Central Depot inventory.</td></tr>
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
                    if ($diffDays < 0) $isExpired = true;
                    elseif ($diffDays <= 30) $isExpiringSoon = true;
                }
                $condColors = ['New' => 'badge-success', 'Good' => 'badge-info', 'Fair' => 'badge-warning', 'Poor' => 'badge-danger', 'Expired' => 'badge-danger'];
                $condBadge = $condColors[$res['item_condition']] ?? 'badge-neutral';
              ?>
              <tr id="inv-row-<?= $res['id'] ?>"
                  data-id="<?= $res['id'] ?>"
                  data-code="<?= clean($res['code']) ?>"
                  data-name="<?= clean($res['name']) ?>"
                  data-category="<?= clean($res['category']) ?>"
                  data-unit="<?= clean($res['unit']) ?>"
                  data-available="<?= (int)$res['available_quantity'] ?>"
                  data-in-use="<?= (int)$res['in_use_quantity'] ?>"
                  data-damaged="<?= (int)$res['damaged_quantity'] ?>"
                  data-total="<?= (int)$res['total_quantity'] ?>"
                  data-min="<?= (int)$res['min_threshold'] ?>"
                  data-location="<?= clean($res['storage_location']) ?>"
                  data-brand="<?= clean($res['brand'] ?: '') ?>"
                  data-supplier="<?= clean($res['supplier_donor'] ?: '') ?>"
                  data-condition="<?= clean($res['item_condition']) ?>"
                  data-expiry="<?= clean($res['expiry_date'] ?: '') ?>"
                  data-is-low="<?= $isLow ? '1' : '0' ?>"
                  style="<?= ($isLow || $isExpired) ? 'background-color: #FFFDF8;' : '' ?>">
                <td>
                  <span class="badge badge-neutral" style="font-family:var(--font-secondary);font-weight:700;font-size:10px;">
                    <?= clean($res['code']) ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight:700;color:var(--color-primary);font-size:12.5px;"><?= clean($res['name']) ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);">
                    <?= clean($res['brand'] ?: 'No Brand') ?> &bull; <?= clean($res['storage_location']) ?>
                  </div>
                </td>
                <td><span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($res['category']) ?></span></td>
                <td id="inv-cell-avail-<?= $res['id'] ?>">
                  <div style="font-family:var(--font-secondary);font-weight:800;font-size:13px;color:<?= $isLow ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                    <span class="val-avail"><?= number_format($res['available_quantity']) ?></span>
                    <span style="font-size:9px;font-weight:500;color:var(--color-text-muted);"><?= clean($res['unit']) ?></span>
                  </div>
                  <?php if ($isLow): ?>
                    <span class="badge badge-danger" style="font-size:8px;padding:1px 4px;">Low Stock (Min: <?= number_format($res['min_threshold']) ?>)</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-secondary);font-size:11.5px;color:var(--color-text-muted);" id="inv-cell-inuse-<?= $res['id'] ?>">
                  <span class="val-inuse"><?= number_format($res['in_use_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);font-size:11.5px;color:var(--color-danger);" id="inv-cell-damaged-<?= $res['id'] ?>">
                  <span class="val-damaged"><?= number_format($res['damaged_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:600;font-size:12px;" id="inv-cell-total-<?= $res['id'] ?>">
                  <span class="val-total"><?= number_format($res['total_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td><span class="badge <?= $condBadge ?>" style="font-size:9px;"><?= clean($res['item_condition']) ?></span></td>
                <td style="font-size:10.5px;font-family:var(--font-secondary);">
                  <?php if ($hasExpiry): ?>
                    <div><?= date('M d, Y', strtotime($res['expiry_date'])) ?></div>
                    <?php if ($isExpired): ?>
                      <span class="badge badge-danger" style="font-size:8px;padding:1px 4px;">Expired</span>
                    <?php elseif ($isExpiringSoon): ?>
                      <span class="badge badge-warning" style="font-size:8px;padding:1px 4px;">Exp in <?= $diffDays ?>d</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="color:var(--color-text-muted);font-size:10px;">N/A</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;" id="inv-cell-actions-<?= $res['id'] ?>">
                  <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick="openItemDetailModal(<?= htmlspecialchars(json_encode($res)) ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    View
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($activeTab === 'movements'): ?>
  <!-- Stock Movement Audit Log (Tab 2) -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="font-size:13px;">Stock Movements & Logistics Audit Trail</h3>
      <span class="badge badge-neutral" style="font-size:9.5px;">Last 100 Warehouse Transactions</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Type</th>
            <th>Resource Name & SKU</th>
            <th>Quantity</th>
            <th>Remarks / Source</th>
            <th>Performed By</th>
          </tr>
        </thead>
        <tbody id="movementsTableBody">
          <?php if (empty($transactions)): ?>
            <tr><td colspan="6" style="text-align:center;padding:28px;">No stock transactions recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($transactions as $t): ?>
              <?php
                $color = 'var(--color-primary)';
                if (in_array($t['transaction_type'], ['Restock', 'New Product', 'Returned'])) $color = 'var(--color-success)';
                elseif ($t['transaction_type'] === 'Allocation' || $t['transaction_type'] === 'Dispatched') $color = 'var(--color-secondary)';
                elseif ($t['transaction_type'] === 'Damaged') $color = 'var(--color-danger)';
              ?>
              <tr>
                <td style="font-size:10.5px;color:var(--color-text-muted);font-family:var(--font-secondary);">
                  <?= formatDate($t['created_at']) ?>
                </td>
                <td>
                  <span class="badge" style="background:<?= $color ?>15;color:<?= $color ?>;border:1px solid <?= $color ?>30;font-size:9px;">
                    <?= clean($t['transaction_type']) ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight:600;font-size:11.5px;"><?= clean($t['resource_name']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);font-family:var(--font-secondary);"><?= clean($t['code']) ?></div>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:700;color:<?= $color ?>;font-size:12px;">
                  <?= (in_array($t['transaction_type'], ['Restock', 'New Product', 'Returned']) ? '+' : '-') . number_format($t['quantity']) ?> <?= clean($t['unit']) ?>
                </td>
                <td style="font-size:11px;color:var(--color-text-secondary);max-width:240px;">
                  <?= clean($t['remarks'] ?: '—') ?>
                </td>
                <td style="font-size:10.5px;font-weight:500;">
                  <?= clean($t['performed_by_name'] ?: 'ICDRRMO Admin') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($activeTab === 'dispatched'): ?>
  <!-- Dispatched Allocations to Barangays (Tab 3) -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="font-size:13px;">Relief Supplies Dispatched to Barangays</h3>
      <span class="badge badge-neutral" style="font-size:9.5px;">Completed Incident Transfers</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Disaster Request</th>
            <th>Beneficiary Barangay</th>
            <th>Disaster Event</th>
            <th>Allocated Resource</th>
            <th>Dispatched Quantity</th>
            <th>Recommendation Ref</th>
            <th>Dispatch Approval Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($dispatchedAllocations)): ?>
            <tr><td colspan="7" style="text-align:center;padding:28px;">No resource allocations dispatched yet.</td></tr>
          <?php else: ?>
            <?php foreach ($dispatchedAllocations as $a): ?>
              <tr>
                <td style="font-weight:700;font-family:var(--font-secondary);font-size:11px;color:var(--color-primary);">
                  <?= clean($a['tracking_code']) ?>
                </td>
                <td style="font-weight:600;font-size:12px;">Brgy. <?= clean($a['barangay_name']) ?></td>
                <td><span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($a['disaster_type']) ?></span></td>
                <td>
                  <div style="font-weight:600;font-size:12px;"><?= clean($a['resource_name']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['code']) ?></div>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:800;color:var(--color-success);font-size:13px;">
                  <?= number_format($a['allocated_quantity']) ?> <?= clean($a['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);font-size:10.5px;">
                  <?= clean($a['recommendation_code']) ?>
                </td>
                <td style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);">
                  <?= formatDate($a['reviewed_at']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: Comprehensive Resource Item Details & In-Place Dynamic Operations  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="inventoryItemModal">
  <div class="modal-dialog" style="max-width:680px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-neutral" id="modalItemCode" style="font-family:var(--font-secondary);font-weight:700;"></span>
        <span id="modalItemCategory" class="badge"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('inventoryItemModal')">&times;</button>
    </div>

    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
      <!-- Title & Basic Specs -->
      <div>
        <h2 id="modalItemName" style="font-weight:800;font-size:18px;color:var(--color-primary);margin:0 0 4px 0;"></h2>
        <div id="modalItemSub" style="font-size:11px;color:var(--color-text-muted);"></div>
      </div>

      <!-- Live Stock Metric Cards -->
      <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;">
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:6px;padding:8px 10px;text-align:center;">
          <div style="font-size:9px;color:#166534;font-weight:700;text-transform:uppercase;">Available</div>
          <div id="modalStockAvail" style="font-size:16px;font-weight:800;color:#15803D;margin-top:2px;"></div>
        </div>
        <div style="background:#F8FAFC;border:1px solid var(--color-border-light);border-radius:6px;padding:8px 10px;text-align:center;">
          <div style="font-size:9px;color:var(--color-text-muted);font-weight:700;text-transform:uppercase;">In-Use / Dispatched</div>
          <div id="modalStockInUse" style="font-size:16px;font-weight:800;color:#0F172A;margin-top:2px;"></div>
        </div>
        <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;padding:8px 10px;text-align:center;">
          <div style="font-size:9px;color:#991B1B;font-weight:700;text-transform:uppercase;">Damaged</div>
          <div id="modalStockDamaged" style="font-size:16px;font-weight:800;color:#DC2626;margin-top:2px;"></div>
        </div>
        <div style="background:#F8FAFC;border:1px solid var(--color-border-light);border-radius:6px;padding:8px 10px;text-align:center;">
          <div style="font-size:9px;color:var(--color-text-muted);font-weight:700;text-transform:uppercase;">Total Warehouse</div>
          <div id="modalStockTotal" style="font-size:16px;font-weight:800;color:#0F172A;margin-top:2px;"></div>
        </div>
      </div>

      <!-- Segmented Action Navigation Tabs inside Modal -->
      <div style="display:flex;gap:4px;background:#F1F5F9;padding:3px;border-radius:8px;">
        <button type="button" class="modal-tab-btn active" id="tabBtnSpecs" onclick="switchModalTab('specs')">
          📋 Specifications
        </button>
        <button type="button" class="modal-tab-btn" id="tabBtnRestock" onclick="switchModalTab('restock')">
          📦 Restock Stock
        </button>
        <button type="button" class="modal-tab-btn" id="tabBtnAdjust" onclick="switchModalTab('adjust')">
          ⚖️ Adjust / Damage
        </button>
      </div>

      <!-- Tab Content 1: Specifications -->
      <div id="modalTabSpecs" style="display:block;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:11.5px;background:#F8FAFC;padding:12px;border-radius:6px;border:1px solid var(--color-border-light);">
          <div><strong>Storage Depot:</strong> <span id="specLocation"></span></div>
          <div><strong>Supplier / Donor:</strong> <span id="specSupplier"></span></div>
          <div><strong>Condition:</strong> <span id="specCondition"></span></div>
          <div><strong>Expiry Date:</strong> <span id="specExpiry"></span></div>
          <div><strong>Safety Threshold:</strong> <span id="specMinThreshold"></span></div>
          <div><strong>Unit of Measure:</strong> <span id="specUnit"></span></div>
        </div>
      </div>

      <!-- Tab Content 2: Dynamic Restock Form -->
      <div id="modalTabRestock" style="display:none;">
        <form id="itemRestockForm" onsubmit="event.preventDefault(); submitItemRestock();">
          <input type="hidden" id="itemRestockResId">
          <div style="background:#F0FDF4;border:1px solid #BBF7D0;padding:12px;border-radius:6px;display:flex;flex-direction:column;gap:10px;">
            <div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;">
              ⚡ Dynamic Warehouse Restock
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:2px;font-weight:700;">Restock Quantity to Add <span style="color:var(--color-danger)">*</span></label>
              <input type="number" id="itemRestockQty" class="form-control" min="1" value="50" required style="font-weight:700;font-size:14px;">
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;">
                Will be added immediately in real-time to both Available Stock and Total Stock.
              </div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:2px;">Delivery Batch / Donor Remarks <span style="color:var(--color-danger)">*</span></label>
              <input type="text" id="itemRestockRemarks" class="form-control" placeholder="e.g. Delivery Batch #OCD-2026-X, DSWD replenishment" required>
            </div>
            <div style="text-align:right;margin-top:4px;">
              <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitItemRestock" style="background:var(--color-success);border-color:var(--color-success);display:inline-flex;align-items:center;gap:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Confirm Restock
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Tab Content 3: Dynamic Stock Adjustment Form -->
      <div id="modalTabAdjust" style="display:none;">
        <form id="itemAdjustForm" onsubmit="event.preventDefault(); submitItemAdjustment();">
          <input type="hidden" id="itemAdjustResId">
          <div style="background:#FFFDF8;border:1px solid #FCD34D;padding:12px;border-radius:6px;display:flex;flex-direction:column;gap:10px;">
            <div style="font-size:11px;font-weight:700;color:#92400E;text-transform:uppercase;">
              ⚡ Dynamic Stock Adjustment & Quarantine
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:2px;font-weight:700;">Adjustment Reason / Type <span style="color:var(--color-danger)">*</span></label>
              <select id="itemAdjustType" class="form-control" required style="font-size:12px;">
                <option value="Damaged">Damaged / Expired Quarantine (Deducts from Available −)</option>
                <option value="Returned">Returned from Field Operations (Adds to Available +)</option>
                <option value="Dispatched">Dispatched to Emergency Response Unit (Deducts from Available −)</option>
                <option value="New Product">Physical Inventory Surplus / Count Adjustment (+)</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:2px;font-weight:700;">Quantity <span style="color:var(--color-danger)">*</span></label>
              <input type="number" id="itemAdjustQty" class="form-control" min="1" value="5" required style="font-weight:700;font-size:14px;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:2px;">Justification / Logistics Notes <span style="color:var(--color-danger)">*</span></label>
              <input type="text" id="itemAdjustRemarks" class="form-control" placeholder="e.g. Water damaged box during transport; or returned after training" required>
            </div>
            <div style="text-align:right;margin-top:4px;">
              <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitItemAdjust" style="display:inline-flex;align-items:center;gap:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Confirm Stock Adjustment
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('inventoryItemModal')">
        Close
      </button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Global Restock Supplies Modal                                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="globalRestockModal">
  <div class="modal-dialog" style="max-width:540px;">
    <div class="modal-header">
      <h3 class="modal-title">Restock Warehouse Supplies</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('globalRestockModal')">&times;</button>
    </div>
    <form id="globalRestockForm" onsubmit="event.preventDefault(); submitGlobalRestock();">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Select Commodity Item</label>
          <select id="globalRestockResId" class="form-control" required style="font-size:12px;">
            <?php foreach ($allCatalog as $c): ?>
              <option value="<?= $c['id'] ?>">
                <?= clean($c['code']) ?> — <?= clean($c['name']) ?> (Avail: <?= number_format($c['available_quantity']) ?> <?= clean($c['unit']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Restock Quantity to Add</label>
          <input type="number" id="globalRestockQty" class="form-control" min="1" value="100" required style="font-weight:700;font-size:14px;">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Shipment Batch / Donor Remarks</label>
          <input type="text" id="globalRestockRemarks" class="form-control" placeholder="e.g. DSWD Prepositioned Replenishment #2026-X" required>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('globalRestockModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitGlobalRestock" style="background:var(--color-success);border-color:var(--color-success);">
          Record Restock
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Add New Resource Item (Dynamic AJAX)                               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createResourceModal">
  <div class="modal-dialog" style="max-width:620px;">
    <div class="modal-header">
      <h3 class="modal-title">Register New Resource SKU</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('createResourceModal')">&times;</button>
    </div>
    <form id="createResourceForm" onsubmit="event.preventDefault(); submitCreateResource();">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">SKU Code</label>
            <input type="text" id="newResCode" class="form-control" placeholder="Auto-generated if empty">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Resource Category</label>
            <select id="newResCategory" class="form-control" required>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Resource / Commodity Name</label>
          <input type="text" id="newResName" class="form-control" placeholder="e.g. Standard Family Food Pack (6-Meal)" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Initial Available Quantity</label>
            <input type="number" id="newResQty" class="form-control" min="0" value="100" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Unit of Measure</label>
            <input type="text" id="newResUnit" class="form-control" placeholder="packs, boxes, sets" value="packs" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Safety Buffer Threshold</label>
            <input type="number" id="newResThreshold" class="form-control" min="1" value="25" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Storage Depot Rack</label>
            <input type="text" id="newResLocation" class="form-control" value="Central ICDRRMO Depot — Bay A">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Supplier / Donor</label>
            <input type="text" id="newResSupplier" class="form-control" placeholder="DSWD, OCD, LGU Procurement">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Physical Condition</label>
            <select id="newResCondition" class="form-control">
              <option value="New">New</option>
              <option value="Good">Good</option>
              <option value="Fair">Fair</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Expiry Date (If Perishable)</label>
            <input type="date" id="newResExpiry" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('createResourceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitCreateRes">
          Save Resource SKU
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let currentModalItem = null;

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

// Switch Tab inside Item Modal
function switchModalTab(tab) {
  ['specs', 'restock', 'adjust'].forEach(t => {
    const btn = document.getElementById(`tabBtn${t.charAt(0).toUpperCase() + t.slice(1)}`);
    const pane = document.getElementById(`modalTab${t.charAt(0).toUpperCase() + t.slice(1)}`);
    if (btn) btn.classList.toggle('active', t === tab);
    if (pane) pane.style.display = (t === tab) ? 'block' : 'none';
  });
}

// Open Item Details Modal
function openItemDetailModal(res) {
  currentModalItem = res;
  document.getElementById('modalItemCode').innerText = res.code;
  document.getElementById('modalItemCategory').innerText = res.category;
  document.getElementById('modalItemName').innerText = res.name;
  document.getElementById('modalItemSub').innerText = `${res.brand ? res.brand + ' • ' : ''}${res.storage_location}`;

  document.getElementById('modalStockAvail').innerText = `${parseInt(res.available_quantity).toLocaleString()} ${res.unit}`;
  document.getElementById('modalStockInUse').innerText = `${parseInt(res.in_use_quantity).toLocaleString()} ${res.unit}`;
  document.getElementById('modalStockDamaged').innerText = `${parseInt(res.damaged_quantity).toLocaleString()} ${res.unit}`;
  document.getElementById('modalStockTotal').innerText = `${parseInt(res.total_quantity).toLocaleString()} ${res.unit}`;

  document.getElementById('specLocation').innerText = res.storage_location || 'Central Depot';
  document.getElementById('specSupplier').innerText = res.supplier_donor || 'LGU Central';
  document.getElementById('specCondition').innerText = res.item_condition || 'Good';
  document.getElementById('specExpiry').innerText = res.expiry_date || 'N/A (Non-perishable)';
  document.getElementById('specMinThreshold').innerText = `${parseInt(res.min_threshold).toLocaleString()} ${res.unit}`;
  document.getElementById('specUnit').innerText = res.unit;

  document.getElementById('itemRestockResId').value = res.id;
  document.getElementById('itemRestockQty').value = 50;
  document.getElementById('itemRestockRemarks').value = '';

  document.getElementById('itemAdjustResId').value = res.id;
  document.getElementById('itemAdjustQty').value = 5;
  document.getElementById('itemAdjustRemarks').value = '';

  switchModalTab('specs');
  openModal('inventoryItemModal');
}

// Open Global Restock Modal
function openGlobalRestockModal() {
  const form = document.getElementById('globalRestockForm');
  if (form) form.reset();
  openModal('globalRestockModal');
}

// Open Create Resource Modal
function openCreateResourceModal() {
  const form = document.getElementById('createResourceForm');
  if (form) form.reset();
  openModal('createResourceModal');
}

// Submit Item-Specific Restock via AJAX
async function submitItemRestock() {
  const resId = document.getElementById('itemRestockResId').value;
  const qty = parseInt(document.getElementById('itemRestockQty').value) || 0;
  const remarks = document.getElementById('itemRestockRemarks').value.trim();
  const btn = document.getElementById('btnSubmitItemRestock');

  if (qty <= 0) return showToast('Please enter a valid restock quantity.', 'danger');
  if (!remarks) return showToast('Please provide delivery remarks.', 'danger');

  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = 'Restocking...';

  try {
    const formData = new FormData();
    formData.append('resource_id', resId);
    formData.append('quantity', qty);
    formData.append('remarks', remarks);

    const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/restock.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data.success) {
      showToast(data.message, 'success');
      updateInventoryRowInDOM(data.resource);
      if (data.metrics && data.metrics.total_stock !== undefined) {
        document.getElementById('kpiTotalStock').innerText = parseInt(data.metrics.total_stock).toLocaleString();
      }
      if (data.metrics && data.metrics.low_stock_count !== undefined) {
        document.getElementById('kpiLowStock').innerText = parseInt(data.metrics.low_stock_count).toLocaleString();
      }
      closeModal('inventoryItemModal');
    } else {
      showToast(data.message || 'Restock failed.', 'danger');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error during restock.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

// Submit Item-Specific Adjustment via AJAX
async function submitItemAdjustment() {
  const resId = document.getElementById('itemAdjustResId').value;
  const type = document.getElementById('itemAdjustType').value;
  const qty = parseInt(document.getElementById('itemAdjustQty').value) || 0;
  const remarks = document.getElementById('itemAdjustRemarks').value.trim();
  const btn = document.getElementById('btnSubmitItemAdjust');

  if (qty <= 0) return showToast('Please enter a valid quantity.', 'danger');
  if (!remarks) return showToast('Please provide justification remarks.', 'danger');

  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = 'Adjusting...';

  try {
    const formData = new FormData();
    formData.append('resource_id', resId);
    formData.append('transaction_type', type);
    formData.append('quantity', qty);
    formData.append('remarks', remarks);

    const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/adjust.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data.success) {
      showToast(data.message, 'success');
      updateInventoryRowInDOM(data.resource);
      if (data.metrics && data.metrics.total_stock !== undefined) {
        document.getElementById('kpiTotalStock').innerText = parseInt(data.metrics.total_stock).toLocaleString();
      }
      if (data.metrics && data.metrics.low_stock_count !== undefined) {
        document.getElementById('kpiLowStock').innerText = parseInt(data.metrics.low_stock_count).toLocaleString();
      }
      closeModal('inventoryItemModal');
    } else {
      showToast(data.message || 'Stock adjustment failed.', 'danger');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error during stock adjustment.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

// Submit Global Restock via AJAX
async function submitGlobalRestock() {
  const resId = document.getElementById('globalRestockResId').value;
  const qty = parseInt(document.getElementById('globalRestockQty').value) || 0;
  const remarks = document.getElementById('globalRestockRemarks').value.trim();
  const btn = document.getElementById('btnSubmitGlobalRestock');

  if (qty <= 0) return showToast('Please enter a valid restock quantity.', 'danger');
  if (!remarks) return showToast('Please enter remarks.', 'danger');

  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = 'Recording...';

  try {
    const formData = new FormData();
    formData.append('resource_id', resId);
    formData.append('quantity', qty);
    formData.append('remarks', remarks);

    const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/restock.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data.success) {
      showToast(data.message, 'success');
      updateInventoryRowInDOM(data.resource);
      if (data.metrics && data.metrics.total_stock !== undefined) {
        document.getElementById('kpiTotalStock').innerText = parseInt(data.metrics.total_stock).toLocaleString();
      }
      closeModal('globalRestockModal');
    } else {
      showToast(data.message || 'Restock failed.', 'danger');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error during global restock.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

// Submit New Resource SKU via AJAX
async function submitCreateResource() {
  const name = document.getElementById('newResName').value.trim();
  const category = document.getElementById('newResCategory').value;
  const code = document.getElementById('newResCode').value.trim();
  const qty = parseInt(document.getElementById('newResQty').value) || 0;
  const unit = document.getElementById('newResUnit').value.trim() || 'packs';
  const threshold = parseInt(document.getElementById('newResThreshold').value) || 25;
  const location = document.getElementById('newResLocation').value.trim();
  const supplier = document.getElementById('newResSupplier').value.trim();
  const condition = document.getElementById('newResCondition').value;
  const expiry = document.getElementById('newResExpiry').value;
  const btn = document.getElementById('btnSubmitCreateRes');

  if (!name) return showToast('Please enter a commodity name.', 'danger');

  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = 'Saving...';

  try {
    const formData = new FormData();
    formData.append('name', name);
    formData.append('category', category);
    formData.append('code', code);
    formData.append('available_quantity', qty);
    formData.append('unit', unit);
    formData.append('min_threshold', threshold);
    formData.append('storage_location', location);
    formData.append('supplier_donor', supplier);
    formData.append('item_condition', condition);
    if (expiry) formData.append('expiry_date', expiry);

    const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/create.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data.success) {
      showToast(data.message, 'success');
      closeModal('createResourceModal');
      // If currently on catalog tab, reload cleanly or prepend row
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(data.message || 'Failed to create resource.', 'danger');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error creating resource SKU.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

// In-Place Dynamic Row Updater
function updateInventoryRowInDOM(res) {
  const row = document.getElementById(`inv-row-${res.id}`);
  if (!row) return;

  row.classList.remove('row-updated-highlight');
  void row.offsetWidth; // trigger reflow
  row.classList.add('row-updated-highlight');

  // Update datasets
  row.dataset.available = res.available_quantity;
  row.dataset.inUse = res.in_use_quantity;
  row.dataset.damaged = res.damaged_quantity;
  row.dataset.total = res.total_quantity;

  const isLow = parseInt(res.available_quantity) <= parseInt(res.min_threshold);
  row.dataset.isLow = isLow ? '1' : '0';

  // Update table cells
  const availCell = document.getElementById(`inv-cell-avail-${res.id}`);
  if (availCell) {
    availCell.innerHTML = `
      <div style="font-family:var(--font-secondary);font-weight:800;font-size:13px;color:${isLow ? 'var(--color-danger)' : 'var(--color-success)'};">
        <span class="val-avail">${parseInt(res.available_quantity).toLocaleString()}</span>
        <span style="font-size:9px;font-weight:500;color:var(--color-text-muted);">${res.unit}</span>
      </div>
      ${isLow ? `<span class="badge badge-danger" style="font-size:8px;padding:1px 4px;">Low Stock (Min: ${parseInt(res.min_threshold).toLocaleString()})</span>` : ''}
    `;
  }

  const inUseCell = document.getElementById(`inv-cell-inuse-${res.id}`);
  if (inUseCell) {
    inUseCell.innerHTML = `<span class="val-inuse">${parseInt(res.in_use_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  const damagedCell = document.getElementById(`inv-cell-damaged-${res.id}`);
  if (damagedCell) {
    damagedCell.innerHTML = `<span class="val-damaged">${parseInt(res.damaged_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  const totalCell = document.getElementById(`inv-cell-total-${res.id}`);
  if (totalCell) {
    totalCell.innerHTML = `<span class="val-total">${parseInt(res.total_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  // Update action button with fresh data
  const actionsCell = document.getElementById(`inv-cell-actions-${res.id}`);
  if (actionsCell) {
    actionsCell.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick='openItemDetailModal(${JSON.stringify(res)})'>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        View
      </button>
    `;
  }
}

// Client-side instant filter for inventory catalog table
function filterInventoryTable() {
  const query = (document.getElementById('invSearchInput')?.value || '').toLowerCase().trim();
  const category = (document.getElementById('invCategoryFilter')?.value || '').toLowerCase();
  const stockFilter = document.getElementById('invStockFilter')?.value || '';

  const rows = document.querySelectorAll('#invTableBody tr');
  rows.forEach(row => {
    if (row.id === 'noInvRow') return;
    const code = (row.dataset.code || '').toLowerCase();
    const name = (row.dataset.name || '').toLowerCase();
    const cat = (row.dataset.category || '').toLowerCase();
    const brand = (row.dataset.brand || '').toLowerCase();
    const location = (row.dataset.location || '').toLowerCase();
    const avail = parseInt(row.dataset.available) || 0;
    const isLow = row.dataset.isLow === '1';
    const damaged = parseInt(row.dataset.damaged) || 0;

    let matchesSearch = (!query || code.includes(query) || name.includes(query) || brand.includes(query) || location.includes(query));
    let matchesCategory = (!category || cat === category);
    let matchesStock = true;

    if (stockFilter === 'low') matchesStock = isLow;
    else if (stockFilter === 'out') matchesStock = (avail === 0);
    else if (stockFilter === 'adequate') matchesStock = (!isLow && avail > 0);
    else if (stockFilter === 'damaged') matchesStock = (damaged > 0);

    row.style.display = (matchesSearch && matchesCategory && matchesStock) ? '' : 'none';
  });
}

function resetInventoryFilters() {
  if (document.getElementById('invSearchInput')) document.getElementById('invSearchInput').value = '';
  if (document.getElementById('invCategoryFilter')) document.getElementById('invCategoryFilter').value = '';
  if (document.getElementById('invStockFilter')) document.getElementById('invStockFilter').value = '';
  filterInventoryTable();
}

// Backdrop click closer
document.addEventListener('DOMContentLoaded', () => {
  ['inventoryItemModal', 'globalRestockModal', 'createResourceModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('click', (e) => {
        if (e.target === el) closeModal(id);
      });
    }
  });
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
