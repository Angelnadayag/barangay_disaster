<?php
// ============================================================================
// Views (Resident): Profile Settings & Security
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$db = getDBConnection();

$passwordMsg = '';
$passwordError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    $chkStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $chkStmt->execute([$user['id']]);
    $currentHash = $chkStmt->fetchColumn();

    if (!password_verify($currentPass, $currentHash)) {
        $passwordError = "Current password does not match.";
    } elseif (strlen($newPass) < 6) {
        $passwordError = "New password must be at least 6 characters.";
    } elseif ($newPass !== $confirmPass) {
        $passwordError = "New passwords do not match.";
    } else {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$newHash, $user['id']]);
        logSystemEvent('CHANGE_PASSWORD', 'Profile', "Resident {$user['username']} updated password.", $user['id'], $user['full_name'], $user['role']);
        $passwordMsg = "Password updated successfully.";
    }
}

// Fetch fresh resident details with barangay name
$userStmt = $db->prepare("
    SELECT u.*, b.name AS barangay_name 
    FROM users u 
    LEFT JOIN barangays b ON u.barangay_id = b.id 
    WHERE u.id = ? 
    LIMIT 1
");
$userStmt->execute([(int)$user['id']]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

$pageTitle = "Profile Settings";
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Resident Profile Settings: Anti-Slop, Borderless Surface Design, Palette (#17324D, #2F6F73) */
.res-profile-wrap {
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
  padding: 4px 2px 24px 2px;
}

.res-profile-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 2px 0;
}

.res-profile-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0;
}

.res-profile-logout {
  background: #FEE2E2;
  color: #DC2626;
  border: none;
  border-radius: 6px;
  padding: 6px 14px;
  font-size: 11.5px;
  font-weight: 600;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: opacity 0.15s ease;
}

.res-profile-logout:hover {
  opacity: 0.85;
}

.res-card-surface {
  background: #FFFFFF;
  border: none;
  border-radius: var(--radius-primary, 10px);
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(16, 35, 54, 0.05);
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.res-user-header {
  display: flex;
  align-items: center;
  gap: 14px;
}

.res-avatar {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  background: #EBF3F5;
  color: var(--color-primary, #17324D);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 20px;
  flex-shrink: 0;
}

.res-user-meta {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.res-user-name {
  font-size: 15.5px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0;
  line-height: 1.25;
}

.res-role-badge {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: var(--color-secondary, #2F6F73);
  background: #EBF3F5;
  padding: 2px 7px;
  border-radius: 4px;
  width: fit-content;
}

.res-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

@media (max-width: 480px) {
  .res-info-grid {
    grid-template-columns: 1fr;
    gap: 10px;
  }
}

.res-info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.res-info-label {
  font-size: 10px;
  font-weight: 600;
  color: var(--color-text-muted, #8A96A0);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.res-info-val {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text, #24313A);
  word-break: break-word;
}

.res-card-title {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  margin: 0;
}

.res-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.res-form-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.res-form-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-secondary, #66737D);
}

.res-form-input {
  background: #F4F6F8;
  border: none;
  border-radius: 6px;
  padding: 8px 12px;
  font-size: 12.5px;
  color: var(--color-text, #24313A);
  outline: none;
  font-family: var(--font-primary);
  transition: background 0.15s ease;
}

.res-form-input:focus {
  background: #EBF3F5;
}

.res-submit-btn {
  background: var(--color-primary, #17324D);
  color: #FFFFFF;
  border: none;
  border-radius: 6px;
  padding: 8px 16px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  align-self: flex-start;
  transition: opacity 0.15s ease;
}

.res-submit-btn:hover {
  opacity: 0.9;
}

.res-alert-success {
  background: #ECFDF5;
  color: #065F46;
  border-radius: 6px;
  padding: 9px 12px;
  font-size: 11.5px;
  font-weight: 500;
}

.res-alert-error {
  background: #FEF2F2;
  color: #991B1B;
  border-radius: 6px;
  padding: 9px 12px;
  font-size: 11.5px;
  font-weight: 500;
}
</style>

<div class="res-profile-wrap">

  <!-- Header Bar -->
  <div class="res-profile-header">
    <h1 class="res-profile-title">Profile Settings</h1>
    <a href="<?= BASE_URL ?>/backend/functions/auth/logout.php" class="res-profile-logout">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
        <polyline points="16 17 21 12 16 7"></polyline>
        <line x1="21" y1="12" x2="9" y2="12"></line>
      </svg>
      <span>Log Out</span>
    </a>
  </div>

  <!-- Resident Information Card -->
  <div class="res-card-surface">
    <div class="res-user-header">
      <div class="res-avatar">
        <?= strtoupper(substr(trim($userData['full_name'] ?? 'R'), 0, 1)) ?>
      </div>
      <div class="res-user-meta">
        <h2 class="res-user-name"><?= clean($userData['full_name']) ?></h2>
        <span class="res-role-badge">Resident</span>
      </div>
    </div>

    <div class="res-info-grid">
      <div class="res-info-item">
        <span class="res-info-label">Username</span>
        <span class="res-info-val"><?= clean($userData['username']) ?></span>
      </div>
      <div class="res-info-item">
        <span class="res-info-label">Mobile Number</span>
        <span class="res-info-val"><?= clean($userData['phone'] ?: '—') ?></span>
      </div>
      <div class="res-info-item">
        <span class="res-info-label">Email</span>
        <span class="res-info-val"><?= clean($userData['email'] ?: '—') ?></span>
      </div>
      <div class="res-info-item">
        <span class="res-info-label">Barangay</span>
        <span class="res-info-val">Brgy. <?= clean($userData['barangay_name'] ?? 'Hinaplanon') ?></span>
      </div>
      <div class="res-info-item">
        <span class="res-info-label">Purok</span>
        <span class="res-info-val"><?= clean($userData['purok'] ?: '—') ?></span>
      </div>
      <?php if (!empty($userData['gender'])): ?>
        <div class="res-info-item">
          <span class="res-info-label">Gender</span>
          <span class="res-info-val"><?= clean($userData['gender']) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($userData['age'])): ?>
        <div class="res-info-item">
          <span class="res-info-label">Age</span>
          <span class="res-info-val"><?= (int)$userData['age'] ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Change Password Card -->
  <div class="res-card-surface">
    <h3 class="res-card-title">Change Password</h3>

    <?php if (!empty($passwordMsg)): ?>
      <div class="res-alert-success"><?= clean($passwordMsg) ?></div>
    <?php elseif (!empty($passwordError)): ?>
      <div class="res-alert-error"><?= clean($passwordError) ?></div>
    <?php endif; ?>

    <form method="POST" class="res-form">
      <div class="res-form-group">
        <label class="res-form-label">Current Password</label>
        <input type="password" name="current_password" class="res-form-input" required autocomplete="current-password">
      </div>
      <div class="res-form-group">
        <label class="res-form-label">New Password</label>
        <input type="password" name="new_password" class="res-form-input" required minlength="6" autocomplete="new-password">
      </div>
      <div class="res-form-group">
        <label class="res-form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" class="res-form-input" required minlength="6" autocomplete="new-password">
      </div>
      <button type="submit" name="change_password" class="res-submit-btn">
        Update Password
      </button>
    </form>
  </div>

</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
