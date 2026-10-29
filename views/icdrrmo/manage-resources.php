<?php
// ============================================================================
// Views (ICDRRMO): Manage Resources Module
// Complete CRUD: Add, View, Edit, Delete, and Archive with Audit Tracking
// Full detailed resource information including expiry, supplier, condition, etc.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

$activeTab = in_array($_GET['tab'] ?? '', ['active', 'archived', 'allocations', 'movements', 'requests', 'barangays']) ? $_GET['tab'] : ((isset($_GET['barangay_id']) && $_GET['barangay_id'] !== '') ? 'barangays' : 'active');

$moduleTitles = [
    'active' => [
        'title' => 'Active Supplies',
        'desc' => 'Central depot emergency provisions, inventory levels, and cataloged commodities.'
    ],
    'allocations' => [
        'title' => 'Supplies Allocated',
        'desc' => 'Relief item allocations, barangay distribution dispatches, and aid release tracking.'
    ],
    'requests' => [
        'title' => 'Supplies Requested',
        'desc' => 'Formal barangay disaster resource requests, relief requisitions, and review actions.'
    ],
    'archived' => [
        'title' => 'Archived Supplies',
        'desc' => 'Decommissioned, expired, or retired emergency inventory provisions.'
    ],
    'movements' => [
        'title' => 'Stock Movements & Audit Logs',
        'desc' => 'Transaction audit log of stock inflows, allocations, and inventory adjustments.'
    ],
    'barangays' => [
        'title' => 'Barangay Stock & History',
        'desc' => 'Comparative two-column tracker of barangay depot inventory and transfer logs.'
    ]
];
$currentModuleInfo = $moduleTitles[$activeTab] ?? $moduleTitles['active'];
$pageTitle = $currentModuleInfo['title'] . " — Manage Supplies";
require_once __DIR__ . '/../layouts/header.php';

// Filter parameters for resources
$filterCategory = $_GET['category'] ?? '';
$filterStockLevel = $_GET['stock'] ?? '';
$filterCondition = $_GET['condition'] ?? '';
$search = trim($_GET['search'] ?? '');

// Metrics (Calculated on active items)
$activeCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE status = 'active'")->fetchColumn();
$archivedCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE status = 'archived'")->fetchColumn();
$totalAllocatedQty = (int) $db->query("SELECT COALESCE(SUM(allocated_quantity), 0) FROM recommended_items WHERE status = 'Allocated'")->fetchColumn();
$totalAvailableQty = (int) $db->query("SELECT COALESCE(SUM(available_quantity), 0) FROM resources WHERE status = 'active'")->fetchColumn();
$lowStockCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE status = 'active' AND available_quantity <= min_threshold")->fetchColumn();
$expiringSoonCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()")->fetchColumn();
$expiredCount = (int) $db->query("SELECT COUNT(*) FROM resources WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();

// Fetch Resources for Active / Archived tab
$resStatus = ($activeTab === 'archived') ? 'archived' : 'active';
$resSql = "SELECT r.*, rs.name AS supplier_donor, rs.name AS supplier_name, rs.id AS supplier_id
           FROM resources r
           LEFT JOIN resource_suppliers rs ON r.supplier_id = rs.id
           WHERE r.status = ?";
$resParams = [$resStatus];

if (!empty($filterCategory)) {
  $resSql .= " AND r.category = ?";
  $resParams[] = $filterCategory;
}

if ($filterStockLevel === 'low') {
  $resSql .= " AND r.available_quantity <= r.min_threshold";
} elseif ($filterStockLevel === 'expiring') {
  $resSql .= " AND r.expiry_date IS NOT NULL AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND r.expiry_date >= CURDATE()";
} elseif ($filterStockLevel === 'expired') {
  $resSql .= " AND r.expiry_date IS NOT NULL AND r.expiry_date < CURDATE()";
}

if (!empty($filterCondition)) {
  $resSql .= " AND r.item_condition = ?";
  $resParams[] = $filterCondition;
}

if (!empty($search)) {
  $resSql .= " AND (r.code LIKE ? OR r.name LIKE ? OR rs.name LIKE ? OR r.brand LIKE ? OR r.batch_number LIKE ?)";
  $like = "%$search%";
  $resParams[] = $like;
  $resParams[] = $like;
  $resParams[] = $like;
  $resParams[] = $like;
  $resParams[] = $like;
}

$resSql .= " ORDER BY (r.available_quantity <= r.min_threshold) DESC, r.category ASC, r.name ASC";
$resStmt = $db->prepare($resSql);
$resStmt->execute($resParams);
$resourcesList = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// Beneficiary Barangays List (Strictly Hinaplanon)
$barangaysList = $db->query("SELECT id, name FROM barangays WHERE id = 12 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($barangaysList)) {
  $barangaysList = [['id' => 12, 'name' => 'Hinaplanon']];
}

// Hinaplanon Puroks List
$hinaplanonPuroks = $db->query("SELECT id, name FROM puroks WHERE barangay_id = 12 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Allocations to Barangays (Strictly Hinaplanon)
$allocStmt = $db->query("
    SELECT ri.*, res.id AS central_resource_id, res.code, res.name AS resource_name, res.unit, res.category,
           res.available_quantity AS depot_available_quantity,
           r.recommendation_code, dr.tracking_code, dr.disaster_type, dr.severity, dr.purok_name,
           b.name AS barangay_name, b.id AS target_barangay_id, r.reviewed_at
    FROM recommended_items ri
    JOIN resources res ON ri.resource_id = res.id
    JOIN recommendations r ON ri.recommendation_id = r.id
    JOIN disaster_reports dr ON r.disaster_request_id = dr.id
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.barangay_id = 12
    ORDER BY (ri.status = 'Pending') DESC, ri.id DESC
");
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Approved Requisition Allocations to Barangays (Hinaplanon)
$reqAllocStmt = $db->query("
    SELECT brr.*, b.name AS barangay_name, u.full_name AS requested_by_name, rev.full_name AS reviewed_by_name,
           rec_u.full_name AS received_by_name,
           COALESCE(res.name, brr.item_name) AS resource_name,
           COALESCE(res.code, '') AS code,
           COALESCE(res.available_quantity, 0) AS depot_available_quantity,
           COALESCE(res.unit, brr.unit) AS unit,
           COALESCE(res.category, brr.category) AS category
    FROM barangay_resource_requests brr
    JOIN barangays b ON brr.barangay_id = b.id
    LEFT JOIN users u ON brr.requested_by = u.id
    LEFT JOIN users rev ON brr.reviewed_by = rev.id
    LEFT JOIN users rec_u ON brr.received_by = rec_u.id
    LEFT JOIN resources res ON brr.resource_id = res.id
    WHERE brr.barangay_id = 12 AND brr.status = 'Approved'
    ORDER BY (brr.receipt_status = 'Pending') DESC, brr.reviewed_at DESC, brr.id DESC
");
$requisitionAllocations = $reqAllocStmt->fetchAll(PDO::FETCH_ASSOC);

// Combined Allocation Metrics
$reqAllocCount = count($requisitionAllocations);
$totalAllocRecords = count($allocations) + $reqAllocCount;
$totalReqAllocQty = 0;
$receivedReqCount = 0;
$notReceivedReqCount = 0;
$pendingReceiptReqCount = 0;
foreach ($requisitionAllocations as $ra) {
    $totalReqAllocQty += (int)($ra['approved_quantity'] ?: $ra['requested_quantity']);
    if ($ra['receipt_status'] === 'Received') $receivedReqCount++;
    elseif ($ra['receipt_status'] === 'Not Received') $notReceivedReqCount++;
    else $pendingReceiptReqCount++;
}
$grandTotalAllocatedUnits = $totalAllocatedQty + $totalReqAllocQty;

// Pending Recommendation Batches (Strictly Hinaplanon)
$pendingRecBatches = $db->query("
    SELECT r.id AS rec_id, r.recommendation_code, r.disaster_request_id, dr.tracking_code, dr.disaster_type, dr.severity, dr.purok_name,
           b.name AS barangay_name, COUNT(ri.id) AS pending_items_count
    FROM recommendations r
    JOIN disaster_reports dr ON r.disaster_request_id = dr.id
    JOIN barangays b ON dr.barangay_id = b.id
    JOIN recommended_items ri ON ri.recommendation_id = r.id AND ri.status = 'Pending'
    WHERE dr.barangay_id = 12
    GROUP BY r.id, r.recommendation_code, r.disaster_request_id, dr.tracking_code, dr.disaster_type, dr.severity, dr.purok_name, b.name
    ORDER BY r.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Transactions / Movements Log
$trxStmt = $db->query("
    SELECT rt.*, res.code, res.name AS resource_name, res.unit, res.category, u.full_name AS performed_by_name
    FROM resource_transactions rt
    JOIN resources res ON rt.resource_id = res.id
    LEFT JOIN users u ON rt.performed_by = u.id
    ORDER BY rt.created_at DESC
    LIMIT 100
");
$transactions = $trxStmt->fetchAll(PDO::FETCH_ASSOC);

// Barangay Requisition Requests (Strictly Hinaplanon)
$pendingRequestsCount = (int) $db->query("SELECT COUNT(*) FROM barangay_resource_requests WHERE status = 'Pending' AND barangay_id = 12")->fetchColumn();
$reqStmt = $db->query("
    SELECT brr.*, b.name AS barangay_name, u.full_name AS requested_by_name, rev.full_name AS reviewed_by_name,
           rec_u.full_name AS received_by_name,
           COALESCE(res.name, res_name.name, brr.item_name) AS central_res_name,
           COALESCE(res.code, res_name.code, '') AS central_res_code,
           COALESCE(res.available_quantity, res_name.available_quantity, 0) AS central_res_avail,
           COALESCE(res.total_quantity, res_name.total_quantity, 0) AS central_res_total,
           COALESCE(res.unit, res_name.unit, brr.unit) AS central_res_unit,
           COALESCE(res.category, res_name.category, brr.category) AS central_res_category,
           COALESCE(res.min_threshold, res_name.min_threshold, 20) AS central_res_min_threshold
    FROM barangay_resource_requests brr
    JOIN barangays b ON brr.barangay_id = b.id
    LEFT JOIN users u ON brr.requested_by = u.id
    LEFT JOIN users rev ON brr.reviewed_by = rev.id
    LEFT JOIN users rec_u ON brr.received_by = rec_u.id
    LEFT JOIN resources res ON (brr.resource_id IS NOT NULL AND brr.resource_id = res.id)
    LEFT JOIN resources res_name ON (brr.resource_id IS NULL AND LOWER(TRIM(brr.item_name)) = LOWER(TRIM(res_name.name)) AND (res_name.status = 'active' OR res_name.status IS NULL) AND (res_name.barangay_id IS NULL OR res_name.barangay_id = 0))
    WHERE brr.barangay_id = 12
    ORDER BY (brr.status = 'Pending') DESC, brr.created_at DESC
");
$barangayRequests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Dynamic Suppliers / Donors / Source: strictly persisted in resource_suppliers table
$suppliersList = $db->query("SELECT id, name FROM resource_suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$supplierDonorSources = array_column($suppliersList, 'name');
if (empty($suppliersList)) {
  $supplierDonorSources = ['LGU General Procurement', 'DSWD Regional Office', 'DOH Central Depot', 'Office of Civil Defense', 'Philippine Red Cross', 'Private Donor', 'Barangay Disaster Fund'];
  $suppliersList = array_map(function ($i, $n) {
    return ['id' => $i + 1, 'name' => $n]; }, array_keys($supplierDonorSources), $supplierDonorSources);
}

$conditions = ['New', 'Good', 'Fair', 'Poor', 'Expired'];

// Fetch barangays: strictly Hinaplanon
$allBarangays = $db->query("SELECT id, name FROM barangays WHERE id = 12 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($allBarangays)) {
  $allBarangays = [['id' => 12, 'name' => 'Hinaplanon']];
}

// Static Master Resources Catalog for instant direct selection (Diretso nalang)
$staticCatalog = getStaticResourcesCatalog();
$catalogByCategory = [];
foreach ($staticCatalog as $cItem) {
  $cat = $cItem['category'] ?: 'Other';
  $catalogByCategory[$cat][] = $cItem;
}

// Barangay Stockroom & Inventory History tracking logic - Strictly Hinaplanon (ID 12)
$selectedBarangayId = 12;
$selectedBarangay = ['id' => 12, 'name' => 'Hinaplanon'];
$filterPurok = trim($_GET['purok'] ?? '');

// 1. Current Barangay Resources Query (Hinaplanon Local Stockroom via agency_resources)
$bResSql = "SELECT ar.*, r.code, r.name, r.category, r.unit, r.brand, r.is_perishable, b.name AS barangay_name, b.id AS barangay_id
            FROM agency_resources ar 
            JOIN resources r ON ar.resource_id = r.id
            JOIN agencies a ON ar.agency_id = a.id
            JOIN barangays b ON a.barangay_id = b.id
            WHERE (ar.status != 'archived' OR ar.status IS NULL)
            AND b.id = 12";
$bResParams = [];
if (!empty($filterCategory)) {
  $bResSql .= " AND r.category = ?";
  $bResParams[] = $filterCategory;
}
if (!empty($search)) {
  $bResSql .= " AND (r.code LIKE ? OR r.name LIKE ? OR r.brand LIKE ?)";
  $like = "%{$search}%";
  $bResParams[] = $like;
  $bResParams[] = $like;
  $bResParams[] = $like;
}
$bResSql .= " ORDER BY r.name ASC";
$bResStmt = $db->prepare($bResSql);
$bResStmt->execute($bResParams);
$barangayCurrentResources = $bResStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Inventory History & Movements Query (Strictly Hinaplanon & Purok allocations)
$bTrxSql = "SELECT rt.*, res.code, res.name AS resource_name, res.unit, res.category, 
                   COALESCE(b.name, 'Hinaplanon') AS barangay_name, 12 AS barangay_id,
                   u.full_name AS performed_by_name
            FROM resource_transactions rt
            JOIN resources res ON rt.resource_id = res.id
            LEFT JOIN barangays b ON res.barangay_id = b.id
            LEFT JOIN users u ON rt.performed_by = u.id
            WHERE (res.barangay_id = 12 
                   OR (rt.reference_type = 'disaster_request' AND rt.reference_id IN (SELECT id FROM disaster_reports WHERE barangay_id = 12))
                   OR (rt.reference_type = 'barangay_requisition' AND rt.reference_id IN (SELECT id FROM barangay_resource_requests WHERE barangay_id = 12))
                   OR rt.remarks LIKE '%Hinaplanon%')";
$bTrxParams = [];

if (!empty($filterPurok)) {
  $bTrxSql .= " AND (rt.remarks LIKE ? OR rt.reference_id IN (SELECT id FROM disaster_reports WHERE barangay_id = 12 AND purok_name = ?))";
  $bTrxParams[] = "%{$filterPurok}%";
  $bTrxParams[] = $filterPurok;
}
if (!empty($filterCategory)) {
  $bTrxSql .= " AND res.category = ?";
  $bTrxParams[] = $filterCategory;
}
if (!empty($search)) {
  $bTrxSql .= " AND (res.code LIKE ? OR res.name LIKE ? OR rt.remarks LIKE ?)";
  $like = "%{$search}%";
  $bTrxParams[] = $like;
  $bTrxParams[] = $like;
  $bTrxParams[] = $like;
}
$bTrxSql .= " ORDER BY rt.created_at DESC, rt.id DESC LIMIT 150";
$bTrxStmt = $db->prepare($bTrxSql);
$bTrxStmt->execute($bTrxParams);
$barangayInventoryHistory = $bTrxStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
  /* Robust Modal Styling for Manage Resources Module */
  #createResourceModal.modal-overlay,
  #viewResourceModal.modal-overlay,
  #reviewRequisitionModal.modal-overlay {
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
  #reviewRequisitionModal.modal-overlay.active {
    display: flex !important;
  }

  #createResourceModal .modal-dialog,
  #viewResourceModal .modal-dialog,
  #reviewRequisitionModal .modal-dialog {
    max-width: 720px !important;
    width: 100% !important;
    max-height: calc(100vh - 36px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #CBD5E1) !important;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
    animation: modalPopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  }

  #createResourceModal .modal-header,
  #viewResourceModal .modal-header {
    padding: 14px 20px !important;
    border-bottom: 1px solid var(--color-border, #E2E8F0) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: #FFFFFF !important;
  }

  #createResourceModal .modal-body,
  #viewResourceModal .modal-body {
    overflow-y: auto !important;
    max-height: calc(100vh - 160px) !important;
    padding: 18px 20px !important;
  }

  #createResourceModal .modal-footer,
  #viewResourceModal .modal-footer {
    padding: 12px 20px !important;
    border-top: 1px solid var(--color-border, #E2E8F0) !important;
    background-color: #F8FAFC !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
  }

  @keyframes modalPopIn {
    from {
      opacity: 0;
      transform: scale(0.96) translateY(-8px);
    }

    to {
      opacity: 1;
      transform: scale(1) translateY(0);
    }
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

  @keyframes rowHighlightPulse {
    0% {
      background-color: rgba(34, 197, 94, 0.35);
    }

    50% {
      background-color: rgba(34, 197, 94, 0.15);
    }

    100% {
      background-color: transparent;
    }
  }

  .row-updated-highlight {
    animation: rowHighlightPulse 2.5s ease-out;
  }

  /* 2-Column Responsive Layout for Barangay Stock & History Tracker */
  .barangay-two-col-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
  }

  @media (max-width: 1024px) {
    .barangay-two-col-grid {
      grid-template-columns: 1fr !important;
    }
  }

  .two-col-table th,
  .two-col-table td {
    padding: 8px 10px !important;
    vertical-align: middle !important;
  }
</style>

<div class="page-header" style="margin-bottom:var(--space-3);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div class="page-header-title-wrap">
    <h1 style="font-family:'Poppins',sans-serif;font-size:15px;font-weight:700;color:var(--color-primary,#17324D);margin:0;line-height:1.3;"><?= clean($currentModuleInfo['title']) ?></h1>
    <p class="page-header-desc" style="font-family:'Roboto',sans-serif;font-size:10px;color:var(--color-text-muted,#64748B);margin:3px 0 0 0;line-height:1.4;">
      <?= clean($currentModuleInfo['desc']) ?>
    </p>
  </div>
  <div class="page-header-actions" style="display:flex;gap:8px;">
    <?php if ($activeTab === 'allocations'): ?>
      <button class="btn btn-primary" onclick="openDirectAllocationModal()"
        style="display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;font-size:11px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path
            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
          </path>
          <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
          <line x1="12" y1="22.08" x2="12" y2="12"></line>
        </svg>
        Allocate Resources
      </button>
    <?php elseif ($activeTab === 'active' || empty($activeTab) || $activeTab === 'archived'): ?>
      <button class="btn btn-secondary" onclick="openDirectAllocationModal()"
        style="display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;font-size:11px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path
            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
          </path>
          <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
          <line x1="12" y1="22.08" x2="12" y2="12"></line>
        </svg>
        Allocate Resources
      </button>
      <button class="btn btn-primary" onclick="openCreateResourceModal()"
        style="display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;font-size:11px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Add New Resource
      </button>
    <?php endif; ?>
  </div>
</div>


<!-- Metrics Overview -->
<div class="metrics-grid">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Active Resource SKUs</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <polyline points="12 6 12 12 14 14"></polyline>
      </svg>
    </div>
    <div class="metric-value"><?= number_format($activeCount) ?></div>
    <div class="metric-meta">Cataloged Active Commodities</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Central Available Stock</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path
          d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
        </path>
      </svg>
    </div>
    <div class="metric-value" id="metricCentralStock" style="color:var(--color-success);">
      <?= number_format($totalAvailableQty) ?></div>
    <div class="metric-meta">Units Ready in Central Depot</div>
  </div>

  <div class="metric-card" style="cursor:pointer;"
    onclick="location.href='<?= BASE_URL ?>/views/icdrrmo/resources-requested.php'">
    <div class="metric-header">
      <span class="metric-label">Barangay Requisitions</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="16" y1="13" x2="8" y2="13"></line>
        <line x1="16" y1="17" x2="8" y2="17"></line>
      </svg>
    </div>
    <div class="metric-value" id="metricPendingReqs"
      style="color:<?= $pendingRequestsCount > 0 ? '#D97706' : 'var(--color-primary)' ?>;">
      <?= number_format($pendingRequestsCount) ?> <span
        style="font-size:12px;font-weight:500;color:var(--color-text-muted);">Pending</span>
    </div>
    <div class="metric-meta"><?= count($barangayRequests) ?> Total Requisitions Logged</div>
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
    <div class="metric-value" style="color:<?= $lowStockCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($lowStockCount) ?>
    </div>
    <div class="metric-meta">Below Safety Threshold</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Expiring / Expired</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <polyline points="12 6 12 12 16 14"></polyline>
      </svg>
    </div>
    <div class="metric-value"
      style="color:<?= ($expiredCount + $expiringSoonCount) > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= $expiredCount > 0 ? $expiredCount . ' Expired' : '' ?>
      <?= $expiredCount > 0 && $expiringSoonCount > 0 ? ' / ' : '' ?>
      <?= $expiringSoonCount > 0 ? $expiringSoonCount . ' Soon' : '' ?>
      <?= ($expiredCount + $expiringSoonCount) === 0 ? '0' : '' ?>
    </div>
    <div class="metric-meta">Items Near or Past Expiry Date</div>
  </div>
</div>

<?php if ($activeTab === 'active' || $activeTab === 'archived'): ?>
  <!-- Filters Bar -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar"
        style="margin-bottom:0;display:flex;justify-content:space-between;align-items:center;width:100%;flex-wrap:wrap;gap:12px;">
        <input type="hidden" name="tab" value="<?= clean($activeTab) ?>">

        <!-- Group 1 (Left): Track Barangay -> Category Filter -> Stock Levels -> Condition -->
        <div class="filter-group" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=barangays" class="btn btn-outline btn-sm"
            style="font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:5px;background:#F8FAFC;padding:7px 12px;"
            title="Monitor Barangay Hinaplanon Stockroom">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path
                d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
              </path>
            </svg>
            Brgy. Hinaplanon Stock
          </a>

          <?php if ($activeTab === 'archived'): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/active-resources.php" class="btn btn-outline btn-sm"
              style="font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:5px;background:#F8FAFC;padding:7px 12px;">
              &larr; Active Supplies (<?= $activeCount ?>)
            </a>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=archived" class="btn btn-outline btn-sm"
              style="font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:5px;background:#F8FAFC;padding:7px 12px;color:var(--color-text-muted);"
              title="View Archived Supplies">
              Archived (<?= $archivedCount ?>)
            </a>
          <?php endif; ?>

          <select name="category" class="form-control" style="width:170px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= clean($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= clean($cat) ?></option>
            <?php endforeach; ?>
          </select>

          <?php if ($activeTab !== 'archived'): ?>
            <select name="stock" class="form-control" style="width:150px;">
              <option value="">All Stock Levels</option>
              <option value="low" <?= $filterStockLevel === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
              <option value="expiring" <?= $filterStockLevel === 'expiring' ? 'selected' : '' ?>>Expiring Soon (30 days)
              </option>
              <option value="expired" <?= $filterStockLevel === 'expired' ? 'selected' : '' ?>>Expired Items</option>
            </select>

            <select name="condition" class="form-control" style="width:130px;">
              <option value="">All Conditions</option>
              <?php foreach ($conditions as $cond): ?>
                <option value="<?= $cond ?>" <?= $filterCondition === $cond ? 'selected' : '' ?>><?= $cond ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

          <button type="submit" class="btn btn-outline">Apply</button>
          <?php if (!empty($search) || !empty($filterCategory) || !empty($filterStockLevel) || !empty($filterCondition)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=<?= clean($activeTab) ?>"
              class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>

        <!-- Group 2 (Right): Search -->
        <div class="filter-search-group" style="display:flex;align-items:center;gap:8px;">
          <div class="search-input-wrap" style="width:260px;">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Search SKU, name, brand, location..."
              value="<?= clean($search) ?>" style="width:100%;">
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Resources Data Table (Full Detail with Sticky Header and Vertical Scrollbar) -->
  <div class="table-responsive"
    style="max-height: 620px; overflow-y: auto; overflow-x: auto; position: relative; border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-xs, 0 1px 2px rgba(0,0,0,0.05));">
    <table class="data-table" style="width: 100%; border-collapse: separate; border-spacing: 0;">
      <thead
        style="position: sticky; top: 0; background: var(--color-surface, #ffffff); z-index: 10; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <tr>
          <th style="background: inherit;">SKU Code</th>
          <th style="background: inherit;">Resource Name</th>
          <th style="background: inherit;">Category</th>
          <th style="background: inherit;">Depot Stock</th>
          <th style="background: inherit;">Brand</th>
          <th style="background: inherit;">Condition</th>
          <th style="background: inherit;">Expiry Date</th>
          <th style="text-align:right; background: inherit;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resourcesList)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:32px;">No
              <?= $activeTab === 'archived' ? 'archived' : 'active' ?> resources match your filter criteria.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($resourcesList as $r): ?>
            <?php
            $isLow = ($r['available_quantity'] <= $r['min_threshold'] && $r['status'] === 'active');
            $isExpired = (!empty($r['expiry_date']) && $r['expiry_date'] < date('Y-m-d'));
            $isExpiringSoon = (!empty($r['expiry_date']) && !$isExpired && $r['expiry_date'] <= date('Y-m-d', strtotime('+30 days')));
            $rowBg = $isExpired ? '#FEF2F2' : ($isLow ? '#FFFDF8' : '');
            ?>
            <tr id="res-row-<?= $r['id'] ?>" style="<?= $rowBg ? "background-color: $rowBg;" : '' ?>">
              <td>
                <span class="badge badge-neutral" style="font-family:var(--font-secondary);font-weight:700;font-size:10px;">
                  <?= clean($r['code']) ?>
                </span>
                <?php if (!empty($r['batch_number'])): ?>
                  <div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;">Lot: <?= clean($r['batch_number']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight:700;color:var(--color-primary);font-size:12.5px;"><?= clean($r['name']) ?></div>
              </td>
              <td>
                <span class="badge badge-neutral" style="font-size:9.5px;"><?= clean($r['category']) ?></span>
              </td>
              <td id="res-stock-cell-<?= $r['id'] ?>">
                <div
                  style="font-family:var(--font-secondary);font-weight:700;font-size:12.5px;color:<?= $isLow ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                  <span class="res-avail-val"><?= number_format($r['available_quantity']) ?></span> <span
                    style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);"><?= clean($r['unit']) ?></span>
                </div>
                <div style="font-size:9px;color:var(--color-text-muted);">Total: <span
                    class="res-total-val"><?= number_format($r['total_quantity']) ?></span></div>
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
              <td style="text-align:right;">
                <div style="display:inline-flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;">
                  <button class="btn btn-outline btn-sm"
                    onclick="openViewResourceModal(<?= htmlspecialchars(json_encode($r)) ?>)"
                    style="display:inline-flex;align-items:center;gap:3px;" title="View Specifications">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    View
                  </button>
                  <?php if ($activeTab === 'archived'): ?>
                    <button class="btn btn-success btn-sm"
                      onclick="restoreResourceDirect(<?= (int) $r['id'] ?>, '<?= clean(addslashes($r['name'])) ?>')"
                      title="Restore to Active Inventory" style="display:inline-flex;align-items:center;gap:3px;">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="1 4 1 10 7 10"></polyline>
                        <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                      </svg>
                      Restore
                    </button>
                    <button class="btn btn-danger btn-sm"
                      onclick="deleteResourceDirect(<?= (int) $r['id'] ?>, '<?= clean(addslashes($r['name'])) ?>', '<?= clean(addslashes($r['code'])) ?>')"
                      title="Permanently Delete" style="display:inline-flex;align-items:center;gap:3px;">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      </svg>
                      Delete
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($activeTab === 'allocations'): ?>
  <!-- Pending Batches Banner (If Any Recommendation Batches Await Allocation) -->
  <?php if (!empty($pendingRecBatches)): ?>
    <div
      style="background:linear-gradient(135deg, rgba(245,158,11,0.08) 0%, rgba(217,119,6,0.12) 100%);border:1px solid #F59E0B;border-radius:var(--radius-md);padding:14px 18px;margin-bottom:var(--space-3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;"
      id="batchPendingBanner">
      <div style="display:flex;align-items:center;gap:12px;">
        <div
          style="width:32px;height:32px;border-radius:6px;background:var(--color-surface-subtle);color:var(--color-secondary);display:flex;align-items:center;justify-content:center;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
        </div>
        <div>
          <div style="font-family:'Poppins',sans-serif;font-weight:600;font-size:14px;color:var(--color-primary);">Pending Decision Tree Recommendation Batches</div>
          <div style="font-family:'Roboto',sans-serif;font-size:10.5px;color:var(--color-text-muted);margin-top:2px;">
            <?php foreach ($pendingRecBatches as $bIdx => $batch): ?>
              <?= $bIdx > 0 ? ' &bull; ' : '' ?>
              <strong><?= clean($batch['recommendation_code']) ?></strong> (Brgy. <?= clean($batch['barangay_name']) ?> &bull;
              <?= clean($batch['disaster_type']) ?>): <strong><?= $batch['pending_items_count'] ?> item(s)</strong> awaiting
              allocation dispatch
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;">
        <?php foreach ($pendingRecBatches as $batch): ?>
          <button type="button" class="btn btn-sm btn-primary"
            onclick="batchAllocateRecommendation(<?= (int) $batch['rec_id'] ?>, '<?= clean(addslashes($batch['recommendation_code'])) ?>', '<?= clean(addslashes($batch['barangay_name'])) ?>')"
            style="font-family:'Poppins',sans-serif;font-size:11px;">
            Batch Allocate <?= clean($batch['recommendation_code']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Allocations Table -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header"
      style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <h3 class="card-title" style="font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;margin:0;">Resource Allocated & Relief Distribution Status</h3>
        <div style="font-family:'Roboto',sans-serif;font-size:10px;color:var(--color-text-muted);margin-top:2px;">
          Approved barangay requisitions and Decision Tree relief allocations dispatched to Barangay local inventories
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span class="badge badge-info" style="font-size:9.5px;" id="badgeActiveAllocations"><?= $totalAllocRecords ?> Total Dispatches</span>
        <span class="badge badge-success" style="font-size:9.5px;" id="badgeAllocatedUnits"><?= number_format($grandTotalAllocatedUnits) ?> Units Dispatched</span>
        <?php if ($receivedReqCount > 0): ?>
          <span class="badge" style="background:#E8F8F0;color:#0F5132;border:1px solid #C3E6CB;font-size:9px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <?= $receivedReqCount ?> Received
          </span>
        <?php endif; ?>
        <?php if ($notReceivedReqCount > 0): ?>
          <span class="badge" style="background:#FDE8E8;color:#9B1C1C;border:1px solid #F8B4B4;font-size:9px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            <?= $notReceivedReqCount ?> Not Received
          </span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDirectAllocationModal()"
          style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          + New Allocation
        </button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Disaster / Requisition Reference</th>
            <th>Target Purok (Hinaplanon)</th>
            <th>Resource Commodity</th>
            <th>Category</th>
            <th>Recommended / Requested</th>
            <th>Allocated Qty</th>
            <th>Depot Stock</th>
            <th>Status & Delivery Receipt</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody id="allocationsTableBody">
          <?php if (empty($requisitionAllocations) && empty($allocations)): ?>
            <tr id="noAllocationsRow">
              <td colspan="9" style="text-align:center;padding:28px;color:var(--color-text-muted);">
                No resource allocations or approved dispatches recorded yet for Hinaplanon.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($requisitionAllocations as $ra): ?>
              <tr id="req-alloc-row-<?= $ra['id'] ?>" style="background:#FAFCFD;">
                <td>
                  <div style="font-weight:700;color:var(--color-primary);font-size:11px;font-family:var(--font-secondary);">
                    <?= clean($ra['request_code']) ?>
                  </div>
                  <div style="margin-top:2px;">
                    <span class="badge badge-info" style="font-size:8px;padding:1px 5px;font-weight:600;">Requisition Dispatch</span>
                  </div>
                  <div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;">
                    By: <?= clean($ra['requested_by_name'] ?: 'Barangay Head') ?>
                  </div>
                </td>
                <td style="font-size:11px;">
                  <div style="font-weight:700;color:var(--color-primary);">
                    <?= !empty($ra['target_purok']) ? clean($ra['target_purok']) : 'Barangay Hall Depot' ?>
                  </div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);">Brgy. <?= clean($ra['barangay_name']) ?></div>
                </td>
                <td>
                  <div style="font-weight:600;color:#0F172A;font-size:11px;"><?= clean($ra['resource_name']) ?></div>
                  <?php if (!empty($ra['code'])): ?>
                    <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($ra['code']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral" style="font-size:9px;"><?= clean($ra['category']) ?></span></td>
                <td style="font-family:var(--font-secondary);font-size:11px;">
                  <div><?= number_format($ra['requested_quantity']) ?> <?= clean($ra['unit']) ?></div>
                  <div style="font-size:8.5px;color:var(--color-text-muted);">Requested</div>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);font-size:12px;">
                  <div><?= number_format($ra['approved_quantity'] ?: $ra['requested_quantity']) ?> <?= clean($ra['unit']) ?></div>
                  <div style="font-size:8.5px;color:var(--color-text-muted);font-weight:normal;">Approved Allocation</div>
                </td>
                <td style="font-size:10.5px;">
                  <span style="font-weight:600;color:#0F172A;">
                    <?= number_format($ra['depot_available_quantity']) ?>
                  </span>
                  <span style="font-size:9px;color:var(--color-text-muted);"><?= clean($ra['unit']) ?></span>
                </td>
                <td>
                  <?php if ($ra['receipt_status'] === 'Received'): ?>
                    <span class="badge badge-success" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Received
                    </span>
                    <?php if (!empty($ra['received_at'])): ?>
                      <div style="font-size:8.5px;color:var(--color-text-muted);margin-top:2px;">
                        <?= date('M d, g:i A', strtotime($ra['received_at'])) ?>
                      </div>
                    <?php endif; ?>
                  <?php elseif ($ra['receipt_status'] === 'Not Received'): ?>
                    <span class="badge badge-danger" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                      Not Received
                    </span>
                  <?php else: ?>
                    <span class="badge badge-warning" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                      <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                      Awaiting Receipt
                    </span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm"
                    style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;"
                    onclick='openReviewRequisitionModal(<?= htmlspecialchars(json_encode($ra), ENT_QUOTES, 'UTF-8') ?>)'>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    View
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php foreach ($allocations as $a): ?>
              <tr id="alloc-row-<?= $a['id'] ?>" data-item-id="<?= $a['id'] ?>" data-rec-id="<?= $a['recommendation_id'] ?>"
                data-resource-id="<?= $a['resource_id'] ?>" data-resource-name="<?= clean($a['resource_name']) ?>"
                data-resource-code="<?= clean($a['code']) ?>" data-unit="<?= clean($a['unit']) ?>"
                data-recommended-qty="<?= (int) $a['recommended_quantity'] ?>"
                data-depot-avail="<?= (int) $a['depot_available_quantity'] ?>"
                data-tracking-code="<?= clean($a['tracking_code']) ?>" data-barangay-name="<?= clean($a['barangay_name']) ?>"
                data-purok-name="<?= clean($a['purok_name'] ?? 'All Puroks') ?>" data-status="<?= clean($a['status']) ?>">
                <td>
                  <div style="font-weight:700;color:var(--color-primary);font-size:11px;"><?= clean($a['tracking_code']) ?>
                  </div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['disaster_type']) ?> &bull;
                    <?= clean($a['severity']) ?></div>
                </td>
                <td style="font-size:11px;">
                  <div style="font-weight:700;color:var(--color-primary);">
                    <?= !empty($a['purok_name']) ? clean($a['purok_name']) : 'All Puroks' ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-muted);">Brgy. <?= clean($a['barangay_name']) ?></div>
                </td>
                <td>
                  <div style="font-weight:600;color:#0F172A;"><?= clean($a['resource_name']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['code']) ?></div>
                </td>
                <td><span class="badge badge-neutral" style="font-size:9px;"><?= clean($a['category']) ?></span></td>
                <td style="font-family:var(--font-secondary);font-size:11.5px;">
                  <div><?= number_format($a['recommended_quantity']) ?> <?= clean($a['unit']) ?></div>
                  <div style="font-size:8.5px;color:var(--color-text-muted);">Recommended</div>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:800;color:var(--color-success);font-size:13px;"
                  id="alloc-qty-<?= $a['id'] ?>">
                  <div><?= number_format($a['allocated_quantity']) ?> <?= clean($a['unit']) ?></div>
                  <div style="font-size:8.5px;color:var(--color-text-muted);font-weight:normal;">Decision Tree Dispatch</div>
                </td>
                <td style="font-size:10.5px;">
                  <span
                    style="font-weight:600;color:<?= ((int) $a['depot_available_quantity'] < (int) $a['recommended_quantity'] && $a['status'] === 'Pending') ? 'var(--color-danger)' : '#0F172A' ?>;"
                    id="alloc-depot-avail-<?= $a['id'] ?>">
                    <?= number_format($a['depot_available_quantity']) ?>
                  </span>
                  <span style="font-size:9px;color:var(--color-text-muted);"><?= clean($a['unit']) ?></span>
                  <?php if ((int) $a['depot_available_quantity'] < (int) $a['recommended_quantity'] && $a['status'] === 'Pending'): ?>
                    <span class="badge badge-danger" style="font-size:8px;padding:1px 4px;margin-left:2px;">Stock Low</span>
                  <?php endif; ?>
                </td>
                <td id="alloc-status-<?= $a['id'] ?>">
                  <?= renderStatusBadge($a['status']) ?>
                </td>
                <td id="alloc-actions-<?= $a['id'] ?>" style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-sm"
                    style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;"
                    onclick="openProcessAllocationModal(<?= $a['id'] ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
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
  <!-- Top Navigation Subtabs (Audit & Logistics Trail) -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 14px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/views/icdrrmo/event-logs.php" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif;font-size:11px;font-weight:500;">
        Event Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif;font-size:11px;font-weight:500;">
        All System Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/resources-logs.php" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif;font-size:11px;font-weight:500; display:inline-flex; align-items:center; gap:4px;" title="View Resources Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        Resources Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/supplies-logs.php" class="btn btn-outline btn-sm" style="font-family:'Poppins',sans-serif;font-size:11px;font-weight:500; display:inline-flex; align-items:center; gap:4px;" title="View Supplies Logs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
        Supplies Logs
      </a>
      <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=movements" class="btn btn-primary btn-sm" style="font-family:'Poppins',sans-serif;font-size:11px;font-weight:500;">
        Stock Movements
      </a>
    </div>
  </div>

  <!-- Stock Movement Audit Transactions -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="font-size:13px;">Stock Movements & Logistics Audit Trail</h3>
      <span class="badge badge-neutral" style="font-size:9.5px;">Last 100 Transactions</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Type</th>
            <th>Resource Name & SKU</th>
            <th>Quantity</th>
            <th>Remarks / Purpose</th>
            <th>Authorized Officer</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:28px;">No stock transactions recorded.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($transactions as $t): ?>
              <?php
              $color = 'var(--color-text)';
              if (in_array($t['transaction_type'], ['Restock', 'New Product', 'Returned']))
                $color = 'var(--color-success)';
              elseif ($t['transaction_type'] === 'Allocation')
                $color = 'var(--color-secondary)';
              elseif ($t['transaction_type'] === 'Damaged')
                $color = 'var(--color-danger)';
              ?>
              <tr>
                <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-muted);">
                  <?= formatDate($t['created_at']) ?>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-weight:700;font-size:9px;">
                    <?= clean($t['transaction_type']) ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight:600;"><?= clean($t['resource_name']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($t['code']) ?></div>
                </td>
                <td style="font-family:var(--font-secondary);font-weight:700;color:<?= $color ?>;">
                  <?= (in_array($t['transaction_type'], ['Restock', 'New Product', 'Returned']) ? '+' : '-') . number_format($t['quantity']) ?>
                  <?= clean($t['unit']) ?>
                </td>
                <td style="font-size:11px;color:var(--color-text-secondary);"><?= clean($t['remarks'] ?: '—') ?></td>
                <td style="font-size:10px;font-weight:500;"><?= clean($t['performed_by_name'] ?: 'System Operation') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($activeTab === 'requests'): ?>
  <!-- Barangay Resource Requisitions Tab -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-header"
      style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <h3 class="card-title" style="font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;margin:0;">Resources Requested by Barangays</h3>
        <div class="card-subtitle" style="font-family:'Roboto',sans-serif;font-size:10px;color:var(--color-text-muted);margin-top:2px;">
          Review incoming supply requests submitted by Barangay Heads, verify depot stock, and accept or reject with notes
        </div>
      </div>
      <span class="badge <?= $pendingRequestsCount > 0 ? 'badge-danger' : 'badge-neutral' ?>">
        <?= $pendingRequestsCount ?> Pending Requisition(s)
      </span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Request Code</th>
            <th>Barangay Origin</th>
            <th>Requested Commodity</th>
            <th>Category</th>
            <th>Quantity Requested</th>
            <th>Urgency</th>
            <th>Target Purok / Facility</th>
            <th>Status</th>
            <th>Submitted On</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($barangayRequests)): ?>
            <tr>
              <td colspan="10" style="text-align:center;padding:36px;color:var(--color-text-muted);">No resource
                requisitions submitted by barangays yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($barangayRequests as $req): ?>
              <?php
              $urgClass = 'badge-info';
              if ($req['urgency'] === 'Immediate' || $req['urgency'] === 'Critical')
                $urgClass = 'badge-danger';
              elseif ($req['urgency'] === 'High')
                $urgClass = 'badge-warning';
              elseif ($req['urgency'] === 'Medium')
                $urgClass = 'badge-primary';
              ?>
              <tr id="req-row-<?= $req['id'] ?>" data-req-id="<?= $req['id'] ?>">
                <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);font-size:11px;">
                  <?= clean($req['request_code']) ?>
                </td>
                <td style="font-weight:600;font-size:12px;">
                  Brgy. <?= clean($req['barangay_name']) ?>
                </td>
                <td>
                  <div style="font-weight:600;color:#0F172A;font-size:12px;"><?= clean($req['item_name']) ?></div>
                  <div
                    style="font-size:9.5px;color:var(--color-text-muted);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= clean($req['purpose']) ?>
                  </div>
                </td>
                <td><span style="font-size:11px;"><?= clean($req['category']) ?></span></td>
                <td id="req-qty-cell-<?= $req['id'] ?>">
                  <div style="font-family:var(--font-secondary);font-weight:700;font-size:12px;">
                    <?= number_format($req['requested_quantity']) ?>       <?= clean($req['unit']) ?>
                  </div>
                  <div id="req-approved-disp-<?= $req['id'] ?>"
                    style="font-size:9.5px;color:var(--color-success);font-weight:600;<?= ($req['status'] === 'Approved') ? '' : 'display:none;' ?>">
                    Approved: <?= number_format($req['approved_quantity'] ?? $req['requested_quantity']) ?>
                    <?= clean($req['unit']) ?>
                  </div>
                  <?php if ($req['central_res_avail'] !== null): ?>
                    <div
                      style="font-size:9px;color:<?= $req['central_res_avail'] >= $req['requested_quantity'] ? 'var(--color-success)' : 'var(--color-danger)' ?>;font-weight:500;">
                      Depot Avail: <?= number_format($req['central_res_avail']) ?>
                      <?= clean($req['central_res_unit'] ?? $req['unit']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $urgClass ?>" style="font-size:9px;"><?= clean($req['urgency']) ?></span></td>
                <td style="font-size:11px;font-weight:500;"><?= clean($req['target_purok'] ?: 'Barangay-wide') ?></td>
                <td id="req-status-cell-<?= $req['id'] ?>">
                  <?php if ($req['status'] === 'Approved'): ?>
                    <?php if (($req['receipt_status'] ?? '') === 'Received'): ?>
                      <span class="badge badge-success" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Received
                      </span>
                    <?php elseif (($req['receipt_status'] ?? '') === 'Not Received'): ?>
                      <span class="badge badge-danger" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                        Not Received
                      </span>
                    <?php else: ?>
                      <span class="badge badge-warning" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        Awaiting Receipt
                      </span>
                    <?php endif; ?>
                  <?php elseif ($req['status'] === 'Rejected'): ?>
                    <span class="badge badge-danger">Declined</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Pending Review</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:10px;color:var(--color-text-muted);font-family:var(--font-secondary);">
                  <?= formatDate($req['created_at']) ?>
                </td>
                <td style="text-align:right;" id="req-actions-cell-<?= $req['id'] ?>">
                  <button type="button" class="btn btn-outline btn-sm"
                    style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;"
                    onclick="openReviewRequisitionModal(<?= htmlspecialchars(json_encode($req)) ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
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

<?php elseif ($activeTab === 'barangays'): ?>
  <!-- Barangay Stock & Inventory History Filter Bar -->
  <div class="card" style="margin-bottom:var(--space-4);">
    <div class="card-body" style="padding:12px 16px;">
      <form method="GET" class="filter-bar"
        style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="hidden" name="tab" value="barangays">

        <div style="display:flex;align-items:center;gap:6px;">
          <label
            style="font-size:11px;font-weight:700;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:0.5px;margin:0;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            Target Purok (Hinaplanon):
          </label>
          <select name="purok" class="form-control" onchange="this.form.submit()"
            style="font-weight:600;min-width:200px;border-color:var(--color-primary);background:#F8FAFC;font-size:11px;">
            <option value="">-- All Hinaplanon Puroks --</option>
            <?php foreach ($hinaplanonPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>" <?= $filterPurok === $p['name'] ? 'selected' : '' ?>>
                <?= clean($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <select name="category" class="form-control" onchange="this.form.submit()" style="width:160px;">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= clean($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= clean($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="search-input-wrap" style="flex:1;min-width:180px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" name="search" class="form-control" placeholder="Search commodity, SKU, or remarks..."
            value="<?= clean($search) ?>">
        </div>

        <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 14px;">Apply
          Filter</button>
        <?php if (!empty($filterPurok) || !empty($filterCategory) || !empty($search)): ?>
          <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=barangays" class="btn btn-outline btn-sm"
            style="font-size:11px;color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>

        <button type="button" class="btn btn-secondary btn-sm" onclick="openDirectAllocationModalWithBarangay(12)"
          style="margin-left:auto;font-size:11px;display:inline-flex;align-items:center;gap:5px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path
              d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
            </path>
          </svg>
          Allocate Resources to Hinaplanon Puroks
        </button>
      </form>
    </div>
  </div>

  <!-- Scope KPI & Status Banner -->
  <div
    style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:14px 18px;margin-bottom:var(--space-4);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div
        style="width:36px;height:36px;border-radius:6px;background:var(--color-surface-subtle);color:var(--color-secondary);display:flex;align-items:center;justify-content:center;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
          <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
      </div>
      <div>
        <div style="font-family:'Poppins',sans-serif;font-weight:600;font-size:14px;color:var(--color-primary);display:flex;align-items:center;gap:8px;">
          Barangay Hinaplanon Stockroom & Purok Allocations
          <span class="badge badge-primary" style="font-size:9.5px;">Brgy. Hinaplanon (ID: 12)</span>
        </div>
        <div style="font-family:'Roboto',sans-serif;font-size:10.5px;color:var(--color-text-muted);margin-top:2px;">
          Real-time tracking of on-hand local reserves in Hinaplanon and supply allocations across its puroks.
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <div style="text-align:right;">
        <div style="font-size:10px;text-transform:uppercase;color:var(--color-text-muted);font-weight:700;">Commodity
          Types</div>
        <div style="font-size:16px;font-weight:700;color:var(--color-text);font-family:var(--font-secondary);">
          <?= count($barangayCurrentResources) ?></div>
      </div>
      <div style="border-left:1px solid #CBD5E1;height:24px;"></div>
      <div style="text-align:right;">
        <div style="font-size:10px;text-transform:uppercase;color:var(--color-text-muted);font-weight:700;">Total Stock
          Available</div>
        <div style="font-size:16px;font-weight:700;color:var(--color-success);font-family:var(--font-secondary);">
          <?= number_format(array_sum(array_column($barangayCurrentResources, 'available_quantity'))) ?></div>
      </div>
      <div style="border-left:1px solid #CBD5E1;height:24px;"></div>
      <div style="text-align:right;">
        <div style="font-size:10px;text-transform:uppercase;color:var(--color-text-muted);font-weight:700;">History
          Records</div>
        <div style="font-size:16px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">
          <?= count($barangayInventoryHistory) ?></div>
      </div>
    </div>
  </div>

  <!-- 2 Tables in 2 Columns Layout -->
  <div class="barangay-two-col-grid"
    style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin-bottom:var(--space-4);">

    <!-- ========================================================================= -->
    <!-- COLUMN 1: Current Barangay Resources Table                                -->
    <!-- ========================================================================= -->
    <div class="card" style="margin-bottom:0;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;background:#F8FAFC;border-bottom:1px solid var(--color-border);padding:12px 14px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:16px;">📦</span>
          <div>
            <h3 class="card-title" style="font-size:13px;margin:0;font-weight:700;color:var(--color-text);">Current
              Barangay Resources</h3>
            <div class="card-subtitle" style="font-size:10.5px;color:var(--color-text-muted);">Current on-hand stockpiles
              & capacity</div>
          </div>
        </div>
        <span class="badge badge-neutral" style="font-size:10px;font-weight:700;">
          <?= count($barangayCurrentResources) ?> Item(s)
        </span>
      </div>
      <div class="table-responsive" style="max-height:680px;overflow-y:auto;">
        <table class="data-table two-col-table" style="font-size:11.5px;margin-bottom:0;">
          <thead>
            <tr>
              <th style="width:36%;">Resource / SKU</th>
              <th style="width:22%;">Category</th>
              <th style="width:28%;">Stock (Avail/Total)</th>
              <th style="width:14%;text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($barangayCurrentResources)): ?>
              <tr>
                <td colspan="4" style="text-align:center;padding:40px 16px;color:var(--color-text-muted);">
                  <div style="font-size:24px;margin-bottom:6px;">📦</div>
                  <div style="font-weight:600;color:var(--color-text-secondary);font-size:12px;">No resources found in this
                    stockroom.</div>
                  <div style="font-size:11px;margin-top:2px;">Dispatches from ICDRRMO allocations will appear here.</div>
                  <?php if ($selectedBarangayId > 0): ?>
                    <button type="button" class="btn btn-secondary btn-sm"
                      onclick="openDirectAllocationModalWithBarangay(<?= $selectedBarangayId ?>)"
                      style="margin-top:10px;font-size:10.5px;">
                      Allocate Stock to Brgy. <?= clean($selectedBarangay['name']) ?>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($barangayCurrentResources as $r): ?>
                <?php
                $avail = (int) $r['available_quantity'];
                $total = (int) $r['total_quantity'];
                $min = (int) ($r['min_threshold'] ?? 10);
                $healthClass = 'badge-success';
                $healthText = 'In Stock';
                if ($avail <= 0) {
                  $healthClass = 'badge-danger';
                  $healthText = 'Out of Stock';
                } elseif ($avail <= $min) {
                  $healthClass = 'badge-warning';
                  $healthText = 'Low Stock';
                }
                ?>
                <tr>
                  <td>
                    <div style="font-weight:700;color:var(--color-text);font-size:12px;line-height:1.3;">
                      <?= clean($r['name']) ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:2px;flex-wrap:wrap;">
                      <span
                        style="font-family:var(--font-secondary);font-size:9.5px;color:var(--color-text-muted);font-weight:600;">
                        <?= clean($r['code']) ?>
                      </span>
                      <?php if ($selectedBarangayId === 0 && !empty($r['barangay_name'])): ?>
                        <span class="badge badge-neutral" style="font-size:8.5px;padding:1px 5px;">
                          Brgy. <?= clean($r['barangay_name']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <span style="font-size:10.5px;color:var(--color-text-secondary);font-weight:500;">
                      <?= clean($r['category']) ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-family:var(--font-secondary);font-weight:700;font-size:12px;">
                      <span
                        style="color:<?= $avail > 0 ? 'var(--color-text)' : 'var(--color-danger)' ?>;"><?= number_format($avail) ?></span>
                      <span style="font-weight:400;color:var(--color-text-muted);font-size:10.5px;">/
                        <?= number_format($total) ?>       <?= clean($r['unit']) ?></span>
                    </div>
                    <div style="margin-top:2px;">
                      <span class="badge <?= $healthClass ?>"
                        style="font-size:8.5px;padding:1px 5px;"><?= $healthText ?></span>
                    </div>
                  </td>
                  <td style="text-align:right;">
                    <button type="button" class="btn btn-outline btn-sm"
                      onclick="openViewResourceModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="View Specifications"
                      style="padding:3px 8px;font-size:10.5px;display:inline-flex;align-items:center;gap:3px;">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                      </svg>
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

    <!-- ========================================================================= -->
    <!-- COLUMN 2: Inventory Movement & History Log Table                          -->
    <!-- ========================================================================= -->
    <div class="card" style="margin-bottom:0;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
      <div class="card-header"
        style="display:flex;justify-content:space-between;align-items:center;background:#F8FAFC;border-bottom:1px solid var(--color-border);padding:12px 14px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:16px;">📜</span>
          <div>
            <h3 class="card-title" style="font-size:13px;margin:0;font-weight:700;color:var(--color-text);">Inventory
              History & Movements</h3>
            <div class="card-subtitle" style="font-size:10.5px;color:var(--color-text-muted);">Audit log of inbound
              replenishments & outbound dispatches</div>
          </div>
        </div>
        <span class="badge badge-neutral" style="font-size:10px;font-weight:700;">
          <?= count($barangayInventoryHistory) ?> Log(s)
        </span>
      </div>
      <div class="table-responsive" style="max-height:680px;overflow-y:auto;">
        <table class="data-table two-col-table" style="font-size:11.5px;margin-bottom:0;">
          <thead>
            <tr>
              <th style="width:20%;">Timestamp</th>
              <th style="width:16%;">Type</th>
              <th style="width:34%;">Commodity & Qty</th>
              <th style="width:30%;">Remarks & Officer</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($barangayInventoryHistory)): ?>
              <tr>
                <td colspan="4" style="text-align:center;padding:40px 16px;color:var(--color-text-muted);">
                  <div style="font-size:24px;margin-bottom:6px;">📜</div>
                  <div style="font-weight:600;color:var(--color-text-secondary);font-size:12px;">No inventory transactions
                    logged yet.</div>
                  <div style="font-size:11px;margin-top:2px;">Dispatches and distributions will be recorded in this audit
                    trail.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($barangayInventoryHistory as $t): ?>
                <?php
                $isPositive = in_array($t['transaction_type'], ['Restock', 'New Product', 'Returned']);
                $qtyColor = $isPositive ? 'var(--color-success)' : ($t['transaction_type'] === 'Damaged' ? 'var(--color-danger)' : 'var(--color-secondary)');
                $typeClass = 'badge-neutral';
                if ($isPositive)
                  $typeClass = 'badge-success';
                elseif ($t['transaction_type'] === 'Allocation')
                  $typeClass = 'badge-primary';
                elseif ($t['transaction_type'] === 'Dispatched')
                  $typeClass = 'badge-info';
                elseif ($t['transaction_type'] === 'Damaged')
                  $typeClass = 'badge-danger';
                ?>
                <tr>
                  <td>
                    <div style="font-family:var(--font-secondary);font-size:10px;font-weight:600;color:var(--color-text);">
                      <?= date('M d, Y', strtotime($t['created_at'])) ?>
                    </div>
                    <div style="font-family:var(--font-secondary);font-size:9.5px;color:var(--color-text-muted);">
                      <?= date('h:i A', strtotime($t['created_at'])) ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge <?= $typeClass ?>" style="font-size:8.5px;font-weight:700;padding:2px 5px;">
                      <?= clean($t['transaction_type']) ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-weight:600;color:var(--color-text);font-size:11.5px;line-height:1.3;">
                      <?= clean($t['resource_name']) ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:2px;flex-wrap:wrap;">
                      <span style="font-family:var(--font-secondary);font-weight:700;color:<?= $qtyColor ?>;font-size:11px;">
                        <?= ($isPositive ? '+' : '-') . number_format($t['quantity']) ?>       <?= clean($t['unit']) ?>
                      </span>
                      <?php if (!empty($t['barangay_name'])): ?>
                        <span class="badge badge-neutral" style="font-size:8px;padding:0 4px;">
                          Brgy. <?= clean($t['barangay_name']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div
                      style="font-size:10.5px;color:var(--color-text-secondary);line-height:1.3;max-width:220px;word-break:break-word;">
                      <?= clean($t['remarks'] ?: '—') ?>
                    </div>
                    <div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;">
                      by <?= clean($t['performed_by_name'] ?: 'System Operation') ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL 1: Add New Resource Item (Full Detail)                              -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createResourceModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Add New Resource Item</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Add a disaster commodity SKU into the central warehouse inventory catalog.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createResourceModal')">&times;</button>
    </div>
    <form id="createResourceForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/create.php" onsubmit="handleCreateResource(event)">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">

        <!-- Master Resource Catalog Selector & Dynamic Creator -->
        <div class="form-group" style="margin-bottom:12px; background:var(--color-surface-subtle,#F0F3F4); border:1px solid var(--color-border,#D9E0E3); border-radius:8px; padding:12px 14px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <label class="form-label" style="font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; color:var(--color-primary,#17324D); margin:0; display:flex; align-items:center; gap:6px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                <line x1="12" y1="22.08" x2="12" y2="12"></line>
              </svg>
              Select Resource from Master Catalog
            </label>
            <button type="button" class="btn btn-outline btn-xs" id="btnAdd_catalog_resource_create" onclick="toggleDynamicCatalogResource('create')"
              style="font-family:'Poppins',sans-serif; font-size:10.5px; font-weight:600; padding:2px 8px; height:auto; line-height:1.2; color:var(--color-primary,#17324D); border-color:var(--color-border,#D9E0E3); background:var(--color-surface,#FFFFFF);">
              + Add New Resource
            </button>
          </div>

          <select id="create_select_catalog_resource" name="resource_id" class="form-control"
            onchange="onCatalogResourceSelected(this)"
            style="background:var(--color-surface,#FFFFFF); border-color:var(--color-border,#D9E0E3); font-weight:500; font-size:11.5px; color:var(--color-text,#24313A);">
            <option value="">— Select an existing commodity from Master Catalog —</option>
            <?php foreach ($catalogByCategory as $catName => $catItems): ?>
              <optgroup label="<?= clean($catName) ?>">
                <?php foreach ($catItems as $item): ?>
                  <option value="<?= (int) $item['id'] ?>" data-code="<?= clean($item['code']) ?>"
                    data-name="<?= clean($item['name']) ?>" data-category="<?= clean($item['category']) ?>"
                    data-unit="<?= clean($item['unit']) ?>" data-brand="<?= clean($item['brand'] ?? '') ?>"
                    data-description="<?= clean($item['description'] ?? '') ?>"
                    data-is-perishable="<?= (int) ($item['is_perishable'] ?? 0) ?>">
                    <?= clean($item['name']) ?> (<?= clean($item['code']) ?> — <?= clean($item['unit']) ?>)
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
            <option value="custom">+ Add New Custom Resource Definition</option>
          </select>

          <!-- Inline Dynamic Catalog Creator -->
          <div id="dynAddContainer_catalog_resource_create" style="display:none; margin-top:8px; background:var(--color-surface,#FFFFFF); border:1px dashed var(--color-border,#D9E0E3); padding:10px 12px; border-radius:6px;">
            <div style="font-family:'Poppins',sans-serif; font-size:11px; font-weight:600; color:var(--color-primary,#17324D); margin-bottom:6px;">
              Quick Add Resource to Master Catalog
            </div>
            <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:6px; margin-bottom:6px;">
              <input type="text" id="new_catalog_name_create" class="form-control" placeholder="Resource item name *" style="font-size:11px; height:28px;">
              <select id="new_catalog_category_create" class="form-control" style="font-size:11px; height:28px;">
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" id="new_catalog_unit_create" class="form-control" placeholder="Unit (e.g. units, pcs, boxes)" value="units" style="font-size:11px; height:28px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:6px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicCatalogResource('create')" style="font-size:10px; padding:3px 8px;">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicCatalogResource('create')" style="font-size:10px; padding:3px 10px;">Save to Master Catalog</button>
            </div>
          </div>

          <div style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:4px;">
            Selecting or adding a resource synchronizes official SKU code, category, and unit across ICDRRMO and all Barangays.
          </div>
        </div>

        <div class="form-section-title">Basic Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">SKU / Item Code <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="code" class="form-control" placeholder="e.g. RES-FOOD-008" required
              style="text-transform:uppercase;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Category <span
                  style="color:var(--color-danger)">*</span></label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_category_create"
                onclick="toggleDynamicField('category', 'create')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="category" id="create_res_category" class="form-control" required>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_category_create"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_category_input_create" class="form-control"
                  placeholder="New category name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('category', 'create')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('category', 'create')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Resource Item Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Family Food Pack (3-Day Supply)"
            required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Brand / Manufacturer</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_brand_create"
                onclick="toggleDynamicField('brand', 'create')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="brand" id="create_res_brand" class="form-control">
              <option value="">— Select Brand / Manufacturer —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= clean($b) ?>"><?= clean($b) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_brand_create"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_brand_input_create" class="form-control" placeholder="New brand name..."
                  style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('brand', 'create')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('brand', 'create')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
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
            <label class="form-label">Low Stock Alert / Restock Threshold <span
                style="color:var(--color-danger)">*</span></label>
            <input type="number" name="min_threshold" id="create_res_min_threshold" class="form-control" min="1"
              value="50" required>
            <span style="font-size:10px;color:var(--color-text-muted);display:block;margin-top:3px;line-height:1.3;">
              ⚠️ <strong>Restock Trigger Level:</strong> Alerts administrators to restock when depot inventory &le;
              threshold. <em>(NOT a distribution limit).</em>
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
            <input type="number" name="cost_per_unit" class="form-control" min="0" step="0.01"
              placeholder="e.g. 250.00">
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
              <input type="checkbox" name="is_perishable" id="create_is_perishable" value="1"
                style="width:16px;height:16px;">
              <label for="create_is_perishable"
                style="font-size:11px;color:var(--color-text-secondary);cursor:pointer;">Yes, this item is
                perishable</label>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Supplier / Donor</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_supplier_create"
                onclick="toggleDynamicField('supplier', 'create')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;">+ Add</button>
            </div>
            <select name="supplier_id" id="create_res_supplier" class="form-control">
              <option value="">— Select Supplier / Donor —</option>
              <?php foreach ($suppliersList as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= clean($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_supplier_create"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_supplier_input_create" class="form-control"
                  placeholder="New supplier / donor name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('supplier', 'create')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('supplier', 'create')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Batch / Lot Number</label>
            <input type="text" name="batch_number" class="form-control" placeholder="e.g. BATCH-2026-OCT-001">
          </div>
        </div>

        <div class="form-section-title">Description & Details</div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Description & Specifications</label>
          <textarea name="description" class="form-control" rows="2"
            placeholder="Contents, packaging specifications, expiration handling, or donor tags..."></textarea>
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
          Full warehouse catalog profile with procurement details, expiry tracking, and inventory levels.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewResourceModal')">&times;</button>
    </div>
    <form id="viewResourceForm" method="POST" action="<?= BASE_URL ?>/backend/functions/resources/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="id" id="view_res_id">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <!-- Status & Mode Banner -->
        <div id="view_res_edit_banner"
          style="display:none;padding:8px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;font-size:11px;color:#1E40AF;align-items:center;gap:6px;">
          <span>✏️</span> <strong>Edit Mode Active:</strong> Modify the resource specifications below. Click "Save
          Changes" to apply.
        </div>

        <!-- Quick Summary Metrics Bar -->
        <div
          style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px;font-size:11px;display:flex;justify-content:space-around;text-align:center;">
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
        <div id="view_res_expiry_alert"
          style="display:none;padding:8px 12px;border-radius:6px;font-size:11px;align-items:center;gap:6px;"></div>

        <div class="form-section-title">Basic Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">SKU / Item Code <span style="color:var(--color-danger)">*</span></label>
            <input type="text" name="code" id="view_res_code" class="form-control" required disabled
              style="text-transform:uppercase;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Category <span
                  style="color:var(--color-danger)">*</span></label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_category_view"
                onclick="toggleDynamicField('category', 'view')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="category" id="view_res_category" class="form-control" required disabled>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= clean($cat) ?>"><?= clean($cat) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_category_view"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_category_input_view" class="form-control" placeholder="New category name..."
                  style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('category', 'view')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('category', 'view')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
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
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_brand_view"
                onclick="toggleDynamicField('brand', 'view')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="brand" id="view_res_brand" class="form-control" disabled>
              <option value="">— Select Brand / Manufacturer —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= clean($b) ?>"><?= clean($b) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_brand_view"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_brand_input_view" class="form-control" placeholder="New brand name..."
                  style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('brand', 'view')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('brand', 'view')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
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
            <label class="form-label">Low Stock Alert / Restock Threshold <span
                style="color:var(--color-danger)">*</span></label>
            <input type="number" name="min_threshold" id="view_res_min_threshold" class="form-control" min="1" required
              disabled>
            <span style="font-size:10px;color:var(--color-text-muted);display:block;margin-top:3px;line-height:1.3;">
              ⚠️ <strong>Restock Trigger Level:</strong> Alerts administrators to restock when depot inventory &le;
              threshold. <em>(NOT a distribution limit).</em>
            </span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available Stock</label>
            <input type="number" name="available_quantity" id="view_res_available_quantity" class="form-control" min="0"
              required disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">In-Use / Dispatched</label>
            <input type="number" name="in_use_quantity" id="view_res_in_use_quantity" class="form-control" min="0"
              disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Damaged / Expired</label>
            <input type="number" name="damaged_quantity" id="view_res_damaged_quantity" class="form-control" min="0"
              disabled>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Weight per Unit</label>
            <input type="text" name="weight_per_unit" id="view_res_weight_per_unit" class="form-control" disabled>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Cost per Unit (₱)</label>
            <input type="number" name="cost_per_unit" id="view_res_cost_per_unit" class="form-control" min="0"
              step="0.01" disabled>
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
              <input type="checkbox" name="is_perishable" id="view_res_is_perishable" value="1"
                style="width:16px;height:16px;" disabled>
              <label for="view_res_is_perishable"
                style="font-size:11px;color:var(--color-text-secondary);cursor:pointer;">Perishable</label>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:2px;">Supplier / Donor</label>
              <button type="button" class="btn btn-outline btn-xs" id="btnAdd_supplier_view"
                onclick="toggleDynamicField('supplier', 'view')"
                style="font-size:10px;padding:1px 6px;height:auto;line-height:1.2;" disabled>+ Add</button>
            </div>
            <select name="supplier_id" id="view_res_supplier_donor" class="form-control" disabled>
              <option value="">— Select Supplier / Donor —</option>
              <?php foreach ($suppliersList as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= clean($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="dynAddContainer_supplier_view"
              style="display:none;margin-top:6px;background:#F8FAFC;border:1px dashed var(--color-border);padding:6px;border-radius:4px;">
              <div style="display:flex;gap:4px;">
                <input type="text" id="new_supplier_input_view" class="form-control"
                  placeholder="New supplier / donor name..." style="font-size:11px;padding:3px 6px;height:26px;">
                <button type="button" class="btn btn-primary btn-xs" onclick="confirmDynamicField('supplier', 'view')"
                  style="white-space:nowrap;padding:2px 8px;font-size:10.5px;">Add</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="toggleDynamicField('supplier', 'view')"
                  style="padding:2px 6px;font-size:10.5px;">✕</button>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Batch / Lot Number</label>
            <input type="text" name="batch_number" id="view_res_batch_number" class="form-control" disabled>
          </div>
        </div>

        <div class="form-section-title">Description & Details</div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Description & Specifications</label>
          <textarea name="description" id="view_res_description" class="form-control" rows="2" disabled></textarea>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Archive Button -->
          <button type="button" class="btn btn-warning btn-sm" id="btnArchiveResource"
            onclick="onArchiveResourceClick()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="21 8 21 21 3 21 3 8"></polyline>
              <rect x="1" y="3" width="22" height="5"></rect>
              <line x1="10" y1="12" x2="14" y2="12"></line>
            </svg>
            Archive
          </button>

          <!-- Delete Button -->
          <button type="button" class="btn btn-danger btn-sm" id="btnDeleteResource" onclick="onDeleteResourceClick()"
            style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"></polyline>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            </svg>
            Delete
          </button>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Restore Button (visible when viewing archived resource) -->
          <button type="button" class="btn btn-success btn-sm" id="btnRestoreResource"
            onclick="onRestoreResourceClick()" style="display:none;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="1 4 1 10 7 10"></polyline>
              <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
            </svg>
            Restore Resource
          </button>

          <!-- Cancel Edit Button (only in edit mode) -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCancelEditResource"
            onclick="cancelResourceEditMode()" style="display:none;">
            Cancel
          </button>

          <!-- Edit Button (in view mode for active resource) -->
          <button type="button" class="btn btn-primary btn-sm" id="btnToggleEditResource"
            onclick="enableResourceEditMode()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            Edit
          </button>

          <!-- Save Button (only in edit mode) -->
          <button type="submit" class="btn btn-primary btn-sm" id="btnSaveResource" style="display:none;">
            Save Changes
          </button>

          <!-- Close Button -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCloseViewModal"
            onclick="closeModal('viewResourceModal')">
            Close
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: Review Barangay Resource Requisition (Approve / Reject)           -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="reviewRequisitionModal">
  <div class="modal-dialog" style="max-width:700px; border-radius:10px; overflow:hidden;">
    <div class="modal-header" style="background:#FFFFFF; border-bottom:1px solid var(--color-border,#D9E0E3); padding:14px 18px; display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
          <span style="font-family:'Roboto',sans-serif; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-secondary,#2F6F73);">Supply Requisition</span>
          <span id="reviewReqStatusBadge" class="badge"></span>
          <span id="reviewReqUrgencyBadge" class="badge" style="font-size:9.5px;"></span>
        </div>
        <h3 class="modal-title" style="font-family:'Poppins',sans-serif; font-size:15px; font-weight:600; color:var(--color-primary,#17324D); margin:0;">
          Requisition Review & Stock Verification
        </h3>
        <div style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:2px;">
          Tracking Code: <strong id="reviewReqCode" style="font-family:'Poppins',sans-serif; color:var(--color-primary,#17324D);"></strong> &bull;
          <span id="reviewReqBarangay"></span> &bull;
          Submitted: <span id="reviewReqDate"></span>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('reviewRequisitionModal')">&times;</button>
    </div>

    <form id="reviewRequisitionForm" onsubmit="event.preventDefault();">
      <input type="hidden" id="review_req_id">

      <div class="modal-body" style="padding:16px 18px; display:flex; flex-direction:column; gap:12px; background:#FAFCFD;">

        <!-- Current Stock vs Requested Quantity Visual Intelligence Card -->
        <div style="background:var(--color-surface-subtle,#F0F3F4); border:1px solid var(--color-border,#D9E0E3); border-radius:8px; padding:14px 16px;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <!-- Left Column: Barangay Requested Quantity -->
            <div style="background:#FFFFFF; border:1px solid var(--color-border,#D9E0E3); border-radius:6px; padding:12px 14px;">
              <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-text-secondary,#66737D); letter-spacing:0.4px;">
                Requested by Barangay
              </div>
              <div style="display:flex; align-items:baseline; gap:6px; margin-top:4px;">
                <span id="reviewReqQuantityDisplay" style="font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; color:var(--color-primary,#17324D); line-height:1;">0</span>
                <span id="reviewReqUnitDisplay" style="font-family:'Roboto',sans-serif; font-size:12px; font-weight:500; color:var(--color-text-secondary,#66737D);">units</span>
              </div>
              <div style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:4px;" id="reviewReqSubmitter">
                Origin: Barangay Hinaplanon
              </div>
            </div>

            <!-- Right Column: ICDRRMO Central Depot Current Stock -->
            <div style="background:#FFFFFF; border:1px solid var(--color-border,#D9E0E3); border-radius:6px; padding:12px 14px;">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-secondary,#2F6F73); letter-spacing:0.4px;">
                  Current ICDRRMO Depot Stock
                </div>
                <span id="reviewDepotStockBadge" class="badge" style="font-size:9px;">Checking...</span>
              </div>
              <div style="display:flex; align-items:baseline; gap:6px; margin-top:4px;">
                <span id="reviewDepotAvailDisplay" style="font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; color:var(--color-primary,#17324D); line-height:1;">—</span>
                <span id="reviewDepotUnitDisplay" style="font-family:'Roboto',sans-serif; font-size:12px; font-weight:500; color:var(--color-text-secondary,#66737D);">available</span>
              </div>
              <div style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:4px;" id="reviewDepotTotalSub">
                Central Depot Live Inventory
              </div>
            </div>
          </div>

          <!-- Stock Fulfillment Ratio & Health Bar -->
          <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--color-border,#D9E0E3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
              <span style="font-family:'Roboto',sans-serif; font-size:10px; font-weight:600; color:var(--color-primary,#17324D);" id="reviewStockFulfillmentLabel">
                Depot Stock Availability Status
              </span>
              <span style="font-family:'Roboto',sans-serif; font-size:10px; font-weight:600;" id="reviewStockRatioPercent">
                100%
              </span>
            </div>
            <div style="height:6px; background:#D9E0E3; border-radius:3px; overflow:hidden;">
              <div id="reviewStockProgressBar" style="height:100%; width:100%; background:var(--color-secondary,#2F6F73); border-radius:3px; transition:width 0.3s ease;"></div>
            </div>
            <div id="reviewStockFulfillmentNotice" style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:5px; line-height:1.45;">
              Checking live depot inventory...
            </div>
          </div>
        </div>

        <!-- Commodity Specifications & Purok Target -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div style="background:#FFFFFF; border:1px solid var(--color-border,#D9E0E3); border-radius:6px; padding:10px 12px;">
            <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-text-secondary,#66737D);">
              Resource Commodity
            </div>
            <div id="reviewReqItemName" style="font-family:'Poppins',sans-serif; font-size:13px; font-weight:600; color:var(--color-primary,#17324D); margin-top:2px;">
              —
            </div>
            <div id="reviewReqMetaSub" style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-text-secondary,#66737D); margin-top:2px;">
              —
            </div>
          </div>

          <div style="background:#FFFFFF; border:1px solid var(--color-border,#D9E0E3); border-radius:6px; padding:10px 12px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
              <div>
                <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-text-secondary,#66737D);">
                  Urgency Priority
                </div>
                <div id="reviewReqUrgency" style="font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; color:var(--color-primary,#17324D); margin-top:2px;">
                  —
                </div>
              </div>
              <div>
                <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-text-secondary,#66737D);">
                  Target Purok
                </div>
                <div id="reviewReqTargetPurok" style="font-family:'Roboto',sans-serif; font-size:11px; font-weight:500; color:var(--color-primary,#17324D); margin-top:2px;">
                  —
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Purpose & Operational Justification -->
        <div>
          <div style="font-family:'Roboto',sans-serif; font-size:9.5px; text-transform:uppercase; font-weight:700; color:var(--color-text-secondary,#66737D); margin-bottom:4px;">
            Barangay Operational Purpose & Justification
          </div>
          <div id="reviewReqPurpose" style="font-family:'Roboto',sans-serif; font-size:10.5px; color:var(--color-primary,#17324D); background:var(--color-surface-subtle,#F0F3F4); border:1px solid var(--color-border,#D9E0E3); border-radius:6px; padding:10px 12px; line-height:1.5;">
            —
          </div>
        </div>

        <!-- Editable Review Controls (shown when Pending) -->
        <div id="reviewPendingControls" style="background:#FFFFFF; border:1px solid var(--color-border,#D9E0E3); padding:14px; border-radius:8px; display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; color:var(--color-primary,#17324D); text-transform:uppercase; letter-spacing:0.3px;">
              ICDRRMO Decision & Stock Allocation
            </div>
            <span id="reviewQuantityMaxHint" style="font-family:'Roboto',sans-serif; font-size:10px; color:var(--color-secondary,#2F6F73); font-weight:600;"></span>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-family:'Roboto',sans-serif; font-size:11px; margin-bottom:4px;">
              Approved Quantity to Allocate <span style="color:var(--color-danger)">*</span>
            </label>
            <input type="number" id="review_approved_quantity" class="form-control" min="1" required
              style="font-family:'Poppins',sans-serif; font-weight:600; font-size:13px; height:34px;" oninput="validateReviewQuantity()">
            <div id="reviewQtyValidationAlert"
              style="display:none; font-family:'Roboto',sans-serif; font-size:10.5px; color:#991B1B; background:#FEE2E2; border:1px solid #FECACA; border-radius:4px; padding:6px 10px; margin-top:6px; font-weight:600;">
            </div>
            <div style="font-family:'Roboto',sans-serif; font-size:9.5px; color:var(--color-text-secondary,#66737D); margin-top:4px;">
              Upon acceptance, this quantity will be deducted in real-time from ICDRRMO Central Depot and credited to the barangay local inventory.
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-family:'Roboto',sans-serif; font-size:11px; margin-bottom:4px;">
              Review Remarks / Logistics Instructions
            </label>
            <textarea id="review_remarks" class="form-control" rows="2" style="font-family:'Roboto',sans-serif; font-size:11px;"
              placeholder="e.g. Approved for warehouse release. Barangay dispatch truck scheduled for 2:00 PM pickup."></textarea>
          </div>
        </div>

        <!-- Read-Only Review Decision (shown when Approved or Declined) -->
        <div id="reviewCompletedDetails" style="display:none; padding:12px 14px; border-radius:8px; border:1px solid var(--color-border,#D9E0E3);">
          <div style="font-family:'Poppins',sans-serif; font-size:12px; font-weight:600;" id="reviewCompletedTitle">Decision Recorded</div>
          <div id="reviewCompletedRemarks" style="font-family:'Roboto',sans-serif; font-size:11px; margin-top:4px; line-height:1.4;"></div>
          <div id="reviewCompletedMeta" style="font-family:'Roboto',sans-serif; font-size:9.5px; margin-top:6px; color:var(--color-text-secondary,#66737D);"></div>
        </div>

        <!-- Barangay Delivery Receipt Confirmation (shown when Approved) -->
        <div id="reviewReceiptDetails" style="display:none; padding:12px 14px; border-radius:8px; border:1px solid var(--color-border,#D9E0E3);">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; color:var(--color-primary,#17324D);" id="reviewReceiptTitle">
              Barangay Delivery Receipt Status
            </div>
            <span id="reviewReceiptBadge" class="badge" style="font-size:9.5px;"></span>
          </div>
          <div id="reviewReceiptRemarks" style="font-family:'Roboto',sans-serif; font-size:11px; margin-top:6px; line-height:1.4; color:var(--color-primary,#17324D);"></div>
          <div id="reviewReceiptMeta" style="font-family:'Roboto',sans-serif; font-size:9.5px; margin-top:6px; color:var(--color-text-secondary,#66737D);"></div>
        </div>
      </div>

      <div class="modal-footer" style="background:#FFFFFF; border-top:1px solid var(--color-border,#D9E0E3); padding:12px 18px; display:flex; justify-content:space-between; align-items:center;">
        <!-- Left: Decline Button (only when Pending) -->
        <div>
          <button type="button" class="btn btn-outline btn-sm" id="btnRejectRequisition"
            style="color:var(--color-danger); border-color:var(--color-danger); font-size:11px;"
            onclick="submitRequisitionReview('reject')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:4px; vertical-align:-1px;">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="15" y1="9" x2="9" y2="15"></line>
              <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
            Decline Request
          </button>
        </div>

        <!-- Right: Close or Accept Button -->
        <div style="display:flex; gap:8px; align-items:center;">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('reviewRequisitionModal')" style="font-size:11px;">
            Close
          </button>
          <button type="button" class="btn btn-primary btn-sm" id="btnApproveRequisition"
            style="background:var(--color-success); border-color:var(--color-success); font-size:11px;"
            onclick="submitRequisitionReview('approve')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:4px; vertical-align:-1px;">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            Accept & Allocate
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: Process Resource Allocation (ICDRRMO Only)                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="processAllocationModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Process Resource Allocation</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Review Decision Tree recommendation, verify Central Depot availability, and allocate supplies.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('processAllocationModal')">&times;</button>
    </div>

    <form id="processAllocationForm" onsubmit="event.preventDefault();">
      <input type="hidden" id="procModalItemId">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <!-- Header Info Card -->
        <div style="background:#F8FAFC;border:1px solid var(--color-border-light);border-radius:6px;padding:12px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
              <div style="display:flex;gap:6px;align-items:center;">
                <span class="badge badge-info" id="procModalTrackingBadge" style="font-size:9.5px;">REQ-0000</span>
                <span id="procModalStatusBadge"></span>
              </div>
              <div id="procModalResourceName"
                style="font-weight:800;font-size:16px;color:var(--color-primary);margin-top:4px;"></div>
              <div id="procModalResourceMeta" style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">
              </div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:9.5px;color:var(--color-text-muted);text-transform:uppercase;font-weight:600;">
                Beneficiary Barangay</div>
              <div id="procModalBarangayName" style="font-size:13px;font-weight:700;color:#0F172A;margin-top:2px;">
              </div>
            </div>
          </div>
        </div>

        <!-- Stock Availability & Recommended Card -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div style="background:#F0FDF4;border:1px solid #BBF7D0;padding:10px;border-radius:6px;">
            <div style="font-size:9.5px;color:#166534;text-transform:uppercase;font-weight:700;">Central Depot Available
              Stock</div>
            <div id="procModalDepotAvailDisplay" style="font-size:18px;font-weight:800;color:#15803D;margin-top:2px;">—
            </div>
            <div style="font-size:9.5px;color:#166534;margin-top:2px;">Current ready warehouse balance</div>
          </div>
          <div style="background:#EFF6FF;border:1px solid #BFDBFE;padding:10px;border-radius:6px;">
            <div style="font-size:9.5px;color:#1E40AF;text-transform:uppercase;font-weight:700;">Recommended Quantity
            </div>
            <div id="procModalRecQtyDisplay" style="font-size:18px;font-weight:800;color:#1D4ED8;margin-top:2px;">—
            </div>
            <div style="font-size:9.5px;color:#1E40AF;margin-top:2px;">Calculated by Decision Tree model</div>
          </div>
        </div>

        <!-- Pending Allocation Action Controls (shown when Pending) -->
        <div id="procModalPendingControls"
          style="background:#F8FAFC;border:1px solid var(--color-border);padding:12px;border-radius:6px;display:flex;flex-direction:column;gap:10px;">
          <div style="font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">
            ICDRRMO Allocation Action
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <label class="form-label" style="margin-bottom:0;font-weight:700;">Quantity to Allocate & Dispatch <span
                  style="color:var(--color-danger)">*</span></label>
              <span id="procModalMaxHint" style="font-size:9.5px;color:var(--color-primary);font-weight:600;"></span>
            </div>
            <input type="number" id="procModalAllocQty" class="form-control" min="1" required
              style="font-weight:800;font-size:14px;margin-top:4px;" oninput="validateProcessAllocQty()">
            <div id="procModalQtyWarning"
              style="display:none;font-size:10.5px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;border-radius:4px;padding:6px 10px;margin-top:6px;font-weight:600;">
            </div>
            <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:3px;">
              ⚠️ Deducts in real-time from Central Depot and transfers stock directly to the Barangay local inventory.
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Logistics Dispatch Remarks / Escort Details</label>
            <textarea id="procModalRemarks" class="form-control" rows="2"
              placeholder="e.g. Dispatched via Logistics Truck #2 with PNP escort. Expected delivery within 2 hours."></textarea>
          </div>
        </div>

        <!-- Completed Details Box (shown when Allocated or Declined) -->
        <div id="procModalCompletedDetails" style="display:none;padding:12px;border-radius:6px;"></div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <button type="button" class="btn btn-outline btn-sm" id="btnRejectProcessAlloc"
            style="color:var(--color-danger);border-color:var(--color-danger);"
            onclick="submitProcessAllocation('reject')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:3px;vertical-align:-1px;">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="15" y1="9" x2="9" y2="15"></line>
              <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
            Decline Item
          </button>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('processAllocationModal')">
            Close
          </button>
          <button type="button" class="btn btn-primary btn-sm" id="btnConfirmProcessAlloc"
            style="background:var(--color-success);border-color:var(--color-success);"
            onclick="submitProcessAllocation('allocate')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              style="margin-right:3px;vertical-align:-1px;">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            Accept & Allocate
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: Direct Resource Allocation to Barangay (ICDRRMO Only)            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="directAllocationModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Direct Resource Allocation</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Directly allocate and transfer Central Depot provisions to a Barangay inventory in real time.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('directAllocationModal')">&times;</button>
    </div>

    <form id="directAllocationForm" onsubmit="event.preventDefault(); submitDirectAllocation();">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">

        <input type="hidden" id="directAllocBarangay" value="12">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Allocate to Purok (Hinaplanon) <span
                style="color:var(--color-danger)">*</span></label>
            <select id="directAllocPurok" class="form-control" required>
              <option value="All Puroks">All Puroks (Hinaplanon)</option>
              <?php foreach ($hinaplanonPuroks as $p): ?>
                <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Disaster / Allocation Profile <span
                style="color:var(--color-danger)">*</span></label>
            <select id="directAllocDisasterType" class="form-control" required>
              <option value="Typhoon">Typhoon Pre-positioning</option>
              <option value="Flood">Flood Relief Assistance</option>
              <option value="Flash Flood">Flash Flood Emergency</option>
              <option value="Landslide">Landslide Evacuation Support</option>
              <option value="Storm Surge">Storm Surge Coastal Response</option>
              <option value="Fire">Fire Incident Relief</option>
              <option value="Earthquake">Earthquake Standby Supply</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Central Depot Resource Commodity <span
              style="color:var(--color-danger)">*</span></label>
          <select id="directAllocResource" class="form-control" required onchange="onDirectResourceChange()">
            <option value="">-- Select Central Stock Commodity --</option>
            <?php foreach ($resourcesList as $res): ?>
              <?php if ($res['status'] === 'active'): ?>
                <option value="<?= $res['id'] ?>" data-code="<?= clean($res['code']) ?>"
                  data-name="<?= clean($res['name']) ?>" data-avail="<?= (int) $res['available_quantity'] ?>"
                  data-unit="<?= clean($res['unit']) ?>" data-category="<?= clean($res['category']) ?>">
                  [<?= clean($res['code']) ?>] <?= clean($res['name']) ?> (<?= number_format($res['available_quantity']) ?>
                  <?= clean($res['unit']) ?> available)
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Dynamic Stock Information Card -->
        <div id="directAllocStockCard"
          style="display:none;background:#F0FDF4;border:1px solid #BBF7D0;padding:12px;border-radius:6px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <div style="font-size:10px;color:#166534;font-weight:700;text-transform:uppercase;">Central Depot
                Availability</div>
              <div id="directAllocAvailText" style="font-size:18px;font-weight:800;color:#15803D;margin-top:2px;">0
              </div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:9.5px;color:#166534;font-weight:600;">Category</div>
              <div id="directAllocCategoryText" style="font-size:11.5px;color:#14532D;font-weight:600;margin-top:2px;">—
              </div>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <label class="form-label" style="margin-bottom:0;">Quantity to Allocate <span
                style="color:var(--color-danger)">*</span></label>
            <span id="directAllocMaxHint" style="font-size:9.5px;color:var(--color-primary);font-weight:600;"></span>
          </div>
          <input type="number" id="directAllocQuantity" class="form-control" min="1" required
            placeholder="Enter number of units" style="font-weight:800;font-size:13px;margin-top:4px;"
            oninput="validateDirectAllocQuantity()">
          <div id="directAllocQtyWarning"
            style="display:none;font-size:10.5px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;border-radius:4px;padding:6px 10px;margin-top:6px;font-weight:600;">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Purpose / Allocation Title</label>
          <input type="text" id="directAllocPurpose" class="form-control"
            value="Direct Relief Pre-positioning & Assistance" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Dispatch Notes & Logistics Instructions</label>
          <textarea id="directAllocRemarks" class="form-control" rows="2"
            placeholder="e.g. Loaded onto Truck 3; intended for low-lying coastal puroks."></textarea>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('directAllocationModal')">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitDirectAlloc"
          style="background:var(--color-success);border-color:var(--color-success);">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="margin-right:3px;vertical-align:-1px;">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Dispatch Allocation
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  let currentResource = null;
  let isResourceEditMode = false;

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
      alert(`Please enter a ${field} name.`);
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

    const createSelectId = (field === 'supplier') ? 'create_res_supplier' : `create_res_${field}`;
    const viewSelectId = (field === 'supplier') ? 'view_res_supplier_donor' : `view_res_${field}`;

    ['create', 'view'].forEach(m => {
      const select = document.getElementById((m === 'create') ? createSelectId : viewSelectId);
      if (select) {
        let foundIndex = -1;
        for (let i = 0; i < select.options.length; i++) {
          if (select.options[i].text.toLowerCase() === val.toLowerCase() || select.options[i].value.toLowerCase() === val.toLowerCase()) {
            foundIndex = i;
            break;
          }
        }
        if (foundIndex >= 0) {
          if (m === mode) select.selectedIndex = foundIndex;
        } else {
          const opt = document.createElement('option');
          opt.value = (field === 'supplier' && data && data.id) ? data.id : val;
          opt.textContent = val;
          select.appendChild(opt);
          if (m === mode) select.value = opt.value;
        }
      }
    });

    const activeSelect = document.getElementById((mode === 'create') ? createSelectId : viewSelectId);
    if (activeSelect) activeSelect.value = val;

    input.value = '';
    const container = document.getElementById(`dynAddContainer_${field}_${mode}`);
    if (container) container.style.display = 'none';
  }

  function toggleDynamicCatalogResource(mode) {
    const container = document.getElementById(`dynAddContainer_catalog_resource_${mode}`);
    if (!container) return;
    const isHidden = container.style.display === 'none' || !container.style.display;
    container.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
      const input = document.getElementById(`new_catalog_name_${mode}`);
      if (input) {
        input.value = '';
        input.focus();
      }
    }
  }

  async function confirmDynamicCatalogResource(mode) {
    const nameInput = document.getElementById(`new_catalog_name_${mode}`);
    const catSelect = document.getElementById(`new_catalog_category_${mode}`);
    const unitInput = document.getElementById(`new_catalog_unit_${mode}`);
    if (!nameInput) return;

    const name = nameInput.value.trim();
    if (!name) {
      alert('Please enter a resource item name.');
      nameInput.focus();
      return;
    }
    const category = catSelect ? catSelect.value : 'Relief Goods';
    const unit = (unitInput && unitInput.value.trim()) ? unitInput.value.trim() : 'units';

    try {
      const formData = new FormData();
      formData.append('name', name);
      formData.append('category', category);
      formData.append('unit', unit);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/add_catalog_resource.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.message || 'Error saving to catalog.');
        return;
      }

      const item = data.item;
      const catSelectElem = document.getElementById('create_select_catalog_resource');
      if (catSelectElem) {
        let optgroup = null;
        for (let i = 0; i < catSelectElem.children.length; i++) {
          if (catSelectElem.children[i].tagName === 'OPTGROUP' && catSelectElem.children[i].label.toLowerCase() === item.category.toLowerCase()) {
            optgroup = catSelectElem.children[i];
            break;
          }
        }
        if (!optgroup) {
          optgroup = document.createElement('optgroup');
          optgroup.label = item.category;
          catSelectElem.appendChild(optgroup);
        }

        let opt = catSelectElem.querySelector(`option[value="${item.id}"]`);
        if (!opt) {
          opt = document.createElement('option');
          opt.value = item.id;
          opt.textContent = `${item.name} (${item.code} — ${item.unit})`;
          opt.setAttribute('data-code', item.code);
          opt.setAttribute('data-name', item.name);
          opt.setAttribute('data-category', item.category);
          opt.setAttribute('data-unit', item.unit);
          opt.setAttribute('data-brand', item.brand || '');
          opt.setAttribute('data-description', item.description || '');
          opt.setAttribute('data-is-perishable', item.is_perishable ? '1' : '0');
          optgroup.appendChild(opt);
        }
        catSelectElem.value = item.id;
        onCatalogResourceSelected(catSelectElem);
      }

      toggleDynamicCatalogResource(mode);
      if (typeof showToast === 'function') {
        showToast(data.message, 'success');
      }
    } catch (err) {
      console.error('Error adding catalog resource:', err);
      alert('Network or server error while adding catalog resource.');
    }
  }

  function onCatalogResourceSelected(selectElem) {
    const val = selectElem.value;
    const form = selectElem.closest('form') || document.getElementById('createResourceForm');
    if (!form) return;

    if (val === 'custom') {
      toggleDynamicCatalogResource('create');
      return;
    }

    const opt = selectElem.options[selectElem.selectedIndex];
    if (!opt || !val) {
      return;
    }

    const code = opt.getAttribute('data-code') || '';
    const name = opt.getAttribute('data-name') || '';
    const category = opt.getAttribute('data-category') || '';
    const unit = opt.getAttribute('data-unit') || '';
    const brand = opt.getAttribute('data-brand') || '';
    const desc = opt.getAttribute('data-description') || opt.getAttribute('data-desc') || '';
    const isPerishable = opt.getAttribute('data-is-perishable') === '1';

    if (form.elements['code']) form.elements['code'].value = code;
    if (form.elements['name']) form.elements['name'].value = name;
    if (form.elements['category']) form.elements['category'].value = category;
    if (form.elements['unit']) form.elements['unit'].value = unit;
    if (form.elements['brand'] && brand) form.elements['brand'].value = brand;
    if (form.elements['description']) form.elements['description'].value = desc;
    if (form.elements['is_perishable']) {
      form.elements['is_perishable'].value = isPerishable ? '1' : '0';
      if (typeof togglePerishableFields === 'function') {
        togglePerishableFields('create');
      }
    }
  }

  function openCreateResourceModal() {
    const form = document.getElementById('createResourceForm');
    if (form) form.reset();
    const catSel = document.getElementById('create_select_catalog_resource');
    if (catSel) catSel.selectedIndex = 0;
    const catContainer = document.getElementById('dynAddContainer_catalog_resource_create');
    if (catContainer) catContainer.style.display = 'none';
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
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message, 'success');
        closeModal('createResourceModal');
        setTimeout(() => location.reload(), 700);
      } else {
        showToast(data.message || 'Error adding resource.', 'danger');
        btn.disabled = false;
        btn.innerText = 'Add Resource';
      }
    } catch (err) {
      showToast('Network or server error.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Add Resource';
    }
  }

  function openViewResourceModal(r) {
    currentResource = r;
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
      let matched = false;
      for (let i = 0; i < suppSelect.options.length; i++) {
        const optVal = suppSelect.options[i].value;
        const optText = suppSelect.options[i].textContent.trim().toLowerCase();
        if ((r.supplier_id && optVal == r.supplier_id) ||
          (r.supplier_donor && (optText === r.supplier_donor.trim().toLowerCase() || optVal === r.supplier_donor)) ||
          (r.supplier_name && optText === r.supplier_name.trim().toLowerCase())) {
          suppSelect.selectedIndex = i;
          matched = true;
          break;
        }
      }
      if (!matched && (r.supplier_donor || r.supplier_name)) {
        const suppText = r.supplier_donor || r.supplier_name;
        const opt = document.createElement('option');
        opt.value = r.supplier_id || suppText;
        opt.textContent = suppText;
        suppSelect.appendChild(opt);
        suppSelect.value = opt.value;
      } else if (!matched) {
        suppSelect.value = '';
      }
    }

    document.getElementById('view_res_batch_number').value = r.batch_number || '';

    // Badges
    document.getElementById('badge_res_available').innerText = (r.available_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_in_use').innerText = (r.in_use_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_damaged').innerText = (r.damaged_quantity || 0) + ' ' + (r.unit || '');
    document.getElementById('badge_res_total').innerText = (r.total_quantity || 0) + ' ' + (r.unit || '');

    // Condition badge
    const condBadge = document.getElementById('view_res_condition_badge');
    const condColors = { 'New': 'badge-success', 'Good': 'badge-info', 'Fair': 'badge-warning', 'Poor': 'badge-danger', 'Expired': 'badge-danger' };
    condBadge.className = 'badge ' + (condColors[r.item_condition] || 'badge-neutral');
    condBadge.innerText = r.item_condition || 'New';

    // Expiry alert
    const expiryAlert = document.getElementById('view_res_expiry_alert');
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
        expiryAlert.innerHTML = '⚠️ <strong>EXPIRED:</strong> This item expired on ' + r.expiry_date + ' (' + Math.abs(diffDays) + ' days ago). Consider disposal or write-off.';
      } else if (diffDays <= 30) {
        expiryAlert.style.display = 'flex';
        expiryAlert.style.background = '#FEF3C7';
        expiryAlert.style.border = '1px solid #FDE68A';
        expiryAlert.style.color = '#92400E';
        expiryAlert.innerHTML = '⏳ <strong>Expiring Soon:</strong> This item expires on ' + r.expiry_date + ' (' + diffDays + ' days remaining). Plan for distribution or replacement.';
      } else {
        expiryAlert.style.display = 'none';
      }
    } else {
      expiryAlert.style.display = 'none';
    }

    // Status badge & buttons depending on status
    const isArchived = (r.status === 'archived');
    const statusBadge = document.getElementById('view_res_status_badge');
    const archiveBtn = document.getElementById('btnArchiveResource');
    const restoreBtn = document.getElementById('btnRestoreResource');
    const editBtn = document.getElementById('btnToggleEditResource');

    if (isArchived) {
      if (statusBadge) {
        statusBadge.className = 'badge badge-neutral';
        statusBadge.innerText = 'Archived';
      }
      if (archiveBtn) archiveBtn.style.display = 'none';
      if (restoreBtn) restoreBtn.style.display = 'inline-flex';
      if (editBtn) editBtn.style.display = 'none';
    } else {
      if (statusBadge) {
        statusBadge.className = 'badge badge-success';
        statusBadge.innerText = 'Active';
      }
      if (archiveBtn) archiveBtn.style.display = 'inline-flex';
      if (restoreBtn) restoreBtn.style.display = 'none';
      if (editBtn) editBtn.style.display = 'inline-flex';
    }

    cancelResourceEditMode();
    openModal('viewResourceModal');
  }

  function openEditResourceModal(r) {
    openViewResourceModal(r);
    enableResourceEditMode();
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
      if (suppSelect) suppSelect.value = r.supplier_id || '';
      document.getElementById('view_res_batch_number').value = r.batch_number || '';
    }

    ['category', 'brand', 'supplier'].forEach(f => {
      const c = document.getElementById(`dynAddContainer_${f}_view`);
      if (c) c.style.display = 'none';
    });

    setResourceFormInputsDisabled(true);
    document.getElementById('view_res_edit_banner').style.display = 'none';

    const isArchived = currentResource && (currentResource.status === 'archived');
    const editBtn = document.getElementById('btnToggleEditResource');
    if (editBtn) editBtn.style.display = isArchived ? 'none' : 'inline-flex';

    document.getElementById('btnCloseViewModal').style.display = 'inline-flex';
    document.getElementById('btnSaveResource').style.display = 'none';
    document.getElementById('btnCancelEditResource').style.display = 'none';
  }

  function setResourceFormInputsDisabled(disabled) {
    const fields = [
      'view_res_code', 'view_res_category', 'view_res_name', 'view_res_unit',
      'view_res_min_threshold', 'view_res_available_quantity', 'view_res_in_use_quantity',
      'view_res_damaged_quantity', 'view_res_description',
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
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message, 'success');
        closeModal('viewResourceModal');
        setTimeout(() => location.reload(), 700);
      } else {
        showToast(data.message || 'Error updating resource.', 'danger');
        btn.disabled = false;
        btn.innerText = 'Save Changes';
      }
    } catch (err) {
      showToast('Network or server error.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  }

  // Delete Resource Handler (Permanent Removal)
  function onDeleteResourceClick() {
    if (!currentResource || !currentResource.id) {
      showToast('No resource selected.', 'warning');
      return;
    }
    const r = currentResource;
    showConfirmModal(
      'Confirm Permanent Deletion',
      `Are you sure you want to permanently delete Resource [${r.code}] ${r.name}? This will remove the item and all its transaction history. This action cannot be undone.`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', r.id);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/delete.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            closeModal('viewResourceModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Deletion failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error while deleting resource.', 'danger');
        }
      }
    );
  }

  // Archive Resource Handler (Moves to Archived Tab)
  function onArchiveResourceClick() {
    if (!currentResource || !currentResource.id) {
      showToast('No resource selected.', 'warning');
      return;
    }
    const r = currentResource;
    showConfirmModal(
      'Confirm Archive Resource',
      `Are you sure you want to archive Resource [${r.code}] ${r.name}? It will be removed from active allocations and can be restored at any time.`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', r.id);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/archive.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            closeModal('viewResourceModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Archiving failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error while archiving resource.', 'danger');
        }
      }
    );
  }

  // Restore Resource Handler from View Modal
  function onRestoreResourceClick() {
    if (!currentResource || !currentResource.id) {
      showToast('No resource selected.', 'warning');
      return;
    }
    restoreResourceDirect(currentResource.id, currentResource.name);
  }

  // Direct Restore Handler (used by table button and modal)
  function restoreResourceDirect(id, name) {
    showConfirmModal(
      'Confirm Restore Resource',
      `Are you sure you want to restore Resource "${name}" back to Active Inventory?`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', id);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/restore.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            closeModal('viewResourceModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Restoration failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error while restoring resource.', 'danger');
        }
      }
    );
  }

  function archiveResourceDirect(id, name, code) {
    showConfirmModal(
      'Confirm Archive Resource',
      `Are you sure you want to archive Resource [${code}] ${name}? It will be moved to the Archived Resources tab and can be restored at any time.`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', id);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/archive.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Archiving failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error while archiving resource.', 'danger');
        }
      }
    );
  }

  function deleteResourceDirect(id, name, code) {
    showConfirmModal(
      'Confirm Permanent Deletion',
      `Are you sure you want to permanently delete Resource [${code}] ${name}? This will remove the item and all its transaction history. This action cannot be undone.`,
      async () => {
        try {
          const formData = new FormData();
          formData.append('id', id);

          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/delete.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Deletion failed.', 'danger');
          }
        } catch (err) {
          showToast('Network error while deleting resource.', 'danger');
        }
      }
    );
  }

  let currentRequisition = null;

  function openReviewRequisitionModal(req) {
    currentRequisition = req;
    document.getElementById('review_req_id').value = req.id;
    document.getElementById('reviewReqCode').innerText = req.request_code;
    document.getElementById('reviewReqBarangay').innerText = `Brgy. ${req.barangay_name}`;
    document.getElementById('reviewReqItemName').innerText = req.item_name;
    document.getElementById('reviewReqMetaSub').innerText = `Category: ${req.category}` + (req.central_res_code ? ` • SKU: [${req.central_res_code}]` : '') + ` • Submitted by ${req.requested_by_name || 'Barangay Head'}`;
    document.getElementById('reviewReqQuantityDisplay').innerText = `${parseInt(req.requested_quantity).toLocaleString()}`;
    
    const unitDisp = document.getElementById('reviewReqUnitDisplay');
    if (unitDisp) unitDisp.innerText = req.unit || 'units';

    const dateDisp = document.getElementById('reviewReqDate');
    if (dateDisp) dateDisp.innerText = req.created_at || 'Recently';

    const submitterDisp = document.getElementById('reviewReqSubmitter');
    if (submitterDisp) submitterDisp.innerText = `Origin: Brgy. ${req.barangay_name} • By: ${req.requested_by_name || 'Barangay Head'}`;

    const urgBadge = document.getElementById('reviewReqUrgencyBadge');
    if (urgBadge) {
      urgBadge.innerText = req.urgency;
      if (req.urgency === 'Immediate') {
        urgBadge.className = 'badge badge-danger';
      } else if (req.urgency === 'High') {
        urgBadge.className = 'badge badge-warning';
      } else {
        urgBadge.className = 'badge badge-info';
      }
    }

    // Central depot stock display
    updateDepotStockDisplay(req.central_res_avail, req.central_res_unit || req.unit, req.requested_quantity);

    // Background fetch to ensure Central Depot stock is 100% current and fresh
    fetch('<?= BASE_URL ?>/backend/functions/resources/get_catalog.php')
      .then(r => r.json())
      .then(data => {
        if (data.success && data.catalog) {
          const matched = data.catalog.find(c => c.id == req.resource_id || c.name.toLowerCase() === req.item_name.toLowerCase());
          if (matched) {
            req.central_res_avail = matched.available_quantity;
            req.central_res_unit = matched.unit;
            req.central_res_code = matched.code;
            updateDepotStockDisplay(matched.available_quantity, matched.unit, req.requested_quantity);
            validateReviewQuantity();
          }
        }
      })
      .catch(() => { });

    document.getElementById('reviewReqUrgency').innerText = req.urgency;
    document.getElementById('reviewReqTargetPurok').innerText = req.target_purok || 'Barangay-wide Hall Depot';
    document.getElementById('reviewReqPurpose').innerText = req.purpose || 'No justification provided.';

    // Status Badge & Controls
    const badge = document.getElementById('reviewReqStatusBadge');
    const pendingControls = document.getElementById('reviewPendingControls');
    const completedDetails = document.getElementById('reviewCompletedDetails');
    const btnApprove = document.getElementById('btnApproveRequisition');
    const btnReject = document.getElementById('btnRejectRequisition');
    const alertBox = document.getElementById('reviewQtyValidationAlert');
    if (alertBox) alertBox.style.display = 'none';

    if (req.status === 'Pending') {
      badge.className = 'badge badge-warning';
      badge.innerText = 'Pending Review';

      pendingControls.style.display = 'flex';
      completedDetails.style.display = 'none';
      btnApprove.style.display = 'inline-flex';
      btnReject.style.display = 'inline-flex';

      const avail = (req.central_res_avail !== null && req.central_res_avail !== undefined) ? parseInt(req.central_res_avail) : null;
      const reqQty = parseInt(req.requested_quantity);
      const initialApproved = (avail !== null && avail < reqQty) ? avail : reqQty;

      const approvedInput = document.getElementById('review_approved_quantity');
      approvedInput.value = initialApproved > 0 ? initialApproved : reqQty;
      if (avail !== null) approvedInput.max = avail;

      const hint = document.getElementById('reviewQuantityMaxHint');
      if (hint && avail !== null) {
        hint.innerText = `Depot Stock: ${avail.toLocaleString()} ${req.central_res_unit || req.unit}`;
      }

      document.getElementById('review_remarks').value = '';
      validateReviewQuantity();

      const receiptDetails = document.getElementById('reviewReceiptDetails');
      if (receiptDetails) receiptDetails.style.display = 'none';
    } else {
      pendingControls.style.display = 'none';
      completedDetails.style.display = 'block';
      btnApprove.style.display = 'none';
      btnReject.style.display = 'none';

      const receiptDetails = document.getElementById('reviewReceiptDetails');

      if (req.status === 'Approved') {
        if (req.receipt_status === 'Received') {
          badge.className = 'badge badge-success';
          badge.innerText = 'Received';
        } else if (req.receipt_status === 'Not Received') {
          badge.className = 'badge badge-danger';
          badge.innerText = 'Not Received';
        } else {
          badge.className = 'badge badge-warning';
          badge.innerText = 'Awaiting Receipt';
        }
        completedDetails.style.background = '#F0FDF4';
        completedDetails.style.border = '1px solid #BBF7D0';
        document.getElementById('reviewCompletedTitle').innerText = `Allocated (${req.approved_quantity} ${req.unit})`;
        document.getElementById('reviewCompletedTitle').style.color = '#166534';
        document.getElementById('reviewCompletedRemarks').style.color = '#15803D';

        if (receiptDetails) {
          receiptDetails.style.display = 'block';
          const recBadge = document.getElementById('reviewReceiptBadge');
          const recRemarks = document.getElementById('reviewReceiptRemarks');
          const recMeta = document.getElementById('reviewReceiptMeta');

          if (req.receipt_status === 'Received') {
            receiptDetails.style.background = '#F0FDF4';
            receiptDetails.style.border = '1px solid #BBF7D0';
            recBadge.className = 'badge';
            recBadge.style.background = '#E8F8F0';
            recBadge.style.color = '#0F5132';
            recBadge.style.border = '1px solid #C3E6CB';
            recBadge.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:2px;vertical-align:-1px;"><polyline points="20 6 9 17 4 12"></polyline></svg> Received by Barangay';
            recRemarks.innerText = req.receipt_remarks ? `Barangay Remarks: "${req.receipt_remarks}"` : 'Supplies acknowledged and confirmed received in full by Barangay.';
            recMeta.innerText = `Confirmed by: ${req.received_by_name || 'Barangay Head'}` + (req.received_at ? ` on ${req.received_at}` : '');
          } else if (req.receipt_status === 'Not Received') {
            receiptDetails.style.background = '#FEF2F2';
            receiptDetails.style.border = '1px solid #FECACA';
            recBadge.className = 'badge';
            recBadge.style.background = '#FDE8E8';
            recBadge.style.color = '#9B1C1C';
            recBadge.style.border = '1px solid #F8B4B4';
            recBadge.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:2px;vertical-align:-1px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Flagged Not Received';
            recRemarks.innerText = req.receipt_remarks ? `Barangay Report: "${req.receipt_remarks}"` : 'Barangay reported that the approved supplies have not been received.';
            recMeta.innerText = `Flagged by: ${req.received_by_name || 'Barangay Head'}` + (req.received_at ? ` on ${req.received_at}` : '');
          } else {
            receiptDetails.style.background = '#FFFBEB';
            receiptDetails.style.border = '1px solid #FDE68A';
            recBadge.className = 'badge';
            recBadge.style.background = '#FEF3C7';
            recBadge.style.color = '#92400E';
            recBadge.style.border = '1px solid #FDE68A';
            recBadge.innerHTML = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Awaiting Barangay Confirmation';
            recRemarks.innerText = 'Dispatched from depot. Awaiting receipt confirmation from Barangay Head upon supply arrival.';
            recMeta.innerText = 'Delivery Status: In transit / Dispatched to Barangay';
          }
        }
      } else {
        badge.className = 'badge badge-danger';
        badge.innerText = 'Declined';
        completedDetails.style.background = '#FEF2F2';
        completedDetails.style.border = '1px solid #FECACA';
        document.getElementById('reviewCompletedTitle').innerText = 'Requisition Declined';
        document.getElementById('reviewCompletedTitle').style.color = '#991B1B';
        document.getElementById('reviewCompletedRemarks').style.color = '#B91C1C';
        if (receiptDetails) receiptDetails.style.display = 'none';
      }

      document.getElementById('reviewCompletedRemarks').innerText = req.review_remarks || '(No remarks provided)';
      document.getElementById('reviewCompletedMeta').innerText = `Reviewed by ${req.reviewed_by_name || 'ICDRRMO Admin'} on ${req.reviewed_at || ''}`;
    }

    openModal('reviewRequisitionModal');
  }

  function updateDepotStockDisplay(avail, unit, requestedQty) {
    const depotDisp = document.getElementById('reviewDepotAvailDisplay');
    const depotUnit = document.getElementById('reviewDepotUnitDisplay');
    const badge = document.getElementById('reviewDepotStockBadge');
    const totalSub = document.getElementById('reviewDepotTotalSub');
    const progress = document.getElementById('reviewStockProgressBar');
    const ratioPercent = document.getElementById('reviewStockRatioPercent');
    const notice = document.getElementById('reviewStockFulfillmentNotice');
    const btnApprove = document.getElementById('btnApproveRequisition');
    const approvedInput = document.getElementById('review_approved_quantity');
    const hint = document.getElementById('reviewQuantityMaxHint');

    const reqQty = parseInt(requestedQty) || 1;
    const availNum = (avail !== null && avail !== undefined) ? parseInt(avail) : null;
    const unitStr = unit || (currentRequisition ? currentRequisition.unit : 'units');

    if (depotUnit) depotUnit.innerText = `${unitStr} available`;

    if (availNum === null) {
      if (depotDisp) { depotDisp.innerText = 'Custom'; depotDisp.style.color = 'var(--color-text-secondary,#66737D)'; }
      if (badge) { badge.className = 'badge badge-secondary'; badge.innerText = 'Custom Item'; }
      if (progress) { progress.style.width = '100%'; progress.style.background = '#64748B'; }
      if (ratioPercent) ratioPercent.innerText = 'Custom Definition';
      if (notice) notice.innerText = 'This resource item is custom-specified and not linked to a Central Depot SKU.';
      return;
    }

    if (depotDisp) depotDisp.innerText = availNum.toLocaleString();

    if (availNum <= 0) {
      // 0 left - Out of stock!
      if (depotDisp) depotDisp.style.color = '#DC2626';
      if (badge) {
        badge.className = 'badge badge-danger';
        badge.innerText = 'Out of Stock (0 Left)';
      }
      if (totalSub) totalSub.innerText = 'Central Depot inventory depleted';
      if (progress) { progress.style.width = '0%'; progress.style.background = '#DC2626'; }
      if (ratioPercent) { ratioPercent.innerText = '0%'; ratioPercent.style.color = '#DC2626'; }
      if (notice) {
        notice.innerHTML = '<span style="color:#DC2626; font-weight:700;">⚠ Out of Stock:</span> Central Depot currently has <strong>0 ' + unitStr + '</strong> available. This request cannot be allocated until new stock is replenished.';
      }
      if (approvedInput) {
        approvedInput.value = 0;
        approvedInput.max = 0;
        approvedInput.disabled = true;
      }
      if (hint) {
        hint.innerText = 'Depot Stock: 0 available (Depleted)';
        hint.style.color = '#DC2626';
      }
      if (btnApprove && currentRequisition && currentRequisition.status === 'Pending') {
        btnApprove.disabled = true;
        btnApprove.title = 'Cannot allocate: Central Depot stock is 0.';
      }
    } else if (availNum < reqQty) {
      // Limited stock (e.g. 10 only left, but requested 50!)
      if (depotDisp) depotDisp.style.color = '#D97706';
      if (badge) {
        badge.className = 'badge badge-warning';
        badge.innerText = `Limited Stock (${availNum.toLocaleString()} Left)`;
      }
      if (totalSub) totalSub.innerText = `Deficit: ${(reqQty - availNum).toLocaleString()} ${unitStr} short of request`;
      const pct = Math.min(100, Math.round((availNum / reqQty) * 100));
      if (progress) { progress.style.width = `${pct}%`; progress.style.background = '#D97706'; }
      if (ratioPercent) { ratioPercent.innerText = `${pct}% stock coverage`; ratioPercent.style.color = '#D97706'; }
      if (notice) {
        notice.innerHTML = `<span style="color:#D97706; font-weight:700;">⚠ Partial Stock Available:</span> Barangay requested <strong>${reqQty.toLocaleString()} ${unitStr}</strong>, but Central Depot has only <strong>${availNum.toLocaleString()} ${unitStr}</strong>. Allocation is capped at maximum <strong>${availNum.toLocaleString()} ${unitStr}</strong>.`;
      }
      if (approvedInput) {
        approvedInput.disabled = false;
        approvedInput.max = availNum;
        if (parseInt(approvedInput.value) > availNum || parseInt(approvedInput.value) <= 0) {
          approvedInput.value = availNum;
        }
      }
      if (hint) {
        hint.innerText = `Max allocatable: ${availNum.toLocaleString()} ${unitStr} (Limited Stock)`;
        hint.style.color = '#D97706';
      }
      if (btnApprove && currentRequisition && currentRequisition.status === 'Pending') {
        btnApprove.disabled = false;
      }
    } else {
      // Sufficient stock (availNum >= reqQty)
      if (depotDisp) depotDisp.style.color = '#16A34A';
      if (badge) {
        badge.className = 'badge badge-success';
        badge.innerText = `In Stock (${availNum.toLocaleString()} Available)`;
      }
      if (totalSub) totalSub.innerText = `Remaining after allocation: ${(availNum - reqQty).toLocaleString()} ${unitStr}`;
      if (progress) { progress.style.width = '100%'; progress.style.background = '#16A34A'; }
      if (ratioPercent) { ratioPercent.innerText = '100% Sufficient'; ratioPercent.style.color = '#16A34A'; }
      if (notice) {
        notice.innerHTML = `<span style="color:#16A34A; font-weight:700;">✓ Stock Fully Sufficient:</span> ICDRRMO Central Depot has <strong>${availNum.toLocaleString()} ${unitStr}</strong> available, fully covering the requested <strong>${reqQty.toLocaleString()} ${unitStr}</strong>.`;
      }
      if (approvedInput) {
        approvedInput.disabled = false;
        approvedInput.max = availNum;
      }
      if (hint) {
        hint.innerText = `Depot Stock: ${availNum.toLocaleString()} ${unitStr} available`;
        hint.style.color = 'var(--color-secondary,#2F6F73)';
      }
      if (btnApprove && currentRequisition && currentRequisition.status === 'Pending') {
        btnApprove.disabled = false;
      }
    }
  }

  // Live quantity validation in review modal
  function validateReviewQuantity() {
    const input = document.getElementById('review_approved_quantity');
    const alertBox = document.getElementById('reviewQtyValidationAlert');
    const btnApprove = document.getElementById('btnApproveRequisition');
    if (!input || !currentRequisition) return;

    const qty = parseInt(input.value) || 0;
    const avail = (currentRequisition.central_res_avail !== null && currentRequisition.central_res_avail !== undefined)
      ? parseInt(currentRequisition.central_res_avail)
      : null;

    if (avail !== null && avail <= 0) {
      if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.innerText = '⚠ Central Depot stock is depleted (0 available). Cannot allocate.';
      }
      if (btnApprove) btnApprove.disabled = true;
      return;
    }

    if (qty <= 0) {
      if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.innerText = '⚠ Approved quantity must be at least 1.';
      }
      if (btnApprove) btnApprove.disabled = true;
      return;
    }

    if (avail !== null && qty > avail) {
      if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.innerText = `⚠ Cannot approve ${qty}. Only ${avail.toLocaleString()} available in Central Depot stock.`;
      }
      if (btnApprove) btnApprove.disabled = true;
      return;
    }

    if (alertBox) alertBox.style.display = 'none';
    if (btnApprove) btnApprove.disabled = false;
  }

  // Submit Requisition Review (Approve / Decline)
  async function submitRequisitionReview(action) {
    if (!currentRequisition) return;

    const reqId = currentRequisition.id;
    const approvedQty = document.getElementById('review_approved_quantity').value;
    const remarks = document.getElementById('review_remarks').value.trim();

    if (action === 'approve') {
      if (!approvedQty || parseInt(approvedQty) <= 0) {
        showToast('Please enter a valid approved quantity.', 'danger');
        return;
      }
      const avail = (currentRequisition.central_res_avail !== null && currentRequisition.central_res_avail !== undefined)
        ? parseInt(currentRequisition.central_res_avail)
        : null;
      if (avail !== null && parseInt(approvedQty) > avail) {
        showToast(`Cannot approve ${approvedQty}. Central Depot only has ${avail.toLocaleString()} units available.`, 'danger');
        return;
      }
    }

    const confirmTitle = action === 'approve' ? 'Accept & Allocate Requisition' : 'Decline Supply Requisition';
    const confirmMsg = action === 'approve'
      ? `Accept requisition ${currentRequisition.request_code} and allocate ${approvedQty} ${currentRequisition.unit} to Brgy. ${currentRequisition.barangay_name}? This will immediately deduct ${approvedQty} ${currentRequisition.unit} from Central Depot in real-time.`
      : `Decline requisition ${currentRequisition.request_code} from Brgy. ${currentRequisition.barangay_name}? Central stock will not be deducted.`;

    showConfirmModal(
      confirmTitle,
      confirmMsg,
      async () => {
        const formData = new FormData();
        formData.append('request_id', reqId);
        formData.append('action', action);
        formData.append('approved_quantity', approvedQty);
        formData.append('review_remarks', remarks);

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/review_request.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            closeModal('reviewRequisitionModal');
            updateRequisitionRowInDOM(data, remarks);
          } else {
            showToast(data.message || 'Operation failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error while processing requisition.', 'danger');
        }
      }
    );
  }

  // 1-Click Quick Accept directly from table
  function quickAcceptRequisition(req) {
    currentRequisition = req;
    const reqQty = parseInt(req.requested_quantity);
    const avail = (req.central_res_avail !== null && req.central_res_avail !== undefined) ? parseInt(req.central_res_avail) : null;

    if (avail !== null && reqQty > avail) {
      // If requested quantity exceeds depot available stock, open full modal so admin can adjust approved qty
      showToast(`Requested quantity (${reqQty}) exceeds Central Depot stock (${avail} available). Please adjust quantity in the review dialog.`, 'warning');
      openReviewRequisitionModal(req);
      return;
    }

    showConfirmModal(
      'Quick Accept Requisition',
      `Accept requisition ${req.request_code} for Brgy. ${req.barangay_name} (${reqQty} ${req.unit} of ${req.item_name})? This will immediately deduct ${reqQty} units from Central Depot in real-time.`,
      async () => {
        const formData = new FormData();
        formData.append('request_id', req.id);
        formData.append('action', 'approve');
        formData.append('approved_quantity', reqQty);
        formData.append('review_remarks', 'Approved for immediate dispatch via quick allocation.');

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/review_request.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            updateRequisitionRowInDOM(data, 'Approved for immediate dispatch via quick allocation.');
          } else {
            showToast(data.message || 'Quick accept failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error processing requisition.', 'danger');
        }
      }
    );
  }

  // 1-Click Quick Decline directly from table
  function quickDeclineRequisition(req) {
    currentRequisition = req;
    showConfirmModal(
      'Decline Requisition',
      `Are you sure you want to decline requisition ${req.request_code} from Brgy. ${req.barangay_name}?`,
      async () => {
        const formData = new FormData();
        formData.append('request_id', req.id);
        formData.append('action', 'reject');
        formData.append('review_remarks', 'Declined by ICDRRMO logistics administration.');

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/review_request.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            updateRequisitionRowInDOM(data, 'Declined by ICDRRMO logistics administration.');
          } else {
            showToast(data.message || 'Decline failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error processing requisition.', 'danger');
        }
      }
    );
  }

  // Real-Time DOM Updater (updates table rows, badges, and top metric counters without page refresh)
  function updateRequisitionRowInDOM(data, remarks) {
    const reqId = data.request_id;
    const status = data.status;

    // 1. Update status badge in table
    const statusCell = document.getElementById(`req-status-cell-${reqId}`);
    if (statusCell) {
      if (status === 'Approved') {
        statusCell.innerHTML = '<span class="badge badge-warning" style="font-size:9.5px;padding:3px 8px;font-weight:600;display:inline-flex;align-items:center;gap:3px;"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Awaiting Receipt</span>';
      } else {
        statusCell.innerHTML = '<span class="badge badge-danger">Declined</span>';
      }
    }

    // 2. Update approved quantity label
    const approvedDisp = document.getElementById(`req-approved-disp-${reqId}`);
    if (approvedDisp && status === 'Approved') {
      approvedDisp.style.display = 'block';
      approvedDisp.innerText = `Approved: ${parseInt(data.approved_quantity).toLocaleString()} units`;
    }

    // 3. Update action cell to clean View button
    const actionsCell = document.getElementById(`req-actions-cell-${reqId}`);
    if (actionsCell) {
      if (currentRequisition && currentRequisition.id == reqId) {
        currentRequisition.status = status;
        currentRequisition.approved_quantity = data.approved_quantity;
        currentRequisition.review_remarks = remarks;
        currentRequisition.receipt_status = 'Pending';
        currentRequisition.reviewed_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
        actionsCell.innerHTML = `<button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick='openReviewRequisitionModal(${JSON.stringify(currentRequisition)})'><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>View</button>`;
      } else {
        actionsCell.innerHTML = `<button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick="location.reload()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>View</button>`;
      }
    }

    // 4. Update Pending Badges on Tab and Metrics Card
    const pendingBadgeTab = document.getElementById('tabPendingReqsBadge');
    if (pendingBadgeTab) {
      let curr = parseInt(pendingBadgeTab.innerText) || 0;
      curr = Math.max(0, curr - 1);
      if (curr > 0) {
        pendingBadgeTab.innerText = `${curr} Pending`;
      } else {
        pendingBadgeTab.style.display = 'none';
      }
    }

    const metricPending = document.getElementById('metricPendingReqs');
    if (metricPending) {
      let curr = parseInt(metricPending.innerText) || 0;
      curr = Math.max(0, curr - 1);
      metricPending.innerHTML = `${curr} <span style="font-size:12px;font-weight:500;color:var(--color-text-muted);">Pending</span>`;
    }

    // 5. Real-Time Central Depot Stock Deduction update on Top Metric Card
    if (status === 'Approved' && data.approved_quantity) {
      const stockMetric = document.getElementById('metricCentralStock');
      if (stockMetric) {
        let currStock = parseInt(stockMetric.innerText.replace(/,/g, '')) || 0;
        currStock = Math.max(0, currStock - parseInt(data.approved_quantity));
        stockMetric.innerText = currStock.toLocaleString();
      }

      // 6. Update Active Resources table stock cell if present in DOM
      if (data.resource_id && data.remaining_available !== undefined) {
        const stockCell = document.getElementById(`res-stock-cell-${data.resource_id}`);
        if (stockCell) {
          const availSpan = stockCell.querySelector('.res-avail-val');
          if (availSpan) availSpan.innerText = parseInt(data.remaining_available).toLocaleString();
          const totalSpan = stockCell.querySelector('.res-total-val');
          if (totalSpan && data.remaining_total !== undefined) totalSpan.innerText = parseInt(data.remaining_total).toLocaleString();
        }
      }
    }
  }

  // ============================================================================
  // RESOURCE ALLOCATION PROCESSING ENGINE (ICDRRMO Admin Only)
  // ============================================================================

  let currentProcItem = null;

  // Open Process Allocation Modal for a Recommended Item
  function openProcessAllocationModal(itemId) {
    const row = document.getElementById(`alloc-row-${itemId}`);
    if (!row) return;

    currentProcItem = {
      id: itemId,
      recId: row.dataset.recId,
      resourceId: row.dataset.resourceId,
      resourceName: row.dataset.resourceName,
      resourceCode: row.dataset.resourceCode,
      unit: row.dataset.unit,
      recommendedQty: parseInt(row.dataset.recommendedQty) || 0,
      depotAvail: parseInt(row.dataset.depotAvail) || 0,
      trackingCode: row.dataset.trackingCode,
      barangayName: row.dataset.barangayName,
      purokName: row.dataset.purokName || 'All Puroks',
      status: row.dataset.status
    };

    document.getElementById('procModalItemId').value = itemId;
    document.getElementById('procModalTrackingBadge').innerText = currentProcItem.trackingCode;
    document.getElementById('procModalBarangayName').innerText = (currentProcItem.purokName && currentProcItem.purokName !== 'All Puroks')
      ? `${currentProcItem.purokName}, Hinaplanon`
      : 'Brgy. ' + currentProcItem.barangayName;
    document.getElementById('procModalResourceName').innerText = currentProcItem.resourceName;
    document.getElementById('procModalResourceMeta').innerText = `SKU: ${currentProcItem.resourceCode} • ${currentProcItem.unit}`;
    document.getElementById('procModalDepotAvailDisplay').innerText = `${currentProcItem.depotAvail.toLocaleString()} ${currentProcItem.unit}`;
    document.getElementById('procModalRecQtyDisplay').innerText = `${currentProcItem.recommendedQty.toLocaleString()} ${currentProcItem.unit}`;

    const allocInput = document.getElementById('procModalAllocQty');
    const safeQty = (currentProcItem.depotAvail > 0) ? Math.min(currentProcItem.recommendedQty, currentProcItem.depotAvail) : currentProcItem.recommendedQty;
    allocInput.value = safeQty;
    document.getElementById('procModalMaxHint').innerText = `Central Stock: ${currentProcItem.depotAvail.toLocaleString()} ${currentProcItem.unit}`;
    document.getElementById('procModalRemarks').value = '';

    // Toggle Controls & Buttons based on Status
    const pendingControls = document.getElementById('procModalPendingControls');
    const completedDetails = document.getElementById('procModalCompletedDetails');
    const btnConfirm = document.getElementById('btnConfirmProcessAlloc');
    const btnReject = document.getElementById('btnRejectProcessAlloc');
    const statusBadge = document.getElementById('procModalStatusBadge');

    if (currentProcItem.status === 'Pending') {
      statusBadge.innerHTML = '<span class="badge badge-warning" style="font-size:9.5px;">Pending Allocation</span>';
      pendingControls.style.display = 'flex';
      completedDetails.style.display = 'none';
      btnConfirm.style.display = 'inline-flex';
      btnReject.style.display = 'inline-flex';
      validateProcessAllocQty();
    } else {
      pendingControls.style.display = 'none';
      completedDetails.style.display = 'block';
      btnConfirm.style.display = 'none';
      btnReject.style.display = 'none';

      if (currentProcItem.status === 'Allocated') {
        statusBadge.innerHTML = '<span class="badge badge-success" style="font-size:9.5px;">Allocated & Dispatched</span>';
        completedDetails.style.background = '#F0FDF4';
        completedDetails.style.border = '1px solid #BBF7D0';
        completedDetails.innerHTML = `
        <div style="font-weight:700;font-size:12px;color:#166534;">✅ Resource Dispatched & Transferred</div>
        <div style="font-size:11.5px;color:#15803D;margin-top:4px;">
          This resource commodity has been officially allocated from Central Depot and credited to Brgy. ${currentProcItem.barangayName}'s local inventory.
        </div>
      `;
      } else {
        statusBadge.innerHTML = '<span class="badge badge-danger" style="font-size:9.5px;">Declined</span>';
        completedDetails.style.background = '#FEF2F2';
        completedDetails.style.border = '1px solid #FECACA';
        completedDetails.innerHTML = `
        <div style="font-weight:700;font-size:12px;color:#991B1B;">❌ Allocation Declined</div>
        <div style="font-size:11.5px;color:#B91C1C;margin-top:4px;">
          This allocation item was declined by ICDRRMO logistics administration. Zero warehouse stock was deducted.
        </div>
      `;
      }
    }

    openModal('processAllocationModal');
  }

  // Live validation on Process Allocation Quantity
  function validateProcessAllocQty() {
    if (!currentProcItem) return;
    const input = document.getElementById('procModalAllocQty');
    const warning = document.getElementById('procModalQtyWarning');
    const btnConfirm = document.getElementById('btnConfirmProcessAlloc');
    const val = parseInt(input.value) || 0;

    if (val <= 0) {
      warning.style.display = 'block';
      warning.innerText = 'Allocation quantity must be at least 1 unit.';
      btnConfirm.disabled = true;
      btnConfirm.style.opacity = '0.5';
    } else if (val > currentProcItem.depotAvail) {
      warning.style.display = 'block';
      warning.innerText = `Allocation exceeds Central Depot stock! Only ${currentProcItem.depotAvail.toLocaleString()} ${currentProcItem.unit} currently available.`;
      btnConfirm.disabled = true;
      btnConfirm.style.opacity = '0.5';
    } else {
      warning.style.display = 'none';
      btnConfirm.disabled = false;
      btnConfirm.style.opacity = '1';
    }
  }

  // Submit Process Allocation Decision (Allocate or Reject)
  async function submitProcessAllocation(decision) {
    if (!currentProcItem) return;
    const itemId = currentProcItem.id;
    const qty = parseInt(document.getElementById('procModalAllocQty').value) || 0;
    const remarks = document.getElementById('procModalRemarks').value.trim();

    if (decision === 'allocate' && qty <= 0) {
      showToast('Please enter a valid allocation quantity.', 'danger');
      return;
    }

    const btnConfirm = document.getElementById('btnConfirmProcessAlloc');
    const btnReject = document.getElementById('btnRejectProcessAlloc');
    const originalConfirmText = btnConfirm.innerHTML;
    btnConfirm.disabled = true;
    btnReject.disabled = true;
    btnConfirm.innerText = 'Processing...';

    try {
      const formData = new FormData();
      formData.append('item_id', itemId);
      formData.append('decision', decision);
      formData.append('quantity', qty);
      formData.append('remarks', remarks);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/index.php?action=allocate', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();

      if (data.success) {
        showToast(data.message, 'success');
        updateAllocationRowInDOM(data);
        closeModal('processAllocationModal');
      } else {
        showToast(data.message || 'Allocation processing failed.', 'danger');
      }
    } catch (err) {
      console.error(err);
      showToast('Network error processing allocation.', 'danger');
    } finally {
      btnConfirm.disabled = false;
      btnReject.disabled = false;
      btnConfirm.innerHTML = originalConfirmText;
    }
  }

  // 1-Click Quick Allocate Item from Table
  function quickAllocateItem(itemId) {
    const row = document.getElementById(`alloc-row-${itemId}`);
    if (!row) return;

    const recQty = parseInt(row.dataset.recommendedQty) || 0;
    const depotAvail = parseInt(row.dataset.depotAvail) || 0;
    const unit = row.dataset.unit;
    const resName = row.dataset.resourceName;
    const brgyName = row.dataset.barangayName;

    if (depotAvail < recQty) {
      showToast(`Insufficient stock (${depotAvail} available). Click "Process Allocation" to adjust quantity.`, 'warning');
      openProcessAllocationModal(itemId);
      return;
    }

    showConfirmModal(
      'Quick Allocate Resource',
      `Allocate and dispatch ${recQty.toLocaleString()} ${unit} of "${resName}" directly to Brgy. ${brgyName}? Central Depot stock will be deducted in real time.`,
      async () => {
        const formData = new FormData();
        formData.append('item_id', itemId);
        formData.append('decision', 'allocate');
        formData.append('quantity', recQty);
        formData.append('remarks', 'Direct quick allocation via Manage Resources portal');

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/index.php?action=allocate', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            updateAllocationRowInDOM(data);
          } else {
            showToast(data.message || 'Quick allocation failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error during quick allocation.', 'danger');
        }
      }
    );
  }

  // 1-Click Quick Reject Item from Table
  function quickRejectItem(itemId) {
    const row = document.getElementById(`alloc-row-${itemId}`);
    if (!row) return;

    const resName = row.dataset.resourceName;
    const brgyName = row.dataset.barangayName;

    showConfirmModal(
      'Decline Allocation Item',
      `Are you sure you want to decline allocation of "${resName}" for Brgy. ${brgyName}? Zero central stock will be deducted.`,
      async () => {
        const formData = new FormData();
        formData.append('item_id', itemId);
        formData.append('decision', 'reject');
        formData.append('remarks', 'Item allocation declined by ICDRRMO logistics officer');

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/index.php?action=allocate', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');
            updateAllocationRowInDOM(data);
          } else {
            showToast(data.message || 'Decline failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error during item decline.', 'danger');
        }
      }
    );
  }

  // Batch Allocate All Items for a Recommendation
  function batchAllocateRecommendation(recId, recCode, brgyName) {
    showConfirmModal(
      'Confirm Batch Allocation',
      `Allocate and dispatch all pending recommended relief items for ${recCode} to Brgy. ${brgyName}? All provisions will be deducted from Central Depot and credited to Barangay inventory.`,
      async () => {
        const formData = new FormData();
        formData.append('sub_action', 'batch');
        formData.append('recommendation_id', recId);
        formData.append('remarks', `Batch allocation approved for ${recCode}`);

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/index.php?action=allocate', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message, 'success');

            // Update each processed row in DOM
            if (data.processed_item_ids && Array.isArray(data.processed_item_ids)) {
              data.processed_item_ids.forEach(id => {
                const row = document.getElementById(`alloc-row-${id}`);
                if (row) {
                  const recQty = parseInt(row.dataset.recommendedQty) || 0;
                  const unit = row.dataset.unit;
                  updateAllocationRowInDOM({
                    action_type: 'allocate',
                    item_id: id,
                    status: 'Allocated',
                    allocated_quantity: recQty,
                    unit: unit,
                    metrics: data.metrics
                  });
                }
              });
            }

            // Hide batch banner
            const banner = document.getElementById('batchPendingBanner');
            if (banner) banner.style.display = 'none';

          } else {
            showToast(data.message || 'Batch allocation failed.', 'danger');
          }
        } catch (err) {
          console.error(err);
          showToast('Network error during batch allocation.', 'danger');
        }
      }
    );
  }

  // Open Direct Allocation Modal
  function openDirectAllocationModal() {
    document.getElementById('directAllocationForm').reset();
    const bInput = document.getElementById('directAllocBarangay');
    if (bInput) bInput.value = '12';
    document.getElementById('directAllocStockCard').style.display = 'none';
    document.getElementById('directAllocQtyWarning').style.display = 'none';
    document.getElementById('directAllocMaxHint').innerText = '';
    openModal('directAllocationModal');
  }

  // Open Direct Allocation Modal with Pre-selected Barangay
  function openDirectAllocationModalWithBarangay(barangayId) {
    openDirectAllocationModal();
    const bInput = document.getElementById('directAllocBarangay');
    if (bInput) {
      bInput.value = barangayId || '12';
    }
  }

  // On Direct Resource Change
  function onDirectResourceChange() {
    const sel = document.getElementById('directAllocResource');
    const opt = sel.options[sel.selectedIndex];
    const stockCard = document.getElementById('directAllocStockCard');
    const warning = document.getElementById('directAllocQtyWarning');

    if (!opt || !opt.value) {
      stockCard.style.display = 'none';
      warning.style.display = 'none';
      document.getElementById('directAllocMaxHint').innerText = '';
      return;
    }

    const avail = parseInt(opt.dataset.avail) || 0;
    const unit = opt.dataset.unit || 'units';
    const category = opt.dataset.category || 'Relief Goods';

    stockCard.style.display = 'block';
    document.getElementById('directAllocAvailText').innerText = `${avail.toLocaleString()} ${unit}`;
    document.getElementById('directAllocCategoryText').innerText = category;
    document.getElementById('directAllocMaxHint').innerText = `Max Available: ${avail.toLocaleString()} ${unit}`;

    validateDirectAllocQuantity();
  }

  // Live Validation for Direct Allocation Quantity
  function validateDirectAllocQuantity() {
    const sel = document.getElementById('directAllocResource');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const avail = parseInt(opt.dataset.avail) || 0;
    const unit = opt.dataset.unit || 'units';
    const qtyInput = document.getElementById('directAllocQuantity');
    const warning = document.getElementById('directAllocQtyWarning');
    const submitBtn = document.getElementById('btnSubmitDirectAlloc');
    const val = parseInt(qtyInput.value) || 0;

    if (val <= 0) {
      warning.style.display = 'block';
      warning.innerText = 'Allocation quantity must be at least 1 unit.';
      submitBtn.disabled = true;
      submitBtn.style.opacity = '0.5';
    } else if (val > avail) {
      warning.style.display = 'block';
      warning.innerText = `Cannot allocate ${val.toLocaleString()} ${unit}. Only ${avail.toLocaleString()} available in Central Depot.`;
      submitBtn.disabled = true;
      submitBtn.style.opacity = '0.5';
    } else {
      warning.style.display = 'none';
      submitBtn.disabled = false;
      submitBtn.style.opacity = '1';
    }
  }

  // Submit Direct Allocation via AJAX
  async function submitDirectAllocation() {
    const brgyId = document.getElementById('directAllocBarangay').value || 12;
    const purok = document.getElementById('directAllocPurok') ? document.getElementById('directAllocPurok').value : 'All Puroks';
    const resId = document.getElementById('directAllocResource').value;
    const qty = parseInt(document.getElementById('directAllocQuantity').value) || 0;
    const disType = document.getElementById('directAllocDisasterType').value;
    const purpose = document.getElementById('directAllocPurpose').value.trim();
    const remarks = document.getElementById('directAllocRemarks').value.trim();

    if (!brgyId) {
      showToast('Please select a beneficiary barangay.', 'warning');
      return;
    }
    if (!resId) {
      showToast('Please select a Central Depot resource commodity.', 'warning');
      return;
    }
    if (qty <= 0) {
      showToast('Allocation quantity must be at least 1.', 'warning');
      return;
    }

    const submitBtn = document.getElementById('btnSubmitDirectAlloc');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerText = 'Dispatching...';

    try {
      const formData = new FormData();
      formData.append('sub_action', 'direct');
      formData.append('barangay_id', brgyId);
      formData.append('purok_name', purok);
      formData.append('target_purok', purok);
      formData.append('resource_id', resId);
      formData.append('quantity', qty);
      formData.append('disaster_type', disType);
      formData.append('purpose', purpose);
      formData.append('remarks', remarks);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/resources/index.php?action=allocate', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();

      if (data.success) {
        showToast(data.message, 'success');
        closeModal('directAllocationModal');

        // 1. Insert new allocation row into Allocations Table dynamically
        const tableBody = document.getElementById('allocationsTableBody');
        const noRow = document.getElementById('noAllocationsRow');
        if (noRow) noRow.remove();

        if (tableBody && data.allocation) {
          const a = data.allocation;
          const newRow = document.createElement('tr');
          newRow.id = `alloc-row-${a.item_id}`;
          newRow.className = 'row-updated-highlight';
          newRow.dataset.itemId = a.item_id;
          newRow.dataset.resourceId = resId;
          newRow.dataset.resourceName = a.resource_name;
          newRow.dataset.resourceCode = a.resource_code;
          newRow.dataset.unit = a.unit;
          newRow.dataset.recommendedQty = a.recommended_quantity;
          newRow.dataset.depotAvail = data.metrics.remaining_depot_available || 0;
          newRow.dataset.trackingCode = a.tracking_code;
          newRow.dataset.barangayName = a.barangay_name;
          newRow.dataset.purokName = a.purok_name || purok || 'All Puroks';
          newRow.dataset.status = 'Allocated';

          newRow.innerHTML = `
          <td>
            <div style="font-weight:700;color:var(--color-primary);font-size:11px;">${a.tracking_code}</div>
            <div style="font-size:9px;color:var(--color-text-muted);">${a.disaster_type} &bull; ${a.severity}</div>
          </td>
          <td style="font-size:11px;">
            <div style="font-weight:700;color:var(--color-primary);">${a.purok_name || purok || 'All Puroks'}</div>
            <div style="font-size:9.5px;color:var(--color-text-muted);">Brgy. ${a.barangay_name}</div>
          </td>
          <td>
            <div style="font-weight:600;color:#0F172A;">${a.resource_name}</div>
            <div style="font-size:9px;color:var(--color-text-muted);">${a.resource_code}</div>
          </td>
          <td><span class="badge badge-neutral" style="font-size:9px;">${a.category}</span></td>
          <td style="font-family:var(--font-secondary);font-size:11.5px;">
            ${parseInt(a.recommended_quantity).toLocaleString()} ${a.unit}
          </td>
          <td style="font-family:var(--font-secondary);font-weight:800;color:var(--color-success);font-size:13px;" id="alloc-qty-${a.item_id}">
            ${parseInt(a.allocated_quantity).toLocaleString()} ${a.unit}
          </td>
          <td style="font-size:10.5px;">
            <span style="font-weight:600;color:#0F172A;" id="alloc-depot-avail-${a.item_id}">
              ${parseInt(data.metrics.remaining_depot_available || 0).toLocaleString()}
            </span>
            <span style="font-size:9px;color:var(--color-text-muted);">${a.unit}</span>
          </td>
          <td id="alloc-status-${a.item_id}">
            <span class="badge badge-success">Allocated</span>
          </td>
          <td id="alloc-actions-${a.item_id}" style="text-align:right;">
            <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick="openProcessAllocationModal(${a.item_id})">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              View
            </button>
          </td>
        `;
          tableBody.prepend(newRow);
        }

        // 2. Update Top Metric Cards & Badges in Real-Time
        const stockMetric = document.getElementById('metricCentralStock');
        if (stockMetric && data.metrics && data.metrics.total_depot_available !== undefined) {
          stockMetric.innerText = parseInt(data.metrics.total_depot_available).toLocaleString();
        } else if (stockMetric) {
          let curr = parseInt(stockMetric.innerText.replace(/,/g, '')) || 0;
          curr = Math.max(0, curr - qty);
          stockMetric.innerText = curr.toLocaleString();
        }

        const allocBadge = document.getElementById('tabAllocationsCount');
        if (allocBadge) {
          let curr = parseInt(allocBadge.innerText) || 0;
          allocBadge.innerText = (curr + 1);
        }

        const activeAllocBadge = document.getElementById('badgeActiveAllocations');
        if (activeAllocBadge) {
          let curr = parseInt(activeAllocBadge.innerText) || 0;
          activeAllocBadge.innerText = `${curr + 1} Total Records`;
        }

        const allocUnitsBadge = document.getElementById('badgeAllocatedUnits');
        if (allocUnitsBadge && data.metrics && data.metrics.total_allocated_units !== undefined) {
          allocUnitsBadge.innerText = `${parseInt(data.metrics.total_allocated_units).toLocaleString()} Units Dispatched`;
        }

        // 3. Update Active Resources table stock cell if visible
        const stockCell = document.getElementById(`res-stock-cell-${resId}`);
        if (stockCell && data.metrics && data.metrics.remaining_depot_available !== undefined) {
          const availSpan = stockCell.querySelector('.res-avail-val');
          if (availSpan) availSpan.innerText = parseInt(data.metrics.remaining_depot_available).toLocaleString();
          const totalSpan = stockCell.querySelector('.res-total-val');
          if (totalSpan && data.metrics.remaining_depot_total !== undefined) totalSpan.innerText = parseInt(data.metrics.remaining_depot_total).toLocaleString();
        }

      } else {
        showToast(data.message || 'Direct allocation failed.', 'danger');
      }
    } catch (err) {
      console.error(err);
      showToast('Network error processing direct allocation.', 'danger');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }
  }

  // In-Place Dynamic DOM Updater for Allocation Rows
  function updateAllocationRowInDOM(data) {
    const itemId = data.item_id;
    const status = data.status;
    const row = document.getElementById(`alloc-row-${itemId}`);

    if (row) {
      row.classList.remove('row-updated-highlight');
      void row.offsetWidth; // trigger reflow
      row.classList.add('row-updated-highlight');
      row.dataset.status = status;
    }

    // 1. Update status badge
    const statusCell = document.getElementById(`alloc-status-${itemId}`);
    if (statusCell) {
      if (status === 'Allocated') {
        statusCell.innerHTML = '<span class="badge badge-success">Allocated</span>';
      } else {
        statusCell.innerHTML = '<span class="badge badge-neutral" style="color:var(--color-text-muted);">Declined</span>';
      }
    }

    // 2. Update allocated quantity display
    const qtyCell = document.getElementById(`alloc-qty-${itemId}`);
    if (qtyCell && status === 'Allocated') {
      qtyCell.innerText = `${parseInt(data.allocated_quantity).toLocaleString()} ${data.unit || 'units'}`;
    } else if (qtyCell && status === 'Rejected') {
      qtyCell.innerText = `0 ${data.unit || 'units'}`;
    }

    // 3. Update actions cell (strictly keep single View button)
    const actionsCell = document.getElementById(`alloc-actions-${itemId}`);
    if (actionsCell) {
      actionsCell.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" style="padding:4px 12px;font-size:11px;display:inline-flex;align-items:center;gap:4px;" onclick="openProcessAllocationModal(${itemId})">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        View
      </button>
    `;
    }

    // 4. Update Tab Pending Badge
    const tabPendingBadge = document.getElementById('tabPendingAllocBadge');
    if (tabPendingBadge) {
      let curr = parseInt(tabPendingBadge.innerText) || 0;
      curr = Math.max(0, curr - 1);
      if (curr > 0) {
        tabPendingBadge.innerText = `${curr} Pending`;
      } else {
        tabPendingBadge.style.display = 'none';
      }
    }

    // 5. Update Top Metrics: Central Available Stock & Total Dispatched Units
    if (status === 'Allocated') {
      const stockMetric = document.getElementById('metricCentralStock');
      if (stockMetric && data.metrics && data.metrics.total_depot_available !== undefined) {
        stockMetric.innerText = parseInt(data.metrics.total_depot_available).toLocaleString();
      } else if (stockMetric && data.allocated_quantity) {
        let currStock = parseInt(stockMetric.innerText.replace(/,/g, '')) || 0;
        currStock = Math.max(0, currStock - parseInt(data.allocated_quantity));
        stockMetric.innerText = currStock.toLocaleString();
      }

      const allocUnitsBadge = document.getElementById('badgeAllocatedUnits');
      if (allocUnitsBadge && data.metrics && data.metrics.total_allocated_units !== undefined) {
        allocUnitsBadge.innerText = `${parseInt(data.metrics.total_allocated_units).toLocaleString()} Units Dispatched`;
      }
    }
  }

  // Close modal when clicking on backdrop + dynamic field keyboard support
  document.addEventListener('DOMContentLoaded', () => {
    ['createResourceModal', 'viewResourceModal', 'reviewRequisitionModal', 'processAllocationModal', 'directAllocationModal'].forEach(id => {
      const m = document.getElementById(id);
      if (m) {
        m.addEventListener('click', (e) => {
          if (e.target === m) {
            closeModal(id);
          }
        });
      }
    });

    // Enter/Escape keyboard handling for dynamic fields in Create and View modals
    ['create', 'view'].forEach(mode => {
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
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>