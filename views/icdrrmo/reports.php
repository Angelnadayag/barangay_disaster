<?php
// ============================================================================
// Views (ICDRRMO): Official Government Reports & Audit Documentation Module
// Clean, structured, printable, exportable, anti-slop
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$reportType = $_GET['report'] ?? 'requests';
$filterBarangay = $_GET['barangay'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Handle CSV Export before any output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=DRRM_Report_' . $reportType . '_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');

    if ($reportType === 'requests') {
        fputcsv($output, ['Tracking Code', 'Barangay', 'Purok', 'Disaster Type', 'Severity', 'Urgency', 'Affected Families', 'Displaced Families', 'Status', 'Date Submitted']);
        $q = $db->prepare("
            SELECT dr.tracking_code, b.name AS barangay_name, dr.purok_name, dr.disaster_type,
                   dr.severity, dr.urgency, dr.affected_families, dr.displaced_families, dr.status, dr.created_at
            FROM disaster_requests dr JOIN barangays b ON dr.barangay_id = b.id
            WHERE DATE(dr.created_at) BETWEEN ? AND ?
        ");
        $q->execute([$startDate, $endDate]);
        while ($row = $q->fetch()) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'inventory') {
        fputcsv($output, ['SKU Code', 'Resource Name', 'Category', 'Available', 'In Use', 'Damaged', 'Total', 'Min Threshold', 'Location']);
        $q = $db->query("SELECT code, name, category, available_quantity, in_use_quantity, damaged_quantity, total_quantity, min_threshold, storage_location FROM resources");
        while ($row = $q->fetch()) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'recommendations') {
        fputcsv($output, ['Rec Code', 'Request Code', 'Barangay', 'Disaster', 'Priority', 'Confidence', 'Status', 'Reviewer', 'Date']);
        $q = $db->query("
            SELECT r.recommendation_code, dr.tracking_code, b.name, dr.disaster_type, r.priority, r.confidence_score, r.status, rev.full_name, r.created_at
            FROM recommendations r
            JOIN disaster_requests dr ON r.disaster_request_id = dr.id
            JOIN barangays b ON dr.barangay_id = b.id
            LEFT JOIN users rev ON r.reviewed_by = rev.id
        ");
        while ($row = $q->fetch()) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

$pageTitle = "Official DRRM Reports";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();
?>

<style>
@media print {
  body { background: #FFF !important; }
  .topbar, .sidebar, .page-header-actions, .filter-bar, .report-selector-bar, .no-print { display: none !important; }
  .main-content { padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; margin: 0 !important; }
  .print-header { display: block !important; margin-bottom: 20px; text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; }
  .print-signature-block { display: flex !important; justify-content: space-between; margin-top: 40px; }
}
.print-header { display: none; }
.print-signature-block { display: none; }
</style>

<main class="main-content">
  <!-- Printable Government Header -->
  <div class="print-header">
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;">Republic of the Philippines</div>
    <div style="font-size:12px;font-weight:700;">City Government of Iligan</div>
    <div style="font-size:11px;font-weight:600;color:var(--color-primary);">City Disaster Risk Reduction & Management Office (ICDRRMO)</div>
    <div style="font-size:13px;font-weight:700;margin-top:6px;text-transform:uppercase;">
      <?= $reportType === 'requests' ? 'Disaster Assistance Requests & Incident Log Report' : ($reportType === 'inventory' ? 'Warehouse Stock Inventory & Resource Sufficiency Audit' : ($reportType === 'recommendations' ? 'Decision Tree Resource Allocation Recommendations Audit' : 'Barangay Preparedness Drills & Accomplishment Report')) ?>
    </div>
    <div style="font-size:9px;color:#555;margin-top:2px;">Reporting Period: <?= $startDate ?> to <?= $endDate ?> • Generated on <?= date('F d, Y h:i A') ?></div>
  </div>

  <div class="page-header no-print">
    <div class="page-header-title-wrap">
      <h1>Official Disaster Preparedness Reports</h1>
      <p class="page-header-desc">
        Generate audit-compliant disaster summaries, warehouse stock levels, and recommendation validation logs.
      </p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-outline" onclick="window.print()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
        Print Official Report
      </button>
      <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=<?= clean($reportType) ?>&start_date=<?= clean($startDate) ?>&end_date=<?= clean($endDate) ?>&export=csv" class="btn btn-secondary">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
        Export CSV Data
      </a>
    </div>
  </div>

  <!-- Report Category Selector -->
  <div class="report-selector-bar no-print" style="display:flex;gap:4px;margin-bottom:var(--space-4);border-bottom:1px solid var(--color-border);padding-bottom:var(--space-2);flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=requests" class="btn btn-sm <?= $reportType === 'requests' ? 'btn-primary' : 'btn-outline' ?>">
      Disaster Requests & Incidents
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=inventory" class="btn btn-sm <?= $reportType === 'inventory' ? 'btn-primary' : 'btn-outline' ?>">
      Stock Inventory Audit
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=recommendations" class="btn btn-sm <?= $reportType === 'recommendations' ? 'btn-primary' : 'btn-outline' ?>">
      Decision Tree Recommendations
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=evacuation" class="btn btn-sm <?= $reportType === 'evacuation' ? 'btn-primary' : 'btn-outline' ?>">
      Evacuation Centers Readiness
    </a>
    <a href="<?= BASE_URL ?>/views/icdrrmo/reports.php?report=activities" class="btn btn-sm <?= $reportType === 'activities' ? 'btn-primary' : 'btn-outline' ?>">
      Preparedness Activities
    </a>
  </div>

  <!-- Filters -->
  <div class="card no-print" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <input type="hidden" name="report" value="<?= clean($reportType) ?>">
        <div class="filter-group">
          <label style="font-size:10px;font-weight:600;">Date Range:</label>
          <input type="date" name="start_date" class="form-control" value="<?= clean($startDate) ?>" style="width:130px;height:26px;">
          <span style="font-size:10px;">to</span>
          <input type="date" name="end_date" class="form-control" value="<?= clean($endDate) ?>" style="width:130px;height:26px;">

          <select name="barangay" class="form-control" style="width:140px;height:26px;">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <button type="submit" class="btn btn-outline btn-sm">Filter Report</button>
        </div>
      </form>
    </div>
  </div>

  <!-- REPORT CONTENT TABLE -->
  <?php if ($reportType === 'requests'): ?>
    <?php
      $rSql = "
          SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name
          FROM disaster_requests dr
          JOIN barangays b ON dr.barangay_id = b.id
          JOIN users u ON dr.submitted_by = u.id
          WHERE DATE(dr.created_at) BETWEEN ? AND ?
      ";
      $rParams = [$startDate, $endDate];
      if (!empty($filterBarangay)) {
          $rSql .= " AND dr.barangay_id = ?";
          $rParams[] = $filterBarangay;
      }
      $rSql .= " ORDER BY dr.created_at DESC";
      $rStmt = $db->prepare($rSql);
      $rStmt->execute($rParams);
      $repRequests = $rStmt->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Tracking #</th>
            <th>Barangay & Purok</th>
            <th>Disaster</th>
            <th>Severity</th>
            <th>Urgency</th>
            <th>Families</th>
            <th>Displaced</th>
            <th>Injuries / Casualties</th>
            <th>Status</th>
            <th>Date Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($repRequests)): ?>
            <tr><td colspan="10" style="text-align:center;padding:24px;">No disaster requests in this period.</td></tr>
          <?php else: ?>
            <?php foreach ($repRequests as $r): ?>
              <tr>
                <td style="font-weight:600;font-family:var(--font-secondary);"><?= clean($r['tracking_code']) ?></td>
                <td><?= clean($r['barangay_name']) ?> (<?= clean($r['purok_name']) ?>)</td>
                <td><?= clean($r['disaster_type']) ?></td>
                <td><?= renderStatusBadge($r['severity']) ?></td>
                <td><?= renderStatusBadge($r['urgency']) ?></td>
                <td style="font-family:var(--font-secondary);font-weight:600;"><?= $r['affected_families'] ?></td>
                <td style="font-family:var(--font-secondary);color:var(--color-danger);"><?= $r['displaced_families'] ?></td>
                <td style="font-family:var(--font-secondary);"><?= $r['injuries_count'] ?> inj. / <?= $r['casualties_count'] ?> cas.</td>
                <td><?= renderStatusBadge($r['status']) ?></td>
                <td style="font-size:9px;"><?= formatDate($r['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($reportType === 'inventory'): ?>
    <?php
      $repInv = $db->query("SELECT * FROM resources ORDER BY category ASC, name ASC")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>SKU Code</th>
            <th>Resource Description</th>
            <th>Category</th>
            <th>Available</th>
            <th>In-Use</th>
            <th>Damaged</th>
            <th>Total Stock</th>
            <th>Min Safety Threshold</th>
            <th>Sufficiency Assessment</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repInv as $ri): ?>
            <?php $isLow = ($ri['available_quantity'] <= $ri['min_threshold']); ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);"><?= clean($ri['code']) ?></td>
              <td style="font-weight:600;color:var(--color-primary);"><?= clean($ri['name']) ?></td>
              <td><?= clean($ri['category']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;color:<?= $isLow ? 'var(--color-danger)' : 'var(--color-primary)' ?>;">
                <?= number_format($ri['available_quantity']) ?> <?= clean($ri['unit']) ?>
              </td>
              <td style="font-family:var(--font-secondary);"><?= number_format($ri['in_use_quantity']) ?></td>
              <td style="font-family:var(--font-secondary);"><?= number_format($ri['damaged_quantity']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:600;"><?= number_format($ri['total_quantity']) ?></td>
              <td style="font-family:var(--font-secondary);"><?= number_format($ri['min_threshold']) ?></td>
              <td>
                <span class="badge <?= $isLow ? 'badge-danger' : 'badge-success' ?>">
                  <?= $isLow ? 'Stock Deficit Warning' : 'Adequate Buffer' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($reportType === 'recommendations'): ?>
    <?php
      $repRec = $db->query("
          SELECT r.*, dr.tracking_code, dr.disaster_type, dr.severity, dr.affected_families,
                 b.name AS barangay_name, rev.full_name AS reviewer_name
          FROM recommendations r
          JOIN disaster_requests dr ON r.disaster_request_id = dr.id
          JOIN barangays b ON dr.barangay_id = b.id
          LEFT JOIN users rev ON r.reviewed_by = rev.id
          ORDER BY r.created_at DESC
      ")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Rec Code</th>
            <th>Disaster Request</th>
            <th>Barangay</th>
            <th>Disaster Profile</th>
            <th>Priority</th>
            <th>Confidence</th>
            <th>Status</th>
            <th>Reviewer</th>
            <th>Review Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repRec as $rr): ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);"><?= clean($rr['recommendation_code']) ?></td>
              <td><?= clean($rr['tracking_code']) ?></td>
              <td style="font-weight:600;"><?= clean($rr['barangay_name']) ?></td>
              <td><?= clean($rr['disaster_type']) ?> (<?= clean($rr['severity']) ?>)</td>
              <td><?= renderStatusBadge($rr['priority']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;"><?= number_format($rr['confidence_score'], 1) ?>%</td>
              <td><?= renderStatusBadge($rr['status']) ?></td>
              <td><?= clean($rr['reviewer_name'] ?: 'Pending Review') ?></td>
              <td style="font-size:9px;"><?= formatDate($rr['reviewed_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($reportType === 'evacuation'): ?>
    <?php
      $repEvac = $db->query("
          SELECT ea.*, b.name AS barangay_name 
          FROM evacuation_areas ea 
          JOIN barangays b ON ea.barangay_id = b.id
          ORDER BY b.name ASC, ea.name ASC
      ")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Center Name</th>
            <th>Barangay & Address</th>
            <th>Facility Type</th>
            <th>Capacity (Indiv.)</th>
            <th>Capacity (Families)</th>
            <th>Current Sheltered</th>
            <th>Occupancy %</th>
            <th>Status</th>
            <th>Accessibility</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repEvac as $re): ?>
            <?php $pct = $re['capacity_individuals'] > 0 ? round(($re['current_evacuees_count'] / $re['capacity_individuals']) * 100, 1) : 0; ?>
            <tr>
              <td style="font-weight:600;color:var(--color-primary);"><?= clean($re['name']) ?></td>
              <td><?= clean($re['barangay_name']) ?> — <?= clean($re['location_address']) ?></td>
              <td><?= clean($re['center_type']) ?></td>
              <td style="font-family:var(--font-secondary);"><?= number_format($re['capacity_individuals']) ?></td>
              <td style="font-family:var(--font-secondary);"><?= number_format($re['capacity_families']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-danger);"><?= number_format($re['current_evacuees_count']) ?></td>
              <td style="font-family:var(--font-secondary);font-weight:600;"><?= $pct ?>%</td>
              <td><?= renderStatusBadge($re['status']) ?></td>
              <td style="font-size:9px;"><?= clean($re['accessibility']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($reportType === 'activities'): ?>
    <?php
      $repAct = $db->query("
          SELECT pa.*, b.name AS barangay_name 
          FROM preparedness_activities pa 
          LEFT JOIN barangays b ON pa.barangay_id = b.id
          ORDER BY pa.start_datetime DESC
      ")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Activity Title</th>
            <th>Type</th>
            <th>Venue & Barangay</th>
            <th>Facilitator</th>
            <th>Target</th>
            <th>Actual Attended</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repAct as $ra): ?>
            <tr>
              <td style="font-family:var(--font-secondary);font-size:9px;"><?= formatDate($ra['start_datetime'], 'M d, Y') ?></td>
              <td style="font-weight:600;color:var(--color-primary);"><?= clean($ra['title']) ?></td>
              <td><?= clean($ra['activity_type']) ?></td>
              <td><?= clean($ra['venue']) ?> (<?= clean($ra['barangay_name'] ?: 'City-Wide') ?>)</td>
              <td><?= clean($ra['assigned_personnel']) ?></td>
              <td style="font-family:var(--font-secondary);"><?= $ra['target_participants'] ?></td>
              <td style="font-family:var(--font-secondary);font-weight:700;color:var(--color-success);"><?= $ra['actual_participants'] ?></td>
              <td><?= renderStatusBadge($ra['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- Official Signature Section (For Printed Reports) -->
  <div class="print-signature-block">
    <div style="text-align:center;">
      <div style="font-size:10px;color:#555;">Prepared & Certified By:</div>
      <div style="margin-top:40px;border-top:1px solid #000;width:200px;margin-left:auto;margin-right:auto;padding-top:4px;">
        <div style="font-weight:700;font-size:11px;">ENGR. ARMANDO CASTILLO</div>
        <div style="font-size:9px;color:#555;">City DRRM Officer • ICDRRMO Iligan</div>
      </div>
    </div>

    <div style="text-align:center;">
      <div style="font-size:10px;color:#555;">Approved & Noted By:</div>
      <div style="margin-top:40px;border-top:1px solid #000;width:200px;margin-left:auto;margin-right:auto;padding-top:4px;">
        <div style="font-weight:700;font-size:11px;">HON. FREDERICK W. SIAO</div>
        <div style="font-size:9px;color:#555;">City Mayor • Chairperson, ICDRRMC</div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
