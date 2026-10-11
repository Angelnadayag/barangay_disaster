<?php
// ============================================================================
// Views (Barangay Head): Local Barangay Hall Inventory
// Real-Time Stock Monitoring, In-Place Dynamic Distribution, Restock, and Adjustments
// Scoped strictly to Barangay jurisdiction (data isolation guaranteed)
// Fully Dynamic — Real-time DOM updates via AJAX with zero page reloads
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    die("Barangay record not found.");
}

$pageTitle = "Local Stockroom Inventory — Brgy. " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';

// Fetch local puroks for distribution target dropdowns
$purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Active Tab
$activeTab = in_array($_GET['tab'] ?? '', ['catalog', 'movements']) ? $_GET['tab'] : 'catalog';

// Filter parameters
$search = trim($_GET['search'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterStockLevel = trim($_GET['stock'] ?? '');

// Metrics for Local Barangay Hall Stockroom
$totalLocalStock = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL)")->fetchColumn();
$foodPacksAvailable = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL) AND (category = 'Relief Goods' OR name LIKE '%Food%' OR name LIKE '%Water%')")->fetchColumn();
$medicalKitsAvailable = (int)$db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL) AND (category = 'Medical Supplies' OR name LIKE '%Medical%' OR name LIKE '%First Aid%')")->fetchColumn();
$localLowStockCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL) AND available_quantity <= min_threshold")->fetchColumn();

// Fetch Local Resources
$invSql = "SELECT * FROM resources WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)";
$invParams = [$barangayId];

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
$inventoryList = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Local Resources for Dropdown selections in modals
$localCatalogStmt = $db->prepare("SELECT id, code, name, category, unit, available_quantity, total_quantity, min_threshold FROM resources WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL) ORDER BY name ASC");
$localCatalogStmt->execute([$barangayId]);
$localCatalog = $localCatalogStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock Movements Log (Tab 2) for this Barangay
$trxStmt = $db->prepare("
    SELECT rt.*, res.code, res.name AS resource_name, res.unit, u.full_name AS performed_by_name
    FROM resource_transactions rt
    JOIN resources res ON rt.resource_id = res.id
    LEFT JOIN users u ON rt.performed_by = u.id
    WHERE res.barangay_id = ?
    ORDER BY rt.created_at DESC
    LIMIT 100
");
$trxStmt->execute([$barangayId]);
$transactions = $trxStmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    'Relief Goods',
    'Medical Supplies',
    'Rescue Equipment',
    'Emergency Supplies',
    'Shelter & Sanitation'
];
?>

<style>
/* Barangay Inventory Styling */
.brgy-inv-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.brgy-inv-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(23, 50, 77, 0.08);
}
.row-updated-highlight {
  animation: brgyHighlightFade 2.4s ease forwards !important;
}
@keyframes brgyHighlightFade {
  0% { background-color: rgba(46, 125, 50, 0.22) !important; }
  100% { background-color: transparent !important; }
}
.brgy-tab-btn {
  padding: 6px 14px;
  font-size: 11px;
  font-family: var(--font-primary);
  font-weight: 500;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
  color: var(--color-text-secondary);
  transition: all 0.15s ease;
}
.brgy-tab-btn:hover {
  color: var(--color-primary);
}
.brgy-tab-btn.active {
  background: var(--color-surface);
  color: var(--color-primary);
  font-weight: 600;
  border-color: var(--color-border);
  box-shadow: 0 1px 3px rgba(23, 50, 77, 0.08);
}

/* Modal layout and auto-scrollbar for inventory modal */
#barangayItemModal .modal-dialog {
  max-width: 680px;
  max-height: calc(100vh - 40px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

#barangayItemModal .modal-body {
  overflow-y: auto !important;
  flex: 1 1 auto;
  min-height: 0;
  padding: 16px 20px;
}

#barangayItemModal .modal-footer {
  flex-shrink: 0;
  padding: 12px 20px;
  background: var(--color-surface-subtle);
  border-top: 1px solid var(--color-border-light);
}

#barangayItemModal .modal-body::-webkit-scrollbar,
#brgyItemHistoryContainer::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
#barangayItemModal .modal-body::-webkit-scrollbar-track,
#brgyItemHistoryContainer::-webkit-scrollbar-track {
  background: var(--color-surface-subtle);
  border-radius: 4px;
}
#barangayItemModal .modal-body::-webkit-scrollbar-thumb,
#brgyItemHistoryContainer::-webkit-scrollbar-thumb {
  background: var(--color-border);
  border-radius: 4px;
}
#barangayItemModal .modal-body::-webkit-scrollbar-thumb:hover,
#brgyItemHistoryContainer::-webkit-scrollbar-thumb:hover {
  background: var(--color-text-muted);
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Local Stockroom Inventory</h1>
    <p class="page-header-desc">
      Prepositioned relief supplies, emergency equipment, and real-time relief distribution for Brgy. <strong><?= clean($barangay['name']) ?></strong>.
    </p>
  </div>
</div>

<!-- Stock Overview Metrics -->
<div class="metrics-grid">
  <div class="metric-card brgy-inv-card">
    <div class="metric-header">
      <span class="metric-label">Local Prepositioned Stock</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    </div>
    <div class="metric-value" id="kpiLocalStock" style="color:var(--color-success);"><?= number_format($totalLocalStock) ?></div>
    <div class="metric-meta">Ready Units Available in Barangay Hall</div>
  </div>

  <div class="metric-card brgy-inv-card">
    <div class="metric-header">
      <span class="metric-label">Relief Food & Water Packs</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l10 7-10 7-10-7 10-7z"></path></svg>
    </div>
    <div class="metric-value" id="kpiFoodStock"><?= number_format($foodPacksAvailable) ?></div>
    <div class="metric-meta">Family Food & Water Rations Ready</div>
  </div>

  <div class="metric-card brgy-inv-card">
    <div class="metric-header">
      <span class="metric-label">Medical & First Aid Supplies</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
    </div>
    <div class="metric-value" id="kpiMedStock"><?= number_format($medicalKitsAvailable) ?></div>
    <div class="metric-meta">First Response & Medical Kits</div>
  </div>

  <div class="metric-card brgy-inv-card">
    <div class="metric-header">
      <span class="metric-label">Low Stock Safety Alerts</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
    </div>
    <div class="metric-value" id="kpiLowStock" style="color: <?= $localLowStockCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($localLowStockCount) ?>
    </div>
    <div class="metric-meta">Items Below Buffer Level</div>
  </div>
</div>

<!-- Tab Navigation -->
<div style="display:flex;gap:8px;margin-bottom:var(--space-3);align-items:center;flex-wrap:wrap;">
  <a href="<?= BASE_URL ?>/views/barangay/inventory.php?tab=catalog" class="btn btn-sm <?= $activeTab === 'catalog' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    Barangay Stockroom Catalog (<?= count($inventoryList) ?>)
  </a>
  <a href="<?= BASE_URL ?>/views/barangay/inventory.php?tab=movements" class="btn btn-sm <?= $activeTab === 'movements' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;<?= $activeTab === 'movements' ? '' : 'color:var(--color-text-muted);' ?>">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
    Stock Inflows & Outflows Log (<?= count($transactions) ?>)
  </a>
</div>

<?php if ($activeTab === 'catalog'): ?>
  <!-- Instant Search & Filter Bar -->
  <div class="card" style="margin-bottom:var(--space-3);">
    <div class="card-body" style="padding:10px 14px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <div class="search-input-wrap" style="flex:1;min-width:220px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" id="brgySearchInput" class="form-control" placeholder="Search SKU, item name, storage room, or donor..." oninput="filterBarangayInventoryTable()">
        </div>

        <select id="brgyCategoryFilter" class="form-control" style="width:160px;" onchange="filterBarangayInventoryTable()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <select id="brgyStockFilter" class="form-control" style="width:150px;" onchange="filterBarangayInventoryTable()">
          <option value="">All Stock Levels</option>
          <option value="low">Low Stock Only</option>
          <option value="out">Out of Stock</option>
          <option value="adequate">Adequate Stock</option>
          <option value="damaged">Has Damaged Stock</option>
        </select>

        <button type="button" class="btn btn-outline btn-sm" onclick="resetBarangayFilters()" style="color:var(--color-text-muted);">
          Reset Filters
        </button>
      </div>
    </div>
  </div>

  <!-- Barangay Hall Stockroom Table -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="table-responsive">
      <table class="data-table" id="brgyInventoryTable">
        <thead>
          <tr>
            <th>SKU Code</th>
            <th>Resource Description</th>
            <th>Category</th>
            <th>Available Units</th>
            <th>In-Use / Disbursed</th>
            <th>Damaged</th>
            <th>Total Stock</th>
            <th>Storage Location</th>
            <th>Condition</th>
            <th>Expiry Date</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody id="brgyTableBody">
          <?php if (empty($inventoryList)): ?>
            <tr id="noBrgyInvRow"><td colspan="11" style="text-align:center;padding:32px;color:var(--color-text-muted);">No resources found in Barangay <?= clean($barangay['name']) ?> inventory.</td></tr>
          <?php else: ?>
            <?php foreach ($inventoryList as $res): ?>
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
              <tr id="brgy-row-<?= $res['id'] ?>"
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
                    <?= clean($res['brand'] ?: 'General') ?> &bull; <?= clean($res['supplier_donor'] ?: 'Local Inventory') ?>
                  </div>
                </td>
                <td><span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($res['category']) ?></span></td>
                <td id="brgy-cell-avail-<?= $res['id'] ?>">
                  <div style="font-family:var(--font-secondary);font-weight:800;font-size:13px;color:<?= $isLow ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                    <span class="val-avail"><?= number_format($res['available_quantity']) ?></span>
                    <span style="font-size:9px;font-weight:500;color:var(--color-text-muted);"><?= clean($res['unit']) ?></span>
                  </div>
                  <?php if ($isLow): ?>
                    <span class="badge badge-danger" style="font-size:8px;padding:1px 4px;">Low Stock (Min: <?= number_format($res['min_threshold']) ?>)</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-secondary);font-size:11.5px;color:var(--color-text-muted);" id="brgy-cell-inuse-<?= $res['id'] ?>">
                  <span class="val-inuse"><?= number_format($res['in_use_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);font-size:11.5px;color:var(--color-danger);" id="brgy-cell-damaged-<?= $res['id'] ?>">
                  <span class="val-damaged"><?= number_format($res['damaged_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:600;font-size:12px;" id="brgy-cell-total-<?= $res['id'] ?>">
                  <span class="val-total"><?= number_format($res['total_quantity']) ?></span> <?= clean($res['unit']) ?>
                </td>
                <td style="font-size:11px;color:var(--color-text-secondary);">
                  <?= clean($res['storage_location'] ?: 'Barangay Stockroom') ?>
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
                <td style="text-align:right;" id="brgy-cell-actions-<?= $res['id'] ?>">
                  <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;" onclick="openBarangayItemModal(<?= htmlspecialchars(json_encode($res)) ?>)">
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
  <!-- Stock Movement Log (Tab 2) -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="font-size:13px;">Stock Inflows & Outflows Log</h3>
      <span class="badge badge-neutral" style="font-size:9.5px;">Brgy. <?= clean($barangay['name']) ?> Audit Trail</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Type</th>
            <th>Resource Name & SKU</th>
            <th>Quantity</th>
            <th>Remarks / Target</th>
            <th>Logged By</th>
          </tr>
        </thead>
        <tbody id="brgyMovementsTableBody">
          <?php if (empty($transactions)): ?>
            <tr><td colspan="6" style="text-align:center;padding:28px;">No local stock transactions recorded.</td></tr>
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
                  <?= clean($t['performed_by_name'] ?: 'Barangay Staff') ?>
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
<!-- MODAL: Barangay Resource Item Details, Adjustment & History               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="barangayItemModal">
  <div class="modal-dialog" style="max-width:680px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="badge badge-neutral" id="brgyModalItemCode" style="font-family:var(--font-secondary);font-weight:700;font-size:10px;"></span>
        <span id="brgyModalItemCategory" class="badge badge-neutral" style="font-size:10px;"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('barangayItemModal')">&times;</button>
    </div>

    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
      <!-- Title & Basic Specs -->
      <div>
        <h2 id="brgyModalItemName" style="font-weight:700;font-size:16px;color:var(--color-primary);margin:0 0 4px 0;font-family:var(--font-primary);"></h2>
        <div id="brgyModalItemSub" style="font-size:11px;color:var(--color-text-secondary);font-family:var(--font-primary);"></div>
      </div>

      <!-- Live Stock Metric Cards -->
      <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;">
        <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm, 6px);padding:8px 10px;text-align:center;">
          <div style="font-size:9.5px;color:var(--color-text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Available</div>
          <div id="brgyStockAvail" style="font-size:16px;font-weight:700;color:var(--color-success);font-family:var(--font-secondary);margin-top:2px;"></div>
        </div>
        <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm, 6px);padding:8px 10px;text-align:center;">
          <div style="font-size:9.5px;color:var(--color-text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Disbursed / Field</div>
          <div id="brgyStockInUse" style="font-size:16px;font-weight:700;color:var(--color-text);font-family:var(--font-secondary);margin-top:2px;"></div>
        </div>
        <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm, 6px);padding:8px 10px;text-align:center;">
          <div style="font-size:9.5px;color:var(--color-text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Damaged</div>
          <div id="brgyStockDamaged" style="font-size:16px;font-weight:700;color:var(--color-danger);font-family:var(--font-secondary);margin-top:2px;"></div>
        </div>
        <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm, 6px);padding:8px 10px;text-align:center;">
          <div style="font-size:9.5px;color:var(--color-text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Total Stock</div>
          <div id="brgyStockTotal" style="font-size:16px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);margin-top:2px;"></div>
        </div>
      </div>

      <!-- Segmented Navigation inside Modal -->
      <div style="display:flex;gap:4px;background:var(--color-surface-subtle);padding:3px;border-radius:var(--radius-sm, 6px);border:1px solid var(--color-border-light);">
        <button type="button" class="brgy-tab-btn active" id="brgyTabBtnSpecs" onclick="switchBrgyModalTab('specs')">
          Specifications
        </button>
        <button type="button" class="brgy-tab-btn" id="brgyTabBtnAdjust" onclick="switchBrgyModalTab('adjust')">
          Inventory Adjustment
        </button>
        <button type="button" class="brgy-tab-btn" id="brgyTabBtnHistory" onclick="switchBrgyModalTab('history')">
          Inventory History
        </button>
      </div>

      <!-- Tab Content 1: Specifications -->
      <div id="brgyModalTabSpecs" style="display:block;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:11.5px;background:var(--color-surface-subtle);padding:12px;border-radius:var(--radius-sm, 6px);border:1px solid var(--color-border-light);color:var(--color-text);">
          <div><strong style="color:var(--color-text-secondary);">Storage Room / Shelf:</strong> <span id="brgySpecLocation"></span></div>
          <div><strong style="color:var(--color-text-secondary);">Source / Donor:</strong> <span id="brgySpecSupplier"></span></div>
          <div><strong style="color:var(--color-text-secondary);">Condition:</strong> <span id="brgySpecCondition"></span></div>
          <div><strong style="color:var(--color-text-secondary);">Expiry Date:</strong> <span id="brgySpecExpiry"></span></div>
          <div><strong style="color:var(--color-text-secondary);">Safety Threshold:</strong> <span id="brgySpecMinThreshold"></span></div>
          <div><strong style="color:var(--color-text-secondary);">Unit of Measure:</strong> <span id="brgySpecUnit"></span></div>
        </div>
      </div>

      <!-- Tab Content 2: Unified Inventory Adjustment Form -->
      <div id="brgyModalTabAdjust" style="display:none;">
        <form id="brgyUnifiedAdjustForm" onsubmit="event.preventDefault(); submitBrgyUnifiedAdjustment();">
          <input type="hidden" id="brgyUnifiedAdjustResId">
          <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);padding:14px;border-radius:var(--radius-sm, 6px);display:flex;flex-direction:column;gap:10px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="margin-bottom:3px;font-size:11px;font-weight:600;">Adjustment Action <span style="color:var(--color-danger)">*</span></label>
                <select id="brgyUnifiedAdjustType" class="form-control" required style="font-size:12px;" onchange="onBrgyAdjustTypeChange(this.value)">
                  <option value="Restock">Restock / Receive Supplies (+)</option>
                  <option value="Dispatched">Distribute to Purok / Field (-)</option>
                  <option value="Damaged">Record Damaged / Spoiled Stock (-)</option>
                  <option value="Returned">Return Unused Supplies from Field (+)</option>
                  <option value="Adjusted">Count Correction (+ / -)</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="margin-bottom:3px;font-size:11px;font-weight:600;">Quantity <span id="brgyAdjustUnitLabel" style="font-weight:normal;color:var(--color-text-muted);font-size:10px;"></span> <span style="color:var(--color-danger)">*</span></label>
                <input type="number" id="brgyUnifiedAdjustQty" class="form-control" min="1" value="1" required style="font-weight:600;font-size:12px;font-family:var(--font-secondary);">
              </div>
            </div>

            <div class="form-group" id="brgyAdjustTargetGroup" style="display:none;margin-bottom:0;">
              <label class="form-label" style="margin-bottom:3px;font-size:11px;font-weight:600;">Target Purok / Facility <span style="color:var(--color-danger)">*</span></label>
              <select id="brgyUnifiedAdjustTarget" class="form-control" style="font-size:12px;">
                <option value="">Select Target Purok / Facility</option>
                <?php foreach ($localPuroks as $p): ?>
                  <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
                <?php endforeach; ?>
                <option value="Evacuation Center">Barangay Evacuation Center</option>
                <option value="Barangay Responders">Field Response Team</option>
              </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="margin-bottom:3px;font-size:11px;font-weight:600;">Remarks / Purpose / Justification <span style="color:var(--color-danger)">*</span></label>
              <input type="text" id="brgyUnifiedAdjustRemarks" class="form-control" placeholder="Enter reason, donor, purok notes, or explanation" required style="font-size:12px;">
            </div>

            <div style="text-align:right;margin-top:4px;">
              <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitBrgyUnifiedAdjust" style="font-size:11.5px;padding:5px 14px;">
                Confirm Adjustment
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Tab Content 3: Inventory History Log -->
      <div id="brgyModalTabHistory" style="display:none;">
        <div id="brgyItemHistoryContainer" style="max-height:280px;overflow-y:auto;border:1px solid var(--color-border-light);border-radius:var(--radius-sm, 6px);background:var(--color-surface);">
          <table class="data-table" style="margin-bottom:0;font-size:11px;">
            <thead style="position:sticky;top:0;background:var(--color-surface-subtle);z-index:2;">
              <tr>
                <th style="font-size:10px;padding:8px 10px;">Date & Time</th>
                <th style="font-size:10px;padding:8px 10px;">Action Type</th>
                <th style="font-size:10px;padding:8px 10px;">Quantity</th>
                <th style="font-size:10px;padding:8px 10px;">Remarks / Target</th>
                <th style="font-size:10px;padding:8px 10px;">Logged By</th>
              </tr>
            </thead>
            <tbody id="brgyItemHistoryTableBody">
              <tr>
                <td colspan="5" style="text-align:center;padding:24px;color:var(--color-text-muted);">
                  Loading transaction history...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('barangayItemModal')">
        Close
      </button>
    </div>
  </div>
</div>

<script>
let currentBrgyItem = null;

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatHistoryDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr.replace(' ', 'T'));
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
         d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

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

// Switch Tab inside Barangay Item Modal
function switchBrgyModalTab(tab) {
  ['specs', 'adjust', 'history'].forEach(t => {
    const btn = document.getElementById(`brgyTabBtn${t.charAt(0).toUpperCase() + t.slice(1)}`);
    const pane = document.getElementById(`brgyModalTab${t.charAt(0).toUpperCase() + t.slice(1)}`);
    if (btn) btn.classList.toggle('active', t === tab);
    if (pane) pane.style.display = (t === tab) ? 'block' : 'none';
  });
}

// Adjust Type Change Handler
function onBrgyAdjustTypeChange(type) {
  const targetGroup = document.getElementById('brgyAdjustTargetGroup');
  const targetSelect = document.getElementById('brgyUnifiedAdjustTarget');
  if (type === 'Dispatched') {
    if (targetGroup) targetGroup.style.display = 'block';
    if (targetSelect) targetSelect.required = true;
  } else {
    if (targetGroup) targetGroup.style.display = 'none';
    if (targetSelect) {
      targetSelect.required = false;
      targetSelect.value = '';
    }
  }
}

// Open Item Details Modal
function openBarangayItemModal(res) {
  currentBrgyItem = res;
  document.getElementById('brgyModalItemCode').innerText = res.code || '';
  document.getElementById('brgyModalItemCategory').innerText = res.category || '';
  document.getElementById('brgyModalItemName').innerText = res.name || '';
  document.getElementById('brgyModalItemSub').innerText = res.storage_location || 'Barangay Hall Stockroom';

  document.getElementById('brgyStockAvail').innerText = `${parseInt(res.available_quantity || 0).toLocaleString()} ${res.unit || ''}`;
  document.getElementById('brgyStockInUse').innerText = `${parseInt(res.in_use_quantity || 0).toLocaleString()} ${res.unit || ''}`;
  document.getElementById('brgyStockDamaged').innerText = `${parseInt(res.damaged_quantity || 0).toLocaleString()} ${res.unit || ''}`;
  document.getElementById('brgyStockTotal').innerText = `${parseInt(res.total_quantity || 0).toLocaleString()} ${res.unit || ''}`;

  document.getElementById('brgySpecLocation').innerText = res.storage_location || 'Barangay Hall Stockroom';
  document.getElementById('brgySpecSupplier').innerText = res.supplier_donor || 'Local Barangay Inventory';
  document.getElementById('brgySpecCondition').innerText = res.item_condition || 'Good';
  document.getElementById('brgySpecExpiry').innerText = res.expiry_date || 'N/A (Non-perishable)';
  document.getElementById('brgySpecMinThreshold').innerText = `${parseInt(res.min_threshold || 0).toLocaleString()} ${res.unit || ''}`;
  document.getElementById('brgySpecUnit').innerText = res.unit || '';

  // Setup adjustment form
  document.getElementById('brgyUnifiedAdjustResId').value = res.id;
  document.getElementById('brgyUnifiedAdjustType').value = 'Restock';
  onBrgyAdjustTypeChange('Restock');
  document.getElementById('brgyAdjustUnitLabel').innerText = `(${res.unit || ''})`;
  document.getElementById('brgyUnifiedAdjustQty').value = 1;
  document.getElementById('brgyUnifiedAdjustRemarks').value = '';

  // Load history in background
  loadItemHistory(res.id);

  switchBrgyModalTab('specs');
  openModal('barangayItemModal');
}

// Fetch and render item history via AJAX
async function loadItemHistory(resourceId) {
  const tbody = document.getElementById('brgyItemHistoryTableBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:11px;">Loading transaction history...</td></tr>';

  try {
    const res = await fetch(`<?= BASE_URL ?>/backend/functions/resources/get_history.php?resource_id=${encodeURIComponent(resourceId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data.success && Array.isArray(data.transactions) && data.transactions.length > 0) {
      let html = '';
      data.transactions.forEach(t => {
        let badgeClass = 'badge-neutral';
        let qtyColor = 'var(--color-text)';
        let sign = '';

        if (['Restock', 'New Product', 'Returned'].includes(t.transaction_type)) {
          badgeClass = 'badge-success';
          qtyColor = 'var(--color-success)';
          sign = '+';
        } else if (['Dispatched', 'Allocation'].includes(t.transaction_type)) {
          badgeClass = 'badge-info';
          qtyColor = 'var(--color-primary)';
          sign = '-';
        } else if (t.transaction_type === 'Damaged') {
          badgeClass = 'badge-danger';
          qtyColor = 'var(--color-danger)';
          sign = '-';
        }

        const dateStr = formatHistoryDate(t.created_at);
        const performedBy = t.performed_by_name || 'Barangay Staff';
        const remarks = t.remarks || '—';

        html += `
          <tr>
            <td style="font-size:10px;color:var(--color-text-secondary);font-family:var(--font-secondary);white-space:nowrap;padding:7px 10px;">${escapeHtml(dateStr)}</td>
            <td style="padding:7px 10px;"><span class="badge ${badgeClass}" style="font-size:9px;">${escapeHtml(t.transaction_type)}</span></td>
            <td style="font-family:var(--font-secondary);font-weight:700;color:${qtyColor};font-size:11.5px;white-space:nowrap;padding:7px 10px;">${sign}${parseInt(t.quantity).toLocaleString()} ${escapeHtml(t.unit || '')}</td>
            <td style="font-size:11px;color:var(--color-text);max-width:220px;padding:7px 10px;">${escapeHtml(remarks)}</td>
            <td style="font-size:10.5px;color:var(--color-text-secondary);white-space:nowrap;padding:7px 10px;">${escapeHtml(performedBy)}</td>
          </tr>
        `;
      });
      tbody.innerHTML = html;
    } else {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:11px;">No transaction history recorded for this item.</td></tr>';
    }
  } catch (err) {
    console.error(err);
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--color-danger);font-size:11px;">Failed to load transaction history.</td></tr>';
  }
}

// Submit Unified Inventory Adjustment via AJAX
async function submitBrgyUnifiedAdjustment() {
  const resId = document.getElementById('brgyUnifiedAdjustResId').value;
  const type = document.getElementById('brgyUnifiedAdjustType').value;
  const qty = parseInt(document.getElementById('brgyUnifiedAdjustQty').value) || 0;
  let remarks = document.getElementById('brgyUnifiedAdjustRemarks').value.trim();
  const target = document.getElementById('brgyUnifiedAdjustTarget') ? document.getElementById('brgyUnifiedAdjustTarget').value : '';
  const btn = document.getElementById('btnSubmitBrgyUnifiedAdjust');

  if (qty <= 0) return showToast('Please enter a valid quantity.', 'danger');
  if (type === 'Dispatched') {
    if (!target) return showToast('Please select target purok or facility.', 'danger');
    remarks = `Disbursed to ${target}: ${remarks}`;
  }
  if (!remarks) return showToast('Please provide remarks or purpose.', 'danger');

  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = 'Processing...';

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
      currentBrgyItem = data.resource;

      // Update modal live stock metrics
      document.getElementById('brgyStockAvail').innerText = `${parseInt(data.resource.available_quantity).toLocaleString()} ${data.resource.unit}`;
      document.getElementById('brgyStockInUse').innerText = `${parseInt(data.resource.in_use_quantity).toLocaleString()} ${data.resource.unit}`;
      document.getElementById('brgyStockDamaged').innerText = `${parseInt(data.resource.damaged_quantity).toLocaleString()} ${data.resource.unit}`;
      document.getElementById('brgyStockTotal').innerText = `${parseInt(data.resource.total_quantity).toLocaleString()} ${data.resource.unit}`;

      // Update DOM table row in background
      updateBrgyRowInDOM(data.resource);

      if (data.metrics && data.metrics.total_stock !== undefined) {
        const kpi = document.getElementById('kpiLocalStock');
        if (kpi) kpi.innerText = parseInt(data.metrics.total_stock).toLocaleString();
      }

      // Reset form
      document.getElementById('brgyUnifiedAdjustQty').value = '1';
      document.getElementById('brgyUnifiedAdjustRemarks').value = '';
      if (document.getElementById('brgyUnifiedAdjustTarget')) document.getElementById('brgyUnifiedAdjustTarget').value = '';

      // Reload history and switch to history tab
      await loadItemHistory(resId);
      switchBrgyModalTab('history');
    } else {
      showToast(data.message || 'Adjustment failed.', 'danger');
    }
  } catch (err) {
    console.error(err);
    showToast('Network error during inventory adjustment.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

// In-Place Dynamic DOM Row Updater for Barangay Table
function updateBrgyRowInDOM(res) {
  const row = document.getElementById(`brgy-row-${res.id}`);
  if (!row) return;

  row.classList.remove('row-updated-highlight');
  void row.offsetWidth;
  row.classList.add('row-updated-highlight');

  row.dataset.available = res.available_quantity;
  row.dataset.inUse = res.in_use_quantity;
  row.dataset.damaged = res.damaged_quantity;
  row.dataset.total = res.total_quantity;

  const isLow = parseInt(res.available_quantity) <= parseInt(res.min_threshold);
  row.dataset.isLow = isLow ? '1' : '0';

  const availCell = document.getElementById(`brgy-cell-avail-${res.id}`);
  if (availCell) {
    availCell.innerHTML = `
      <div style="font-family:var(--font-secondary);font-weight:700;font-size:12.5px;color:${isLow ? 'var(--color-danger)' : 'var(--color-success)'};">
        <span class="val-avail">${parseInt(res.available_quantity).toLocaleString()}</span>
        <span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);">${res.unit}</span>
      </div>
      ${isLow ? `<span class="badge badge-danger" style="font-size:8px;padding:1px 4px;">Low Stock (Min: ${parseInt(res.min_threshold).toLocaleString()})</span>` : ''}
    `;
  }

  const inUseCell = document.getElementById(`brgy-cell-inuse-${res.id}`);
  if (inUseCell) {
    inUseCell.innerHTML = `<span class="val-inuse">${parseInt(res.in_use_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  const damagedCell = document.getElementById(`brgy-cell-damaged-${res.id}`);
  if (damagedCell) {
    damagedCell.innerHTML = `<span class="val-damaged">${parseInt(res.damaged_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  const totalCell = document.getElementById(`brgy-cell-total-${res.id}`);
  if (totalCell) {
    totalCell.innerHTML = `<span class="val-total">${parseInt(res.total_quantity).toLocaleString()}</span> ${res.unit}`;
  }

  const actionsCell = document.getElementById(`brgy-cell-actions-${res.id}`);
  if (actionsCell) {
    actionsCell.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;" onclick='openBarangayItemModal(${JSON.stringify(res)})'>
        View
      </button>
    `;
  }
}

// Client-side Instant Filter for Barangay Stock Table
function filterBarangayInventoryTable() {
  const query = (document.getElementById('brgySearchInput')?.value || '').toLowerCase().trim();
  const category = (document.getElementById('brgyCategoryFilter')?.value || '').toLowerCase();
  const stockFilter = document.getElementById('brgyStockFilter')?.value || '';

  const rows = document.querySelectorAll('#brgyTableBody tr');
  rows.forEach(row => {
    if (row.id === 'noBrgyInvRow') return;
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

function resetBarangayFilters() {
  if (document.getElementById('brgySearchInput')) document.getElementById('brgySearchInput').value = '';
  if (document.getElementById('brgyCategoryFilter')) document.getElementById('brgyCategoryFilter').value = '';
  if (document.getElementById('brgyStockFilter')) document.getElementById('brgyStockFilter').value = '';
  filterBarangayInventoryTable();
}

// Backdrop click closer
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('barangayItemModal');
  if (el) {
    el.addEventListener('click', (e) => {
      if (e.target === el) closeModal('barangayItemModal');
    });
  }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
