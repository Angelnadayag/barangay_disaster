<?php
// ============================================================================
// Layout Component: User Role-Aware Sidebar Navigation
// Routes to role-specific directories (icdrrmo, barangay, responder, resident)
// ============================================================================

require_once __DIR__ . '/../../backend/services/Auth.php';
$user = getCurrentUser();
$role = $user['role'];
$currentPage = basename($_SERVER['PHP_SELF']);
$db = getDBConnection();

$pendingReqs = 0;
$pendingRecs = 0;
$lowStockCount = 0;
$localLowStockCount = 0;

if ($role === 'icdrrmo' || $role === 'barangay_head') {
    $reqSql = ($role === 'barangay_head') 
        ? "SELECT COUNT(*) FROM disaster_requests WHERE status IN ('Submitted', 'Recommendation Ready') AND barangay_id = ?"
        : "SELECT COUNT(*) FROM disaster_requests WHERE status IN ('Submitted', 'Recommendation Ready')";
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

  <?php if ($role === 'icdrrmo'): ?>
    <!-- ICDRRMO Admin Navigation -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">Admin Management</div>
      <ul class="nav-list">
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            Manage User
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/barangays.php" class="nav-link <?= $currentPage === 'barangays.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
            Manage Barangay
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/manage-resources.php" class="nav-link <?= $currentPage === 'manage-resources.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg></span>
            Manage Resources
            <?php if (!empty($pendingResourceReqs) && $pendingResourceReqs > 0): ?><span class="nav-badge nav-badge-danger"><?= $pendingResourceReqs ?> New</span><?php endif; ?>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/icdrrmo/inventory.php" class="nav-link <?= in_array($currentPage, ['inventory.php', 'resources.php']) ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
            Inventory
            <?php if ($lowStockCount > 0): ?><span class="nav-badge nav-badge-warning"><?= $lowStockCount ?> Low</span><?php endif; ?>
          </a>
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
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/residents.php" class="nav-link <?= $currentPage === 'residents.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            Manage Resident
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/responders.php" class="nav-link <?= $currentPage === 'responders.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></span>
            Manage Responders
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/resources.php" class="nav-link <?= $currentPage === 'resources.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg></span>
            Manage Resources
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
          <a href="<?= BASE_URL ?>/views/barangay/events.php" class="nav-link <?= ($currentPage === 'events.php' || $currentPage === 'preparedness.php') ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
            Manage Event
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/evacuation.php" class="nav-link <?= $currentPage === 'evacuation.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg></span>
            Manage Evacuation Areas
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/puroks.php" class="nav-link <?= $currentPage === 'puroks.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
            Manage Purok
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/barangay/agency.php" class="nav-link <?= $currentPage === 'agency.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path></svg></span>
            Agency Information
          </a>
        </li>
      </ul>
    </div>

  <?php elseif ($role === 'responder'): ?>
    <!-- Responder Navigation -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">Tactical Field Menu</div>
      <ul class="nav-list">
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
            Field Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/field-status.php" class="nav-link <?= $currentPage === 'field-status.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg></span>
            Field Status Updates
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/active-incidents.php" class="nav-link <?= $currentPage === 'active-incidents.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg></span>
            Active Incidents
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/evacuation.php" class="nav-link <?= $currentPage === 'evacuation.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg></span>
            Evacuation Camps
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/risk-map.php" class="nav-link <?= $currentPage === 'risk-map.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon></svg></span>
            Hazard Map
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/responder/notifications.php" class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path></svg></span>
            Emergency Alerts
          </a>
        </li>
      </ul>
    </div>

  <?php elseif ($role === 'resident'): ?>
    <!-- Resident Navigation -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">Community Services</div>
      <ul class="nav-list">
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/resident/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
            Community Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/resident/evacuation.php" class="nav-link <?= $currentPage === 'evacuation.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg></span>
            Evacuation Directory
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/resident/risk-map.php" class="nav-link <?= $currentPage === 'risk-map.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon></svg></span>
            Hazard Map
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/resident/preparedness.php" class="nav-link <?= $currentPage === 'preparedness.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect></svg></span>
            Preparedness Drills
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= BASE_URL ?>/views/resident/notifications.php" class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path></svg></span>
            Public Advisories
          </a>
        </li>
      </ul>
    </div>
  <?php endif; ?>
</aside>
