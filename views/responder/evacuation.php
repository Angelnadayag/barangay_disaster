<?php
// ============================================================================
// Views (Responder): Tactical Evacuation Routing & Camp Management
// Mobile-First Priority & Field-Responsive Convoy Navigation System
// Features: Road Accessibility Status, Headcount Management, and Convoy GPS Routing
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('responder');
$user = getCurrentUser();
$db = getDBConnection();

$filterBarangay = $_GET['barangay'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

$sql = "
    SELECT ea.*, 
           ea.capacity_individuals AS capacity, 
           ea.current_evacuees_count AS current_evacuees,
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
    $sql .= " AND (ea.name LIKE ? OR ea.location_address LIKE ? OR b.name LIKE ? OR ea.contact_officer LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY FIELD(ea.status, 'Open / Active', 'Standby', 'At Capacity', 'Closed'), ea.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Tactical statistics
$totalCamps = count($centers);
$activeSheltering = 0;
$standbyCamps = 0;
$totalEvacueesSheltered = 0;
foreach ($centers as $c) {
    if ($c['status'] === 'Open / Active' || $c['status'] === 'Open') $activeSheltering++;
    if ($c['status'] === 'Standby') $standbyCamps++;
    $totalEvacueesSheltered += (int)$c['current_evacuees_count'];
}

$pageTitle = "Evacuation Routing & Camps — Tactical View";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<!-- Leaflet GIS Map Styling -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
/* ==========================================================================
   Responder Evacuation Tactical View & Mobile-First Route System
   Strict 10px radius, standard Poppins/Roboto typography, crisp color tokens
   ========================================================================== */
.tactical-evac-container {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
  width: 100%;
}

/* Mobile View Switcher */
.tactical-view-toggle {
  display: flex;
  background: var(--color-surface-subtle, #F0F3F4);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 3px;
  gap: 3px;
}
.tactical-toggle-btn {
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
.tactical-toggle-btn.active {
  background: var(--color-surface, #FFFFFF);
  color: var(--color-primary, #17324D);
  box-shadow: 0 1px 3px rgba(23, 50, 77, 0.08);
}

/* Tactical GPS Banner */
.tactical-gps-banner {
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

/* Search and Filters */
.tactical-filter-deck {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.tactical-search-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
.tactical-search-input {
  flex: 1;
  font-size: 12px;
  padding: 8px 12px;
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-sm, 6px);
  outline: none;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
}
.tactical-search-input:focus {
  border-color: var(--color-primary, #17324D);
}

/* Filter Pills */
.tactical-filter-pills {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: none;
}
.tactical-filter-pills::-webkit-scrollbar {
  display: none;
}
.tactical-pill-btn {
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
.tactical-pill-btn.active {
  background: var(--color-primary, #17324D);
  border-color: var(--color-primary, #17324D);
  color: #FFFFFF;
  font-weight: 600;
}

/* Responsive Split */
.tactical-content-split {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 12px);
}
@media (min-width: 901px) {
  .tactical-content-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: start;
  }
  .tactical-map-wrapper {
    position: sticky;
    top: calc(var(--topbar-height, 52px) + 16px);
  }
}

/* Camp Card */
.tactical-camp-card {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.tactical-camp-card:hover {
  border-color: var(--color-secondary, #2F6F73);
  box-shadow: 0 3px 10px rgba(23, 50, 77, 0.06);
}
.tactical-camp-card.active-routed {
  border: 2px solid var(--color-primary, #17324D);
  background: #FDFEFE;
}

/* Road Accessibility Badge */
.road-access-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-family: var(--font-secondary, 'Roboto', sans-serif);
  font-weight: 600;
  padding: 2px 7px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid transparent;
}
.road-access-badge.all-vehicles {
  background: var(--color-success-bg, #EAF4EB);
  color: var(--color-success, #2E7D32);
  border-color: var(--color-success-border, #BCE3C1);
}
.road-access-badge.high-clearance {
  background: var(--color-warning-bg, #FEF8E8);
  color: var(--color-warning, #B78103);
  border-color: var(--color-warning-border, #FCE6AA);
}
.road-access-badge.inaccessible {
  background: var(--color-danger-bg, #FDE8E8);
  color: var(--color-danger, #C62828);
  border-color: var(--color-danger-border, #F8B4B4);
}

/* The Route Button */
.tactical-route-btn {
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
.tactical-route-btn:hover {
  background: var(--color-primary-light, #214364) !important;
  transform: translateY(-1px);
}

/* Map Wrapper */
.tactical-map-wrapper {
  background: var(--color-surface, #FFFFFF);
  border: 1px solid var(--color-border, #D9E0E3);
  border-radius: var(--radius-primary, 10px);
  overflow: hidden;
  box-shadow: var(--shadow-card, 0 1px 4px rgba(23, 50, 77, 0.08));
}
#tactical-evac-map {
  width: 100%;
  height: 380px;
}
@media (min-width: 901px) {
  #tactical-evac-map {
    height: 480px;
  }
}

/* Tactical Route Sheet */
.tactical-route-sheet {
  display: none;
  background: #FFFFFF;
  border-top: 3px solid var(--color-primary, #17324D);
  padding: 14px 16px;
  animation: slideUpTactical 0.25s ease forwards;
}
@keyframes slideUpTactical {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.tactical-route-sheet.active {
  display: block;
}
</style>

<main class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Tactical Evacuation Routing & Camps</h1>
      <p class="page-header-desc">
        Field convoy routing, road passability tracking, and real-time camp headcount management.
      </p>
    </div>
    <div class="page-header-actions">
      <button type="button" class="btn btn-secondary btn-sm" onclick="locateResponderGPS()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
        Field GPS
      </button>
    </div>
  </div>

  <div class="tactical-evac-container">
    <!-- Mobile GPS Status Banner -->
    <div class="tactical-gps-banner">
      <div style="display:flex;align-items:center;gap:6px;font-family:var(--font-secondary);font-weight:600;color:var(--color-primary);">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--color-success);" id="respGpsDot"></span>
        <span id="respGpsText">Field Status: GPS Connected</span>
      </div>
      <span style="font-size:10px;color:var(--color-text-muted);">
        Total Sheltered: <strong><?= number_format($totalEvacueesSheltered) ?></strong> Individuals
      </span>
    </div>

    <!-- Mobile View Switcher (Camps vs Route Map) -->
    <div class="tactical-view-toggle">
      <button type="button" class="tactical-toggle-btn active" id="btnTacList" onclick="switchTacticalView('list')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Camp Directory (<?= count($centers) ?>)
      </button>
      <button type="button" class="tactical-toggle-btn" id="btnTacMap" onclick="switchTacticalView('map')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg>
        Tactical Route Map
      </button>
    </div>

    <!-- Filter Deck -->
    <div class="tactical-filter-deck">
      <form method="GET" class="tactical-search-row">
        <input type="text" 
               name="q" 
               value="<?= clean($searchQuery) ?>" 
               placeholder="Search camp name, barangay, or camp officer..." 
               class="tactical-search-input">
        <?php if (!empty($filterBarangay)): ?><input type="hidden" name="barangay" value="<?= clean($filterBarangay) ?>"><?php endif; ?>
        <?php if (!empty($filterStatus)): ?><input type="hidden" name="status" value="<?= clean($filterStatus) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:0 14px;font-size:11px;">Search</button>
        <?php if (!empty($searchQuery) || !empty($filterBarangay) || !empty($filterStatus)): ?>
          <a href="evacuation.php" class="btn btn-outline btn-sm" style="padding:0 10px;font-size:11px;display:flex;align-items:center;">Reset</a>
        <?php endif; ?>
      </form>

      <!-- Quick Filter Pills -->
      <div class="tactical-filter-pills">
        <a href="evacuation.php" class="tactical-pill-btn <?= (empty($filterBarangay) && empty($filterStatus)) ? 'active' : '' ?>">
          All Camps (<?= $totalCamps ?>)
        </a>
        <a href="evacuation.php?status=Open%20/%20Active" class="tactical-pill-btn <?= ($filterStatus === 'Open / Active') ? 'active' : '' ?>">
          Open / Active (<?= $activeSheltering ?>)
        </a>
        <a href="evacuation.php?status=Standby" class="tactical-pill-btn <?= ($filterStatus === 'Standby') ? 'active' : '' ?>">
          Standby (<?= $standbyCamps ?>)
        </a>
      </div>
    </div>

    <!-- Responsive Layout Split -->
    <div class="tactical-content-split">

      <!-- Column 1: Camps List -->
      <div id="tacticalListSection" style="display:flex;flex-direction:column;gap:10px;">
        <?php if (empty($centers)): ?>
          <div class="tactical-camp-card" style="padding:36px;text-align:center;color:var(--color-text-muted);">
            No evacuation camps match your search criteria.
          </div>
        <?php else: ?>
          <?php foreach ($centers as $center): 
            $cap = (int)$center['capacity_individuals'];
            $current = (int)$center['current_evacuees_count'];
            $pct = $cap > 0 ? min(100, round(($current / $cap) * 100)) : 0;
            $barColor = $pct >= 90 ? 'var(--color-danger, #C62828)' : ($pct >= 75 ? 'var(--color-warning, #B78103)' : 'var(--color-success, #2E7D32)');
            
            $accessClass = 'all-vehicles';
            if (stripos($center['accessibility'], '4x4') !== false || stripos($center['accessibility'], 'High-Clearance') !== false) {
                $accessClass = 'high-clearance';
            } elseif (stripos($center['accessibility'], 'Foot') !== false || stripos($center['accessibility'], 'Inaccessible') !== false) {
                $accessClass = 'inaccessible';
            }
          ?>
            <div class="tactical-camp-card" 
                 id="camp-card-<?= (int)$center['id'] ?>"
                 data-id="<?= (int)$center['id'] ?>"
                 data-lat="<?= (float)$center['coordinates_lat'] ?>"
                 data-lng="<?= (float)$center['coordinates_lng'] ?>"
                 data-name="<?= clean($center['name']) ?>"
                 data-barangay="<?= clean($center['barangay_name']) ?>"
                 data-address="<?= clean($center['location_address']) ?>"
                 data-access="<?= clean($center['accessibility']) ?>"
                 data-officer="<?= clean($center['contact_officer']) ?>"
                 data-phone="<?= clean($center['contact_number']) ?>">
              
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div>
                  <h3 style="font-family:var(--font-primary);font-size:13.5px;font-weight:700;color:var(--color-primary);margin:0;">
                    <?= clean($center['name']) ?>
                  </h3>
                  <div style="font-size:10.5px;color:var(--color-text-secondary);margin-top:2px;">
                    Brgy. <?= clean($center['barangay_name']) ?> &bull; <?= clean($center['center_type']) ?>
                  </div>
                </div>
                <div><?= renderStatusBadge($center['status']) ?></div>
              </div>

              <!-- Distance & Convoy Accessibility -->
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:10.5px;flex-wrap:wrap;gap:6px;">
                <span class="road-access-badge <?= $accessClass ?>">
                  Convoy: <?= clean($center['accessibility']) ?>
                </span>
                <span class="badge badge-neutral" id="tac-dist-pill-<?= (int)$center['id'] ?>" style="font-size:10px;font-weight:700;">
                  Calculating distance...
                </span>
              </div>

              <!-- Headcount Progress Bar -->
              <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 10px;">
                <div style="display:flex;justify-content:space-between;font-size:10.5px;font-family:var(--font-secondary);">
                  <span style="font-weight:600;">Camp Headcount</span>
                  <span style="font-weight:700;color:<?= $barColor ?>;">
                    <?= number_format($current) ?> / <?= number_format($cap) ?> (<?= $pct ?>%)
                  </span>
                </div>
                <div style="width:100%;height:6px;background:#D9E0E3;border-radius:3px;overflow:hidden;margin-top:4px;">
                  <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px;"></div>
                </div>
              </div>

              <!-- Camp Officer Contact -->
              <div style="font-size:10.5px;color:var(--color-text-secondary);">
                Camp Manager: <strong><?= clean($center['contact_officer']) ?></strong> &bull; 
                <a href="tel:<?= clean($center['contact_number']) ?>" style="color:var(--color-secondary);font-weight:600;"><?= clean($center['contact_number']) ?></a>
              </div>

              <!-- Action Footer -->
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;border-top:1px solid var(--color-border-light);padding-top:10px;margin-top:2px;">
                <button type="button" 
                        class="btn btn-outline btn-sm" 
                        onclick="openUpdateEvacModal(<?= htmlspecialchars(json_encode($center)) ?>)"
                        style="font-size:11px;padding:6px 12px;border-radius:var(--radius-sm);">
                  Update Headcount
                </button>

                <!-- The Prominent Route Button -->
                <button type="button" 
                        class="tactical-route-btn" 
                        onclick="startTacticalRoute(<?= (int)$center['id'] ?>)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                  Route
                </button>
              </div>

            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Column 2: Tactical Live Route Map -->
      <div id="tacticalMapSection" class="tactical-map-wrapper">
        <div style="padding:10px 14px;background:var(--color-surface-subtle);border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:11px;font-weight:700;color:var(--color-primary);text-transform:uppercase;">
            Tactical Convoy Route Map
          </span>
          <span style="font-size:10px;color:var(--color-text-muted);">Real-Time GPS Vector</span>
        </div>

        <div id="tactical-evac-map"></div>

        <!-- In-App Route Bottom Sheet -->
        <div id="tacticalRouteSheet" class="tactical-route-sheet">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
            <div>
              <span class="badge badge-success" style="font-size:9.5px;padding:2px 6px;">Convoy Route Target</span>
              <h4 id="tacRouteTargetName" style="font-size:13.5px;font-weight:700;color:var(--color-primary);margin:4px 0 2px 0;">Camp Target</h4>
              <div id="tacRouteTargetAddress" style="font-size:10.5px;color:var(--color-text-secondary);">—</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearTacticalRoute()" style="padding:4px 8px;font-size:10px;">Close Route</button>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;">
            <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 12px;text-align:center;">
              <div style="font-family:var(--font-secondary);font-weight:700;font-size:14px;color:var(--color-primary);" id="tacRouteDistance">0.0 km</div>
              <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;">Distance</div>
            </div>
            <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 12px;text-align:center;">
              <div style="font-family:var(--font-secondary);font-weight:700;font-size:14px;color:var(--color-primary);" id="tacRouteConvoyEta">0 min</div>
              <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;">Convoy ETA</div>
            </div>
            <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:6px;padding:8px 12px;text-align:center;">
              <div style="font-family:var(--font-secondary);font-weight:700;font-size:12px;color:var(--color-secondary);" id="tacRouteAccessHint">Accessible</div>
              <div style="font-size:9.5px;color:var(--color-text-secondary);text-transform:uppercase;">Passability</div>
            </div>
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="tacGmapsBtn" 
               href="#" 
               target="_blank" 
               rel="noopener noreferrer" 
               class="tactical-route-btn" 
               style="flex:1;text-align:center;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
              Start GPS Convoy Navigation
            </a>
            <a id="tacCallOfficerBtn" 
               href="#" 
               class="btn btn-secondary" 
               style="font-size:11.5px;font-weight:600;border-radius:10px;padding:8px 14px;display:flex;align-items:center;gap:6px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              Call Camp Desk
            </a>
          </div>
        </div>

      </div>

    </div>
  </div>
</main>

<!-- Modal: Update Center Status & Evacuees -->
<div class="modal-overlay" id="updateEvacModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="updateEvacModalTitle">Update Evacuation Center</h3>
      <button class="modal-close-btn" onclick="closeModal('updateEvacModal')">&times;</button>
    </div>
    <form id="updateEvacForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/update_status.php">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="center_id" id="editCenterId">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Operational Status</label>
          <select name="status" id="editStatusSelect" class="form-control" required>
            <option value="Open / Active">Open / Active (Sheltering Evacuees)</option>
            <option value="Standby">Standby (Ready to Receive)</option>
            <option value="At Capacity">At Capacity (Camp Full)</option>
            <option value="Closed">Closed</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Current Sheltered Evacuees</label>
          <input type="number" name="evacuees_count" id="editEvacCountInput" class="form-control" min="0" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Road / Route Accessibility</label>
          <select name="accessibility" id="editAccessibilitySelect" class="form-control" required>
            <option value="Accessible (All Vehicles)">Accessible (All Vehicles)</option>
            <option value="High-Clearance / 4x4 Only">High-Clearance / 4x4 Only</option>
            <option value="Foot Access Only">Foot Access Only</option>
            <option value="Temporarily Inaccessible">Temporarily Inaccessible</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('updateEvacModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Updates</button>
      </div>
    </form>
  </div>
</div>

<script>
const responderCamps = <?= json_encode($centers) ?>;
let respLat = 8.2280;
let respLng = 124.2452;
let tacMap = null;
let respMarker = null;
let campMarkers = {};
let activeTacRouteLine = null;

function calculateHaversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

function updateTacDistances() {
  responderCamps.forEach(c => {
    const cLat = parseFloat(c.coordinates_lat);
    const cLng = parseFloat(c.coordinates_lng);
    const pill = document.getElementById(`tac-dist-pill-${c.id}`);
    if (pill && !isNaN(cLat) && !isNaN(cLng)) {
      const d = calculateHaversineKm(respLat, respLng, cLat, cLng);
      pill.innerText = `${d.toFixed(1)} km away`;
    }
  });
}

function switchTacticalView(view) {
  const listSec = document.getElementById('tacticalListSection');
  const mapSec = document.getElementById('tacticalMapSection');
  const btnList = document.getElementById('btnTacList');
  const btnMap = document.getElementById('btnTacMap');

  if (window.innerWidth <= 900) {
    if (view === 'map') {
      listSec.style.display = 'none';
      mapSec.style.display = 'block';
      btnMap.classList.add('active');
      btnList.classList.remove('active');
      if (tacMap) setTimeout(() => tacMap.invalidateSize(), 200);
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

function locateResponderGPS() {
  const txt = document.getElementById('respGpsText');
  const dot = document.getElementById('respGpsDot');

  if (navigator.geolocation) {
    txt.innerText = "Acquiring Field GPS...";
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        respLat = pos.coords.latitude;
        respLng = pos.coords.longitude;
        txt.innerText = `Field GPS Active (${respLat.toFixed(4)}, ${respLng.toFixed(4)})`;
        dot.style.background = 'var(--color-success, #2E7D32)';
        updateTacDistances();
        if (tacMap) {
          if (respMarker) {
            respMarker.setLatLng([respLat, respLng]);
          } else {
            respMarker = L.circleMarker([respLat, respLng], {
              radius: 9,
              fillColor: '#17324D',
              color: '#FFFFFF',
              weight: 2,
              fillOpacity: 1
            }).addTo(tacMap).bindPopup("<strong>Responder Current Unit Position</strong>");
          }
        }
      },
      () => {
        txt.innerText = "Using ICDRRMO Command Base Reference";
        dot.style.background = 'var(--color-warning, #B78103)';
        updateTacDistances();
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  } else {
    updateTacDistances();
  }
}

function startTacticalRoute(campId) {
  const camp = responderCamps.find(c => parseInt(c.id) === parseInt(campId));
  if (!camp) return;

  const destLat = parseFloat(camp.coordinates_lat);
  const destLng = parseFloat(camp.coordinates_lng);

  if (isNaN(destLat) || isNaN(destLng)) {
    alert("Coordinates for this camp are currently being verified.");
    return;
  }

  document.querySelectorAll('.tactical-camp-card').forEach(c => c.classList.remove('active-routed'));
  const card = document.getElementById(`camp-card-${campId}`);
  if (card) card.classList.add('active-routed');

  const distKm = calculateHaversineKm(respLat, respLng, destLat, destLng);
  const convoyMins = Math.max(1, Math.round((distKm / 35) * 60));

  document.getElementById('tacRouteTargetName').innerText = camp.name;
  document.getElementById('tacRouteTargetAddress').innerText = `${camp.barangay_name} • ${camp.location_address}`;
  document.getElementById('tacRouteDistance').innerText = `${distKm.toFixed(1)} km`;
  document.getElementById('tacRouteConvoyEta').innerText = `~${convoyMins} min`;
  document.getElementById('tacRouteAccessHint').innerText = camp.accessibility.includes('4x4') ? '4x4 Required' : 'All Passable';

  const gmapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${respLat},${respLng}&destination=${destLat},${destLng}&travelmode=driving`;
  document.getElementById('tacGmapsBtn').href = gmapsUrl;

  const phone = camp.contact_number || '';
  document.getElementById('tacCallOfficerBtn').href = phone ? `tel:${phone}` : '#';

  document.getElementById('tacticalRouteSheet').classList.add('active');

  if (window.innerWidth <= 900) {
    switchTacticalView('map');
  }

  if (tacMap) {
    if (activeTacRouteLine) {
      tacMap.removeLayer(activeTacRouteLine);
    }

    activeTacRouteLine = L.polyline([
      [respLat, respLng],
      [destLat, destLng]
    ], {
      color: '#17324D',
      weight: 4,
      dashArray: '6, 6',
      opacity: 0.85
    }).addTo(tacMap);

    tacMap.fitBounds([
      [respLat, respLng],
      [destLat, destLng]
    ], { padding: [50, 50], maxZoom: 16 });

    if (campMarkers[campId]) {
      campMarkers[campId].openPopup();
    }
  }
}

function clearTacticalRoute() {
  document.getElementById('tacticalRouteSheet').classList.remove('active');
  document.querySelectorAll('.tactical-camp-card').forEach(c => c.classList.remove('active-routed'));
  if (tacMap && activeTacRouteLine) {
    tacMap.removeLayer(activeTacRouteLine);
    activeTacRouteLine = null;
    tacMap.setView([respLat, respLng], 14);
  }
}

function openUpdateEvacModal(center) {
  document.getElementById('editCenterId').value = center.id;
  document.getElementById('updateEvacModalTitle').innerText = `Update ${center.name}`;
  document.getElementById('editStatusSelect').value = center.status;
  document.getElementById('editEvacCountInput').value = center.current_evacuees_count;
  document.getElementById('editAccessibilitySelect').value = center.accessibility;
  openModal('updateEvacModal');
}

document.addEventListener('DOMContentLoaded', function() {
  tacMap = L.map('tactical-evac-map').setView([respLat, respLng], 14);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap'
  }).addTo(tacMap);

  respMarker = L.circleMarker([respLat, respLng], {
    radius: 9,
    fillColor: '#17324D',
    color: '#FFFFFF',
    weight: 2,
    fillOpacity: 1
  }).addTo(tacMap).bindPopup("<strong>Responder Unit Reference</strong>");

  responderCamps.forEach(c => {
    const cLat = parseFloat(c.coordinates_lat);
    const cLng = parseFloat(c.coordinates_lng);
    if (!isNaN(cLat) && !isNaN(cLng)) {
      const isOpen = (c.status === 'Open / Active' || c.status === 'Open');
      const isFull = (c.status === 'At Capacity');
      const markerColor = isFull ? '#C62828' : (isOpen ? '#2E7D32' : '#B78103');

      const m = L.circleMarker([cLat, cLng], {
        radius: 9,
        fillColor: markerColor,
        color: '#FFFFFF',
        weight: 2,
        fillOpacity: 0.95
      }).addTo(tacMap);

      m.bindPopup(`
        <div style="font-family:var(--font-primary, sans-serif);font-size:12px;line-height:1.4;">
          <strong style="color:var(--color-primary);font-size:13px;">${c.name}</strong><br>
          <span style="font-size:11px;color:#66737D;">Brgy. ${c.barangay_name}</span><br>
          <b>Status:</b> ${c.status}<br>
          <b>Headcount:</b> ${c.current_evacuees_count} / ${c.capacity_individuals}<br>
          <b>Access:</b> ${c.accessibility}<br>
          <div style="margin-top:6px;">
            <button onclick="startTacticalRoute(${c.id})" class="btn btn-primary btn-sm" style="font-size:10.5px;padding:3px 10px;border-radius:6px;width:100%;">
              Plot Tactical Route
            </button>
          </div>
        </div>
      `);

      campMarkers[c.id] = m;
    }
  });

  locateResponderGPS();

  window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
      document.getElementById('tacticalListSection').style.display = 'flex';
      document.getElementById('tacticalMapSection').style.display = 'block';
    }
  });
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
