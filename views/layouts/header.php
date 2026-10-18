<?php
// ============================================================================
// Layout Component: Topbar & HTML Head Shell
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireLogin();
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}
$currentUser = getCurrentUser();
$db = getDBConnection();

// Fetch unread notifications count for this user
$notifCountStmt = $db->prepare("
    SELECT COUNT(*) FROM notifications n
    LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
    WHERE nr.id IS NULL
    AND (
        n.target_role = 'all' 
        OR n.target_role = ? 
        OR (n.target_barangay_id IS NOT NULL AND n.target_barangay_id = ?)
        OR n.target_user_id = ?
    )
");
$notifCountStmt->execute([
    $currentUser['id'],
    $currentUser['role'],
    $currentUser['barangay_id'],
    $currentUser['id']
]);
$unreadCount = (int)$notifCountStmt->fetchColumn();

// Role-based profile link
$userFolder = getRoleFolder($currentUser['role']);
$profileLink = BASE_URL . "/views/{$userFolder}/profile.php";

// Role-based office subtitle for navbar
if (($currentUser['role'] ?? '') === 'barangay_head') {
    $officeSubtitle = 'Barangay Disaster Risk Reduction and Management Committee • Local Government of Iligan';
} elseif (($currentUser['role'] ?? '') === 'icdrrmo') {
    $officeSubtitle = 'Iligan City Disaster Risk Reduction and Management Office • Local Government of Iligan';
} else {
    $officeSubtitle = 'Iligan City Disaster Risk Reduction and Management Office • Local Government of Iligan';
}
$isCitizenOrResponder = in_array($currentUser['role'] ?? '', ['resident', 'responder'], true);
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? clean($pageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/css/app.css?v=<?= filemtime(__DIR__ . '/../../frontend/css/app.css') ?>">
  <!-- Favicon & Touch Icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/frontend/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>/frontend/images/favicon-16x16.png">
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/frontend/images/favicon.png">
  <link rel="shortcut icon" href="<?= BASE_URL ?>/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>/frontend/images/apple-touch-icon.png">
  <script>
    var BASE_URL = <?= json_encode(BASE_URL) ?>;
    if (window.innerWidth > 900 && localStorage.getItem('drrm_sidebar_collapsed') === 'true') {
      document.documentElement.classList.add('sidebar-collapsed');
      document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('sidebar-collapsed');
      });
    }
  </script>
</head>
<body class="<?= $isCitizenOrResponder ? 'citizen-responder-shell' : '' ?>">
<div class="app-shell">
  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <?php if (!$isCitizenOrResponder): ?>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Navigation" title="Toggle Navigation Sidebar">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/views/<?= $userFolder ?>/dashboard.php" class="portal-brand-link" title="Disaster Portal">
          <img src="<?= BASE_URL ?>/frontend/images/logo-secondary.png" alt="Logo" class="portal-brand-logo">
          <div class="portal-brand-text">
            <div class="portal-brand-title"><?= $currentUser['role'] === 'responder' ? 'Tactical Responders' : 'Barangay Safety' ?></div>
            <div class="portal-brand-sub"><?= clean($currentUser['barangay_name'] ?? 'Iligan City') ?></div>
          </div>
        </a>
      <?php endif; ?>
    </div>

    <?php if ($isCitizenOrResponder): ?>
      <nav class="topbar-desktop-nav">
        <a href="<?= BASE_URL ?>/views/<?= $userFolder ?>/dashboard.php" class="topbar-nav-pill <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Home</a>
        <a href="<?= BASE_URL ?>/views/<?= $userFolder ?>/events.php" class="topbar-nav-pill <?= in_array($currentPage, ['events.php', 'preparedness.php'], true) ? 'active' : '' ?>">Events</a>
        <a href="<?= BASE_URL ?>/views/<?= $userFolder ?>/notifications.php" class="topbar-nav-pill <?= $currentPage === 'notifications.php' ? 'active' : '' ?>">Notification</a>
      </nav>
    <?php endif; ?>

    <div class="topbar-right">
      <?php if (!$isCitizenOrResponder): ?>
        <!-- Notification Bell (Admins and Staff Only) -->
        <div style="position:relative;">
          <button class="notif-bell-btn" id="notifBellBtn" title="Notifications & Advisories">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <?php if ($unreadCount > 0): ?>
              <span class="notif-badge" id="topNotifBadge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
          </button>

          <!-- Dropdown Notifications Menu -->
          <div id="notifDropdownMenu" style="display:none;position:absolute;right:0;top:38px;width:320px;background:#FFF;border:1px solid var(--color-border);border-radius:10px;box-shadow:var(--shadow-modal);z-index:1000;overflow:hidden;">
            <div style="padding:8px 12px;background:var(--color-surface-subtle);border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">Recent Advisories & Alerts</span>
              <a href="<?= BASE_URL ?>/views/<?= $userFolder ?>/notifications.php" style="font-size:9px;font-weight:600;color:var(--color-secondary);">View All</a>
            </div>
            <div id="notifDropdownList" style="max-height:260px;overflow-y:auto;padding:6px 0;">
              <div style="padding:12px;text-align:center;font-size:10px;color:var(--color-text-muted);">Loading alerts...</div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- User Profile Dropdown Component -->
      <div class="user-profile-dropdown-wrap" id="userProfileWrap" style="position:relative;display:inline-block;">
        <button type="button" 
                class="user-profile-btn circle-profile-btn" 
                id="userProfileDropdownBtn" 
                onclick="toggleUserDropdown(event)" 
                aria-haspopup="true" 
                aria-expanded="false" 
                title="<?= clean($currentUser['full_name']) ?> — Account & Profile Settings">
          <!-- Circular Avatar Icon -->
          <div class="user-avatar-sm circle-avatar">
            <?php if (!empty($currentUser['image'])): ?>
              <img src="<?= BASE_URL ?>/<?= clean($currentUser['image']) ?>" alt="<?= clean($currentUser['full_name']) ?>">
            <?php else: ?>
              <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
            <?php endif; ?>
          </div>
        </button>

        <!-- Dropdown Menu Box -->
        <div class="user-profile-dropdown-menu" 
             id="userProfileDropdownMenu"
             style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:250px;background:#FFFFFF;border:1px solid #D9E0E3;border-radius:10px;box-shadow:0 12px 32px rgba(23,50,77,0.22);z-index:99999;overflow:hidden;text-align:left;">
          
          <!-- Dropdown Header: User Info Summary -->
          <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#F0F3F4;border-bottom:1px solid #E7EDF0;">
            <div style="width:36px;height:36px;border-radius:8px;background:#17324D;color:#FFF;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;overflow:hidden;flex-shrink:0;">
              <?php if (!empty($currentUser['image'])): ?>
                <img src="<?= BASE_URL ?>/<?= clean($currentUser['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
              <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:11px;font-weight:700;color:#17324D;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= clean($currentUser['full_name']) ?>
              </div>
              <div style="font-size:9.5px;color:#2F6F73;font-weight:600;margin-top:1px;">
                <?= clean(formatRoleName($currentUser['role'])) ?>
              </div>
              <div style="font-size:9px;color:#8A96A0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;">
                <?= clean($currentUser['email'] ?? '') ?>
              </div>
            </div>
          </div>

          <div style="height:1px;background:#E7EDF0;margin:4px 0;"></div>

          <!-- Item 1: Profile Settings -->
          <a href="<?= $profileLink ?>" 
             class="dropdown-item"
             style="display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:11px;font-weight:500;color:#24313A;text-decoration:none;transition:background 0.15s ease;"
             onmouseover="this.style.background='#F0F3F4';this.style.color='#2F6F73'"
             onmouseout="this.style.background='transparent';this.style.color='#24313A'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#66737D;flex-shrink:0;">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span>Profile Settings</span>
          </a>

          <?php if ($currentUser['role'] === 'barangay_head'): ?>
          <!-- Item 2: Agency Information -->
          <a href="<?= BASE_URL ?>/views/barangay/agency.php" 
             class="dropdown-item"
             style="display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:11px;font-weight:500;color:#24313A;text-decoration:none;transition:background 0.15s ease;"
             onmouseover="this.style.background='#F0F3F4';this.style.color='#2F6F73'"
             onmouseout="this.style.background='transparent';this.style.color='#24313A'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#66737D;flex-shrink:0;">
              <path d="M3 21h18"></path>
              <path d="M5 21V7l8-4v18"></path>
              <path d="M19 21V11l-6-3"></path>
              <path d="M9 9v.01"></path>
              <path d="M9 12v.01"></path>
              <path d="M9 15v.01"></path>
              <path d="M9 18v.01"></path>
            </svg>
            <span>Agency Information</span>
          </a>
          <?php endif; ?>

          <div style="height:1px;background:#E7EDF0;margin:4px 0;"></div>

          <!-- Item 2: Logout -->
          <a href="<?= BASE_URL ?>/backend/functions/auth/logout.php" 
             class="dropdown-item dropdown-item-danger"
             style="display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:11px;font-weight:600;color:#C62828;text-decoration:none;transition:background 0.15s ease;"
             onmouseover="this.style.background='#FDE8E8'"
             onmouseout="this.style.background='transparent'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#C62828;flex-shrink:0;">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Logout</span>
          </a>
        </div>
      </div>

      <script>
      function toggleUserDropdown(e) {
        if (e) {
          e.stopPropagation();
          e.preventDefault();
        }
        const menu = document.getElementById('userProfileDropdownMenu');
        const caret = document.getElementById('userProfileCaret');
        const btn = document.getElementById('userProfileDropdownBtn');
        if (!menu) return;
        const isHidden = (menu.style.display === 'none' || menu.style.display === '');
        if (isHidden) {
          const notif = document.getElementById('notifDropdownMenu');
          if (notif) notif.style.display = 'none';
          menu.style.display = 'block';
          if (caret) caret.style.transform = 'rotate(180deg)';
          if (btn) btn.setAttribute('aria-expanded', 'true');
        } else {
          menu.style.display = 'none';
          if (caret) caret.style.transform = 'rotate(0deg)';
          if (btn) btn.setAttribute('aria-expanded', 'false');
        }
      }

      document.addEventListener('click', function(e) {
        const wrap = document.getElementById('userProfileWrap');
        const menu = document.getElementById('userProfileDropdownMenu');
        const caret = document.getElementById('userProfileCaret');
        const btn = document.getElementById('userProfileDropdownBtn');
        if (wrap && menu && !wrap.contains(e.target)) {
          menu.style.display = 'none';
          if (caret) caret.style.transform = 'rotate(0deg)';
          if (btn) btn.setAttribute('aria-expanded', 'false');
        }
      });
      </script>
    </div>
  </header>

  <!-- App Body Layout -->
  <div class="app-container <?= $isCitizenOrResponder ? 'app-container-no-sidebar' : '' ?>">
    <?php if (!$isCitizenOrResponder): ?>
      <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
      <?php require_once __DIR__ . '/sidebar.php'; ?>
    <?php endif; ?>
    <main class="main-content" id="mainContent">
      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div style="margin-bottom:14px;padding:10px 14px;background:var(--color-success-bg, #EAF4EB);border:1px solid var(--color-success-border, #BCE3C1);color:var(--color-success, #2E7D32);border-radius:6px;font-size:12px;font-family:var(--font-primary);display:flex;justify-content:space-between;align-items:center;">
          <span><?= clean($_SESSION['flash_success']) ?></span>
          <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;font-size:16px;line-height:1;padding:0 4px;">&times;</button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div style="margin-bottom:14px;padding:10px 14px;background:var(--color-danger-bg, #FDE8E8);border:1px solid var(--color-danger-border, #F8B4B4);color:var(--color-danger, #C62828);border-radius:6px;font-size:12px;font-family:var(--font-primary);display:flex;justify-content:space-between;align-items:center;">
          <span><?= clean($_SESSION['flash_error']) ?></span>
          <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;font-size:16px;line-height:1;padding:0 4px;">&times;</button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
