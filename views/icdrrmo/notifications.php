<?php
// ============================================================================
// Views (ICDRRMO): Communication & Emergency Advisories Center
// Differentiates informational, warning, and urgent alerts
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Communication & Emergency Advisories";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$filterLevel = $_GET['level'] ?? '';

$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE (
        n.target_role = 'all'
        OR n.target_role = ?
        OR (n.target_barangay_id IS NOT NULL AND n.target_barangay_id = ?)
        OR n.target_user_id = ?
    )
";
$params = [$user['id'], $user['role'], $user['barangay_id'], $user['id']];

if (!empty($filterLevel)) {
    $sql .= " AND n.alert_level = ?";
    $params[] = $filterLevel;
}

$sql .= " ORDER BY n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Communication & Emergency Advisories Center</h1>
      <p class="page-header-desc">
        Official city disaster warnings, evacuation notices, resource dispatch updates, and community alerts.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-outline" onclick="markAllAsRead()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
        Mark All Read
      </button>
      <button class="btn btn-primary" onclick="openModal('broadcastModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        Broadcast Official Advisory
      </button>
    </div>
  </div>

  <!-- Severity Filters -->
  <div style="display:flex;gap:6px;margin-bottom:var(--space-4);flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/views/icdrrmo/notifications.php" class="btn btn-sm <?= empty($filterLevel) ? 'btn-primary' : 'btn-outline' ?>">All Advisories (<?= count($alerts) ?>)</a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/notifications.php?level=urgent" class="btn btn-sm <?= $filterLevel === 'urgent' ? 'btn-danger' : 'btn-outline' ?>" style="<?= $filterLevel === 'urgent' ? '' : 'color:var(--color-danger);border-color:var(--color-danger-border);' ?>">
      Urgent Alerts
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/notifications.php?level=warning" class="btn btn-sm <?= $filterLevel === 'warning' ? 'btn-secondary' : 'btn-outline' ?>">
      Warnings
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/notifications.php?level=informational" class="btn btn-sm <?= $filterLevel === 'informational' ? 'btn-primary' : 'btn-outline' ?>">
      Informational
    </a>
  </div>

  <!-- Alerts Feed -->
  <div style="display:flex;flex-direction:column;gap:var(--space-3);">
    <?php if (empty($alerts)): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-state-title">No Advisories Found</div>
          <div class="empty-state-desc">There are no notifications matching your current filter.</div>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $a): ?>
        <?php
          $levelClass = ($a['alert_level'] === 'urgent') ? 'danger' : (($a['alert_level'] === 'warning') ? 'warning' : 'info');
          $borderCol = ($a['alert_level'] === 'urgent') ? 'var(--color-danger)' : (($a['alert_level'] === 'warning') ? 'var(--color-warning)' : 'var(--color-secondary)');
        ?>
        <div class="card" style="margin-bottom:0;border-left: 4px solid <?= $borderCol ?>;opacity: <?= $a['is_read'] ? '0.85' : '1.0' ?>;">
          <div class="card-header" style="background:var(--color-surface-subtle);padding:8px 14px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="badge badge-<?= $levelClass ?>" style="font-size:8px;font-weight:700;">
                <?= strtoupper($a['alert_level']) ?>
              </span>
              <h3 class="card-title" style="margin:0;font-size:12px;"><?= clean($a['title']) ?></h3>
            </div>
            <div style="font-size:9px;color:var(--color-text-muted);font-family:var(--font-secondary);">
              <?= formatDate($a['created_at']) ?>
            </div>
          </div>
          <div class="card-body" style="padding:10px 14px;">
            <div style="font-size:11px;color:var(--color-text);line-height:1.45;margin-bottom:8px;">
              <?= nl2br(clean($a['message'])) ?>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:9px;color:var(--color-text-muted);border-top:1px solid var(--color-border-light);padding-top:6px;">
              <div>
                Origin: <strong><?= clean($a['sender_name'] ?: 'Command Center') ?></strong>
                <?= $a['barangay_name'] ? ' • Target: Brgy. ' . clean($a['barangay_name']) : ' • Target: City-Wide' ?>
              </div>
              <div>
                <?php if ($a['related_module'] === 'disaster_request' && $a['related_id']): ?>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php?id=<?= $a['related_id'] ?>" class="btn btn-outline btn-sm">View Disaster Request</a>
                <?php elseif ($a['related_module'] === 'recommendation' && $a['related_id']): ?>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $a['related_id'] ?>" class="btn btn-secondary btn-sm">View Recommendation</a>
                <?php elseif ($a['related_module'] === 'preparedness' && $a['related_id']): ?>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/preparedness.php" class="btn btn-outline btn-sm">View Activity</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<!-- Modal: Broadcast Advisory -->
<div class="modal-overlay" id="broadcastModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Broadcast Official Advisory</h3>
      <button class="modal-close-btn" onclick="closeModal('broadcastModal')">&times;</button>
    </div>
    <form id="broadcastForm" method="POST" action="<?= BASE_URL ?>/backend/functions/notifications/broadcast.php">
      <input type="hidden" name="action" value="broadcast">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Advisory Headline / Title</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Flash Flood Pre-Emptive Evacuation Order" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Severity Level</label>
            <select name="alert_level" class="form-control" required>
              <option value="urgent">Urgent (Red Alert / Immediate Evacuation)</option>
              <option value="warning" selected>Warning (Elevated Risk / Advisory)</option>
              <option value="informational">Informational (General Community Notice)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label form-label-required">Target Role</label>
            <select name="target_role" class="form-control" required>
              <option value="all">All Stakeholders (Public & Responders)</option>
              <option value="resident">Residents Only</option>
              <option value="responder">Responders / Field Teams</option>
              <option value="barangay_head">Barangay Heads</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Target Barangay</label>
          <select name="barangay_id" class="form-control">
            <option value="">All Barangays (City-Wide)</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Advisory Message Content</label>
          <textarea name="message" class="form-control" rows="4" placeholder="Official advisory text, river gauge readings, mandatory safe routes, assembly instructions..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('broadcastModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Transmit Broadcast</button>
      </div>
    </form>
  </div>
</div>

<script>
function markAllAsRead() {
  window.location.href = '<?= BASE_URL ?>/backend/functions/notifications/mark_all_read.php?return_url=' + encodeURIComponent(window.location.href);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
