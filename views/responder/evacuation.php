<?php
// ============================================================================
// Views (Responder): Evacuation Centers Operational Status & Camp Directory
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$filterBarangay = $_GET['barangay'] ?? '';
$filterStatus = $_GET['status'] ?? '';

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

$sql .= " ORDER BY FIELD(ea.status, 'Open / Active', 'Standby', 'At Capacity', 'Closed'), ea.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$centers = $stmt->fetchAll();

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();

$pageTitle = "Evacuation Centers — Responder Tactical View";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Evacuation Shelters Tactical Directory</h1>
      <p class="page-header-desc">
        Real-time camp occupancy and road passability for routing rescue convoys and displaced citizens.
      </p>
    </div>
  </div>

  <!-- Filters -->
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
          </select>

          <button type="submit" class="btn btn-outline">Filter</button>
          <?php if (!empty($filterBarangay) || !empty($filterStatus)): ?>
            <a href="<?= BASE_URL ?>/views/responder/evacuation.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

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
              Brgy. <?= clean($center['barangay_name']) ?> • <?= clean($center['center_type']) ?>
            </span>
            <h3 class="card-title" style="margin-top:2px;"><?= clean($center['name']) ?></h3>
          </div>
          <div><?= renderStatusBadge($center['status']) ?></div>
        </div>

        <div class="card-body">
          <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:8px;">
            <?= clean($center['location_address']) ?>
          </div>

          <div style="margin-bottom:10px;background:var(--color-surface-subtle);padding:8px 10px;border-radius:8px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
              <span style="font-weight:600;">Camp Headcount</span>
              <span style="font-family:var(--font-secondary);font-weight:700;color:<?= $barColor ?>;">
                <?= $center['current_evacuees_count'] ?> / <?= $center['capacity_individuals'] ?> (<?= $pct ?>%)
              </span>
            </div>
            <div style="width:100%;height:6px;background:#D9E0E3;border-radius:3px;overflow:hidden;">
              <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;"></div>
            </div>
          </div>

          <div style="font-size:10px;margin-bottom:6px;">
            <span style="font-weight:600;">Convoy Access:</span>
            <span style="color:var(--color-secondary);font-weight:600;"><?= clean($center['accessibility']) ?></span>
          </div>

          <div style="font-size:9px;color:var(--color-text-muted);border-top:1px solid var(--color-border-light);padding-top:6px;">
            Camp Officer: <strong><?= clean($center['contact_officer']) ?></strong> (<?= clean($center['contact_number']) ?>)
          </div>
        </div>

        <div class="card-footer">
          <span style="font-size:9px;color:var(--color-text-muted);">GPS: <?= $center['coordinates_lat'] ?>, <?= $center['coordinates_lng'] ?></span>
          <button class="btn btn-outline btn-sm" onclick="openUpdateEvacModal(<?= htmlspecialchars(json_encode($center)) ?>)">
            Update Headcount
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
          <label class="form-label form-label-required">Current Sheltered Evacuees</label>
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
