<?php
// ============================================================================
// Views (ICDRRMO): Evacuation Centers Management & Public Directory
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Evacuation Centers Directory";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Filters
$filterBarangay = $_GET['barangay'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';

$sql = "
    SELECT ea.*, b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id 
    WHERE 1=1
";
$params = [];

if (!empty($filterBarangay)) {
    $sql .= " AND ea.barangay_id = ?";
    $params[] = $filterBarangay;
}

if (!empty($filterStatus)) {
    $sql .= " AND ea.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterType)) {
    $sql .= " AND ea.center_type = ?";
    $params[] = $filterType;
}

$sql .= " ORDER BY FIELD(ea.status, 'Open / Active', 'At Capacity', 'Standby', 'Closed'), ea.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$centers = $stmt->fetchAll();

// Aggregates
$totalCenters = count($centers);
$activeCenters = 0;
$totalCapacity = 0;
$totalEvacuees = 0;

foreach ($centers as $c) {
    if ($c['status'] === 'Open / Active' || $c['status'] === 'At Capacity') $activeCenters++;
    $totalCapacity += (int)$c['capacity_individuals'];
    $totalEvacuees += (int)$c['current_evacuees_count'];
}
$occupancyRate = $totalCapacity > 0 ? round(($totalEvacuees / $totalCapacity) * 100, 1) : 0;

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Evacuation Centers Management & Public Directory</h1>
      <p class="page-header-desc">
        Monitor live occupancy, accessibility conditions, camp sanitation facilities, and emergency contact personnel.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openModal('createEvacModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Register New Center
      </button>
    </div>
  </div>

  <!-- Overview Metrics -->
  <div class="metrics-grid">
    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Designated Centers</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
      </div>
      <div class="metric-value"><?= number_format($totalCenters) ?></div>
      <div class="metric-meta">Verified Safe Sanctuaries</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Currently Open Camps</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle></svg>
      </div>
      <div class="metric-value" style="color:var(--color-danger);"><?= number_format($activeCenters) ?></div>
      <div class="metric-meta">Sheltering Active Evacuees</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Sheltered Evacuees</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
      </div>
      <div class="metric-value"><?= number_format($totalEvacuees) ?></div>
      <div class="metric-meta">Of <?= number_format($totalCapacity) ?> Total Individual Cap.</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Overall City Occupancy</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
      </div>
      <div class="metric-value" style="color:var(--color-primary);"><?= $occupancyRate ?>%</div>
      <div class="metric-meta">Safe Buffer Available</div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <div class="filter-group">
          <select name="barangay" class="form-control" style="width:140px;">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="status" class="form-control" style="width:140px;">
            <option value="">All Statuses</option>
            <option value="Open / Active" <?= $filterStatus === 'Open / Active' ? 'selected' : '' ?>>Open / Active</option>
            <option value="Standby" <?= $filterStatus === 'Standby' ? 'selected' : '' ?>>Standby</option>
            <option value="At Capacity" <?= $filterStatus === 'At Capacity' ? 'selected' : '' ?>>At Capacity</option>
            <option value="Closed" <?= $filterStatus === 'Closed' ? 'selected' : '' ?>>Closed</option>
          </select>

          <select name="type" class="form-control" style="width:160px;">
            <option value="">All Facility Types</option>
            <option value="School Gym" <?= $filterType === 'School Gym' ? 'selected' : '' ?>>School Gym</option>
            <option value="Barangay Multi-Purpose Hall" <?= $filterType === 'Barangay Multi-Purpose Hall' ? 'selected' : '' ?>>Barangay Multi-Purpose Hall</option>
            <option value="Civic Center" <?= $filterType === 'Civic Center' ? 'selected' : '' ?>>Civic Center</option>
          </select>

          <button type="submit" class="btn btn-outline">Apply Filter</button>
          <?php if (!empty($filterBarangay) || !empty($filterStatus) || !empty($filterType)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/evacuation.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Evacuation Cards Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(360px, 1fr));gap:var(--space-4);">
    <?php foreach ($centers as $center): ?>
      <?php
        $pct = $center['capacity_individuals'] > 0 ? min(100, round(($center['current_evacuees_count'] / $center['capacity_individuals']) * 100, 1)) : 0;
        $barColor = $pct >= 90 ? 'var(--color-danger)' : ($pct >= 60 ? 'var(--color-warning)' : 'var(--color-secondary)');
      ?>
      <div class="card" style="margin-bottom:0;display:flex;flex-direction:column;justify-content:space-between;">
        <div class="card-header">
          <div>
            <span style="font-size:9px;color:var(--color-text-muted);text-transform:uppercase;font-weight:700;">
              Barangay <?= clean($center['barangay_name']) ?> • <?= clean($center['center_type']) ?>
            </span>
            <h3 class="card-title" style="margin-top:2px;"><?= clean($center['name']) ?></h3>
          </div>
          <div><?= renderStatusBadge($center['status']) ?></div>
        </div>

        <div class="card-body">
          <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:10px;">
            <?= clean($center['location_address']) ?>
          </div>

          <!-- Occupancy Bar -->
          <div style="margin-bottom:12px;background:var(--color-surface-subtle);padding:8px 10px;border-radius:8px;border:1px solid var(--color-border-light);">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:10px;margin-bottom:4px;">
              <span style="font-weight:600;color:var(--color-primary);">Occupancy Rate</span>
              <span style="font-family:var(--font-secondary);font-weight:700;color:<?= $barColor ?>;">
                <?= number_format($center['current_evacuees_count']) ?> / <?= number_format($center['capacity_individuals']) ?> (<?= $pct ?>%)
              </span>
            </div>
            <div style="width:100%;height:6px;background:#D9E0E3;border-radius:3px;overflow:hidden;">
              <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;"></div>
            </div>
            <div style="font-size:8px;color:var(--color-text-muted);margin-top:4px;">
              Capacity: ~<?= number_format($center['capacity_families']) ?> Families (NDRRMC shelter standard)
            </div>
          </div>

          <!-- Accessibility -->
          <div style="font-size:10px;margin-bottom:8px;">
            <span style="font-weight:600;color:var(--color-text);">Road Accessibility:</span>
            <span style="color:var(--color-secondary);font-weight:500;"> <?= clean($center['accessibility']) ?></span>
          </div>

          <!-- Facilities Badges -->
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
            <span class="badge <?= $center['has_potable_water'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_potable_water'] ? '✓ Water Available' : 'No Water' ?>
            </span>
            <span class="badge <?= $center['has_electricity'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_electricity'] ? '✓ Power / Gen' : 'No Power' ?>
            </span>
            <span class="badge <?= $center['has_medical_station'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $center['has_medical_station'] ? '✓ Medical Bay' : 'No Medic' ?>
            </span>
          </div>

          <!-- Contact Officer -->
          <div style="font-size:9px;color:var(--color-text-muted);border-top:1px solid var(--color-border-light);padding-top:8px;">
            Officer in Charge: <strong><?= clean($center['contact_officer']) ?></strong> (<?= clean($center['contact_number']) ?>)
          </div>
        </div>

        <div class="card-footer">
          <span style="font-size:9px;color:var(--color-text-muted);">Coordinates: <?= $center['coordinates_lat'] ?>, <?= $center['coordinates_lng'] ?></span>
          <button class="btn btn-outline btn-sm" onclick="openUpdateEvacModal(<?= htmlspecialchars(json_encode($center)) ?>)">
            Update Occupancy
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</main>

<!-- Modal: Update Center Status & Evacuees -->
<div class="modal-overlay" id="updateEvacModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="updateEvacModalTitle">Update Evacuation Center</h3>
      <button class="modal-close-btn" onclick="closeModal('updateEvacModal')">&times;</button>
    </div>
    <form id="updateEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/update_status.php">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="center_id" id="editCenterId">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Operational Status</label>
          <select name="status" id="editStatusSelect" class="form-control" required>
            <option value="Open / Active">Open / Active (Sheltering Evacuees)</option>
            <option value="Standby">Standby (Ready to Receive)</option>
            <option value="At Capacity">At Capacity (Camp Full)</option>
            <option value="Closed">Closed</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Current Sheltered Evacuees (Individuals)</label>
          <input type="number" name="evacuees_count" id="editEvacCountInput" class="form-control" min="0" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Road / Route Accessibility</label>
          <select name="accessibility" id="editAccessibilitySelect" class="form-control" required>
            <option value="Accessible (All Vehicles)">Accessible (All Vehicles)</option>
            <option value="High-Clearance / 4x4 Only">High-Clearance / 4x4 Only</option>
            <option value="Foot Access Only">Foot Access Only</option>
            <option value="Temporarily Inaccessible">Temporarily Inaccessible</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('updateEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Updates</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Register Evacuation Center -->
<div class="modal-overlay" id="createEvacModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Register Designated Evacuation Shelter</h3>
      <button class="modal-close-btn" onclick="closeModal('createEvacModal')">&times;</button>
    </div>
    <form id="createEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Shelter / Center Name</label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Hinaplanon National High School Gymnasium" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Barangay</label>
            <select name="barangay_id" class="form-control" required>
              <?php foreach ($barangays as $b): ?>
                <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Facility Type</label>
            <select name="center_type" class="form-control" required>
              <option value="School Gym">School Gym</option>
              <option value="Barangay Multi-Purpose Hall">Barangay Multi-Purpose Hall</option>
              <option value="Civic Center">Civic Center</option>
              <option value="Church / Chapel">Church / Chapel</option>
              <option value="Designated Open Ground">Designated Open Ground</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Exact Address / Landmark</label>
          <input type="text" name="location_address" class="form-control" placeholder="Purok or street name" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Capacity (Individuals)</label>
            <input type="number" name="capacity_individuals" class="form-control" min="10" value="800" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Capacity (Families)</label>
            <input type="number" name="capacity_families" class="form-control" min="2" value="180" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Officer in Charge</label>
            <input type="text" name="contact_officer" class="form-control" placeholder="Camp Manager name" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Contact Number</label>
            <input type="text" name="contact_number" class="form-control" placeholder="+63 9XX XXX XXXX" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Available Facilities</label>
          <div style="display:flex;gap:12px;margin-top:4px;">
            <label style="font-size:10px;"><input type="checkbox" name="has_potable_water" value="1" checked> Potable Water</label>
            <label style="font-size:10px;"><input type="checkbox" name="has_electricity" value="1" checked> Backup Power</label>
            <label style="font-size:10px;"><input type="checkbox" name="has_medical_station" value="1" checked> Medical First Aid Station</label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Register Center</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUpdateEvacModal(center) {
  document.getElementById('editCenterId').value = center.id;
  document.getElementById('updateEvacModalTitle').innerText = `Update ${center.name}`;
  document.getElementById('editStatusSelect').value = center.status;
  document.getElementById('editEvacCountInput').value = center.current_evacuees_count;
  document.getElementById('editAccessibilitySelect').value = center.accessibility;
  openModal('updateEvacModal');
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
