<?php
// ============================================================================
// Views (Barangay Head): Manage Puroks Module
// Sub-community clusters, demographic counts, and localized hazard risks
// With Active & Archived Tabs, View Only Action column, and View/Edit/Archive/Delete Modal
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
$barangay = $bStmt->fetch();

if (!$barangay) {
  die("Barangay jurisdiction not found or unassigned.");
}

// Ensure status column exists in puroks table
$cols = $db->query("SHOW COLUMNS FROM puroks LIKE 'status'")->fetchAll();
if (empty($cols)) {
  $db->exec("ALTER TABLE puroks ADD COLUMN status ENUM('active', 'archived') DEFAULT 'active' AFTER coordinates_lng");
}

// Ensure database options tables exist
$db->exec("
    CREATE TABLE IF NOT EXISTS purok_cluster_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barangay_id INT NULL,
        name VARCHAR(150) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_brgy (barangay_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$db->exec("
    CREATE TABLE IF NOT EXISTS hazard_type_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Fetch Purok Cluster Name options strictly from database
$purokClustersStmt = $db->prepare("
    SELECT id, name FROM purok_cluster_options 
    WHERE barangay_id = ? OR barangay_id IS NULL 
    ORDER BY name ASC
");
$purokClustersStmt->execute([$barangayId]);
$purokClusterRows = $purokClustersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Primary Hazard Type options strictly from database
$hazardTypesStmt = $db->query("
    SELECT id, name FROM hazard_type_options 
    ORDER BY name ASC
");
$hazardTypeRows = $hazardTypesStmt->fetchAll(PDO::FETCH_ASSOC);

// Tabs: Active vs Archived
$viewTab = $_GET['tab'] ?? 'active';
if (!in_array($viewTab, ['active', 'archived'], true)) {
  $viewTab = 'active';
}

$search = trim($_GET['search'] ?? '');
$filterRisk = trim($_GET['risk_level'] ?? '');

// Counts for navigation tabs
$activeCountStmt = $db->prepare("SELECT COUNT(*) FROM puroks WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)");
$activeCountStmt->execute([$barangayId]);
$activeCount = (int) $activeCountStmt->fetchColumn();

$archivedCountStmt = $db->prepare("SELECT COUNT(*) FROM puroks WHERE barangay_id = ? AND status = 'archived'");
$archivedCountStmt->execute([$barangayId]);
$archivedCount = (int) $archivedCountStmt->fetchColumn();

// Query Puroks
$sql = "SELECT * FROM puroks WHERE barangay_id = ?";
$params = [$barangayId];

if ($viewTab === 'archived') {
  $sql .= " AND status = 'archived'";
} else {
  $sql .= " AND (status != 'archived' OR status IS NULL)";
}

if (!empty($search)) {
  $sql .= " AND (name LIKE ? OR hazard_types LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
}

if (!empty($filterRisk)) {
  $sql .= " AND risk_level = ?";
  $params[] = $filterRisk;
}

$sql .= " ORDER BY (risk_level = 'Critical') DESC, (risk_level = 'High') DESC, name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics across active jurisdiction puroks
$metricsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_puroks,
        COALESCE(SUM(households), 0) as total_households,
        COALESCE(SUM(population), 0) as total_population,
        COALESCE(SUM(CASE WHEN risk_level IN ('Critical', 'High') THEN 1 ELSE 0 END), 0) as critical_puroks
    FROM puroks 
    WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL)
");
$metricsStmt->execute([$barangayId]);
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

$totalPuroks = (int) ($metrics['total_puroks'] ?? 0);
$totalHouseholds = (int) ($metrics['total_households'] ?? 0);
$totalPopulation = (int) ($metrics['total_population'] ?? 0);
$criticalPuroks = (int) ($metrics['critical_puroks'] ?? 0);

$pageTitle = "Manage Puroks — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
  /* High-clarity disabled inputs for View Mode */
  .form-control:disabled,
  select.form-control:disabled {
    background-color: var(--color-surface-subtle, #F0F3F4) !important;
    color: var(--color-text, #24313A) !important;
    border-color: var(--color-border-light, #E7EDF0) !important;
    cursor: default !important;
    opacity: 0.96 !important;
    -webkit-text-fill-color: var(--color-text, #24313A) !important;
  }

  .table-user-link {
    text-decoration: none;
    display: block;
  }
  .table-user-link:hover div:first-child {
    color: var(--color-secondary, #2F6F73) !important;
    text-decoration: underline;
  }

  /* Responsive and vertically scrollable modals */
  #viewPurokModal.modal-overlay,
  #createPurokModal.modal-overlay,
  #confirmationModal.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.48) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }

  #viewPurokModal.modal-overlay.active,
  #createPurokModal.modal-overlay.active,
  #confirmationModal.modal-overlay.active {
    display: flex !important;
  }

  #viewPurokModal .modal-dialog,
  #createPurokModal .modal-dialog {
    max-width: 640px !important;
    width: 100% !important;
    max-height: calc(100vh - 36px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
    position: relative !important;
  }

  #viewPurokModal .modal-header,
  #createPurokModal .modal-header {
    flex-shrink: 0 !important;
    padding: 12px 18px !important;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
  }

  #viewPurokModal .modal-body,
  #createPurokModal .modal-body {
    flex: 1 1 auto !important;
    padding: 18px 20px !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
  }

  #viewPurokModal .modal-footer,
  #createPurokModal .modal-footer {
    flex-shrink: 0 !important;
    padding: 12px 20px !important;
    border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
    background-color: #FAFCFC !important;
  }

  /* Custom scrollbars */
  #viewPurokModal .modal-body::-webkit-scrollbar,
  #createPurokModal .modal-body::-webkit-scrollbar {
    width: 6px;
  }
  #viewPurokModal .modal-body::-webkit-scrollbar-thumb,
  #createPurokModal .modal-body::-webkit-scrollbar-thumb {
    background: #CBD5E1;
    border-radius: 4px;
  }

  /* Nav tabs styling */
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
    border-bottom-color: var(--color-secondary);
    background-color: var(--color-surface);
  }
  .nav-tab-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 9.5px;
    font-weight: 700;
    background-color: var(--color-surface-subtle);
    color: var(--color-text-muted);
  }
  .nav-tab-item.active .nav-tab-badge {
    background-color: var(--color-primary);
    color: #FFF;
  }

  /* Custom Selects for Purok & Hazard Selects */
  .custom-select-wrapper {
    position: relative;
    width: 100%;
  }

  .custom-select-trigger {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    font-size: 11px;
    background: #ffffff;
    padding: 8px 12px;
    min-height: 38px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm, 6px);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .custom-select-trigger:hover {
    border-color: var(--color-primary);
  }

  .custom-select-trigger:focus-within {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(47, 111, 115, 0.15);
  }

  .custom-select-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
    z-index: 1050;
    max-height: 220px;
    overflow-y: auto;
  }

  .custom-option-item,
  .custom-option-hazard-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 10px;
    cursor: pointer;
    border-bottom: 1px solid #F1F5F9;
    transition: background-color 0.15s ease;
    font-size: 11px;
  }

  .custom-option-item:last-child,
  .custom-option-hazard-item:last-child {
    border-bottom: none;
  }

  .custom-option-item:hover,
  .custom-option-hazard-item:hover {
    background-color: #F8FAFC;
  }

  .custom-option-item.selected,
  .custom-option-hazard-item.selected {
    background-color: #F0FDFA;
  }

  .custom-option-item.selected .option-label,
  .custom-option-hazard-item.selected .option-label {
    color: var(--color-primary);
    font-weight: 600;
  }

  .btn-trash-option {
    background: transparent;
    border: none;
    color: #94A3B8;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease-in-out;
  }

  .btn-trash-option:hover {
    color: #DC2626 !important;
    background-color: #FEE2E2 !important;
  }
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Manage Puroks — Barangay <?= clean($barangay['name'] ?? '') ?></h1>
    <p class="page-header-desc">
      Administer sub-community clusters, localized flood/landslide hazards, and demographic registries for Barangay
      <?= clean($barangay['name']) ?>.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openCreatePurokModal()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Add New Purok
    </button>
  </div>
</div>

<!-- Summary Metric Cards (Active Jurisdiction Data) -->
<div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: var(--space-4);">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Active Puroks</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
        <circle cx="12" cy="10" r="3"></circle>
      </svg>
    </div>
    <div class="metric-value"><?= number_format($totalPuroks) ?></div>
    <div class="metric-meta">Active Community Clusters</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Total Households</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
      </svg>
    </div>
    <div class="metric-value"><?= number_format($totalHouseholds) ?></div>
    <div class="metric-meta">Across Active Puroks</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Purok Population</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
        <circle cx="9" cy="7" r="4"></circle>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
      </svg>
    </div>
    <div class="metric-value"><?= number_format($totalPopulation) ?></div>
    <div class="metric-meta">Active Resident Individuals</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">High / Critical Vulnerability</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
      </svg>
    </div>
    <div class="metric-value" style="color: <?= $criticalPuroks > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($criticalPuroks) ?>
    </div>
    <div class="metric-meta">Puroks Requiring Priority Monitoring</div>
  </div>
</div>

<!-- Tabs Navigation: Active vs Archived -->
<div class="nav-tabs">
  <a href="?tab=active" class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
      <circle cx="12" cy="10" r="3"></circle>
    </svg>
    Active Puroks
    <span class="nav-tab-badge"><?= $activeCount ?></span>
  </a>
  <a href="?tab=archived" class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="21 8 21 21 3 21 3 8"></polyline>
      <rect x="1" y="3" width="22" height="5"></rect>
      <line x1="10" y1="12" x2="14" y2="12"></line>
    </svg>
    Archived Puroks
    <span class="nav-tab-badge"><?= $archivedCount ?></span>
  </a>
</div>

<!-- Filter & Search Bar -->
<div class="card" style="margin-bottom: var(--space-4);">
  <div class="card-body" style="padding: 10px 14px;">
    <form method="GET" action="<?= BASE_URL ?>/views/barangay/puroks.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:0;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($viewTab) ?>">

      <div class="search-input-wrap" style="min-width:240px;flex:1;max-width:320px;">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" name="search" class="form-control" placeholder="Search by purok name or hazard..." value="<?= clean($search) ?>">
      </div>

      <select name="risk_level" class="form-control" style="width:170px;">
        <option value="">All Vulnerability Levels</option>
        <option value="Critical" <?= $filterRisk === 'Critical' ? 'selected' : '' ?>>Critical</option>
        <option value="High" <?= $filterRisk === 'High' ? 'selected' : '' ?>>High</option>
        <option value="Moderate" <?= $filterRisk === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
        <option value="Low" <?= $filterRisk === 'Low' ? 'selected' : '' ?>>Low</option>
      </select>

      <button type="submit" class="btn btn-outline">Apply Filter</button>
      <?php if (!empty($search) || !empty($filterRisk)): ?>
        <a href="?tab=<?= $viewTab ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
      <?php endif; ?>

      <div style="margin-left:auto;font-size:11px;color:var(--color-text-muted);font-weight:600;">
        Showing: <?= count($puroks) ?> <?= $viewTab === 'archived' ? 'Archived ' : '' ?>Purok(s)
      </div>
    </form>
  </div>
</div>

<!-- Puroks Table (Action Column is VIEW ONLY) -->
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th>Purok Name</th>
        <th>Primary Hazard Types</th>
        <th>Vulnerability Level</th>
        <th>Households</th>
        <th>Population</th>
        <th>GPS Coordinates</th>
        <?php if ($viewTab === 'archived'): ?>
          <th>Status</th>
        <?php endif; ?>
        <th style="text-align:right;width:120px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($puroks)): ?>
        <tr>
          <td colspan="<?= $viewTab === 'archived' ? '8' : '7' ?>" style="text-align:center;padding:36px;color:var(--color-text-muted);">
            No purok records found in <?= $viewTab === 'archived' ? 'Archived' : 'Active' ?> registry matching your filter criteria.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($puroks as $p): ?>
          <?php
          $pRisk = $p['risk_level'] ?? 'Moderate';
          $pColors = ['Critical' => 'badge-danger', 'High' => 'badge-warning', 'Moderate' => 'badge-info', 'Low' => 'badge-success'];
          $pBadge = $pColors[$pRisk] ?? 'badge-neutral';
          $isCritical = ($pRisk === 'Critical' || $pRisk === 'High');
          ?>
          <tr style="<?= $isCritical ? 'background-color: #FFFDF8;' : '' ?>">
            <td>
              <a href="javascript:void(0)" onclick="viewPurokDetails(<?= htmlspecialchars(json_encode($p)) ?>)" class="table-user-link">
                <div style="font-weight:700;color:var(--color-primary);font-size:12.5px;"><?= clean($p['name']) ?></div>
                <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:1px;">Barangay <?= clean($barangay['name']) ?></div>
              </a>
            </td>
            <td>
              <div style="font-size:10.5px;color:var(--color-text-secondary);max-width:240px;line-height:1.3;">
                <?= clean($p['hazard_types'] ?: 'Standard Flood/Squall Risk') ?>
              </div>
            </td>
            <td>
              <span class="badge <?= $pBadge ?>" style="font-size:9.5px;"><?= clean($pRisk) ?></span>
            </td>
            <td style="font-family:var(--font-secondary);font-size:11.5px;">
              <?= number_format($p['households']) ?>
            </td>
            <td style="font-family:var(--font-secondary);font-weight:700;font-size:12px;color:var(--color-primary);">
              <?= number_format($p['population']) ?>
            </td>
            <td style="font-family:var(--font-secondary);font-size:10.5px;color:var(--color-text-secondary);white-space:nowrap;">
              <?= clean($p['coordinates_lat']) ?>, <?= clean($p['coordinates_lng']) ?>
            </td>
            <?php if ($viewTab === 'archived'): ?>
              <td>
                <span class="badge badge-neutral" style="font-size:9.5px;">Archived</span>
              </td>
            <?php endif; ?>
            <td style="text-align:right;">
              <div style="display:inline-flex;gap:4px;justify-content:flex-end;">
                <!-- View Only Action Button (Pops up modal with View details, Delete, Archive, and Edit) -->
                <button type="button" class="btn btn-outline btn-sm" style="padding:3px 10px;font-size:10.5px;"
                  onclick="viewPurokDetails(<?= htmlspecialchars(json_encode($p)) ?>)" title="View Purok Details">
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

<!-- ========================================================================= -->
<!-- Modal 1: Add New Purok Modal                                              -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createPurokModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Add New Purok to <?= clean($barangay['name'] ?? '') ?></h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('createPurokModal')">&times;</button>
    </div>
    <form id="createPurokForm" method="POST" action="<?= BASE_URL ?>/backend/functions/puroks/create.php" onsubmit="handleCreatePurok(event)">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body" style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;max-height:75vh;overflow-y:auto;">
        
        <!-- Purok Cluster Name: Custom Select with Delete Option -->
        <div class="form-group" style="margin-bottom:0;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label class="form-label form-label-required" style="margin-bottom:0;">Purok Cluster Name</label>
            <span style="font-size:10px;color:var(--color-text-muted);">Database options</span>
          </div>

          <div class="custom-select-wrapper" style="position:relative;">
            <div class="form-control custom-select-trigger" id="create_purok_trigger"
              onclick="toggleCustomDropdown('create_purok_menu')"
              style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;font-size:11px;background:#fff;padding:8px 12px;min-height:38px;">
              <span id="create_purok_text" style="color:var(--color-text-muted);">-- Select Purok Cluster Name --</span>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </div>
            <input type="hidden" name="name" id="create_purok_name">

            <!-- Dropdown Menu -->
            <div class="custom-select-menu" id="create_purok_menu"
              style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,0.12);z-index:1050;max-height:220px;overflow-y:auto;">
              <div style="padding:6px 8px;border-bottom:1px solid #E2E8F0;position:sticky;top:0;background:#F8FAFC;z-index:2;">
                <input type="text" class="form-control form-control-sm" placeholder="Search purok cluster..."
                  style="font-size:11px;padding:4px 8px;" oninput="filterCustomDropdown(this, 'create_purok_options')">
              </div>
              <div class="custom-options-list" id="create_purok_options">
                <?php foreach ($purokClusterRows as $r): ?>
                  <div class="custom-option-item" data-id="<?= (int) $r['id'] ?>" data-value="<?= clean($r['name']) ?>"
                    onclick="selectCustomOption('create_purok', this.getAttribute('data-value'))">
                    <span class="option-label" style="color:var(--color-text-primary);"><?= clean($r['name']) ?></span>
                    <button type="button" class="btn-trash-option"
                      onclick="event.stopPropagation(); deletePurokClusterOption(this.closest('.custom-option-item').getAttribute('data-id'), this.closest('.custom-option-item').getAttribute('data-value'))"
                      title="Delete from database">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      </svg>
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Dynamic Action Area Below Select -->
          <div style="margin-top:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span style="font-size:10px;color:var(--color-text-secondary);">Need a new or custom purok name?</span>
              <button type="button" class="btn btn-outline btn-sm" id="btnShowAddPurokCreate"
                onclick="toggleDynamicPurokInput('create', true)"
                style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:3px 9px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                + Add Purok
              </button>
            </div>

            <!-- Inline Dynamic Add Box -->
            <div id="dynamicPurokBox_create"
              style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
              <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Purok Cluster to Dropdown & Database</label>
              <div style="display:flex;gap:6px;">
                <input type="text" id="dynamic_purok_input_create" class="form-control"
                  placeholder="Type purok name (e.g. Purok Riverside 3)" style="font-size:11px;padding:5px 9px;"
                  onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicPurok('create');}">
                <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicPurok('create')"
                  style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  Add to Select
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicPurokInput('create', false)"
                  style="padding:4px 8px;font-size:10px;">Cancel</button>
              </div>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
                Entered cluster will be saved in the database and automatically selected.
              </div>
            </div>
          </div>
        </div>

        <!-- Primary Hazard Types: Custom Select with Delete Option -->
        <div class="form-group" style="margin-bottom:0;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label class="form-label form-label-required" style="margin-bottom:0;">Primary Hazard Types</label>
            <span style="font-size:10px;color:var(--color-text-muted);">Database options</span>
          </div>

          <div class="custom-select-wrapper" style="position:relative;">
            <div class="form-control custom-select-trigger" id="create_hazard_trigger"
              onclick="toggleCustomDropdown('create_hazard_menu')"
              style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;font-size:11px;background:#fff;padding:8px 12px;min-height:38px;">
              <span id="create_hazard_text" style="color:var(--color-text-muted);">-- Select Primary Hazard Type --</span>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </div>
            <input type="hidden" name="hazard_types" id="create_purok_hazards">

            <!-- Dropdown Menu -->
            <div class="custom-select-menu" id="create_hazard_menu"
              style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,0.12);z-index:1050;max-height:220px;overflow-y:auto;">
              <div style="padding:6px 8px;border-bottom:1px solid #E2E8F0;position:sticky;top:0;background:#F8FAFC;z-index:2;">
                <input type="text" class="form-control form-control-sm" placeholder="Search hazard type..."
                  style="font-size:11px;padding:4px 8px;" oninput="filterCustomDropdown(this, 'create_hazard_options')">
              </div>
              <div class="custom-options-list" id="create_hazard_options">
                <?php foreach ($hazardTypeRows as $h): ?>
                  <div class="custom-option-hazard-item" data-id="<?= (int) $h['id'] ?>" data-value="<?= clean($h['name']) ?>"
                    onclick="selectCustomHazardOption('create_hazard', this.getAttribute('data-value'))">
                    <span class="option-label" style="color:var(--color-text-primary);"><?= clean($h['name']) ?></span>
                    <button type="button" class="btn-trash-option"
                      onclick="event.stopPropagation(); deleteHazardTypeOption(this.closest('.custom-option-hazard-item').getAttribute('data-id'), this.closest('.custom-option-hazard-item').getAttribute('data-value'))"
                      title="Delete from database">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      </svg>
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Dynamic Action Area Below Hazard Select -->
          <div style="margin-top:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span style="font-size:10px;color:var(--color-text-secondary);">Specific hazard not listed?</span>
              <button type="button" class="btn btn-outline btn-sm" id="btnShowAddHazardCreate"
                onclick="toggleDynamicHazardInput('create', true)"
                style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:3px 9px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                + Add Hazard Type
              </button>
            </div>

            <!-- Inline Dynamic Add Box -->
            <div id="dynamicHazardBox_create"
              style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
              <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Hazard Type to Dropdown & Database</label>
              <div style="display:flex;gap:6px;">
                <input type="text" id="dynamic_hazard_input_create" class="form-control"
                  placeholder="e.g. Flash Flood, Mountain Mudflow" style="font-size:11px;padding:5px 9px;"
                  onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicHazard('create');}">
                <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicHazard('create')"
                  style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  Add to Select
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicHazardInput('create', false)"
                  style="padding:4px 8px;font-size:10px;">Cancel</button>
              </div>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
                Entered hazard type will be saved in the database and automatically selected.
              </div>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Vulnerability / Risk Level</label>
          <select name="risk_level" class="form-control" required>
            <option value="Low">Low (Safe from common inundation)</option>
            <option value="Moderate" selected>Moderate (Occasional localized pooling)</option>
            <option value="High">High (Flood prone, swift water current)</option>
            <option value="Critical">Critical (Riverbank proximity, red warning zone)</option>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Estimated Households</label>
            <input type="number" name="households" class="form-control" placeholder="e.g. 120" min="0" value="100">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Estimated Population</label>
            <input type="number" name="population" class="form-control" placeholder="e.g. 450" min="0" value="450">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Latitude</label>
            <input type="number" step="0.000001" name="coordinates_lat" class="form-control"
              value="<?= clean($barangay['coordinates_lat']) ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Longitude</label>
            <input type="number" step="0.000001" name="coordinates_lng" class="form-control"
              value="<?= clean($barangay['coordinates_lng']) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--color-border);background:#F8FAFC;display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('createPurokModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitCreatePurok">Save Purok</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View / In-Place Edit Purok Details Modal                         -->
<!-- Opens on View Click: Shows View mode, with Delete, Archive, and Edit      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewPurokModal">
  <form class="modal-dialog" id="viewPurokForm" method="POST" action="<?= BASE_URL ?>/backend/functions/puroks/update.php" onsubmit="handleViewPurokSave(event)">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="id" id="view_purok_id">

    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="viewModalStatusBadge"></span>
        <span id="viewModalRiskBadge"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewPurokModal')" title="Close">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Section 1: Purok Identity Banner -->
      <div style="padding-bottom:12px;border-bottom:1px solid var(--color-border-light);">
        <div id="view_purok_display_name" style="font-weight:700;font-size:16px;color:var(--color-primary);line-height:1.2;"></div>
        <div id="view_purok_meta_sub" style="font-size:10px;color:var(--color-text-muted);margin-top:4px;"></div>
      </div>

      <!-- Section 2: Heading "Purok Details" -->
      <div style="margin:14px 0 10px 0;padding-bottom:6px;border-bottom:1px solid var(--color-border-light);display:flex;align-items:center;justify-content:space-between;">
        <h4 id="viewPurokSectionHeading" style="margin:0;font-size:12px;font-weight:700;color:var(--color-primary);text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
          Purok Details
        </h4>
        <span id="viewPurokModeHintText" style="font-size:9.5px;color:var(--color-text-muted);font-weight:500;">(View Only Mode)</span>
      </div>

      <!-- Form Inputs: Purok Cluster Name -->
      <div class="form-group" style="margin-bottom:10px;">
        <label class="form-label form-label-required">Purok Cluster Name</label>
        
        <!-- View Mode: Readonly text input -->
        <input type="text" id="view_purok_name_readonly" class="form-control" disabled>
        
        <!-- Edit Mode: Custom Select with Options and Dynamic Add -->
        <div id="edit_purok_name_wrapper" style="display:none;">
          <div class="custom-select-wrapper" style="position:relative;">
            <div class="form-control custom-select-trigger" id="edit_purok_trigger"
              onclick="toggleCustomDropdown('edit_purok_menu')"
              style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;font-size:11px;background:#fff;padding:8px 12px;min-height:38px;">
              <span id="edit_purok_text" style="color:var(--color-text-muted);">-- Select Purok Cluster Name --</span>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </div>
            <input type="hidden" name="name" id="edit_purok_name">

            <!-- Dropdown Menu -->
            <div class="custom-select-menu" id="edit_purok_menu"
              style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,0.12);z-index:1050;max-height:220px;overflow-y:auto;">
              <div style="padding:6px 8px;border-bottom:1px solid #E2E8F0;position:sticky;top:0;background:#F8FAFC;z-index:2;">
                <input type="text" class="form-control form-control-sm" placeholder="Search purok cluster..."
                  style="font-size:11px;padding:4px 8px;" oninput="filterCustomDropdown(this, 'edit_purok_options')">
              </div>
              <div class="custom-options-list" id="edit_purok_options">
                <?php foreach ($purokClusterRows as $r): ?>
                  <div class="custom-option-item" data-id="<?= (int) $r['id'] ?>" data-value="<?= clean($r['name']) ?>"
                    onclick="selectCustomOption('edit_purok', this.getAttribute('data-value'))">
                    <span class="option-label" style="color:var(--color-text-primary);"><?= clean($r['name']) ?></span>
                    <button type="button" class="btn-trash-option"
                      onclick="event.stopPropagation(); deletePurokClusterOption(this.closest('.custom-option-item').getAttribute('data-id'), this.closest('.custom-option-item').getAttribute('data-value'))"
                      title="Delete from database">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      </svg>
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Dynamic Action Area Below Select -->
          <div style="margin-top:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span style="font-size:10px;color:var(--color-text-secondary);">Need a new or custom purok name?</span>
              <button type="button" class="btn btn-outline btn-sm" id="btnShowAddPurokEdit"
                onclick="toggleDynamicPurokInput('edit', true)"
                style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:3px 9px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                + Add Purok
              </button>
            </div>

            <!-- Inline Dynamic Add Box -->
            <div id="dynamicPurokBox_edit"
              style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
              <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Purok Cluster to Dropdown & Database</label>
              <div style="display:flex;gap:6px;">
                <input type="text" id="dynamic_purok_input_edit" class="form-control"
                  placeholder="Type purok name (e.g. Purok Riverside 3)" style="font-size:11px;padding:5px 9px;"
                  onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicPurok('edit');}">
                <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicPurok('edit')"
                  style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  Add to Select
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicPurokInput('edit', false)"
                  style="padding:4px 8px;font-size:10px;">Cancel</button>
              </div>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
                Entered cluster will be saved in the database and automatically selected.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Form Inputs: Primary Hazard Types -->
      <div class="form-group" style="margin-bottom:10px;">
        <label class="form-label form-label-required">Primary Hazard Types</label>
        
        <!-- View Mode: Readonly text input -->
        <input type="text" id="view_purok_hazards_readonly" class="form-control" disabled>

        <!-- Edit Mode: Custom Select with Options and Dynamic Add -->
        <div id="edit_purok_hazards_wrapper" style="display:none;">
          <div class="custom-select-wrapper" style="position:relative;">
            <div class="form-control custom-select-trigger" id="edit_hazard_trigger"
              onclick="toggleCustomDropdown('edit_hazard_menu')"
              style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;font-size:11px;background:#fff;padding:8px 12px;min-height:38px;">
              <span id="edit_hazard_text" style="color:var(--color-text-muted);">-- Select Primary Hazard Type --</span>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </div>
            <input type="hidden" name="hazard_types" id="edit_purok_hazards">

            <!-- Dropdown Menu -->
            <div class="custom-select-menu" id="edit_hazard_menu"
              style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,0.12);z-index:1050;max-height:220px;overflow-y:auto;">
              <div style="padding:6px 8px;border-bottom:1px solid #E2E8F0;position:sticky;top:0;background:#F8FAFC;z-index:2;">
                <input type="text" class="form-control form-control-sm" placeholder="Search hazard type..."
                  style="font-size:11px;padding:4px 8px;" oninput="filterCustomDropdown(this, 'edit_hazard_options')">
              </div>
              <div class="custom-options-list" id="edit_hazard_options">
                <?php foreach ($hazardTypeRows as $h): ?>
                  <div class="custom-option-hazard-item" data-id="<?= (int) $h['id'] ?>" data-value="<?= clean($h['name']) ?>"
                    onclick="selectCustomHazardOption('edit_hazard', this.getAttribute('data-value'))">
                    <span class="option-label" style="color:var(--color-text-primary);"><?= clean($h['name']) ?></span>
                    <button type="button" class="btn-trash-option"
                      onclick="event.stopPropagation(); deleteHazardTypeOption(this.closest('.custom-option-hazard-item').getAttribute('data-id'), this.closest('.custom-option-hazard-item').getAttribute('data-value'))"
                      title="Delete from database">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      </svg>
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Dynamic Action Area Below Select -->
          <div style="margin-top:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span style="font-size:10px;color:var(--color-text-secondary);">Specific hazard not listed?</span>
              <button type="button" class="btn btn-outline btn-sm" id="btnShowAddHazardEdit"
                onclick="toggleDynamicHazardInput('edit', true)"
                style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:3px 9px;font-weight:600;color:var(--color-primary);border-color:var(--color-primary);background:#F8FAFC;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                + Add Hazard Type
              </button>
            </div>

            <!-- Inline Dynamic Add Box -->
            <div id="dynamicHazardBox_edit"
              style="display:none;margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
              <label style="display:block;font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:4px;">Add New Hazard Type to Dropdown & Database</label>
              <div style="display:flex;gap:6px;">
                <input type="text" id="dynamic_hazard_input_edit" class="form-control"
                  placeholder="e.g. Flash Flood, Mountain Mudflow" style="font-size:11px;padding:5px 9px;"
                  onkeydown="if(event.key === 'Enter'){event.preventDefault();addDynamicHazard('edit');}">
                <button type="button" class="btn btn-primary btn-sm" onclick="addDynamicHazard('edit')"
                  style="padding:4px 10px;white-space:nowrap;font-size:10px;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  Add to Select
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleDynamicHazardInput('edit', false)"
                  style="padding:4px 8px;font-size:10px;">Cancel</button>
              </div>
              <div style="font-size:9.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.3;">
                Entered hazard type will be saved in the database and automatically selected.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Vulnerability / Risk Level -->
      <div class="form-group" style="margin-bottom:10px;">
        <label class="form-label form-label-required">Vulnerability / Risk Level</label>
        <select name="risk_level" id="view_purok_risk" class="form-control" disabled required>
          <option value="Low">Low (Safe from common inundation)</option>
          <option value="Moderate">Moderate (Occasional localized pooling)</option>
          <option value="High">High (Flood prone, swift water current)</option>
          <option value="Critical">Critical (Riverbank proximity, red warning zone)</option>
        </select>
      </div>

      <!-- Households & Population -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Estimated Households</label>
          <input type="number" name="households" id="view_purok_households" class="form-control" min="0" disabled>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Estimated Population</label>
          <input type="number" name="population" id="view_purok_population" class="form-control" min="0" disabled>
        </div>
      </div>

      <!-- GPS Coordinates -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Latitude</label>
          <input type="number" step="0.000001" name="coordinates_lat" id="view_purok_lat" class="form-control" disabled>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Longitude</label>
          <input type="number" step="0.000001" name="coordinates_lng" id="view_purok_lng" class="form-control" disabled>
        </div>
      </div>
    </div>

    <!-- Modal Footer: Delete, Archive, Restore on Left; Cancel, Edit, Save, Close on Right -->
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <!-- Left Actions: Delete, Archive, Restore -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Delete Button (Triggers confirmation dialog) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalPurokDeleteBtn" style="color:var(--color-danger);border-color:var(--color-danger);" onclick="onModalPurokDelete()" title="Permanently delete purok">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
          Delete
        </button>

        <!-- Archive Button (visible when active) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalPurokArchiveBtn" style="color:var(--color-warning, #D97706);border-color:var(--color-warning, #D97706);" onclick="onModalPurokArchive()" title="Archive purok (moves to Archived Puroks tab)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;">
            <polyline points="21 8 21 21 3 21 3 8"></polyline>
            <rect x="1" y="3" width="22" height="5"></rect>
            <line x1="10" y1="12" x2="14" y2="12"></line>
          </svg>
          Archive
        </button>

        <!-- Restore Button (visible when viewing archived purok) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalPurokRestoreBtn" style="color:var(--color-success);border-color:var(--color-success);display:none;" onclick="onModalPurokRestore()" title="Restore purok back to active list">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;">
            <polyline points="1 4 1 10 7 10"></polyline>
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
          </svg>
          Restore Purok
        </button>
      </div>

      <!-- Right Actions: Cancel, Edit, Save Changes, Close -->
      <div style="display:flex;gap:6px;align-items:center;">
        <!-- Cancel Edit Button (visible only in edit mode) -->
        <button type="button" class="btn btn-outline btn-sm" id="modalPurokCancelEditBtn" onclick="cancelPurokEditMode()" style="display:none;">
          Cancel
        </button>

        <!-- Edit Button (visible in view mode) -->
        <button type="button" class="btn btn-primary btn-sm" id="modalPurokEditBtn" onclick="enablePurokEditMode()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:-1px;">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
          </svg>
          Edit
        </button>

        <!-- Save Changes Button (visible only in edit mode) -->
        <button type="submit" class="btn btn-primary btn-sm" id="modalPurokSaveBtn" style="display:none;">
          Save Changes
        </button>

        <!-- Close Button -->
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewPurokModal')">
          Close
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Reusable Confirmation Dialog (Matching Manage Residents)         -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="confirmationModal" style="z-index: 1200 !important;">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" id="confirmModalTitle">Confirm Action</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('confirmationModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want to proceed with this operation?</p>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<script>
  let currentPurokInModal = null;
  let isEditMode = false;
  let confirmCallback = null;

  // --- Confirmation Modal Helper ---
  function showConfirmModal(title, message, onConfirm, confirmBtnClass = 'btn-primary', confirmBtnText = 'Confirm') {
    document.getElementById('confirmModalTitle').innerText = title;
    document.getElementById('confirmModalMessage').innerText = message;
    const btn = document.getElementById('confirmModalSubmitBtn');
    btn.className = `btn btn-sm ${confirmBtnClass}`;
    btn.innerText = confirmBtnText;
    confirmCallback = onConfirm;
    openModal('confirmationModal');
  }

  document.getElementById('confirmModalSubmitBtn').addEventListener('click', function() {
    closeModal('confirmationModal');
    if (typeof confirmCallback === 'function') {
      confirmCallback();
    }
  });

  // --- Custom Dropdown Toggling & Filtering ---
  function toggleCustomDropdown(menuId) {
    const menu = document.getElementById(menuId);
    if (!menu) return;
    const isShown = (menu.style.display === 'block');

    // Close all other dropdown menus first
    document.querySelectorAll('.custom-select-menu').forEach(m => {
      m.style.display = 'none';
    });

    if (!isShown) {
      menu.style.display = 'block';
      const searchInput = menu.querySelector('input[type="text"]');
      if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
        const list = menu.querySelector('.custom-options-list');
        if (list) {
          list.querySelectorAll('.custom-option-item, .custom-option-hazard-item').forEach(item => {
            item.style.display = 'flex';
          });
        }
      }
    }
  }

  // Close custom dropdowns when clicking outside
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.custom-select-wrapper')) {
      document.querySelectorAll('.custom-select-menu').forEach(m => {
        m.style.display = 'none';
      });
    }
  });

  // Real-time filter inside dropdown options
  function filterCustomDropdown(input, listId) {
    const filter = input.value.trim().toLowerCase();
    const list = document.getElementById(listId);
    if (!list) return;
    const items = list.querySelectorAll('.custom-option-item, .custom-option-hazard-item');
    items.forEach(item => {
      const text = (item.querySelector('.option-label')?.innerText || item.getAttribute('data-value') || '').toLowerCase();
      item.style.display = text.includes(filter) ? 'flex' : 'none';
    });
  }

  // Selection handlers
  function selectCustomOption(prefix, value) {
    const textEl = document.getElementById(prefix + '_text');
    const inputEl = document.getElementById(prefix + '_name');
    const menuEl = document.getElementById(prefix + '_menu');
    if (textEl) {
      textEl.innerText = value || '-- Select Purok Cluster Name --';
      textEl.style.color = value ? 'var(--color-text-primary)' : 'var(--color-text-muted)';
      textEl.style.fontWeight = value ? '600' : 'normal';
    }
    if (inputEl) {
      inputEl.value = value || '';
    }
    if (menuEl) {
      menuEl.style.display = 'none';
    }
    const list = document.getElementById(prefix + '_options');
    if (list) {
      list.querySelectorAll('.custom-option-item').forEach(item => {
        if (item.getAttribute('data-value') === value) {
          item.classList.add('selected');
        } else {
          item.classList.remove('selected');
        }
      });
    }
  }

  function selectCustomHazardOption(prefix, value) {
    const textEl = document.getElementById(prefix + '_text');
    const inputId = (prefix === 'create_hazard') ? 'create_purok_hazards' : 'edit_purok_hazards';
    const inputEl = document.getElementById(inputId);
    const menuEl = document.getElementById(prefix + '_menu');
    if (textEl) {
      textEl.innerText = value || '-- Select Primary Hazard Type --';
      textEl.style.color = value ? 'var(--color-text-primary)' : 'var(--color-text-muted)';
      textEl.style.fontWeight = value ? '600' : 'normal';
    }
    if (inputEl) {
      inputEl.value = value || '';
    }
    if (menuEl) {
      menuEl.style.display = 'none';
    }
    const list = document.getElementById(prefix + '_options');
    if (list) {
      list.querySelectorAll('.custom-option-hazard-item').forEach(item => {
        if (item.getAttribute('data-value') === value) {
          item.classList.add('selected');
        } else {
          item.classList.remove('selected');
        }
      });
    }
  }

  // Dynamic Input Box Expanders
  function toggleDynamicPurokInput(type, show) {
    const box = document.getElementById('dynamicPurokBox_' + type);
    const input = document.getElementById('dynamic_purok_input_' + type);
    if (!box) return;
    if (show) {
      box.style.display = 'block';
      if (input) input.focus();
    } else {
      box.style.display = 'none';
      if (input) input.value = '';
    }
  }

  function toggleDynamicHazardInput(type, show) {
    const box = document.getElementById('dynamicHazardBox_' + type);
    const input = document.getElementById('dynamic_hazard_input_' + type);
    if (!box) return;
    if (show) {
      box.style.display = 'block';
      if (input) input.focus();
    } else {
      box.style.display = 'none';
      if (input) input.value = '';
    }
  }

  // Add Dynamic Purok Option
  async function addDynamicPurok(type) {
    const input = document.getElementById('dynamic_purok_input_' + type);
    if (!input) return;
    const rawVal = input.value.trim();
    if (!rawVal) {
      input.focus();
      if (typeof showToast === 'function') {
        showToast('Please type a purok name to add.', 'danger');
      } else {
        alert('Please type a purok name to add.');
      }
      return;
    }

    const prefix = (type === 'create') ? 'create_purok' : 'edit_purok';
    const formData = new FormData();
    formData.append('name', rawVal);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/add_cluster_option.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        const optId = data.id;
        const optName = data.name;

        // Append row to both dropdown option lists
        ['create_purok_options', 'edit_purok_options'].forEach(listId => {
          const list = document.getElementById(listId);
          if (list) {
            let found = false;
            list.querySelectorAll('.custom-option-item').forEach(item => {
              if (item.getAttribute('data-value').toLowerCase() === optName.toLowerCase()) {
                found = true;
                if (optId) item.setAttribute('data-id', optId);
              }
            });
            if (!found) {
              const row = document.createElement('div');
              row.className = 'custom-option-item';
              row.setAttribute('data-id', optId);
              row.setAttribute('data-value', optName);
              const targetPfx = listId.startsWith('create') ? 'create_purok' : 'edit_purok';
              row.onclick = function () { selectCustomOption(targetPfx, this.getAttribute('data-value')); };

              const span = document.createElement('span');
              span.className = 'option-label';
              span.style.color = 'var(--color-text-primary)';
              span.textContent = optName;

              const trashBtn = document.createElement('button');
              trashBtn.type = 'button';
              trashBtn.className = 'btn-trash-option';
              trashBtn.title = 'Delete from database';
              trashBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
              trashBtn.onclick = function (ev) {
                ev.stopPropagation();
                deletePurokClusterOption(this.closest('.custom-option-item').getAttribute('data-id'), this.closest('.custom-option-item').getAttribute('data-value'));
              };

              row.appendChild(span);
              row.appendChild(trashBtn);
              list.appendChild(row);
            }
          }
        });

        // Auto-select in current modal
        selectCustomOption(prefix, optName);
        toggleDynamicPurokInput(type, false);

        if (typeof showToast === 'function') {
          showToast(data.message || `Purok "${optName}" added to database and selected!`, 'success');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error saving purok cluster.', 'danger');
        } else {
          alert(data.message || 'Error saving purok cluster.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while saving purok cluster.', 'danger');
      }
    }
  }

  // Add Dynamic Hazard Option
  async function addDynamicHazard(type) {
    const input = document.getElementById('dynamic_hazard_input_' + type);
    if (!input) return;
    const rawVal = input.value.trim();
    if (!rawVal) {
      input.focus();
      if (typeof showToast === 'function') {
        showToast('Please type a hazard type to add.', 'danger');
      } else {
        alert('Please type a hazard type to add.');
      }
      return;
    }

    const prefix = (type === 'create') ? 'create_hazard' : 'edit_hazard';
    const formData = new FormData();
    formData.append('name', rawVal);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/add_hazard_option.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        const optId = data.id;
        const optName = data.name;

        // Append row to both dropdown option lists
        ['create_hazard_options', 'edit_hazard_options'].forEach(listId => {
          const list = document.getElementById(listId);
          if (list) {
            let found = false;
            list.querySelectorAll('.custom-option-hazard-item').forEach(item => {
              if (item.getAttribute('data-value').toLowerCase() === optName.toLowerCase()) {
                found = true;
                if (optId) item.setAttribute('data-id', optId);
              }
            });
            if (!found) {
              const row = document.createElement('div');
              row.className = 'custom-option-hazard-item';
              row.setAttribute('data-id', optId);
              row.setAttribute('data-value', optName);
              const targetPfx = listId.startsWith('create') ? 'create_hazard' : 'edit_hazard';
              row.onclick = function () { selectCustomHazardOption(targetPfx, this.getAttribute('data-value')); };

              const span = document.createElement('span');
              span.className = 'option-label';
              span.style.color = 'var(--color-text-primary)';
              span.textContent = optName;

              const trashBtn = document.createElement('button');
              trashBtn.type = 'button';
              trashBtn.className = 'btn-trash-option';
              trashBtn.title = 'Delete from database';
              trashBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
              trashBtn.onclick = function (ev) {
                ev.stopPropagation();
                deleteHazardTypeOption(this.closest('.custom-option-hazard-item').getAttribute('data-id'), this.closest('.custom-option-hazard-item').getAttribute('data-value'));
              };

              row.appendChild(span);
              row.appendChild(trashBtn);
              list.appendChild(row);
            }
          }
        });

        // Auto-select in current modal
        selectCustomHazardOption(prefix, optName);
        toggleDynamicHazardInput(type, false);

        if (typeof showToast === 'function') {
          showToast(data.message || `Hazard type "${optName}" added to database and selected!`, 'success');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error saving hazard type.', 'danger');
        } else {
          alert(data.message || 'Error saving hazard type.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while saving hazard type.', 'danger');
      }
    }
  }

  // Delete option from database via Trash Icon
  async function deletePurokClusterOption(id, name) {
    if (!confirm(`Are you sure you want to delete Purok Cluster "${name}" from the database options?`)) {
      return;
    }

    const formData = new FormData();
    if (id) formData.append('id', id);
    formData.append('name', name);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/delete_cluster_option.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        ['create_purok_options', 'edit_purok_options'].forEach(listId => {
          const list = document.getElementById(listId);
          if (list) {
            list.querySelectorAll('.custom-option-item').forEach(item => {
              if ((id && item.getAttribute('data-id') == id) || item.getAttribute('data-value') === name) {
                item.remove();
              }
            });
          }
        });

        ['create_purok', 'edit_purok'].forEach(pfx => {
          const input = document.getElementById(pfx + '_name');
          if (input && input.value === name) {
            selectCustomOption(pfx, '');
          }
        });

        if (typeof showToast === 'function') {
          showToast(`Purok Cluster "${name}" removed from database options.`, 'info');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error deleting cluster option.', 'danger');
        } else {
          alert(data.message || 'Error deleting cluster option.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while deleting option.', 'danger');
      }
    }
  }

  async function deleteHazardTypeOption(id, name) {
    if (!confirm(`Are you sure you want to delete Hazard Type "${name}" from the database options?`)) {
      return;
    }

    const formData = new FormData();
    if (id) formData.append('id', id);
    formData.append('name', name);

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/delete_hazard_option.php', {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        ['create_hazard_options', 'edit_hazard_options'].forEach(listId => {
          const list = document.getElementById(listId);
          if (list) {
            list.querySelectorAll('.custom-option-hazard-item').forEach(item => {
              if ((id && item.getAttribute('data-id') == id) || item.getAttribute('data-value') === name) {
                item.remove();
              }
            });
          }
        });

        const createHazardInput = document.getElementById('create_purok_hazards');
        if (createHazardInput && createHazardInput.value === name) {
          selectCustomHazardOption('create_hazard', '');
        }
        const editHazardInput = document.getElementById('edit_purok_hazards');
        if (editHazardInput && editHazardInput.value === name) {
          selectCustomHazardOption('edit_hazard', '');
        }

        if (typeof showToast === 'function') {
          showToast(`Hazard Type "${name}" removed from database options.`, 'info');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Error deleting hazard option.', 'danger');
        } else {
          alert(data.message || 'Error deleting hazard option.');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error while deleting hazard option.', 'danger');
      }
    }
  }

  // --- Open Create Modal ---
  function openCreatePurokModal() {
    const f = document.getElementById('createPurokForm');
    if (f) f.reset();
    selectCustomOption('create_purok', '');
    selectCustomHazardOption('create_hazard', '');
    toggleDynamicPurokInput('create', false);
    toggleDynamicHazardInput('create', false);
    openModal('createPurokModal');
  }

  // --- Open View Purok Details Modal (View Only Default) ---
  function viewPurokDetails(p) {
    currentPurokInModal = p;
    isEditMode = false;

    // Header Badges
    const isArchived = (p.status === 'archived');
    const statusBadgeHtml = isArchived
      ? `<span class="badge badge-neutral">Archived</span>`
      : `<span class="badge badge-success">Active</span>`;
    document.getElementById('viewModalStatusBadge').innerHTML = statusBadgeHtml;

    const riskBadges = {
      'Critical': 'badge-danger',
      'High': 'badge-warning',
      'Moderate': 'badge-info',
      'Low': 'badge-success'
    };
    const riskBadgeClass = riskBadges[p.risk_level] || 'badge-neutral';
    document.getElementById('viewModalRiskBadge').innerHTML = `<span class="badge ${riskBadgeClass}">${escapeHtml(p.risk_level || 'Moderate')} Risk</span>`;

    // Identity Banner
    document.getElementById('view_purok_display_name').innerText = p.name;
    document.getElementById('view_purok_meta_sub').innerText = `Barangay <?= clean($barangay['name']) ?> • Sub-community Cluster`;

    // Section 2 Heading
    document.getElementById('viewPurokSectionHeading').innerHTML = `
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
      Purok Details (${escapeHtml(p.name)})
    `;
    document.getElementById('viewPurokModeHintText').innerHTML = `(View Only Mode)`;

    // Populate Input Values
    document.getElementById('view_purok_id').value = p.id;

    // Name values
    document.getElementById('view_purok_name_readonly').value = p.name || '';
    selectCustomOption('edit_purok', p.name || '');

    // Hazard values
    document.getElementById('view_purok_hazards_readonly').value = p.hazard_types || 'None listed';
    selectCustomHazardOption('edit_hazard', p.hazard_types || '');

    // Numeric & Select values
    document.getElementById('view_purok_risk').value = p.risk_level || 'Moderate';
    document.getElementById('view_purok_households').value = p.households || 0;
    document.getElementById('view_purok_population').value = p.population || 0;
    document.getElementById('view_purok_lat').value = p.coordinates_lat || '';
    document.getElementById('view_purok_lng').value = p.coordinates_lng || '';

    // Set View Mode (Readonly displays visible, custom edit dropdowns hidden, inputs disabled)
    document.getElementById('view_purok_name_readonly').style.display = 'block';
    document.getElementById('edit_purok_name_wrapper').style.display = 'none';

    document.getElementById('view_purok_hazards_readonly').style.display = 'block';
    document.getElementById('edit_purok_hazards_wrapper').style.display = 'none';

    document.getElementById('view_purok_risk').disabled = true;
    document.getElementById('view_purok_households').disabled = true;
    document.getElementById('view_purok_population').disabled = true;
    document.getElementById('view_purok_lat').disabled = true;
    document.getElementById('view_purok_lng').disabled = true;

    toggleDynamicPurokInput('edit', false);
    toggleDynamicHazardInput('edit', false);

    // Button states
    document.getElementById('modalPurokDeleteBtn').style.display = 'inline-flex';
    document.getElementById('modalPurokArchiveBtn').style.display = isArchived ? 'none' : 'inline-flex';
    document.getElementById('modalPurokRestoreBtn').style.display = isArchived ? 'inline-flex' : 'none';

    document.getElementById('modalPurokEditBtn').style.display = isArchived ? 'none' : 'inline-flex';
    document.getElementById('modalPurokSaveBtn').style.display = 'none';
    document.getElementById('modalPurokCancelEditBtn').style.display = 'none';

    openModal('viewPurokModal');
    const vBody = document.querySelector('#viewPurokModal .modal-body');
    if (vBody) vBody.scrollTop = 0;
  }

  // Alias for backward compatibility if any legacy code calls openEditPurokModal
  function openEditPurokModal(p) {
    viewPurokDetails(p);
    enablePurokEditMode();
  }

  // --- Enable Edit Mode Inside Modal ---
  function enablePurokEditMode() {
    if (!currentPurokInModal) return;
    isEditMode = true;

    document.getElementById('viewPurokSectionHeading').innerHTML = `
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
      Edit Purok Details (${escapeHtml(currentPurokInModal.name)})
    `;
    document.getElementById('viewPurokModeHintText').innerHTML = `<span style="color:var(--color-primary);font-weight:600;">(Editing Mode)</span>`;

    // Swap readonly inputs for interactive custom dropdowns
    document.getElementById('view_purok_name_readonly').style.display = 'none';
    document.getElementById('edit_purok_name_wrapper').style.display = 'block';

    document.getElementById('view_purok_hazards_readonly').style.display = 'none';
    document.getElementById('edit_purok_hazards_wrapper').style.display = 'block';

    // Enable other inputs
    document.getElementById('view_purok_risk').disabled = false;
    document.getElementById('view_purok_households').disabled = false;
    document.getElementById('view_purok_population').disabled = false;
    document.getElementById('view_purok_lat').disabled = false;
    document.getElementById('view_purok_lng').disabled = false;

    // Toggle button visibility
    document.getElementById('modalPurokEditBtn').style.display = 'none';
    document.getElementById('modalPurokSaveBtn').style.display = 'inline-flex';
    document.getElementById('modalPurokCancelEditBtn').style.display = 'inline-flex';
  }

  // --- Cancel Edit Mode Inside Modal ---
  function cancelPurokEditMode() {
    if (!currentPurokInModal) return;
    viewPurokDetails(currentPurokInModal);
  }

  // --- Delete Purok Handler (from Modal) ---
  function onModalPurokDelete() {
    if (!currentPurokInModal) return;
    const p = currentPurokInModal;

    showConfirmModal(
      'Confirm Purok Deletion',
      `Are you sure you want to permanently delete Purok "${p.name}"? This action cannot be undone.`,
      async () => {
        const formData = new FormData();
        formData.append('id', p.id);

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/delete.php', {
            method: 'POST',
            body: formData,
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message || `Purok "${p.name}" deleted successfully.`, 'success');
            closeModal('viewPurokModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Error deleting purok.', 'danger');
          }
        } catch (err) {
          showToast('Network error while deleting purok.', 'danger');
        }
      },
      'btn-danger',
      'Delete Permanently'
    );
  }

  // --- Archive Purok Handler (from Modal) ---
  function onModalPurokArchive() {
    if (!currentPurokInModal) return;
    const p = currentPurokInModal;

    showConfirmModal(
      'Confirm Archive Purok',
      `Are you sure you want to archive Purok "${p.name}"? It will be moved to the Archived Puroks tab and can be restored at any time.`,
      async () => {
        const formData = new FormData();
        formData.append('id', p.id);

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/archive.php', {
            method: 'POST',
            body: formData,
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message || `Purok "${p.name}" archived successfully.`, 'success');
            closeModal('viewPurokModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Error archiving purok.', 'danger');
          }
        } catch (err) {
          showToast('Network error while archiving purok.', 'danger');
        }
      },
      'btn-warning',
      'Archive Purok'
    );
  }

  // --- Restore Purok Handler (from Modal) ---
  function onModalPurokRestore() {
    if (!currentPurokInModal) return;
    const p = currentPurokInModal;

    showConfirmModal(
      'Confirm Restore Purok',
      `Are you sure you want to restore Purok "${p.name}" back to active status?`,
      async () => {
        const formData = new FormData();
        formData.append('id', p.id);

        try {
          const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/restore.php', {
            method: 'POST',
            body: formData,
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          });
          const data = await res.json();
          if (data.success) {
            showToast(data.message || `Purok "${p.name}" restored to active status.`, 'success');
            closeModal('viewPurokModal');
            setTimeout(() => location.reload(), 700);
          } else {
            showToast(data.message || 'Error restoring purok.', 'danger');
          }
        } catch (err) {
          showToast('Network error while restoring purok.', 'danger');
        }
      },
      'btn-primary',
      'Restore Purok'
    );
  }

  // --- Save Changes from Modal Handler ---
  async function handleViewPurokSave(e) {
    e.preventDefault();
    const form = e.target;
    const purokName = (document.getElementById('edit_purok_name')?.value || '').trim();
    const hazardType = (document.getElementById('edit_purok_hazards')?.value || '').trim();

    if (!purokName) {
      if (typeof showToast === 'function') {
        showToast('Please select or add a Purok Cluster Name.', 'danger');
      } else {
        alert('Please select or add a Purok Cluster Name.');
      }
      toggleCustomDropdown('edit_purok_menu');
      return;
    }

    if (!hazardType) {
      if (typeof showToast === 'function') {
        showToast('Please select or add a Primary Hazard Type.', 'danger');
      } else {
        alert('Please select or add a Primary Hazard Type.');
      }
      toggleCustomDropdown('edit_hazard_menu');
      return;
    }

    const btn = document.getElementById('modalPurokSaveBtn');
    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/update.php', {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'Purok updated successfully.', 'success');
        closeModal('viewPurokModal');
        setTimeout(() => location.reload(), 700);
      } else {
        showToast(data.message || 'Error updating purok.', 'danger');
        btn.disabled = false;
        btn.innerText = 'Save Changes';
      }
    } catch (err) {
      showToast('Network error while saving changes.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  }

  // --- Form Submission for Create Purok ---
  async function handleCreatePurok(e) {
    e.preventDefault();
    const form = e.target;
    const purokName = (document.getElementById('create_purok_name')?.value || '').trim();
    const hazardType = (document.getElementById('create_purok_hazards')?.value || '').trim();

    if (!purokName) {
      if (typeof showToast === 'function') {
        showToast('Please select or add a Purok Cluster Name.', 'danger');
      } else {
        alert('Please select or add a Purok Cluster Name.');
      }
      toggleCustomDropdown('create_purok_menu');
      return;
    }

    if (!hazardType) {
      if (typeof showToast === 'function') {
        showToast('Please select or add a Primary Hazard Type.', 'danger');
      } else {
        alert('Please select or add a Primary Hazard Type.');
      }
      toggleCustomDropdown('create_hazard_menu');
      return;
    }

    const btn = document.getElementById('btnSubmitCreatePurok');
    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/puroks/create.php', {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json();
      if (data.success) {
        showToast(data.message || 'Purok created successfully.', 'success');
        closeModal('createPurokModal');
        setTimeout(() => location.reload(), 700);
      } else {
        showToast(data.message || 'Error creating purok.', 'danger');
        btn.disabled = false;
        btn.innerText = 'Save Purok';
      }
    } catch (err) {
      showToast('Network error while saving purok.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Save Purok';
    }
  }

  // Reusable HTML escape helper
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>