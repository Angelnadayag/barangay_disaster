<?php
// ============================================================================
// Views (Barangay Head): Manage Resources & Supply Requisitions
// Scoped strictly to Barangay jurisdiction (data isolation guaranteed)
// Features: Full Local Inventory CRUD, Central Depot Requisition submission,
// Allocation tracking, and in-place View/Edit modals.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int) $user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
  die("Barangay record not found.");
}

// Fetch local puroks for dropdowns
$purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$purokStmt->execute([$barangayId]);
$localPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);

// Tabs: inventory (default), requests, allocations
$activeTab = in_array($_GET['tab'] ?? '', ['inventory', 'requests', 'allocations']) ? $_GET['tab'] : 'inventory';

// Filter parameters
$search = trim($_GET['search'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterStock = trim($_GET['stock'] ?? '');

// Metrics: strictly scoped to this barangay
$localTotalStock = (int) $db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL)")->fetchColumn();
$localSkuCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL)")->fetchColumn();
$localLowStockCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE barangay_id = {$barangayId} AND (status != 'archived' OR status IS NULL) AND available_quantity <= min_threshold")->fetchColumn();
$pendingRequestsCount = (int) $db->query("SELECT COUNT(*) FROM barangay_resource_requests WHERE barangay_id = {$barangayId} AND status = 'Pending'")->fetchColumn();
$approvedRequestsCount = (int) $db->query("SELECT COUNT(*) FROM barangay_resource_requests WHERE barangay_id = {$barangayId} AND status = 'Approved'")->fetchColumn();

// 1. Fetch Local Barangay Inventory
$invSql = "SELECT * FROM (SELECT r.*, rs.name AS supplier_donor FROM resources r LEFT JOIN resource_suppliers rs ON r.supplier_id = rs.id) resources WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)";
$invParams = [$barangayId];

if (!empty($filterCategory)) {
  $invSql .= " AND category = ?";
  $invParams[] = $filterCategory;
}

if ($filterStock === 'low') {
  $invSql .= " AND available_quantity <= min_threshold";
} elseif ($filterStock === 'out') {
  $invSql .= " AND available_quantity = 0";
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

$invSql .= " ORDER BY (available_quantity <= min_threshold) DESC, name ASC";
$invStmt = $db->prepare($invSql);
$invStmt->execute($invParams);
$inventoryList = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Requisition Requests submitted by this Barangay
$reqSql = "
    SELECT r.*, u.full_name AS requested_by_name, rev.full_name AS reviewed_by_name
    FROM barangay_resource_requests r
    LEFT JOIN users u ON r.requested_by = u.id
    LEFT JOIN users rev ON r.reviewed_by = rev.id
    WHERE r.barangay_id = ?
";
$reqParams = [$barangayId];

if (!empty($search) && $activeTab === 'requests') {
  $reqSql .= " AND (r.request_code LIKE ? OR r.item_name LIKE ? OR r.target_purok LIKE ? OR r.purpose LIKE ?)";
  $like = "%$search%";
  $reqParams[] = $like;
  $reqParams[] = $like;
  $reqParams[] = $like;
  $reqParams[] = $like;
}

$reqSql .= " ORDER BY r.created_at DESC";
$reqStmt = $db->prepare($reqSql);
$reqStmt->execute($reqParams);
$requestsList = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Received Relief Allocations
$allocStmt = $db->prepare("
    SELECT ri.*, res.code, res.name AS resource_name, res.unit, res.category,
           r.recommendation_code, dr.tracking_code, dr.disaster_type, dr.purok_name, r.reviewed_at
    FROM recommended_items ri
    JOIN resources res ON ri.resource_id = res.id
    JOIN recommendations r ON ri.recommendation_id = r.id
    JOIN disaster_reports dr ON r.disaster_request_id = dr.id
    WHERE dr.barangay_id = ? AND ri.status = 'Allocated'
    ORDER BY r.reviewed_at DESC
");
$allocStmt->execute([$barangayId]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Central Depot Catalog (for requisition items dropdown)
$centralCatalog = $db->query("
    SELECT id, code, name, category, unit, available_quantity, storage_location
    FROM resources
    WHERE barangay_id IS NULL AND status = 'active'
    ORDER BY category ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Dynamic Categories: strictly persisted in resource_categories table
$categories = $db->query("SELECT name FROM resource_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($categories)) {
  $categories = ['Relief Goods', 'Medical Supplies', 'Rescue Equipment', 'Emergency Supplies', 'Shelter & Sanitation'];
}

// Dynamic Brands: strictly persisted in resource_brands table
$brands = $db->query("SELECT name FROM resource_brands ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($brands)) {
  $brands = ['Generic / Unbranded', 'DSWD Relief', 'Honda', 'Yamaha', 'Stihl', '3M', 'Johnson & Johnson', 'Standard LGU'];
}

$conditions = ['New', 'Good', 'Fair', 'Poor', 'Expired'];

// Predefined Storage Locations (defined in code only, not from database)
$storageLocations = [
  'Barangay Hall Stockroom',
  'Barangay Evacuation Center',
  'Barangay Health Center',
  'Barangay Disaster Operations Center (BDOC)',
  'Barangay Covered Court / Warehouse',
  'Barangay Outpost / Storage Shed',
  'Purok Storage Unit',
  'Materials Recovery Facility (MRF)'
];

// Dynamic Suppliers / Donors / Sources: strictly persisted in resource_suppliers table
$supplierDonorSources = $db->query("SELECT name FROM resource_suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($supplierDonorSources)) {
  $supplierDonorSources = [
    'Barangay Disaster Risk Reduction and Management Fund (BDRRMF)',
    'City Disaster Risk Reduction and Management Office (CDRRMO)',
    'Department of Social Welfare and Development (DSWD)',
    'Department of Health (DOH)',
    'Office of Civil Defense (OCD)',
    'Philippine Red Cross (PRC)'
  ];
}

// Dynamic Resource Names Catalog (Standard supplies + all existing registered items)
$registeredResourceItems = $db->query("
    SELECT DISTINCT name, category, unit, brand
    FROM resources
    WHERE name IS NOT NULL AND TRIM(name) != ''
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$resourceCatalog = [];
foreach ($registeredResourceItems as $item) {
  $resourceCatalog[$item['name']] = [
    'name' => $item['name'],
    'category' => $item['category'] ?? '',
    'unit' => $item['unit'] ?? 'pcs',
    'brand' => $item['brand'] ?? ''
  ];
}

$standardResources = [
  ['name' => 'Standard Family Food Pack (3-day ration)', 'category' => 'Relief Goods', 'unit' => 'packs'],
  ['name' => 'Ready-to-Eat Emergency Meals (RTE Retort Pouch)', 'category' => 'Relief Goods', 'unit' => 'pouches'],
  ['name' => 'Bottled Mineral Drinking Water (6L Dispenser Bottle)', 'category' => 'Relief Goods', 'unit' => 'bottles'],
  ['name' => 'High-Energy Disaster Relief Biscuits (HEB)', 'category' => 'Relief Goods', 'unit' => 'boxes'],
  ['name' => 'Family Hygiene & Sanitation Kit', 'category' => 'Shelter & Sanitation', 'unit' => 'kits'],
  ['name' => 'Disaster First Aid Response Kit (Heavy Duty Bag)', 'category' => 'Medical Supplies', 'unit' => 'kits'],
  ['name' => 'Essential Medicine Dispensing Pack (Box of 500 doses)', 'category' => 'Medical Supplies', 'unit' => 'boxes'],
  ['name' => 'Sterile Intravenous Fluid & Infusion Set (0.9% NaCl 1000mL)', 'category' => 'Medical Supplies', 'unit' => 'sets'],
  ['name' => 'Spine Board with Head Immobilizer & Straps', 'category' => 'Rescue Equipment', 'unit' => 'sets'],
  ['name' => 'Inflatable Rescue Boat with 25HP Outboard Motor', 'category' => 'Rescue Equipment', 'unit' => 'units'],
  ['name' => 'Standard Personal Flotation Device (SOLAS Type-III PFD)', 'category' => 'Rescue Equipment', 'unit' => 'pcs'],
  ['name' => 'Heavy Rescue Chainsaw (Gasoline 24-inch Bar)', 'category' => 'Rescue Equipment', 'unit' => 'units'],
  ['name' => 'Megaphone / Bullhorn Siren', 'category' => 'Rescue Equipment', 'unit' => 'units'],
  ['name' => 'Fire Extinguisher (ABC Dry Chemical 10lbs)', 'category' => 'Emergency Supplies', 'unit' => 'tanks'],
  ['name' => 'Family Evacuation Modular Privacy Tent (3m x 3m)', 'category' => 'Shelter & Sanitation', 'unit' => 'tents'],
  ['name' => 'Folding Cot / Camp Bed', 'category' => 'Shelter & Sanitation', 'unit' => 'units'],
  ['name' => 'Emergency Thermal Blankets (Pack of 50)', 'category' => 'Shelter & Sanitation', 'unit' => 'packs'],
  ['name' => 'Heavy Duty Quiet Inverter Gasoline Generator (7.5kVA)', 'category' => 'Emergency Supplies', 'unit' => 'units'],
  ['name' => 'Emergency Generator 5kVA', 'category' => 'Rescue Equipment', 'unit' => 'units'],
  ['name' => 'High-Output Rechargeable LED Searchlight & Area Floodlight', 'category' => 'Emergency Supplies', 'unit' => 'units'],
  ['name' => 'High-Capacity Submersible De-Watering Trash Pump (3-inch)', 'category' => 'Emergency Supplies', 'unit' => 'units']
];

foreach ($standardResources as $std) {
  if (!isset($resourceCatalog[$std['name']])) {
    $resourceCatalog[$std['name']] = [
      'name' => $std['name'],
      'category' => $std['category'],
      'unit' => $std['unit'],
      'brand' => ''
    ];
  }
}
ksort($resourceCatalog, SORT_NATURAL | SORT_FLAG_CASE);

$pageTitle = "Manage Resources — Barangay " . clean($barangay['name']);
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<style>
  /* Robust Modal Styling for Barangay Manage Resources */
  #createResourceModal.modal-overlay,
  #viewResourceModal.modal-overlay,
  #requestResourceModal.modal-overlay,
  #viewRequestModal.modal-overlay,
  #confirmationModal.modal-overlay {
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

  #createResourceModal.modal-overlay.active,
  #viewResourceModal.modal-overlay.active,
  #requestResourceModal.modal-overlay.active,
  #viewRequestModal.modal-overlay.active,
  #confirmationModal.modal-overlay.active {
    display: flex !important;
  }

  #createResourceModal .modal-dialog,
  #viewResourceModal .modal-dialog,
  #requestResourceModal .modal-dialog,
  #viewRequestModal .modal-dialog {
    max-width: 680px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #CBD5E1) !important;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
    position: relative !important;
  }

  #confirmationModal .modal-dialog {
    max-width: 440px !important;
    width: 100% !important;
    margin: auto !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #CBD5E1) !important;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25) !important;
  }

  .modal-header {
    flex-shrink: 0 !important;
    padding: 14px 20px !important;
    border-bottom: 1px solid var(--color-border-light, #E2E8F0) !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
  }

  .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    padding: 18px 20px !important;
  }

  .modal-footer {
    flex-shrink: 0 !important;
    padding: 12px 20px !important;
    border-top: 1px solid var(--color-border-light, #E2E8F0) !important;
    background-color: var(--color-surface, #F8FAFC) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 10px !important;
  }

  .modal-close-btn {
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
  }

  .modal-close-btn:hover {
    color: var(--color-danger);
    background: rgba(239, 68, 68, 0.1);
  }

  .form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-bottom: 14px;
  }

  @media (max-width: 600px) {
    .form-row {
      grid-template-columns: 1fr;
    }
  }

  .form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }

  .form-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--color-text);
    letter-spacing: 0.2px;
  }

  .form-label-required::after {
    content: " *";
    color: var(--color-danger);
  }

  .form-control {
    padding: 7px 11px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-secondary, 6px);
    font-size: 12px;
    background: #FFFFFF;
    color: var(--color-text);
    font-family: inherit;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .form-control:focus {
    border-color: var(--color-primary);
    outline: none;
    box-shadow: 0 0 0 3px rgba(27, 75, 120, 0.12);
  }

  .form-control:disabled,
  .form-control[readonly] {
    background-color: #F1F5F9;
    color: #475569;
    cursor: not-allowed;
    opacity: 0.9;
  }

  #createResourceModal .modal-dialog,
  #viewResourceModal .modal-dialog {
    max-width: 720px !important;
  }

  .form-section-title {
    font-size: 11px;
    font-weight: 700;
    color: var(--color-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-bottom: 6px;
    margin-top: 6px;
    border-bottom: 1px solid var(--color-border);
  }

  .expiry-warning {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 8.5px;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 700;
  }

  .expiry-expired {
    background: #FEE2E2;
    color: #991B1B;
  }

  .expiry-soon {
    background: #FEF3C7;
    color: #92400E;
  }

  .info-banner {
    background: #EFF6FF;
    border-left: 3px solid #3B82F6;
    padding: 9px 12px;
    border-radius: 4px;
    font-size: 11px;
    color: #1E40AF;
    margin-bottom: 14px;
  }
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay <?= clean($barangay['name']) ?> Manage Resources</h1>
      <p class="page-header-desc">
        Maintain local relief and emergency provisions, track inventory stock, and submit supply requisition requests
        directly to the ICDRRMO Central Depot.
      </p>
    </div>
    <div class="page-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" class="btn btn-outline" onclick="openRequestResourceModal()"
        style="border-color:var(--color-primary);color:var(--color-primary);">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          style="margin-right:4px;">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
          <polyline points="7 10 12 15 17 10"></polyline>
          <line x1="12" y1="15" x2="12" y2="3"></line>
        </svg>
        Request Supplies from ICDRRMO
      </button>
      <button type="button" class="btn btn-primary" onclick="openCreateResourceModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          style="margin-right:4px;">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Add Local Resource
      </button>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div style="display:flex;gap:8px;margin-bottom:var(--space-3);align-items:center;flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/views/barangay/resources.php?tab=inventory"
      class="btn btn-sm <?= $activeTab === 'inventory' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        style="margin-right:4px;vertical-align:-1px;">
        <rect x="1" y="3" width="15" height="13"></rect>
        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
        <circle cx="5.5" cy="18.5" r="2.5"></circle>
        <circle cx="18.5" cy="18.5" r="2.5"></circle>
      </svg>
      Local Inventory (<?= $localSkuCount ?>)
    </a>
    <a href="<?= BASE_URL ?>/views/barangay/resources.php?tab=requests"
      class="btn btn-sm <?= $activeTab === 'requests' ? 'btn-primary' : 'btn-outline' ?>"
      style="font-size:11px;<?= $activeTab === 'requests' ? '' : 'color:var(--color-text-muted);' ?>">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        style="margin-right:4px;vertical-align:-1px;">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="16" y1="13" x2="8" y2="13"></line>
        <line x1="16" y1="17" x2="8" y2="17"></line>
        <polyline points="10 9 9 9 8 9"></polyline>
      </svg>
      Supply Requisitions (<?= count($requestsList) ?>)
      <?php if ($pendingRequestsCount > 0): ?>
        <span class="badge badge-warning" style="margin-left:4px;font-size:9px;"><?= $pendingRequestsCount ?>
          Pending</span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/views/barangay/resources.php?tab=allocations"
      class="btn btn-sm <?= $activeTab === 'allocations' ? 'btn-primary' : 'btn-outline' ?>"
      style="font-size:11px;<?= $activeTab === 'allocations' ? '' : 'color:var(--color-text-muted);' ?>">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        style="margin-right:4px;vertical-align:-1px;">
        <path
          d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
        </path>
        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
        <line x1="12" y1="22.08" x2="12" y2="12"></line>
      </svg>
      Received Relief Allocations (<?= count($allocations) ?>)
    </a>
  </div>

  <!-- KPI Metrics Grid -->
  <div class="metrics-grid" style="margin-bottom:var(--space-4);">
    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Local Available Units</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path
            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
          </path>
        </svg>
      </div>
      <div class="metric-value" style="color:var(--color-primary);"><?= number_format($localTotalStock) ?></div>
      <div class="metric-meta">On hand in Barangay Hall stockroom</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Active Local SKUs</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7"></rect>
          <rect x="14" y="3" width="7" height="7"></rect>
          <rect x="14" y="14" width="7" height="7"></rect>
          <rect x="3" y="14" width="7" height="7"></rect>
        </svg>
      </div>
      <div class="metric-value"><?= number_format($localSkuCount) ?></div>
      <div class="metric-meta">Cataloged commodity types</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Low Stock Alerts</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon>
          <line x1="12" y1="8" x2="12" y2="12"></line>
          <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
      </div>
      <div class="metric-value"
        style="color:<?= $localLowStockCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
        <?= number_format($localLowStockCount) ?>
      </div>
      <div class="metric-meta">At or below safety threshold</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">ICDRRMO Requisitions</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
        </svg>
      </div>
      <div class="metric-value" style="color:<?= $pendingRequestsCount > 0 ? '#D97706' : 'var(--color-primary)' ?>;">
        <?= $pendingRequestsCount ?> <span style="font-size:12px;font-weight:500;color:var(--color-text-muted);">/
          <?= count($requestsList) ?></span>
      </div>
      <div class="metric-meta"><?= $pendingRequestsCount ?> Pending Review, <?= $approvedRequestsCount ?> Approved</div>
    </div>
  </div>

  <?php if ($activeTab === 'inventory'): ?>
    <!-- TAB 1: LOCAL INVENTORY MANAGEMENT -->
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
          <h3 class="card-title">Barangay <?= clean($barangay['name']) ?> Local Provisions</h3>
          <div class="card-subtitle">Exclusive inventory belonging strictly to this barangay (not visible to other
            barangays)</div>
        </div>

        <!-- Filter Form -->
        <form method="GET" action="<?= BASE_URL ?>/views/barangay/resources.php"
          style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="tab" value="inventory">
          <input type="text" name="search" class="form-control" placeholder="Search SKU or name..."
            value="<?= htmlspecialchars($search) ?>" style="width:180px;font-size:11px;padding:5px 9px;">

          <select name="category" class="form-control" style="width:140px;font-size:11px;padding:5px 9px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= $cat ?></option>
            <?php endforeach; ?>
          </select>

          <select name="stock" class="form-control" style="width:130px;font-size:11px;padding:5px 9px;">
            <option value="">All Stock Levels</option>
            <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
            <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
          </select>

          <button type="submit" class="btn btn-primary btn-sm" style="padding:5px 10px;font-size:11px;">Filter</button>
          <?php if (!empty($search) || !empty($filterCategory) || !empty($filterStock)): ?>
            <a href="<?= BASE_URL ?>/views/barangay/resources.php?tab=inventory" class="btn btn-outline btn-sm"
              style="padding:5px 8px;font-size:11px;">Clear</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>SKU Code</th>
              <th>Resource Name</th>
              <th>Category</th>
              <th>Barangay Stock</th>
              <th>Brand</th>
              <th>Condition</th>
              <th>Expiry Date</th>
              <th>Supplier / Donor</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($inventoryList)): ?>
              <tr>
                <td colspan="9" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                  <div style="font-size:14px;font-weight:600;margin-bottom:6px;">No local resources found</div>
                  <div style="font-size:11px;margin-bottom:12px;">Add local emergency equipment or request provisions from
                    ICDRRMO Central Depot.</div>
                  <button type="button" class="btn btn-primary btn-sm" onclick="openCreateResourceModal()">Add First
                    Resource</button>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($inventoryList as $r): ?>
                <?php
                $isLow = ($r['available_quantity'] <= $r['min_threshold'] && ($r['status'] ?? 'active') === 'active');
                $isExpired = (!empty($r['expiry_date']) && $r['expiry_date'] < date('Y-m-d'));
                $isExpiringSoon = (!empty($r['expiry_date']) && !$isExpired && $r['expiry_date'] <= date('Y-m-d', strtotime('+30 days')));
                $rowBg = $isExpired ? '#FEF2F2' : ($isLow ? '#FFFDF8' : '');
                ?>
                <tr id="res-row-<?= $r['id'] ?>" style="<?= $rowBg ? "background-color: $rowBg;" : '' ?>">
                  <td>
                    <span class="badge badge-neutral" style="font-family:var(--font-secondary);font-weight:700;font-size:10px;">
                      <?= clean($r['code']) ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-weight:700;color:var(--color-primary);font-size:12.5px;"><?= clean($r['name']) ?></div>
                  </td>
                  <td>
                    <span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($r['category']) ?></span>
                  </td>
                  <td id="res-stock-cell-<?= $r['id'] ?>">
                    <div style="font-family:var(--font-secondary);font-weight:700;font-size:12.5px;color:<?= $isLow ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                      <span class="res-avail-val"><?= number_format($r['available_quantity']) ?></span> <span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);"><?= clean($r['unit']) ?></span>
                    </div>
                    <div style="font-size:9px;color:var(--color-text-muted);">Total: <span class="res-total-val"><?= number_format($r['total_quantity']) ?></span></div>
                  </td>
                  <td style="font-size:11px;color:var(--color-text-secondary);">
                    <?= clean($r['brand'] ?: '—') ?>
                  </td>
                  <td>
                    <?php
                    $condColors = ['New' => 'badge-success', 'Good' => 'badge-info', 'Fair' => 'badge-warning', 'Poor' => 'badge-danger', 'Expired' => 'badge-danger'];
                    $condClass = $condColors[$r['item_condition']] ?? 'badge-neutral';
                    ?>
                    <span class="badge <?= $condClass ?>" style="font-size:9px;"><?= clean($r['item_condition']) ?></span>
                  </td>
                  <td>
                    <?php if (!empty($r['expiry_date'])): ?>
                      <div style="font-family:var(--font-secondary);font-size:11px;">
                        <?= date('M d, Y', strtotime($r['expiry_date'])) ?>
                      </div>
                      <?php if ($isExpired): ?>
                        <span class="expiry-warning expiry-expired">⚠ EXPIRED</span>
                      <?php elseif ($isExpiringSoon): ?>
                        <span class="expiry-warning expiry-soon">⏳ Expiring Soon</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span style="font-size:10px;color:var(--color-text-muted);">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:10px;color:var(--color-text-secondary);max-width:140px;">
                    <?= clean($r['supplier_donor'] ?: '—') ?>
                    <?php if (!empty($r['batch_number'])): ?>
                      <div style="font-size:9px;color:var(--color-text-muted);">Batch: <?= clean($r['batch_number']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:inline-flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;">
                      <button class="btn btn-outline btn-sm" onclick="openViewResourceModal(<?= htmlspecialchars(json_encode($r)) ?>)" style="display:inline-flex;align-items:center;gap:3px;" title="View Specifications">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        View
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

  <?php elseif ($activeTab === 'requests'): ?>
    <!-- TAB 2: SUPPLY REQUISITIONS TO ICDRRMO -->
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
          <h3 class="card-title">Requisitions Submitted to ICDRRMO Central Depot</h3>
          <div class="card-subtitle">Track requisition reviews, view remarks, and inspect stock transferred upon approval
          </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openRequestResourceModal()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="margin-right:4px;">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          New Requisition Request
        </button>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Request Code</th>
              <th>Item Requested</th>
              <th>Category</th>
              <th>Quantity</th>
              <th>Urgency</th>
              <th>Target Purok</th>
              <th>Status</th>
              <th>Date Filed</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($requestsList)): ?>
              <tr>
                <td colspan="9" style="text-align:center;padding:36px;color:var(--color-text-muted);">
                  <div style="font-size:14px;font-weight:600;margin-bottom:6px;">No requisitions submitted yet</div>
                  <div style="font-size:11px;margin-bottom:12px;">When supplies are needed from the central depot, submit a
                    requisition request here.</div>
                  <button type="button" class="btn btn-primary btn-sm" onclick="openRequestResourceModal()">Submit
                    Requisition</button>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($requestsList as $req): ?>
                <tr>
                  <td style="font-family:var(--font-secondary);font-weight:700;font-size:11px;color:var(--color-primary);">
                    <?= clean($req['request_code']) ?>
                  </td>
                  <td>
                    <div style="font-weight:600;color:var(--color-text);font-size:12px;"><?= clean($req['item_name']) ?></div>
                    <div
                      style="font-size:9.5px;color:var(--color-text-muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                      <?= clean($req['purpose']) ?>
                    </div>
                  </td>
                  <td>
                    <span style="font-size:11px;"><?= clean($req['category']) ?></span>
                  </td>
                  <td>
                    <div style="font-family:var(--font-secondary);font-weight:700;font-size:12px;">
                      <?= number_format($req['requested_quantity']) ?>       <?= clean($req['unit']) ?>
                    </div>
                    <?php if ($req['status'] === 'Approved' && !empty($req['approved_quantity'])): ?>
                      <div style="font-size:9.5px;color:var(--color-success);font-weight:600;">
                        Approved: <?= number_format($req['approved_quantity']) ?>         <?= clean($req['unit']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php
                    $urgClass = 'badge-info';
                    if ($req['urgency'] === 'Immediate' || $req['urgency'] === 'Critical')
                      $urgClass = 'badge-danger';
                    elseif ($req['urgency'] === 'High')
                      $urgClass = 'badge-warning';
                    elseif ($req['urgency'] === 'Medium')
                      $urgClass = 'badge-primary';
                    ?>
                    <span class="badge <?= $urgClass ?>" style="font-size:9.5px;"><?= clean($req['urgency']) ?></span>
                  </td>
                  <td style="font-size:11px;font-weight:500;">
                    <?= clean($req['target_purok'] ?: 'Barangay-wide') ?>
                  </td>
                  <td>
                    <?php if ($req['status'] === 'Approved'): ?>
                      <span class="badge badge-success">Approved</span>
                    <?php elseif ($req['status'] === 'Rejected'): ?>
                      <span class="badge badge-danger">Rejected</span>
                    <?php else: ?>
                      <span class="badge badge-warning">Pending Review</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);">
                    <?= formatDate($req['created_at'], 'M d, Y') ?>
                  </td>
                  <td style="text-align:right;">
                    <!-- Single View button matching style -->
                    <button type="button" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:10.5px;"
                      onclick="viewRequisitionDetails(<?= htmlspecialchars(json_encode($req)) ?>)">
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

  <?php elseif ($activeTab === 'allocations'): ?>
    <!-- TAB 3: RECEIVED RELIEF ALLOCATIONS (INCIDENTS) -->
    <div class="card" style="margin-bottom:var(--space-4);">
      <div class="card-header">
        <div>
          <h3 class="card-title">Dispatched & Received Relief Allocations</h3>
          <div class="card-subtitle">Approved by ICDRRMO via Decision Tree Recommender for Barangay
            <?= clean($barangay['name']) ?></div>
        </div>
        <span class="badge badge-success"><?= count($allocations) ?> Allocation Batch(es)</span>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Disaster Incident</th>
              <th>Purok Target</th>
              <th>Allocated Resource</th>
              <th>Category</th>
              <th>Allocated Quantity</th>
              <th>Recommendation Ref</th>
              <th>Dispatch Approval Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allocations)): ?>
              <tr>
                <td colspan="7" style="text-align:center;padding:36px;color:var(--color-text-muted);">No disaster incident
                  packages have been allocated to this barangay yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allocations as $a): ?>
                <tr>
                  <td style="font-weight:600;font-family:var(--font-secondary);">
                    <span style="color:var(--color-primary);"><?= clean($a['tracking_code']) ?></span>
                    <div style="font-size:9.5px;color:var(--color-text-secondary);"><?= clean($a['disaster_type']) ?></div>
                  </td>
                  <td style="font-weight:600;font-size:11px;"><?= clean($a['purok_name']) ?></td>
                  <td>
                    <div style="font-weight:600;color:var(--color-primary);font-size:12px;"><?= clean($a['resource_name']) ?>
                    </div>
                    <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['code']) ?></div>
                  </td>
                  <td><?= clean($a['category']) ?></td>
                  <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);font-size:12px;">
                    <?= number_format($a['allocated_quantity']) ?>       <?= clean($a['unit']) ?>
                  </td>
                  <td style="font-family:var(--font-secondary);font-size:10px;">
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
</main>

<!-- ========================================================================= -->
<!-- MODAL 1: Add New Resource Item (Full Detail)                              -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createResourceModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Add New Resource Item</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Add a disaster commodity SKU into barangay local warehouse inventory.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createResourceModal')">&times;</button>
    </div>
    <form id="createResourceForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/create.php" onsubmit="handleCreateResource(event)">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">

        <div class="form-section-title">Basic Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">SKU / Item Code <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="code" class="form-control" placeholder="e.g. BRG<?= $barangayId ?>-FOOD-008" required style="text-transform:uppercase;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Category <span style="color:var(--color-danger)">*</span></label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_category_create" onclick="toggleDynamicField('category', 'create')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="category" id="create_res_category" class="form-control" required>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_category_create" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_category_input_create" class="form-control" placeholder="New category name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('category', 'create')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('category', 'create')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Resource Item Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Family Food Pack (3-Day Supply)" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Brand / Manufacturer</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_brand_create" onclick="toggleDynamicField('brand', 'create')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="brand" id="create_res_brand" class="form-control">
              <option value="">— Select Brand / Manufacturer —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= clean($b) ?>"><?= clean($b) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_brand_create" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_brand_input_create" class="form-control" placeholder="New brand name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('brand', 'create')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('brand', 'create')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Item Condition <span style="color:var(--color-danger)">*</span></label>
            <select name="item_condition" class="form-control" required>
              <?php foreach ($conditions as $cond): ?>
                <option value="<?= $cond ?>" <?= $cond === 'New' ? 'selected' : '' ?>><?= $cond ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-section-title">Quantity & Measurement</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Unit of Measure <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="unit" class="form-control" placeholder="e.g. packs, boxes, kits" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Initial Quantity <span style="color:var(--color-danger)">*</span></label>
            <input type="number" name="quantity" class="form-control" min="0" value="0" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Low Stock Alert / Restock Threshold <span style="color:var(--color-danger)">*</span></label>
            <input type="number" name="min_threshold" id="create_res_min_threshold" class="form-control" min="1" value="50" required>
            <span style="font-size:10px;color:var(--color-text-muted);display:block;margin-top:3px;line-height:1.3;">
              ⚠️ <strong>Restock Trigger Level:</strong> Alerts administrators to restock when depot inventory &le; threshold. <em>(NOT a distribution limit).</em>
            </span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Weight per Unit</label>
            <input type="text" name="weight_per_unit" class="form-control" placeholder="e.g. 5kg, 500g, 1 liter">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Cost per Unit (₱)</label>
            <input type="number" name="cost_per_unit" class="form-control" min="0" step="0.01" placeholder="e.g. 250.00">
          </div>
        </div>

        <div class="form-section-title">Procurement & Expiry</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Date Acquired</label>
            <input type="date" name="date_acquired" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expiry_date" class="form-control">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Perishable?</label>
            <div style="display:flex;align-items:center;gap:8px;padding-top:6px;">
              <input type="checkbox" name="is_perishable" id="create_is_perishable" value="1" style="width:16px;height:16px;">
              <label for="create_is_perishable" style="font-size:11px;color:var(--color-text-secondary);cursor:pointer;">Yes, this item is perishable</label>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Supplier / Donor</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_supplier_create" onclick="toggleDynamicField('supplier', 'create')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="supplier_donor" id="create_res_supplier" class="form-control">
              <option value="">— Select Supplier / Donor —</option>
              <?php foreach ($supplierDonorSources as $s): ?>
                <option value="<?= clean($s) ?>"><?= clean($s) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_supplier_create" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_supplier_input_create" class="form-control" placeholder="New supplier / donor name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('supplier', 'create')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('supplier', 'create')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Batch / Lot Number</label>
            <input type="text" name="batch_number" class="form-control" placeholder="e.g. BATCH-2026-OCT-001">
          </div>
        </div>

        <div class="form-section-title">Storage & Details</div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Storage Depot / Warehouse Location</label>
          <input type="text" name="storage_location" class="form-control" value="Barangay Hall Stockroom">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Description & Specifications</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Contents, packaging specifications, expiration handling, or donor tags..."></textarea>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('createResourceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitCreateResource">Add Resource</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: View / Edit Resource Modal (Full Detail)                         -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewResourceModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <div style="display:flex;align-items:center;gap:8px;">
          <h3 class="modal-title" id="view_res_title" style="margin:0;">Resource Details</h3>
          <span id="view_res_status_badge" class="badge badge-success" style="font-size:10px;">Active</span>
          <span id="view_res_condition_badge" class="badge badge-success" style="font-size:10px;">New</span>
        </div>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;" id="view_res_subtitle">
          Full barangay inventory profile with procurement details, expiry tracking, and inventory levels.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewResourceModal')">&times;</button>
    </div>
    <form id="viewResourceForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/update.php" onsubmit="handleUpdateResource(event)">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="id" id="view_res_id">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <!-- Status & Mode Banner -->
        <div id="view_res_edit_banner" style="display:none;padding:8px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;font-size:11px;color:#1E40AF;align-items:center;gap:6px;">
          <span>✏️</span> <strong>Edit Mode Active:</strong> Modify the resource specifications below. Click "Save Changes" to apply.
        </div>

        <!-- Quick Summary Metrics Bar -->
        <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px;font-size:11px;display:flex;justify-content:space-around;text-align:center;">
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Available</span>
            <strong id="badge_res_available" style="color:var(--color-success);font-size:13px;">0</strong>
          </div>
          <div style="border-left:1px solid #E2E8F0;height:24px;"></div>
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">In-Use</span>
            <strong id="badge_res_in_use" style="color:var(--color-text-muted);font-size:13px;">0</strong>
          </div>
          <div style="border-left:1px solid #E2E8F0;height:24px;"></div>
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Damaged</span>
            <strong id="badge_res_damaged" style="color:var(--color-danger);font-size:13px;">0</strong>
          </div>
          <div style="border-left:1px solid #E2E8F0;height:24px;"></div>
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Total</span>
            <strong id="badge_res_total" style="font-size:13px;">0</strong>
          </div>
        </div>

        <!-- Expiry Alert (conditionally shown) -->
        <div id="view_res_expiry_alert" style="display:none;padding:8px 12px;border-radius:6px;font-size:11px;align-items:center;gap:6px;"></div>

        <div class="form-section-title">Basic Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">SKU / Item Code <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="code" id="view_res_code" class="form-control" required disabled style="text-transform:uppercase;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Category <span style="color:var(--color-danger)">*</span></label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_category_view" onclick="toggleDynamicField('category', 'view')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="category" id="view_res_category" class="form-control" required disabled>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_category_view" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_category_input_view" class="form-control" placeholder="New category name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('category', 'view')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('category', 'view')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Resource Item Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" name="name" id="view_res_name" class="form-control" required disabled>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Brand / Manufacturer</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_brand_view" onclick="toggleDynamicField('brand', 'view')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="brand" id="view_res_brand" class="form-control" disabled>
              <option value="">— Select Brand / Manufacturer —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= clean($b) ?>"><?= clean($b) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_brand_view" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_brand_input_view" class="form-control" placeholder="New brand name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('brand', 'view')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('brand', 'view')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Item Condition <span style="color:var(--color-danger)">*</span></label>
            <select name="item_condition" id="view_res_item_condition" class="form-control" required disabled>
              <?php foreach ($conditions as $cond): ?>
                <option value="<?= $cond ?>"><?= $cond ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-section-title">Stock Quantities</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Unit of Measure <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="unit" id="view_res_unit" class="form-control" required disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Low Stock Alert / Restock Threshold <span style="color:var(--color-danger)">*</span></label>
            <input type="number" name="min_threshold" id="view_res_min_threshold" class="form-control" min="1" required disabled>
            <span style="font-size:10px;color:var(--color-text-muted);display:block;margin-top:3px;line-height:1.3;">
              ⚠️ <strong>Restock Trigger Level:</strong> Alerts administrators to restock when depot inventory &le; threshold. <em>(NOT a distribution limit).</em>
            </span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available Stock</label>
            <input type="number" name="available_quantity" id="view_res_available_quantity" class="form-control" min="0" required disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">In-Use / Dispatched</label>
            <input type="number" name="in_use_quantity" id="view_res_in_use_quantity" class="form-control" min="0" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Damaged / Expired</label>
            <input type="number" name="damaged_quantity" id="view_res_damaged_quantity" class="form-control" min="0" disabled>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Weight per Unit</label>
            <input type="text" name="weight_per_unit" id="view_res_weight_per_unit" class="form-control" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Cost per Unit (₱)</label>
            <input type="number" name="cost_per_unit" id="view_res_cost_per_unit" class="form-control" min="0" step="0.01" disabled>
          </div>
        </div>

        <div class="form-section-title">Procurement & Expiry</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Date Acquired</label>
            <input type="date" name="date_acquired" id="view_res_date_acquired" class="form-control" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expiry_date" id="view_res_expiry_date" class="form-control" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Perishable?</label>
            <div style="display:flex;align-items:center;gap:8px;padding-top:6px;">
              <input type="checkbox" name="is_perishable" id="view_res_is_perishable" value="1" style="width:16px;height:16px;" disabled>
              <label for="view_res_is_perishable" style="font-size:11px;color:var(--color-text-secondary);cursor:pointer;">Perishable</label>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Supplier / Donor</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_supplier_view" onclick="toggleDynamicField('supplier', 'view')" style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="supplier_donor" id="view_res_supplier_donor" class="form-control" disabled>
              <option value="">— Select Supplier / Donor —</option>
              <?php foreach ($supplierDonorSources as $s): ?>
                <option value="<?= clean($s) ?>"><?= clean($s) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_supplier_view" style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_supplier_input_view" class="form-control" placeholder="New supplier / donor name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('supplier', 'view')" style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('supplier', 'view')" style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Batch / Lot Number</label>
            <input type="text" name="batch_number" id="view_res_batch_number" class="form-control" disabled>
          </div>
        </div>

        <div class="form-section-title">Storage & Details</div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Storage Depot / Warehouse Location</label>
          <input type="text" name="storage_location" id="view_res_storage_location" class="form-control" disabled>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Description & Specifications</label>
          <textarea name="description" id="view_res_description" class="form-control" rows="2" disabled></textarea>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Delete Button -->
          <button type="button" class="btn btn-danger btn-sm" id="btnDeleteResource" onclick="onDeleteResourceClick()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            Delete
          </button>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Cancel Edit Button (only in edit mode) -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCancelEditResource" onclick="cancelResourceEditMode()" style="display:none;">
            Cancel
          </button>

          <!-- Edit Button (in view mode) -->
          <button type="button" class="btn btn-primary btn-sm" id="btnToggleEditResource" onclick="enableResourceEditMode()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit
          </button>

          <!-- Save Button (only in edit mode) -->
          <button type="submit" class="btn btn-primary btn-sm" id="btnSaveResource" style="display:none;">
            Save Changes
          </button>

          <!-- Close Button -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCloseViewModal" onclick="closeModal('viewResourceModal')">
            Close
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Submit Requisition Request to ICDRRMO                            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="requestResourceModal">
  <form class="modal-dialog" id="requestResourceForm" method="POST"
    action="<?= BASE_URL ?>/backend/functions/resources/request.php">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Submit Resource
          Requisition to ICDRRMO</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">Request provisions and logistics from the City
          Central Depot</div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('requestResourceModal')">&times;</button>
    </div>

    <div class="modal-body">
      <div class="info-banner"
        style="background:#EFF6FF;border-left:4px solid var(--color-primary);padding:10px 14px;border-radius:6px;font-size:11.5px;color:#1E3A8A;margin-bottom:14px;line-height:1.45;">
        Submitted requisitions directly link to <strong>ICDRRMO Central Logistics</strong>. Available quantities reflect
        central depot inventory in real-time. Once accepted by the admin, stock is automatically deducted from Central
        Depot and credited to this barangay's local stock.
      </div>

      <!-- Live Depot Stock Card (Appears upon selecting commodity) -->
      <div id="centralDepotInfoBox"
        style="display:none;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:8px;padding:12px 16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
          <div>
            <span
              style="font-size:9.5px;text-transform:uppercase;font-weight:700;color:var(--color-primary);letter-spacing:0.5px;">ICDRRMO
              Central Depot Live Stock</span>
            <div id="boxDepotItemName" style="font-weight:700;font-size:13.5px;color:#0F172A;margin-top:2px;"></div>
            <div id="boxDepotLocation" style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">Central
              ICDRRMO Depot</div>
          </div>
          <div style="text-align:right;">
            <div id="boxDepotStockNum"
              style="font-size:20px;font-weight:800;color:var(--color-success);font-family:var(--font-secondary);line-height:1.1;">
              0</div>
            <span id="boxDepotBadge" class="badge badge-success" style="font-size:9px;margin-top:2px;">In Stock</span>
          </div>
        </div>
        <div id="boxDepotWarning"
          style="display:none;margin-top:10px;font-size:11px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;border-radius:6px;padding:8px 12px;font-weight:600;display:flex;align-items:center;gap:6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <span id="boxDepotWarningText">Exceeds available Central Depot inventory.</span>
        </div>
      </div>

      <!-- Select from Catalog or Custom -->
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label form-label-required"
          style="display:flex;justify-content:space-between;align-items:center;">
          <span>Select ICDRRMO Central Commodity</span>
          <span style="font-size:9.5px;color:var(--color-text-muted);font-weight:normal;">Real-time stock synced</span>
        </label>
        <select name="resource_id" id="req_central_resource_id" class="form-control"
          onchange="onSelectCentralResource(this)">
          <option value="">-- Choose from ICDRRMO Catalog (or specify custom item below) --</option>
          <?php foreach ($centralCatalog as $catItem): ?>
            <?php
            $isOutOfStock = ((int) $catItem['available_quantity'] <= 0);
            $isLow = ((int) $catItem['available_quantity'] <= (int) ($catItem['min_threshold'] ?? 50));
            ?>
            <option value="<?= $catItem['id'] ?>" data-name="<?= htmlspecialchars($catItem['name']) ?>"
              data-category="<?= htmlspecialchars($catItem['category']) ?>"
              data-unit="<?= htmlspecialchars($catItem['unit']) ?>"
              data-avail="<?= (int) $catItem['available_quantity'] ?>"
              data-location="<?= htmlspecialchars($catItem['storage_location'] ?? 'Central ICDRRMO Depot') ?>"
              <?= $isOutOfStock ? 'disabled style="color:#94A3B8;background:#F8FAFC;"' : '' ?>>
              [<?= clean($catItem['category']) ?>] <?= clean($catItem['name']) ?> —
              <?= number_format($catItem['available_quantity']) ?>   <?= clean($catItem['unit']) ?> available
              <?= $isOutOfStock ? '(DEPOT DEPLETED)' : ($isLow ? '(LOW STOCK)' : '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Item Name / Commodity Title</label>
          <input type="text" name="item_name" id="req_item_name" class="form-control"
            placeholder="e.g. Standard Family Food Pack (3-day ration)" required>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Commodity Category</label>
          <select name="category" id="req_category" class="form-control" required>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat ?>"><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required"
            style="display:flex;justify-content:space-between;align-items:center;">
            <span>Requested Quantity</span>
            <span id="reqQuantityMaxHint" style="font-size:9.5px;color:var(--color-primary);font-weight:600;"></span>
          </label>
          <input type="number" name="requested_quantity" id="req_quantity" class="form-control" min="1" value="50"
            required oninput="validateRequisitionQuantity()">
          <div id="depotStockAlert" style="font-size:10px;margin-top:3px;font-weight:500;"></div>
        </div>
        <div class="form-group">
          <label class="form-label form-label-required">Unit of Measure</label>
          <input type="text" name="unit" id="req_unit" class="form-control" placeholder="e.g. boxes, packs, pcs"
            value="packs" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label form-label-required">Urgency Level</label>
          <select name="urgency" class="form-control" required>
            <option value="Immediate">Immediate (Active Incident / Critical Need)</option>
            <option value="High" selected>High (Pre-emptive Evacuation / Forecast Alert)</option>
            <option value="Medium">Medium (Inventory Replenishment)</option>
            <option value="Low">Low (General Preparedness)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Target Purok / Evacuation Site</label>
          <select name="target_purok" class="form-control">
            <option value="">Barangay-wide (General Hall Depot)</option>
            <?php foreach ($localPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label form-label-required">Purpose & Operational Justification</label>
        <textarea name="purpose" id="req_purpose" class="form-control" rows="3"
          placeholder="Provide background justification for this request (e.g. Pre-positioning relief ahead of storm surge warning for Purok Riverside families)..."
          required></textarea>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('requestResourceModal')">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitRequisition">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          style="margin-right:4px;">
          <line x1="22" y1="2" x2="11" y2="13"></line>
          <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
        </svg>
        Submit Requisition to ICDRRMO
      </button>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 4: View Requisition Details Modal                                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewRequestModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewReqStatusBadge" class="badge"></span>
        <span id="viewReqCode"
          style="font-family:var(--font-secondary);font-size:12px;font-weight:700;color:var(--color-primary);"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewRequestModal')">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Requisition Overview -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);margin-bottom:12px;">
        <div id="viewReqItemName" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;">
        </div>
        <div id="viewReqMeta" style="font-size:10.5px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div style="background:#F8FAFC;padding:10px;border-radius:6px;border:1px solid var(--color-border-light);">
          <div style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:600;">Requested
            Quantity</div>
          <div id="viewReqQty" style="font-size:15px;font-weight:700;color:var(--color-primary);margin-top:2px;"></div>
        </div>
        <div style="background:#F8FAFC;padding:10px;border-radius:6px;border:1px solid var(--color-border-light);">
          <div style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:600;">Approved
            Quantity</div>
          <div id="viewReqApprovedQty"
            style="font-size:15px;font-weight:700;color:var(--color-success);margin-top:2px;">—</div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Urgency Level</label>
          <div id="viewReqUrgency" style="font-size:11.5px;font-weight:600;padding:6px 0;"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Target Purok / Zone</label>
          <div id="viewReqPurok" style="font-size:11.5px;font-weight:600;padding:6px 0;"></div>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Operational Purpose & Justification</label>
        <div id="viewReqPurpose"
          style="font-size:11.5px;color:var(--color-text);background:#F8FAFC;padding:10px;border-radius:6px;border:1px solid var(--color-border-light);line-height:1.5;">
        </div>
      </div>

      <!-- Review Section -->
      <div id="viewReqReviewSection"
        style="display:none;background:#F0FDF4;border:1px solid #BBF7D0;padding:12px;border-radius:6px;margin-bottom:10px;">
        <div style="font-size:11px;font-weight:700;color:#166534;margin-bottom:4px;" id="viewReqReviewTitle">ICDRRMO
          Review Decision</div>
        <div id="viewReqReviewRemarks" style="font-size:11px;color:#15803D;line-height:1.4;"></div>
        <div id="viewReqReviewedBy" style="font-size:9.5px;color:#166534;margin-top:6px;"></div>
      </div>
    </div>

    <div class="modal-footer" style="justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewRequestModal')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 5: Confirmation Modal                                               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="confirmationModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="confirmModalTitle">Confirm Action</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('confirmationModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want
        to proceed?</p>
    </div>
    <div class="modal-footer" style="justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<script>
  // ============================================================================
  // Modal & Interaction Logic for Barangay Head Manage Resources
  // ============================================================================

  let currentResourceData = null;

  function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
  }

  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  }

  // Close modals on backdrop click or Escape key
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal-overlay').forEach(modal => {
      modal.addEventListener('click', function (e) {
        if (e.target === this) closeModal(this.id);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
      }
    });

    // Enter/Escape keyboard handling for dynamic add resource name inputs
    ['create', 'view'].forEach(mode => {
      const input = document.getElementById('newResourceInput_' + mode);
      if (input) {
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            confirmDynamicAddResource(mode);
          } else if (e.key === 'Escape') {
            toggleAddResourceInput(mode);
          }
        });
      }

      ['category', 'brand', 'supplier'].forEach(field => {
        const fieldInput = document.getElementById(`new_${field}_input_${mode}`);
        if (fieldInput) {
          fieldInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              confirmDynamicField(field, mode);
            } else if (e.key === 'Escape') {
              toggleDynamicField(field, mode);
            }
          });
        }
      });
    });
  });

  // Universal Dynamic Field (Category, Brand, Supplier) Handlers
  function toggleDynamicField(field, mode) {
    const container = document.getElementById(`dynAddContainer_${field}_${mode}`);
    const input = document.getElementById(`new_${field}_input_${mode}`);
    if (!container) return;

    if (container.style.display === 'none' || container.style.display === '') {
      container.style.display = 'block';
      if (input) {
        input.value = '';
        setTimeout(() => input.focus(), 60);
      }
    } else {
      container.style.display = 'none';
    }
  }

  async function confirmDynamicField(field, mode) {
    const input = document.getElementById(`new_${field}_input_${mode}`);
    if (!input) return;
    const val = input.value.trim();
    if (!val) {
      alert(`Please enter a valid ${field} name.`);
      input.focus();
      return;
    }

    // Persist permanently into dedicated database table so it survives page reloads and reboots
    try {
      const formData = new FormData();
      formData.append('type', field);
      formData.append('name', val);
      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/add_attribute_option.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success && !data.already_exists && typeof showToast === 'function') {
        showToast(data.message, 'success');
      }
    } catch (err) {
      console.error('Failed to persist option to database table:', err);
    }

    let createSelectId = '';
    let viewSelectId = '';
    if (field === 'category') {
      createSelectId = 'create_res_category';
      viewSelectId = 'view_res_category';
    } else if (field === 'brand') {
      createSelectId = 'create_res_brand';
      viewSelectId = 'view_res_brand';
    } else if (field === 'supplier') {
      createSelectId = 'create_res_supplier';
      viewSelectId = 'view_res_supplier_donor';
    }

    // Ensure option is added to both create and view dropdowns
    ['create', 'view'].forEach(m => {
      const selectId = (m === 'create') ? createSelectId : viewSelectId;
      const select = document.getElementById(selectId);
      if (select) {
        let foundIndex = -1;
        for (let i = 0; i < select.options.length; i++) {
          if (select.options[i].value.toLowerCase() === val.toLowerCase()) {
            foundIndex = i;
            break;
          }
        }
        if (foundIndex >= 0) {
          if (m === mode) select.selectedIndex = foundIndex;
        } else {
          const opt = document.createElement('option');
          opt.value = val;
          opt.textContent = val;
          select.appendChild(opt);
          if (m === mode) select.value = val;
        }
      }
    });

    const activeSelect = document.getElementById((mode === 'create') ? createSelectId : viewSelectId);
    if (activeSelect) activeSelect.value = val;

    input.value = '';
    const container = document.getElementById(`dynAddContainer_${field}_${mode}`);
    if (container) container.style.display = 'none';
  }

  // Dynamic Resource Name Addition Handlers
  function toggleAddResourceInput(mode) {
    const container = document.getElementById('dynamicAddContainer_' + mode);
    const input = document.getElementById('newResourceInput_' + mode);
    if (!container) return;

    if (container.style.display === 'none' || container.style.display === '') {
      container.style.display = 'block';
      if (input) {
        input.value = '';
        setTimeout(() => input.focus(), 60);
      }
    } else {
      container.style.display = 'none';
    }
  }

  function confirmDynamicAddResource(mode) {
    const input = document.getElementById('newResourceInput_' + mode);
    if (!input) return;
    const name = input.value.trim();
    if (!name) {
      alert('Please enter a resource name.');
      input.focus();
      return;
    }

    // Ensure option is present in both create and view selects
    ['create', 'view'].forEach(m => {
      const select = (m === 'create') ? document.getElementById('create_res_name') : document.getElementById('view_res_name');
      if (select) {
        let foundIndex = -1;
        for (let i = 0; i < select.options.length; i++) {
          if (select.options[i].value.toLowerCase() === name.toLowerCase()) {
            foundIndex = i;
            break;
          }
        }
        if (foundIndex >= 0) {
          if (m === mode) select.selectedIndex = foundIndex;
        } else {
          const opt = document.createElement('option');
          opt.value = name;
          opt.textContent = name;
          select.appendChild(opt);
          if (m === mode) select.value = name;
        }
      }
    });

    const activeSelect = (mode === 'create') ? document.getElementById('create_res_name') : document.getElementById('view_res_name');
    if (activeSelect) {
      activeSelect.value = name;
      onSelectResourceName(activeSelect, mode);
    }

    input.value = '';
    const container = document.getElementById('dynamicAddContainer_' + mode);
    if (container) container.style.display = 'none';
  }

  function onSelectResourceName(sel, mode) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const category = opt.getAttribute('data-category');
    const unit = opt.getAttribute('data-unit');
    const brand = opt.getAttribute('data-brand');

    if (mode === 'create') {
      const form = document.getElementById('createResourceForm');
      if (form) {
        if (category && form.querySelector('[name="category"]')) {
          form.querySelector('[name="category"]').value = category;
        }
        if (unit && form.querySelector('[name="unit"]')) {
          form.querySelector('[name="unit"]').value = unit;
        }
        if (brand && form.querySelector('[name="brand"]') && !form.querySelector('[name="brand"]').value) {
          form.querySelector('[name="brand"]').value = brand;
        }
      }
    }
  }

  // 1. Open Create Resource Modal
  function openCreateResourceModal() {
    const form = document.getElementById('createResourceForm');
    if (form) form.reset();
    ['category', 'brand', 'supplier'].forEach(f => {
      const c = document.getElementById(`dynAddContainer_${f}_create`);
      if (c) c.style.display = 'none';
    });
    openModal('createResourceModal');
  }

  async function handleCreateResource(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSubmitCreateResource');
    btn.disabled = true;
    btn.innerText = 'Adding...';

    const formData = new FormData(form);
    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/create.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        } else {
          alert(data.message);
        }
        closeModal('createResourceModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error adding resource.', 'danger');
        } else {
          alert(data.message || 'Error adding resource.');
        }
        btn.disabled = false;
        btn.innerText = 'Add Resource';
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network or server error.', 'danger');
      } else {
        alert('Network or server error.');
      }
      btn.disabled = false;
      btn.innerText = 'Add Resource';
    }
  }

  // 2. Open Request Supplies Modal
  let selectedCentralResourceMax = null;

  function openRequestResourceModal() {
    const form = document.getElementById('requestResourceForm');
    if (form) {
      form.reset();
    }
    selectedCentralResourceMax = null;
    const infoBox = document.getElementById('centralDepotInfoBox');
    if (infoBox) infoBox.style.display = 'none';
    const alertEl = document.getElementById('depotStockAlert');
    if (alertEl) alertEl.innerText = '';
    const hintEl = document.getElementById('reqQuantityMaxHint');
    if (hintEl) hintEl.innerText = '';
    const submitBtn = document.getElementById('btnSubmitRequisition');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Submit Requisition to ICDRRMO';
    }

    // Refresh latest catalog data in background
    fetch('<?= BASE_URL ?>/backend/functions/resources/get_catalog.php')
      .then(r => r.json())
      .then(data => {
        if (data.success && data.catalog) {
          updateCentralCatalogDropdown(data.catalog);
        }
      })
      .catch(() => { });

    openModal('requestResourceModal');
  }

  function updateCentralCatalogDropdown(items) {
    const sel = document.getElementById('req_central_resource_id');
    if (!sel) return;
    const currentVal = sel.value;

    sel.innerHTML = '<option value="">-- Choose from ICDRRMO Catalog (or specify custom item below) --</option>';
    items.forEach(item => {
      const isOut = parseInt(item.available_quantity) <= 0;
      const isLow = parseInt(item.available_quantity) <= parseInt(item.min_threshold || 50);
      const opt = document.createElement('option');
      opt.value = item.id;
      opt.setAttribute('data-name', item.name);
      opt.setAttribute('data-category', item.category);
      opt.setAttribute('data-unit', item.unit);
      opt.setAttribute('data-avail', item.available_quantity);
      opt.setAttribute('data-location', item.storage_location || 'Central ICDRRMO Depot');

      if (isOut) {
        opt.disabled = true;
        opt.style.color = '#94A3B8';
      }
      opt.textContent = `[${item.category}] ${item.name} — ${parseInt(item.available_quantity).toLocaleString()} ${item.unit} available ${isOut ? '(DEPOT DEPLETED)' : (isLow ? '(LOW STOCK)' : '')}`;
      sel.appendChild(opt);
    });
    if (currentVal) sel.value = currentVal;
  }

  // Auto-fill item details and show live depot stock card
  function onSelectCentralResource(sel) {
    const opt = sel.options[sel.selectedIndex];
    const infoBox = document.getElementById('centralDepotInfoBox');
    const alertEl = document.getElementById('depotStockAlert');
    const hintEl = document.getElementById('reqQuantityMaxHint');

    if (!opt || !opt.value) {
      selectedCentralResourceMax = null;
      if (infoBox) infoBox.style.display = 'none';
      if (alertEl) alertEl.innerText = '';
      if (hintEl) hintEl.innerText = '';
      validateRequisitionQuantity();
      return;
    }

    const name = opt.getAttribute('data-name');
    const cat = opt.getAttribute('data-category');
    const unit = opt.getAttribute('data-unit');
    const avail = parseInt(opt.getAttribute('data-avail') || 0);
    const location = opt.getAttribute('data-location') || 'Central ICDRRMO Depot';

    selectedCentralResourceMax = avail;

    if (name) document.getElementById('req_item_name').value = name;
    if (cat) document.getElementById('req_category').value = cat;
    if (unit) document.getElementById('req_unit').value = unit;

    // Set quantity input max
    const qtyInput = document.getElementById('req_quantity');
    if (qtyInput) {
      qtyInput.max = avail;
      if (avail > 0 && parseInt(qtyInput.value) > avail) {
        qtyInput.value = avail;
      } else if (avail > 0 && (!qtyInput.value || parseInt(qtyInput.value) <= 0)) {
        qtyInput.value = Math.min(50, avail);
      }
    }

    // Populate Live Stock Info Card
    if (infoBox) {
      infoBox.style.display = 'block';
      document.getElementById('boxDepotItemName').innerText = name;
      document.getElementById('boxDepotLocation').innerText = `Location: ${location}`;
      document.getElementById('boxDepotStockNum').innerText = `${avail.toLocaleString()} ${unit}`;

      const badge = document.getElementById('boxDepotBadge');
      if (avail <= 0) {
        badge.className = 'badge badge-danger';
        badge.innerText = 'Out of Stock';
        document.getElementById('boxDepotStockNum').style.color = 'var(--color-danger)';
      } else if (avail < 50) {
        badge.className = 'badge badge-warning';
        badge.innerText = 'Limited Depot Stock';
        document.getElementById('boxDepotStockNum').style.color = '#D97706';
      } else {
        badge.className = 'badge badge-success';
        badge.innerText = 'Sufficient In Stock';
        document.getElementById('boxDepotStockNum').style.color = 'var(--color-success)';
      }
    }

    if (hintEl) {
      hintEl.innerText = `Max available: ${avail.toLocaleString()} ${unit}`;
    }

    validateRequisitionQuantity();
  }

  // Live validation for requested quantity against available depot stock
  function validateRequisitionQuantity() {
    const qtyInput = document.getElementById('req_quantity');
    const submitBtn = document.getElementById('btnSubmitRequisition');
    const warningBox = document.getElementById('boxDepotWarning');
    const warningText = document.getElementById('boxDepotWarningText');
    const alertEl = document.getElementById('depotStockAlert');

    if (!qtyInput) return;
    const qty = parseInt(qtyInput.value) || 0;

    if (qty <= 0) {
      if (alertEl) {
        alertEl.style.color = 'var(--color-danger)';
        alertEl.innerText = '⚠ Requested quantity must be at least 1.';
      }
      if (warningBox) warningBox.style.display = 'none';
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    if (selectedCentralResourceMax !== null) {
      if (selectedCentralResourceMax <= 0) {
        if (warningBox) {
          warningBox.style.display = 'flex';
          warningText.innerText = 'Selected commodity is depleted at ICDRRMO Depot. Please choose another commodity.';
        }
        if (alertEl) {
          alertEl.style.color = 'var(--color-danger)';
          alertEl.innerText = '⚠ Item depleted at central depot.';
        }
        if (submitBtn) submitBtn.disabled = true;
        return;
      }

      if (qty > selectedCentralResourceMax) {
        if (warningBox) {
          warningBox.style.display = 'flex';
          warningText.innerText = `Cannot request ${qty}. Only ${selectedCentralResourceMax.toLocaleString()} units available at Central Depot.`;
        }
        if (alertEl) {
          alertEl.style.color = 'var(--color-danger)';
          alertEl.innerText = `⚠ Exceeds depot available stock (Max: ${selectedCentralResourceMax.toLocaleString()}).`;
        }
        if (submitBtn) submitBtn.disabled = true;
        return;
      }

      // Valid quantity within stock
      if (warningBox) warningBox.style.display = 'none';
      if (alertEl) {
        alertEl.style.color = 'var(--color-success)';
        alertEl.innerText = `✓ Within depot available stock (${selectedCentralResourceMax.toLocaleString()} available).`;
      }
      if (submitBtn) submitBtn.disabled = false;
    } else {
      // Custom item not bound to central catalog
      if (warningBox) warningBox.style.display = 'none';
      if (alertEl) alertEl.innerText = '';
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  // AJAX Submission for Resource Requisition
  document.addEventListener('DOMContentLoaded', function () {
    const reqForm = document.getElementById('requestResourceForm');
    if (reqForm) {
      reqForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const submitBtn = document.getElementById('btnSubmitRequisition');
        const origHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner" style="display:inline-block;width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;margin-right:6px;vertical-align:-1px;"></span> Submitting to ICDRRMO...';

        try {
          const formData = new FormData(reqForm);
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/request.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();

          if (data.success) {
            showToast(data.message, 'success');
            closeModal('requestResourceModal');
            // Navigate dynamically to the Supply Requisitions tab
            setTimeout(() => {
              window.location.href = '<?= BASE_URL ?>/views/barangay/resources.php?tab=requests';
            }, 600);
          } else {
            showToast(data.message || 'Failed to submit requisition.', 'danger');
            submitBtn.disabled = false;
            submitBtn.innerHTML = origHtml;
          }
        } catch (err) {
          console.error(err);
          showToast('Network error while submitting requisition to ICDRRMO.', 'danger');
          submitBtn.disabled = false;
          submitBtn.innerHTML = origHtml;
        }
      });
    }
  });

  // 3. View / Edit Resource Modal Handlers (1:1 with ICDRRMO)
  let currentResource = null;
  let isResourceEditMode = false;

  function openViewResourceModal(r) {
    currentResource = r;
    currentResourceData = r;
    isResourceEditMode = false;

    // Basic fields
    document.getElementById('view_res_id').value = r.id || '';
    document.getElementById('view_res_title').innerText = r.name || 'Resource Details';
    document.getElementById('view_res_code').value = r.code || '';

    // Set Category select (ensure option exists dynamically)
    const catSelect = document.getElementById('view_res_category');
    if (catSelect) {
      if (r.category) {
        let exists = false;
        for (let i = 0; i < catSelect.options.length; i++) {
          if (catSelect.options[i].value.toLowerCase() === r.category.toLowerCase()) {
            exists = true;
            catSelect.selectedIndex = i;
            break;
          }
        }
        if (!exists) {
          const opt = document.createElement('option');
          opt.value = r.category;
          opt.textContent = r.category;
          catSelect.appendChild(opt);
          catSelect.value = r.category;
        }
      } else {
        catSelect.value = '';
      }
    }

    document.getElementById('view_res_name').value = r.name || '';
    document.getElementById('view_res_unit').value = r.unit || '';
    document.getElementById('view_res_min_threshold').value = r.min_threshold || 50;
    document.getElementById('view_res_available_quantity').value = r.available_quantity || 0;
    document.getElementById('view_res_in_use_quantity').value = r.in_use_quantity || 0;
    document.getElementById('view_res_damaged_quantity').value = r.damaged_quantity || 0;
    document.getElementById('view_res_storage_location').value = r.storage_location || '';
    document.getElementById('view_res_description').value = r.description || '';

    // Set Brand select (ensure option exists dynamically)
    const brandSelect = document.getElementById('view_res_brand');
    if (brandSelect) {
      if (r.brand) {
        let exists = false;
        for (let i = 0; i < brandSelect.options.length; i++) {
          if (brandSelect.options[i].value.toLowerCase() === r.brand.toLowerCase()) {
            exists = true;
            brandSelect.selectedIndex = i;
            break;
          }
        }
        if (!exists) {
          const opt = document.createElement('option');
          opt.value = r.brand;
          opt.textContent = r.brand;
          brandSelect.appendChild(opt);
          brandSelect.value = r.brand;
        }
      } else {
        brandSelect.value = '';
      }
    }

    document.getElementById('view_res_item_condition').value = r.item_condition || 'New';
    document.getElementById('view_res_weight_per_unit').value = r.weight_per_unit || '';
    document.getElementById('view_res_cost_per_unit').value = r.cost_per_unit || '';
    document.getElementById('view_res_date_acquired').value = r.date_acquired || '';
    document.getElementById('view_res_expiry_date').value = r.expiry_date || '';
    document.getElementById('view_res_is_perishable').checked = (r.is_perishable == 1);

    // Set Supplier select (ensure option exists dynamically)
    const suppSelect = document.getElementById('view_res_supplier_donor');
    if (suppSelect) {
      if (r.supplier_donor) {
        let exists = false;
        for (let i = 0; i < suppSelect.options.length; i++) {
          if (suppSelect.options[i].value.toLowerCase() === r.supplier_donor.toLowerCase()) {
            exists = true;
            suppSelect.selectedIndex = i;
            break;
          }
        }
        if (!exists) {
          const opt = document.createElement('option');
          opt.value = r.supplier_donor;
          opt.textContent = r.supplier_donor;
          suppSelect.appendChild(opt);
          suppSelect.value = r.supplier_donor;
        }
      } else {
        suppSelect.value = '';
      }
    }

    document.getElementById('view_res_batch_number').value = r.batch_number || '';

    // Badges in summary metrics bar
    document.getElementById('badge_res_available').innerText = (r.available_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_in_use').innerText = (r.in_use_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_damaged').innerText = (r.damaged_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_total').innerText = (r.total_quantity || 0) + ' ' + (r.unit || '');

    // Condition badge
    const condBadge = document.getElementById('view_res_condition_badge');
    if (condBadge) {
      const condColors = { 'New': 'badge-success', 'Good': 'badge-info', 'Fair': 'badge-warning', 'Poor': 'badge-danger', 'Expired': 'badge-danger' };
      condBadge.className = 'badge ' + (condColors[r.item_condition] || 'badge-neutral');
      condBadge.innerText = r.item_condition || 'New';
    }

    // Expiry alert
    const expiryAlert = document.getElementById('view_res_expiry_alert');
    if (expiryAlert) {
      if (r.expiry_date) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const expDate = new Date(r.expiry_date + 'T00:00:00');
        const diffDays = Math.ceil((expDate - today) / (1000 * 60 * 60 * 24));

        if (diffDays < 0) {
          expiryAlert.style.display = 'flex';
          expiryAlert.style.background = '#FEE2E2';
          expiryAlert.style.border = '1px solid #FECACA';
          expiryAlert.style.color = '#991B1B';
          expiryAlert.innerHTML = '⚠️ <strong>EXPIRED:</strong> This item expired on ' + r.expiry_date + ' (' + Math.abs(diffDays) + ' days ago). Consider replenishment or disposal.';
        } else if (diffDays <= 30) {
          expiryAlert.style.display = 'flex';
          expiryAlert.style.background = '#FEF3C7';
          expiryAlert.style.border = '1px solid #FDE68A';
          expiryAlert.style.color = '#92400E';
          expiryAlert.innerHTML = '⏳ <strong>Expiring Soon:</strong> This item expires on ' + r.expiry_date + ' (' + diffDays + ' days remaining). Plan for distribution.';
        } else {
          expiryAlert.style.display = 'none';
        }
      } else {
        expiryAlert.style.display = 'none';
      }
    }

    // Status badge
    const statusBadge = document.getElementById('view_res_status_badge');
    if (statusBadge) {
      const isArchived = (r.status === 'archived');
      if (isArchived) {
        statusBadge.className = 'badge badge-neutral';
        statusBadge.innerText = 'Archived';
      } else {
        statusBadge.className = 'badge badge-success';
        statusBadge.innerText = 'Active';
      }
    }

    cancelResourceEditMode();
    openModal('viewResourceModal');
  }

  // Backward compatibility alias
  function viewResourceDetails(res) {
    openViewResourceModal(res);
  }

  function enableResourceEditMode() {
    isResourceEditMode = true;
    setResourceFormInputsDisabled(false);

    document.getElementById('view_res_edit_banner').style.display = 'flex';
    document.getElementById('btnToggleEditResource').style.display = 'none';
    document.getElementById('btnCloseViewModal').style.display = 'none';
    document.getElementById('btnSaveResource').style.display = 'inline-flex';
    document.getElementById('btnCancelEditResource').style.display = 'inline-flex';

    document.getElementById('view_res_name').focus();
  }

  function cancelResourceEditMode() {
    isResourceEditMode = false;
    if (currentResource) {
      const r = currentResource;
      document.getElementById('view_res_code').value = r.code || '';
      const catSelect = document.getElementById('view_res_category');
      if (catSelect) catSelect.value = r.category || 'Relief Goods';
      document.getElementById('view_res_name').value = r.name || '';
      document.getElementById('view_res_unit').value = r.unit || '';
      document.getElementById('view_res_min_threshold').value = r.min_threshold || 50;
      document.getElementById('view_res_available_quantity').value = r.available_quantity || 0;
      document.getElementById('view_res_in_use_quantity').value = r.in_use_quantity || 0;
      document.getElementById('view_res_damaged_quantity').value = r.damaged_quantity || 0;
      document.getElementById('view_res_storage_location').value = r.storage_location || '';
      document.getElementById('view_res_description').value = r.description || '';
      const brandSelect = document.getElementById('view_res_brand');
      if (brandSelect) brandSelect.value = r.brand || '';
      document.getElementById('view_res_item_condition').value = r.item_condition || 'New';
      document.getElementById('view_res_weight_per_unit').value = r.weight_per_unit || '';
      document.getElementById('view_res_cost_per_unit').value = r.cost_per_unit || '';
      document.getElementById('view_res_date_acquired').value = r.date_acquired || '';
      document.getElementById('view_res_expiry_date').value = r.expiry_date || '';
      document.getElementById('view_res_is_perishable').checked = (r.is_perishable == 1);
      const suppSelect = document.getElementById('view_res_supplier_donor');
      if (suppSelect) suppSelect.value = r.supplier_donor || '';
      document.getElementById('view_res_batch_number').value = r.batch_number || '';
    }

    ['category', 'brand', 'supplier'].forEach(f => {
      const c = document.getElementById(`dynAddContainer_${f}_view`);
      if (c) c.style.display = 'none';
    });

    setResourceFormInputsDisabled(true);
    document.getElementById('view_res_edit_banner').style.display = 'none';
    document.getElementById('btnToggleEditResource').style.display = 'inline-flex';
    document.getElementById('btnCloseViewModal').style.display = 'inline-flex';
    document.getElementById('btnSaveResource').style.display = 'none';
    document.getElementById('btnCancelEditResource').style.display = 'none';
  }

  function setResourceFormInputsDisabled(disabled) {
    const fields = [
      'view_res_code', 'view_res_category', 'view_res_name', 'view_res_unit',
      'view_res_min_threshold', 'view_res_available_quantity', 'view_res_in_use_quantity',
      'view_res_damaged_quantity', 'view_res_storage_location', 'view_res_description',
      'view_res_brand', 'view_res_item_condition', 'view_res_weight_per_unit',
      'view_res_cost_per_unit', 'view_res_date_acquired', 'view_res_expiry_date',
      'view_res_is_perishable', 'view_res_supplier_donor', 'view_res_batch_number'
    ];
    fields.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = disabled;
    });

    ['category', 'brand', 'supplier'].forEach(f => {
      const btn = document.getElementById(`btnAdd_${f}_view`);
      if (btn) btn.disabled = disabled;
    });
  }

  async function handleUpdateResource(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSaveResource');
    btn.disabled = true;
    btn.innerText = 'Saving...';

    setResourceFormInputsDisabled(false);
    const formData = new FormData(form);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/update.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        } else {
          alert(data.message);
        }
        closeModal('viewResourceModal');
        setTimeout(() => location.reload(), 700);
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error updating resource.', 'danger');
        } else {
          alert(data.message || 'Error updating resource.');
        }
        btn.disabled = false;
        btn.innerText = 'Save Changes';
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network or server error.', 'danger');
      } else {
        alert('Network or server error.');
      }
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  }

  function showConfirmModal(title, message, onConfirm) {
    const modal = document.getElementById('confirmationModal');
    if (!modal) return;
    document.getElementById('confirmModalTitle').innerText = title;
    document.getElementById('confirmModalMessage').innerHTML = message;
    const btn = document.getElementById('confirmModalSubmitBtn');
    btn.className = 'btn btn-danger btn-sm';
    btn.innerText = 'Delete Resource';
    btn.onclick = function () {
      closeModal('confirmationModal');
      if (typeof onConfirm === 'function') onConfirm();
    };
    openModal('confirmationModal');
  }

  function onDeleteResourceClick() {
    if (!currentResource || !currentResource.id) {
      if (typeof showToast === 'function') {
        showToast('No resource selected.', 'warning');
      } else {
        alert('No resource selected.');
      }
      return;
    }
    const r = currentResource;
    showConfirmModal(
      'Confirm Permanent Deletion',
      `Are you sure you want to permanently delete Resource [${r.code}] ${r.name}? This operation cannot be undone.`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', r.id);
          formData.append('return_url', window.location.href);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/delete.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            if (typeof showToast === 'function') {
              showToast(data.message, 'success');
            } else {
              alert(data.message);
            }
            closeModal('viewResourceModal');
            setTimeout(() => location.reload(), 700);
          } else {
            if (typeof showToast === 'function') {
              showToast(data.message || 'Deletion failed.', 'danger');
            } else {
              alert(data.message || 'Deletion failed.');
            }
          }
        } catch (err) {
          if (typeof showToast === 'function') {
            showToast('Network error while deleting resource.', 'danger');
          } else {
            alert('Network error while deleting resource.');
          }
        }
      }
    );
  }

  // 5. View Requisition Details
  function viewRequisitionDetails(req) {
    document.getElementById('viewReqCode').innerText = req.request_code;
    document.getElementById('viewReqItemName').innerText = req.item_name;
    document.getElementById('viewReqMeta').innerText = `Category: ${req.category} • Requested by: ${req.requested_by_name || 'Barangay Head'} on ${req.created_at}`;

    document.getElementById('viewReqQty').innerText = `${parseInt(req.requested_quantity).toLocaleString()} ${req.unit}`;

    if (req.status === 'Approved' && req.approved_quantity) {
      document.getElementById('viewReqApprovedQty').innerText = `${parseInt(req.approved_quantity).toLocaleString()} ${req.unit}`;
      document.getElementById('viewReqApprovedQty').style.color = 'var(--color-success)';
    } else if (req.status === 'Rejected') {
      document.getElementById('viewReqApprovedQty').innerText = '0 (Rejected)';
      document.getElementById('viewReqApprovedQty').style.color = 'var(--color-danger)';
    } else {
      document.getElementById('viewReqApprovedQty').innerText = 'Pending Review';
      document.getElementById('viewReqApprovedQty').style.color = 'var(--color-text-muted)';
    }

    // Urgency
    document.getElementById('viewReqUrgency').innerText = req.urgency;
    document.getElementById('viewReqPurok').innerText = req.target_purok || 'Barangay-wide Hall Depot';
    document.getElementById('viewReqPurpose').innerText = req.purpose || 'No purpose notes provided.';

    // Status Badge
    const b = document.getElementById('viewReqStatusBadge');
    if (req.status === 'Approved') {
      b.className = 'badge badge-success';
      b.innerText = 'Approved';
    } else if (req.status === 'Rejected') {
      b.className = 'badge badge-danger';
      b.innerText = 'Rejected';
    } else {
      b.className = 'badge badge-warning';
      b.innerText = 'Pending Review';
    }

    // Review section
    const revSec = document.getElementById('viewReqReviewSection');
    if (req.status === 'Approved' || req.status === 'Rejected') {
      revSec.style.display = 'block';
      if (req.status === 'Approved') {
        revSec.style.background = '#F0FDF4';
        revSec.style.borderColor = '#BBF7D0';
        document.getElementById('viewReqReviewTitle').innerText = 'ICDRRMO Approval & Dispatch Notes';
        document.getElementById('viewReqReviewTitle').style.color = '#166534';
        document.getElementById('viewReqReviewRemarks').style.color = '#15803D';
      } else {
        revSec.style.background = '#FEF2F2';
        revSec.style.borderColor = '#FECACA';
        document.getElementById('viewReqReviewTitle').innerText = 'ICDRRMO Rejection Notice';
        document.getElementById('viewReqReviewTitle').style.color = '#991B1B';
        document.getElementById('viewReqReviewRemarks').style.color = '#B91C1C';
      }
      document.getElementById('viewReqReviewRemarks').innerText = req.review_remarks || '(No remarks provided)';
      document.getElementById('viewReqReviewedBy').innerText = `Reviewed by ${req.reviewed_by_name || 'ICDRRMO Admin'} on ${req.reviewed_at || ''}`;
    } else {
      revSec.style.display = 'none';
    }

    openModal('viewRequestModal');
  }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>