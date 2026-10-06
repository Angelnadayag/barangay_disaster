<?php
// ============================================================================
// Views (Responder): Field Status Operations & Tactical Logging
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$statStmt = $db->prepare("SELECT * FROM responder_field_updates WHERE responder_id = ? ORDER BY created_at DESC LIMIT 1");
$statStmt->execute([$user['id']]);
$currentStatus = $statStmt->fetch();
$activeStatus = $currentStatus['operational_status'] ?? 'Available';

$targetRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

$activeIncidents = $db->query("
    SELECT dr.id, dr.tracking_code, dr.disaster_type, dr.purok_name, b.name AS barangay_name, dr.barangay_id
    FROM disaster_requests dr
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.status NOT IN ('Completed', 'Rejected')
    ORDER BY dr.created_at DESC
")->fetchAll();

$historyStmt = $db->query("
    SELECT rfu.*, u.full_name AS responder_name, b.name AS barangay_name, dr.tracking_code
    FROM responder_field_updates rfu
    JOIN users u ON rfu.responder_id = u.id
    JOIN barangays b ON rfu.barangay_id = b.id
    LEFT JOIN disaster_requests dr ON rfu.disaster_request_id = dr.id
    ORDER BY rfu.created_at DESC
    LIMIT 25
");
$historyLogs = $historyStmt->fetchAll();

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();

$pageTitle = "Field Status & Situational Report";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Tactical Field Operations & Incident Logging</h1>
      <p class="page-header-desc">
        Rapid situational reporting for rescuers, mission telemetry, and live casualty/rescue counting.
      </p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:380px 1fr;gap:var(--space-4);align-items:start;">
    <!-- Left: Rapid Status Entry Form -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Transmit Field Situational Report</h3>
      </div>
      <div class="card-body">
        <form id="fieldReportForm" method="POST" action="<?= BASE_URL ?>/backend/functions/responder/submit_report.php">
          <input type="hidden" name="action" value="submit_report">
          <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
          <div class="form-group">
            <label class="form-label form-label-required">Operational Status</label>
            <select name="operational_status" class="form-control" required>
              <option value="On Scene" <?= $activeStatus === 'On Scene' ? 'selected' : '' ?>>On Scene (At Incident Site)</option>
              <option value="En Route" <?= $activeStatus === 'En Route' ? 'selected' : '' ?>>En Route (Traveling to Site)</option>
              <option value="Available" <?= $activeStatus === 'Available' ? 'selected' : '' ?>>Available (Standby / Ready)</option>
              <option value="Busy" <?= $activeStatus === 'Busy' ? 'selected' : '' ?>>Busy (Active Extraction / Transport)</option>
              <option value="Off Duty" <?= $activeStatus === 'Off Duty' ? 'selected' : '' ?>>Off Duty</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Linked Active Incident</label>
            <select name="disaster_request_id" class="form-control" onchange="autoFillBarangay(this)">
              <option value="">No Specific Incident (General Area Patrol)</option>
              <?php foreach ($activeIncidents as $ai): ?>
                <option value="<?= $ai['id'] ?>" data-brgy-id="<?= $ai['barangay_id'] ?>" data-purok="<?= clean($ai['purok_name']) ?>" <?= $targetRequestId === $ai['id'] ? 'selected' : '' ?>>
                  <?= clean($ai['tracking_code']) ?> — <?= clean($ai['disaster_type']) ?> (<?= clean($ai['barangay_name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label form-label-required">Barangay Location</label>
              <select name="barangay_id" id="reportBarangayId" class="form-control" required>
                <?php foreach ($barangays as $b): ?>
                  <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label form-label-required">Purok / Landmark</label>
              <input type="text" name="purok" id="reportPurok" class="form-control" placeholder="e.g. Purok Riverside 1" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Rescued Individuals</label>
              <input type="number" name="rescued_individuals" class="form-control" min="0" value="0">
            </div>
            <div class="form-group">
              <label class="form-label">Injuries Treated</label>
              <input type="number" name="injuries" class="form-control" min="0" value="0">
            </div>
            <div class="form-group">
              <label class="form-label">Casualties</label>
              <input type="number" name="casualties" class="form-control" min="0" value="0">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Resources / Equipment Utilized</label>
            <input type="text" name="resources_utilized" class="form-control" placeholder="e.g. 1 Rubber Boat, 4 Life Vests, 1 First Aid Kit">
          </div>

          <div class="form-group">
            <label class="form-label form-label-required">Ground Condition Overview</label>
            <textarea name="condition_overview" class="form-control" rows="2" placeholder="Water height, road passable/blocked, weather on site, extraction difficulties..." required></textarea>
          </div>

          <button type="submit" class="btn btn-primary" id="btnSubmitReport" style="width:100%;margin-top:6px;">
            Transmit Field Update
          </button>
        </form>
      </div>
    </div>

    <!-- Right: Field Logs History -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Live Tactical Operations Stream</h3>
        <span style="font-size:9px;color:var(--color-text-muted);">Real-time responder broadcasts</span>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Responder</th>
              <th>Status</th>
              <th>Location</th>
              <th>Extracted / Injured</th>
              <th>Situation Summary</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($historyLogs)): ?>
              <tr><td colspan="6" style="text-align:center;padding:24px;">No field updates transmitted today.</td></tr>
            <?php else: ?>
              <?php foreach ($historyLogs as $hl): ?>
                <tr>
                  <td style="font-family:var(--font-secondary);font-size:9px;color:var(--color-text-muted);white-space:nowrap;">
                    <?= formatDate($hl['created_at'], 'H:i:s • M d') ?>
                  </td>
                  <td style="font-weight:600;color:var(--color-primary);">
                    <?= clean($hl['responder_name']) ?>
                  </td>
                  <td><?= renderStatusBadge($hl['operational_status']) ?></td>
                  <td>
                    <div><?= clean($hl['barangay_name']) ?></div>
                    <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($hl['purok']) ?></div>
                  </td>
                  <td style="font-family:var(--font-secondary);font-size:9px;">
                    <span style="color:var(--color-success);font-weight:700;">+<?= $hl['rescued_individuals'] ?> saved</span> / 
                    <span style="color:var(--color-danger);"><?= $hl['injuries'] ?> inj</span>
                  </td>
                  <td style="font-size:10px;color:var(--color-text);max-width:280px;">
                    <?= clean($hl['condition_overview']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
function autoFillBarangay(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (opt && opt.getAttribute('data-brgy-id')) {
    document.getElementById('reportBarangayId').value = opt.getAttribute('data-brgy-id');
    document.getElementById('reportPurok').value = opt.getAttribute('data-purok') || '';
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
