<?php
// ============================================================================
// Views (Resident): Public Advisories & Emergency Weather Bulletins
// Citizen notifications feed for typhoon warnings, flood alerts, and barangay announcements
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterLevel = $_GET['level'] ?? '';

$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE (
        n.target_role IN ('all', 'resident')
        OR n.target_barangay_id = ?
        OR n.target_user_id = ?
    )
";
$params = [$user['id'], $barangayId, $user['id']];

if (!empty($filterLevel)) {
    $sql .= " AND n.alert_level = ?";
    $params[] = $filterLevel;
}

$sql .= " ORDER BY n.alert_level = 'Critical' DESC, n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$pageTitle = "Public Advisories & Bulletins";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Public Advisories & Disaster Bulletins</h1>
      <p class="page-header-desc">
        Official bulletins released by Iligan City Disaster Risk Reduction & Management Office (ICDRRMO) and your local BDRRMC.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-secondary btn-sm" onclick="markAllResidentRead()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
        Mark All Read
      </button>
    </div>
  </div>

  <!-- Filter Strip -->
  <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap;">
    <a href="notifications.php" class="btn btn-sm <?= empty($filterLevel) ? 'btn-primary' : 'btn-secondary' ?>">
      All Advisories (<?= count($alerts) ?>)
    </a>
    <a href="notifications.php?level=Critical" class="btn btn-sm <?= $filterLevel === 'Critical' ? 'btn-danger' : 'btn-secondary' ?>">
      Critical / Evacuation Order
    </a>
    <a href="notifications.php?level=Warning" class="btn btn-sm <?= $filterLevel === 'Warning' ? 'btn-warning' : 'btn-secondary' ?>">
      Warning Bulletins
    </a>
    <a href="notifications.php?level=Info" class="btn btn-sm <?= $filterLevel === 'Info' ? 'btn-primary' : 'btn-secondary' ?>">
      General Community Info
    </a>
  </div>

  <!-- Bulletins List -->
  <div class="card">
    <div class="card-body" style="padding:0;">
      <?php if (empty($alerts)): ?>
        <div style="padding:48px 24px;text-align:center;color:var(--color-text-muted);">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <div style="font-weight:600;font-size:14px;color:var(--color-text);">No Active Public Advisories</div>
          <div style="font-size:12px;margin-top:4px;">No active emergency weather bulletins or evacuation advisories found.</div>
        </div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach ($alerts as $a): 
            $isCrit = in_array($a['alert_level'], ['Critical', 'Danger']);
            $borderCol = $isCrit ? '#ef4444' : ($a['alert_level'] === 'Warning' ? '#f59e0b' : 'var(--color-primary)');
          ?>
            <div style="padding:16px 20px;border-bottom:1px solid var(--color-border);border-left:4px solid <?= $borderCol ?>;background:<?= $a['is_read'] ? 'transparent' : 'var(--color-surface-subtle)' ?>;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
                <div style="display:flex;align-items:center;gap:8px;">
                  <?= renderStatusBadge($a['alert_level']) ?>
                  <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">
                    <?= clean($a['alert_type'] ?? 'Bulletin') ?>
                  </span>
                  <?php if (!$a['is_read']): ?>
                    <span class="badge badge-info" style="font-size:10px;padding:1px 6px;">NEW</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:11px;color:var(--color-text-muted);display:flex;align-items:center;gap:12px;">
                  <span><?= timeAgo($a['created_at']) ?> (<?= formatDate($a['created_at']) ?>)</span>
                  <?php if (!$a['is_read']): ?>
                    <button class="btn btn-secondary btn-sm" style="padding:2px 8px;font-size:11px;" onclick="markResidentRead(<?= (int)$a['id'] ?>, this)">
                      Mark Read
                    </button>
                  <?php endif; ?>
                </div>
              </div>

              <h3 style="font-size:14px;font-weight:700;color:var(--color-text);margin:0 0 6px 0;">
                <?= clean($a['title']) ?>
              </h3>

              <div style="font-size:13px;color:var(--color-text-muted);line-height:1.5;">
                <?= nl2br(clean($a['message'])) ?>
              </div>

              <div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:var(--color-text-muted);border-top:1px dashed var(--color-border);padding-top:8px;">
                <span><strong>Issued by:</strong> <?= clean($a['sender_name'] ?: 'ICDRRMO Central') ?></span>
                <?php if ($a['barangay_name']): ?>
                  <span><strong>Target Barangay:</strong> <?= clean($a['barangay_name']) ?></span>
                <?php else: ?>
                  <span><strong>Target Barangay:</strong> All Iligan City Barangays</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
async function markResidentRead(id, btn) {
  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/notifications/mark_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    });
    btn.outerHTML = '<span style="color:var(--color-success);font-weight:600;font-size:11px;">✓ Read</span>';
  } catch (err) {
    console.error(err);
  }
}

function markAllResidentRead() {
  window.location.href = '<?= BASE_URL ?>/backend/functions/notifications/mark_all_read.php?return_url=' + encodeURIComponent(window.location.href);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
