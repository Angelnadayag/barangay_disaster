<?php
// ============================================================================
// Views (Responder): Active Incidents Dispatch Board
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$filterSeverity = $_GET['severity'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name
    FROM disaster_requests dr
    JOIN barangays b ON dr.barangay_id = b.id
    JOIN users u ON dr.submitted_by = u.id
    WHERE dr.status NOT IN ('Completed', 'Rejected')
";
$params = [];

if (!empty($filterSeverity)) {
    $sql .= " AND dr.severity = ?";
    $params[] = $filterSeverity;
}

if (!empty($search)) {
    $sql .= " AND (dr.tracking_code LIKE ? OR dr.purok_name LIKE ? OR b.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(dr.severity, 'Critical', 'High', 'Moderate', 'Low'), dr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

$pageTitle = "Active Incidents Dispatch";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Active Incidents Dispatch Directory</h1>
      <p class="page-header-desc">
        Field situational overview of all reported disaster incidents across Iligan City barangays requiring rescue and resource deployment.
      </p>
    </div>
    <div class="page-header-actions">
      <a href="<?= BASE_URL ?>/views/responder/risk-map.php" class="btn btn-outline">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon></svg>
        Tactical Map View
      </a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <div class="filter-group">
          <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search tracking # or location..." value="<?= clean($search) ?>">
          </div>

          <select name="severity" class="form-control" style="width:130px;">
            <option value="">All Severities</option>
            <option value="Critical" <?= $filterSeverity === 'Critical' ? 'selected' : '' ?>>Critical</option>
            <option value="High" <?= $filterSeverity === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Moderate" <?= $filterSeverity === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
            <option value="Low" <?= $filterSeverity === 'Low' ? 'selected' : '' ?>>Low</option>
          </select>

          <button type="submit" class="btn btn-outline">Filter</button>
          <?php if (!empty($search) || !empty($filterSeverity)): ?>
            <a href="<?= BASE_URL ?>/views/responder/active-incidents.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10px;color:var(--color-text-muted);">
            Active Missions: <?= count($incidents) ?>
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Incident Cards Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(360px, 1fr));gap:var(--space-4);">
    <?php if (empty($incidents)): ?>
      <div class="card" style="grid-column:1/-1;">
        <div class="empty-state">
          <div class="empty-state-title">No Active Incidents</div>
          <div class="empty-state-desc">All reported disaster incidents have been resolved or are currently inactive.</div>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($incidents as $inc): ?>
        <div class="card" style="margin-bottom:0;border-left:4px solid <?= $inc['severity'] === 'Critical' ? 'var(--color-danger)' : ($inc['severity'] === 'High' ? 'var(--color-warning)' : 'var(--color-secondary)') ?>;">
          <div class="card-header">
            <div>
              <span style="font-size:9px;color:var(--color-text-muted);font-family:var(--font-secondary);">
                <?= clean($inc['tracking_code']) ?> • <?= formatDate($inc['created_at'], 'M d, h:i A') ?>
              </span>
              <h3 class="card-title" style="margin-top:2px;">
                <?= clean($inc['disaster_type']) ?> — Barangay <?= clean($inc['barangay_name']) ?>
              </h3>
            </div>
            <div><?= renderStatusBadge($inc['severity']) ?></div>
          </div>

          <div class="card-body">
            <div style="font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:6px;">
              Purok Location: <?= clean($inc['purok_name']) ?>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:6px;margin-bottom:10px;text-align:center;">
              <div style="background:var(--color-surface-subtle);padding:6px;border-radius:6px;">
                <div style="font-size:8px;color:var(--color-text-muted);">Affected Fam.</div>
                <div style="font-size:12px;font-weight:700;color:var(--color-primary);"><?= $inc['affected_families'] ?></div>
              </div>
              <div style="background:var(--color-danger-bg);padding:6px;border-radius:6px;">
                <div style="font-size:8px;color:var(--color-danger);">Displaced</div>
                <div style="font-size:12px;font-weight:700;color:var(--color-danger);"><?= $inc['displaced_families'] ?></div>
              </div>
              <div style="background:var(--color-surface-subtle);padding:6px;border-radius:6px;">
                <div style="font-size:8px;color:var(--color-text-muted);">Casualties</div>
                <div style="font-size:12px;font-weight:700;color:var(--color-primary);"><?= $inc['casualties_count'] ?></div>
              </div>
            </div>

            <div style="font-size:10px;color:var(--color-text-secondary);background:var(--color-surface-subtle);padding:8px 10px;border-radius:6px;margin-bottom:10px;">
              <strong>Situation:</strong> <?= clean($inc['situation_overview'] ?: 'Immediate assistance requested by barangay responders.') ?>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:9px;color:var(--color-text-muted);">Status: <?= clean($inc['status']) ?></span>
              <a href="<?= BASE_URL ?>/views/responder/field-status.php?request_id=<?= $inc['id'] ?>" class="btn btn-primary btn-sm">
                Deploy / Log Status
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
