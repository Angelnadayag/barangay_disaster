<?php
// ============================================================================
// Views: ICDRRMO Central Operations Entry Point
// Redirects to first active admin module: Manage User
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';

requireRole('icdrrmo');
header('Location: ' . BASE_URL . '/views/icdrrmo/users.php');
exit;

// Key Metrics
$activeRequestsCount = (int)$db->query("SELECT COUNT(*) FROM disaster_requests WHERE status NOT IN ('Completed', 'Rejected')")->fetchColumn();
$pendingRecsCount = (int)$db->query("SELECT COUNT(*) FROM recommendations WHERE status = 'Pending Review'")->fetchColumn();
$totalStockPacks = (int)$db->query("SELECT SUM(available_quantity) FROM resources WHERE category = 'Relief Goods'")->fetchColumn();
$lowStockItemsCount = (int)$db->query("SELECT COUNT(*) FROM resources WHERE available_quantity <= min_threshold")->fetchColumn();
$activeEvacueesCount = (int)$db->query("SELECT SUM(current_evacuees_count) FROM evacuation_areas WHERE status = 'Open / Active'")->fetchColumn();

// Pending Recommendations
$pendingRecs = $db->query("
    SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.urgency, b.name AS barangay_name, dr.affected_families
    FROM recommendations r
    JOIN disaster_requests dr ON r.disaster_request_id = dr.id
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE r.status = 'Pending Review'
    ORDER BY FIELD(r.priority, 'Critical', 'High', 'Medium', 'Low'), r.created_at DESC
    LIMIT 5
")->fetchAll();

// Urgent Requests
$urgentRequests = $db->query("
    SELECT dr.*, b.name AS barangay_name
    FROM disaster_requests dr
    JOIN barangays b ON dr.barangay_id = b.id
    WHERE dr.status NOT IN ('Completed', 'Rejected')
    ORDER BY FIELD(dr.severity, 'Critical', 'High', 'Moderate', 'Low'), dr.created_at DESC
    LIMIT 5
")->fetchAll();

// Low Stock Items
$lowStockItems = $db->query("
    SELECT * FROM resources
    WHERE available_quantity <= min_threshold
    ORDER BY (available_quantity / min_threshold) ASC
    LIMIT 5
")->fetchAll();
?>

<div class="page-header">
  <div class="page-header-title-wrap">
    <h1>ICDRRMO Central Disaster Operations Dashboard</h1>
    <p class="page-header-desc">Consolidated monitoring of barangay requests, decision-tree recommendations, and central resource inventory.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-secondary">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
      Review Recommendations (<?= $pendingRecsCount ?>)
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php" class="btn btn-outline">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
      Inventory Depot
    </a>
  </div>
</div>

<!-- Central Key Metrics -->
<div class="metrics-grid">
  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Active Disaster Requests</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($activeRequestsCount) ?></div>
    <div class="metric-meta">Across 7 City Barangays</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Pending Recommendations</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
    </div>
    <div class="metric-value" style="color:var(--color-warning);"><?= number_format($pendingRecsCount) ?></div>
    <div class="metric-meta">Awaiting ICDRRMO Human Review</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Relief Food Packs Available</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
    </div>
    <div class="metric-value"><?= number_format($totalStockPacks) ?></div>
    <div class="metric-meta">Standard 3-day NDRRMC buffer</div>
  </div>

  <div class="metric-card">
    <div class="metric-header">
      <span class="metric-label">Active Evacuees Sheltered</span>
      <svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
    </div>
    <div class="metric-value" style="color:var(--color-danger);"><?= number_format($activeEvacueesCount) ?></div>
    <div class="metric-meta">Across Open Evacuation Camps</div>
  </div>
</div>

<!-- Pending Decision Tree Recommendations Requiring Human Review -->
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Pending Decision Tree Resource Recommendations (Human Review Required)</h2>
      <div class="card-subtitle">Transparent algorithmic recommendation based on disaster conditions, population displacement, and warehouse stock.</div>
    </div>
    <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Rec Code</th>
          <th>Disaster & Barangay</th>
          <th>Severity</th>
          <th>Priority</th>
          <th>Confidence</th>
          <th>Model Recommendation Summary</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pendingRecs)): ?>
          <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--color-text-secondary);">All generated Decision Tree recommendations have been reviewed.</td></tr>
        <?php else: ?>
          <?php foreach ($pendingRecs as $rec): ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);">
                <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $rec['id'] ?>"><?= clean($rec['recommendation_code']) ?></a>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);"><?= clean($rec['disaster_type']) ?></div>
                <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($rec['barangay_name']) ?> (<?= $rec['affected_families'] ?> families)</div>
              </td>
              <td><?= renderStatusBadge($rec['severity']) ?></td>
              <td><?= renderStatusBadge($rec['priority']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:600;"><?= number_format($rec['confidence_score'], 1) ?>%</td>
              <td style="max-width:320px;font-size:10px;color:var(--color-text-secondary);line-height:1.3;"><?= clean($rec['recommendation_reason']) ?></td>
              <td><?= renderStatusBadge($rec['status']) ?></td>
              <td>
                <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $rec['id'] ?>" class="btn btn-primary btn-sm">
                  Review & Decide
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Operational Split -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-top:var(--space-4);">
  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <h3 class="card-title">Recent Disaster Requests</h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php" class="btn btn-outline btn-sm">All Requests</a>
    </div>
    <div class="table-responsive" style="border:none;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Tracking #</th>
            <th>Barangay</th>
            <th>Type</th>
            <th>Severity</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($urgentRequests as $req): ?>
            <tr>
              <td style="font-family:var(--font-secondary);font-weight:600;">
                <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php?id=<?= $req['id'] ?>"><?= clean($req['tracking_code']) ?></a>
              </td>
              <td><?= clean($req['barangay_name']) ?></td>
              <td><?= clean($req['disaster_type']) ?></td>
              <td><?= renderStatusBadge($req['severity']) ?></td>
              <td><?= renderStatusBadge($req['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <h3 class="card-title">Inventory Low-Stock Alerts</h3>
      <a href="<?= BASE_URL ?>/views/icdrrmo/resources.php" class="btn btn-outline btn-sm">Depot Management</a>
    </div>
    <div class="table-responsive" style="border:none;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Resource Name</th>
            <th>Available</th>
            <th>Min Threshold</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lowStockItems)): ?>
            <tr><td colspan="4" style="text-align:center;padding:16px;">All central depot items are above safe minimum threshold.</td></tr>
          <?php else: ?>
            <?php foreach ($lowStockItems as $item): ?>
              <tr>
                <td style="font-weight:600;"><?= clean($item['name']) ?></td>
                <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-danger);">
                  <?= number_format($item['available_quantity']) ?> <?= clean($item['unit']) ?>
                </td>
                <td style="font-family:var(--font-secondary);"><?= number_format($item['min_threshold']) ?></td>
                <td><span class="badge badge-danger">Low Stock</span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
