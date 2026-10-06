<?php
// ============================================================================
// Views (ICDRRMO): Manage Barangays Module
// Comprehensive management of all 44 official barangays of Iligan City
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$db = getDBConnection();

$pageTitle = "Manage Barangays — ICDRRMO Admin";
require_once __DIR__ . '/../layouts/header.php';

$viewTab = in_array($_GET['tab'] ?? '', ['active', 'archived']) ? $_GET['tab'] : 'active';
$filterRisk = $_GET['risk_level'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT b.*,
           (SELECT COUNT(*) FROM puroks p WHERE p.barangay_id = b.id) AS puroks_count,
           (SELECT COUNT(*) FROM evacuation_areas ea WHERE ea.barangay_id = b.id) AS evac_count,
           (SELECT COUNT(*) FROM users u WHERE u.barangay_id = b.id AND u.role = 'barangay_head') AS captains_count,
           (SELECT COUNT(*) FROM disaster_requests dr WHERE dr.barangay_id = b.id AND dr.status NOT IN ('Completed', 'Rejected')) AS active_requests_count
    FROM barangays b
    WHERE b.status = ?
";
$params = [$viewTab];

if (!empty($filterRisk)) {
    $sql .= " AND b.risk_level = ?";
    $params[] = $filterRisk;
}

if (!empty($search)) {
    $sql .= " AND (b.name LIKE ? OR b.contact_person LIKE ? OR b.contact_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(b.risk_level, 'Critical', 'High', 'Moderate', 'Low'), b.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$barangaysList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$activeCount = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status = 'active'")->fetchColumn();
$archivedCount = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status = 'archived'")->fetchColumn();
$totalCount = $activeCount;
$highRiskCount = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status = 'active' AND risk_level IN ('Critical', 'High')")->fetchColumn();
$totalPop = (int)$db->query("SELECT COALESCE(SUM(population), 0) FROM barangays WHERE status = 'active'")->fetchColumn();
$totalHouseholds = (int)$db->query("SELECT COALESCE(SUM(total_households), 0) FROM barangays WHERE status = 'active'")->fetchColumn();

// 44 Official Barangays of Iligan City with verified geodetic coordinates
$officialBarangays = [
    'Abuno' => ['lat' => 8.184697, 'lng' => 124.256959],
    'Acmac-Mariano Badelles Sr.' => ['lat' => 8.279906, 'lng' => 124.274134],
    'Bagong Silang' => ['lat' => 8.241626, 'lng' => 124.251969],
    'Bonbonon' => ['lat' => 8.267662, 'lng' => 124.290299],
    'Bunawan' => ['lat' => 8.303333, 'lng' => 124.303922],
    'Buru-un' => ['lat' => 8.188072, 'lng' => 124.172216],
    'Dalipuga' => ['lat' => 8.305960, 'lng' => 124.258587],
    'Del Carmen' => ['lat' => 8.232228, 'lng' => 124.258058],
    'Digkilaan' => ['lat' => 8.313922, 'lng' => 124.372466],
    'Ditucalan' => ['lat' => 8.176033, 'lng' => 124.192768],
    'Dulag' => ['lat' => 8.205348, 'lng' => 124.368826],
    'Hinaplanon' => ['lat' => 8.246596, 'lng' => 124.259474],
    'Hindang' => ['lat' => 8.303563, 'lng' => 124.353225],
    'Kabacsanan' => ['lat' => 8.281250, 'lng' => 124.308865],
    'Kalilangan' => ['lat' => 8.154576, 'lng' => 124.391046],
    'Kiwalan' => ['lat' => 8.281893, 'lng' => 124.266195],
    'Lanipao' => ['lat' => 8.227227, 'lng' => 124.340547],
    'Luinab' => ['lat' => 8.247049, 'lng' => 124.267966],
    'Mahayahay' => ['lat' => 8.224316, 'lng' => 124.238313],
    'Mainit' => ['lat' => 8.297810, 'lng' => 124.387679],
    'Mandulog' => ['lat' => 8.244349, 'lng' => 124.306129],
    'Maria Cristina' => ['lat' => 8.198912, 'lng' => 124.193998],
    'Pala-o (Palao)' => ['lat' => 8.229264, 'lng' => 124.254059],
    'Puga-an' => ['lat' => 8.228983, 'lng' => 124.274746],
    'Poblacion' => ['lat' => 8.228898, 'lng' => 124.234013],
    'Rogongon' => ['lat' => 8.238865, 'lng' => 124.411368],
    'San Miguel' => ['lat' => 8.238596, 'lng' => 124.248663],
    'San Roque' => ['lat' => 8.257546, 'lng' => 124.261743],
    'Santa Elena' => ['lat' => 8.195303, 'lng' => 124.226301],
    'Santa Filomena' => ['lat' => 8.269375, 'lng' => 124.260689],
    'Santiago' => ['lat' => 8.250560, 'lng' => 124.244460],
    'Santo Rosario' => ['lat' => 8.242852, 'lng' => 124.252966],
    'Saray (Saray-Tibanga)' => ['lat' => 8.235098, 'lng' => 124.237157],
    'Suarez' => ['lat' => 8.191592, 'lng' => 124.217092],
    'Tambacan' => ['lat' => 8.223634, 'lng' => 124.234526],
    'Tibanga' => ['lat' => 8.239938, 'lng' => 124.240781],
    'Tipanoy' => ['lat' => 8.198544, 'lng' => 124.250993],
    'Tomas Cabili' => ['lat' => 8.215800, 'lng' => 124.238900],
    'Tominobo Proper' => ['lat' => 8.204500, 'lng' => 124.232000],
    'Tominobo Upper' => ['lat' => 8.172752, 'lng' => 124.224325],
    'Ubaldo Laya' => ['lat' => 8.223500, 'lng' => 124.246500],
    'Upper Hinaplanon' => ['lat' => 8.255802, 'lng' => 124.268887],
    'Villa Verde' => ['lat' => 8.232672, 'lng' => 124.242997],
    'Panoroganan' => ['lat' => 8.174503, 'lng' => 124.424734]
];

// Query all currently registered barangay names
$registeredNamesRaw = $db->query("SELECT name FROM barangays")->fetchAll(PDO::FETCH_COLUMN);
$registeredNamesSet = [];
foreach ($registeredNamesRaw as $rName) {
    $registeredNamesSet[strtolower(trim($rName))] = true;
}
?>

<!-- Leaflet GIS Map Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* Robust Modal Styling for Barangays Module */
#createBarangayModal.modal-overlay,
#viewBarangayModal.modal-overlay {
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

#createBarangayModal.modal-overlay.active,
#viewBarangayModal.modal-overlay.active {
  display: flex !important;
}

#createBarangayModal .modal-dialog {
  max-width: 500px !important;
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

#viewBarangayModal .modal-dialog {
  max-width: 620px !important;
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

#createBarangayModal .modal-header,
#viewBarangayModal .modal-header {
  padding: 14px 20px !important;
  border-bottom: 1px solid var(--color-border, #E2E8F0) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  background: #FFFFFF !important;
}

#createBarangayModal .modal-body,
#viewBarangayModal .modal-body {
  overflow-y: auto !important;
  max-height: calc(100vh - 160px) !important;
  padding: 18px 20px !important;
}

#createBarangayModal .modal-footer,
#viewBarangayModal .modal-footer {
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
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Manage Barangays & Administrative Jurisdictions</h1>
    <p class="page-header-desc">
      Official administration of all 44 barangays of Iligan City: risk classifications, demographics, disaster coverage radius, and operations focal personnel.
    </p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openCreateBarangayModal()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      Add New Barangay
    </button>
  </div>
</div>

<!-- Navigation Tabs: Active vs Archived -->
<div style="display:flex;gap:8px;margin-bottom:var(--space-3);align-items:center;">
  <a href="<?= BASE_URL ?>/views/icdrrmo/barangays.php?tab=active" class="btn btn-sm <?= $viewTab === 'active' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;">
    Active Barangays (<?= $activeCount ?>)
  </a>
  <a href="<?= BASE_URL ?>/views/icdrrmo/barangays.php?tab=archived" class="btn btn-sm <?= $viewTab === 'archived' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:11px;<?= $viewTab === 'archived' ? '' : 'color:var(--color-text-muted);' ?>">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:-1px;"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
    Archived Barangays (<?= $archivedCount ?>)
  </a>
</div>

<!-- Overview Metrics -->
<div class="metrics-grid">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Active Barangays</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
    </div>
    <div class="metric-value"><?= number_format($totalCount) ?></div>
    <div class="metric-meta">Official Iligan City Jurisdictions</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">High & Critical Risk</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    </div>
    <div class="metric-value" style="color:var(--color-danger);"><?= number_format($highRiskCount) ?></div>
    <div class="metric-meta">Priority Disaster Monitoring Zones</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Registered Population</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($totalPop) ?></div>
    <div class="metric-meta">Across Active Barangays</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Total Households</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($totalHouseholds) ?></div>
    <div class="metric-meta">Target Family Beneficiary Units</div>
  </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom:var(--space-4);">
  <div class="card-body" style="padding:10px 14px;">
    <form method="GET" class="filter-bar" style="margin-bottom:0;">
      <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">

      <div class="filter-group">
        <div class="search-input-wrap">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="search" class="form-control" placeholder="Search barangay name, captain..." value="<?= clean($search) ?>">
        </div>

        <select name="risk_level" class="form-control" style="width:160px;">
          <option value="">All Risk Levels</option>
          <option value="Critical" <?= $filterRisk === 'Critical' ? 'selected' : '' ?>>Critical Risk</option>
          <option value="High" <?= $filterRisk === 'High' ? 'selected' : '' ?>>High Risk</option>
          <option value="Moderate" <?= $filterRisk === 'Moderate' ? 'selected' : '' ?>>Moderate Risk</option>
          <option value="Low" <?= $filterRisk === 'Low' ? 'selected' : '' ?>>Low Risk</option>
        </select>

        <button type="submit" class="btn btn-outline">Apply Filter</button>
        <?php if (!empty($search) || !empty($filterRisk)): ?>
          <a href="<?= BASE_URL ?>/views/icdrrmo/barangays.php?tab=<?= clean($viewTab) ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </div>
      <div>
        <span style="font-size:11px;color:var(--color-text-muted);font-weight:600;">
          Showing: <?= count($barangaysList) ?> Barangay(s) (<?= ucfirst($viewTab) ?>)
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Barangays Data Table -->
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th>Barangay Name</th>
        <th>Risk Level</th>
        <th>Barangay Head / Focal</th>
        <th>Contact Number</th>
        <th>Population</th>
        <th>Households</th>
        <th>Hazard Radius</th>
        <th>Puroks / Shelters</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($barangaysList)): ?>
        <tr><td colspan="9" style="text-align:center;padding:28px;">No barangays match your search filter criteria.</td></tr>
      <?php else: ?>
        <?php foreach ($barangaysList as $b): ?>
          <tr>
            <td>
              <div style="font-weight:700;color:var(--color-primary);font-size:12px;"><?= clean($b['name']) ?></div>
              <div style="font-size:9.5px;color:var(--color-text-muted);">
                GPS: <?= number_format((float)$b['coordinates_lat'], 4) ?>, <?= number_format((float)$b['coordinates_lng'], 4) ?>
              </div>
            </td>
            <td><?= renderStatusBadge($b['risk_level']) ?></td>
            <td style="font-size:11px;">
              <?php if (!empty($b['contact_person'])): ?>
                <span style="font-weight:600;"><?= clean($b['contact_person']) ?></span>
              <?php else: ?>
                <span style="color:var(--color-text-muted);font-style:italic;">Unassigned</span>
              <?php endif; ?>
            </td>
            <td style="font-family:var(--font-secondary);font-size:10px;">
              <?php if (!empty($b['contact_number'])): ?>
                <a href="tel:<?= clean($b['contact_number']) ?>" style="color:var(--color-secondary);text-decoration:none;font-weight:600;">
                  <?= clean($b['contact_number']) ?>
                </a>
              <?php else: ?>
                <span style="color:var(--color-text-muted);font-style:italic;">Not set</span>
              <?php endif; ?>
            </td>
            <td style="font-family:var(--font-secondary);font-weight:600;">
              <?= $b['population'] !== null ? number_format((float)$b['population']) : '<span style="color:var(--color-text-muted);font-style:italic;font-weight:normal;">—</span>' ?>
            </td>
            <td style="font-family:var(--font-secondary);color:var(--color-text-muted);">
              <?= $b['total_households'] !== null ? number_format((float)$b['total_households']) : '<span style="color:var(--color-text-muted);font-style:italic;">—</span>' ?>
            </td>
            <td>
              <?php if ($b['area_radius'] !== null): ?>
                <span class="badge badge-neutral" style="font-size:9px;">
                  <?= number_format((float)$b['area_radius']) ?>m radius
                </span>
              <?php else: ?>
                <span style="color:var(--color-text-muted);font-style:italic;font-size:10px;">Not set</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-size:10px;">
                <b><?= $b['puroks_count'] ?></b> Purok(s) &bull; <b><?= $b['evac_count'] ?></b> Shelter(s)
              </div>
              <?php if ($b['active_requests_count'] > 0): ?>
                <span class="badge badge-warning" style="font-size:8px;margin-top:2px;">
                  <?= $b['active_requests_count'] ?> Active Incident
                </span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($viewTab === 'archived'): ?>
                <div style="display:flex;gap:6px;align-items:center;">
                  <button class="btn btn-outline btn-sm" onclick="openViewBarangayModal(<?= htmlspecialchars(json_encode($b)) ?>)">
                    View
                  </button>
                  <button class="btn btn-success btn-sm" onclick="restoreBarangayDirect(<?= (int)$b['id'] ?>, '<?= clean(addslashes($b['name'])) ?>')" title="Restore to Active Jurisdictions" style="display:inline-flex;align-items:center;gap:3px;">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                    Restore
                  </button>
                </div>
              <?php else: ?>
                <button class="btn btn-outline btn-sm" onclick="openViewBarangayModal(<?= htmlspecialchars(json_encode($b)) ?>)">
                  View
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal 1: Register New Barangay -->
<div class="modal-overlay" id="createBarangayModal">
  <div class="modal-dialog" style="max-width:500px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Register New Barangay</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Select an official Iligan City barangay to register. Geographic coordinates are automatically pinned.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createBarangayModal')">&times;</button>
    </div>
    <form id="createBarangayForm" method="POST" action="<?= BASE_URL ?>/backend/functions/barangays/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <!-- Auto-located coordinates passed to backend -->
      <input type="hidden" name="coordinates_lat" id="create_b_lat" value="">
      <input type="hidden" name="coordinates_lng" id="create_b_lng" value="">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:18px 20px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="create_b_name" style="font-weight:700;">
            Barangay Name <span style="color:var(--color-danger)">*</span>
          </label>
          <select name="name" id="create_b_name" class="form-control" required onchange="onBarangayNameSelect(this.value)" style="font-weight:600;padding:10px 12px;font-size:13px;">
            <option value="">-- Select Barangay Name --</option>
            <?php foreach ($officialBarangays as $bName => $bCoord): ?>
              <?php $isAlready = isset($registeredNamesSet[strtolower(trim($bName))]); ?>
              <option value="<?= clean($bName) ?>" <?= $isAlready ? 'disabled style="color:#94a3b8;background:#f8fafc;"' : '' ?>>
                <?= clean($bName) ?> <?= $isAlready ? '(Already Registered)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--color-text-muted);font-size:11px;margin-top:5px;display:block;">
            Barangay name must be unique. Already registered barangays cannot be added twice.
          </small>
        </div>

        <!-- Geographic Centroid Location Display -->
        <div>
          <!-- Placeholder state when nothing selected -->
          <div id="create_geo_placeholder" style="padding:18px 14px;background:#F8FAFC;border:1.5px dashed #CBD5E1;border-radius:8px;text-align:center;">
            <div style="font-size:22px;margin-bottom:4px;">📍</div>
            <div style="font-size:12px;font-weight:700;color:#475569;">Auto-Locate Geolocation</div>
            <div style="font-size:11px;color:#94A3B8;margin-top:2px;">
              Select a barangay name above to auto-detect its official latitude and longitude coordinates.
            </div>
          </div>

          <!-- Active state when barangay is selected -->
          <div id="create_geo_resolved" style="display:none;padding:14px 16px;background:linear-gradient(135deg, #F0FDF4 0%, #EFF6FF 100%);border:1px solid #BBF7D0;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;background:#10B981;color:#FFFFFF;border-radius:50%;font-size:12px;font-weight:bold;">✓</span>
                <div>
                  <span style="font-size:13px;font-weight:800;color:#0F172A;" id="resolved_b_name">Barangay</span>
                  <span class="badge badge-success" style="font-size:9.5px;margin-left:6px;vertical-align:middle;">Centroid Located</span>
                </div>
              </div>
              <span style="font-size:10px;color:#059669;font-weight:700;">Iligan City Datum</span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <div style="background:#FFFFFF;border:1px solid #E2E8F0;border-radius:6px;padding:8px 10px;">
                <span style="display:block;font-size:10px;color:#64748B;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Latitude</span>
                <span id="resolved_b_lat" style="font-size:13px;font-weight:700;color:#0F172A;font-family:var(--font-secondary, monospace);">—</span>
              </div>
              <div style="background:#FFFFFF;border:1px solid #E2E8F0;border-radius:6px;padding:8px 10px;">
                <span style="display:block;font-size:10px;color:#64748B;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Longitude</span>
                <span id="resolved_b_lng" style="font-size:13px;font-weight:700;color:#0F172A;font-family:var(--font-secondary, monospace);">—</span>
              </div>
            </div>

            <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(226,232,240,0.8);font-size:11px;color:#64748B;display:flex;align-items:center;gap:6px;">
              <span>ℹ️</span>
              <span>All other attributes (Captain, Contact, Population, Risk Level, Radius) will automatically default to <strong>NULL</strong>.</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('createBarangayModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitCreate" disabled>Register Barangay</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 2: View / Edit Barangay Modal -->
<div class="modal-overlay" id="viewBarangayModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <div style="display:flex;align-items:center;gap:8px;">
          <h3 class="modal-title" id="view_b_title" style="margin:0;">Barangay Details</h3>
          <span id="view_b_status_badge" class="badge badge-success" style="font-size:10px;">Active</span>
        </div>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;" id="view_b_subtitle">
          Administrative profile, focal leadership, hazard risk classification, and geodetic positioning.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('viewBarangayModal')">&times;</button>
    </div>
    <form id="viewBarangayForm" method="POST" action="<?= BASE_URL ?>/backend/functions/barangays/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="id" id="view_b_id">
      
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
        <!-- Status & Mode Banner -->
        <div id="view_edit_banner" style="display:none;padding:8px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;font-size:11px;color:#1E40AF;align-items:center;gap:6px;">
          <span>✏️</span> <strong>Edit Mode Active:</strong> You can edit demographic fields and reposition the pin on the map. Click "Save Changes" to apply.
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-weight:700;">Barangay Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" name="name" id="view_b_name" class="form-control" required disabled>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Barangay Captain / Head</label>
            <input type="text" name="contact_person" id="view_b_contact_person" class="form-control" placeholder="Unassigned" disabled>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Contact Number</label>
            <input type="text" name="contact_number" id="view_b_contact_number" class="form-control" placeholder="Not set" disabled>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Population</label>
            <input type="number" name="population" id="view_b_population" class="form-control" min="0" placeholder="Not set" disabled>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Total Households</label>
            <input type="number" name="total_households" id="view_b_total_households" class="form-control" min="0" placeholder="Not set" disabled>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Hazard Risk Classification</label>
            <select name="risk_level" id="view_b_risk_level" class="form-control" disabled>
              <option value="">Unclassified</option>
              <option value="Low">Low Risk</option>
              <option value="Moderate">Moderate Risk</option>
              <option value="High">High Risk</option>
              <option value="Critical">Critical Risk</option>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Coverage / Hazard Radius (m)</label>
            <input type="number" name="area_radius" id="view_b_area_radius" class="form-control" min="100" max="10000" placeholder="Not set" disabled oninput="onRadiusChange(this.value)">
          </div>
        </div>

        <!-- Interactive Map Section for Longitude & Latitude Pinning -->
        <div style="border-top:1px solid var(--color-border, #E2E8F0);padding-top:10px;margin-top:2px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <label class="form-label" style="font-weight:700;margin-bottom:0;display:flex;align-items:center;gap:5px;font-size:12px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
              Barangay Geographic Centroid (Interactive Pin)
            </label>
            <span id="map_mode_badge" class="badge badge-neutral" style="font-size:9.5px;">View Mode (Locked)</span>
          </div>

          <!-- Leaflet Map Container -->
          <div style="position:relative;border-radius:8px;overflow:hidden;border:1px solid #CBD5E1;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
            <div id="view_barangay_map" style="height:220px;width:100%;background:#e2e8f0;"></div>

            <!-- Dynamic hint badge inside map during edit mode -->
            <div id="map_edit_hint" style="display:none;position:absolute;bottom:8px;left:50%;transform:translateX(-50%);z-index:400;background:rgba(15,23,42,0.85);color:#FFFFFF;padding:4px 12px;border-radius:20px;font-size:10.5px;font-weight:600;pointer-events:none;backdrop-filter:blur(3px);box-shadow:0 2px 6px rgba(0,0,0,0.3);white-space:nowrap;">
              📍 Drag pin or click map to reposition barangay
            </div>
          </div>

          <!-- Auto-synchronized Lat / Lng Display & Form Hidden Inputs -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">
            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:6px 10px;display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:10px;color:#64748B;font-weight:700;text-transform:uppercase;">Latitude</span>
              <span id="display_b_lat" style="font-size:12px;font-weight:700;color:#0F172A;font-family:var(--font-secondary, monospace);">8.228000</span>
              <input type="hidden" name="coordinates_lat" id="view_b_lat" value="8.228000">
            </div>
            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:6px 10px;display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:10px;color:#64748B;font-weight:700;text-transform:uppercase;">Longitude</span>
              <span id="display_b_lng" style="font-size:12px;font-weight:700;color:#0F172A;font-family:var(--font-secondary, monospace);">124.245200</span>
              <input type="hidden" name="coordinates_lng" id="view_b_lng" value="124.245200">
            </div>
          </div>
          <small id="map_sub_hint" style="color:var(--color-text-muted);font-size:10.5px;margin-top:4px;display:block;">
            * Official geographic datum for emergency GIS dispatching and disaster radius calculations.
          </small>
        </div>

        <!-- Quick Summary Box -->
        <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px;font-size:11px;color:#475569;display:flex;justify-content:space-around;text-align:center;">
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Puroks</span>
            <strong id="view_b_puroks_badge">0</strong>
          </div>
          <div style="border-left:1px solid #E2E8F0;height:24px;"></div>
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Evacuation Centers</span>
            <strong id="view_b_evac_badge">0</strong>
          </div>
          <div style="border-left:1px solid #E2E8F0;height:24px;"></div>
          <div>
            <span style="font-size:10px;color:#64748B;display:block;">Active Incidents</span>
            <strong id="view_b_incidents_badge">0</strong>
          </div>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Archive Button (visible when active) -->
          <button type="button" class="btn btn-warning btn-sm" id="btnArchiveBarangay" onclick="onArchiveBarangayClick()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
            Archive
          </button>

          <!-- Delete Button (always clickable, triggers confirm modal) -->
          <button type="button" class="btn btn-danger btn-sm" id="btnDeleteBarangay" onclick="onDeleteBarangayClick()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            Delete
          </button>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
          <!-- Restore Button (visible when viewing archived barangay) -->
          <button type="button" class="btn btn-success btn-sm" id="btnRestoreBarangay" onclick="onRestoreBarangayClick()" style="display:none;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
            Restore Barangay
          </button>

          <!-- Cancel Edit Button (only in edit mode) -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCancelEditBarangay" onclick="cancelBarangayEditMode()" style="display:none;">
            Cancel
          </button>

          <!-- Edit Button (in view mode for active barangay) -->
          <button type="button" class="btn btn-primary btn-sm" id="btnToggleEditBarangay" onclick="enableBarangayEditMode()" style="display:inline-flex;align-items:center;gap:4px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit
          </button>

          <!-- Save Button (only in edit mode) -->
          <button type="submit" class="btn btn-primary btn-sm" id="btnSaveBarangay" style="display:none;">
            Save Changes
          </button>

          <!-- Close Button -->
          <button type="button" class="btn btn-outline btn-sm" id="btnCloseViewModal" onclick="closeModal('viewBarangayModal')">
            Close
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
const officialCoords = <?= json_encode($officialBarangays) ?>;
let currentBarangay = null;
let bMap = null;
let bMarker = null;
let bCircle = null;
let isBarangayEditMode = false;

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

function openCreateBarangayModal() {
  const form = document.getElementById('createBarangayForm');
  if (form) form.reset();
  onBarangayNameSelect('');
  openModal('createBarangayModal');
}

function onBarangayNameSelect(name) {
  const btn = document.getElementById('btnSubmitCreate');
  const placeholder = document.getElementById('create_geo_placeholder');
  const resolved = document.getElementById('create_geo_resolved');
  const latInput = document.getElementById('create_b_lat');
  const lngInput = document.getElementById('create_b_lng');

  if (!name || !officialCoords[name]) {
    btn.disabled = true;
    latInput.value = '';
    lngInput.value = '';
    placeholder.style.display = 'block';
    resolved.style.display = 'none';
    return;
  }

  const coords = officialCoords[name];
  latInput.value = coords.lat.toFixed(6);
  lngInput.value = coords.lng.toFixed(6);

  document.getElementById('resolved_b_name').innerText = 'Barangay ' + name;
  document.getElementById('resolved_b_lat').innerText = coords.lat.toFixed(6);
  document.getElementById('resolved_b_lng').innerText = coords.lng.toFixed(6);

  placeholder.style.display = 'none';
  resolved.style.display = 'block';
  btn.disabled = false;
}

// Initialize or Update Leaflet Map for View/Edit Barangay Modal
function initOrUpdateBarangayMap(lat, lng, radius, bName) {
  const defaultLat = 8.228000;
  const defaultLng = 124.245200;
  const useLat = (lat !== null && !isNaN(lat) && lat !== '') ? parseFloat(lat) : defaultLat;
  const useLng = (lng !== null && !isNaN(lng) && lng !== '') ? parseFloat(lng) : defaultLng;

  updateCoordsFields(useLat, useLng);

  const container = document.getElementById('view_barangay_map');
  if (!container) return;

  if (!bMap) {
    bMap = L.map('view_barangay_map', {
      center: [useLat, useLng],
      zoom: 14,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(bMap);

    // Standard Leaflet Icon
    const pinIcon = L.icon({
      iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
      iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
      shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
      iconSize: [25, 41],
      iconAnchor: [12, 41],
      popupAnchor: [1, -34],
      shadowSize: [41, 41]
    });

    bMarker = L.marker([useLat, useLng], {
      draggable: false,
      icon: pinIcon
    }).addTo(bMap);

    bMarker.bindPopup(`<strong>Barangay ${escapeHtml(bName || '')}</strong><br><span style="font-size:11px;color:#64748b;">GPS: ${useLat.toFixed(6)}, ${useLng.toFixed(6)}</span>`);

    // Dragend listener for marker
    bMarker.on('dragend', function (e) {
      const pos = e.target.getLatLng();
      updateCoordsFields(pos.lat, pos.lng);
      if (bCircle) bCircle.setLatLng(pos);
      bMarker.getPopup().setContent(`<strong>Barangay ${escapeHtml(document.getElementById('view_b_name').value || '')}</strong><br><span style="font-size:11px;color:#64748b;">GPS: ${pos.lat.toFixed(6)}, ${pos.lng.toFixed(6)}</span>`);
    });

    // Click listener on map to move pin
    bMap.on('click', function (e) {
      if (!isBarangayEditMode) return;
      const latlng = e.latlng;
      bMarker.setLatLng(latlng);
      updateCoordsFields(latlng.lat, latlng.lng);
      if (bCircle) bCircle.setLatLng(latlng);
      bMarker.getPopup().setContent(`<strong>Barangay ${escapeHtml(document.getElementById('view_b_name').value || '')}</strong><br><span style="font-size:11px;color:#64748b;">GPS: ${latlng.lat.toFixed(6)}, ${latlng.lng.toFixed(6)}</span>`);
    });
  } else {
    bMarker.setLatLng([useLat, useLng]);
    bMap.setView([useLat, useLng], 14);
    bMarker.getPopup().setContent(`<strong>Barangay ${escapeHtml(bName || '')}</strong><br><span style="font-size:11px;color:#64748b;">GPS: ${useLat.toFixed(6)}, ${useLng.toFixed(6)}</span>`);
  }

  // Coverage radius circle
  if (bCircle) {
    bMap.removeLayer(bCircle);
    bCircle = null;
  }
  if (radius && !isNaN(radius) && parseInt(radius) > 0) {
    bCircle = L.circle([useLat, useLng], {
      radius: parseInt(radius),
      color: '#2563EB',
      fillColor: '#93C5FD',
      fillOpacity: 0.22,
      weight: 1.5
    }).addTo(bMap);
  }

  // Invalidate map size after modal transition
  setTimeout(() => {
    if (bMap) {
      bMap.invalidateSize();
      bMap.setView([useLat, useLng], 14);
    }
  }, 220);
}

function updateCoordsFields(lat, lng) {
  const latFixed = parseFloat(lat).toFixed(6);
  const lngFixed = parseFloat(lng).toFixed(6);
  const latIn = document.getElementById('view_b_lat');
  const lngIn = document.getElementById('view_b_lng');
  const latDisp = document.getElementById('display_b_lat');
  const lngDisp = document.getElementById('display_b_lng');

  if (latIn) latIn.value = latFixed;
  if (lngIn) lngIn.value = lngFixed;
  if (latDisp) latDisp.innerText = latFixed;
  if (lngDisp) lngDisp.innerText = lngFixed;
}

function onRadiusChange(val) {
  if (!bMap || !bMarker) return;
  const r = parseInt(val);
  const latlng = bMarker.getLatLng();
  if (bCircle) {
    bMap.removeLayer(bCircle);
    bCircle = null;
  }
  if (r && !isNaN(r) && r > 0) {
    bCircle = L.circle(latlng, {
      radius: r,
      color: '#2563EB',
      fillColor: '#93C5FD',
      fillOpacity: 0.22,
      weight: 1.5
    }).addTo(bMap);
  }
}

function openViewBarangayModal(b) {
  currentBarangay = b;
  isBarangayEditMode = false;

  document.getElementById('view_b_id').value = b.id || '';
  document.getElementById('view_b_title').innerText = 'Barangay ' + (b.name || '');
  document.getElementById('view_b_name').value = b.name || '';
  document.getElementById('view_b_contact_person').value = b.contact_person || '';
  document.getElementById('view_b_contact_number').value = b.contact_number || '';
  document.getElementById('view_b_population').value = (b.population !== null && b.population !== undefined) ? b.population : '';
  document.getElementById('view_b_total_households').value = (b.total_households !== null && b.total_households !== undefined) ? b.total_households : '';
  document.getElementById('view_b_risk_level').value = b.risk_level || '';
  document.getElementById('view_b_area_radius').value = (b.area_radius !== null && b.area_radius !== undefined) ? b.area_radius : '';

  // Badges
  document.getElementById('view_b_puroks_badge').innerText = b.puroks_count || '0';
  document.getElementById('view_b_evac_badge').innerText = b.evac_count || '0';
  document.getElementById('view_b_incidents_badge').innerText = b.active_requests_count || '0';

  // Status Badge & Action buttons depending on active vs archived
  const isArchived = (b.status === 'archived');
  const statusBadge = document.getElementById('view_b_status_badge');
  const archiveBtn = document.getElementById('btnArchiveBarangay');
  const restoreBtn = document.getElementById('btnRestoreBarangay');
  const editBtn = document.getElementById('btnToggleEditBarangay');

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

  // Always reset to View mode (inputs disabled)
  cancelBarangayEditMode();

  openModal('viewBarangayModal');

  // Initialize or update the interactive map
  initOrUpdateBarangayMap(b.coordinates_lat, b.coordinates_lng, b.area_radius, b.name);
}

function enableBarangayEditMode() {
  isBarangayEditMode = true;
  setFormInputsDisabled(false);

  document.getElementById('view_edit_banner').style.display = 'flex';
  document.getElementById('btnToggleEditBarangay').style.display = 'none';
  document.getElementById('btnCloseViewModal').style.display = 'none';
  document.getElementById('btnSaveBarangay').style.display = 'inline-flex';
  document.getElementById('btnCancelEditBarangay').style.display = 'inline-flex';

  // Enable Leaflet marker dragging and interactive pinning
  if (bMarker) {
    bMarker.dragging.enable();
  }
  const badge = document.getElementById('map_mode_badge');
  if (badge) {
    badge.className = 'badge badge-warning';
    badge.innerText = 'Interactive (Click or Drag Pin)';
  }
  const hint = document.getElementById('map_edit_hint');
  if (hint) hint.style.display = 'block';

  document.getElementById('view_b_name').focus();
}

function cancelBarangayEditMode() {
  isBarangayEditMode = false;
  if (currentBarangay) {
    document.getElementById('view_b_name').value = currentBarangay.name || '';
    document.getElementById('view_b_contact_person').value = currentBarangay.contact_person || '';
    document.getElementById('view_b_contact_number').value = currentBarangay.contact_number || '';
    document.getElementById('view_b_population').value = (currentBarangay.population !== null && currentBarangay.population !== undefined) ? currentBarangay.population : '';
    document.getElementById('view_b_total_households').value = (currentBarangay.total_households !== null && currentBarangay.total_households !== undefined) ? currentBarangay.total_households : '';
    document.getElementById('view_b_risk_level').value = currentBarangay.risk_level || '';
    document.getElementById('view_b_area_radius').value = (currentBarangay.area_radius !== null && currentBarangay.area_radius !== undefined) ? currentBarangay.area_radius : '';

    const origLat = currentBarangay.coordinates_lat || 8.228000;
    const origLng = currentBarangay.coordinates_lng || 124.245200;
    updateCoordsFields(origLat, origLng);

    if (bMarker) {
      bMarker.setLatLng([origLat, origLng]);
      bMarker.dragging.disable();
    }
    if (bCircle) {
      bCircle.setLatLng([origLat, origLng]);
      if (currentBarangay.area_radius) {
        bCircle.setRadius(parseInt(currentBarangay.area_radius));
      }
    }
    if (bMap) {
      bMap.setView([origLat, origLng], 14);
    }
  }

  setFormInputsDisabled(true);
  document.getElementById('view_edit_banner').style.display = 'none';

  // Toggle buttons back
  const isArchived = currentBarangay && (currentBarangay.status === 'archived');
  const editBtn = document.getElementById('btnToggleEditBarangay');
  if (editBtn) editBtn.style.display = isArchived ? 'none' : 'inline-flex';

  document.getElementById('btnCloseViewModal').style.display = 'inline-flex';
  document.getElementById('btnSaveBarangay').style.display = 'none';
  document.getElementById('btnCancelEditBarangay').style.display = 'none';

  const badge = document.getElementById('map_mode_badge');
  if (badge) {
    badge.className = 'badge badge-neutral';
    badge.innerText = 'View Mode (Locked)';
  }
  const hint = document.getElementById('map_edit_hint');
  if (hint) hint.style.display = 'none';
}

function setFormInputsDisabled(disabled) {
  const fields = ['view_b_name', 'view_b_contact_person', 'view_b_contact_number', 'view_b_population', 'view_b_total_households', 'view_b_risk_level', 'view_b_area_radius'];
  fields.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = disabled;
  });
}

async function handleCreateBarangay(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('btnSubmitCreate');
  btn.disabled = true;
  btn.innerText = 'Registering...';

  const formData = new FormData(form);
  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/barangays/create.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      showToast(data.message, 'success');
      closeModal('createBarangayModal');
      setTimeout(() => location.reload(), 700);
    } else {
      showToast(data.message || 'Error registering barangay.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Register Barangay';
    }
  } catch (err) {
    showToast('Network or server error.', 'danger');
    btn.disabled = false;
    btn.innerText = 'Register Barangay';
  }
}

async function handleUpdateBarangay(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('btnSaveBarangay');
  btn.disabled = true;
  btn.innerText = 'Saving...';

  // Enable all fields so FormData includes their values
  setFormInputsDisabled(false);
  const formData = new FormData(form);

  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/barangays/update.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      showToast(data.message, 'success');
      closeModal('viewBarangayModal');
      setTimeout(() => location.reload(), 700);
    } else {
      showToast(data.message || 'Error updating barangay.', 'danger');
      btn.disabled = false;
      btn.innerText = 'Save Changes';
    }
  } catch (err) {
    showToast('Network or server error.', 'danger');
    btn.disabled = false;
    btn.innerText = 'Save Changes';
  }
}

// Delete Barangay Handler (Permanent Removal)
function onDeleteBarangayClick() {
  if (!currentBarangay || !currentBarangay.id) {
    showToast('No barangay selected.', 'warning');
    return;
  }
  const b = currentBarangay;
  showConfirmModal(
    'Confirm Permanent Deletion',
    `Are you sure you want to permanently delete Barangay ${b.name}? This will remove the jurisdiction and cascade to associated records. This action cannot be undone.`,
    async () => {
      try {
        const formData = new FormData();
        formData.append('id', b.id);

        const res = await fetch('<?= BASE_URL ?>/backend/functions/barangays/delete.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('viewBarangayModal');
          setTimeout(() => location.reload(), 700);
        } else {
          showToast(data.message || 'Deletion failed.', 'danger');
        }
      } catch (err) {
        showToast('Network error while deleting barangay.', 'danger');
      }
    }
  );
}

// Archive Barangay Handler (Moves to Archived Tab)
function onArchiveBarangayClick() {
  if (!currentBarangay || !currentBarangay.id) {
    showToast('No barangay selected.', 'warning');
    return;
  }
  const b = currentBarangay;
  showConfirmModal(
    'Confirm Archive Barangay',
    `Are you sure you want to archive Barangay ${b.name}? It will be moved to the Archived Barangays tab and can be restored at any time.`,
    async () => {
      try {
        const formData = new FormData();
        formData.append('id', b.id);

        const res = await fetch('<?= BASE_URL ?>/backend/functions/barangays/archive.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('viewBarangayModal');
          setTimeout(() => location.reload(), 700);
        } else {
          showToast(data.message || 'Archiving failed.', 'danger');
        }
      } catch (err) {
        showToast('Network error while archiving barangay.', 'danger');
      }
    }
  );
}

// Restore Barangay Handler from View Modal
function onRestoreBarangayClick() {
  if (!currentBarangay || !currentBarangay.id) {
    showToast('No barangay selected.', 'warning');
    return;
  }
  restoreBarangayDirect(currentBarangay.id, currentBarangay.name);
}

// Direct Restore Handler (used by table button and modal)
function restoreBarangayDirect(id, name) {
  showConfirmModal(
    'Confirm Restore Barangay',
    `Are you sure you want to restore Barangay ${name} back to Active status?`,
    async () => {
      try {
        const formData = new FormData();
        formData.append('id', id);

        const res = await fetch('<?= BASE_URL ?>/backend/functions/barangays/restore.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('viewBarangayModal');
          setTimeout(() => location.reload(), 700);
        } else {
          showToast(data.message || 'Restoration failed.', 'danger');
        }
      } catch (err) {
        showToast('Network error while restoring barangay.', 'danger');
      }
    }
  );
}

// Close modal when clicking on backdrop
document.addEventListener('DOMContentLoaded', () => {
  ['createBarangayModal', 'viewBarangayModal'].forEach(id => {
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
