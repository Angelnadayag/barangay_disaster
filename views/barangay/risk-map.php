<?php
// ============================================================================
// Views (Barangay Head): Barangay Spatial Hazard & Risk Map
// Focused GIS visualization for the official's specific barangay jurisdiction
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

$allBarangays = $db->query("SELECT * FROM barangays ORDER BY name ASC")->fetchAll();

$puroksStmt = $db->prepare("SELECT * FROM puroks WHERE barangay_id = ? ORDER BY risk_level DESC, name ASC");
$puroksStmt->execute([$barangayId]);
$localPuroks = $puroksStmt->fetchAll();

$evacStmt = $db->prepare("SELECT * FROM evacuation_areas WHERE barangay_id = ?");
$evacStmt->execute([$barangayId]);
$localEvac = $evacStmt->fetchAll();

$incStmt = $db->prepare("SELECT * FROM disaster_requests WHERE barangay_id = ? AND status NOT IN ('Completed', 'Rejected')");
$incStmt->execute([$barangayId]);
$activeIncidents = $incStmt->fetchAll();

$pageTitle = "Hazard Map — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay <?= clean($barangay['name']) ?> Spatial Hazard Map</h1>
      <p class="page-header-desc">
        Local terrain analysis, river catchment proximity, flood inundation zones, and verified safe evacuation paths.
      </p>
    </div>
    <div class="page-header-actions">
      <span class="badge badge-<?= $barangay['risk_level'] === 'Critical' ? 'danger' : 'warning' ?>">
        <?= clean($barangay['risk_level']) ?> Hazard Level
      </span>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-4);align-items:start;">
    <!-- Map Vector Container -->
    <div class="card" style="margin-bottom:0;overflow:hidden;">
      <div class="card-header" style="padding:8px 12px;background:var(--color-surface-subtle);display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:10px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">
          Barangay <?= clean($barangay['name']) ?> Vector Overlay
        </span>
        <span style="font-size:9px;color:var(--color-text-muted);">River Basin & Coastal Buffer</span>
      </div>

      <div style="position:relative;background:#EAF0F4;height:480px;width:100%;overflow:hidden;">
        <svg viewBox="0 0 800 480" style="width:100%;height:100%;">
          <!-- Water & Bay background -->
          <rect width="800" height="480" fill="#E8EEF3"/>
          <path d="M 0 0 L 260 0 C 240 100, 270 200, 230 310 C 200 400, 150 480, 100 480 L 0 480 Z" fill="#D3E2ED" stroke="#B8CFE0" stroke-width="1.5"/>
          <text x="50" y="220" fill="#7E9EB8" font-size="12" font-weight="700" letter-spacing="2" transform="rotate(-40 50 220)">ILIGAN BAY</text>

          <!-- Mandulog River Flowing through Hinaplanon -->
          <path d="M 780 140 Q 600 130 480 150 T 360 170 T 260 160" fill="none" stroke="#6AA5C9" stroke-width="8" stroke-linecap="round"/>
          <path d="M 780 140 Q 600 130 480 150 T 360 170 T 260 160" fill="none" stroke="#A2CCE4" stroke-width="4" stroke-linecap="round"/>

          <!-- Highlighting This Barangay -->
          <polygon points="255,120 380,120 420,180 360,220 240,200 250,150"
                   fill="<?= $barangay['risk_level'] === 'Critical' ? '#FDE8E8' : '#FEF8E8' ?>"
                   stroke="<?= $barangay['risk_level'] === 'Critical' ? '#E05252' : '#D4A12B' ?>"
                   stroke-width="3.5"/>
          
          <text x="290" y="160" font-size="11" font-weight="700" fill="#8A1F1F"><?= clean($barangay['name']) ?></text>
          <text x="288" y="174" font-size="8" font-weight="600" fill="#C62828"><?= strtoupper($barangay['risk_level']) ?> RISK ZONE</text>

          <!-- Local Evacuation Centers -->
          <?php foreach ($localEvac as $idx => $le): ?>
            <?php $cx = 320 + ($idx * 30); $cy = 150 + ($idx * 25); ?>
            <g transform="translate(<?= $cx ?>, <?= $cy ?>)" style="cursor:pointer;">
              <rect x="-8" y="-8" width="16" height="16" rx="4" fill="#17324D" stroke="#FFF" stroke-width="1.5"/>
              <text x="0" y="4" font-size="8" font-weight="bold" fill="#FFF" text-anchor="middle">E<?= $idx + 1 ?></text>
            </g>
          <?php endforeach; ?>

          <!-- Active Incidents Pin -->
          <?php if (!empty($activeIncidents)): ?>
            <g transform="translate(305, 175)">
              <circle cx="0" cy="0" r="12" fill="#C62828" opacity="0.3"/>
              <circle cx="0" cy="0" r="6" fill="#C62828"/>
              <text x="10" y="4" font-size="8" font-weight="700" fill="#C62828">ACTIVE FLOOD INCIDENT</text>
            </g>
          <?php endif; ?>
        </svg>

        <div style="position:absolute;bottom:10px;left:10px;background:rgba(255,255,255,0.94);border:1px solid var(--color-border);border-radius:8px;padding:6px 10px;font-size:9px;">
          <div style="font-weight:700;color:var(--color-primary);margin-bottom:2px;">Legend:</div>
          <div style="display:flex;gap:8px;">
            <span><span style="display:inline-block;width:8px;height:8px;background:#C62828;border-radius:2px;"></span> Critical Zone</span>
            <span><span style="display:inline-block;width:8px;height:8px;background:#17324D;border-radius:2px;"></span> Evacuation Sanctuary</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Purok Risk Breakdown -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Purok Vulnerability Profile</h3>
      </div>
      <div class="card-body">
        <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:12px;">
          High-risk clusters identified by Barangay Disaster Risk Reduction Committee:
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php if (empty($localPuroks)): ?>
            <div style="font-size:10px;color:var(--color-text-muted);">No specific purok risk clusters mapped yet.</div>
          <?php else: ?>
            <?php foreach ($localPuroks as $p): ?>
              <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:8px;padding:8px 10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                  <span style="font-weight:700;font-size:11px;color:var(--color-primary);"><?= clean($p['name']) ?></span>
                  <?= renderStatusBadge($p['risk_level']) ?>
                </div>
                <div style="font-size:9px;color:var(--color-danger);font-weight:600;margin-bottom:4px;">
                  <?= clean($p['hazard_types']) ?>
                </div>
                <div style="font-size:9px;color:var(--color-text-muted);">
                  Households: <strong><?= number_format($p['households_count']) ?></strong> • Population: ~<?= number_format($p['population']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--color-border-light);">
          <a href="<?= BASE_URL ?>/views/barangay/disaster-requests.php?action=new" class="btn btn-primary btn-sm" style="width:100%;">
            File Incident Report for a Purok
          </a>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
