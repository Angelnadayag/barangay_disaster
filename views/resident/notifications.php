<?php
// ============================================================================
// Views (Resident): Barangay Notifications, Financial Aid, Resources & Events
// Citizen notices strictly scoped to their barangay jurisdiction
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT name, risk_level FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);
$barangayName = $barangay['name'] ?? 'Local';

// Filter tabs: all, financial_aid, resources, events, advisories
$activeTab = trim($_GET['tab'] ?? 'all');
$filterRead = trim($_GET['read'] ?? ''); // 'unread' or empty
$searchQuery = trim($_GET['q'] ?? '');

// Base query strictly scoped to this resident's barangay ONLY
$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, u.role AS sender_role, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE n.target_barangay_id = ?
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = ?)
";
$params = [$user['id'], $barangayId, $user['id']];

// Category / Module filtering
if ($activeTab === 'financial_aid') {
    $sql .= " AND (n.related_module = 'financial_aid' OR n.title LIKE '%Financial Aid%' OR n.title LIKE '%Ayuda%' OR n.title LIKE '%Cash Assistance%')";
} elseif ($activeTab === 'resources') {
    $sql .= " AND (n.related_module = 'resources' OR n.title LIKE '%Resource%' OR n.title LIKE '%Relief%' OR n.title LIKE '%Supplies%' OR n.title LIKE '%Food Pack%')";
} elseif ($activeTab === 'events') {
    $sql .= " AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Seminar%' OR n.title LIKE '%Workshop%')";
} elseif ($activeTab === 'advisories') {
    $sql .= " AND (n.related_module NOT IN ('financial_aid', 'resources', 'events', 'preparedness') OR n.alert_level IN ('urgent', 'warning', 'Critical', 'Warning'))";
}

// Read status filtering
if ($filterRead === 'unread') {
    $sql .= " AND nr.id IS NULL";
}

// Keyword search
if (!empty($searchQuery)) {
    $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (nr.id IS NULL) DESC, FIELD(n.alert_level, 'urgent', 'warning', 'informational'), n.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts per category for filter pills (strictly scoped to this barangay)
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
      AND (n.related_module = 'resources' OR n.title LIKE '%Resource%' OR n.title LIKE '%Relief%' OR n.title LIKE '%Supplies%' OR n.title LIKE '%Food Pack%')
")->fetchColumn();

$countEvents = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND (n.related_module IN ('events', 'preparedness') OR n.title LIKE '%Event%' OR n.title LIKE '%Drill%' OR n.title LIKE '%Seminar%' OR n.title LIKE '%Workshop%')
")->fetchColumn();

$countUnread = (int)$db->query("
    SELECT COUNT(*) FROM notifications n 
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = {$user['id']}
    WHERE n.target_barangay_id = {$barangayId} 
      AND (n.target_role IN ('all', 'resident') OR n.target_user_id = {$user['id']})
      AND nr.id IS NULL
")->fetchColumn();

$pageTitle = "Brgy. " . clean($barangayName) . " Community Notifications";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Resident Notifications Styling — Anti-Slop, Strict 10px radius, 9-11px typography */
.notif-container {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  max-width: 960px;
  margin: 0 auto;
}

.notif-header-banner {
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
.notif-header-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0 0 2px 0;
}
.notif-header-sub {
  font-size: 10.5px;
  color: var(--color-text-secondary, #2F6F73);
  font-weight: 500;
}

/* Category Filter Deck */
.notif-filter-deck {
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
.notif-pill-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.notif-pill-btn {
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
.notif-pill-btn:hover {
  background: #EBF3F5;
  color: var(--color-primary, #17324D);
  border-color: var(--color-secondary, #2F6F73);
}
.notif-pill-btn.active {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border-color: var(--color-primary, #17324D);
}
.notif-pill-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 1px 5px;
  font-size: 9px;
  font-weight: 700;
  border-radius: 10px;
  background: rgba(0,0,0,0.08);
}
.notif-pill-btn.active .notif-pill-count {
  background: rgba(255,255,255,0.25);
  color: #FFFFFF;
}

/* Notification Feed Items */
.notif-list-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.notif-item-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
  position: relative;
}
.notif-item-card:hover {
  box-shadow: 0 4px 12px rgba(23, 50, 77, 0.06);
  border-color: #BAC7CC;
}
.notif-item-card.is-unread {
  background: #FCFDFD;
  border-left: 4px solid var(--color-secondary, #2F6F73);
}
.notif-item-card.is-urgent {
  border-left: 4px solid #ef4444;
}

.notif-top-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.notif-badges-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.notif-type-tag {
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
.notif-type-tag.tag-financial {
  background: #E8F5E9;
  color: #1B5E20;
}
.notif-type-tag.tag-resources {
  background: #E3F2FD;
  color: #0D47A1;
}
.notif-type-tag.tag-events {
  background: #F3E5F5;
  color: #6A1B9A;
}
.notif-type-tag.tag-advisory {
  background: #FFF3E0;
  color: #E65100;
}

.notif-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-primary, #17324D);
  margin: 0 0 6px 0;
  line-height: 1.35;
}
.notif-body {
  font-size: 10.5px;
  color: var(--color-text, #24313A);
  line-height: 1.5;
  margin-bottom: 10px;
}

.notif-footer-row {
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
.notif-origin-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 500;
}

/* Empty State */
.notif-empty-box {
  background: var(--color-surface, #FFFFFF);
  border: 1px dashed var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 42px 20px;
  text-align: center;
}
</style>

<div class="notif-container">

  <!-- Header Banner -->
  <div class="notif-header-banner">
    <div>
      <h1 class="notif-header-title">Brgy. <?= clean($barangayName) ?> Citizen Announcements</h1>
      <div class="notif-header-sub">
        Notifications strictly scoped to your barangay &bull; Financial Aid &bull; Relief Resources &bull; Community Drills
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <?php if ($countUnread > 0): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="markAllResidentRead()" style="font-size:10.5px;padding:5px 12px;display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          Mark All Read (<?= $countUnread ?>)
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter Deck -->
  <div class="notif-filter-deck">
    <div class="notif-pill-group">
      <a href="notifications.php" class="notif-pill-btn <?= $activeTab === 'all' && empty($filterRead) ? 'active' : '' ?>">
        All Notices <span class="notif-pill-count"><?= $countAll ?></span>
      </a>
      <a href="notifications.php?tab=financial_aid" class="notif-pill-btn <?= $activeTab === 'financial_aid' ? 'active' : '' ?>">
        💰 Financial Aid <span class="notif-pill-count"><?= $countAid ?></span>
      </a>
      <a href="notifications.php?tab=resources" class="notif-pill-btn <?= $activeTab === 'resources' ? 'active' : '' ?>">
        📦 Relief Resources <span class="notif-pill-count"><?= $countRes ?></span>
      </a>
      <a href="notifications.php?tab=events" class="notif-pill-btn <?= $activeTab === 'events' ? 'active' : '' ?>">
        📅 Barangay Events <span class="notif-pill-count"><?= $countEvents ?></span>
      </a>
      <?php if ($countUnread > 0): ?>
        <a href="notifications.php?read=unread" class="notif-pill-btn <?= $filterRead === 'unread' ? 'active' : '' ?>" style="color:#C62828;">
          • Unread Only <span class="notif-pill-count" style="background:#FDE8E8;color:#C62828;"><?= $countUnread ?></span>
        </a>
      <?php endif; ?>
    </div>

    <!-- Keyword Filter -->
    <form method="GET" action="notifications.php" style="margin:0;display:flex;gap:4px;">
      <input type="hidden" name="tab" value="<?= clean($activeTab) ?>">
      <div style="position:relative;">
        <input type="text" name="q" value="<?= clean($searchQuery) ?>" placeholder="Search notices..." class="form-control" style="font-size:10px;padding:4px 8px;width:150px;border-radius:6px;height:28px;">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 8px;height:28px;">Find</button>
      <?php if (!empty($searchQuery)): ?>
        <a href="notifications.php?tab=<?= clean($activeTab) ?>" class="btn btn-outline btn-sm" style="font-size:10px;padding:3px 7px;height:28px;">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Notification Feed -->
  <div class="notif-list-wrap">
    <?php if (empty($notices)): ?>
      <div class="notif-empty-box">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-muted);margin:0 auto 10px;display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div style="font-size:13px;font-weight:700;color:var(--color-primary);margin-bottom:3px;">No Notices in this Category</div>
        <div style="font-size:10.5px;color:var(--color-text-secondary);max-width:380px;margin:0 auto;">
          <?php if (!empty($searchQuery)): ?>
            No notices matching "<?= clean($searchQuery) ?>". Try clearing your keyword filter.
          <?php elseif ($activeTab === 'financial_aid'): ?>
            No financial aid programs or distribution notices announced yet for Brgy. <?= clean($barangayName) ?>.
          <?php elseif ($activeTab === 'resources'): ?>
            No relief resource allocations currently posted for Brgy. <?= clean($barangayName) ?>.
          <?php elseif ($activeTab === 'events'): ?>
            No community preparedness drills or events scheduled for this period.
          <?php else: ?>
            All community safety and assistance notices are up to date for your barangay.
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($notices as $n): 
        $isRead = !empty($n['is_read']);
        $mod = $n['related_module'] ?? '';
        $titleLower = strtolower($n['title']);
        $isUrgent = in_array(strtolower($n['alert_level'] ?? ''), ['urgent', 'critical']);
        
        // Categorize Tag
        if ($mod === 'financial_aid' || strpos($titleLower, 'financial aid') !== false || strpos($titleLower, 'ayuda') !== false || strpos($titleLower, 'cash assistance') !== false) {
            $tagClass = 'tag-financial';
            $tagIcon = '💰';
            $tagLabel = 'Financial Aid';
            $actionLink = BASE_URL . '/views/resident/dashboard.php#residentCalendarWidget';
            $actionText = 'View Details';
        } elseif ($mod === 'resources' || strpos($titleLower, 'resource') !== false || strpos($titleLower, 'relief') !== false || strpos($titleLower, 'supplies') !== false) {
            $tagClass = 'tag-resources';
            $tagIcon = '📦';
            $tagLabel = 'Relief Resources';
            $actionLink = BASE_URL . '/views/resident/dashboard.php#evacuation-routing';
            $actionText = 'Check Shelters';
        } elseif ($mod === 'events' || $mod === 'preparedness' || strpos($titleLower, 'event') !== false || strpos($titleLower, 'drill') !== false) {
            $tagClass = 'tag-events';
            $tagIcon = '📅';
            $tagLabel = 'Barangay Event';
            $actionLink = BASE_URL . '/views/resident/events.php';
            $actionText = 'View Drill & RSVP';
        } else {
            $tagClass = 'tag-advisory';
            $tagIcon = '⚠️';
            $tagLabel = 'Safety Advisory';
            $actionLink = BASE_URL . '/views/resident/dashboard.php#evacuation-routing';
            $actionText = 'Emergency Map';
        }
      ?>
        <div class="notif-item-card <?= !$isRead ? 'is-unread' : '' ?> <?= $isUrgent ? 'is-urgent' : '' ?>" id="notifCard<?= (int)$n['id'] ?>">
          <div class="notif-top-meta">
            <div class="notif-badges-row">
              <span class="notif-type-tag <?= $tagClass ?>">
                <span><?= $tagIcon ?></span>
                <span><?= $tagLabel ?></span>
              </span>
              <?= renderStatusBadge($n['alert_level']) ?>
              <?php if (!$isRead): ?>
                <span class="badge badge-info" style="font-size:9px;padding:1px 5px;">NEW</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;font-size:10px;color:var(--color-text-muted);">
              <span><?= timeAgo($n['created_at']) ?></span>
              <?php if (!$isRead): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="markResidentRead(<?= (int)$n['id'] ?>, this)" style="padding:2px 7px;font-size:9.5px;">
                  Mark Read
                </button>
              <?php endif; ?>
            </div>
          </div>

          <h2 class="notif-title"><?= clean($n['title']) ?></h2>

          <div class="notif-body">
            <?= nl2br(clean($n['message'])) ?>
          </div>

          <div class="notif-footer-row">
            <div class="notif-origin-tag">
              <span><strong>Issued by:</strong> <?= clean($n['sender_name'] ?: 'Barangay Head Hon. Rodrigo Almonte') ?></span>
              <span style="color:var(--color-border);">&bull;</span>
              <span><strong>Barangay:</strong> Brgy. <?= clean($n['barangay_name'] ?? $barangayName) ?></span>
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
async function markResidentRead(id, btn) {
  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/notifications/mark_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    });
    btn.outerHTML = '<span style="color:var(--color-success);font-weight:600;font-size:10px;">✓ Read</span>';
    const card = document.getElementById('notifCard' + id);
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
