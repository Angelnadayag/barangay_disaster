<?php
// ============================================================================
// Views (Responder): Responder Profile & Unit Readiness
// Tactical responder badge, equipment checklist, and security settings
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$passwordMsg = '';
$passwordError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    $chkStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $chkStmt->execute([$user['id']]);
    $currentHash = $chkStmt->fetchColumn();

    if (!password_verify($currentPass, $currentHash)) {
        $passwordError = "Current password does not match our records.";
    } elseif (strlen($newPass) < 6) {
        $passwordError = "New password must be at least 6 characters long.";
    } elseif ($newPass !== $confirmPass) {
        $passwordError = "New password and confirmation do not match.";
    } else {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$newHash, $user['id']]);
        logSystemEvent('CHANGE_PASSWORD', 'Profile', "Responder {$user['username']} updated password.", $user['id'], $user['full_name'], $user['role']);
        $passwordMsg = "Password updated successfully.";
    }
}

$pageTitle = "Responder Profile & Unit Checklist";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Responder Unit Profile & Tactical Gear</h1>
      <p class="page-header-desc">
        Field unit credentials, emergency VHF radio channels, personal readiness checklist, and account credentials.
      </p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:var(--space-4);align-items:start;">
    <!-- Left Column: Unit Credentials & Security -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Profile Card -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Field Responder Credentials</h2>
        </div>
        <div class="card-body">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--color-border);">
            <div style="width:54px;height:54px;border-radius:12px;background:#e0f2fe;display:flex;align-items:center;justify-content:center;color:#0284c7;font-weight:700;font-size:22px;border:2px solid #bae6fd;">
              <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div>
              <h3 style="font-size:16px;font-weight:700;color:var(--color-text);margin:0 0 4px 0;">
                <?= clean($user['full_name']) ?>
              </h3>
              <div style="font-size:12px;color:var(--color-primary);font-weight:600;">
                Callsign: Alpha-Echo-1 &bull; Rescue Unit #<?= (int)$user['id'] ?>
              </div>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12px;">
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Username</label>
              <div style="font-weight:600;color:var(--color-text);"><?= clean($user['username']) ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Assigned Sector</label>
              <div style="font-weight:600;color:var(--color-text);"><?= clean($user['barangay_name'] ?? 'Iligan City Wide') ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Contact Phone</label>
              <div style="font-weight:600;color:var(--color-text);"><?= clean($user['phone'] ?? '+63 917 555 0192') ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Duty Status</label>
              <span class="badge badge-success" style="font-size:10px;">Active On-Duty</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Password Change -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Security & Password</h2>
        </div>
        <div class="card-body">
          <?php if (!empty($passwordMsg)): ?>
            <div style="background:#ecfdf5;border:1px solid #10b981;color:#065f46;padding:10px 14px;border-radius:6px;font-size:12px;margin-bottom:14px;">
              <?= clean($passwordMsg) ?>
            </div>
          <?php elseif (!empty($passwordError)): ?>
            <div style="background:#fef2f2;border:1px solid #ef4444;color:#991b1b;padding:10px 14px;border-radius:6px;font-size:12px;margin-bottom:14px;">
              <?= clean($passwordError) ?>
            </div>
          <?php endif; ?>

          <form method="POST">
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" style="font-size:12px;">Current Password</label>
              <input type="password" name="current_password" class="form-control" required style="font-size:12px;">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" style="font-size:12px;">New Password</label>
              <input type="password" name="new_password" class="form-control" required minlength="6" style="font-size:12px;">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
              <label class="form-label" style="font-size:12px;">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" required minlength="6" style="font-size:12px;">
            </div>
            <button type="submit" name="change_password" class="btn btn-primary btn-sm">
              Update Password
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right Column: Radio Channels & Gear Checklist -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- VHF Comms Channels -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Emergency Radio Frequencies</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:12px;margin-bottom:0;">
            <thead>
              <tr>
                <th>Channel</th>
                <th>Frequency</th>
                <th>Designation</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>CH 1 (Main)</strong></td>
                <td><code>145.500 MHz</code></td>
                <td>ICDRRMO Command & Dispatch</td>
              </tr>
              <tr>
                <td><strong>CH 2 (Tactical)</strong></td>
                <td><code>146.225 MHz</code></td>
                <td>Riverine Rescue & Search Alpha</td>
              </tr>
              <tr>
                <td><strong>CH 3 (Evac)</strong></td>
                <td><code>144.875 MHz</code></td>
                <td>Barangay Evac Center Coord</td>
              </tr>
              <tr>
                <td><strong>CH 4 (Med)</strong></td>
                <td><code>147.100 MHz</code></td>
                <td>Triage & Emergency Medical</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Rapid Deployment Gear Checklist -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Field Pack Inspection Checklist</h2>
        </div>
        <div class="card-body" style="font-size:12px;">
          <div style="display:flex;flex-direction:column;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" checked>
              <span>VHF Two-Way Radio with spare charged lithium battery</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" checked>
              <span>Type III PFD Life Vest + Whistle + Throw Bag (20m)</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" checked>
              <span>Level 2 First Aid Trauma Kit + Tourniquet</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" checked>
              <span>Tactical Waterproof Headlamp + spare batteries</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" checked>
              <span>Heavy Duty Rescue Gloves & Steel-Toe Boots</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox">
              <span>GPS Handheld Locator / offline topo map cache</span>
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
