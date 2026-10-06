<?php
// ============================================================================
// Views (Barangay Head): Barangay Evacuation Centers Management
// Full CRUD with Interactive Leaflet Geolocation Pinpointing & Coordinate Capture
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Profile
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

$brgyName = $barangay['name'] ?? 'Barangay';
$brgyLat = !empty($barangay['coordinates_lat']) ? (float)$barangay['coordinates_lat'] : 8.246596;
$brgyLng = !empty($barangay['coordinates_lng']) ? (float)$barangay['coordinates_lng'] : 124.259474;

// Filters
$filterStatus = trim($_GET['status'] ?? '');
$filterType = trim($_GET['type'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM evacuation_areas WHERE barangay_id = ?";
$params = [$barangayId];

if (!empty($filterStatus)) {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterType)) {
    $sql .= " AND center_type = ?";
    $params[] = $filterType;
}

if (!empty($search)) {
    $sql .= " AND (name LIKE ? OR location_address LIKE ? OR contact_officer LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(status, 'Open / Active', 'At Capacity', 'Standby', 'Closed'), name ASC";
$centerListStmt = $db->prepare($sql);
$centerListStmt->execute($params);
$centers = $centerListStmt->fetchAll(PDO::FETCH_ASSOC);

// Overall Statistics for this Barangay
$allStmt = $db->prepare("SELECT * FROM evacuation_areas WHERE barangay_id = ?");
$allStmt->execute([$barangayId]);
$allCenters = $allStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCenters = count($allCenters);
$activeCenters = 0;
$totalCapacityInd = 0;
$totalCapacityFam = 0;
$totalSheltered = 0;

foreach ($allCenters as $c) {
    if (in_array($c['status'], ['Open / Active', 'At Capacity'], true)) {
        $activeCenters++;
    }
    $totalCapacityInd += (int)$c['capacity_individuals'];
    $totalCapacityFam += (int)$c['capacity_families'];
    $totalSheltered += (int)$c['current_evacuees_count'];
}

$occupancyRate = ($totalCapacityInd > 0) ? round(($totalSheltered / $totalCapacityInd) * 100, 1) : 0;

$pageTitle = "Manage Evacuation Areas — Barangay " . $brgyName;
require_once __DIR__ . '/../layouts/header.php';
?>

<!-- Leaflet GIS Library -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* Modern styling for Leaflet interactive pinpointing & evacuation cards */
.evac-map-container {
  width: 100%;
  height: 380px;
  border-radius: var(--radius-lg, 10px);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-sm);
  z-index: 1;
}

.modal-map-picker {
  width: 100%;
  height: 250px;
  border-radius: var(--radius-md, 8px);
  border: 1px solid var(--color-border);
  margin-top: 6px;
  z-index: 1;
}

.map-pin-hint {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: #F0F4F8;
  border: 1px solid #D2DFEB;
  border-radius: var(--radius-md, 8px);
  font-size: 11px;
  color: var(--color-primary);
  margin-bottom: 8px;
}

.custom-marker-pin {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  border: 2px solid #FFFFFF;
  box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}
.custom-marker-pin span {
  transform: rotate(45deg);
  font-size: 14px;
}

.status-pin-active { background: #E53935; }
.status-pin-capacity { background: #FB8C00; }
.status-pin-standby { background: #43A047; }
.status-pin-closed { background: #78909C; }

.evac-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg, 10px);
  padding: 16px;
  box-shadow: var(--shadow-sm);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.evac-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}
</style>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>Manage Evacuation Areas — Barangay <?= clean($brgyName) ?></h1>
    <p class="page-header-desc">
      Register safety shelters, monitor live occupancy headcounts, and pinpoint exact geolocation coordinates via interactive Leaflet mapping.
    </p>
  </div>
  <div class="page-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
    <button type="button" class="btn btn-outline" onclick="scrollToOverviewMap()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
      View Shelters Map
    </button>
    <button type="button" class="btn btn-primary" onclick="openCreateEvacModal()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      Register New Evacuation Area
    </button>
  </div>
</div>

<!-- Key Performance Metrics Grid -->
<div class="metrics-grid" style="margin-bottom:var(--space-4);">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Registered Shelters</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($totalCenters) ?></div>
    <div class="metric-meta">Under Barangay <?= clean($brgyName) ?></div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Currently Sheltering</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle></svg>
    </div>
    <div class="metric-value" style="color:<?= $activeCenters > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
      <?= number_format($activeCenters) ?> Active
    </div>
    <div class="metric-meta"><?= $totalCenters - $activeCenters ?> Ready on Standby</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Sheltered Evacuees</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
    </div>
    <div class="metric-value"><?= number_format($totalSheltered) ?></div>
    <div class="metric-meta">Of <?= number_format($totalCapacityInd) ?> Individual Capacity</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Occupancy Load</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
    </div>
    <div class="metric-value" style="color:var(--color-primary);"><?= $occupancyRate ?>%</div>
    <div class="metric-meta">~<?= number_format($totalCapacityFam) ?> Total Family Capacity</div>
  </div>
</div>

<!-- Interactive Leaflet Overview Map -->
<div class="card" id="overviewMapCard" style="margin-bottom:var(--space-4);">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div>
      <h3 class="card-title" style="display:flex;align-items:center;gap:6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-secondary)" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
        Barangay <?= clean($brgyName) ?> Evacuation Geolocation Map
      </h3>
      <div class="card-subtitle">Real-time geospatial layout of designated shelters with status pins and live occupancy.</div>
    </div>
    <button type="button" class="btn btn-outline btn-sm" onclick="fitAllOverviewMarkers()">
      🎯 Reset Map Zoom
    </button>
  </div>
  <div class="card-body" style="padding:12px;">
    <div id="barangayEvacOverviewMap" class="evac-map-container"></div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:10px;padding-top:8px;border-top:1px solid var(--color-border-light);">
      <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#43A047;"></span> <strong>Standby</strong> (Ready)</span>
      <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#E53935;"></span> <strong>Open / Active</strong> (Sheltering)</span>
      <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#FB8C00;"></span> <strong>At Capacity</strong> (Full)</span>
      <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#78909C;"></span> <strong>Closed</strong></span>
      <span style="margin-left:auto;color:var(--color-text-muted);font-style:italic;">Click on any marker to inspect camp stats or perform fast updates.</span>
    </div>
  </div>
</div>

<!-- Filters & Search Toolbar -->
<div class="card" style="margin-bottom:var(--space-4);padding:14px;">
  <form method="GET" action="" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
      <input type="text" name="search" class="form-control" placeholder="Search by shelter name, address, or camp manager..." value="<?= clean($search) ?>">
    </div>
    <div style="min-width:160px;">
      <select name="status" class="form-control" onchange="this.form.submit()">
        <option value="">All Operational Statuses</option>
        <option value="Open / Active" <?= $filterStatus === 'Open / Active' ? 'selected' : '' ?>>Open / Active</option>
        <option value="Standby" <?= $filterStatus === 'Standby' ? 'selected' : '' ?>>Standby</option>
        <option value="At Capacity" <?= $filterStatus === 'At Capacity' ? 'selected' : '' ?>>At Capacity</option>
        <option value="Closed" <?= $filterStatus === 'Closed' ? 'selected' : '' ?>>Closed</option>
      </select>
    </div>
    <div style="min-width:170px;">
      <select name="type" class="form-control" onchange="this.form.submit()">
        <option value="">All Facility Types</option>
        <option value="School Gym" <?= $filterType === 'School Gym' ? 'selected' : '' ?>>School Gym</option>
        <option value="Barangay Multi-Purpose Hall" <?= $filterType === 'Barangay Multi-Purpose Hall' ? 'selected' : '' ?>>Barangay Multi-Purpose Hall</option>
        <option value="Civic Center" <?= $filterType === 'Civic Center' ? 'selected' : '' ?>>Civic Center</option>
        <option value="Church / Chapel" <?= $filterType === 'Church / Chapel' ? 'selected' : '' ?>>Church / Chapel</option>
        <option value="Designated Open Ground" <?= $filterType === 'Designated Open Ground' ? 'selected' : '' ?>>Designated Open Ground</option>
      </select>
    </div>
    <button type="submit" class="btn btn-outline">Filter</button>
    <?php if (!empty($search) || !empty($filterStatus) || !empty($filterType)): ?>
      <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="btn btn-outline" style="color:var(--color-danger);">Reset</a>
    <?php endif; ?>
  </form>
</div>

<!-- Shelters Cards Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(360px, 1fr));gap:var(--space-4);">
  <?php if (empty($centers)): ?>
    <div class="card" style="grid-column:1/-1;">
      <div class="empty-state">
        <div class="empty-state-title">No Evacuation Centers Found</div>
        <div class="empty-state-desc">
          <?= (!empty($search) || !empty($filterStatus) || !empty($filterType)) 
              ? 'No evacuation centers match your filter criteria.' 
              : 'There are no designated evacuation shelters currently registered under Barangay ' . clean($brgyName) . '.' ?>
        </div>
        <button type="button" class="btn btn-primary" onclick="openCreateEvacModal()" style="margin-top:12px;">
          Register First Shelter
        </button>
      </div>
    </div>
  <?php else: ?>
    <?php foreach ($centers as $center): ?>
      <?php
        $pct = $center['capacity_individuals'] > 0 ? min(100, round(($center['current_evacuees_count'] / $center['capacity_individuals']) * 100, 1)) : 0;
        $barColor = $pct >= 90 ? 'var(--color-danger)' : ($pct >= 60 ? 'var(--color-warning)' : 'var(--color-secondary)');
      ?>
      <div class="evac-card" id="center-card-<?= (int)$center['id'] ?>">
        <div>
          <!-- Header -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
            <div>
              <span style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">
                <?= clean($center['center_type']) ?>
              </span>
              <h3 style="margin:2px 0 0 0;font-size:14px;color:var(--color-primary);font-weight:700;">
                <?= clean($center['name']) ?>
              </h3>
            </div>
            <div><?= renderStatusBadge($center['status']) ?></div>
          </div>

          <!-- Address & Coordinates Pin -->
          <div style="font-size:11px;color:var(--color-text-secondary);margin-bottom:8px;line-height:1.4;">
            📍 <?= clean($center['location_address']) ?>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;background:var(--color-surface-subtle);padding:4px 8px;border-radius:6px;font-size:10px;font-family:var(--font-secondary);color:var(--color-text-muted);margin-bottom:12px;">
            <span>GPS: <strong><?= number_format($center['coordinates_lat'], 6) ?>, <?= number_format($center['coordinates_lng'], 6) ?></strong></span>
            <button type="button" class="btn btn-outline btn-sm" style="padding:2px 6px;font-size:9px;" onclick="focusOnMap(<?= (float)$center['coordinates_lat'] ?>, <?= (float)$center['coordinates_lng'] ?>, <?= (int)$center['id'] ?>)">
              📍 Locate
            </button>
          </div>

          <!-- Occupancy Bar -->
          <div style="margin-bottom:12px;background:var(--color-surface-subtle);padding:8px 10px;border-radius:8px;border:1px solid var(--color-border-light);">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:10px;margin-bottom:4px;">
              <span style="font-weight:600;color:var(--color-primary);">Current Occupancy</span>
              <span style="font-family:var(--font-secondary);font-weight:700;color:<?= $barColor ?>;">
                <?= number_format($center['current_evacuees_count']) ?> / <?= number_format($center['capacity_individuals']) ?> (<?= $pct ?>%)
              </span>
            </div>
            <div style="width:100%;height:6px;background:#D9E0E3;border-radius:3px;overflow:hidden;">
              <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;transition:width 0.3s ease;"></div>
            </div>
            <div style="font-size:8px;color:var(--color-text-muted);margin-top:4px;">
              Family Capacity: ~<?= number_format($center['capacity_families']) ?> Families (NDRRMC shelter standard)
            </div>
          </div>

          <!-- Road Accessibility -->
          <div style="font-size:10px;margin-bottom:8px;">
            <span style="font-weight:600;color:var(--color-text);">Road Accessibility:</span>
            <span style="color:var(--color-secondary);font-weight:600;"> <?= clean($center['accessibility']) ?></span>
          </div>

          <!-- Facilities -->
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
            <span class="badge <?= $center['has_potable_water'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_potable_water'] ? '✓ Potable Water' : 'No Water' ?>
            </span>
            <span class="badge <?= $center['has_electricity'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_electricity'] ? '✓ Backup Power' : 'No Power' ?>
            </span>
            <span class="badge <?= $center['has_medical_station'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_medical_station'] ? '✓ Medical Bay' : 'No Medic' ?>
            </span>
          </div>

          <!-- Camp Manager -->
          <div style="font-size:10px;color:var(--color-text-muted);border-top:1px solid var(--color-border-light);padding-top:8px;margin-bottom:12px;">
            Camp Manager: <strong><?= clean($center['contact_officer']) ?: 'Unassigned' ?></strong> 
            <?= !empty($center['contact_number']) ? ' &bull; ' . clean($center['contact_number']) : '' ?>
          </div>
        </div>

        <!-- Card Action Toolbar (Full CRUD) -->
        <div style="display:flex;gap:6px;border-top:1px solid var(--color-border-light);padding-top:10px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline btn-sm" onclick="openUpdateOccupancyModal(<?= htmlspecialchars(json_encode($center), ENT_QUOTES, 'UTF-8') ?>)" title="Update sheltered evacuees and status">
            📊 Headcount
          </button>
          <button type="button" class="btn btn-outline btn-sm" onclick="openEditEvacModal(<?= htmlspecialchars(json_encode($center), ENT_QUOTES, 'UTF-8') ?>)" title="Edit center details and GPS pinpoint">
            ✏️ Edit
          </button>
          <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-danger);border-color:#F8B4B4;" onclick="openDeleteEvacModal(<?= (int)$center['id'] ?>, '<?= clean(addslashes($center['name'])) ?>')" title="Delete center">
            🗑️ Delete
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: REGISTER NEW EVACUATION AREA WITH LEAFLET PINPOINTING TOOL      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createEvacModal">
  <div class="modal-dialog modal-dialog-lg" style="max-width:720px;width:95%;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title">Register Designated Evacuation Shelter</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Register an official safe sanctuary under Barangay <?= clean($brgyName) ?> with automated Leaflet GPS capture.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('createEvacModal')">&times;</button>
    </div>
    <form id="createEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;max-height:75vh;overflow-y:auto;padding:18px 20px;">
        <!-- Basic Info -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Evacuation Shelter / Facility Name</label>
          <input type="text" name="name" id="create_name" class="form-control" placeholder="e.g. Hinaplanon National High School Gymnasium" required>
        </div>

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Facility Type</label>
            <select name="center_type" class="form-control" required>
              <option value="School Gym">School Gym</option>
              <option value="Barangay Multi-Purpose Hall">Barangay Multi-Purpose Hall</option>
              <option value="Civic Center">Civic Center</option>
              <option value="Church / Chapel">Church / Chapel</option>
              <option value="Designated Open Ground">Designated Open Ground</option>
              <option value="Covered Court">Covered Court</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Initial Status</label>
            <select name="status" class="form-control" required>
              <option value="Standby" selected>Standby (Ready to Shelter)</option>
              <option value="Open / Active">Open / Active (Currently Sheltering)</option>
              <option value="Closed">Closed</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Location Address / Street / Landmark</label>
          <input type="text" name="location_address" id="create_address" class="form-control" placeholder="e.g. Purok 3 Highway, near Hinaplanon Bridge" required>
        </div>

        <!-- Leaflet Geolocation Pinpointing Tool -->
        <div style="margin-top:4px;">
          <label class="form-label form-label-required" style="margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
            <span>📍 Pinpoint Exact Geolocation on Map</span>
            <button type="button" class="btn btn-outline btn-sm" style="font-size:10px;padding:2px 8px;" onclick="recenterCreateMapToBarangay()">
              🎯 Center on Barangay
            </button>
          </label>
          
          <div class="map-pin-hint">
            <span><strong>Click anywhere on the map or drag the marker</strong> to accurately pinpoint the evacuation center. Latitude & Longitude are captured automatically.</span>
          </div>

          <div id="createEvacMap" class="modal-map-picker"></div>

          <div class="form-row" style="margin-top:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Captured Latitude</label>
              <input type="number" step="0.000001" name="coordinates_lat" id="create_lat" class="form-control" value="<?= $brgyLat ?>" required onchange="manualCreateCoordInput()">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Captured Longitude</label>
              <input type="number" step="0.000001" name="coordinates_lng" id="create_lng" class="form-control" value="<?= $brgyLng ?>" required onchange="manualCreateCoordInput()">
            </div>
          </div>
        </div>

        <!-- Capacities & Accessibility -->
        <div class="form-row" style="margin-top:4px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Capacity (Individuals)</label>
            <input type="number" name="capacity_individuals" id="create_cap_ind" class="form-control" min="10" value="800" required oninput="calcCreateFamilyCap()">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Capacity (Families)</label>
            <input type="number" name="capacity_families" id="create_cap_fam" class="form-control" min="2" value="180" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Road Accessibility</label>
            <select name="accessibility" class="form-control" required>
              <option value="Accessible (All Vehicles)">Accessible (All Vehicles)</option>
              <option value="High-Clearance / 4x4 Only">High-Clearance / 4x4 Only</option>
              <option value="Foot Access Only">Foot Access Only</option>
              <option value="Temporarily Inaccessible">Temporarily Inaccessible</option>
            </select>
          </div>
        </div>

        <!-- Officer & Contacts -->
        <div class="form-row" style="margin-top:4px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Camp Manager / Focal Officer</label>
            <input type="text" name="contact_officer" class="form-control" placeholder="e.g. Brgy. Kagawad / Camp Leader">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Contact Emergency Hotline</label>
            <input type="text" name="contact_number" class="form-control" placeholder="e.g. +63 9XX XXX XXXX">
          </div>
        </div>

        <!-- Facilities -->
        <div class="form-group" style="margin-bottom:0;margin-top:4px;">
          <label class="form-label">Available Camp Facilities & Amenities</label>
          <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap;">
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_potable_water" value="1" checked> Potable Clean Water
            </label>
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_electricity" value="1" checked> Backup Generator / Power
            </label>
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_medical_station" value="1" checked> Medical First Aid Station
            </label>
          </div>
        </div>
      </div>

      <div class="modal-footer" style="padding:14px 20px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('createEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitCreateEvac">Register Evacuation Area</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: EDIT EVACUATION AREA WITH LEAFLET PINPOINTING TOOL               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editEvacModal">
  <div class="modal-dialog modal-dialog-lg" style="max-width:720px;width:95%;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="editEvacModalTitle">Edit Evacuation Shelter Details</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Update center capacity, facilities, or adjust the pinned geospatial location.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('editEvacModal')">&times;</button>
    </div>
    <form id="editEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;max-height:75vh;overflow-y:auto;padding:18px 20px;">
        <!-- Basic Info -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Evacuation Shelter / Facility Name</label>
          <input type="text" name="name" id="edit_name" class="form-control" required>
        </div>

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Facility Type</label>
            <select name="center_type" id="edit_center_type" class="form-control" required>
              <option value="School Gym">School Gym</option>
              <option value="Barangay Multi-Purpose Hall">Barangay Multi-Purpose Hall</option>
              <option value="Civic Center">Civic Center</option>
              <option value="Church / Chapel">Church / Chapel</option>
              <option value="Designated Open Ground">Designated Open Ground</option>
              <option value="Covered Court">Covered Court</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Operational Status</label>
            <select name="status" id="edit_status" class="form-control" required>
              <option value="Standby">Standby (Ready to Shelter)</option>
              <option value="Open / Active">Open / Active (Currently Sheltering)</option>
              <option value="At Capacity">At Capacity (Full)</option>
              <option value="Closed">Closed</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Location Address / Street / Landmark</label>
          <input type="text" name="location_address" id="edit_location_address" class="form-control" required>
        </div>

        <!-- Leaflet Geolocation Pinpointing Tool -->
        <div style="margin-top:4px;">
          <label class="form-label form-label-required" style="margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
            <span>📍 Pinpoint Exact Geolocation on Map</span>
            <button type="button" class="btn btn-outline btn-sm" style="font-size:10px;padding:2px 8px;" onclick="recenterEditMapToShelter()">
              🎯 Re-Center to Current Pin
            </button>
          </label>
          
          <div class="map-pin-hint">
            <span><strong>Click anywhere on the map or drag the marker</strong> to reposition the exact pin. Latitude & Longitude are updated automatically.</span>
          </div>

          <div id="editEvacMap" class="modal-map-picker"></div>

          <div class="form-row" style="margin-top:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Captured Latitude</label>
              <input type="number" step="0.000001" name="coordinates_lat" id="edit_lat" class="form-control" required onchange="manualEditCoordInput()">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Captured Longitude</label>
              <input type="number" step="0.000001" name="coordinates_lng" id="edit_lng" class="form-control" required onchange="manualEditCoordInput()">
            </div>
          </div>
        </div>

        <!-- Capacities & Occupancy -->
        <div class="form-row" style="margin-top:4px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Capacity (Individuals)</label>
            <input type="number" name="capacity_individuals" id="edit_capacity_individuals" class="form-control" min="10" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Capacity (Families)</label>
            <input type="number" name="capacity_families" id="edit_capacity_families" class="form-control" min="2" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Current Sheltered Evacuees</label>
            <input type="number" name="current_evacuees_count" id="edit_current_evacuees_count" class="form-control" min="0" required>
          </div>
        </div>

        <!-- Accessibility -->
        <div class="form-group" style="margin-bottom:0;margin-top:4px;">
          <label class="form-label form-label-required">Road / Route Accessibility</label>
          <select name="accessibility" id="edit_accessibility" class="form-control" required>
            <option value="Accessible (All Vehicles)">Accessible (All Vehicles)</option>
            <option value="High-Clearance / 4x4 Only">High-Clearance / 4x4 Only</option>
            <option value="Foot Access Only">Foot Access Only</option>
            <option value="Temporarily Inaccessible">Temporarily Inaccessible</option>
          </select>
        </div>

        <!-- Officer & Contacts -->
        <div class="form-row" style="margin-top:4px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Camp Manager / Focal Officer</label>
            <input type="text" name="contact_officer" id="edit_contact_officer" class="form-control">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Contact Emergency Hotline</label>
            <input type="text" name="contact_number" id="edit_contact_number" class="form-control">
          </div>
        </div>

        <!-- Facilities -->
        <div class="form-group" style="margin-bottom:0;margin-top:4px;">
          <label class="form-label">Available Camp Facilities & Amenities</label>
          <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap;">
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_potable_water" id="edit_has_potable_water" value="1"> Potable Clean Water
            </label>
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_electricity" id="edit_has_electricity" value="1"> Backup Generator / Power
            </label>
            <label style="font-size:11px;display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="has_medical_station" id="edit_has_medical_station" value="1"> Medical First Aid Station
            </label>
          </div>
        </div>
      </div>

      <div class="modal-footer" style="padding:14px 20px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('editEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitEditEvac">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: QUICK UPDATE OCCUPANCY & ROAD ACCESSIBILITY                      -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="updateOccupancyModal">
  <div class="modal-dialog" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="occupancyModalTitle">Update Camp Headcount</h3>
        <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
          Quickly record live sheltered evacuee figures and operational state.
        </p>
      </div>
      <button class="modal-close-btn" onclick="closeModal('updateOccupancyModal')">&times;</button>
    </div>
    <form id="updateOccupancyForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/update_status.php">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="center_id" id="occupancy_center_id">
      
      <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;padding:18px 20px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Operational Status</label>
          <select name="status" id="occupancy_status" class="form-control" required>
            <option value="Open / Active">Open / Active (Sheltering Evacuees)</option>
            <option value="Standby">Standby (Ready to Receive)</option>
            <option value="At Capacity">At Capacity (Camp Full)</option>
            <option value="Closed">Closed</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Current Sheltered Evacuees (Individuals)</label>
          <input type="number" name="evacuees_count" id="occupancy_count" class="form-control" min="0" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Road / Route Accessibility</label>
          <select name="accessibility" id="occupancy_accessibility" class="form-control" required>
            <option value="Accessible (All Vehicles)">Accessible (All Vehicles)</option>
            <option value="High-Clearance / 4x4 Only">High-Clearance / 4x4 Only</option>
            <option value="Foot Access Only">Foot Access Only</option>
            <option value="Temporarily Inaccessible">Temporarily Inaccessible</option>
          </select>
        </div>
      </div>

      <div class="modal-footer" style="padding:14px 20px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('updateOccupancyModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Headcount</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: DELETE CONFIRMATION MODAL                                        -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deleteEvacModal">
  <div class="modal-dialog" style="max-width:440px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" style="color:var(--color-danger);">Confirm Shelter Deletion</h3>
      <button class="modal-close-btn" onclick="closeModal('deleteEvacModal')">&times;</button>
    </div>
    <form id="deleteEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/delete.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delete_center_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="modal-body" style="padding:18px 20px;">
        <p style="font-size:12px;color:var(--color-text);line-height:1.5;margin:0;">
          Are you sure you want to permanently delete evacuation center <strong id="delete_center_name" style="color:var(--color-primary);"></strong>?
        </p>
        <p style="font-size:11px;color:var(--color-danger);margin:8px 0 0 0;">
          ⚠️ This will remove the facility from disaster response rosters and delete associated resource allocations. This action cannot be undone.
        </p>
      </div>

      <div class="modal-footer" style="padding:14px 20px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('deleteEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:#C62828;border-color:#C62828;">Yes, Delete Shelter</button>
      </div>
    </form>
  </div>
</div>

<script>
// Data from server
const BRGY_NAME = <?= json_encode($brgyName) ?>;
const BRGY_CENTER_LAT = <?= $brgyLat ?>;
const BRGY_CENTER_LNG = <?= $brgyLng ?>;
const EVAC_CENTERS = <?= json_encode($centers) ?>;

// Leaflet Instances
let overviewMap = null;
let overviewMarkers = {};
let createMap = null;
let createMarker = null;
let editMap = null;
let editMarker = null;

// Standard Leaflet Pin Icon
const pinIcon = L.icon({
  iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
  iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
  shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41]
});

// Status-colored Pin Maker
function getStatusPinIcon(status) {
  let pinClass = 'status-pin-standby';
  let symbol = '🏠';

  if (status === 'Open / Active') {
    pinClass = 'status-pin-active';
    symbol = '🚨';
  } else if (status === 'At Capacity') {
    pinClass = 'status-pin-capacity';
    symbol = '⚠️';
  } else if (status === 'Closed') {
    pinClass = 'status-pin-closed';
    symbol = '✖';
  }

  return L.divIcon({
    className: 'custom-div-icon',
    html: `<div class="custom-marker-pin ${pinClass}"><span>${symbol}</span></div>`,
    iconSize: [32, 32],
    iconAnchor: [16, 32],
    popupAnchor: [0, -30]
  });
}

// 1. Initialize Overview Map
document.addEventListener('DOMContentLoaded', () => {
  initOverviewMap();
});

function initOverviewMap() {
  const container = document.getElementById('barangayEvacOverviewMap');
  if (!container) return;

  overviewMap = L.map('barangayEvacOverviewMap', {
    center: [BRGY_CENTER_LAT, BRGY_CENTER_LNG],
    zoom: 14,
    zoomControl: true
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(overviewMap);

  const group = L.featureGroup();

  EVAC_CENTERS.forEach(c => {
    const lat = parseFloat(c.coordinates_lat);
    const lng = parseFloat(c.coordinates_lng);
    if (isNaN(lat) || isNaN(lng)) return;

    const marker = L.marker([lat, lng], {
      icon: getStatusPinIcon(c.status)
    }).addTo(overviewMap);

    const pct = c.capacity_individuals > 0 ? Math.min(100, Math.round((c.current_evacuees_count / c.capacity_individuals) * 100)) : 0;
    const barCol = pct >= 90 ? '#E53935' : (pct >= 60 ? '#FB8C00' : '#2F6F73');

    const popupHtml = `
      <div style="min-width:240px;font-family:var(--font-primary, sans-serif);line-height:1.4;">
        <div style="font-size:9px;text-transform:uppercase;color:#64748B;font-weight:700;">${escapeHtml(c.center_type || 'Shelter')}</div>
        <div style="font-size:13px;font-weight:700;color:#17324D;margin:2px 0 6px 0;">${escapeHtml(c.name)}</div>
        <div style="font-size:11px;color:#475569;margin-bottom:6px;">📍 ${escapeHtml(c.location_address)}</div>
        <div style="margin-bottom:8px;background:#F1F5F9;padding:6px 8px;border-radius:6px;">
          <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:600;">
            <span>Occupancy:</span>
            <span style="color:${barCol};">${c.current_evacuees_count} / ${c.capacity_individuals} (${pct}%)</span>
          </div>
          <div style="width:100%;height:5px;background:#CBD5E1;border-radius:3px;overflow:hidden;margin-top:3px;">
            <div style="width:${pct}%;height:100%;background:${barCol};"></div>
          </div>
        </div>
        <div style="font-size:10px;color:#64748B;margin-bottom:8px;">
          Camp Officer: <strong>${escapeHtml(c.contact_officer || 'Unassigned')}</strong> (${escapeHtml(c.contact_number || 'N/A')})
        </div>
        <div style="display:flex;gap:4px;border-top:1px solid #E2E8F0;padding-top:6px;">
          <button type="button" style="flex:1;padding:4px 6px;font-size:10px;background:#17324D;color:#FFF;border:none;border-radius:4px;cursor:pointer;" onclick="openEditFromMap(${c.id})">
            Edit
          </button>
          <button type="button" style="flex:1;padding:4px 6px;font-size:10px;background:#2F6F73;color:#FFF;border:none;border-radius:4px;cursor:pointer;" onclick="openOccupancyFromMap(${c.id})">
            Headcount
          </button>
        </div>
      </div>
    `;

    marker.bindPopup(popupHtml);
    overviewMarkers[c.id] = marker;
    group.addLayer(marker);
  });

  if (EVAC_CENTERS.length > 0) {
    try {
      overviewMap.fitBounds(group.getBounds().pad(0.2));
    } catch (e) {
      overviewMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 14);
    }
  }
}

function scrollToOverviewMap() {
  const card = document.getElementById('overviewMapCard');
  if (card) {
    card.scrollIntoView({ behavior: 'smooth' });
    setTimeout(() => {
      if (overviewMap) overviewMap.invalidateSize();
    }, 400);
  }
}

function fitAllOverviewMarkers() {
  if (!overviewMap) return;
  overviewMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 14);
}

function focusOnMap(lat, lng, centerId) {
  scrollToOverviewMap();
  setTimeout(() => {
    if (overviewMap) {
      overviewMap.setView([lat, lng], 17, { animate: true });
      if (overviewMarkers[centerId]) {
        overviewMarkers[centerId].openPopup();
      }
    }
  }, 450);
}

function openEditFromMap(centerId) {
  const c = EVAC_CENTERS.find(item => parseInt(item.id) === parseInt(centerId));
  if (c) openEditEvacModal(c);
}

function openOccupancyFromMap(centerId) {
  const c = EVAC_CENTERS.find(item => parseInt(item.id) === parseInt(centerId));
  if (c) openUpdateOccupancyModal(c);
}

// =========================================================================
// 2. CREATE MODAL & LEAFLET PINPOINTING TOOL
// =========================================================================
function openCreateEvacModal() {
  document.getElementById('createEvacForm').reset();
  document.getElementById('create_lat').value = BRGY_CENTER_LAT.toFixed(6);
  document.getElementById('create_lng').value = BRGY_CENTER_LNG.toFixed(6);
  calcCreateFamilyCap();

  openModal('createEvacModal');

  setTimeout(() => {
    initCreateLeafletMap(BRGY_CENTER_LAT, BRGY_CENTER_LNG);
  }, 220);
}

function initCreateLeafletMap(lat, lng) {
  const container = document.getElementById('createEvacMap');
  if (!container) return;

  if (!createMap) {
    createMap = L.map('createEvacMap', {
      center: [lat, lng],
      zoom: 15,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(createMap);

    createMarker = L.marker([lat, lng], {
      draggable: true,
      icon: pinIcon
    }).addTo(createMap);

    createMarker.bindPopup(`<strong>Pinpoint Shelter Location</strong><br><span style="font-size:10px;color:#64748B;">Drag marker or click anywhere</span>`).openPopup();

    // Event: Marker dragged
    createMarker.on('dragend', function(e) {
      const pos = e.target.getLatLng();
      updateCreateCoords(pos.lat, pos.lng);
    });

    // Event: Map clicked
    createMap.on('click', function(e) {
      const pos = e.latlng;
      createMarker.setLatLng(pos);
      updateCreateCoords(pos.lat, pos.lng);
    });
  } else {
    createMarker.setLatLng([lat, lng]);
    createMap.setView([lat, lng], 15);
    createMap.invalidateSize();
    createMarker.openPopup();
  }
}

function updateCreateCoords(lat, lng) {
  document.getElementById('create_lat').value = parseFloat(lat).toFixed(6);
  document.getElementById('create_lng').value = parseFloat(lng).toFixed(6);
  if (createMarker) {
    createMarker.getPopup().setContent(`<strong>Selected Pinpoint</strong><br><span style="font-size:10px;color:#64748B;">${lat.toFixed(6)}, ${lng.toFixed(6)}</span>`);
  }
}

function manualCreateCoordInput() {
  const lat = parseFloat(document.getElementById('create_lat').value);
  const lng = parseFloat(document.getElementById('create_lng').value);
  if (!isNaN(lat) && !isNaN(lng) && createMap && createMarker) {
    createMarker.setLatLng([lat, lng]);
    createMap.panTo([lat, lng]);
  }
}

function recenterCreateMapToBarangay() {
  if (createMap && createMarker) {
    createMarker.setLatLng([BRGY_CENTER_LAT, BRGY_CENTER_LNG]);
    createMap.setView([BRGY_CENTER_LAT, BRGY_CENTER_LNG], 15);
    updateCreateCoords(BRGY_CENTER_LAT, BRGY_CENTER_LNG);
    createMarker.openPopup();
  }
}

function calcCreateFamilyCap() {
  const ind = parseInt(document.getElementById('create_cap_ind').value, 10) || 0;
  if (ind > 0) {
    document.getElementById('create_cap_fam').value = Math.max(2, Math.round(ind / 4.5));
  }
}

// =========================================================================
// 3. EDIT MODAL & LEAFLET PINPOINTING TOOL
// =========================================================================
function openEditEvacModal(center) {
  document.getElementById('edit_id').value = center.id;
  document.getElementById('edit_name').value = center.name;
  document.getElementById('edit_center_type').value = center.center_type;
  document.getElementById('edit_status').value = center.status;
  document.getElementById('edit_location_address').value = center.location_address;
  document.getElementById('edit_capacity_individuals').value = center.capacity_individuals;
  document.getElementById('edit_capacity_families').value = center.capacity_families;
  document.getElementById('edit_current_evacuees_count').value = center.current_evacuees_count;
  document.getElementById('edit_accessibility').value = center.accessibility;
  document.getElementById('edit_contact_officer').value = center.contact_officer || '';
  document.getElementById('edit_contact_number').value = center.contact_number || '';

  document.getElementById('edit_has_potable_water').checked = parseInt(center.has_potable_water) === 1;
  document.getElementById('edit_has_electricity').checked = parseInt(center.has_electricity) === 1;
  document.getElementById('edit_has_medical_station').checked = parseInt(center.has_medical_station) === 1;

  const lat = parseFloat(center.coordinates_lat) || BRGY_CENTER_LAT;
  const lng = parseFloat(center.coordinates_lng) || BRGY_CENTER_LNG;

  document.getElementById('edit_lat').value = lat.toFixed(6);
  document.getElementById('edit_lng').value = lng.toFixed(6);

  document.getElementById('editEvacModalTitle').innerText = `Edit ${center.name}`;

  openModal('editEvacModal');

  setTimeout(() => {
    initEditLeafletMap(lat, lng, center.name);
  }, 220);
}

function initEditLeafletMap(lat, lng, centerName) {
  const container = document.getElementById('editEvacMap');
  if (!container) return;

  if (!editMap) {
    editMap = L.map('editEvacMap', {
      center: [lat, lng],
      zoom: 16,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(editMap);

    editMarker = L.marker([lat, lng], {
      draggable: true,
      icon: pinIcon
    }).addTo(editMap);

    editMarker.bindPopup(`<strong>${escapeHtml(centerName || 'Evacuation Shelter')}</strong><br><span style="font-size:10px;color:#64748B;">Drag or click to adjust location</span>`).openPopup();

    editMarker.on('dragend', function(e) {
      const pos = e.target.getLatLng();
      updateEditCoords(pos.lat, pos.lng);
    });

    editMap.on('click', function(e) {
      const pos = e.latlng;
      editMarker.setLatLng(pos);
      updateEditCoords(pos.lat, pos.lng);
    });
  } else {
    editMarker.setLatLng([lat, lng]);
    editMap.setView([lat, lng], 16);
    editMap.invalidateSize();
    editMarker.getPopup().setContent(`<strong>${escapeHtml(centerName || 'Evacuation Shelter')}</strong><br><span style="font-size:10px;color:#64748B;">Drag or click to adjust location</span>`);
    editMarker.openPopup();
  }
}

function updateEditCoords(lat, lng) {
  document.getElementById('edit_lat').value = parseFloat(lat).toFixed(6);
  document.getElementById('edit_lng').value = parseFloat(lng).toFixed(6);
  if (editMarker) {
    editMarker.getPopup().setContent(`<strong>Repositioned Pinpoint</strong><br><span style="font-size:10px;color:#64748B;">${lat.toFixed(6)}, ${lng.toFixed(6)}</span>`);
  }
}

function manualEditCoordInput() {
  const lat = parseFloat(document.getElementById('edit_lat').value);
  const lng = parseFloat(document.getElementById('edit_lng').value);
  if (!isNaN(lat) && !isNaN(lng) && editMap && editMarker) {
    editMarker.setLatLng([lat, lng]);
    editMap.panTo([lat, lng]);
  }
}

function recenterEditMapToShelter() {
  const lat = parseFloat(document.getElementById('edit_lat').value);
  const lng = parseFloat(document.getElementById('edit_lng').value);
  if (!isNaN(lat) && !isNaN(lng) && editMap && editMarker) {
    editMarker.setLatLng([lat, lng]);
    editMap.setView([lat, lng], 16);
    editMarker.openPopup();
  }
}

// =========================================================================
// 4. OCCUPANCY & DELETE MODALS
// =========================================================================
function openUpdateOccupancyModal(center) {
  document.getElementById('occupancy_center_id').value = center.id;
  document.getElementById('occupancyModalTitle').innerText = `Update ${center.name} Headcount`;
  document.getElementById('occupancy_status').value = center.status;
  document.getElementById('occupancy_count').value = center.current_evacuees_count;
  document.getElementById('occupancy_accessibility').value = center.accessibility;
  openModal('updateOccupancyModal');
}

function openDeleteEvacModal(centerId, centerName) {
  document.getElementById('delete_center_id').value = centerId;
  document.getElementById('delete_center_name').innerText = centerName;
  openModal('deleteEvacModal');
}

// Utility
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
