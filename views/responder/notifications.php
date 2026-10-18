<?php
// ============================================================================
// Views (Responder): Field Directives, Disaster Incidents & Tactical Drills
// Scoped strictly to Responder's Barangay Jurisdiction
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangayName = $bStmt->fetchColumn() ?: 'Local Sector';

// Filters
$activeTab = trim($_GET['tab'] ?? 'all');
$filterRead = trim($_GET['read'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');

// Base query strictly scoped to this responder's barangay ONLY
$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE n.target_barangay_id = ?
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = ?)
";
$params = [$user['id'], $barangayId, $user['id']];

if ($activeTab === 'incidents') {
    $sql .= " AND (n.related_module IN ('disaster_request', 'disaster_report', 'incident', 'responder') OR n.title LIKE '%Incident%' OR n.title LIKE '%Dispatch%' OR n.title LIKE '%Flood%' OR n.title LIKE '%Fire%')";
} elseif ($activeTab === 'events') {
    $sql .= " AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Training%' OR n.title LIKE '%Refresher%')";
} elseif ($activeTab === 'urgent') {
    $sql .= " AND (n.alert_level IN ('urgent', 'Critical'))";
}

if ($filterRead === 'unread') {
    $sql .= " AND nr.id IS NULL";
}

if (!empty($searchQuery)) {
    $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (nr.id IS NULL) DESC, FIELD(n.alert_level, 'urgent', 'warning', 'informational'), n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts per tab (strictly scoped to responder's barangay)
$countAll = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = {$user['id']})
")->fetchColumn();

$countIncidents = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = {$user['id']})
      AND (n.related_module IN ('disaster_request', 'disaster_report', 'incident', 'responder') OR n.title LIKE '%Incident%' OR n.title LIKE '%Dispatch%' OR n.title LIKE '%Flood%' OR n.title LIKE '%Fire%')
")->fetchColumn();

$countEvents = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = {$user['id']})
      AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Training%' OR n.title LIKE '%Refresher%')
")->fetchColumn();

$countUnread = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = {$user['id']}
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'responder') OR n.target_user_id = {$user['id']})
      AND nr.id IS NULL
")->fetchColumn();

$pageTitle = "Brgy. " . clean($barangayName) . " Responder Directives";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Responder Notifications Styling — Strict 10px radius, 9-11px typography */
.resp-notif-container {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  max-width: 960px;
  margin: 0 auto;
}

.resp-header-banner {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.resp-header-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0 0 2px 0;
}
.resp-header-sub {
  font-size: 10.5px;
  color: var(--color-text-secondary, #2F6F73);
  font-weight: 500;
}

.resp-filter-deck {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 8px 12px;
}
.resp-pill-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.resp-pill-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10.5px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 8px;
  border: 1px solid var(--color-border, #D9E0E3);
  background: var(--color-surface-subtle, #F7F8F7);
  color: var(--color-text, #24313A);
  text-decoration: none;
  transition: all 0.15s ease;
}
.resp-pill-btn:hover {
  background: #EBF3F5;
  color: var(--color-primary, #17324D);
  border-color: var(--color-secondary, #2F6F73);
}
.resp-pill-btn.active {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border-color: var(--color-primary, #17324D);
}
.resp-pill-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 1px 5px;
  font-size: 9px;
  font-weight: 700;
  border-radius: 10px;
  background: rgba(0,0,0,0.08);
}
.resp-pill-btn.active .resp-pill-count {
  background: rgba(255,255,255,0.25);
  color: #FFFFFF;
}

.resp-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.resp-card:hover {
  box-shadow: 0 4px 12px rgba(23, 50, 77, 0.06);
  border-color: #BAC7CC;
}
.resp-card.is-unread {
  background: #FCFDFD;
  border-left: 4px solid var(--color-secondary, #2F6F73);
}
.resp-card.is-urgent {
  border-left: 4px solid #ef4444;
}

.resp-type-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 7px;
  border-radius: 6px;
  letter-spacing: 0.3px;
}
.resp-type-tag.tag-incident {
  background: #FEE2E2;
  color: #991B1B;
}
.resp-type-tag.tag-event {
  background: #E0E7FF;
  color: #3730A3;
}
.resp-type-tag.tag-directive {
  background: #EBF3F5;
  color: var(--color-secondary, #2F6F73);
}

.resp-card-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-primary, #17324D);
  margin: 0 0 6px 0;
  line-height: 1.35;
}
.resp-card-body {
  font-size: 10.5px;
  color: var(--color-text, #24313A);
  line-height: 1.5;
  margin-bottom: 10px;
}
.resp-card-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding-top: 8px;
  border-top: 1px dashed var(--color-border-light, #E7EDF0);
  font-size: 10px;
  color: var(--color-text-muted, #66737D);
}
</style>

<div class="resp-notif-container">

  <!-- Header Banner -->
  <div class="resp-header-banner">
    <div>
      <h1 class="resp-header-title">Brgy. <?= clean($barangayName) ?> Tactical Directives &amp; Alerts</h1>
      <div class="resp-header-sub">
        Field operations feed strictly scoped to Brgy. <?= clean($barangayName) ?> &bull; Disaster Incidents &bull; Readiness Drills
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <?php if ($countUnread > 0): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="markAllResponderRead()" style="font-size:10.5px;padding:5px 12px;display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          Acknowledge All (<?= $countUnread ?>)
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter Deck -->
  <div class="resp-filter-deck">
    <div class="resp-pill-group">
      <a href="notifications.php" class="resp-pill-btn <?= $activeTab === 'all' && empty($filterRead) ? 'active' : '' ?>">
        All Directives <span class="resp-pill-count"><?= $countAll ?></span>
      </a>
      <a href="notifications.php?tab=incidents" class="resp-pill-btn <?= $activeTab === 'incidents' ? 'active' : '' ?>">
        🚨 Disaster Incidents <span class="resp-pill-count"><?= $countIncidents ?></span>
      </a>
      <a href="notifications.php?tab=events" class="resp-pill-btn <?= $activeTab === 'events' ? 'active' : '' ?>">
        📅 Drills &amp; Events <span class="resp-pill-count"><?= $countEvents ?></span>
      </a>
      <?php if ($countUnread > 0): ?>
        <a href="notifications.php?read=unread" class="resp-pill-btn <?= $filterRead === 'unread' ? 'active' : '' ?>" style="color:#C62828;">
          • Pending Acknowledgment <span class="resp-pill-count" style="background:#FDE8E8;color:#C62828;"><?= $countUnread ?></span>
        </a>
      <?php endif; ?>
    </div>

    <!-- Search Form -->
    <form method="GET" action="notifications.php" style="margin:0;display:flex;gap:4px;">
      <input type="hidden" name="tab" value="<?= clean($activeTab) ?>">
      <input type="text" name="q" value="<?= clean($searchQuery) ?>" placeholder="Search directives..." class="form-control" style="font-size:10px;padding:4px 8px;width:150px;border-radius:6px;height:28px;">
      <button type="submit" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 8px;height:28px;">Find</button>
      <?php if (!empty($searchQuery)): ?>
        <a href="notifications.php?tab=<?= clean($activeTab) ?>" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 7px;height:28px;">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Feed -->
  <div style="display:flex;flex-direction:column;gap:10px;">
    <?php if (empty($alerts)): ?>
      <div style="background:#FFF;border:1px dashed var(--color-border);border-radius:10px;padding:42px 20px;text-align:center;">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-muted);margin:0 auto 10px;display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div style="font-size:13px;font-weight:700;color:var(--color-primary);margin-bottom:3px;">No Active Directives</div>
        <div style="font-size:10.5px;color:var(--color-text-secondary);max-width:380px;margin:0 auto;">
          <?php if (!empty($searchQuery)): ?>
            No directives matching "<?= clean($searchQuery) ?>".
          <?php elseif ($activeTab === 'incidents'): ?>
            No active disaster incidents reported in Brgy. <?= clean($barangayName) ?>.
          <?php elseif ($activeTab === 'events'): ?>
            No upcoming responder drills or simulation events currently scheduled.
          <?php else: ?>
            All tactical dispatches have been acknowledged or resolved for this barangay.
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $a): 
        $isRead = !empty($a['is_read']);
        $mod = $a['related_module'] ?? '';
        $titleLower = strtolower($a['title']);
        $isUrgent = in_array(strtolower($a['alert_level'] ?? ''), ['urgent', 'critical']);

        if ($mod === 'disaster_request' || $mod === 'disaster_report' || strpos($titleLower, 'incident') !== false || strpos($titleLower, 'flood') !== false || strpos($titleLower, 'fire') !== false) {
            $tagClass = 'tag-incident';
            $tagIcon = '🚨';
            $tagLabel = 'Disaster Incident';
            $actionLink = BASE_URL . '/views/responder/active-incidents.php';
            $actionText = 'Dispatch Board';
        } elseif ($mod === 'events' || $mod === 'preparedness' || strpos($titleLower, 'event') !== false || strpos($titleLower, 'drill') !== false || strpos($titleLower, 'refresher') !== false) {
            $tagClass = 'tag-event';
            $tagIcon = '📅';
            $tagLabel = 'Drill / Event';
            $actionLink = BASE_URL . '/views/responder/events.php';
            $actionText = 'Event Details';
        } else {
            $tagClass = 'tag-directive';
            $tagIcon = '📋';
            $tagLabel = 'Field Directive';
            $actionLink = BASE_URL . '/views/responder/field-status.php';
            $actionText = 'Field Status';
        }
      ?>
        <div class="resp-card <?= !$isRead ? 'is-unread' : '' ?> <?= $isUrgent ? 'is-urgent' : '' ?>" id="respCard<?= (int)$a['id'] ?>">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <span class="resp-type-tag <?= $tagClass ?>">
                <span><?= $tagIcon ?></span>
                <span><?= $tagLabel ?></span>
              </span>
              <?= renderStatusBadge($a['alert_level']) ?>
              <?php if (!$isRead): ?>
                <span class="badge badge-info" style="font-size:9px;padding:1px 5px;">NEW</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;font-size:10px;color:var(--color-text-muted);">
              <span><?= timeAgo($a['created_at']) ?></span>
              <?php if (!$isRead): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="acknowledgeAlert(<?= (int)$a['id'] ?>, this)" style="padding:2px 7px;font-size:9.5px;">
                  Acknowledge
                </button>
              <?php endif; ?>
            </div>
          </div>

          <h2 class="resp-card-title"><?= clean($a['title']) ?></h2>

          <div class="resp-card-body">
            <?= nl2br(clean($a['message'])) ?>
          </div>

          <div class="resp-card-footer">
            <div>
              <span><strong>Sector:</strong> Brgy. <?= clean($a['barangay_name'] ?? $barangayName) ?></span>
              <span style="color:var(--color-border);">&bull;</span>
              <span><strong>Source:</strong> <?= clean($a['sender_name'] ?: 'Barangay Disaster Operations') ?></span>
            </div>
            <div>
              <a href="<?= $actionLink ?>" class="btn btn-outline btn-sm" style="font-size:9.5px;padding:2px 8px;text-decoration:none;">
                <?= $actionText ?> &rarr;
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
async function acknowledgeAlert(id, btn) {
  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/notifications/mark_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    });
    btn.outerHTML = '<span style="color:var(--color-success);font-weight:600;font-size:10px;">✓ Acknowledged</span>';
    const card = document.getElementById('respCard' + id);
    if (card) {
      card.classList.remove('is-unread');
    }
  } catch (err) {
    console.error(err);
  }
}

function markAllResponderRead() {
  window.location.href = '<?= BASE_URL ?>/backend/functions/notifications/mark_all_read.php?return_url=' + encodeURIComponent(window.location.href);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
