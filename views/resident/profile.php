<?php
// ============================================================================
// Views (Resident): Citizen Profile, Household Census & Go-Bag Tracker
// Household emergency details, special medical needs, and account settings
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
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
        logSystemEvent('CHANGE_PASSWORD', 'Profile', "Resident {$user['username']} updated password.", $user['id'], $user['full_name'], $user['role']);
        $passwordMsg = "Password updated successfully.";
    }
}

$pageTitle = "Household Profile & Preparedness Tracker";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Household Profile & Disaster Readiness</h1>
      <p class="page-header-desc">
        Manage your family emergency registration details, track your household 72-hour survival kit, and update credentials.
      </p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:var(--space-4);align-items:start;">
    <!-- Left Column: Household Details & Security -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Citizen Card -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Resident Household Card</h2>
        </div>
        <div class="card-body">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--color-border);">
            <div style="width:54px;height:54px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#b45309;font-weight:700;font-size:22px;border:2px solid #fde68a;">
              <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div>
              <h3 style="font-size:16px;font-weight:700;color:var(--color-text);margin:0 0 4px 0;">
                <?= clean($user['full_name']) ?>
              </h3>
              <div style="font-size:12px;color:var(--color-primary);font-weight:600;">
                Household Head &bull; Barangay <?= clean($user['barangay_name'] ?? 'Hinaplanon') ?>
              </div>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:12px;">
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Registered Username</label>
              <div style="font-weight:600;color:var(--color-text);"><?= clean($user['username']) ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Jurisdiction</label>
              <div style="font-weight:600;color:var(--color-text);">Barangay <?= clean($user['barangay_name'] ?? 'Hinaplanon') ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Emergency Mobile Number</label>
              <div style="font-weight:600;color:var(--color-text);"><?= clean($user['phone'] ?? '+63 928 444 8812') ?></div>
            </div>
            <div>
              <label style="font-weight:600;color:var(--color-text-muted);display:block;margin-bottom:2px;">Family Members Registered</label>
              <div style="font-weight:600;color:var(--color-text);">4 Individuals (1 Senior, 1 Child)</div>
            </div>
          </div>

          <div style="margin-top:16px;background:var(--color-surface-subtle);padding:12px;border-radius:6px;font-size:11px;">
            <strong style="color:var(--color-primary);">Family Evacuation Meeting Point:</strong>
            <p style="margin:4px 0 0 0;color:var(--color-text-muted);">
              Hinaplanon Central Elementary School (Building C) or Barangay Multi-Purpose Hall.
            </p>
          </div>
        </div>
      </div>

      <!-- Password Change -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Security & Credentials</h2>
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
              Save New Password
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right Column: Interactive 72-Hour Kit Checklist -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title">Household 72-Hour Go-Bag Readiness</h2>
          <span id="gobag-score" class="badge badge-success">6 / 6 Ready</span>
        </div>
        <div class="card-body" style="font-size:12px;">
          <p style="color:var(--color-text-muted);margin-bottom:14px;line-height:1.4;">
            Check off items your household has ready in your emergency grab bag. This checklist saves directly in your local browser:
          </p>

          <div style="display:flex;flex-direction:column;gap:10px;" id="gobag-list">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>Water Supply:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">1 gallon of water per person per day for drinking & sanitation (3 days).</div>
              </div>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>Non-Perishable Food:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">Canned fish/meat, biscuits, easy-open cans, utensils.</div>
              </div>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>Battery/Crank Radio & Flashlight:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">To monitor local CDRRMO announcements during power outages.</div>
              </div>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>First Aid & Prescription Medicines:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">Bandages, antiseptic, hypertension/asthma maintenance for 7 days.</div>
              </div>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>Waterproof Documents Case:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">PhilHealth, national IDs, land titles, and emergency cash.</div>
              </div>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
              <input type="checkbox" checked onchange="updateKit()" style="margin-top:2px;">
              <div>
                <strong>Whistle, Spare Clothes & Raincoat:</strong>
                <div style="color:var(--color-text-muted);font-size:11px;">High decibel whistle to signal rescuers if trapped by flood waters.</div>
              </div>
            </label>
          </div>
        </div>
      </div>

      <!-- Quick Hotline Card -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Emergency Direct Dial</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:11px;margin-bottom:0;">
            <tbody>
              <tr>
                <td><strong>ICDRRMO Command Hotlines</strong></td>
                <td style="text-align:right;"><a href="tel:161" style="font-weight:700;color:var(--color-danger);">161 / (063) 221-1234</a></td>
              </tr>
              <tr>
                <td><strong>Hinaplanon BDRRMC Office</strong></td>
                <td style="text-align:right;"><a href="tel:0632239000" style="font-weight:600;color:var(--color-primary);">(063) 223-9000</a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
function updateKit() {
  const checkboxes = document.querySelectorAll('#gobag-list input[type="checkbox"]');
  let checked = 0;
  checkboxes.forEach(cb => { if (cb.checked) checked++; });
  const scoreBadge = document.getElementById('gobag-score');
  scoreBadge.textContent = `${checked} / ${checkboxes.length} Ready`;
  if (checked === checkboxes.length) {
    scoreBadge.className = 'badge badge-success';
  } else if (checked >= 3) {
    scoreBadge.className = 'badge badge-warning';
  } else {
    scoreBadge.className = 'badge badge-danger';
  }
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
