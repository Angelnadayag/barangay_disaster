<?php
// ============================================================================
// Views (Barangay Head): Profile & Account Settings
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$bName = '';
if (!empty($user['barangay_id'])) {
    $bStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
    $bStmt->execute([$user['barangay_id']]);
    $bName = $bStmt->fetchColumn() ?: '';
}

$pageTitle = "Barangay Official Profile";
require_once __DIR__ . '/../layouts/header.php';

$passwordMsg = '';
$passwordError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['change_password'])) {
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
        logSystemEvent('CHANGE_PASSWORD', 'Profile', "User {$user['username']} updated account password.", $user['id'], $user['full_name'], $user['role']);
        $passwordMsg = "Password updated successfully.";
    }
}
?>

  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay Official Profile & Emergency Checklist</h1>
      <p class="page-header-desc">
        Manage your barangay leadership credentials and review standard community readiness protocols.
      </p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
    <div>
      <div class="card" style="margin-bottom:var(--space-4);">
        <div class="card-header">
          <h3 class="card-title">Barangay Official Profile</h3>
          <span class="badge badge-info"><?= clean(formatRoleName($role)) ?></span>
        </div>
        <div class="card-body">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:48px;height:48px;border-radius:10px;background:var(--color-primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;">
              <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div>
              <div style="font-size:13px;font-weight:700;color:var(--color-primary);"><?= clean($user['full_name']) ?></div>
              <div style="font-size:10px;color:var(--color-text-secondary);">@<?= clean($user['username']) ?> • <?= clean($user['email']) ?></div>
              <div style="font-size:9px;color:var(--color-text-muted);margin-top:2px;">
                Jurisdiction: Barangay <?= clean($bName ?: ($user['barangay_name'] ?? 'Assigned')) ?>
              </div>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:10px;background:var(--color-surface-subtle);padding:10px;border-radius:8px;border:1px solid var(--color-border-light);">
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Phone:</span>
              <div style="font-weight:600;"><?= clean($user['phone']) ?></div>
            </div>
            <div>
              <span style="color:var(--color-text-muted);font-size:9px;text-transform:uppercase;">Account Status:</span>
              <div><?= renderStatusBadge($user['status']) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3 class="card-title">Change Password</h3>
        </div>
        <div class="card-body">
          <?php if ($passwordMsg): ?>
            <div style="background:#EAF4EB;border:1px solid #BCE3C1;color:var(--color-success);padding:8px 10px;border-radius:8px;font-size:10px;margin-bottom:10px;">
              <?= clean($passwordMsg) ?>
            </div>
          <?php elseif ($passwordError): ?>
            <div style="background:#FDE8E8;border:1px solid #F8B4B4;color:var(--color-danger);padding:8px 10px;border-radius:8px;font-size:10px;margin-bottom:10px;">
              <?= clean($passwordError) ?>
            </div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="change_password" value="1">
            <div class="form-group">
              <label class="form-label form-label-required">Current Password</label>
              <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="form-group">
              <label class="form-label form-label-required">New Password</label>
              <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required>
            </div>
            <div class="form-group">
              <label class="form-label form-label-required">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:6px;">Update Password</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: 72-Hour Go Bag Guide -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <h3 class="card-title">Barangay Emergency Preparedness Guidelines</h3>
      </div>
      <div class="card-body">
        <div style="font-size:10px;color:var(--color-text-secondary);margin-bottom:12px;line-height:1.4;">
          Barangay Disaster Risk Reduction and Management Committee (BDRRMC) Minimum Compliance Checklist:
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;font-size:10px;">
          <div style="padding:8px 10px;background:var(--color-surface-subtle);border-radius:6px;">
            <strong>1. Early Warning Sirens:</strong> Ensure manual sirens and flood bells are tested weekly along riverbanks.
          </div>
          <div style="padding:8px 10px;background:var(--color-surface-subtle);border-radius:6px;">
            <strong>2. Evacuation Center Readiness:</strong> Inspect water tanks, power generator fuel, and sanitation restrooms.
          </div>
          <div style="padding:8px 10px;background:var(--color-surface-subtle);border-radius:6px;">
            <strong>3. Pre-Emptive Evacuation Triggers:</strong> At River Warning Level 2 (Orange), begin orderly transfer of vulnerable households.
          </div>
          <div style="padding:8px 10px;background:var(--color-surface-subtle);border-radius:6px;">
            <strong>4. Relief Goods Receiving Protocol:</strong> Verify package seals and distribute immediately with signature registry.
          </div>
        </div>
      </div>
    </div>
  </div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
