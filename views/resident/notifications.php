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

$userPurok = trim($user['purok'] ?? '');
$hasNotifPuroks = function_exists('ensureNotificationColumns') ? ensureNotificationColumns($db) : false;
$purokExpr = $hasNotifPuroks ? "COALESCE(n.target_puroks, e.target_puroks)" : "e.target_puroks";

// Base query strictly scoped to resident's barangay jurisdiction, created by Barangay Head ONLY,
// and dynamically filtered by target puroks (if all puroks selected -> all residents; if specific puroks -> only residents in those puroks)
$baseWhere = "
    WHERE (
        (n.target_user_id IS NOT NULL AND n.target_user_id = ?)
        OR
        (n.target_user_id IS NULL AND (n.target_barangay_id = ? OR n.target_barangay_id IS NULL) AND n.target_role IN ('all', 'resident'))
    )
    AND (u.role = 'barangay_head' OR n.created_by IN (SELECT id FROM users WHERE role = 'barangay_head' AND barangay_id = ?))
    AND (
        (n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid', 'resources'))
        OR (e.id IS NOT NULL AND (e.is_archived = 0 OR e.is_archived IS NULL))
        OR (n.related_module = 'financial_aid' AND n.target_user_id IS NOT NULL)
    )
    AND (
        n.target_user_id IS NOT NULL
        OR {$purokExpr} IS NULL
        OR TRIM({$purokExpr}) = ''
        OR {$purokExpr} IN ('All Puroks', 'All')
        OR FIND_IN_SET(?, REPLACE({$purokExpr}, ', ', ',')) > 0
    )
";
$baseParams = [(int)$user['id'], $barangayId, $barangayId, $userPurok];

$sql = "
    SELECT n.*, b.name AS barangay_name, u.full_name AS sender_name, nr.id AS is_read
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN events e ON (n.related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND n.related_id = e.id)
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    {$baseWhere}
";
$params = array_merge([(int)$user['id']], $baseParams);

// Strictly 3 categories only: 'financial_aid', 'resources', or 'events'
// related_module is the definitive source of truth; title checks are fallback only when related_module is empty
$condAid = "(
    n.related_module = 'financial_aid' 
    OR (
        (n.related_module IS NULL OR n.related_module = '' OR n.related_module NOT IN ('events', 'event', 'preparedness', 'resources'))
        AND (n.title LIKE '%Financial Aid%' OR n.title LIKE '%Financial Assistance%' OR n.title LIKE '%Cash Assistance%')
    )
)";

$condRes = "(
    n.related_module = 'resources'
    OR (
        (n.related_module IS NULL OR n.related_module = '' OR n.related_module NOT IN ('events', 'event', 'preparedness', 'financial_aid'))
        AND (n.title LIKE '%Relief Goods%' OR n.title LIKE '%Food Assistance%')
    )
)";

$condEvents = "(NOT ($condAid) AND NOT ($condRes))";

if ($filterTab === 'financial_aid') {
    $sql .= " AND {$condAid}";
} elseif ($filterTab === 'resources') {
    $sql .= " AND {$condRes}";
} elseif ($filterTab === 'events') {
    $sql .= " AND {$condEvents}";
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

// Counts per category (strictly matching the exact purok and barangay scope)
$countHelper = function(string $extraClause = '') use ($db, $baseWhere, $baseParams): int {
    $cSql = "
        SELECT COUNT(*)
        FROM notifications n
        LEFT JOIN users u ON n.created_by = u.id
        LEFT JOIN events e ON (n.related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND n.related_id = e.id)
        {$baseWhere}
        {$extraClause}
    ";
    $stmt = $db->prepare($cSql);
    $stmt->execute($baseParams);
    return (int)$stmt->fetchColumn();
};

$countAll = $countHelper();
$countEvents = $countHelper("AND {$condEvents}");
$countAid = $countHelper("AND {$condAid}");
$countRes = $countHelper("AND {$condRes}");

$unreadStmt = $db->prepare("
    SELECT COUNT(*)
    FROM notifications n
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN events e ON (n.related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND n.related_id = e.id)
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    {$baseWhere}
    AND nr.id IS NULL
");
$unreadStmt->execute(array_merge([(int)$user['id']], $baseParams));
$countUnread = (int)$unreadStmt->fetchColumn();

$pageTitle = "Notifications & Advisories";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Resident Notifications: Anti-Slop, Borderless Surface Design, Project Palette (#17324D, #2F6F73) */
.res-notif-wrap {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
  max-width: 680px;
  margin: 0 auto;
}

/* Header */
.res-header-bar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 4px 2px;
}

.res-header-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0;
  line-height: 1.25;
}

.res-header-sub {
  font-size: 11px;
  color: var(--color-text-muted, #8A96A0);
  margin: 3px 0 0 0;
}

.res-mark-all-link {
  background: transparent;
  border: none;
  color: var(--color-secondary, #2F6F73);
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  padding: 2px 0;
  white-space: nowrap;
  text-decoration: none;
  transition: opacity 0.15s ease;
}

.res-mark-all-link:hover {
  opacity: 0.8;
  text-decoration: underline;
}

/* Search Bar (Borderless, Clean) */
.res-search-box {
  background: #FFFFFF;
  border: none;
  border-radius: var(--radius-primary, 10px);
  padding: 6px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 1px 3px rgba(16, 35, 54, 0.04);
}

.res-search-box svg {
  color: var(--color-text-muted, #8A96A0);
  flex-shrink: 0;
}

.res-search-input {
  border: none;
  outline: none;
  background: transparent;
  width: 100%;
  font-size: 11.5px;
  color: var(--color-text, #24313A);
  font-family: var(--font-primary);
}

.res-search-input::placeholder {
  color: var(--color-text-muted, #8A96A0);
}

.res-search-clear {
  color: var(--color-text-muted, #8A96A0);
  text-decoration: none;
  font-size: 15px;
  line-height: 1;
  padding: 0 4px;
}

.res-search-clear:hover {
  color: var(--color-primary, #17324D);
}

/* Horizontal Scrollable Filter Pills (Borderless) */
.res-pills-row {
  display: flex;
  align-items: center;
  gap: 6px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  padding: 2px 0 4px 0;
}

.res-pills-row::-webkit-scrollbar {
  display: none;
}

.res-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 500;
  padding: 6px 12px;
  border-radius: 20px;
  border: none;
  background: #FFFFFF;
  color: var(--color-text-secondary, #66737D);
  text-decoration: none;
  white-space: nowrap;
  box-shadow: 0 1px 2px rgba(16, 35, 54, 0.04);
  transition: background 0.15s ease, color 0.15s ease;
  flex-shrink: 0;
}

.res-pill:hover {
  background: #F0F3F4;
  color: var(--color-primary, #17324D);
}

.res-pill.active {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  font-weight: 600;
}

.res-pill-count {
  font-size: 9.5px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 10px;
  background: #F0F3F4;
  color: var(--color-primary, #17324D);
}

.res-pill.active .res-pill-count {
  background: rgba(255, 255, 255, 0.2);
  color: #FFFFFF;
}

/* Notification Cards (Borderless, Elevated Surface) */
.res-cards-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.res-card {
  background: #FFFFFF;
  border: none;
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05);
  display: flex;
  flex-direction: column;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.res-card.is-unread {
  background: #FFFFFF;
  box-shadow: 0 2px 6px rgba(47, 111, 115, 0.09);
}

.res-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 7px;
  flex-wrap: wrap;
}

.res-card-badges {
  display: flex;
  align-items: center;
  gap: 6px;
}

.res-tag {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: var(--color-secondary, #2F6F73);
  background: #EBF3F5;
  padding: 2.5px 8px;
  border-radius: 4px;
  border: none;
}

.res-unread-badge {
  font-size: 8.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: #FFFFFF;
  background: var(--color-secondary, #2F6F73);
  padding: 2px 6px;
  border-radius: 4px;
  border: none;
}

.res-card-meta {
  display: flex;
  align-items: center;
  gap: 10px;
}

.res-date {
  font-size: 10.5px;
  color: var(--color-text-muted, #8A96A0);
  font-family: var(--font-secondary);
}

.res-read-btn {
  background: transparent;
  border: none;
  color: var(--color-secondary, #2F6F73);
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
  text-decoration: none;
}

.res-read-btn:hover {
  text-decoration: underline;
}

.res-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-primary, #17324D);
  margin: 0 0 6px 0;
  line-height: 1.35;
}

.res-message {
  font-size: 11.5px;
  color: var(--color-text-secondary, #66737D);
  line-height: 1.55;
  margin: 0;
}

.res-link-row {
  margin-top: 8px;
  padding-top: 4px;
}

.res-link {
  font-size: 10.5px;
  color: var(--color-secondary, #2F6F73);
  font-weight: 600;
  text-decoration: none;
}

.res-link:hover {
  text-decoration: underline;
}

.res-empty-box {
  background: #FFFFFF;
  border: none;
  border-radius: var(--radius-primary, 10px);
  padding: 40px 20px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05);
}
</style>

<div class="res-notif-wrap">

  <!-- Clean Minimal Header -->
  <div class="res-header-bar">
    <div>
      <h1 class="res-header-title">Notifications &amp; Advisories</h1>
      <p class="res-header-sub"><?= $countUnread > 0 ? "{$countUnread} unread notifications" : "All notices are up to date" ?></p>
    </div>
    <?php if ($countUnread > 0): ?>
      <button type="button" class="res-mark-all-link" onclick="markAllResidentRead()">
        Mark all read
      </button>
    <?php endif; ?>
  </div>

  <!-- Borderless Search Field -->
  <form method="GET" action="notifications.php" class="res-search-box">
    <input type="hidden" name="tab" value="<?= clean($filterTab) ?>">
    <?php if ($filterUnread): ?><input type="hidden" name="unread" value="1"><?php endif; ?>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="11" cy="11" r="8"></circle>
      <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
    <input type="text" name="q" value="<?= clean($searchQuery) ?>" placeholder="Search notices..." class="res-search-input">
    <?php if (!empty($searchQuery)): ?>
      <a href="notifications.php?tab=<?= clean($filterTab) ?><?= $filterUnread ? '&unread=1' : '' ?>" class="res-search-clear">&times;</a>
    <?php endif; ?>
  </form>

  <!-- Smooth Horizontal Filter Pills (Borderless, Project Palette: Strictly 3 Categories) -->
  <div class="res-pills-row">
    <a href="notifications.php" class="res-pill <?= $filterTab === 'all' && !$filterUnread ? 'active' : '' ?>">
      <span>All</span>
      <span class="res-pill-count"><?= $countAll ?></span>
    </a>
    <a href="notifications.php?tab=events" class="res-pill <?= $filterTab === 'events' ? 'active' : '' ?>">
      <span>Events</span>
      <span class="res-pill-count"><?= $countEvents ?></span>
    </a>
    <a href="notifications.php?tab=financial_aid" class="res-pill <?= $filterTab === 'financial_aid' ? 'active' : '' ?>">
      <span>Financial Aid</span>
      <span class="res-pill-count"><?= $countAid ?></span>
    </a>
    <a href="notifications.php?tab=resources" class="res-pill <?= $filterTab === 'resources' ? 'active' : '' ?>">
      <span>Resources</span>
      <span class="res-pill-count"><?= $countRes ?></span>
    </a>
    <?php if ($countUnread > 0): ?>
      <a href="notifications.php?unread=1" class="res-pill <?= $filterUnread ? 'active' : '' ?>">
        <span>Unread</span>
        <span class="res-pill-count"><?= $countUnread ?></span>
      </a>
    <?php endif; ?>
  </div>

  <!-- Notification Cards (Anti-Slop, Borderless Surface) -->
  <div class="res-cards-list">
    <?php if (empty($alerts)): ?>
      <div class="res-empty-box">
        <div style="font-weight: 600; font-size: 13px; color: var(--color-primary, #17324D); margin-bottom: 4px;">
          No Notifications
        </div>
        <div style="font-size: 11px; color: var(--color-text-muted, #8A96A0);">
          No active notices found matching your selection.
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $a): 
        $isRead = !empty($a['is_read']);
        $mod = $a['related_module'] ?? '';
        $tLower = strtolower($a['title'] . ' ' . ($a['message'] ?? ''));

        // Strictly 3 category badges: Financial Aid, Resources, Event
        if ($mod === 'financial_aid') {
            $tagLabel = 'Financial Aid';
        } elseif ($mod === 'resources') {
            $tagLabel = 'Resources';
        } elseif (in_array($mod, ['events', 'event', 'preparedness'], true)) {
            $tagLabel = 'Event';
        } else {
            // Fallback for unclassified modules
            if (strpos($tLower, 'financial aid') !== false || strpos($tLower, 'financial assistance') !== false || strpos($tLower, 'cash assistance') !== false) {
                $tagLabel = 'Financial Aid';
            } elseif (strpos($tLower, 'relief goods') !== false || strpos($tLower, 'food assistance') !== false || strpos($tLower, 'resource') !== false) {
                $tagLabel = 'Resources';
            } else {
                $tagLabel = 'Event';
            }
        }

        $formattedDate = date('M d, Y • g:i A', strtotime($a['created_at']));
      ?>
        <div class="res-card <?= !$isRead ? 'is-unread' : '' ?>" id="resNotifItem<?= (int)$a['id'] ?>">
          <div class="res-card-top">
            <div class="res-card-badges">
              <span class="res-tag"><?= $tagLabel ?></span>
              <?php if (!$isRead): ?>
                <span class="res-unread-badge">New</span>
              <?php endif; ?>
            </div>
            <div class="res-card-meta">
              <span class="res-date"><?= $formattedDate ?></span>
              <?php if (!$isRead): ?>
                <button type="button" class="res-read-btn" onclick="markResidentRead(<?= (int)$a['id'] ?>, this)">
                  Mark read
                </button>
              <?php endif; ?>
            </div>
          </div>

          <h2 class="res-title"><?= clean($a['title']) ?></h2>

          <p class="res-message">
            <?= nl2br(clean($a['message'])) ?>
          </p>

          <?php if (in_array($mod, ['events', 'preparedness', 'financial_aid', 'resources'], true) || !empty($a['related_id'])): ?>
            <div class="res-link-row">
              <a href="events.php" class="res-link">View Event Schedule &rarr;</a>
            </div>
          <?php endif; ?>
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
    btn.outerHTML = '<span style="color:var(--color-secondary);font-weight:600;font-size:10.5px;">Read</span>';
    const card = document.getElementById('resNotifItem' + id);
    if (card) {
      card.classList.remove('is-unread');
      const badge = card.querySelector('.res-unread-badge');
      if (badge) badge.remove();
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
