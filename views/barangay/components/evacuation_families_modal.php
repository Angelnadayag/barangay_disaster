<?php
// ============================================================================
// Component: Evacuation Camp Household Families Directory & Roster Modals
// Full family-level tracking: intake check-in, room assignments, vulnerability flags,
// relief pack distribution ledger, and DROMIC camp manifest generation.
// ============================================================================
?>

<style>
  /* =========================================================================
     MODAL OVERLAYS & SHELL STYLING
     ========================================================================= */
  #modalEvacueeFamilies.modal-overlay,
  #modalCheckinFamily.modal-overlay,
  #modalDistributeEvacRelief.modal-overlay,
  #modalEditEvacCheckin.modal-overlay,
  #modalCheckoutFamily.modal-overlay,
  #modalDromicManifest.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.52) !important;
    backdrop-filter: blur(2px) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1080 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }
  #modalEvacueeFamilies.modal-overlay.active,
  #modalCheckinFamily.modal-overlay.active,
  #modalDistributeEvacRelief.modal-overlay.active,
  #modalEditEvacCheckin.modal-overlay.active,
  #modalCheckoutFamily.modal-overlay.active,
  #modalDromicManifest.modal-overlay.active {
    display: flex !important;
  }

  /* Telemetry mini-cards in modal header */
  .ef-telemetry-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 8px;
    margin-bottom: 12px;
  }
  .ef-tele-card {
    background: var(--color-surface, #FFFFFF);
    border: 1px solid var(--color-border-light, #E7EDF0);
    border-radius: var(--radius-sm, 6px);
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
  }
  .ef-tele-label {
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-text-secondary, #66737D);
  }
  .ef-tele-val {
    font-size: 16px;
    font-weight: 700;
    font-family: var(--font-secondary, inherit);
    color: var(--color-primary, #17324D);
    margin-top: 2px;
  }
  .ef-tele-meta {
    font-size: 9px;
    color: var(--color-text-muted, #8A96A0);
  }

  /* Filter Toolbar */
  .ef-toolbar {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    background: var(--color-surface-subtle, #F0F3F4);
    border: 1px solid var(--color-border-light, #E7EDF0);
    border-radius: var(--radius-sm, 6px);
    padding: 8px 10px;
    margin-bottom: 12px;
  }

  /* Roster Table */
  .ef-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--color-border-light, #E7EDF0);
    border-radius: var(--radius-sm, 6px);
    background: var(--color-surface, #FFFFFF);
    max-height: 52vh;
    overflow-y: auto;
  }
  .ef-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    text-align: left;
  }
  .ef-table th {
    background: var(--color-surface-subtle, #F0F3F4);
    padding: 8px 10px;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-text-secondary, #66737D);
    border-bottom: 1px solid var(--color-border, #D9E0E3);
    position: sticky;
    top: 0;
    z-index: 2;
  }
  .ef-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0);
    color: var(--color-text, #24313A);
    vertical-align: middle;
  }
  .ef-table tr:hover td {
    background: #F8FAFC;
  }

  /* Status Badges */
  .badge-sheltered {
    background: #E8F5E9;
    color: #2E7D32;
    border: 1px solid #C8E6C9;
    font-weight: 600;
    font-size: 9.5px;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
  }
  .badge-decamped {
    background: #ECEFF1;
    color: #546E7A;
    border: 1px solid #CFD8DC;
    font-weight: 600;
    font-size: 9.5px;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
  }
  .badge-pwd {
    background: #FFF3E0;
    color: #E65100;
    border: 1px solid #FFE0B2;
    font-weight: 600;
    font-size: 9px;
    padding: 1px 5px;
    border-radius: 3px;
  }
  .badge-senior {
    background: #E0F2F1;
    color: #00695C;
    border: 1px solid #B2DFDB;
    font-weight: 600;
    font-size: 9px;
    padding: 1px 5px;
    border-radius: 3px;
  }
  .badge-room {
    background: #E3F2FD;
    color: #1565C0;
    border: 1px solid #BBDEFB;
    font-weight: 600;
    font-size: 9.5px;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: var(--font-secondary, inherit);
  }

  /* Preset chip tags */
  .preset-chip {
    font-size: 9.5px;
    padding: 2px 8px;
    background: var(--color-surface-subtle, #F0F3F4);
    border: 1px solid var(--color-border, #D9E0E3);
    border-radius: 12px;
    cursor: pointer;
    color: var(--color-primary, #17324D);
    transition: all 0.15s ease;
  }
  .preset-chip:hover {
    background: #E2E8F0;
    border-color: var(--color-secondary, #2F6F73);
  }

  /* Printable DROMIC manifest styles */
  @media print {
    body * {
      visibility: hidden !important;
    }
    #modalDromicManifest, #modalDromicManifest * {
      visibility: visible !important;
    }
    #modalDromicManifest {
      position: absolute !important;
      left: 0 !important;
      top: 0 !important;
      width: 100% !important;
      height: auto !important;
      background: #FFFFFF !important;
      padding: 0 !important;
    }
    .no-print {
      display: none !important;
    }
  }
</style>

<!-- ========================================================================= -->
<!-- MODAL 1: EVACUEE FAMILIES ROSTER & CAMP MANAGEMENT MODAL                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalEvacueeFamilies">
  <div class="modal-dialog" style="max-width:1080px;width:96%;max-height:92vh;display:flex;flex-direction:column;">
    <!-- Modal Header -->
    <div class="modal-header" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <h3 class="modal-title" id="efModalCenterName" style="margin:0;font-size:14px;font-weight:700;color:var(--color-primary,#17324D);">
            Evacuee Family Roster
          </h3>
          <span id="efModalStatusBadge"></span>
          <span id="efModalCenterTypeBadge" class="badge badge-neutral" style="font-size:9.5px;"></span>
        </div>
        <p id="efModalCenterSub" style="font-size:10.5px;color:var(--color-text-secondary,#66737D);margin:2px 0 0 0;">
          Camp Family Directory, Room Allocations, Vulnerability Disaggregation, and Relief Payouts.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeEvacueeFamiliesModal()">&times;</button>
    </div>

    <!-- Modal Body -->
    <div class="modal-body" style="padding:14px 18px;overflow-y:auto;flex:1;">
      <!-- Telemetry Grid -->
      <div class="ef-telemetry-grid">
        <div class="ef-tele-card">
          <span class="ef-tele-label">Sheltered Families</span>
          <span class="ef-tele-val" id="efTeleFamilies">0</span>
          <span class="ef-tele-meta" id="efTeleFamilyCap">Of 0 Capacity</span>
        </div>
        <div class="ef-tele-card">
          <span class="ef-tele-label">Individuals</span>
          <span class="ef-tele-val" id="efTeleIndividuals">0</span>
          <span class="ef-tele-meta" id="efTeleIndCap">Of 0 Capacity</span>
        </div>
        <div class="ef-tele-card">
          <span class="ef-tele-label">PWDs Sheltered</span>
          <span class="ef-tele-val" id="efTelePwds" style="color:#E65100;">0</span>
          <span class="ef-tele-meta">Priority Mobility</span>
        </div>
        <div class="ef-tele-card">
          <span class="ef-tele-label">Senior Citizens</span>
          <span class="ef-tele-val" id="efTeleSeniors" style="color:#00695C;">0</span>
          <span class="ef-tele-meta">Elderly Care</span>
        </div>
        <div class="ef-tele-card">
          <span class="ef-tele-label">Relief Packs Given</span>
          <span class="ef-tele-val" id="efTeleRelief" style="color:var(--color-secondary,#2F6F73);">0</span>
          <span class="ef-tele-meta">Family Food Packs</span>
        </div>
        <div class="ef-tele-card">
          <span class="ef-tele-label">Hot Meals Served</span>
          <span class="ef-tele-val" id="efTeleMeals" style="color:var(--color-primary,#17324D);">0</span>
          <span class="ef-tele-meta">Community Kitchen</span>
        </div>
      </div>

      <!-- Filter Toolbar & Action Buttons -->
      <div class="ef-toolbar">
        <div style="flex:1;min-width:180px;">
          <input type="text" id="efSearchInput" class="form-control" placeholder="Search by family, head name, code, room..." style="font-size:11px;padding:5px 8px;" oninput="filterEvacRosterTable()">
        </div>

        <div style="min-width:130px;">
          <select id="efPurokFilter" class="form-control" style="font-size:11px;padding:5px 8px;" onchange="filterEvacRosterTable()">
            <option value="">All Origin Puroks</option>
            <?php foreach ($allPuroks as $p): ?>
              <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="min-width:130px;">
          <select id="efVulnerabilityFilter" class="form-control" style="font-size:11px;padding:5px 8px;" onchange="filterEvacRosterTable()">
            <option value="">All Demographics</option>
            <option value="pwd">With PWD Members</option>
            <option value="seniors">With Senior Citizens</option>
            <option value="minors">With Minor Children</option>
            <option value="medical">Has Medical / Dietary Needs</option>
          </select>
        </div>

        <div style="min-width:120px;">
          <select id="efStatusFilter" class="form-control" style="font-size:11px;padding:5px 8px;" onchange="filterEvacRosterTable()">
            <option value="Sheltered" selected>Currently Sheltered</option>
            <option value="Decamped">Decamped / Returned</option>
            <option value="">All Records</option>
          </select>
        </div>

        <div style="display:flex;gap:6px;margin-left:auto;flex-wrap:wrap;">
          <button type="button" class="btn btn-outline btn-sm" onclick="openDromicManifestModal()" style="font-size:10px;padding:4px 9px;">
            🖨️ DROMIC Roster
          </button>
          <button type="button" class="btn btn-primary btn-sm" onclick="openCheckinFamilyModal()" style="font-size:10px;padding:4px 10px;">
            + Check-In Family
          </button>
        </div>
      </div>

      <!-- Roster Table -->
      <div class="ef-table-wrap">
        <table class="ef-table">
          <thead>
            <tr>
              <th style="width:110px;">Code / Family</th>
              <th style="width:140px;">Head of Family</th>
              <th style="width:100px;">Origin Purok</th>
              <th style="width:120px;">Room / Tent / Bay</th>
              <th style="width:110px;text-align:center;">Sheltered</th>
              <th style="width:130px;">Medical / Needs</th>
              <th style="width:110px;text-align:center;">Relief Packs</th>
              <th style="width:90px;text-align:center;">Status</th>
              <th style="width:130px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody id="efRosterTableBody">
            <!-- Dynamically populated via JavaScript -->
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="modal-footer" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <span id="efRosterFooterCount" style="font-size:10.5px;color:var(--color-text-secondary,#66737D);">
        Showing 0 families
      </span>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeEvacueeFamiliesModal()">Close Roster</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: CHECK-IN HOUSEHOLD FAMILY INTAKE TOOL                            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalCheckinFamily">
  <div class="modal-dialog" style="max-width:680px;width:95%;max-height:90vh;display:flex;flex-direction:column;">
    <div class="modal-header" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h3 class="modal-title" style="margin:0;font-size:13.5px;font-weight:700;color:var(--color-primary,#17324D);">
          Admit & Check-In Household Family
        </h3>
        <p style="font-size:10px;color:var(--color-text-secondary,#66737D);margin:2px 0 0 0;">
          Select from registered Hinaplanon households, assign room, record vulnerabilities, and issue initial relief.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalCheckinFamily')">&times;</button>
    </div>

    <form id="checkinFamilyForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/checkin_family.php" style="margin:0;display:flex;flex-direction:column;flex:1;overflow:hidden;">
      <input type="hidden" name="action" value="checkin">
      <input type="hidden" name="evacuation_area_id" id="checkin_evacuation_area_id">
      <input type="hidden" name="household_id" id="checkin_household_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body" style="padding:16px 18px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;flex:1;">
        <!-- Filter Purok & Household Selector -->
        <div style="background:#F8FAFC;border:1px solid #E2E8F0;padding:10px 12px;border-radius:6px;">
          <div class="form-row">
            <div class="form-group" style="margin-bottom:0;flex:1;">
              <label class="form-label" style="font-size:10px;margin-bottom:3px;">Filter by Origin Purok</label>
              <select id="checkin_purok_filter" class="form-control" style="font-size:11px;padding:4px 8px;" onchange="updateCheckinHouseholdOptions()">
                <option value="">All Hinaplanon Puroks (<?= count($allHouseholds) ?> Families)</option>
                <?php foreach ($allPuroks as $p): ?>
                  <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;margin-top:8px;">
            <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Select Household Family</label>
            <select id="checkin_household_select" class="form-control" style="font-size:11px;padding:5px 8px;" required onchange="onCheckinHouseholdSelected(this)">
              <option value="">-- Choose Household Family --</option>
              <!-- Populated dynamically via JS -->
            </select>
          </div>
        </div>

        <!-- Selected Family Details Preview Card -->
        <div id="checkinFamilyCard" style="display:none;background:var(--color-surface,#FFFFFF);border:1px solid var(--color-border,#D9E0E3);border-left:3px solid var(--color-secondary,#2F6F73);padding:10px 12px;border-radius:6px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
              <span id="cfCardCode" style="font-size:9.5px;font-family:var(--font-secondary);font-weight:700;color:var(--color-primary);"></span>
              <h4 id="cfCardName" style="margin:2px 0 0 0;font-size:12px;color:var(--color-text);font-weight:700;"></h4>
              <div id="cfCardHead" style="font-size:10.5px;color:var(--color-secondary);font-weight:600;margin-top:2px;"></div>
            </div>
            <div style="text-align:right;">
              <span id="cfCardPurok" class="badge badge-neutral" style="font-size:9.5px;"></span>
              <div id="cfCardEcon" style="font-size:9px;color:var(--color-text-muted);margin-top:2px;"></div>
            </div>
          </div>

          <div style="display:flex;gap:10px;margin-top:8px;padding-top:6px;border-top:1px solid var(--color-border-light);font-size:10px;color:var(--color-text-secondary);flex-wrap:wrap;">
            <span>Registered Total: <strong id="cfCardTotalMem" style="color:var(--color-primary);">0</strong></span>
            <span>Seniors: <strong id="cfCardSeniors" style="color:#00695C;">0</strong></span>
            <span>PWDs: <strong id="cfCardPwds" style="color:#E65100;">0</strong></span>
            <span>Priority: <strong id="cfCardPriority">Standard</strong></span>
          </div>
        </div>

        <!-- Room / Tent / Bay Assignment -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Room / Tent / Bay / Sector</label>
          <input type="text" name="room_tent_number" id="checkin_room_tent" class="form-control" placeholder="e.g. Classroom 102, Bleacher Sector B, Modular Tent 4" required style="font-size:11px;padding:5px 8px;">
          <div style="display:flex;gap:4px;margin-top:4px;flex-wrap:wrap;align-items:center;">
            <span style="font-size:9.5px;color:var(--color-text-muted);">Quick Presets:</span>
            <span class="preset-chip" onclick="setRoomPreset('Gym Bleacher Sec A')">Bleacher Sec A</span>
            <span class="preset-chip" onclick="setRoomPreset('Classroom 101')">Classroom 101</span>
            <span class="preset-chip" onclick="setRoomPreset('Classroom 102')">Classroom 102</span>
            <span class="preset-chip" onclick="setRoomPreset('Court Bay North')">Court Bay North</span>
            <span class="preset-chip" onclick="setRoomPreset('Modular Tent 1')">Modular Tent 1</span>
            <span class="preset-chip" onclick="setRoomPreset('Stage Wing Sector')">Stage Wing</span>
          </div>
        </div>

        <!-- Disaggregated Headcounts Sheltered -->
        <div style="background:#F8FAFC;border:1px solid #E2E8F0;padding:10px 12px;border-radius:6px;">
          <label class="form-label" style="font-size:10px;margin-bottom:6px;font-weight:700;color:var(--color-primary);">
            Sheltered Family Members Disaggregation
          </label>
          <div class="form-row">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required" style="font-size:9.5px;">Members Sheltered</label>
              <input type="number" name="members_count" id="checkin_members_count" class="form-control" min="1" required style="font-size:11px;padding:4px 8px;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="font-size:9.5px;">PWD Members</label>
              <input type="number" name="pwd_count" id="checkin_pwd_count" class="form-control" min="0" value="0" style="font-size:11px;padding:4px 8px;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="font-size:9.5px;">Senior Citizens</label>
              <input type="number" name="seniors_count" id="checkin_seniors_count" class="form-control" min="0" value="0" style="font-size:11px;padding:4px 8px;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" style="font-size:9.5px;">Minor Children</label>
              <input type="number" name="minors_count" id="checkin_minors_count" class="form-control" min="0" value="0" style="font-size:11px;padding:4px 8px;">
            </div>
          </div>
        </div>

        <!-- Special Medical / Dietary Needs -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Special Medical, Mobility, or Dietary Needs</label>
          <input type="text" name="special_medical_needs" id="checkin_medical_needs" class="form-control" placeholder="e.g. Hypertension maintenance, Infant 3 months (needs milk), Wheelchair user, Asthma" style="font-size:11px;padding:5px 8px;">
        </div>

        <!-- Initial Relief Kit Provision -->
        <div style="background:#FFFDE7;border:1px solid #FFF59D;padding:8px 12px;border-radius:6px;display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="give_initial_relief" id="checkin_give_initial_relief" value="1" checked style="cursor:pointer;accent-color:var(--color-secondary);">
          <label for="checkin_give_initial_relief" style="font-size:10.5px;color:#795548;margin:0;cursor:pointer;font-weight:600;">
            📦 Issue Initial Family Food Pack upon intake (+1 recorded to Camp Relief Ledger)
          </label>
        </div>

        <!-- Notes / Remarks -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Intake Officer Remarks</label>
          <input type="text" name="notes" id="checkin_notes" class="form-control" placeholder="Optional registration notes" style="font-size:11px;padding:4px 8px;">
        </div>
      </div>

      <div class="modal-footer" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalCheckinFamily')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitCheckin">Admit & Sheltered</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: DISTRIBUTE RELIEF TO SHELTERED FAMILY                            -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalDistributeEvacRelief">
  <div class="modal-dialog" style="max-width:480px;width:95%;">
    <div class="modal-header" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h3 class="modal-title" style="margin:0;font-size:13.5px;font-weight:700;color:var(--color-primary,#17324D);">
          Issue Evacuee Relief Provision
        </h3>
        <p style="font-size:10px;color:var(--color-text-secondary,#66737D);margin:2px 0 0 0;">
          Record relief pack or hot meal distribution for the designated family.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalDistributeEvacRelief')">&times;</button>
    </div>

    <form id="distributeEvacReliefForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/distribute_relief.php" style="margin:0;">
      <input type="hidden" name="action" value="distribute_relief">
      <input type="hidden" name="checkin_id" id="relief_checkin_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body" style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">
        <!-- Beneficiary Banner -->
        <div style="background:#F0F4F8;border:1px solid #D2DFEB;padding:8px 12px;border-radius:6px;">
          <div style="font-size:9.5px;color:var(--color-text-muted);font-family:var(--font-secondary);" id="relief_family_code"></div>
          <div style="font-weight:700;font-size:12px;color:var(--color-primary);" id="relief_family_name"></div>
          <div style="font-size:10px;color:var(--color-text-secondary);" id="relief_family_head"></div>
          <div style="font-size:9.5px;color:var(--color-secondary);margin-top:2px;" id="relief_family_room"></div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Relief Provision Type</label>
          <select name="item_type" id="relief_item_type" class="form-control" required style="font-size:11px;padding:5px 8px;">
            <option value="Family Food Pack (DSWD Standard)">Family Food Pack (DSWD Standard)</option>
            <option value="Community Kitchen Hot Meal">Community Kitchen Hot Meal</option>
            <option value="Hygiene & Sanitation Kit">Hygiene & Sanitation Kit</option>
            <option value="Sleeping Mat & Thermal Blanket">Sleeping Mat & Thermal Blanket</option>
            <option value="Infant / Maternal Care Kit">Infant / Maternal Care Kit</option>
            <option value="Drinking Water 5-Gallon Container">Drinking Water 5-Gallon Container</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;flex:1;">
            <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Quantity</label>
            <input type="number" name="quantity" id="relief_quantity" class="form-control" min="1" value="1" required style="font-size:11px;padding:4px 8px;">
          </div>
          <div class="form-group" style="margin-bottom:0;flex:1;">
            <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Unit</label>
            <input type="text" name="unit" id="relief_unit" class="form-control" value="packs" required style="font-size:11px;padding:4px 8px;">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Notes / Remarks</label>
          <input type="text" name="notes" id="relief_notes" class="form-control" placeholder="e.g. Batch 2 relief round, distributed at tent" style="font-size:11px;padding:4px 8px;">
        </div>
      </div>

      <div class="modal-footer" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalDistributeEvacRelief')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Record Provision</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: EDIT CHECK-IN DETAILS & ROOM ALLOCATION                          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalEditEvacCheckin">
  <div class="modal-dialog" style="max-width:540px;width:95%;">
    <div class="modal-header" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h3 class="modal-title" style="margin:0;font-size:13.5px;font-weight:700;color:var(--color-primary,#17324D);">
          Update Sheltered Family Details
        </h3>
        <p style="font-size:10px;color:var(--color-text-secondary,#66737D);margin:2px 0 0 0;">
          Adjust room/tent assignment, member count, or medical alerts.
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalEditEvacCheckin')">&times;</button>
    </div>

    <form id="editEvacCheckinForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/update_checkin.php" style="margin:0;">
      <input type="hidden" name="action" value="update_checkin">
      <input type="hidden" name="checkin_id" id="edit_checkin_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body" style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">
        <div style="background:#F0F4F8;border:1px solid #D2DFEB;padding:8px 12px;border-radius:6px;">
          <div style="font-weight:700;font-size:12px;color:var(--color-primary);" id="edit_checkin_family_name"></div>
          <div style="font-size:10px;color:var(--color-text-secondary);" id="edit_checkin_head_name"></div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Room / Tent / Bay / Sector</label>
          <input type="text" name="room_tent_number" id="edit_checkin_room_tent" class="form-control" required style="font-size:11px;padding:5px 8px;">
        </div>

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required" style="font-size:9.5px;">Members Sheltered</label>
            <input type="number" name="members_count" id="edit_checkin_members_count" class="form-control" min="1" required style="font-size:11px;padding:4px 8px;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:9.5px;">PWD Members</label>
            <input type="number" name="pwd_count" id="edit_checkin_pwd_count" class="form-control" min="0" style="font-size:11px;padding:4px 8px;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:9.5px;">Senior Citizens</label>
            <input type="number" name="seniors_count" id="edit_checkin_seniors_count" class="form-control" min="0" style="font-size:11px;padding:4px 8px;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:9.5px;">Minors</label>
            <input type="number" name="minors_count" id="edit_checkin_minors_count" class="form-control" min="0" style="font-size:11px;padding:4px 8px;">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Special Medical, Mobility, or Dietary Needs</label>
          <input type="text" name="special_medical_needs" id="edit_checkin_medical_needs" class="form-control" style="font-size:11px;padding:5px 8px;">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Staff Notes</label>
          <input type="text" name="notes" id="edit_checkin_notes" class="form-control" style="font-size:11px;padding:5px 8px;">
        </div>
      </div>

      <div class="modal-footer" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalEditEvacCheckin')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: CHECKOUT / DECAMP CONFIRMATION MODAL                             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalCheckoutFamily">
  <div class="modal-dialog" style="max-width:440px;width:95%;">
    <div class="modal-header" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <h3 class="modal-title" style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary,#17324D);">
        Confirm Decamp & Departure
      </h3>
      <button class="modal-close-btn" onclick="closeModal('modalCheckoutFamily')">&times;</button>
    </div>

    <form id="checkoutFamilyForm" method="POST" action="<?= BASE_URL ?>/backend/functions/evacuation/checkout_family.php" style="margin:0;">
      <input type="hidden" name="action" value="checkout">
      <input type="hidden" name="checkin_id" id="checkout_checkin_id">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-body" style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">
        <p style="font-size:11.5px;color:var(--color-text);margin:0;line-height:1.5;">
          Are you checking out family <strong id="checkout_family_name" style="color:var(--color-primary);"></strong> (<span id="checkout_head_name"></span>) from this evacuation center?
        </p>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required" style="font-size:10px;margin-bottom:3px;">Departure Status</label>
          <select name="status" class="form-control" required style="font-size:11px;padding:5px 8px;">
            <option value="Decamped / Returned Home">Decamped / Returned Home</option>
            <option value="Transferred">Transferred to Another Facility</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-size:10px;margin-bottom:3px;">Departure Notes / Remarks</label>
          <input type="text" name="notes" class="form-control" placeholder="e.g. Cleared by CDRRMO; flood subsided" style="font-size:11px;padding:4px 8px;">
        </div>
      </div>

      <div class="modal-footer" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalCheckoutFamily')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#C62828;border-color:#C62828;">Confirm Checkout</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 6: DROMIC DISASTER CAMP ROSTER PRINT PREVIEW                        -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalDromicManifest">
  <div class="modal-dialog" style="max-width:960px;width:96%;max-height:92vh;display:flex;flex-direction:column;background:#FFFFFF;">
    <div class="modal-header no-print" style="padding:12px 18px;background:var(--color-surface-subtle,#F0F3F4);border-bottom:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h3 class="modal-title" style="margin:0;font-size:13.5px;font-weight:700;color:var(--color-primary,#17324D);">
          DROMIC Evacuation Camp Masterlist & Manifest
        </h3>
        <p style="font-size:10px;color:var(--color-text-secondary,#66737D);margin:2px 0 0 0;">
          Official Camp Registration format for submission to CDRRMO / DSWD Field Office.
        </p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()" style="font-size:10px;padding:4px 10px;">
          🖨️ Print Manifest
        </button>
        <button type="button" class="modal-close-btn" onclick="closeModal('modalDromicManifest')">&times;</button>
      </div>
    </div>

    <div class="modal-body" id="dromicPrintContent" style="padding:24px;overflow-y:auto;flex:1;font-family:'Segoe UI', Arial, sans-serif;font-size:11px;color:#17324D;">
      <!-- DROMIC Header -->
      <div style="text-align:center;border-bottom:2px solid #17324D;padding-bottom:12px;margin-bottom:14px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748B;">
          Republic of the Philippines &bull; City of Iligan &bull; Barangay Hinaplanon
        </div>
        <h2 style="margin:4px 0 2px 0;font-size:16px;font-weight:800;color:#17324D;">
          EVACUATION CAMP MANAGEMENT MASTERLIST (DROMIC FORMAT)
        </h2>
        <div style="font-size:11px;color:#334155;font-weight:600;" id="dromicCenterNameHeader">
          Center: Hinaplanon Evacuation Shelter
        </div>
        <div style="font-size:10px;color:#64748B;margin-top:2px;" id="dromicReportDate">
          Report Generated: <?= date('F j, Y - g:i A') ?>
        </div>
      </div>

      <!-- DROMIC Summary Stats Row -->
      <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:10px;margin-bottom:14px;background:#F8FAFC;border:1px solid #CBD5E1;padding:8px 12px;border-radius:4px;text-align:center;">
        <div>
          <div style="font-size:9px;text-transform:uppercase;color:#64748B;">Total Families</div>
          <div style="font-size:14px;font-weight:800;color:#17324D;" id="dromicTotalFam">0</div>
        </div>
        <div>
          <div style="font-size:9px;text-transform:uppercase;color:#64748B;">Total Individuals</div>
          <div style="font-size:14px;font-weight:800;color:#17324D;" id="dromicTotalInd">0</div>
        </div>
        <div>
          <div style="font-size:9px;text-transform:uppercase;color:#64748B;">PWD Persons</div>
          <div style="font-size:14px;font-weight:800;color:#E65100;" id="dromicTotalPwd">0</div>
        </div>
        <div>
          <div style="font-size:9px;text-transform:uppercase;color:#64748B;">Senior Citizens</div>
          <div style="font-size:14px;font-weight:800;color:#00695C;" id="dromicTotalSenior">0</div>
        </div>
        <div>
          <div style="font-size:9px;text-transform:uppercase;color:#64748B;">Food Packs Given</div>
          <div style="font-size:14px;font-weight:800;color:#2F6F73;" id="dromicTotalPacks">0</div>
        </div>
      </div>

      <!-- Manifest Table -->
      <table style="width:100%;border-collapse:collapse;font-size:10px;border:1px solid #CBD5E1;">
        <thead>
          <tr style="background:#F1F5F9;border-bottom:1px solid #94A3B8;">
            <th style="padding:6px;border:1px solid #CBD5E1;text-align:center;width:30px;">#</th>
            <th style="padding:6px;border:1px solid #CBD5E1;width:80px;">HH Code</th>
            <th style="padding:6px;border:1px solid #CBD5E1;">Family Name</th>
            <th style="padding:6px;border:1px solid #CBD5E1;">Head of Family</th>
            <th style="padding:6px;border:1px solid #CBD5E1;">Origin Purok</th>
            <th style="padding:6px;border:1px solid #CBD5E1;">Room / Tent</th>
            <th style="padding:6px;border:1px solid #CBD5E1;text-align:center;width:40px;">Indiv</th>
            <th style="padding:6px;border:1px solid #CBD5E1;text-align:center;width:35px;">PWD</th>
            <th style="padding:6px;border:1px solid #CBD5E1;text-align:center;width:35px;">Sr</th>
            <th style="padding:6px;border:1px solid #CBD5E1;">Medical / Alerts</th>
            <th style="padding:6px;border:1px solid #CBD5E1;text-align:center;width:50px;">Packs</th>
          </tr>
        </thead>
        <tbody id="dromicTableBody">
          <!-- Populated dynamically via JS -->
        </tbody>
      </table>

      <!-- Signatures Footer -->
      <div style="display:flex;justify-content:space-between;margin-top:35px;padding-top:16px;">
        <div style="text-align:center;width:200px;">
          <div style="border-bottom:1px solid #17324D;height:24px;"></div>
          <div style="font-weight:700;font-size:10px;margin-top:4px;">Prepared By: Camp Manager</div>
          <div style="font-size:9px;color:#64748B;">BDRRMC Camp Operations</div>
        </div>
        <div style="text-align:center;width:200px;">
          <div style="border-bottom:1px solid #17324D;height:24px;"></div>
          <div style="font-weight:700;font-size:10px;margin-top:4px;">Hon. Rodrigo Almonte</div>
          <div style="font-size:9px;color:#64748B;">Punong Barangay &bull; Hinaplanon</div>
        </div>
      </div>
    </div>

    <div class="modal-footer no-print" style="padding:10px 18px;background:var(--color-surface-subtle,#F0F3F4);border-top:1px solid var(--color-border-light,#E7EDF0);display:flex;justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalDromicManifest')">Close Manifest</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT CONTROLLERS FOR EVACUEE HOUSEHOLD FAMILIES                      -->
<!-- ========================================================================= -->
<script>
// Raw data exposed from PHP
const RAW_CENTERS = <?= json_encode($centers) ?>;
const RAW_CHECKINS = <?= json_encode($allCheckins) ?>;
const RAW_HOUSEHOLDS = <?= json_encode($allHouseholds) ?>;
const RAW_PUROKS = <?= json_encode($allPuroks) ?>;

let currentCenterId = null;

// =========================================================================
// 1. OPEN & CLOSE EVACUEE FAMILIES ROSTER MODAL
// =========================================================================
function openEvacueeFamiliesModal(centerId) {
  currentCenterId = parseInt(centerId, 10);
  const center = RAW_CENTERS.find(c => parseInt(c.id, 10) === currentCenterId);
  if (!center) return;

  // Header
  document.getElementById('efModalCenterName').innerText = `${center.name}`;
  document.getElementById('efModalCenterTypeBadge').innerText = center.center_type;
  document.getElementById('efModalCenterSub').innerText = `📍 ${center.location_address} • Camp Manager: ${center.contact_officer || 'Unassigned'}`;

  // Status Badge
  const statusBadge = document.getElementById('efModalStatusBadge');
  if (statusBadge) {
    statusBadge.className = 'status-badge ' + (center.status === 'Open / Active' ? 'active' : (center.status === 'At Capacity' ? 'warning' : 'neutral'));
    statusBadge.innerText = center.status;
  }

  // Pre-fill checkin modal hidden field
  document.getElementById('checkin_evacuation_area_id').value = currentCenterId;

  // Reset filters
  if (document.getElementById('efSearchInput')) document.getElementById('efSearchInput').value = '';
  if (document.getElementById('efPurokFilter')) document.getElementById('efPurokFilter').value = '';
  if (document.getElementById('efVulnerabilityFilter')) document.getElementById('efVulnerabilityFilter').value = '';
  if (document.getElementById('efStatusFilter')) document.getElementById('efStatusFilter').value = 'Sheltered';

  // Render
  renderEvacRosterTable();

  // Show
  document.getElementById('modalEvacueeFamilies').classList.add('active');
}

function closeEvacueeFamiliesModal() {
  document.getElementById('modalEvacueeFamilies').classList.remove('active');
}

// =========================================================================
// 2. RENDER ROSTER TABLE & TELEMETRY
// =========================================================================
function renderEvacRosterTable() {
  const tbody = document.getElementById('efRosterTableBody');
  if (!tbody) return;

  const center = RAW_CENTERS.find(c => parseInt(c.id, 10) === currentCenterId);
  const centerCheckins = RAW_CHECKINS.filter(ck => parseInt(ck.evacuation_area_id, 10) === currentCenterId);

  // Compute Telemetry
  let shelteredFam = 0;
  let shelteredInd = 0;
  let totalPwds = 0;
  let totalSeniors = 0;
  let totalRelief = 0;
  let totalMeals = 0;

  centerCheckins.forEach(ck => {
    if (ck.status === 'Sheltered') {
      shelteredFam++;
      shelteredInd += parseInt(ck.members_count, 10) || 0;
      totalPwds += parseInt(ck.pwd_count, 10) || 0;
      totalSeniors += parseInt(ck.seniors_count, 10) || 0;
    }
    totalRelief += parseInt(ck.relief_packs_given, 10) || 0;
    totalMeals += parseInt(ck.hot_meals_claimed, 10) || 0;
  });

  document.getElementById('efTeleFamilies').innerText = shelteredFam;
  document.getElementById('efTeleFamilyCap').innerText = center ? `Of ${center.capacity_families} Fam Cap` : '';
  document.getElementById('efTeleIndividuals').innerText = shelteredInd;
  document.getElementById('efTeleIndCap').innerText = center ? `Of ${center.capacity_individuals} Ind Cap` : '';
  document.getElementById('efTelePwds').innerText = totalPwds;
  document.getElementById('efTeleSeniors').innerText = totalSeniors;
  document.getElementById('efTeleRelief').innerText = totalRelief;
  document.getElementById('efTeleMeals').innerText = totalMeals;

  // Filter List
  const searchVal = (document.getElementById('efSearchInput')?.value || '').trim().toLowerCase();
  const purokFilter = (document.getElementById('efPurokFilter')?.value || '').trim().toLowerCase();
  const vulnFilter = (document.getElementById('efVulnerabilityFilter')?.value || '').trim();
  const statusFilter = (document.getElementById('efStatusFilter')?.value || '').trim();

  const filtered = centerCheckins.filter(ck => {
    // Search
    if (searchVal) {
      const match = (ck.family_name && ck.family_name.toLowerCase().includes(searchVal)) ||
                    (ck.household_code && ck.household_code.toLowerCase().includes(searchVal)) ||
                    (ck.head_full_name && ck.head_full_name.toLowerCase().includes(searchVal)) ||
                    (ck.room_tent_number && ck.room_tent_number.toLowerCase().includes(searchVal)) ||
                    (ck.purok_name && ck.purok_name.toLowerCase().includes(searchVal));
      if (!match) return false;
    }

    // Purok
    if (purokFilter && (ck.purok_name || '').toLowerCase() !== purokFilter) {
      return false;
    }

    // Status
    if (statusFilter) {
      if (statusFilter === 'Sheltered' && ck.status !== 'Sheltered') return false;
      if (statusFilter === 'Decamped' && ck.status === 'Sheltered') return false;
    }

    // Vulnerability
    if (vulnFilter === 'pwd' && parseInt(ck.pwd_count, 10) <= 0) return false;
    if (vulnFilter === 'seniors' && parseInt(ck.seniors_count, 10) <= 0) return false;
    if (vulnFilter === 'minors' && parseInt(ck.minors_count, 10) <= 0) return false;
    if (vulnFilter === 'medical' && (!ck.special_medical_needs || !ck.special_medical_needs.trim())) return false;

    return true;
  });

  document.getElementById('efRosterFooterCount').innerText = `Showing ${filtered.length} of ${centerCheckins.length} recorded families in ${center ? center.name : 'this center'}`;

  if (filtered.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="9" style="padding:32px;text-align:center;color:var(--color-text-muted,#8A96A0);font-size:11px;">
          No evacuee families match your selected filters. Click <strong>+ Check-In Family</strong> above to admit an evacuated household.
        </td>
      </tr>
    `;
    return;
  }

  let html = '';
  filtered.forEach(ck => {
    const isSheltered = ck.status === 'Sheltered';

    // Demographics tags
    let demoTags = [];
    if (parseInt(ck.pwd_count, 10) > 0) {
      demoTags.push(`<span class="badge-pwd">${ck.pwd_count} PWD</span>`);
    }
    if (parseInt(ck.seniors_count, 10) > 0) {
      demoTags.push(`<span class="badge-senior">${ck.seniors_count} Senior</span>`);
    }

    const medAlert = ck.special_medical_needs && ck.special_medical_needs.trim()
      ? `<div style="font-size:9.5px;color:#C62828;display:flex;align-items:center;gap:3px;margin-top:2px;" title="${escapeHtml(ck.special_medical_needs)}">⚠️ ${escapeHtml(ck.special_medical_needs)}</div>`
      : `<span style="color:var(--color-text-muted);font-size:9.5px;">None</span>`;

    const statusBadge = isSheltered
      ? `<span class="badge-sheltered">Sheltered</span>`
      : `<span class="badge-decamped">Decamped</span>`;

    const actions = isSheltered
      ? `
        <div style="display:flex;gap:4px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline btn-sm" style="font-size:9px;padding:2px 6px;" onclick="openDistributeReliefModal(${ck.id})" title="Issue relief food packs or hot meals">
            + Relief
          </button>
          <button type="button" class="btn btn-outline btn-sm" style="font-size:9px;padding:2px 6px;" onclick="openEditCheckinModal(${ck.id})" title="Edit room or members count">
            ✏️
          </button>
          <button type="button" class="btn btn-outline btn-sm" style="font-size:9px;padding:2px 6px;color:#C62828;border-color:#F8B4B4;" onclick="openCheckoutFamilyModal(${ck.id})" title="Check out family upon decamp">
            Decamp
          </button>
        </div>
      `
      : `
        <div style="text-align:right;font-size:9.5px;color:var(--color-text-muted);">
          Departed ${ck.checkout_time ? escapeHtml(ck.checkout_time.substring(0, 10)) : ''}
        </div>
      `;

    html += `
      <tr>
        <td style="font-family:var(--font-secondary);font-weight:600;color:var(--color-primary);font-size:10.5px;">
          <div>${escapeHtml(ck.household_code)}</div>
          <div style="font-weight:700;color:var(--color-text);font-size:11px;">${escapeHtml(ck.family_name)}</div>
        </td>
        <td>
          <div style="font-weight:600;color:var(--color-primary);">${escapeHtml(ck.head_full_name)}</div>
          <div style="font-size:9.5px;color:var(--color-text-muted);font-family:var(--font-secondary);">${escapeHtml(ck.head_phone || '—')}</div>
        </td>
        <td style="font-size:10.5px;color:var(--color-text);">
          ${escapeHtml(ck.purok_name)}
        </td>
        <td>
          <span class="badge-room">${escapeHtml(ck.room_tent_number || 'Unassigned')}</span>
        </td>
        <td style="text-align:center;">
          <div style="font-weight:700;font-size:12px;font-family:var(--font-secondary);color:var(--color-primary);">${ck.members_count}</div>
          <div style="display:flex;gap:3px;justify-content:center;margin-top:2px;flex-wrap:wrap;">
            ${demoTags.join(' ')}
          </div>
        </td>
        <td>
          ${medAlert}
        </td>
        <td style="text-align:center;">
          <div style="font-weight:700;font-size:11.5px;font-family:var(--font-secondary);color:var(--color-secondary);">${ck.relief_packs_given} packs</div>
          <div style="font-size:9px;color:var(--color-text-muted);">${ck.hot_meals_claimed} meals</div>
        </td>
        <td style="text-align:center;">
          ${statusBadge}
        </td>
        <td style="text-align:right;">
          ${actions}
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

function filterEvacRosterTable() {
  renderEvacRosterTable();
}

// =========================================================================
// 3. CHECK-IN HOUSEHOLD FAMILY INTAKE
// =========================================================================
function openCheckinFamilyModal() {
  if (!currentCenterId) return;
  document.getElementById('checkin_evacuation_area_id').value = currentCenterId;
  document.getElementById('checkinFamilyForm').reset();
  document.getElementById('checkinFamilyCard').style.display = 'none';

  updateCheckinHouseholdOptions();

  openModal('modalCheckinFamily');
}

function updateCheckinHouseholdOptions() {
  const purokFilter = (document.getElementById('checkin_purok_filter')?.value || '').trim().toLowerCase();
  const selectElem = document.getElementById('checkin_household_select');
  if (!selectElem) return;

  // Filter out households already actively sheltered anywhere
  const activeCheckinHhIds = new Set(
    RAW_CHECKINS.filter(ck => ck.status === 'Sheltered').map(ck => parseInt(ck.household_id, 10))
  );

  let html = '<option value="">-- Choose Household Family --</option>';
  RAW_HOUSEHOLDS.forEach(hh => {
    if (activeCheckinHhIds.has(parseInt(hh.id, 10))) return; // Already sheltered
    if (purokFilter && (hh.purok_name || '').toLowerCase() !== purokFilter) return;

    let meta = `${hh.purok_name} • ${hh.total_members} members`;
    if (parseInt(hh.pwd_count, 10) > 0) meta += ` • ${hh.pwd_count} PWD`;
    if (parseInt(hh.seniors_count, 10) > 0) meta += ` • ${hh.seniors_count} Senior`;

    html += `<option value="${hh.id}">${escapeHtml(hh.family_name)} (${escapeHtml(hh.household_code)}) — Head: ${escapeHtml(hh.head_full_name)} [${meta}]</option>`;
  });

  selectElem.innerHTML = html;
}

function onCheckinHouseholdSelected(selectElem) {
  const hhId = parseInt(selectElem.value, 10);
  const card = document.getElementById('checkinFamilyCard');
  if (!hhId) {
    if (card) card.style.display = 'none';
    document.getElementById('checkin_household_id').value = '';
    return;
  }

  const hh = RAW_HOUSEHOLDS.find(h => parseInt(h.id, 10) === hhId);
  if (!hh) return;

  document.getElementById('checkin_household_id').value = hh.id;
  document.getElementById('cfCardCode').innerText = hh.household_code;
  document.getElementById('cfCardName').innerText = hh.family_name;
  document.getElementById('cfCardHead').innerText = `Head: ${hh.head_full_name} (${hh.head_phone || 'No Phone'})`;
  document.getElementById('cfCardPurok').innerText = hh.purok_name;
  document.getElementById('cfCardEcon').innerText = hh.economic_status || 'Indigent';
  document.getElementById('cfCardTotalMem').innerText = hh.total_members;
  document.getElementById('cfCardSeniors').innerText = hh.seniors_count || 0;
  document.getElementById('cfCardPwds').innerText = hh.pwd_count || 0;
  document.getElementById('cfCardPriority').innerText = hh.evacuation_priority || 'Standard';

  // Pre-fill disaggregated inputs
  document.getElementById('checkin_members_count').value = hh.total_members;
  document.getElementById('checkin_seniors_count').value = hh.seniors_count || 0;
  document.getElementById('checkin_pwd_count').value = hh.pwd_count || 0;
  document.getElementById('checkin_minors_count').value = hh.minors_count || 0;

  if (hh.emergency_notes) {
    document.getElementById('checkin_medical_needs').value = hh.emergency_notes;
  }

  if (card) card.style.display = 'block';
}

function setRoomPreset(preset) {
  const input = document.getElementById('checkin_room_tent');
  if (input) input.value = preset;
}

// =========================================================================
// 4. DISTRIBUTE RELIEF MODAL
// =========================================================================
function openDistributeReliefModal(checkinId) {
  const ck = RAW_CHECKINS.find(item => parseInt(item.id, 10) === parseInt(checkinId, 10));
  if (!ck) return;

  document.getElementById('relief_checkin_id').value = ck.id;
  document.getElementById('relief_family_code').innerText = ck.household_code;
  document.getElementById('relief_family_name').innerText = ck.family_name;
  document.getElementById('relief_family_head').innerText = `Head: ${ck.head_full_name} • ${ck.purok_name}`;
  document.getElementById('relief_family_room').innerText = `Assigned: ${ck.room_tent_number || 'General Camp'}`;

  document.getElementById('distributeEvacReliefForm').reset();
  document.getElementById('relief_quantity').value = '1';
  document.getElementById('relief_unit').value = 'packs';

  openModal('modalDistributeEvacRelief');
}

// Adjust unit automatically based on item type
document.getElementById('relief_item_type')?.addEventListener('change', function() {
  const val = this.value.toLowerCase();
  const unitInput = document.getElementById('relief_unit');
  if (!unitInput) return;
  if (val.includes('meal') || val.includes('soup')) unitInput.value = 'meals';
  else if (val.includes('container') || val.includes('water')) unitInput.value = 'containers';
  else if (val.includes('kit') || val.includes('set')) unitInput.value = 'kits';
  else unitInput.value = 'packs';
});

// =========================================================================
// 5. EDIT CHECKIN MODAL
// =========================================================================
function openEditCheckinModal(checkinId) {
  const ck = RAW_CHECKINS.find(item => parseInt(item.id, 10) === parseInt(checkinId, 10));
  if (!ck) return;

  document.getElementById('edit_checkin_id').value = ck.id;
  document.getElementById('edit_checkin_family_name').innerText = `${ck.family_name} (${ck.household_code})`;
  document.getElementById('edit_checkin_head_name').innerText = `Head: ${ck.head_full_name} • ${ck.purok_name}`;
  document.getElementById('edit_checkin_room_tent').value = ck.room_tent_number || '';
  document.getElementById('edit_checkin_members_count').value = ck.members_count || 1;
  document.getElementById('edit_checkin_pwd_count').value = ck.pwd_count || 0;
  document.getElementById('edit_checkin_seniors_count').value = ck.seniors_count || 0;
  document.getElementById('edit_checkin_minors_count').value = ck.minors_count || 0;
  document.getElementById('edit_checkin_medical_needs').value = ck.special_medical_needs || '';
  document.getElementById('edit_checkin_notes').value = ck.notes || '';

  openModal('modalEditEvacCheckin');
}

// =========================================================================
// 6. CHECKOUT / DECAMP MODAL
// =========================================================================
function openCheckoutFamilyModal(checkinId) {
  const ck = RAW_CHECKINS.find(item => parseInt(item.id, 10) === parseInt(checkinId, 10));
  if (!ck) return;

  document.getElementById('checkout_checkin_id').value = ck.id;
  document.getElementById('checkout_family_name').innerText = ck.family_name;
  document.getElementById('checkout_head_name').innerText = ck.head_full_name;

  openModal('modalCheckoutFamily');
}

// =========================================================================
// 7. DROMIC MASTERLIST PRINT PREVIEW
// =========================================================================
function openDromicManifestModal() {
  const center = RAW_CENTERS.find(c => parseInt(c.id, 10) === currentCenterId);
  const centerCheckins = RAW_CHECKINS.filter(ck => parseInt(ck.evacuation_area_id, 10) === currentCenterId && ck.status === 'Sheltered');

  document.getElementById('dromicCenterNameHeader').innerText = center ? `Facility: ${center.name} (${center.location_address})` : 'Evacuation Center';

  let totalInd = 0;
  let totalPwd = 0;
  let totalSenior = 0;
  let totalPacks = 0;

  let html = '';
  centerCheckins.forEach((ck, idx) => {
    totalInd += parseInt(ck.members_count, 10) || 0;
    totalPwd += parseInt(ck.pwd_count, 10) || 0;
    totalSenior += parseInt(ck.seniors_count, 10) || 0;
    totalPacks += parseInt(ck.relief_packs_given, 10) || 0;

    html += `
      <tr>
        <td style="padding:6px;border:1px solid #CBD5E1;text-align:center;">${idx + 1}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;font-weight:600;">${escapeHtml(ck.household_code)}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;font-weight:700;">${escapeHtml(ck.family_name)}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;">${escapeHtml(ck.head_full_name)}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;">${escapeHtml(ck.purok_name)}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;">${escapeHtml(ck.room_tent_number || 'Main Bay')}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;text-align:center;font-weight:700;">${ck.members_count}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;text-align:center;color:#E65100;">${ck.pwd_count || 0}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;text-align:center;color:#00695C;">${ck.seniors_count || 0}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;">${escapeHtml(ck.special_medical_needs || '—')}</td>
        <td style="padding:6px;border:1px solid #CBD5E1;text-align:center;font-weight:600;">${ck.relief_packs_given}</td>
      </tr>
    `;
  });

  if (centerCheckins.length === 0) {
    html = `<tr><td colspan="11" style="padding:20px;text-align:center;color:#64748B;">No families currently sheltered in this center.</td></tr>`;
  }

  document.getElementById('dromicTableBody').innerHTML = html;
  document.getElementById('dromicTotalFam').innerText = centerCheckins.length;
  document.getElementById('dromicTotalInd').innerText = totalInd;
  document.getElementById('dromicTotalPwd').innerText = totalPwd;
  document.getElementById('dromicTotalSenior').innerText = totalSenior;
  document.getElementById('dromicTotalPacks').innerText = totalPacks;

  openModal('modalDromicManifest');
}
</script>
