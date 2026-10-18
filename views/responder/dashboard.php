<?php
// ============================================================================
// Views (Responder): Tactical Field Command Dashboard
// Clean, rapid mobile-accessible situational dashboard for field responders
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

// Latest status of current responder
$statStmt = $db->prepare("SELECT * FROM responder_field_updates WHERE responder_id = ? ORDER BY created_at DESC LIMIT 1");
$statStmt->execute([$user['id']]);
$currentStatus = $statStmt->fetch();
$activeStatus = $currentStatus['operational_status'] ?? 'Available';

// Active Incidents
$incStmt = $db->query("
    SELECT dr.*, b.name AS barangay_name 
    FROM disaster_reports dr 
    JOIN barangays b ON dr.barangay_id = b.id 
    WHERE dr.status = 'Ongoing'
    ORDER BY FIELD(dr.severity, 'Critical', 'High', 'Moderate', 'Low'), dr.created_at DESC
    LIMIT 6
");
$activeIncidents = $incStmt->fetchAll();

// Evacuation Centers Open
$evacStmt = $db->query("
    SELECT ea.*, b.name AS barangay_name 
    FROM evacuation_areas ea 
    JOIN barangays b ON ea.barangay_id = b.id 
    WHERE ea.status IN ('Open / Active', 'Standby')
    ORDER BY ea.current_evacuees_count DESC
    LIMIT 4
");
$openCenters = $evacStmt->fetchAll();

// Urgent Notifications
$notifStmt = $db->query("
    SELECT * FROM notifications 
    WHERE alert_level IN ('urgent', 'warning')
    ORDER BY created_at DESC LIMIT 3
");
$urgentAlerts = $notifStmt->fetchAll();

$pageTitle = "Responder Field Dashboard";
require_once __DIR__ . '/../layouts/header.php';
?>

  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Tactical Field Operations Dashboard</h1>
    </div>
    <div class="page-header-actions">
      <a href="active-incidents.php" class="btn btn-secondary btn-sm" style="font-size:11px;padding:6px 12px;">Active Incidents</a>
      <a href="evacuation.php" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 12px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
      </a>
    </div>
  </div>

  <!-- Tactical Identity Banner -->
  <div style="background:var(--color-primary);color:#FFF;border-radius:var(--radius-primary, 10px);padding:12px 16px;margin-bottom:var(--space-3);box-shadow:var(--shadow-subtle);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-size:9px;text-transform:uppercase;color:#B5C6D4;letter-spacing:0.8px;font-weight:700;">Tactical Field Identity</div>
        <div style="font-size:15px;font-weight:700;margin-top:2px;"><?= clean($user['full_name']) ?></div>
        <div style="font-size:10px;color:#D9E0E3;">Field Unit: <?= clean($user['purok'] ?: 'Rapid Response Team A') ?> • Base: Central Command</div>
      </div>
      <div>
        <span style="font-size:9px;color:#B5C6D4;display:block;margin-bottom:4px;text-align:right;">Current Mission Readiness:</span>
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
          <?php foreach (['Available', 'En Route', 'On Scene', 'Busy', 'Off Duty'] as $st): ?>
            <?php
              $isSelected = ($activeStatus === $st);
              $bgCol = $isSelected ? '#2F6F73' : 'rgba(255,255,255,0.12)';
              $borderCol = $isSelected ? '#4DD0E1' : 'transparent';
            ?>
            <button class="btn btn-sm" style="background:<?= $bgCol ?>;color:#FFF;border:1px solid <?= $borderCol ?>;font-size:9.5px;padding:4px 9px;"
                    onclick="setQuickStatus('<?= $st ?>')">
              <?= $st ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($urgentAlerts)): ?>
    <div style="margin-bottom:var(--space-3);display:flex;flex-direction:column;gap:6px;">
      <?php foreach ($urgentAlerts as $alert): ?>
        <div style="background:#FDE8E8;border-left:4px solid var(--color-danger);padding:7px 12px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;">
          <div style="font-size:10px;color:var(--color-danger);font-weight:600;">
            [<?= strtoupper($alert['alert_level']) ?>] <?= clean($alert['title']) ?>: <span style="font-weight:400;"><?= clean($alert['message']) ?></span>
          </div>
          <span style="font-size:8.5px;color:#991B1B;white-space:nowrap;margin-left:8px;"><?= formatDate($alert['created_at'], 'h:i A') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="responsive-split-grid split-50-50">
    <!-- Active Disasters Awaiting Support -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
        <h3 class="card-title" style="margin:0;">Priority Incident Dispatches</h3>
        <a href="<?= BASE_URL ?>/views/responder/active-incidents.php" class="btn btn-outline btn-sm">All Incidents</a>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Tracking #</th>
              <th>Location</th>
              <th>Severity</th>
              <th>Displaced</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeIncidents)): ?>
              <tr><td colspan="5" style="text-align:center;padding:24px;">No active disaster incidents reported.</td></tr>
            <?php else: ?>
              <?php foreach ($activeIncidents as $inc): ?>
                <tr>
                  <td style="font-weight:600;font-family:var(--font-secondary);">
                    <?= clean($inc['tracking_code']) ?>
                    <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($inc['disaster_type']) ?></div>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= clean($inc['barangay_name']) ?></div>
                    <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($inc['purok_name']) ?></div>
                  </td>
                  <td><?= renderStatusBadge($inc['severity']) ?></td>
                  <td style="font-family:var(--font-secondary);"><?= $inc['displaced_families'] ?> fam</td>
                  <td>
                    <a href="<?= BASE_URL ?>/views/responder/field-status.php?request_id=<?= $inc['id'] ?>" class="btn btn-secondary btn-sm">
                      Log Situation
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Evacuation Centers Available -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
        <h3 class="card-title" style="margin:0;">Evacuation Shelters Vacancy Status</h3>
        <a href="<?= BASE_URL ?>/views/responder/evacuation.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Shelter Name</th>
              <th>Barangay</th>
              <th>Occupancy</th>
              <th>Road Access</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($openCenters)): ?>
              <tr><td colspan="4" style="text-align:center;padding:24px;">No open shelters recorded.</td></tr>
            <?php else: ?>
              <?php foreach ($openCenters as $oc): ?>
                <?php
                  $pct = $oc['capacity_individuals'] > 0 ? min(100, round(($oc['current_evacuees_count'] / $oc['capacity_individuals']) * 100)) : 0;
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;color:var(--color-primary);"><?= clean($oc['name']) ?></div>
                    <div style="font-size:8px;color:var(--color-text-muted);"><?= clean($oc['center_type']) ?></div>
                  </td>
                  <td><?= clean($oc['barangay_name']) ?></td>
                  <td style="font-family:var(--font-secondary);">
                    <?= $oc['current_evacuees_count'] ?> / <?= $oc['capacity_individuals'] ?> (<?= $pct ?>%)
                  </td>
                  <td>
                    <span style="font-size:9px;color:var(--color-secondary);font-weight:600;">
                      <?= clean($oc['accessibility']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<script>
async function setQuickStatus(status) {
  try {
    const fd = new FormData();
    fd.append('status', status);

    const res = await fetch(`${BASE_URL}/backend/functions/responder/quick_status.php`, {
      method: 'POST',
      body: fd
    });
    const data = await res.json();
    if (data.success) {
      showToast(`Operational readiness updated to: ${status}`, 'success');
      setTimeout(() => window.location.reload(), 700);
    } else {
      showToast(data.message || 'Status update failed.', 'danger');
    }
  } catch (err) {
    showToast('Network error updating operational status.', 'danger');
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
