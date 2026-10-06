<?php
// ============================================================================
// Views (Resident): Community Disaster Safety Dashboard
// Public citizen portal for localized early warning, nearest shelter, and emergency hotlines
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

// Fetch Resident's Barangay details
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

// Nearest open evacuation centers
$evacStmt = $db->prepare("
    SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
           ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude
    FROM evacuation_areas ea 
    WHERE ea.barangay_id = ? AND ea.status IN ('Open / Active', 'Standby', 'Open')
    ORDER BY ea.status = 'Open / Active' DESC, ea.capacity_individuals DESC
");
$evacStmt->execute([$barangayId]);
$localEvacs = $evacStmt->fetchAll();

// If none in barangay, fetch city-wide open
if (empty($localEvacs)) {
    $evacStmt2 = $db->query("
        SELECT ea.*, ea.capacity_individuals AS capacity, ea.current_evacuees_count AS current_evacuees,
               ea.has_electricity AS has_power, ea.has_potable_water AS has_water, ea.has_medical_station AS has_medical,
               ea.coordinates_lat AS latitude, ea.coordinates_lng AS longitude,
               b.name AS barangay_name 
        FROM evacuation_areas ea 
        JOIN barangays b ON ea.barangay_id = b.id
        WHERE ea.status IN ('Open / Active', 'Open')
        LIMIT 3
    ");
    $localEvacs = $evacStmt2->fetchAll();
}

// Latest Public Advisories
$notifStmt = $db->prepare("
    SELECT n.*, b.name AS barangay_name 
    FROM notifications n
    LEFT JOIN barangays b ON n.target_barangay_id = b.id
    WHERE n.target_role IN ('all', 'resident')
       OR n.target_barangay_id = ?
    ORDER BY n.alert_level = 'Critical' DESC, n.created_at DESC
    LIMIT 4
");
$notifStmt->execute([$barangayId]);
$recentAdvisories = $notifStmt->fetchAll();

// Upcoming Community Drills
$actStmt = $db->prepare("
    SELECT pa.*, pa.start_datetime AS scheduled_date, pa.venue AS location
    FROM preparedness_activities pa 
    WHERE (pa.barangay_id = ? OR pa.barangay_id IS NULL)
      AND pa.start_datetime >= CURDATE()
    ORDER BY pa.start_datetime ASC 
    LIMIT 3
");
$actStmt->execute([$barangayId]);
$upcomingDrills = $actStmt->fetchAll();

$pageTitle = "Community Safety Dashboard";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay <?= clean($barangay['name'] ?? 'Local') ?> Resident Portal</h1>
      <p class="page-header-desc">
        Real-time community safety advisories, evacuation center vacancies, and emergency assistance channels.
      </p>
    </div>
    <div class="page-header-actions">
      <span class="badge badge-<?= ($barangay['risk_level'] ?? '') === 'Critical' ? 'danger' : 'warning' ?>">
        <?= clean($barangay['risk_level'] ?? 'Moderate') ?> Risk Sector
      </span>
      <a href="evacuation.php" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
        Find Evacuation Centers
      </a>
    </div>
  </div>

  <!-- Citizen Alert Banner if High Risk or Active Weather -->
  <div style="background:var(--color-surface);border-left:4px solid var(--color-warning);border-radius:var(--radius-md);padding:14px 18px;margin-bottom:var(--space-4);box-shadow:var(--shadow-sm);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <div>
        <div style="font-weight:700;font-size:13px;color:var(--color-text);">Mandigan River Basin Alert: Code Orange (Heightened Vigilance)</div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px;">Residents near riverbanks and low-lying puroks should secure essential documents and prepare 72-hour family go-bags.</div>
      </div>
    </div>
    <a href="risk-map.php" class="btn btn-secondary btn-sm" style="font-size:11px;">View Safe Elevation Map</a>
  </div>

  <!-- Summary Cards -->
  <div class="metrics-grid" style="grid-template-columns:repeat(4, 1fr);margin-bottom:var(--space-4);">
    <div class="metric-card">
      <div class="metric-label">Barangay Jurisdiction</div>
      <div class="metric-value" style="font-size:18px;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?></div>
      <div class="metric-trend text-muted">Population: <?= number_format($barangay['population'] ?? 15000) ?></div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Designated Evacuation Centers</div>
      <div class="metric-value"><?= count($localEvacs) ?></div>
      <div class="metric-trend text-success">Verified Municipal Shelters</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Active Public Bulletins</div>
      <div class="metric-value"><?= count($recentAdvisories) ?></div>
      <div class="metric-trend text-primary">Issued by ICDRRMO Central</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Emergency Call Direct</div>
      <div class="metric-value" style="color:var(--color-danger);font-size:20px;">161</div>
      <div class="metric-trend text-danger">Toll-Free 24/7 Hotline</div>
    </div>
  </div>

  <!-- Main Content Grid -->
  <div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:var(--space-4);align-items:start;">
    <!-- Left Column: Safe Shelters & Public Advisories -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Evacuation Centers Available -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title">Nearest Designated Evacuation Shelters</h2>
          <a href="evacuation.php" style="font-size:11px;color:var(--color-primary);font-weight:600;">View Directory &rarr;</a>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($localEvacs)): ?>
            <div style="padding:24px;text-align:center;color:var(--color-text-muted);font-size:12px;">
              No open evacuation shelters currently registered for this barangay.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table" style="font-size:12px;margin-bottom:0;">
                <thead>
                  <tr>
                    <th>Facility Name</th>
                    <th>Status</th>
                    <th>Current Occupancy</th>
                    <th>Key Facilities</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($localEvacs as $ev): 
                    $pct = $ev['capacity'] > 0 ? round(($ev['current_evacuees'] / $ev['capacity']) * 100) : 0;
                  ?>
                    <tr>
                      <td>
                        <strong><?= clean($ev['name']) ?></strong>
                        <div style="font-size:10px;color:var(--color-text-muted);">
                          <?= clean($ev['barangay_name'] ?? $barangay['name']) ?>
                        </div>
                      </td>
                      <td><?= renderStatusBadge($ev['status']) ?></td>
                      <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                          <div style="flex:1;background:var(--color-border);height:6px;border-radius:3px;overflow:hidden;min-width:60px;">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 90 ? 'var(--color-danger)' : 'var(--color-success)' ?>;"></div>
                          </div>
                          <span style="font-size:11px;font-weight:600;"><?= $pct ?>%</span>
                        </div>
                        <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;">
                          <?= number_format($ev['current_evacuees']) ?> of <?= number_format($ev['capacity']) ?> slots
                        </div>
                      </td>
                      <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;font-size:10px;">
                          <span class="badge <?= $ev['has_power'] ? 'badge-success' : 'badge-neutral' ?>">Power</span>
                          <span class="badge <?= $ev['has_water'] ? 'badge-success' : 'badge-neutral' ?>">Water</span>
                          <span class="badge <?= $ev['has_medical'] ? 'badge-success' : 'badge-neutral' ?>">Med Station</span>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Public Safety Advisories -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title">Official Bulletins & Weather Warnings</h2>
          <a href="notifications.php" style="font-size:11px;color:var(--color-primary);font-weight:600;">All Advisories &rarr;</a>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($recentAdvisories)): ?>
            <div style="padding:24px;text-align:center;color:var(--color-text-muted);font-size:12px;">
              No new public advisories at this time.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($recentAdvisories as $adv): ?>
                <div style="padding:14px 18px;border-bottom:1px solid var(--color-border);">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <div style="display:flex;align-items:center;gap:6px;">
                      <?= renderStatusBadge($adv['alert_level']) ?>
                      <strong style="font-size:12px;color:var(--color-text);"><?= clean($adv['title']) ?></strong>
                    </div>
                    <span style="font-size:10px;color:var(--color-text-muted);"><?= timeAgo($adv['created_at']) ?></span>
                  </div>
                  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.4;">
                    <?= nl2br(clean($adv['message'])) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right Column: Emergency Helplines & Go-Bag Readiness -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Direct Emergency Directory -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Emergency Contact Directory</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:11px;margin-bottom:0;">
            <tbody>
              <tr>
                <td><strong>ICDRRMO Command Center</strong></td>
                <td style="text-align:right;"><a href="tel:161" style="font-weight:700;color:var(--color-danger);">161 / (063) 221-1234</a></td>
              </tr>
              <tr>
                <td><strong>Brgy. <?= clean($barangay['name'] ?? 'Local') ?> Emergency Desk</strong></td>
                <td style="text-align:right;"><a href="tel:<?= clean($barangay['contact_number'] ?? '09170001111') ?>" style="font-weight:600;color:var(--color-primary);"><?= clean($barangay['contact_number'] ?? '(063) 223-9000') ?></a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 72-Hour Survival Go-Bag Checklist -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title">Family 72-Hour Go-Bag</h2>
          <span class="badge badge-success">Preparedness Guide</span>
        </div>
        <div class="card-body" style="font-size:11px;">
          <p style="color:var(--color-text-muted);margin-bottom:12px;line-height:1.4;">
            Every household should maintain a packed emergency kit ready to grab upon evacuation notice:
          </p>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>Drinking Water:</strong> 1 gallon per person per day (3-day supply)</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>Ready-to-eat Canned Goods:</strong> High calorie, non-perishable</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>First Aid & Prescription Meds:</strong> Antiseptics, bandages, maintenance</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>Flashlight & Portable AM Radio:</strong> Extra AA batteries</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>Waterproof Document Pouch:</strong> IDs, birth certificates, deeds</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="color:var(--color-success);font-weight:bold;">✓</span>
              <span><strong>Emergency Whistle & Power Bank:</strong> Fully charged for cell phone</span>
            </div>
          </div>
          <div style="margin-top:14px;border-top:1px solid var(--color-border);padding-top:10px;">
            <a href="preparedness.php" class="btn btn-secondary btn-sm" style="width:100%;text-align:center;">
              View Community Drills & Schedules
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
