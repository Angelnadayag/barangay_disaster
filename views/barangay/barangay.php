<?php
// ============================================================================
// Views (Barangay Head): Manage Barangay Module
// Official jurisdiction details, purok subdivisions, and community risk profile
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("
    SELECT b.*,
           (SELECT COUNT(*) FROM puroks p WHERE p.barangay_id = b.id) AS puroks_count,
           (SELECT COUNT(*) FROM evacuation_areas ea WHERE ea.barangay_id = b.id) AS evac_count,
           (SELECT COUNT(*) FROM residents r WHERE r.barangay_id = b.id) AS registered_residents
    FROM barangays b 
    WHERE b.id = ?
");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

if (!$barangay) {
    die("Barangay jurisdiction not found or unassigned.");
}

// Fetch Puroks in this Barangay
$pStmt = $db->prepare("SELECT * FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
$pStmt->execute([$barangayId]);
$puroks = $pStmt->fetchAll();

// Fetch Evacuation Centers in this Barangay
$eStmt = $db->prepare("SELECT * FROM evacuation_areas WHERE barangay_id = ? ORDER BY name ASC");
$eStmt->execute([$barangayId]);
$evacCenters = $eStmt->fetchAll();

$pageTitle = "Manage Barangay — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Manage Barangay — <?= clean($barangay['name']) ?></h1>
      <p class="page-header-desc">
        Official jurisdiction profile, leadership credentials, community puroks, and hazard vulnerability data.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openEditBarangayModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Edit Barangay Details
      </button>
    </div>
  </div>

  <!-- Summary Metric Cards -->
  <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: var(--space-4);">
    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Total Population</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
      </div>
      <div class="metric-value"><?= $barangay['population'] ? number_format($barangay['population']) : '—' ?></div>
      <div class="metric-meta"><?= $barangay['total_households'] ? number_format($barangay['total_households']) . ' Households' : 'No household census' ?></div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Vulnerability Level</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
      </div>
      <div class="metric-value">
        <?php
          $risk = $barangay['risk_level'] ?? 'Moderate';
          $riskColors = ['Critical' => 'var(--color-danger)', 'High' => '#C2410C', 'Moderate' => '#D97706', 'Low' => 'var(--color-success)'];
          $rColor = $riskColors[$risk] ?? 'var(--color-primary)';
        ?>
        <span style="color: <?= $rColor ?>;"><?= clean($risk ?: 'Unassigned') ?></span>
      </div>
      <div class="metric-meta">Disaster Risk Priority Stage</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Puroks / Subdivisions</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
      </div>
      <div class="metric-value"><?= count($puroks) ?></div>
      <div class="metric-meta">Registered Community Clusters</div>
    </div>

    <div class="metric-card">
      <div class="metric-header">
        <span class="metric-label">Evacuation Centers</span>
        <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
      </div>
      <div class="metric-value"><?= count($evacCenters) ?></div>
      <div class="metric-meta">Designated Safe Shelters</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-4);">
    <!-- Barangay Details Card -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Barangay Administration & Contact</h3>
        <span class="badge badge-success">Official Jurisdiction</span>
      </div>
      <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:12px;font-size:11px;">
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <span style="color:var(--color-text-muted);">Official Name:</span>
            <strong>Barangay <?= clean($barangay['name']) ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <span style="color:var(--color-text-muted);">City / Municipality:</span>
            <span><?= clean($barangay['city'] ?? 'Iligan City') ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <span style="color:var(--color-text-muted);">Punong Barangay (Captain):</span>
            <strong style="color:var(--color-primary);"><?= clean($barangay['contact_person'] ?: 'Unassigned') ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <span style="color:var(--color-text-muted);">Hotline / Mobile Number:</span>
            <strong style="font-family:var(--font-secondary);"><?= clean($barangay['contact_number'] ?: '—') ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-border-light);padding-bottom:8px;">
            <span style="color:var(--color-text-muted);">Area Radius:</span>
            <span style="font-family:var(--font-secondary);"><?= $barangay['area_radius'] ? number_format($barangay['area_radius']) . ' meters' : 'Default' ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span style="color:var(--color-text-muted);">GPS Coordinates:</span>
            <span style="font-family:var(--font-secondary);font-size:10.5px;">
              <?= clean($barangay['coordinates_lat']) ?>, <?= clean($barangay['coordinates_lng']) ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Community Readiness & Evac Overview -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Evacuation Shelters in <?= clean($barangay['name']) ?></h3>
        <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="btn btn-outline btn-sm">Manage Shelters</a>
      </div>
      <div class="card-body">
        <?php if (empty($evacCenters)): ?>
          <div style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:11px;">
            No designated evacuation centers recorded for Barangay <?= clean($barangay['name']) ?>.
          </div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($evacCenters as $ec): ?>
              <div style="padding:10px 12px;background:var(--color-surface-subtle);border-radius:8px;border:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;">
                <div>
                  <div style="font-weight:700;font-size:11.5px;color:var(--color-primary);"><?= clean($ec['name']) ?></div>
                  <div style="font-size:9.5px;color:var(--color-text-secondary);"><?= clean($ec['location_address']) ?></div>
                </div>
                <div style="text-align:right;">
                  <span class="badge badge-info" style="font-size:9px;"><?= clean($ec['status']) ?></span>
                  <div style="font-size:9px;font-family:var(--font-secondary);margin-top:2px;">
                    Cap: <?= number_format($ec['capacity_individuals']) ?> pax
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Purok Registry Table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title">Registered Puroks & Communities in <?= clean($barangay['name']) ?></h3>
      <span class="badge badge-neutral"><?= count($puroks) ?> Purok Clusters</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Purok Name</th>
            <th>Primary Hazards</th>
            <th>Vulnerability Level</th>
            <th>Households</th>
            <th>Population</th>
            <th>Coordinates</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($puroks)): ?>
            <tr><td colspan="6" style="text-align:center;padding:28px;">No sub-purok records logged yet.</td></tr>
          <?php else: ?>
            <?php foreach ($puroks as $p): ?>
              <?php
                $pRisk = $p['risk_level'] ?? 'Moderate';
                $pColors = ['Critical' => 'badge-danger', 'High' => 'badge-warning', 'Moderate' => 'badge-info', 'Low' => 'badge-success'];
                $pBadge = $pColors[$pRisk] ?? 'badge-neutral';
              ?>
              <tr>
                <td style="font-weight:700;color:var(--color-primary);"><?= clean($p['name']) ?></td>
                <td style="font-size:10px;color:var(--color-text-secondary);"><?= clean($p['hazard_types'] ?: 'Standard Flood/Squall') ?></td>
                <td><span class="badge <?= $pBadge ?>" style="font-size:9px;"><?= clean($pRisk) ?></span></td>
                <td style="font-family:var(--font-secondary);"><?= number_format($p['households']) ?></td>
                <td style="font-family:var(--font-secondary);font-weight:600;"><?= number_format($p['population']) ?></td>
                <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-text-muted);">
                  <?= clean($p['coordinates_lat']) ?>, <?= clean($p['coordinates_lng']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal: Edit Barangay Details -->
<div class="modal-overlay" id="editBarangayModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
  <div class="modal-dialog" style="max-width:550px;width:100%;background:#FFF;border-radius:10px;overflow:hidden;box-shadow:0 12px 32px rgba(0,0,0,0.25);">
    <div class="modal-header" style="padding:14px 20px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 class="modal-title" style="margin:0;font-size:14px;color:var(--color-primary);">Edit Barangay <?= clean($barangay['name']) ?> Profile</h3>
      <button type="button" class="modal-close-btn" onclick="closeEditBarangayModal()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form id="editBarangayForm" method="POST" action="<?= BASE_URL ?>/backend/functions/barangays/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="id" value="<?= (int)$barangay['id'] ?>">
      <input type="hidden" name="name" value="<?= clean($barangay['name']) ?>">
      <div class="modal-body" style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;max-height:75vh;overflow-y:auto;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Barangay Captain / Contact Official</label>
          <input type="text" name="contact_person" class="form-control" value="<?= clean($barangay['contact_person']) ?>" placeholder="e.g. Hon. Rodrigo Almonte" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Emergency Contact / Hotline Number</label>
          <input type="text" name="contact_number" class="form-control" value="<?= clean($barangay['contact_number']) ?>" placeholder="e.g. +63 917 234 5678" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Total Population</label>
            <input type="number" name="population" class="form-control" value="<?= clean($barangay['population']) ?>" min="0">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Total Households</label>
            <input type="number" name="total_households" class="form-control" value="<?= clean($barangay['total_households']) ?>" min="0">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Disaster Vulnerability / Risk Level</label>
          <select name="risk_level" class="form-control">
            <option value="Low" <?= ($barangay['risk_level'] === 'Low') ? 'selected' : '' ?>>Low</option>
            <option value="Moderate" <?= ($barangay['risk_level'] === 'Moderate') ? 'selected' : '' ?>>Moderate</option>
            <option value="High" <?= ($barangay['risk_level'] === 'High') ? 'selected' : '' ?>>High</option>
            <option value="Critical" <?= ($barangay['risk_level'] === 'Critical') ? 'selected' : '' ?>>Critical</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Operational Radius (Meters)</label>
          <input type="number" name="area_radius" class="form-control" value="<?= clean($barangay['area_radius'] ?: 1500) ?>" min="100">
        </div>
      </div>
      <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--color-border);background:#F8FAFC;display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="closeEditBarangayModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSaveBarangay">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditBarangayModal() {
  const m = document.getElementById('editBarangayModal');
  if (m) {
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeEditBarangayModal() {
  const m = document.getElementById('editBarangayModal');
  if (m) {
    m.style.display = 'none';
    document.body.style.overflow = '';
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
