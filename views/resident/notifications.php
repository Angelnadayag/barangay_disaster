<?php
// ============================================================================
// Views (Resident): Barangay Notifications & Advisories
// Citizen notices strictly scoped to resident's barangay jurisdiction
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterTab = trim($_GET['tab'] ?? 'all');
$filterUnread = !empty($_GET['unread']);
$searchQuery = trim($_GET['q'] ?? '');

// Base query strictly scoped to resident's barangay ONLY
$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE n.target_barangay_id = ?
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = ?)
";
$params = [$user['id'], $barangayId, $user['id']];

if ($filterTab === 'financial_aid') {
    $sql .= " AND (n.related_module = 'financial_aid' OR n.title LIKE '%Financial Aid%' OR n.title LIKE '%Ayuda%' OR n.title LIKE '%Cash Assistance%')";
} elseif ($filterTab === 'resources') {
    $sql .= " AND (n.related_module = 'resources' OR n.title LIKE '%Resource%' OR n.title LIKE '%Relief%' OR n.title LIKE '%Supplies%')";
} elseif ($filterTab === 'events') {
    $sql .= " AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Seminar%')";
} elseif ($filterTab === 'critical') {
    $sql .= " AND n.alert_level IN ('urgent', 'Critical', 'critical')";
}

if ($filterUnread) {
    $sql .= " AND nr.id IS NULL";
}

if (!empty($searchQuery)) {
    $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (nr.id IS NULL) DESC, FIELD(n.alert_level, 'urgent', 'Critical', 'warning', 'informational'), n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts per category (strictly scoped to this barangay)
$countAll = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
")->fetchColumn();

$countAid = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND (n.related_module = 'financial_aid' OR n.title LIKE '%Financial Aid%' OR n.title LIKE '%Ayuda%' OR n.title LIKE '%Cash Assistance%')
")->fetchColumn();

$countRes = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND (n.related_module = 'resources' OR n.title LIKE '%Resource%' OR n.title LIKE '%Relief%' OR n.title LIKE '%Supplies%')
")->fetchColumn();

$countEvents = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Seminar%')
")->fetchColumn();

$countCritical = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND n.alert_level IN ('urgent', 'Critical', 'critical')
")->fetchColumn();

$countUnread = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = {$user['id']}
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND nr.id IS NULL
")->fetchColumn();

$pageTitle = "Notifications & Advisories";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Resident Notifications: Anti-Slop, Strict 10px Radius, Project Palette (#17324D, #2F6F73, #D9E0E3, #F7F8F7) */
.res-notif-wrap {
  display: flex;
  flex-direction: column;
  gap: var(--space-3, 10px);
  width: 100%;
}

.res-filter-bar {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 8px 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.res-filter-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.res-filter-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 10.5px;
  font-weight: 500;
  padding: 5px 10px;
  border-radius: 6px;
  border: 1px solid var(--color-border, #D9E0E3);
  background: var(--color-surface-subtle, #F7F8F7);
  color: var(--color-text, #24313A);
  text-decoration: none;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.res-filter-pill:hover {
  background: #EBF3F5;
  color: var(--color-primary, #17324D);
  border-color: var(--color-secondary, #2F6F73);
}

.res-filter-pill.active {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border-color: var(--color-primary, #17324D);
  font-weight: 600;
}

.res-pill-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 1px 5px;
  font-size: 9px;
  font-weight: 700;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.08);
  line-height: 1.2;
}

.res-filter-pill.active .res-pill-badge {
  background: rgba(255, 255, 255, 0.22);
  color: #FFFFFF;
}

.res-notif-cards-stack {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.res-notif-item {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 16px;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  position: relative;
}

.res-notif-item:hover {
  border-color: var(--color-secondary, #2F6F73);
  box-shadow: 0 2px 8px rgba(23, 50, 77, 0.05);
}

.res-notif-item.is-unread {
  background: #FCFDFD;
  border-left: 3.5px solid var(--color-secondary, #2F6F73);
}

.res-notif-item.is-urgent {
  border-left: 3.5px solid #ef4444;
}

.res-notif-header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 5px;
}

.res-notif-tags-group {
  display: flex;
  align-items: center;
  gap: 6px;
}

.res-module-badge {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: var(--color-secondary, #2F6F73);
  background: #EBF3F5;
  padding: 2px 7px;
  border-radius: 4px;
}

.res-notif-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-primary, #17324D);
  margin: 0 0 5px 0;
  line-height: 1.35;
}

.res-notif-body {
  font-size: 10.5px;
  color: var(--color-text, #24313A);
  line-height: 1.5;
  margin-bottom: 8px;
}

.res-notif-meta-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding-top: 7px;
  border-top: 1px solid var(--color-border-light, #E7EDF0);
  font-size: 10px;
  color: var(--color-text-muted, #66737D);
}

.res-meta-issuer {
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>

<div class="res-notif-wrap">

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom:0;padding-bottom:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;color:var(--color-primary,#17324D);">Notifications &amp; Advisories</h1>
    </div>
    <div class="page-header-actions" style="display:flex;gap:6px;">
      <?php if ($countUnread > 0): ?>
        <button class="btn btn-secondary btn-sm" onclick="markAllResidentRead()" style="font-size:11px;padding:5px 12px;display:inline-flex;align-items:center;gap:5px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          Mark All Read (<?= $countUnread ?>)
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter Strip -->
  <div class="res-filter-bar">
    <div class="res-filter-pills">
      <a href="notifications.php" class="res-filter-pill <?= $filterTab === 'all' && !$filterUnread ? 'active' : '' ?>">
        <span>All</span>
        <span class="res-pill-badge"><?= $countAll ?></span>
      </a>
      <a href="notifications.php?tab=financial_aid" class="res-filter-pill <?= $filterTab === 'financial_aid' ? 'active' : '' ?>">
        <span>Financial Aid</span>
        <span class="res-pill-badge"><?= $countAid ?></span>
      </a>
      <a href="notifications.php?tab=resources" class="res-filter-pill <?= $filterTab === 'resources' ? 'active' : '' ?>">
        <span>Relief Resources</span>
        <span class="res-pill-badge"><?= $countRes ?></span>
      </a>
      <a href="notifications.php?tab=events" class="res-filter-pill <?= $filterTab === 'events' ? 'active' : '' ?>">
        <span>Events &amp; Drills</span>
        <span class="res-pill-badge"><?= $countEvents ?></span>
      </a>
      <a href="notifications.php?tab=critical" class="res-filter-pill <?= $filterTab === 'critical' ? 'active' : '' ?>">
        <span>Critical</span>
        <span class="res-pill-badge"><?= $countCritical ?></span>
      </a>
      <?php if ($countUnread > 0): ?>
        <a href="notifications.php?unread=1" class="res-filter-pill <?= $filterUnread ? 'active' : '' ?>" style="color:#C62828;">
          <span>Unread</span>
          <span class="res-pill-badge" style="background:#FDE8E8;color:#C62828;"><?= $countUnread ?></span>
        </a>
      <?php endif; ?>
    </div>

    <!-- Search input -->
    <form method="GET" action="notifications.php" style="margin:0;display:flex;gap:4px;align-items:center;">
      <input type="hidden" name="tab" value="<?= clean($filterTab) ?>">
      <?php if ($filterUnread): ?><input type="hidden" name="unread" value="1"><?php endif; ?>
      <input type="text" name="q" value="<?= clean($searchQuery) ?>" placeholder="Search notices..." class="form-control" style="font-size:10.5px;padding:4px 8px;width:140px;height:28px;border-radius:6px;">
      <button type="submit" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 8px;height:28px;">Filter</button>
      <?php if (!empty($searchQuery)): ?>
        <a href="notifications.php?tab=<?= clean($filterTab) ?><?= $filterUnread ? '&unread=1' : '' ?>" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 7px;height:28px;">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Notifications List -->
  <div class="res-notif-cards-stack">
    <?php if (empty($alerts)): ?>
      <div style="background:#FFF;border:1px dashed var(--color-border,#D9E0E3);border-radius:10px;padding:36px 20px;text-align:center;color:var(--color-text-muted);">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div style="font-weight:600;font-size:13px;color:var(--color-primary,#17324D);">No Notifications Found</div>
        <div style="font-size:10.5px;margin-top:3px;">No active notices found for your barangay sector.</div>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $a): 
        $isRead = !empty($a['is_read']);
        $isCrit = in_array(strtolower($a['alert_level'] ?? ''), ['critical', 'urgent', 'danger']);
        $mod = $a['related_module'] ?? '';
        $tLower = strtolower($a['title']);

        if ($mod === 'financial_aid' || strpos($tLower, 'financial aid') !== false || strpos($tLower, 'ayuda') !== false) {
            $tagLabel = 'Financial Aid';
        } elseif ($mod === 'resources' || strpos($tLower, 'resource') !== false || strpos($tLower, 'relief') !== false) {
            $tagLabel = 'Relief Resources';
        } elseif ($mod === 'events' || $mod === 'preparedness' || strpos($tLower, 'event') !== false || strpos($tLower, 'drill') !== false) {
            $tagLabel = 'Barangay Event';
        } else {
            $tagLabel = clean($a['alert_type'] ?? 'Advisory');
        }
      ?>
        <div class="res-notif-item <?= !$isRead ? 'is-unread' : '' ?> <?= $isCrit ? 'is-urgent' : '' ?>" id="resNotifItem<?= (int)$a['id'] ?>">
          <div class="res-notif-header-row">
            <div class="res-notif-tags-group">
              <span class="res-module-badge"><?= $tagLabel ?></span>
              <?= renderStatusBadge($a['alert_level']) ?>
              <?php if (!$isRead): ?>
                <span class="badge badge-info" style="font-size:9px;padding:1px 5px;">NEW</span>
              <?php endif; ?>
            </div>
            <div style="font-size:10px;color:var(--color-text-muted);display:flex;align-items:center;gap:10px;">
              <span><?= timeAgo($a['created_at']) ?> (<?= formatDate($a['created_at']) ?>)</span>
              <?php if (!$isRead): ?>
                <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 7px;font-size:10px;" onclick="markResidentRead(<?= (int)$a['id'] ?>, this)">
                  Mark Read
                </button>
              <?php endif; ?>
            </div>
          </div>

          <h2 class="res-notif-title"><?= clean($a['title']) ?></h2>

          <div class="res-notif-body">
            <?= nl2br(clean($a['message'])) ?>
          </div>

          <div class="res-notif-meta-footer">
            <div class="res-meta-issuer">
              <span><strong>Issued by:</strong> <?= clean($a['sender_name'] ?: 'Barangay Head Hon. Rodrigo Almonte') ?></span>
              <span style="color:var(--color-border);">&bull;</span>
              <span><strong>Barangay:</strong> Brgy. <?= clean($a['barangay_name'] ?? $user['barangay_name'] ?? 'Local') ?></span>
            </div>
            <?php if ($mod === 'events' || $mod === 'preparedness'): ?>
              <div>
                <a href="events.php" style="font-size:10px;color:var(--color-secondary);font-weight:600;text-decoration:none;">View Drill Schedule &rarr;</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
async function markResidentRead(id, btn) {
  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/notifications/mark_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    });
    btn.outerHTML = '<span style="color:var(--color-success);font-weight:600;font-size:10px;">✓ Read</span>';
    const card = document.getElementById('resNotifItem' + id);
    if (card) {
      card.classList.remove('is-unread');
    }
  } catch (err) {
    console.error(err);
  }
}

function markAllResidentRead() {
  window.location.href = '<?= BASE_URL ?>/backend/functions/notifications/mark_all_read.php?return_url=' + encodeURIComponent(window.location.href);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
