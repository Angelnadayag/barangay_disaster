<?php
// ============================================================================
// Layout Component: User Role-Aware Sidebar Navigation
// Routes to role-specific directories (icdrrmo, barangay, responder, resident)
// ============================================================================

require_once __DIR__ . '/../../backend/services/Auth.php';
$user = getCurrentUser();
$role = $user['role'];

// Residents and Responders use mobile-first app shell without sidebar
if ($role === 'resident' || $role === 'responder') {
    return;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$db = getDBConnection();

$pendingReqs = 0;
$pendingRecs = 0;
$lowStockCount = 0;
$localLowStockCount = 0;

if ($role === 'icdrrmo' || $role === 'barangay_head') {
    $reqSql = ($role === 'barangay_head') 
        ? "SELECT COUNT(*) FROM disaster_reports WHERE status = 'Ongoing' AND barangay_id = ?"
        : "SELECT COUNT(*) FROM disaster_reports WHERE status = 'Ongoing'";
    $reqStmt = $db->prepare($reqSql);
    $reqStmt->execute($role === 'barangay_head' ? [$user['barangay_id']] : []);
    $pendingReqs = (int)$reqStmt->fetchColumn();

    if ($role === 'icdrrmo') {
        $recStmt = $db->query("SELECT COUNT(*) FROM recommendations WHERE status = 'Pending Review'");
        $pendingRecs = (int)$recStmt->fetchColumn();

        $stockStmt = $db->query("SELECT COUNT(*) FROM resources WHERE (barangay_id IS NULL OR status = 'active') AND available_quantity <= min_threshold");
        $lowStockCount = (int)$stockStmt->fetchColumn();

        try {
            $resReqStmt = $db->query("SELECT COUNT(*) FROM barangay_resource_requests WHERE status = 'Pending'");
            $pendingResourceReqs = (int)$resReqStmt->fetchColumn();
        } catch (Exception $e) {
            $pendingResourceReqs = 0;
        }
    } elseif ($role === 'barangay_head') {
        try {
            $bStockStmt = $db->prepare("SELECT COUNT(*) FROM resources WHERE barangay_id = ? AND (status != 'archived' OR status IS NULL) AND available_quantity <= min_threshold");
            $bStockStmt->execute([$user['barangay_id']]);
            $localLowStockCount = (int)$bStockStmt->fetchColumn();
        } catch (Exception $e) {
            $localLowStockCount = 0;
        }
    }
}
?>
<aside class="sidebar" id="appSidebar">

  <!-- Secondary Brand Logo Header -->
  <div class="sidebar-brand-header">
    <a href="<?= BASE_URL ?>/views/<?= getRoleFolder($role) ?>/dashboard.php" class="sidebar-brand-link" title="Disaster Resource Allocation Recommender">
      <img src="<?= BASE_URL ?>/frontend/images/logo-secondary.png" alt="Disaster Resource Allocation Recommender" class="sidebar-brand-logo">
    </a>
  </div>

  <?php if ($role === 'icdrrmo'): ?>
    <!-- ICDRRMO Admin Navigation -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">Admin Management</div>
      <ul class="nav-list">
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
            Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            Manage User
          </a>
        </li>
        <?php if (false): // Hidden from left sidebar as requested; module code preserved ?>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/barangays.php" class="nav-link <?= $currentPage === 'barangays.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
            Manage Barangay
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-reports.php" class="nav-link <?= in_array($currentPage, ['disaster-reports.php', 'disaster-requests.php'], true) ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span>
            Disaster Reports
            <?php if (!empty($pendingReqs) && $pendingReqs > 0): ?><span class="nav-badge nav-badge-danger"><?= $pendingReqs ?> Active</span><?php endif; ?>
          </a>
        </li>
        <?php 
          $resTab = $_GET['tab'] ?? '';
          $isManageResourcesActive = in_array($currentPage, [
            'manage-resources.php', 
            'active-resources.php', 
            'resource-allocated.php', 
            'resources-requested.php'
          ], true);
        ?>
        <li class="nav-item nav-item-dropdown <?= $isManageResourcesActive ? 'active-dropdown' : '' ?>" id="sidebarManageResourcesDropdown">
          <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php?tab=active" class="nav-link <?= $isManageResourcesActive ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg></span>
            <span style="flex:1;">Manage Supplies</span>
            <?php if (!empty($pendingResourceReqs) && $pendingResourceReqs > 0): ?><span class="nav-badge nav-badge-danger" style="margin-right:4px; font-size:9px; padding:1px 5px;"><?= $pendingResourceReqs ?> New</span><?php endif; ?>
            <svg class="nav-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </a>
          <ul class="nav-submenu">
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/active-resources.php" class="nav-submenu-link <?= ($currentPage === 'active-resources.php' || ($currentPage === 'manage-resources.php' && ($resTab === 'active' || empty($resTab)))) ? 'active' : '' ?>" title="View Active Supplies">
                <span class="nav-submenu-bullet"></span>
                <span>Active Supplies</span>
              </a>
            </li>
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/resource-allocated.php" class="nav-submenu-link <?= ($currentPage === 'resource-allocated.php' || ($currentPage === 'manage-resources.php' && $resTab === 'allocations')) ? 'active' : '' ?>" title="View Supplies Allocated">
                <span class="nav-submenu-bullet"></span>
                <span>Supplies Allocated</span>
              </a>
            </li>
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/resources-requested.php" class="nav-submenu-link <?= ($currentPage === 'resources-requested.php' || ($currentPage === 'manage-resources.php' && $resTab === 'requests')) ? 'active' : '' ?>" title="View Supplies Requested">
                <span class="nav-submenu-bullet"></span>
                <span style="flex:1;">Supplies Requested</span>
                <?php if (!empty($pendingResourceReqs) && $pendingResourceReqs > 0): ?>
                  <span class="nav-badge nav-badge-danger" style="font-size:8.5px; padding:1px 4px;"><?= $pendingResourceReqs ?></span>
                <?php endif; ?>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/inventory.php" class="nav-link <?= in_array($currentPage, ['inventory.php', 'resources.php', 'inventory-history.php', 'inventory-adjustment.php']) ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
            Inventory
            <?php if ($lowStockCount > 0): ?><span class="nav-badge nav-badge-warning"><?= $lowStockCount ?> Low</span><?php endif; ?>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/financial-aid-allocation.php" class="nav-link <?= $currentPage === 'financial-aid-allocation.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></span>
            Manage Resources
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/events.php" class="nav-link <?= in_array($currentPage, ['events.php', 'event-details.php'], true) ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
            Preparedness Activities
          </a>
        </li>
        <?php 
          $sysLogModule = $_GET['module'] ?? '';
          $isLogsActive = in_array($currentPage, [
            'event-logs.php', 
            'system-logs.php', 
            'resources-logs.php', 
            'supplies-logs.php'
          ], true); 
        ?>
        <li class="nav-item nav-item-dropdown <?= $isLogsActive ? 'active-dropdown' : '' ?>" id="sidebarSystemLogsDropdown">
          <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="nav-link <?= $isLogsActive ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></span>
            <span style="flex:1;">System Logs</span>
            <svg class="nav-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </a>
          <ul class="nav-submenu">
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/event-logs.php" class="nav-submenu-link <?= $currentPage === 'event-logs.php' ? 'active' : '' ?>" title="View Barangay Event Logs">
                <span class="nav-submenu-bullet"></span>
                <span>Event Logs</span>
              </a>
            </li>
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/system-logs.php" class="nav-submenu-link <?= ($currentPage === 'system-logs.php' && empty($sysLogModule)) ? 'active' : '' ?>" title="View All System Audit Logs">
                <span class="nav-submenu-bullet"></span>
                <span>All System Logs</span>
              </a>
            </li>
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/resources-logs.php" class="nav-submenu-link <?= ($currentPage === 'resources-logs.php' || ($currentPage === 'system-logs.php' && ($sysLogModule === 'Manage Resources' || $sysLogModule === 'Resources'))) ? 'active' : '' ?>" title="View Resources Logs">
                <span class="nav-submenu-bullet"></span>
                <span>Resources Logs</span>
              </a>
            </li>
            <li class="nav-submenu-item">
              <a href="<?= BASE_URL ?>/views/icdrrmo/supplies-logs.php" class="nav-submenu-link <?= ($currentPage === 'supplies-logs.php' || ($currentPage === 'system-logs.php' && $sysLogModule === 'Supplies')) ? 'active' : '' ?>" title="View Supplies Logs">
                <span class="nav-submenu-bullet"></span>
                <span>Supplies Logs</span>
              </a>
            </li>
          </ul>
        </li>
      </ul>
    </div>

  <?php elseif ($role === 'barangay_head'): ?>
    <!-- Barangay Head Navigation -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">Barangay Operations</div>
      <ul class="nav-list">
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
            Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/disaster-reports.php" class="nav-link <?= ($currentPage === 'disaster-reports.php' || $currentPage === 'disaster-requests.php') ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span>
            Disaster Reports
          </a>
        </li>
        <?php /* Manage User removed for Barangay Head — user management is restricted to ICDRRMO only */ ?>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/resources.php" class="nav-link <?= $currentPage === 'resources.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg></span>
            Manage Supplies
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/inventory.php" class="nav-link <?= $currentPage === 'inventory.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
            Inventory
            <?php if (!empty($localLowStockCount) && $localLowStockCount > 0): ?><span class="nav-badge nav-badge-warning"><?= $localLowStockCount ?> Low</span><?php endif; ?>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/financial-aid-distribution.php" class="nav-link <?= $currentPage === 'financial-aid-distribution.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></span>
            Manage Resources
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/events.php" class="nav-link <?= in_array($currentPage, ['events.php', 'event-details.php'], true) ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
            Manage Event
          </a>
        </li>
        <?php /* Temporarily hidden - Manage Evacuation Areas
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="nav-link <?= $currentPage === 'evacuation.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg></span>
            Manage Evacuation Areas
          </a>
        </li>
        */ ?>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="nav-link <?= $currentPage === 'puroks.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
            Manage Purok
          </a>
        </li>
        <?php /* Transferred to navbar profile dropdown: Agency Information
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/agency.php" class="nav-link <?= $currentPage === 'agency.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path></svg></span>
            Agency Information
          </a>
        </li>
        */ ?>
      </ul>
    </div>
  <?php endif; ?>
</aside>

<style>
/* Sidebar Submenu & Hover Extended Dropdown */
.nav-item-dropdown {
  position: relative;
}
.nav-item-dropdown > .nav-link {
  display: flex;
  align-items: center;
  gap: var(--space-3, 10px);
  position: relative;
}
.nav-chevron {
  margin-left: auto;
  transition: transform 0.24s cubic-bezier(0.4, 0, 0.2, 1);
  opacity: 0.75;
  flex-shrink: 0;
}
.nav-submenu {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 0;
  opacity: 0;
  overflow: hidden;
  transform: translateY(-4px);
  transition: max-height 0.28s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.22s ease, transform 0.22s ease, padding 0.2s ease;
  padding-left: 28px;
}
/* Trigger open on hover or active page */
.nav-item-dropdown:hover .nav-chevron,
.nav-item-dropdown.active-dropdown .nav-chevron {
  transform: rotate(180deg);
  opacity: 1;
}
.nav-item-dropdown:hover .nav-submenu,
.nav-item-dropdown.active-dropdown .nav-submenu {
  max-height: 180px;
  opacity: 1;
  transform: translateY(0);
  padding-top: 4px;
  padding-bottom: 4px;
}
.nav-submenu-item {
  width: 100%;
}
.nav-submenu-link {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 10px;
  font-size: 11px;
  color: var(--color-text-secondary, #475569);
  text-decoration: none;
  border-radius: var(--radius-primary, 5px);
  font-weight: 500;
  transition: all 0.15s ease;
}
.nav-submenu-bullet {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #94A3B8;
  transition: all 0.15s ease;
  flex-shrink: 0;
}
.nav-submenu-link:hover {
  background: var(--color-surface-subtle, #F0F3F4);
  color: var(--color-primary, #17324D);
  padding-left: 13px;
}
.nav-submenu-link:hover .nav-submenu-bullet {
  background: var(--color-primary, #17324D);
  transform: scale(1.4);
}
.nav-submenu-link.active {
  background: var(--color-surface-subtle, #F0F3F4);
  color: var(--color-primary, #17324D);
  font-weight: 700;
  border-left: 2px solid var(--color-primary, #17324D);
  border-radius: 0 var(--radius-primary, 5px) var(--radius-primary, 5px) 0;
}
.nav-submenu-link.active .nav-submenu-bullet {
  background: var(--color-primary, #17324D);
  transform: scale(1.4);
}
</style>

