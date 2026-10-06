<?php
// ============================================================================
// Views (Barangay Head): Connected Agency & Disaster Command Desk
// Shows which BDRRMC and Central ICDRRMO agency this Barangay Head is linked to,
// with station details, emergency hotlines, and station profile editing.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

// Fetch Barangay Info
$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    die("Barangay jurisdiction not found.");
}

// 1. Fetch Connected Local BDRRMC Agency
$aStmt = $db->prepare("SELECT * FROM agencies WHERE barangay_id = ? AND agency_type = 'BDRRMC'");
$aStmt->execute([$barangayId]);
$localAgency = $aStmt->fetch(PDO::FETCH_ASSOC);

// If no agency row exists yet, establish default representation
if (!$localAgency) {
    $localAgency = [
        'id' => 0,
        'name' => 'BDRRMC - ' . $barangay['name'],
        'agency_type' => 'BDRRMC',
        'barangay_id' => $barangayId,
        'contact_person' => $user['full_name'],
        'contact_number' => $user['phone'] ?: ($barangay['contact_number'] ?? 'Emergency Hotline'),
        'email' => 'bdrrmc.' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $barangay['name'])) . '@iligan.gov.ph',
        'office_address' => 'Barangay Disaster Operations Desk, Brgy. ' . $barangay['name'] . ', Iligan City',
        'coordinates_lat' => $barangay['coordinates_lat'] ?? 8.228,
        'coordinates_lng' => $barangay['coordinates_lng'] ?? 124.245,
        'status' => 'active'
    ];
}

// 2. Fetch Central Coordinating Command Agency (ICDRRMO)
$icdStmt = $db->query("SELECT * FROM agencies WHERE agency_type = 'ICDRRMO' LIMIT 1");
$centralAgency = $icdStmt->fetch(PDO::FETCH_ASSOC);

// 3. Count connected responders & resources in this barangay jurisdiction
$rCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'responder' AND barangay_id = ? AND status = 'active'");
$rCountStmt->execute([$barangayId]);
$responderCount = (int)$rCountStmt->fetchColumn();

$resCountStmt = $db->prepare("SELECT COUNT(*) FROM resources WHERE barangay_id = ? AND status = 'active'");
$resCountStmt->execute([$barangayId]);
$resourceCount = (int)$resCountStmt->fetchColumn();

$evacCountStmt = $db->prepare("SELECT COUNT(*) FROM evacuation_areas WHERE barangay_id = ?");
$evacCountStmt->execute([$barangayId]);
$evacCount = (int)$evacCountStmt->fetchColumn();

$pageTitle = "Connected Agency & Operations Desk — Barangay " . $barangay['name'];
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Agency Affiliation & Command Lineage</h1>
      <p class="page-header-desc">
        Operational agency connections, BDRRMC unit credentials, and direct communication links to the Central ICDRRMO Operations Center.
      </p>
    </div>
  </div>

  <!-- Agency Connection Hierarchy Banner -->
  <div style="background:linear-gradient(135deg, #17324D 0%, #2F6F73 100%);color:#FFF;border-radius:12px;padding:22px 26px;margin-bottom:var(--space-4);box-shadow:0 8px 24px rgba(23,50,77,0.18);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:18px;">
      <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:58px;height:58px;border-radius:12px;background:rgba(255,255,255,0.15);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;font-size:26px;">
          🏛️
        </div>
        <div>
          <div style="font-size:10px;text-transform:uppercase;color:#80DEEA;letter-spacing:1px;font-weight:700;">
            Primary Agency Affiliation
          </div>
          <div style="font-size:20px;font-weight:800;margin-top:2px;">
            <?= clean($localAgency['name']) ?>
          </div>
          <div style="font-size:11.5px;color:#E0F2F1;margin-top:2px;">
            Barangay Disaster Risk Reduction and Management Council • <?= clean($barangay['name']) ?> Division
          </div>
        </div>
      </div>

      <div style="display:flex;gap:14px;align-items:center;background:rgba(0,0,0,0.2);padding:10px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.1);">
        <div>
          <span style="font-size:9.5px;color:#B2DFDB;text-transform:uppercase;font-weight:700;display:block;">Central Coordinating HQ</span>
          <span style="font-size:12px;font-weight:700;color:#FFF;">ICDRRMO Central Command</span>
        </div>
        <div style="padding-left:12px;border-left:1px solid rgba(255,255,255,0.2);">
          <span style="font-size:9.5px;color:#B2DFDB;text-transform:uppercase;font-weight:700;display:block;">Emergency Hotline</span>
          <span style="font-size:14px;font-weight:800;color:#4DD0E1;">Dial 161</span>
        </div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:var(--space-4);align-items:start;">
    <!-- Left Column: Local BDRRMC Station Configuration -->
    <div>
      <div class="card" style="margin-bottom:var(--space-4);">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <h3 class="card-title" style="margin:0;font-size:13px;font-weight:700;">Local BDRRMC Station Profile</h3>
            <span style="font-size:10px;color:var(--color-text-muted);">Barangay Operations Unit Information</span>
          </div>
          <span class="badge badge-success">Connected & Active</span>
        </div>

        <div class="card-body">
          <form method="POST" action="<?= BASE_URL ?>/backend/functions/barangay/update_agency.php">
            <input type="hidden" name="return_url" value="<?= clean($_SERVER['REQUEST_URI']) ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Agency Name</label>
                <input type="text" class="form-control" value="<?= clean($localAgency['name']) ?>" disabled>
                <small style="font-size:9px;color:var(--color-text-muted);">Assigned by Iligan City Disaster Council</small>
              </div>
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Agency Classification</label>
                <input type="text" class="form-control" value="BDRRMC (Local Emergency Unit)" disabled>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Station Head / Focal Person *</label>
                <input type="text" name="contact_person" class="form-control" required value="<?= clean($localAgency['contact_person'] ?? $user['full_name']) ?>" placeholder="e.g. Hon. Rodrigo Almonte">
              </div>
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Station Hotline / Emergency Phone *</label>
                <input type="text" name="contact_number" class="form-control" required value="<?= clean($localAgency['contact_number'] ?? $user['phone']) ?>" placeholder="e.g. +63 917 234 5678">
              </div>
            </div>

            <div style="margin-bottom:14px;">
              <label class="form-label" style="font-size:10.5px;font-weight:600;">Official BDRRMC Email</label>
              <input type="email" name="email" class="form-control" value="<?= clean($localAgency['email'] ?? '') ?>" placeholder="e.g. bdrrmc.hinaplanon@iligan.gov.ph">
            </div>

            <div style="margin-bottom:14px;">
              <label class="form-label" style="font-size:10.5px;font-weight:600;">Physical Command Post / Office Address</label>
              <input type="text" name="office_address" class="form-control" value="<?= clean($localAgency['office_address'] ?? '') ?>" placeholder="e.g. Barangay Disaster Operations Desk, Brgy. Hall, Iligan City">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Station Latitude</label>
                <input type="number" step="0.000001" name="coordinates_lat" class="form-control" value="<?= clean($localAgency['coordinates_lat'] ?? $barangay['coordinates_lat']) ?>">
              </div>
              <div>
                <label class="form-label" style="font-size:10.5px;font-weight:600;">Station Longitude</label>
                <input type="number" step="0.000001" name="coordinates_lng" class="form-control" value="<?= clean($localAgency['coordinates_lng'] ?? $barangay['coordinates_lng']) ?>">
              </div>
            </div>

            <div style="display:flex;justify-content:flex-end;">
              <button type="submit" class="btn btn-primary">
                Save Station Details
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Right Column: Higher Command Directory & Jurisdiction Assets -->
    <div>
      <!-- Parent Agency Card: Central ICDRRMO -->
      <div class="card" style="margin-bottom:var(--space-4);border-top:3px solid var(--color-primary);">
        <div class="card-header">
          <h3 class="card-title" style="margin:0;font-size:12.5px;font-weight:700;">Central Command Lineage</h3>
        </div>
        <div class="card-body">
          <div style="display:flex;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;border-radius:10px;background:#E3F2FD;color:#1565C0;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
              🛡️
            </div>
            <div>
              <div style="font-weight:700;font-size:12px;color:var(--color-primary);">
                <?= clean($centralAgency['name'] ?? 'ICDRRMO Iligan City') ?>
              </div>
              <div style="font-size:10px;color:var(--color-text-secondary);margin-top:2px;">
                Lead Disaster Coordinating Authority
              </div>
            </div>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;font-size:10.5px;background:var(--color-surface-subtle);padding:12px;border-radius:8px;border:1px solid var(--color-border-light);">
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;font-weight:700;display:block;">Operations Desk:</span>
              <span style="font-weight:600;"><?= clean($centralAgency['contact_person'] ?? 'Engr. Armando Castillo') ?></span>
            </div>
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;font-weight:700;display:block;">Emergency Hotlines:</span>
              <span style="font-weight:700;color:var(--color-danger);"><?= clean($centralAgency['contact_number'] ?? '161 / (063) 221-1234') ?></span>
            </div>
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;font-weight:700;display:block;">Headquarters Address:</span>
              <span><?= clean($centralAgency['office_address'] ?? 'City Hall Complex, Buhanginan Hill, Pala-o, Iligan City') ?></span>
            </div>
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;font-weight:700;display:block;">Official Email:</span>
              <span><?= clean($centralAgency['email'] ?? 'operations@icdrrmo.iligan.gov.ph') ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Agency Local Resources & Assets Snapshot -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title" style="margin:0;font-size:12.5px;font-weight:700;">Barangay Station Assets</h3>
        </div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#FFF;border:1px solid var(--color-border-light);border-radius:6px;">
              <span style="font-size:11px;font-weight:600;">Registered Responders</span>
              <span class="badge badge-info" style="font-size:11px;font-weight:700;"><?= $responderCount ?> Active</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#FFF;border:1px solid var(--color-border-light);border-radius:6px;">
              <span style="font-size:11px;font-weight:600;">Tracked Inventory Items</span>
              <span class="badge badge-secondary" style="font-size:11px;font-weight:700;"><?= $resourceCount ?> Items</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#FFF;border:1px solid var(--color-border-light);border-radius:6px;">
              <span style="font-size:11px;font-weight:600;">Evacuation Centers</span>
              <span class="badge badge-warning" style="font-size:11px;font-weight:700;"><?= $evacCount ?> Centers</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
