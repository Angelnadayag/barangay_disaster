<?php
// ============================================================================
// Views (ICDRRMO): System Audit Trail & Event Logs Module
// Immutable audit record of all administrative, recommendation, and field actions
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "System Audit Logs";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$filterModule = $_GET['module'] ?? '';
$filterRole = $_GET['role'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM system_logs WHERE 1=1";
$params = [];

if (!empty($filterModule)) {
    $sql .= " AND module = ?";
    $params[] = $filterModule;
}

if (!empty($filterRole)) {
    $sql .= " AND role = ?";
    $params[] = $filterRole;
}

if (!empty($search)) {
    $sql .= " AND (user_name LIKE ? OR action LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct modules for filter
$modules = $db->query("SELECT DISTINCT module FROM system_logs ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>System Audit & Event Logs</h1>
      <p class="page-header-desc">
        Immutable operational audit trail recording logins, Decision Tree evaluations, recommendation reviews, and stock transactions.
      </p>
    </div>
    <div class="page-header-actions">
      <span class="badge badge-info" style="font-size:10px;">Audit Integrity: Encrypted & Timestamped</span>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <div class="filter-group">
          <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search user, action, details..." value="<?= clean($search) ?>">
          </div>

          <select name="module" class="form-control" style="width:160px;">
            <option value="">All System Modules</option>
            <?php foreach ($modules as $m): ?>
              <option value="<?= clean($m) ?>" <?= $filterModule === $m ? 'selected' : '' ?>><?= clean($m) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="role" class="form-control" style="width:140px;">
            <option value="">All Roles</option>
            <option value="icdrrmo" <?= $filterRole === 'icdrrmo' ? 'selected' : '' ?>>ICDRRMO Admin</option>
            <option value="barangay_head" <?= $filterRole === 'barangay_head' ? 'selected' : '' ?>>Barangay Head</option>
            <option value="responder" <?= $filterRole === 'responder' ? 'selected' : '' ?>>Responder</option>
            <option value="resident" <?= $filterRole === 'resident' ? 'selected' : '' ?>>Resident</option>
          </select>

          <button type="submit" class="btn btn-outline">Apply Filter</button>
          <?php if (!empty($search) || !empty($filterModule) || !empty($filterRole)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10px;color:var(--color-text-muted);">
            Displaying <?= count($logs) ?> Most Recent Audit Entries
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Logs Table -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>User Identity</th>
          <th>Role</th>
          <th>System Module</th>
          <th>Action Code</th>
          <th>Audit Details</th>
          <th>IP Origin</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="7" style="text-align:center;padding:24px;">No system events match your criteria.</td></tr>
        <?php else: ?>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td style="font-family:var(--font-secondary);font-size:9px;color:var(--color-text-muted);white-space:nowrap;">
                <?= formatDate($log['created_at'], 'Y-m-d H:i:s') ?>
              </td>
              <td style="font-weight:600;color:var(--color-primary);">
                <?= clean($log['user_name']) ?>
              </td>
              <td>
                <span class="badge badge-neutral" style="font-size:8px;">
                  <?= clean(formatRoleName($log['role'])) ?>
                </span>
              </td>
              <td style="font-weight:500;color:var(--color-secondary);">
                <?= clean($log['module']) ?>
              </td>
              <td style="font-family:var(--font-secondary);font-weight:700;font-size:9px;">
                <?= clean($log['action']) ?>
              </td>
              <td style="font-size:10px;color:var(--color-text);max-width:380px;">
                <?= clean($log['details']) ?>
              </td>
              <td style="font-family:var(--font-secondary);font-size:9px;color:var(--color-text-muted);">
                <?= clean($log['ip_address']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
