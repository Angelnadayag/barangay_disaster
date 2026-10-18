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
    FROM events pa 
    WHERE (pa.barangay_id = ? OR pa.barangay_id IS NULL)
      AND pa.start_datetime >= CURDATE()
    ORDER BY pa.start_datetime ASC 
    LIMIT 3
");
$actStmt->execute([$barangayId]);
$upcomingDrills = $actStmt->fetchAll();

$pageTitle = "Community Safety Dashboard";
require_once __DIR__ . '/../layouts/header.php';
?>

  <!-- Page Header -->
  <div class="page-header" style="margin-bottom:12px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div class="page-header-title-wrap">
      <h1 style="font-size:16px;font-weight:700;margin:0;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?> Resident Dashboard</h1>
    </div>
    <div class="page-header-actions">
      <a href="evacuation.php" class="btn btn-primary btn-sm" style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        Evacuation Routing
      </a>
    </div>
  </div>

  <!-- Concise Status Banner (Only if Elevated Risk) -->
  <?php if (($barangay['risk_level'] ?? '') === 'Critical' || ($barangay['risk_level'] ?? '') === 'High'): ?>
    <div style="background:#FFF;border-left:4px solid #ef4444;border-radius:var(--radius-primary, 10px);padding:9px 12px;margin-bottom:var(--space-3);border:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <div style="display:flex;align-items:center;gap:8px;font-size:11px;font-weight:600;color:var(--color-primary);">
        <span style="color:#ef4444;">⚠️</span>
        <span>High Inundation Risk Sector — Monitor Evacuation Alerts</span>
      </div>
      <a href="risk-map.php" class="btn btn-secondary btn-sm" style="font-size:10px;padding:3px 9px;">Map View</a>
    </div>
  <?php endif; ?>

  <!-- Summary Metric Cards (Clean Label & Value Only) -->
  <div class="metrics-grid" style="margin-bottom:var(--space-3);">
    <div class="metric-card">
      <div class="metric-label">My Barangay</div>
      <div class="metric-value" style="font-size:14.5px;">Brgy. <?= clean($barangay['name'] ?? 'Local') ?></div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Open Shelters</div>
      <div class="metric-value" style="font-size:15px;"><?= count($localEvacs) ?> Verified</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Active Advisories</div>
      <div class="metric-value" style="font-size:15px;"><?= count($recentAdvisories) ?> Bulletins</div>
    </div>

    <div class="metric-card">
      <div class="metric-label">Emergency Call Direct</div>
      <div class="metric-value" style="color:var(--color-danger);font-size:15px;">161 (ICDRRMO)</div>
    </div>
  </div>

  <!-- Main Content Split -->
  <div class="responsive-split-grid split-60-40">
    <!-- Left Column: Safe Shelters & Public Advisories -->
    <div style="display:flex;flex-direction:column;gap:var(--space-3);">

      <!-- Evacuation Centers Available (Mobile-Optimized Clean Cards) -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Designated Evacuation Shelters</h2>
          <a href="evacuation.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;">View All &rarr;</a>
        </div>
        <div class="card-body" style="padding:10px 12px;display:flex;flex-direction:column;gap:8px;">
          <?php if (empty($localEvacs)): ?>
            <div style="padding:16px;text-align:center;color:var(--color-text-muted);font-size:10.5px;">
              No open evacuation shelters currently registered for this barangay.
            </div>
          <?php else: ?>
            <?php foreach ($localEvacs as $ev): 
              $cap = (int)$ev['capacity'];
              $cur = (int)$ev['current_evacuees'];
              $pct = $cap > 0 ? round(($cur / $cap) * 100) : 0;
              $barCol = $pct >= 90 ? 'var(--color-danger)' : ($pct >= 75 ? 'var(--color-warning)' : 'var(--color-success)');
              $remaining = max(0, $cap - $cur);
            ?>
              <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:170px;">
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="font-size:12px;color:var(--color-primary);"><?= clean($ev['name']) ?></strong>
                    <?= renderStatusBadge($ev['status']) ?>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                    <div style="flex:1;max-width:130px;background:#D9E0E3;height:5px;border-radius:3px;overflow:hidden;">
                      <div style="width:<?= $pct ?>%;height:100%;background:<?= $barCol ?>;"></div>
                    </div>
                    <span style="font-size:10px;color:var(--color-text-secondary);font-weight:600;">
                      <?= $pct ?>% (<?= number_format($remaining) ?> slots available)
                    </span>
                  </div>
                </div>
                <a href="evacuation.php" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:4px 10px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                  Route
                </a>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Public Safety Advisories -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Official Bulletins</h2>
          <a href="notifications.php" style="font-size:11px;color:var(--color-secondary);font-weight:600;">All Bulletins &rarr;</a>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($recentAdvisories)): ?>
            <div style="padding:16px;text-align:center;color:var(--color-text-muted);font-size:10.5px;">
              No new public advisories at this time.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($recentAdvisories as $adv): ?>
                <div style="padding:10px 14px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                  <div style="display:flex;align-items:center;gap:6px;min-width:0;flex:1;">
                    <?= renderStatusBadge($adv['alert_level']) ?>
                    <span style="font-size:11px;font-weight:600;color:var(--color-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?= clean($adv['title']) ?>
                    </span>
                  </div>
                  <span style="font-size:9.5px;color:var(--color-text-muted);white-space:nowrap;">
                    <?= timeAgo($adv['created_at']) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Right Column: Emergency Contacts & Quick Navigation -->
    <div style="display:flex;flex-direction:column;gap:var(--space-3);">

      <!-- Direct Emergency Directory -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Emergency Hotlines</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:10.5px;margin-bottom:0;">
            <tbody>
              <tr>
                <td style="padding:9px 14px;"><strong>ICDRRMO Command Center</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:161" style="font-weight:700;color:var(--color-danger);">161 / (063) 221-1234</a>
                </td>
              </tr>
              <tr>
                <td style="padding:9px 14px;"><strong>Brgy. Emergency Desk</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:<?= clean($barangay['contact_number'] ?? '09170001111') ?>" style="font-weight:600;color:var(--color-primary);">
                    <?= clean($barangay['contact_number'] ?? '(063) 223-9000') ?>
                  </a>
                </td>
              </tr>
              <tr>
                <td style="padding:9px 14px;"><strong>National Emergency Hotline</strong></td>
                <td style="padding:9px 14px;text-align:right;">
                  <a href="tel:911" style="font-weight:700;color:var(--color-danger);">911</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Quick Portals -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;">
          <h2 class="card-title" style="font-size:14px;font-weight:600;margin:0;">Community Safety Links</h2>
        </div>
        <div class="card-body" style="padding:10px 14px;display:flex;flex-direction:column;gap:6px;">
          <a href="evacuation.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Evacuation Centers &amp; Route Map</span>
            <span>&rarr;</span>
          </a>
          <a href="events.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Preparedness Events &amp; Drills</span>
            <span>&rarr;</span>
          </a>
          <a href="risk-map.php" class="btn btn-outline btn-sm" style="width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;">
            <span>Flood Risk &amp; Hazard Map</span>
            <span>&rarr;</span>
          </a>
        </div>
      </div>

    </div>
  </div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
