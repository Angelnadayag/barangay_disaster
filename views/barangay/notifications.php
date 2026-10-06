<?php
// ============================================================================
// Views (Barangay Head): Alerts & Advisories Center
// Scoped to notifications relevant to this barangay with ability to broadcast to residents
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

$filterLevel = $_GET['level'] ?? '';

$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE (
        n.target_role = 'all'
        OR n.target_role = 'barangay_head'
        OR (n.target_barangay_id IS NOT NULL AND n.target_barangay_id = ?)
        OR n.target_user_id = ?
    )
";
$params = [$user['id'], $barangayId, $user['id']];

if (!empty($filterLevel)) {
    $sql .= " AND n.alert_level = ?";
    $params[] = $filterLevel;
}

$sql .= " ORDER BY n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$pageTitle = "Alerts & Advisories — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay <?= clean($barangay['name'] ?? '') ?> Alerts & Advisories</h1>
      <p class="page-header-desc">
        Emergency alerts from ICDRRMO, pre-emptive evacuation advisories, and community broadcasts.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-outline" onclick="markAllAsRead()">
        Mark All Read
      </button>
      <button class="btn btn-primary" onclick="openModal('broadcastModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path></svg>
        Broadcast to Barangay Residents
      </button>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--space-3);">
    <?php if (empty($alerts)): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-state-title">No Active Alerts</div>
          <div class="empty-state-desc">There are no unread advisories for Barangay <?= clean($barangay['name']) ?>.</div>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $a): ?>
        <?php
          $levelClass = ($a['alert_level'] === 'urgent') ? 'danger' : (($a['alert_level'] === 'warning') ? 'warning' : 'info');
          $borderCol = ($a['alert_level'] === 'urgent') ? 'var(--color-danger)' : (($a['alert_level'] === 'warning') ? 'var(--color-warning)' : 'var(--color-secondary)');
        ?>
        <div class="card" style="margin-bottom:0;border-left: 4px solid <?= $borderCol ?>;">
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
            <div style="font-size:9px;color:var(--color-text-muted);border-top:1px solid var(--color-border-light);padding-top:6px;">
              From: <strong><?= clean($a['sender_name'] ?: 'Command Center') ?></strong>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<!-- Modal: Broadcast to Barangay -->
<div class="modal-overlay" id="broadcastModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Broadcast Notice to Barangay Residents</h3>
      <button class="modal-close-btn" onclick="closeModal('broadcastModal')">&times;</button>
    </div>
    <form id="broadcastForm" method="POST" action="<?= BASE_URL ?>/backend/functions/notifications/broadcast.php">
      <input type="hidden" name="action" value="broadcast">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
      <input type="hidden" name="target_role" value="resident">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Advisory Headline / Subject</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Flash Flood Advisory for Purok Riverside Residents" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Alert Level</label>
          <select name="alert_level" class="form-control" required>
            <option value="urgent">Urgent (Pre-Emptive Evacuation Notice)</option>
            <option value="warning" selected>Warning (Elevated Risk / Standby)</option>
            <option value="informational">Informational (General Barangay Announcement)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Advisory Message</label>
          <textarea name="message" class="form-control" rows="4" placeholder="Instruction for residents, safe assembly centers, hotlines..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('broadcastModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send to Residents</button>
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
