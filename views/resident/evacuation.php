<?php
// ============================================================================
// Views (Resident): Evacuation Directory & Safe Shelters
// Public directory with real-time occupancy, amenities, and safe shelter finder
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterBarangay = $_GET['barangay_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = "
    SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
           ea.location_address AS location_details,
           b.name AS barangay_name, b.risk_level AS brgy_risk
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

$sql .= " ORDER BY (ea.barangay_id = ?) DESC, ea.status = 'Open' DESC, ea.name ASC";
$params[] = $barangayId;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$evacAreas = $stmt->fetchAll();

$barangays = $db->query("SELECT * FROM barangays ORDER BY name ASC")->fetchAll();

$pageTitle = "Evacuation Centers Directory — Citizen Portal";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Designated Evacuation Shelters Directory</h1>
      <p class="page-header-desc">
        Real-time capacity tracking, facility amenities (electricity, potable water, medical station), and safe shelter navigation.
      </p>
    </div>
    <div class="page-header-actions">
      <a href="risk-map.php" class="btn btn-secondary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon></svg>
        View on Hazard Map
      </a>
    </div>
  </div>

  <!-- Filter Ribbon -->
  <div class="card" style="margin-bottom:var(--space-4);padding:14px 18px;">
    <form method="GET" style="display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
      <div style="flex:1;min-width:200px;">
        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;color:var(--color-text-muted);">Select Barangay</label>
        <select name="barangay_id" class="form-control" style="font-size:12px;" onchange="this.form.submit()">
          <option value="">All Iligan City Barangays</option>
          <?php foreach ($barangays as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>>
              Barangay <?= clean($b['name']) ?> <?= $b['id'] == $barangayId ? '(My Barangay)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="width:180px;">
        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;color:var(--color-text-muted);">Center Status</label>
        <select name="status" class="form-control" style="font-size:12px;" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open (Accepting Evacuees)</option>
          <option value="Standby" <?= $filterStatus === 'Standby' ? 'selected' : '' ?>>Standby / Prepared</option>
          <option value="Full" <?= $filterStatus === 'Full' ? 'selected' : '' ?>>Full / At Capacity</option>
        </select>
      </div>

      <div style="align-self:flex-end;">
        <a href="evacuation.php" class="btn btn-secondary btn-sm" style="font-size:12px;padding:7px 12px;">Reset Filter</a>
      </div>
    </form>
  </div>

  <!-- Cards Grid for Shelters -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(360px, 1fr));gap:var(--space-4);">
    <?php if (empty($evacAreas)): ?>
      <div class="card" style="grid-column:1/-1;padding:48px;text-align:center;color:var(--color-text-muted);">
        No evacuation centers match the selected criteria.
      </div>
    <?php else: ?>
      <?php foreach ($evacAreas as $ea): 
        $pct = $ea['capacity'] > 0 ? round(($ea['current_evacuees'] / $ea['capacity']) * 100) : 0;
        $isMyBarangay = ($ea['barangay_id'] == $barangayId);
      ?>
        <div class="card" style="margin-bottom:0;display:flex;flex-direction:column;border-top:3px solid <?= $ea['status'] === 'Open' ? 'var(--color-success)' : ($ea['status'] === 'Full' ? 'var(--color-danger)' : 'var(--color-text-muted)') ?>;">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;padding:14px 18px;">
            <div>
              <div style="display:flex;align-items:center;gap:6px;">
                <h2 class="card-title" style="font-size:14px;color:var(--color-text);"><?= clean($ea['name']) ?></h2>
                <?php if ($isMyBarangay): ?>
                  <span class="badge badge-primary" style="font-size:10px;">Local</span>
                <?php endif; ?>
              </div>
              <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px;">
                Barangay <?= clean($ea['barangay_name']) ?> &bull; <?= clean($ea['location_details'] ?? 'Community Center') ?>
              </div>
            </div>
            <?= renderStatusBadge($ea['status']) ?>
          </div>

          <div class="card-body" style="padding:14px 18px;flex:1;display:flex;flex-direction:column;justify-content:space-between;">
            <!-- Capacity Meter -->
            <div style="margin-bottom:16px;">
              <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:6px;">
                <span style="font-weight:600;color:var(--color-text);">Occupancy Load</span>
                <span style="font-weight:700;color:<?= $pct >= 90 ? 'var(--color-danger)' : ($pct >= 75 ? 'var(--color-warning)' : 'var(--color-success)') ?>;">
                  <?= number_format($ea['current_evacuees']) ?> / <?= number_format($ea['capacity']) ?> Individuals (<?= $pct ?>%)
                </span>
              </div>
              <div style="background:var(--color-surface-subtle);height:8px;border-radius:4px;overflow:hidden;border:1px solid var(--color-border);">
                <div style="width:<?= min($pct, 100) ?>%;height:100%;background:<?= $pct >= 90 ? 'var(--color-danger)' : ($pct >= 75 ? 'var(--color-warning)' : 'var(--color-success)') ?>;"></div>
              </div>
              <div style="font-size:10px;color:var(--color-text-muted);margin-top:4px;">
                Remaining available slots: <strong><?= max(0, $ea['capacity'] - $ea['current_evacuees']) ?></strong>
              </div>
            </div>

            <!-- Amenities Badges -->
            <div style="margin-bottom:16px;background:var(--color-surface-subtle);padding:10px 12px;border-radius:6px;">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:6px;letter-spacing:0.04em;">Available Life Support Utilities</div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px;">
                <span class="badge <?= $ea['has_power'] ? 'badge-success' : 'badge-neutral' ?>">
                  <?= $ea['has_power'] ? '⚡ Backup Generator' : '✖ No Generator' ?>
                </span>
                <span class="badge <?= $ea['has_water'] ? 'badge-success' : 'badge-neutral' ?>">
                  <?= $ea['has_water'] ? '💧 Potable Water' : '✖ Water Tank Truck Needed' ?>
                </span>
                <span class="badge <?= $ea['has_medical'] ? 'badge-success' : 'badge-neutral' ?>">
                  <?= $ea['has_medical'] ? '🩺 First Aid On-Site' : '✖ No Medical Staff' ?>
                </span>
              </div>
            </div>

            <!-- Footer Contact & Map Link -->
            <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--color-border);padding-top:10px;font-size:11px;">
              <span style="color:var(--color-text-muted);">
                Camp Mgr: <strong><?= clean($ea['contact_person'] ?? 'Barangay Focal') ?></strong>
              </span>
              <a href="risk-map.php" class="btn btn-secondary btn-sm" style="font-size:11px;padding:3px 8px;">
                Locate Pin &rarr;
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
