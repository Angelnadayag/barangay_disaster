<?php
// ============================================================================
// Views (Resident): Evacuation Routing & Safe Shelters Directory
// Mobile-First Priority & Desktop-Responsive Shelter Navigation System
// Features: Real-Time Capacity, Life Support Utilities, and One-Tap Evacuation Routing
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$filterBarangay = $_GET['barangay_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

// Fetch Resident Barangay details for default coordinates
$bStmt = $db->prepare("SELECT id, name, coordinates_lat AS latitude, coordinates_lng AS longitude FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$residentBarangay = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'id' => 12,
    'name' => 'Hinaplanon',
    'latitude' => 8.246596,
    'longitude' => 124.259474
];

$sql = "
    SELECT ea.*, 
           ea.capacity_individuals AS capacity, 
           ea.current_evacuees_count AS current_evacuees,
           ea.has_electricity AS has_power, 
           ea.has_potable_water AS has_water, 
           ea.has_medical_station AS has_medical,
           ea.location_address AS location_details,
           ea.coordinates_lat AS latitude,
           ea.coordinates_lng AS longitude,
           b.name AS barangay_name, 
           b.risk_level AS brgy_risk
    FROM evacuation_areas ea
    JOIN barangays b ON ea.barangay_id = b.id
    WHERE 1=1
";
$params = [];

if (!empty($filterBarangay)) {
    $sql .= " AND ea.barangay_id = ?";
    $params[] = $filterBarangay;
}

if (!empty($filterStatus)) {
    $sql .= " AND ea.status = ?";
    $params[] = $filterStatus;
}

if (!empty($searchQuery)) {
    $sql .= " AND (ea.name LIKE ? OR ea.location_address LIKE ? OR b.name LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (ea.barangay_id = ?) DESC, FIELD(ea.status, 'Open / Active', 'Open', 'Standby', 'At Capacity', 'Closed'), ea.name ASC";
$params[] = $barangayId;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$evacAreas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for quick filter pills
$totalCenters = count($evacAreas);
$openCenters = 0;
$standbyCenters = 0;
foreach ($evacAreas as $ea) {
    if (in_array($ea['status'], ['Open / Active', 'Open'], true)) $openCenters++;
    elseif ($ea['status'] === 'Standby') $standbyCenters++;
}

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Evacuation Routing & Shelters";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<!-- Leaflet GIS Map Styling -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* ==========================================================================
   Evacuation Routing Mobile-First Layout & Anti-Slop Design
   Strict 10px radius, standard Poppins/Roboto typography, crisp color tokens
   ========================================================================== */
.evac-routing-container {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  width: 100%;
}

/* Mobile View Switcher Tabs */
.evac-view-toggle {
  display: flex;
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 3px;
  gap: 3px;
}
.evac-toggle-btn {
  flex: 1;
  border: none;
  background: transparent;
  padding: 8px 12px;
  font-family: var(--font-primary, 'Poppins', sans-serif);
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-secondary, #66737D);
  border-radius: var(--radius-sm, 6px);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.15s ease;
}
.evac-toggle-btn.active {
  background: var(--color-surface, #FFFFFF);
  color: var(--color-primary, #17324D);
  box-shadow: 0 1px 3px rgba(23, 50, 77, 0.08);
}

/* Mobile GPS Status Banner */
.evac-gps-banner {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 11px;
}
.evac-gps-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-weight: 600;
  color: var(--color-primary, #17324D);
}
.gps-status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-success, #2E7D32);
}

/* Search and Filter Deck */
.evac-filter-deck {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.evac-search-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
.evac-search-input {
  flex: 1;
  font-size: 12px;
  padding: 8px 12px;
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-sm, 6px);
  outline: none;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
}
.evac-search-input:focus {
  border-color: var(--color-primary, #17324D);
}

/* Filter Pills */
.evac-filter-pills {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: none;
}
.evac-filter-pills::-webkit-scrollbar {
  display: none;
}
.evac-pill-btn {
  white-space: nowrap;
  font-size: 10.5px;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: var(--radius-full, 9999px);
  border: 1px solid var(--color-border, #D9E0E3);
  background: var(--color-surface, #FFFFFF);
  color: var(--color-text-secondary, #66737D);
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.evac-pill-btn.active {
  background: var(--color-primary, #17324D);
  border-color: var(--color-primary, #17324D);
  color: #FFFFFF;
  font-weight: 600;
}

/* Responsive Desktop Split: List + Sticky Map */
.evac-content-split {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
}
@media (min-width: 901px) {
  .evac-content-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: start;
  }
  .evac-map-wrapper {
    position: sticky;
    top: calc(var(--topbar-height, 52px) + 16px);
  }
}

/* Evacuation Card */
.evac-shelter-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  position: relative;
}
.evac-shelter-card:hover {
  border-color: var(--color-secondary, #2F6F73);
  box-shadow: 0 3px 10px rgba(23, 50, 77, 0.06);
}
.evac-shelter-card.active-routed {
  border: 2px solid var(--color-primary, #17324D);
  background: #FDFEFE;
}

/* Card Header */
.evac-card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
}
.evac-card-title {
  font-family: var(--font-primary, 'Poppins', sans-serif);
  font-size: 13.5px;
  font-weight: 700;
  color: var(--color-primary, #17324D);
  line-height: 1.3;
  margin: 0;
}
.evac-card-subtitle {
  font-size: 10.5px;
  color: var(--color-text-secondary, #66737D);
  margin-top: 2px;
}

/* Occupancy Progress */
.evac-occupancy-wrap {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: var(--radius-sm, 6px);
  padding: 8px 10px;
}
.evac-progress-bar-bg {
  width: 100%;
  height: 6px;
  background: #D9E0E3;
  border-radius: 3px;
  overflow: hidden;
  margin-top: 4px;
}
.evac-progress-bar-fill {
  height: 100%;
  border-radius: 3px;
  transition: width 0.3s ease;
}

/* Amenities Badges Row */
.evac-amenities-row {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 10px;
}
.evac-utility-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: var(--radius-sm, 6px);
  font-size: 9.5px;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-weight: 600;
  border: 1px solid transparent;
}
.evac-utility-badge.avail {
  background: var(--color-success-bg, #EAF4EB);
  color: var(--color-success, #2E7D32);
  border-color: var(--color-success-border, #BCE3C1);
}
.evac-utility-badge.unavail {
  background: var(--color-surface-subtle, #F0F3F4);
  color: var(--color-text-muted, #8A96A0);
  border-color: var(--color-border-light, #E7EDF0);
}

/* Action Footer & Route Button */
.evac-card-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  border-top: 1px solid var(--color-border-light, #E7EDF0);
  padding-top: 10px;
  margin-top: 2px;
}

/* Big Thumb-Friendly Route Button */
.evac-route-btn {
  background: var(--color-primary, #17324D) !important;
  color: #FFFFFF !important;
  font-family: var(--font-primary, 'Poppins', sans-serif) !important;
  font-size: 11.5px !important;
  font-weight: 600 !important;
  border-radius: var(--radius-primary, 10px) !important;
  padding: 8px 16px !important;
  border: 1px solid var(--color-primary-dark, #102336) !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 6px !important;
  min-height: 40px !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
  text-decoration: none !important;
}
.evac-route-btn:hover {
  background: var(--color-primary-light, #214364) !important;
  transform: translateY(-1px);
}
.evac-route-btn:active {
  transform: translateY(0);
}

.evac-contact-link {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-secondary, #2F6F73);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.evac-contact-link:hover {
  text-decoration: underline;
}

/* Evacuation Map Wrapper */
.evac-map-wrapper {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  overflow: hidden;
  box-shadow: var(--shadow-card, 0 1px 4px rgba(23, 50, 77, 0.08));
}
.evac-map-header {
  padding: 10px 14px;
  background: var(--color-surface-subtle, #F0F3F4);
  border-bottom: 1px solid var(--color-border, #D9E0E3);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
#evacuation-live-map {
  width: 100%;
  height: 380px;
}
@media (min-width: 901px) {
  #evacuation-live-map {
    height: 480px;
  }
}

/* In-App Live Route Active Sheet (Mobile Friendly Bottom Drawer) */
.evac-route-sheet {
  display: none;
  background: #FFFFFF;
  border-top: 3px solid var(--color-primary, #17324D);
  padding: 14px 16px;
  animation: slideUpRoute 0.25s ease forwards;
}
@keyframes slideUpRoute {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.evac-route-sheet.active {
  display: block;
}
.route-stat-box {
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border-light, #E7EDF0);
  border-radius: var(--radius-sm, 6px);
  padding: 8px 12px;
  text-align: center;
}
.route-stat-val {
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-weight: 700;
  font-size: 14px;
  color: var(--color-primary, #17324D);
}
.route-stat-lbl {
  font-size: 9.5px;
  color: var(--color-text-secondary, #66737D);
  text-transform: uppercase;
}
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Evacuation Routing & Safe Shelters</h1>
      <p class="page-header-desc">
        Real-time capacity tracking, backup utilities, and instant GPS evacuation routing to designated safe shelters.
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-secondary btn-sm" onclick="locateUserGPS()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
        Update My GPS
      </button>
    </div>
  </div>

  <div class="evac-routing-container">
    <!-- Mobile GPS Status Banner -->
    <div class="evac-gps-banner">
      <div class="evac-gps-pill">
        <span class="gps-status-dot" id="gpsIndicatorDot"></span>
        <span id="gpsStatusText">Location: Brgy. <?= clean($residentBarangay['name']) ?> Area</span>
      </div>
      <span style="font-size:10px;color:var(--color-text-muted);" id="gpsDistanceHint">Distances calculated live</span>
    </div>

    <!-- Mobile View Switcher (List vs Map) -->
    <div class="evac-view-toggle">
      <button type="button" class="evac-toggle-btn active" id="btnViewList" onclick="switchEvacView('list')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Shelters List (<?= count($evacAreas) ?>)
      </button>
      <button type="button" class="evac-toggle-btn" id="btnViewMap" onclick="switchEvacView('map')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg>
        Route Map & Safe Zones
      </button>
    </div>

    <!-- Filter Deck -->
    <div class="evac-filter-deck">
      <form method="GET" class="evac-search-row">
        <input type="text" 
               name="q" 
               value="<?= clean($searchQuery) ?>" 
               placeholder="Search shelter name, barangay, or street address..." 
               class="evac-search-input">
        <?php if (!empty($filterBarangay)): ?><input type="hidden" name="barangay_id" value="<?= clean($filterBarangay) ?>"><?php endif; ?>
        <?php if (!empty($filterStatus)): ?><input type="hidden" name="status" value="<?= clean($filterStatus) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:0 14px;font-size:11px;">Search</button>
        <?php if (!empty($searchQuery) || !empty($filterBarangay) || !empty($filterStatus)): ?>
          <a href="evacuation.php" class="btn btn-outline btn-sm" style="padding:0 10px;font-size:11px;display:flex;align-items:center;">Reset</a>
        <?php endif; ?>
      </form>

      <!-- Quick Filter Pills -->
      <div class="evac-filter-pills">
        <a href="evacuation.php" class="evac-pill-btn <?= (empty($filterBarangay) && empty($filterStatus)) ? 'active' : '' ?>">
          All Shelters (<?= $totalCenters ?>)
        </a>
        <a href="evacuation.php?barangay_id=<?= $barangayId ?>" class="evac-pill-btn <?= ($filterBarangay == $barangayId) ? 'active' : '' ?>">
          My Barangay (<?= clean($residentBarangay['name']) ?>)
        </a>
        <a href="evacuation.php?status=Open%20/%20Active" class="evac-pill-btn <?= ($filterStatus === 'Open / Active' || $filterStatus === 'Open') ? 'active' : '' ?>">
          Open / Active (<?= $openCenters ?>)
        </a>
        <a href="evacuation.php?status=Standby" class="evac-pill-btn <?= ($filterStatus === 'Standby') ? 'active' : '' ?>">
          Standby (<?= $standbyCenters ?>)
        </a>
      </div>
    </div>

    <!-- Responsive Layout Split -->
    <div class="evac-content-split">

      <!-- Column 1: Shelters List -->
      <div id="evacListSection" style="display:flex;flex-direction:column;gap:10px;">
        <?php if (empty($evacAreas)): ?>
          <div class="evac-shelter-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No designated evacuation shelters match your active filter.
          </div>
        <?php else: ?>
          <?php foreach ($evacAreas as $center): 
            $cap = (int)$center['capacity'];
            $current = (int)$center['current_evacuees'];
            $pct = $cap > 0 ? min(100, round(($current / $cap) * 100)) : 0;
            $remaining = max(0, $cap - $current);
            $isOpen = in_array($center['status'], ['Open / Active', 'Open'], true);
            $isFull = ($center['status'] === 'At Capacity' || $pct >= 100);
            $barColor = $isFull ? 'var(--color-danger, #C62828)' : ($pct >= 75 ? 'var(--color-warning, #B78103)' : 'var(--color-success, #2E7D32)');
            $isMyBrgy = ((int)$center['barangay_id'] === $barangayId);
          ?>
            <div class="evac-shelter-card" 
                 id="shelter-card-<?= (int)$center['id'] ?>"
                 data-id="<?= (int)$center['id'] ?>"
                 data-lat="<?= (float)$center['latitude'] ?>"
                 data-lng="<?= (float)$center['longitude'] ?>"
                 data-name="<?= clean($center['name']) ?>"
                 data-barangay="<?= clean($center['barangay_name']) ?>"
                 data-address="<?= clean($center['location_details'] ?? $center['location_address']) ?>"
                 data-officer="<?= clean($center['contact_officer']) ?>"
                 data-phone="<?= clean($center['contact_number']) ?>"
                 data-status="<?= clean($center['status']) ?>"
                 data-avail="<?= $remaining ?>">
              
              <div class="evac-card-head">
                <div>
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <h3 class="evac-card-title"><?= clean($center['name']) ?></h3>
                    <?php if ($isMyBrgy): ?>
                      <span class="badge badge-primary" style="font-size:9.5px;padding:2px 6px;">Local</span>
                    <?php endif; ?>
                  </div>
                  <div class="evac-card-subtitle">
                    Brgy. <?= clean($center['barangay_name']) ?> &bull; <?= clean($center['center_type']) ?>
                  </div>
                </div>
                <div>
                  <?= renderStatusBadge($center['status']) ?>
                </div>
              </div>

              <!-- Distance & Address -->
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:10.5px;">
                <span style="color:var(--color-text-secondary);"><?= clean($center['location_details'] ?? $center['location_address']) ?></span>
                <span class="badge badge-neutral shelter-distance-pill" id="dist-pill-<?= (int)$center['id'] ?>" style="font-size:10px;font-weight:700;">
                  Calculating...
                </span>
              </div>

              <!-- Occupancy Bar -->
              <div class="evac-occupancy-wrap">
                <div style="display:flex;justify-content:space-between;font-size:10.5px;font-family:var(--font-secondary);">
                  <span style="font-weight:600;color:var(--color-text);">Shelter Load</span>
                  <span style="font-weight:700;color:<?= $barColor ?>;">
                    <?= number_format($current) ?> / <?= number_format($cap) ?> (<?= $pct ?>%)
                  </span>
                </div>
                <div class="evac-progress-bar-bg">
                  <div class="evac-progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:9.5px;color:var(--color-text-muted);margin-top:3px;">
                  <span>Available slots: <strong><?= number_format($remaining) ?></strong></span>
                  <span>Convoy: <?= clean($center['accessibility']) ?></span>
                </div>
              </div>

              <!-- Amenities -->
              <div class="evac-amenities-row">
                <span class="evac-utility-badge <?= $center['has_power'] ? 'avail' : 'unavail' ?>">
                  <?= $center['has_power'] ? '✓ Backup Power' : '✕ No Power' ?>
                </span>
                <span class="evac-utility-badge <?= $center['has_water'] ? 'avail' : 'unavail' ?>">
                  <?= $center['has_water'] ? '✓ Potable Water' : '✕ Water Truck' ?>
                </span>
                <span class="evac-utility-badge <?= $center['has_medical'] ? 'avail' : 'unavail' ?>">
                  <?= $center['has_medical'] ? '✓ Medical Station' : '✕ No Clinic' ?>
                </span>
              </div>

              <!-- Action Footer -->
              <div class="evac-card-actions">
                <a href="tel:<?= clean($center['contact_number']) ?>" class="evac-contact-link">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  <?= clean($center['contact_officer'] ?: 'Call Manager') ?>
                </a>

                <!-- The Prominent Route Button -->
                <button type="button" 
                        class="evac-route-btn" 
                        onclick="startEvacuationRoute(<?= (int)$center['id'] ?>)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                  Route
                </button>
              </div>

            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Column 2: Live Evacuation Route Map & Active Route Sheet -->
      <div id="evacMapSection" class="evac-map-wrapper">
        <div class="evac-map-header">
          <span style="font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">
            Live Evacuation Routing Map
          </span>
          <span style="font-size:10px;color:var(--color-text-muted);">OpenStreetMap / Leaflet</span>
        </div>

        <!-- The Map Element -->
        <div id="evacuation-live-map"></div>

        <!-- In-App Route Bottom Sheet / Information Panel -->
        <div id="evacRouteSheet" class="evac-route-sheet">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
            <div>
              <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;">Active Route Selected</span>
              <h4 id="routeTargetName" style="font-size:13.5px;font-weight:700;color:var(--color-primary);margin:4px 0 2px 0;">Select a Shelter</h4>
              <div id="routeTargetAddress" style="font-size:10.5px;color:var(--color-text-secondary);">—</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearEvacuationRoute()" style="padding:4px 8px;font-size:10px;">Close Route</button>
          </div>

          <!-- Trip Statistics (Distance & Time) -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;">
            <div class="route-stat-box">
              <div class="route-stat-val" id="routeDistanceText">0.0 km</div>
              <div class="route-stat-lbl">Distance</div>
            </div>
            <div class="route-stat-box">
              <div class="route-stat-val" id="routeWalkTime">0 min</div>
              <div class="route-stat-lbl">Walking</div>
            </div>
            <div class="route-stat-box">
              <div class="route-stat-val" id="routeDriveTime">0 min</div>
              <div class="route-stat-lbl">Vehicle</div>
            </div>
          </div>

          <!-- Turn-by-Turn GPS Button and Emergency Call -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="routeGmapsBtn" 
               href="#" 
               target="_blank" 
               rel="noopener noreferrer" 
               class="evac-route-btn" 
               style="flex:1;text-align:center;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
              Open in Google Maps (Turn-by-Turn)
            </a>
            <a id="routeCallBtn" 
               href="#" 
               class="btn btn-secondary" 
               style="font-size:11.5px;font-weight:600;border-radius:10px;padding:8px 14px;display:flex;align-items:center;gap:6px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              Call Camp
            </a>
          </div>
        </div>

      </div>

    </div>
  </div>
</main>

<script>
// Evacuation Centers Master Payload
const evacShelters = <?= json_encode($evacAreas) ?>;

// Default fallback coordinates (User's Barangay Center)
let userLat = <?= (float)$residentBarangay['latitude'] ?>;
let userLng = <?= (float)$residentBarangay['longitude'] ?>;
let hasLiveGps = false;

let evacMap = null;
let userMarker = null;
let shelterMarkers = {};
let activeRouteLine = null;

// Haversine formula for live distance in kilometers
function calculateHaversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371; // Earth radius in km
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

// Update all distance badges in the UI
function updateAllDistancePills() {
  evacShelters.forEach(s => {
    const sLat = parseFloat(s.latitude);
    const sLng = parseFloat(s.longitude);
    const pill = document.getElementById(`dist-pill-${s.id}`);
    if (pill && !isNaN(sLat) && !isNaN(sLng)) {
      const dist = calculateHaversineKm(userLat, userLng, sLat, sLng);
      pill.innerText = `${dist.toFixed(1)} km away`;
    }
  });
}

// Mobile View Switcher (List vs Map)
function switchEvacView(view) {
  const listSec = document.getElementById('evacListSection');
  const mapSec = document.getElementById('evacMapSection');
  const btnList = document.getElementById('btnViewList');
  const btnMap = document.getElementById('btnViewMap');

  if (window.innerWidth <= 900) {
    if (view === 'map') {
      listSec.style.display = 'none';
      mapSec.style.display = 'block';
      btnMap.classList.add('active');
      btnList.classList.remove('active');
      if (evacMap) setTimeout(() => evacMap.invalidateSize(), 200);
    } else {
      listSec.style.display = 'flex';
      mapSec.style.display = 'none';
      btnList.classList.add('active');
      btnMap.classList.remove('active');
    }
  } else {
    listSec.style.display = 'flex';
    mapSec.style.display = 'block';
  }
}

// Live GPS Geolocation
function locateUserGPS() {
  const statusTxt = document.getElementById('gpsStatusText');
  const dot = document.getElementById('gpsIndicatorDot');

  if (navigator.geolocation) {
    statusTxt.innerText = "Acquiring live GPS...";
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        userLat = pos.coords.latitude;
        userLng = pos.coords.longitude;
        hasLiveGps = true;
        statusTxt.innerText = `Live GPS Active (${userLat.toFixed(4)}, ${userLng.toFixed(4)})`;
        dot.style.background = 'var(--color-success, #2E7D32)';
        updateAllDistancePills();
        if (evacMap) {
          if (userMarker) {
            userMarker.setLatLng([userLat, userLng]);
          } else {
            userMarker = L.circleMarker([userLat, userLng], {
              radius: 8,
              fillColor: '#17324D',
              color: '#FFFFFF',
              weight: 2,
              fillOpacity: 1
            }).addTo(evacMap).bindPopup("<strong>Your Current Location</strong>");
          }
        }
      },
      (err) => {
        console.warn("GPS Access denied or unavailable, using Barangay center.", err);
        statusTxt.innerText = "Location: Brgy. <?= clean($residentBarangay['name']) ?> Center";
        dot.style.background = 'var(--color-warning, #B78103)';
        updateAllDistancePills();
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  } else {
    updateAllDistancePills();
  }
}

// The Core Feature: Start Evacuation Route
function startEvacuationRoute(centerId) {
  const center = evacShelters.find(s => parseInt(s.id) === parseInt(centerId));
  if (!center) return;

  const destLat = parseFloat(center.latitude);
  const destLng = parseFloat(center.longitude);

  if (isNaN(destLat) || isNaN(destLng)) {
    alert("Coordinates for this shelter are currently being verified.");
    return;
  }

  // Highlight card
  document.querySelectorAll('.evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
  const card = document.getElementById(`shelter-card-${centerId}`);
  if (card) card.classList.add('active-routed');

  // Compute metrics
  const distKm = calculateHaversineKm(userLat, userLng, destLat, destLng);
  const walkMins = Math.max(1, Math.round((distKm / 4.5) * 60));
  const driveMins = Math.max(1, Math.round((distKm / 30) * 60));

  // Populate Route Sheet
  document.getElementById('routeTargetName').innerText = center.name;
  document.getElementById('routeTargetAddress').innerText = `${center.barangay_name} • ${center.location_details || center.location_address}`;
  document.getElementById('routeDistanceText').innerText = `${distKm.toFixed(1)} km`;
  document.getElementById('routeWalkTime').innerText = `~${walkMins} min`;
  document.getElementById('routeDriveTime').innerText = `~${driveMins} min`;

  // External GPS Google Maps URL (Native turn-by-turn navigation)
  const gmapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${userLat},${userLng}&destination=${destLat},${destLng}&travelmode=walking`;
  document.getElementById('routeGmapsBtn').href = gmapsUrl;

  // Phone Call Link
  const phone = center.contact_number || '';
  document.getElementById('routeCallBtn').href = phone ? `tel:${phone}` : '#';

  // Display Route Sheet
  document.getElementById('evacRouteSheet').classList.add('active');

  // Switch to map on mobile so user sees the route immediately
  if (window.innerWidth <= 900) {
    switchEvacView('map');
  }

  // Draw Leaflet Route Polyline
  if (evacMap) {
    if (activeRouteLine) {
      evacMap.removeLayer(activeRouteLine);
    }

    activeRouteLine = L.polyline([
      [userLat, userLng],
      [destLat, destLng]
    ], {
      color: '#17324D',
      weight: 4,
      dashArray: '6, 6',
      opacity: 0.85
    }).addTo(evacMap);

    // Fit bounds to show both user and destination
    evacMap.fitBounds([
      [userLat, userLng],
      [destLat, destLng]
    ], { padding: [50, 50], maxZoom: 16 });

    // Open destination popup
    if (shelterMarkers[centerId]) {
      shelterMarkers[centerId].openPopup();
    }
  }
}

// Clear Active Route
function clearEvacuationRoute() {
  document.getElementById('evacRouteSheet').classList.remove('active');
  document.querySelectorAll('.evac-shelter-card').forEach(c => c.classList.remove('active-routed'));
  if (evacMap && activeRouteLine) {
    evacMap.removeLayer(activeRouteLine);
    activeRouteLine = null;
    evacMap.setView([userLat, userLng], 14);
  }
}

// Initialize Leaflet Map
document.addEventListener('DOMContentLoaded', function() {
  evacMap = L.map('evacuation-live-map').setView([userLat, userLng], 14);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap'
  }).addTo(evacMap);

  // User Marker
  userMarker = L.circleMarker([userLat, userLng], {
    radius: 8,
    fillColor: '#17324D',
    color: '#FFFFFF',
    weight: 2,
    fillOpacity: 1
  }).addTo(evacMap).bindPopup("<strong>Your Location (Reference Point)</strong>");

  // Plot Shelters
  evacShelters.forEach(s => {
    const sLat = parseFloat(s.latitude);
    const sLng = parseFloat(s.longitude);
    if (!isNaN(sLat) && !isNaN(sLng)) {
      const isOpen = (s.status === 'Open / Active' || s.status === 'Open');
      const isFull = (s.status === 'At Capacity');
      const markerColor = isFull ? '#C62828' : (isOpen ? '#2E7D32' : '#B78103');

      const m = L.circleMarker([sLat, sLng], {
        radius: 9,
        fillColor: markerColor,
        color: '#FFFFFF',
        weight: 2,
        fillOpacity: 0.95
      }).addTo(evacMap);

      m.bindPopup(`
        <div style="font-family:var(--font-primary, sans-serif);font-size:12px;line-height:1.4;">
          <strong style="color:var(--color-primary);font-size:13px;">${s.name}</strong><br>
          <span style="font-size:11px;color:#66737D;">Brgy. ${s.barangay_name}</span><br>
          <b>Status:</b> ${s.status}<br>
          <b>Occupancy:</b> ${s.current_evacuees} / ${s.capacity}<br>
          <div style="margin-top:6px;">
            <button onclick="startEvacuationRoute(${s.id})" class="btn btn-primary btn-sm" style="font-size:10.5px;padding:3px 10px;border-radius:6px;width:100%;">
              Route to Shelter
            </button>
          </div>
        </div>
      `);

      shelterMarkers[s.id] = m;
    }
  });

  // Calculate distances on load
  locateUserGPS();

  // Handle window resize responsive view
  window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
      document.getElementById('evacListSection').style.display = 'flex';
      document.getElementById('evacMapSection').style.display = 'block';
    }
  });
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
