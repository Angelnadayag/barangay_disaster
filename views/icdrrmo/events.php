<?php
// ============================================================================
// Views (ICDRRMO): Barangay Preparedness Activities & Interactive Calendar
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Preparedness Activities";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$viewMode = $_GET['view'] ?? 'list'; // 'month', 'list'
$viewTab = $_GET['tab'] ?? 'active'; // 'active', 'archived'
if (!in_array($viewTab, ['active', 'archived'], true)) {
  $viewTab = 'active';
}

$filterBarangay = (int) ($_GET['barangay_id'] ?? 0);
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$activityTypes = [
  "Health and Medical",
  "Nutrition and Feeding",
  "Cleanliness and Environment",
  "Youth and Children",
  "Education and Skills Training",
  "Social Welfare and Assistance",
  "Livelihood and Employment",
  "Sports and Recreation",
  "Disaster Preparedness and Safety",
  "Animal Welfare",
  "Community Celebrations and Special Events",
  "Government and Community Services",
  "Agricultural Programs"
];

// Query String helper to preserve filters across actions and tabs
function getIcdrrmoEventFilterUrl($newParams = [])
{
  $params = $_GET;
  foreach ($newParams as $k => $v) {
    if ($v === null || $v === '') {
      unset($params[$k]);
    } else {
      $params[$k] = $v;
    }
  }
  return '?' . http_build_query($params);
}

// Auto-sync event statuses: Scheduled events whose starting time has arrived transition to Ongoing
syncEventStatuses($db);

// Count active vs archived events (respecting barangay filter if set)
$countFilterSql = ($filterBarangay > 0) ? " AND barangay_id = " . (int) $filterBarangay : "";
$activeCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE (is_archived = 0 OR is_archived IS NULL)" . $countFilterSql)->fetchColumn();
$archivedCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE is_archived = 1" . $countFilterSql)->fetchColumn();

// Fetch activities based on current tab and filters
$sql = "
    SELECT pa.*, b.name AS barangay_name, u.full_name AS creator_name 
    FROM events pa 
    LEFT JOIN barangays b ON pa.barangay_id = b.id 
    LEFT JOIN users u ON pa.created_by = u.id 
    WHERE 1=1
";
$params = [];

if ($viewTab === 'archived') {
  $sql .= " AND pa.is_archived = 1";
} else {
  $sql .= " AND (pa.is_archived = 0 OR pa.is_archived IS NULL)";
}

if (!empty($filterStatus)) {
  $sql .= " AND pa.status = ?";
  $params[] = $filterStatus;
}

if ($filterBarangay > 0) {
  $sql .= " AND pa.barangay_id = ?";
  $params[] = $filterBarangay;
}

if (!empty($filterType)) {
  $sql .= " AND pa.activity_type = ?";
  $params[] = $filterType;
}

if (!empty($search)) {
  $sql .= " AND (pa.title LIKE ? OR pa.venue LIKE ? OR pa.assigned_personnel LIKE ? OR b.name LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY pa.start_datetime ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
  .nav-tabs {
    display: flex;
    gap: 6px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: var(--space-4, 16px);
    overflow-x: auto;
  }

  .nav-tab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: 11.5px;
    font-weight: 600;
    text-decoration: none;
    color: var(--color-text-secondary);
    border-bottom: 2px solid transparent;
    transition: all 0.15s ease;
    white-space: nowrap;
  }

  .nav-tab-item:hover {
    color: var(--color-primary);
    background-color: var(--color-surface-subtle);
  }

  .nav-tab-item.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-secondary);
    background-color: var(--color-surface);
  }

  .nav-tab-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 9.5px;
    font-weight: 700;
    background-color: var(--color-surface-subtle);
    color: var(--color-text-muted);
  }

  .nav-tab-item.active .nav-tab-badge {
    background-color: var(--color-primary);
    color: #FFF;
  }
</style>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Preparedness Activities of Barangay</h1>
      <p class="page-header-desc">
        Monitor, oversee, and view disaster drills, community seminars, and preparedness campaigns across all barangays.
      </p>
    </div>
    <div class="page-header-actions">
      <div
        style="display:flex;background:var(--color-surface);border:1px solid var(--color-border);border-radius:10px;padding:2px;gap:2px;">
        <a href="<?= getIcdrrmoEventFilterUrl(['view' => 'list']) ?>"
          class="btn btn-sm <?= $viewMode === 'list' ? 'btn-primary' : 'btn-outline' ?>" style="border:none;">List
          View</a>
        <a href="<?= getIcdrrmoEventFilterUrl(['view' => 'month']) ?>"
          class="btn btn-sm <?= $viewMode === 'month' ? 'btn-primary' : 'btn-outline' ?>" style="border:none;">Month
          View</a>
      </div>
    </div>
  </div>

  <!-- Tabs Navigation: Active Events vs Archived Events -->
  <div class="nav-tabs">
    <a href="<?= getIcdrrmoEventFilterUrl(['tab' => 'active']) ?>"
      class="nav-tab-item <?= $viewTab === 'active' ? 'active' : '' ?>">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
        <line x1="16" y1="2" x2="16" y2="6"></line>
        <line x1="8" y1="2" x2="8" y2="6"></line>
        <line x1="3" y1="10" x2="21" y2="10"></line>
      </svg>
      Active Events
      <span class="nav-tab-badge"><?= $activeCount ?></span>
    </a>
    <a href="<?= getIcdrrmoEventFilterUrl(['tab' => 'archived']) ?>"
      class="nav-tab-item <?= $viewTab === 'archived' ? 'active' : '' ?>">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="21 8 21 21 3 21 3 8"></polyline>
        <rect x="1" y="3" width="22" height="5"></rect>
        <line x1="10" y1="12" x2="14" y2="12"></line>
      </svg>
      Archived Events
      <span class="nav-tab-badge"><?= $archivedCount ?></span>
    </a>
  </div>

  <!-- Filters Bar -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar"
        style="margin-bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <input type="hidden" name="tab" value="<?= clean($viewTab) ?>">
        <input type="hidden" name="view" value="<?= clean($viewMode) ?>">

        <div style="flex:1;min-width:180px;">
          <input type="text" name="search" value="<?= clean($search) ?>" class="form-control"
            placeholder="Search by title, venue, personnel..." style="font-size:11.5px;height:34px;">
        </div>

        <div style="min-width:160px;">
          <select name="barangay_id" class="form-control" style="font-size:11.5px;height:34px;">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay === (int) $b['id'] ? 'selected' : '' ?>>
                <?= clean($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="min-width:180px;">
          <select name="type" class="form-control" style="font-size:11.5px;height:34px;">
            <option value="">All Activity Types</option>
            <?php foreach ($activityTypes as $t): ?>
              <option value="<?= clean($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= clean($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="min-width:130px;">
          <select name="status" class="form-control" style="font-size:11.5px;height:34px;">
            <option value="">All Statuses</option>
            <option value="Scheduled" <?= $filterStatus === 'Scheduled' ? 'selected' : '' ?>>Scheduled</option>
            <option value="Ongoing" <?= $filterStatus === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <button type="submit" class="btn btn-outline"
          style="font-size:11.5px;height:34px;padding:0 14px;">Filter</button>
        <?php if (!empty($filterType) || !empty($filterStatus) || !empty($filterBarangay) || !empty($search)): ?>
          <a href="<?= getIcdrrmoEventFilterUrl(['type' => '', 'status' => '', 'barangay_id' => '', 'search' => '']) ?>"
            class="btn btn-outline"
            style="font-size:11.5px;height:34px;padding:0 12px;color:var(--color-text-muted);">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if ($viewMode === 'month'): ?>
    <!-- Month Calendar View -->
    <div class="card">
      <div class="card-header"
        style="background:var(--color-surface-subtle);display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">October 2026 Preparedness Schedule</h3>
        <span style="font-size:10px;color:var(--color-text-muted);">Iligan City DRRM Calendar
          (<?= $viewTab === 'archived' ? 'Archived Records' : 'Active Events' ?>)</span>
      </div>
      <div
        style="display:grid;grid-template-columns:repeat(7, 1fr);background:var(--color-border);gap:1px;border-bottom:1px solid var(--color-border);">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
          <div
            style="background:var(--color-surface-subtle);padding:6px;text-align:center;font-weight:700;font-size:9px;color:var(--color-text-secondary);text-transform:uppercase;">
            <?= $day ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(7, 1fr);background:var(--color-border);gap:1px;">
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span
            style="font-size:9px;color:var(--color-text-muted);">27</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span
            style="font-size:9px;color:var(--color-text-muted);">28</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span
            style="font-size:9px;color:var(--color-text-muted);">29</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span
            style="font-size:9px;color:var(--color-text-muted);">30</span></div>

        <?php for ($d = 1; $d <= 31; $d++): ?>
          <?php
          $dateStr = sprintf('2026-10-%02d', $d);
          $dayActs = array_filter($activities, function ($a) use ($dateStr) {
            return strpos($a['start_datetime'], $dateStr) === 0;
          });
          ?>
          <div
            style="background:#FFF;min-height:85px;padding:4px;display:flex;flex-direction:column;justify-content:space-between;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span
                style="font-size:10px;font-weight:<?= count($dayActs) > 0 ? '700' : '400' ?>;color:<?= count($dayActs) > 0 ? 'var(--color-primary)' : 'var(--color-text-muted)' ?>;"><?= $d ?></span>
              <?php if (count($dayActs) > 0): ?>
                <span class="badge badge-info" style="font-size:7px;padding:1px 3px;"><?= count($dayActs) ?></span>
              <?php endif; ?>
            </div>
            <div style="margin-top:2px;display:flex;flex-direction:column;gap:2px;">
              <?php foreach ($dayActs as $da): ?>
                <a href="<?= BASE_URL ?>/views/icdrrmo/event-details.php?id=<?= $da['id'] ?>"
                  style="text-decoration:none;display:block;" title="View: <?= clean($da['title']) ?>">
                  <div
                    style="background:#EBF3FC;border-left:3px solid var(--color-secondary);padding:2px 4px;border-radius:3px;font-size:8px;line-height:1.2;cursor:pointer;">
                    <strong style="color:var(--color-primary);"><?= clean(substr($da['title'], 0, 22)) ?>...</strong>
                    <div style="font-size:7.5px;color:var(--color-text-muted);">
                      <?= clean($da['barangay_name'] ?: 'City-Wide') ?>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

  <?php else: ?>
    <!-- List View -->
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Schedule Date & Time</th>
            <th>Activity Title</th>
            <th>Activity Type</th>
            <th>Target Barangay & Venue</th>
            <th>Facilitator / Team</th>
            <th>Attendance Ratio</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($activities)): ?>
            <tr>
              <td colspan="8" style="text-align:center;padding:32px;color:var(--color-text-muted);">
                <?= $viewTab === 'archived' ? 'No archived preparedness activities found.' : 'No active preparedness activities found matching your criteria.' ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($activities as $act): ?>
              <?php $isActArchived = !empty($act['is_archived']); ?>
              <tr>
                <td style="font-family:var(--font-secondary);font-size:10px;white-space:nowrap;">
                  <div style="font-weight:600;color:var(--color-primary);"><?= formatDate($act['start_datetime'], 'M d, Y') ?>
                  </div>
                  <div style="font-size:8px;color:var(--color-text-muted);"><?= formatDate($act['start_datetime'], 'h:i A') ?>
                    - <?= formatDate($act['end_datetime'], 'h:i A') ?></div>
                </td>
                <td>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/event-details.php?id=<?= $act['id'] ?>" style="text-decoration:none;"
                    title="View Activity Details">
                    <div style="font-weight:600;color:var(--color-primary);"><?= clean($act['title']) ?></div>
                  </a>
                  <div style="font-size:9px;color:var(--color-text-secondary);max-width:280px;">
                    <?= clean(substr($act['description'] ?? '', 0, 80)) ?>
                    <?= strlen($act['description'] ?? '') > 80 ? '...' : '' ?>
                  </div>
                </td>
                <td><span class="badge badge-info"><?= clean($act['activity_type']) ?></span></td>
                <td>
                  <div style="font-weight:600;color:var(--color-text);"><?= clean($act['barangay_name'] ?: 'City-Wide') ?>
                  </div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($act['venue']) ?></div>
                </td>
                <td style="font-size:10px;color:var(--color-text-secondary);"><?= clean($act['assigned_personnel']) ?></td>
                <td style="font-family:var(--font-secondary);">
                  <?php if ($act['status'] === 'Completed'): ?>
                    <span style="font-weight:700;color:var(--color-success);"><?= (int) $act['actual_participants'] ?> /
                      <?= (int) $act['target_participants'] ?></span>
                  <?php else: ?>
                    <span style="color:var(--color-text-muted);">Target: <?= (int) $act['target_participants'] ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isActArchived): ?>
                    <span class="badge badge-neutral" style="font-size:9.5px;">Archived</span>
                  <?php else: ?>
                    <?= renderStatusBadge($act['status']) ?>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <div style="display:inline-flex;gap:4px;align-items:center;">
                    <!-- View Button -->
                    <a href="<?= BASE_URL ?>/views/icdrrmo/event-details.php?id=<?= $act['id'] ?>"
                      class="btn btn-primary btn-sm"
                      style="font-size:11px;padding:4px 9px;display:inline-flex;align-items:center;gap:3px;text-decoration:none;"
                      title="View Event Details">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                      </svg>
                      View
                    </a>

                    <?php if ($isActArchived): ?>
                      <!-- Restore Button for Archived Events -->
                      <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/restore.php"
                        style="margin:0;display:inline;"
                        onsubmit="return confirm('Restore this archived event back to active?');">
                        <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                        <button type="submit" class="btn btn-outline btn-sm"
                          style="font-size:11px;padding:4px 8px;color:var(--color-success);border-color:var(--color-success);display:inline-flex;align-items:center;gap:3px;"
                          title="Restore Event to Active">
                          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="1 4 1 10 7 10"></polyline>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                          </svg>
                          Restore
                        </button>
                      </form>

                      <!-- Delete Button for Archived Events -->
                      <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/delete.php"
                        style="margin:0;display:inline;"
                        onsubmit="return confirm('Permanently delete this event? This action cannot be undone.');">
                        <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                        <button type="submit" class="btn btn-outline btn-sm"
                          style="font-size:11px;padding:4px 8px;color:#DC2626;border-color:#FCA5A5;display:inline-flex;align-items:center;gap:3px;"
                          title="Permanently Delete Event">
                          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                          </svg>
                          Delete
                        </button>
                      </form>

                    <?php else: ?>
                      <!-- Archive Button for Active Events -->
                      <form method="POST" action="<?= BASE_URL ?>/backend/functions/event/archive.php"
                        style="margin:0;display:inline;" onsubmit="return confirm('Move this event to the archives?');">
                        <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                        <button type="submit" class="btn btn-outline btn-sm"
                          style="font-size:11px;padding:4px 8px;color:#D97706;border-color:#FCD34D;display:inline-flex;align-items:center;gap:3px;"
                          title="Archive Event">
                          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="21 8 21 21 3 21 3 8"></polyline>
                            <rect x="1" y="3" width="22" height="5"></rect>
                            <line x1="10" y1="12" x2="14" y2="12"></line>
                          </svg>
                          Archive
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>