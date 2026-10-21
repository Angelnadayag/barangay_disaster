<?php
// ============================================================================
// Component: Purok Household Families Directory & Authorized Representative Modal
// Strict design system compliance: anti-slop, project typography & palette.
// Features: Manual family member management, system registration indicator.
// ============================================================================
?>

<style>
  #modalPurokHouseholds.modal-overlay,
  #modalViewHouseholdMembers.modal-overlay,
  #modalAddHousehold.modal-overlay,
  #modalEditHousehold.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background-color: rgba(23, 50, 77, 0.48) !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1080 !important;
    padding: 16px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
  }
  #modalPurokHouseholds.modal-overlay.active,
  #modalViewHouseholdMembers.modal-overlay.active,
  #modalAddHousehold.modal-overlay.active,
  #modalEditHousehold.modal-overlay.active {
    display: flex !important;
  }

  #modalPurokHouseholds .modal-dialog {
    max-width: 880px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
  }

  #modalViewHouseholdMembers .modal-dialog {
    max-width: 620px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
  }

  #modalAddHousehold .modal-dialog,
  #modalEditHousehold .modal-dialog {
    max-width: 540px !important;
    width: 100% !important;
    max-height: calc(100vh - 40px) !important;
    margin: auto !important;
    display: flex !important;
    flex-direction: column !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    border-radius: var(--radius-primary, 10px) !important;
    border: 1px solid var(--color-border, #D9E0E3) !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22) !important;
    overflow: hidden !important;
  }

  #modalPurokHouseholds .modal-header,
  #modalViewHouseholdMembers .modal-header,
  #modalAddHousehold .modal-header,
  #modalEditHousehold .modal-header {
    flex-shrink: 0 !important;
    padding: 12px 18px !important;
    border-bottom: 1px solid var(--color-border-light, #E7EDF0) !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
  }

  #modalPurokHouseholds .modal-body,
  #modalViewHouseholdMembers .modal-body,
  #modalAddHousehold .modal-body,
  #modalEditHousehold .modal-body {
    flex: 1 1 auto !important;
    padding: 16px 18px !important;
    overflow-y: auto !important;
  }

  #modalPurokHouseholds .modal-footer,
  #modalViewHouseholdMembers .modal-footer,
  #modalAddHousehold .modal-footer,
  #modalEditHousehold .modal-footer {
    flex-shrink: 0 !important;
    padding: 10px 18px !important;
    border-top: 1px solid var(--color-border-light, #E7EDF0) !important;
    background-color: var(--color-surface-hover, #F9FAFB) !important;
  }

  #modalPurokHouseholds .data-table thead th,
  #modalViewHouseholdMembers .data-table thead th {
    position: sticky !important;
    top: 0 !important;
    background-color: var(--color-surface, #FFFFFF) !important;
    z-index: 5 !important;
    box-shadow: inset 0 -1px 0 var(--color-border-light, #E7EDF0);
  }
</style>

<!-- ========================================================================= -->
<!-- Modal 1: Household Families Directory Pop-up                              -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalPurokHouseholds">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary);font-family:var(--font-primary);">
        Household Families — <span id="hhModalPurokBadge"></span>
      </h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalPurokHouseholds')">&times;</button>
    </div>

    <!-- Search & Actions Bar -->
    <div style="padding:10px 18px;border-bottom:1px solid var(--color-border-light);display:flex;justify-content:space-between;align-items:center;gap:8px;background:var(--color-surface);flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:440px;">
        <input type="text" id="hhSearchInput" class="form-control" placeholder="Search families, heads, codes..." 
               oninput="filterPurokHouseholdsLocal()" style="font-size:10px;height:28px;flex:1;">
        <select id="hhRegFilter" class="form-control" onchange="filterPurokHouseholdsLocal()" style="font-size:10px;height:28px;width:130px;flex-shrink:0;">
          <option value="all">All Households</option>
          <option value="registered">Registered Only</option>
          <option value="unregistered">Not Registered</option>
        </select>
      </div>
      <div style="display:flex;gap:6px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="printPurokHouseholdRoster()">
          Print
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddHouseholdModal()">
          Add Household
        </button>
      </div>
    </div>

    <!-- Table of Household Families -->
    <div class="modal-body">
      <div id="hhLoadingIndicator" style="text-align:center;padding:24px;display:none;color:var(--color-text-muted);font-size:10px;">
        Loading household families...
      </div>

      <div class="table-responsive" id="hhTableWrap" style="border:1px solid var(--color-border-light);border-radius:var(--radius-sm);overflow:hidden;">
        <table class="data-table" style="margin-bottom:0;width:100%;">
          <thead>
            <tr>
              <th style="width:85px;">HH Code</th>
              <th>Family Name</th>
              <th>1 Authorized Representative</th>
              <th style="text-align:center;width:120px;">Registered in System</th>
              <th style="width:70px;text-align:center;">Members</th>
              <th>Address</th>
              <th style="text-align:right;width:120px;">Actions</th>
            </tr>
          </thead>
          <tbody id="hhTableBody">
            <!-- Dynamically populated via loadPurokHouseholds() -->
          </tbody>
        </table>
      </div>

      <div id="hhEmptyNotice" style="display:none;text-align:center;padding:28px 16px;color:var(--color-text-muted);font-size:10px;">
        No household families found for this purok.
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:10px;color:var(--color-text-muted);" id="hhFooterCount"></span>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalPurokHouseholds')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: View Household Members Roster & Manual Member Management          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalViewHouseholdMembers">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="vmFamilyTitle" style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary);font-family:var(--font-primary);"></h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalViewHouseholdMembers')">&times;</button>
    </div>

    <div class="modal-body">
      <!-- Authorized Representative Summary Line -->
      <div style="margin-bottom:12px;padding:8px 10px;background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);font-size:10px;color:var(--color-text);">
        1 Authorized Representative: <strong id="vmHeadName" style="color:var(--color-primary);"></strong> • Contact: <span id="vmHeadPhone" style="font-family:var(--font-secondary);"></span>
      </div>

      <!-- Members Table -->
      <div style="margin-bottom:6px;font-size:10px;font-weight:600;color:var(--color-text);">Family Members Roster</div>
      <div class="table-responsive" style="border:1px solid var(--color-border-light);border-radius:var(--radius-sm);overflow:hidden;">
        <table class="data-table" style="margin-bottom:0;width:100%;">
          <thead>
            <tr>
              <th>Name</th>
              <th>Relationship</th>
              <th>Gender / Age</th>
              <th style="text-align:center;">1 Authorized Rep</th>
              <th style="text-align:right;width:110px;">Action</th>
            </tr>
          </thead>
          <tbody id="vmMembersTbody">
            <!-- Dynamically populated via renderViewHouseholdMembersTable() -->
          </tbody>
        </table>
      </div>

      <!-- Add Family Member Manually Form -->
      <div style="margin-top:14px;padding:12px;background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);">
        <div style="font-size:10.5px;font-weight:600;color:var(--color-primary);margin-bottom:8px;">
          Add Family Member Manually
        </div>
        <form id="formAddHouseholdMember" onsubmit="saveNewHouseholdMember(event)">
          <input type="hidden" name="household_id" id="vmAddMemberHhId">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Full Name</label>
              <input type="text" name="full_name" id="vmMemFullName" class="form-control" placeholder="Member full name" required style="background:#fff;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Relationship</label>
              <select name="relationship" id="vmMemRelation" class="form-control" style="background:#fff;">
                <option value="Spouse">Spouse</option>
                <option value="Child">Child</option>
                <option value="Son">Son</option>
                <option value="Daughter">Daughter</option>
                <option value="Parent">Parent</option>
                <option value="Sibling">Sibling</option>
                <option value="Grandparent">Grandparent</option>
                <option value="Relative">Relative</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Gender</label>
              <select name="gender" id="vmMemGender" class="form-control" style="background:#fff;">
                <option value="Male" selected>Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Age</label>
              <input type="number" name="age" id="vmMemAge" class="form-control" value="20" min="0" max="120" style="background:#fff;">
            </div>
            <div>
              <button type="submit" class="btn btn-primary btn-sm" id="btnSaveMember" style="height:30px;padding:0 12px;white-space:nowrap;">
                Add Member
              </button>
            </div>
          </div>
          <div style="margin-top:8px;">
            <label style="font-size:10px;color:var(--color-text-secondary);cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
              <input type="checkbox" name="is_authorized_rep" id="vmMemIsRep" value="1">
              Designate as the 1 Authorized Representative for this household
            </label>
          </div>
        </form>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:flex-end;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalViewHouseholdMembers')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Register Household Family Form                                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalAddHousehold">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary);font-family:var(--font-primary);">Add Household Family</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalAddHousehold')">&times;</button>
    </div>

    <form id="formAddHousehold" onsubmit="saveNewHousehold(event)">
      <input type="hidden" name="purok_name" id="addHhPurokName">
      <input type="hidden" name="purok_id" id="addHhPurokId">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Family Name</label>
            <input type="text" name="family_name" class="form-control" placeholder="e.g. Mendoza Family" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Household Code</label>
            <input type="text" name="household_code" id="addHhCodeInput" class="form-control" placeholder="Auto-generated">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">House Address</label>
          <input type="text" name="house_number" class="form-control" placeholder="e.g. Block 2, Lot 3" required>
        </div>

        <div style="padding:10px 12px;background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);">
          <div style="font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:8px;">1 Authorized Representative</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Full Name</label>
              <input type="text" name="head_full_name" class="form-control" required style="background:#fff;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Contact Phone</label>
              <input type="text" name="head_phone" class="form-control" placeholder="+63 9..." required style="background:#fff;">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Relationship</label>
              <select name="head_relation" class="form-control" style="background:#fff;">
                <option value="Household Head" selected>Household Head</option>
                <option value="Spouse">Spouse</option>
                <option value="Eldest Child">Eldest Child</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Gender</label>
              <select name="head_gender" class="form-control" style="background:#fff;">
                <option value="Male" selected>Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Age</label>
              <input type="number" name="head_age" class="form-control" value="38" min="15" max="110" style="background:#fff;">
            </div>
          </div>
        </div>

        <!-- Additional Family Members Section -->
        <div style="padding:10px 12px;border:1px solid var(--color-border-light);border-radius:var(--radius-sm);">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="font-size:10px;font-weight:600;color:var(--color-text);">Additional Family Members (Optional)</div>
            <button type="button" class="btn btn-outline btn-sm" onclick="addManualMemberRow()" style="font-size:9.5px;padding:2px 8px;">
              + Add Member
            </button>
          </div>
          <div id="createMembersContainer" style="display:flex;flex-direction:column;gap:6px;">
            <!-- Dynamically appended rows -->
          </div>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:6px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalAddHousehold')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSaveNewHh">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 4: Edit Household Family Form                                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalEditHousehold">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="margin:0;font-size:13px;font-weight:700;color:var(--color-primary);font-family:var(--font-primary);">Edit Household Family</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('modalEditHousehold')">&times;</button>
    </div>

    <form id="formEditHousehold" onsubmit="saveEditHousehold(event)">
      <input type="hidden" name="id" id="editHhId">

      <div class="modal-body" style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label form-label-required">Family Name</label>
            <input type="text" name="family_name" id="editHhFamilyName" class="form-control" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Household Code</label>
            <input type="text" name="household_code" id="editHhCode" class="form-control" readonly style="background:var(--color-surface-subtle);">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">House Address</label>
          <input type="text" name="house_number" id="editHhHouseNumber" class="form-control" required>
        </div>

        <div style="padding:10px 12px;background:var(--color-surface-subtle);border:1px solid var(--color-border-light);border-radius:var(--radius-sm);">
          <div style="font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:8px;">1 Authorized Representative</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Full Name</label>
              <input type="text" name="head_full_name" id="editHhHeadName" class="form-control" required style="background:#fff;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label form-label-required">Contact Phone</label>
              <input type="text" name="head_phone" id="editHhHeadPhone" class="form-control" required style="background:#fff;">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Relationship</label>
              <select name="head_relation" id="editHhHeadRel" class="form-control" style="background:#fff;">
                <option value="Household Head">Household Head</option>
                <option value="Spouse">Spouse</option>
                <option value="Eldest Child">Eldest Child</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Gender</label>
              <select name="head_gender" id="editHhHeadGender" class="form-control" style="background:#fff;">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Age</label>
              <input type="number" name="head_age" id="editHhHeadAge" class="form-control" min="15" max="110" style="background:#fff;">
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label form-label-required">Total Members</label>
          <input type="number" name="total_members" id="editHhTotalMembers" class="form-control" min="1" max="25" required>
        </div>
      </div>

      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:6px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modalEditHousehold')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="btnSaveEditHh">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- JavaScript Logic                                                          -->
<!-- ========================================================================= -->
<script>
  let currentHouseholdPurokId = null;
  let currentHouseholdPurokName = '';
  let allLoadedHouseholds = [];
  let currentActiveHouseholdId = null;

  function openPurokHouseholdsModal(purokId, purokName) {
    currentHouseholdPurokId = purokId;
    currentHouseholdPurokName = purokName;

    const badge = document.getElementById('hhModalPurokBadge');
    if (badge) badge.innerText = purokName;

    const sInput = document.getElementById('hhSearchInput');
    if (sInput) sInput.value = '';

    openModal('modalPurokHouseholds');
    loadPurokHouseholds(purokId, purokName);
  }

  async function loadPurokHouseholds(purokId, purokName) {
    const loading = document.getElementById('hhLoadingIndicator');
    const tableWrap = document.getElementById('hhTableWrap');
    const emptyNotice = document.getElementById('hhEmptyNotice');

    if (loading) loading.style.display = 'block';
    if (tableWrap) tableWrap.style.display = 'none';
    if (emptyNotice) emptyNotice.style.display = 'none';

    try {
      const url = `<?= BASE_URL ?>/backend/functions/purok_households/list.php?purok_id=${encodeURIComponent(purokId || '')}&purok_name=${encodeURIComponent(purokName || '')}`;
      const res = await fetch(url);
      const data = await res.json();

      if (loading) loading.style.display = 'none';

      if (data.success) {
        allLoadedHouseholds = data.households || [];
        renderPurokHouseholds(allLoadedHouseholds);
        if (data.summary) {
          updateMainTableDemographics(currentHouseholdPurokId, data.summary.total_households, data.summary.total_members);
        }
      } else {
        if (emptyNotice) {
          emptyNotice.style.display = 'block';
          emptyNotice.innerText = data.message || 'Error loading households.';
        }
      }
    } catch (err) {
      if (loading) loading.style.display = 'none';
      if (emptyNotice) {
        emptyNotice.style.display = 'block';
        emptyNotice.innerText = 'Network error fetching households.';
      }
    }
  }

  function renderPurokHouseholds(households) {
    const tbody = document.getElementById('hhTableBody');
    const tableWrap = document.getElementById('hhTableWrap');
    const emptyNotice = document.getElementById('hhEmptyNotice');
    const footerCount = document.getElementById('hhFooterCount');

    if (!households || households.length === 0) {
      if (tbody) tbody.innerHTML = '';
      if (tableWrap) tableWrap.style.display = 'none';
      if (emptyNotice) emptyNotice.style.display = 'block';
      if (footerCount) footerCount.innerText = `0 households in ${currentHouseholdPurokName}`;
      return;
    }

    if (emptyNotice) emptyNotice.style.display = 'none';
    if (tableWrap) tableWrap.style.display = 'block';

    let html = '';
    households.forEach((hh) => {
      const isReg = !!hh.is_registered_in_system;
      const regBadge = isReg 
        ? `<span class="badge badge-success" style="font-size:9px;">Registered</span>${hh.resident_username ? `<div style="font-size:9.5px;color:var(--color-text-muted);font-family:var(--font-secondary);margin-top:2px;">@${escapeHtml(hh.resident_username)}</div>` : ''}`
        : `<span class="badge badge-neutral" style="font-size:9px;">Not Registered</span>`;

      html += `
        <tr>
          <td style="font-family:var(--font-secondary);font-size:10px;color:var(--color-primary);font-weight:600;">
            ${escapeHtml(hh.household_code)}
          </td>
          <td style="font-size:10px;font-weight:600;color:var(--color-text);">
            ${escapeHtml(hh.family_name)}
          </td>
          <td>
            <div style="font-size:10px;font-weight:600;color:var(--color-text);">
              ${escapeHtml(hh.head_full_name)}
            </div>
            <div style="font-size:10px;color:var(--color-text-secondary);font-family:var(--font-secondary);">
              ${escapeHtml(hh.head_phone || '—')}
            </div>
          </td>
          <td style="text-align:center;">
            ${regBadge}
          </td>
          <td style="text-align:center;font-family:var(--font-secondary);font-size:10px;" id="hhRowMembersCount_${hh.id}">
            ${hh.total_members}
          </td>
          <td style="font-size:10px;color:var(--color-text-secondary);">
            ${escapeHtml(hh.house_number || '—')}
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex;gap:4px;justify-content:flex-end;">
              <button type="button" class="btn btn-outline btn-sm" onclick="openViewHouseholdMembersModal(${hh.id})" title="View / Add Members">
                Members
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="openEditHouseholdModal(${hh.id})" title="Edit">
                Edit
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="archiveHouseholdFamily(${hh.id}, '${escapeHtml(hh.family_name)}')" title="Archive">
                Archive
              </button>
            </div>
          </td>
        </tr>
      `;
    });

    if (tbody) tbody.innerHTML = html;
    if (footerCount) {
      footerCount.innerText = `${households.length} household families in ${currentHouseholdPurokName}`;
    }
  }

  function filterPurokHouseholdsLocal() {
    const query = (document.getElementById('hhSearchInput')?.value || '').toLowerCase().trim();
    const regFilter = document.getElementById('hhRegFilter')?.value || 'all';

    let filtered = allLoadedHouseholds.filter(hh => {
      const matchesQuery = !query || 
        (hh.family_name && hh.family_name.toLowerCase().includes(query)) ||
        (hh.head_full_name && hh.head_full_name.toLowerCase().includes(query)) ||
        (hh.household_code && hh.household_code.toLowerCase().includes(query)) ||
        (hh.resident_username && hh.resident_username.toLowerCase().includes(query)) ||
        (hh.head_phone && hh.head_phone.toLowerCase().includes(query));

      const isReg = !!hh.is_registered_in_system;
      const matchesReg = (regFilter === 'all') ||
        (regFilter === 'registered' && isReg) ||
        (regFilter === 'unregistered' && !isReg);

      return matchesQuery && matchesReg;
    });

    renderPurokHouseholds(filtered);
  }

  // =========================================================================
  // Members Modal & Manual Member Management
  // =========================================================================
  function openViewHouseholdMembersModal(hhId) {
    currentActiveHouseholdId = parseInt(hhId);
    const hh = allLoadedHouseholds.find(h => parseInt(h.id) === currentActiveHouseholdId);
    if (!hh) return;

    document.getElementById('vmFamilyTitle').innerText = `${hh.family_name}`;
    document.getElementById('vmHeadName').innerText = `${hh.head_full_name} (${hh.head_relation || 'Head'})`;
    document.getElementById('vmHeadPhone').innerText = hh.head_phone || '—';
    document.getElementById('vmAddMemberHhId').value = hh.id;

    // Reset member form
    const mForm = document.getElementById('formAddHouseholdMember');
    if (mForm) mForm.reset();

    renderViewHouseholdMembersTable(hh);
    openModal('modalViewHouseholdMembers');
  }

  function renderViewHouseholdMembersTable(hh) {
    const members = hh.members || [];
    const tbody = document.getElementById('vmMembersTbody');
    if (!tbody) return;

    if (members.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td style="font-weight:600;color:var(--color-text);">${escapeHtml(hh.head_full_name)}</td>
          <td>${escapeHtml(hh.head_relation || 'Household Head')}</td>
          <td>${escapeHtml(hh.head_gender || 'Male')} (${escapeHtml(hh.head_age || 38)})</td>
          <td style="text-align:center;"><span class="badge badge-success" style="font-size:9px;">Authorized Rep</span></td>
          <td style="text-align:right;"><span style="color:var(--color-text-muted);font-size:9.5px;">Primary</span></td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = members.map(m => {
      const isRep = (parseInt(m.is_authorized_rep) === 1);
      return `
        <tr>
          <td style="font-weight:${isRep ? '600' : 'normal'};color:var(--color-text);">
            ${escapeHtml(m.full_name)}
          </td>
          <td>${escapeHtml(m.relationship)}</td>
          <td>${escapeHtml(m.gender || '—')} (${escapeHtml(m.age || '—')})</td>
          <td style="text-align:center;">
            ${isRep 
              ? `<span class="badge badge-success" style="font-size:9px;">Authorized Rep</span>` 
              : `<button type="button" class="btn btn-outline btn-sm" style="font-size:9px;padding:2px 6px;" onclick="setMemberAsAuthorizedRep(${m.id})">Set Rep</button>`}
          </td>
          <td style="text-align:right;">
            ${isRep 
              ? `<span style="color:var(--color-text-muted);font-size:9.5px;">Primary</span>` 
              : `<button type="button" class="btn btn-outline btn-sm" style="font-size:9px;padding:2px 6px;" onclick="removeHouseholdMember(${m.id}, '${escapeHtml(m.full_name)}')">Remove</button>`}
          </td>
        </tr>
      `;
    }).join('');
  }

  async function saveNewHouseholdMember(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveMember');
    const f = document.getElementById('formAddHouseholdMember');
    const formData = new FormData(f);

    btn.disabled = true;
    btn.innerText = 'Adding...';

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/add_member.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();

      btn.disabled = false;
      btn.innerText = 'Add Member';

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        }

        // Update local state
        const hh = allLoadedHouseholds.find(h => parseInt(h.id) === currentActiveHouseholdId);
        if (hh) {
          if (!hh.members) hh.members = [];
          if (data.member.is_authorized_rep === 1) {
            hh.members.forEach(m => m.is_authorized_rep = 0);
            hh.head_full_name = data.member.full_name;
            hh.head_relation = data.member.relationship;
            document.getElementById('vmHeadName').innerText = `${hh.head_full_name} (${hh.head_relation})`;
          }
          hh.members.push(data.member);
          hh.total_members = data.total_members;

          // Update main table count cell
          const countCell = document.getElementById(`hhRowMembersCount_${hh.id}`);
          if (countCell) countCell.innerText = hh.total_members;

          renderViewHouseholdMembersTable(hh);

          if (data.dynamic_households !== undefined && data.dynamic_population !== undefined) {
            updateMainTableDemographics(currentHouseholdPurokId, data.dynamic_households, data.dynamic_population);
          }
        }

        f.reset();
        document.getElementById('vmAddMemberHhId').value = currentActiveHouseholdId;
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to add member.', 'danger');
        } else {
          alert(data.message);
        }
      }
    } catch (err) {
      btn.disabled = false;
      btn.innerText = 'Add Member';
      if (typeof showToast === 'function') {
        showToast('Network error while adding member.', 'danger');
      }
    }
  }

  async function setMemberAsAuthorizedRep(memberId) {
    if (!currentActiveHouseholdId) return;

    try {
      const fd = new FormData();
      fd.append('household_id', currentActiveHouseholdId);
      fd.append('member_id', memberId);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/set_authorized_rep.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json();

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        }

        const hh = allLoadedHouseholds.find(h => parseInt(h.id) === currentActiveHouseholdId);
        if (hh && hh.members) {
          hh.members.forEach(m => {
            m.is_authorized_rep = (parseInt(m.id) === parseInt(memberId)) ? 1 : 0;
            if (m.is_authorized_rep === 1) {
              hh.head_full_name = m.full_name;
              hh.head_relation = m.relationship;
            }
          });
          document.getElementById('vmHeadName').innerText = `${hh.head_full_name} (${hh.head_relation})`;
          renderViewHouseholdMembersTable(hh);
          renderPurokHouseholds(allLoadedHouseholds);
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to set representative.', 'danger');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error.', 'danger');
      }
    }
  }

  function removeHouseholdMember(memberId, memberName) {
    if (!currentActiveHouseholdId) return;

    if (typeof showConfirmModal === 'function') {
      showConfirmModal(
        'Remove Member',
        `Remove "${memberName}" from family roster?`,
        () => performRemoveHouseholdMember(memberId),
        'btn-danger',
        'Remove'
      );
    } else {
      if (confirm(`Remove "${memberName}" from family roster?`)) {
        performRemoveHouseholdMember(memberId);
      }
    }
  }

  async function performRemoveHouseholdMember(memberId) {
    try {
      const fd = new FormData();
      fd.append('household_id', currentActiveHouseholdId);
      fd.append('member_id', memberId);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/delete_member.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json();

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        }

        const hh = allLoadedHouseholds.find(h => parseInt(h.id) === currentActiveHouseholdId);
        if (hh && hh.members) {
          hh.members = hh.members.filter(m => parseInt(m.id) !== parseInt(memberId));
          hh.total_members = data.total_members;

          // Update main table count cell
          const countCell = document.getElementById(`hhRowMembersCount_${hh.id}`);
          if (countCell) countCell.innerText = hh.total_members;

          renderViewHouseholdMembersTable(hh);

          if (data.dynamic_households !== undefined && data.dynamic_population !== undefined) {
            updateMainTableDemographics(currentHouseholdPurokId, data.dynamic_households, data.dynamic_population);
          }
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to remove member.', 'danger');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error.', 'danger');
      }
    }
  }

  // =========================================================================
  // Add Household with Manual Initial Members
  // =========================================================================
  function openAddHouseholdModal() {
    const f = document.getElementById('formAddHousehold');
    if (f) f.reset();

    document.getElementById('addHhPurokName').value = currentHouseholdPurokName;
    document.getElementById('addHhPurokId').value = currentHouseholdPurokId || '';

    const pNum = currentHouseholdPurokName.replace(/[^0-9]/g, '') || '01';
    const nextCode = `HH-${pNum.padStart(2, '0')}-${String(allLoadedHouseholds.length + 1).padStart(3, '0')}`;
    const codeInput = document.getElementById('addHhCodeInput');
    if (codeInput) codeInput.value = nextCode;

    // Reset extra members container
    const mContainer = document.getElementById('createMembersContainer');
    if (mContainer) mContainer.innerHTML = '';

    openModal('modalAddHousehold');
  }

  function addManualMemberRow() {
    const mContainer = document.getElementById('createMembersContainer');
    if (!mContainer) return;

    const rowId = 'mem_row_' + Date.now();
    const div = document.createElement('div');
    div.id = rowId;
    div.style.display = 'grid';
    div.style.gridTemplateColumns = '2fr 1.5fr 1fr 1fr auto';
    div.style.gap = '6px';
    div.style.alignItems = 'center';
    div.style.background = '#FFFFFF';
    div.style.padding = '6px 8px';
    div.style.borderRadius = '4px';
    div.style.border = '1px solid var(--color-border-light)';

    div.innerHTML = `
      <input type="text" class="form-control form-control-sm member-input-name" placeholder="Member Name" required style="font-size:10px;height:26px;">
      <select class="form-control form-control-sm member-input-relation" style="font-size:10px;height:26px;">
        <option value="Spouse">Spouse</option>
        <option value="Child" selected>Child</option>
        <option value="Son">Son</option>
        <option value="Daughter">Daughter</option>
        <option value="Parent">Parent</option>
        <option value="Sibling">Sibling</option>
        <option value="Other">Other</option>
      </select>
      <select class="form-control form-control-sm member-input-gender" style="font-size:10px;height:26px;">
        <option value="Male" selected>Male</option>
        <option value="Female">Female</option>
      </select>
      <input type="number" class="form-control form-control-sm member-input-age" value="15" min="0" max="110" style="font-size:10px;height:26px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('${rowId}').remove()" style="font-size:10px;padding:2px 6px;height:26px;" title="Remove">&times;</button>
    `;

    mContainer.appendChild(div);
  }

  async function saveNewHousehold(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveNewHh');
    const f = document.getElementById('formAddHousehold');
    const formData = new FormData(f);

    // Collect manual members rows if any
    const mRows = document.querySelectorAll('#createMembersContainer > div');
    const extraMembers = [];
    mRows.forEach(r => {
      const name = r.querySelector('.member-input-name')?.value?.trim();
      const relation = r.querySelector('.member-input-relation')?.value;
      const gender = r.querySelector('.member-input-gender')?.value;
      const age = parseInt(r.querySelector('.member-input-age')?.value) || 20;
      if (name) {
        extraMembers.push({ full_name: name, relationship: relation, gender: gender, age: age });
      }
    });

    if (extraMembers.length > 0) {
      formData.append('members_json', JSON.stringify(extraMembers));
    }
    // Head + additional members count
    formData.append('total_members', 1 + extraMembers.length);

    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/create.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();

      btn.disabled = false;
      btn.innerText = 'Save';

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Household registered successfully.', 'success');
        }
        closeModal('modalAddHousehold');
        loadPurokHouseholds(currentHouseholdPurokId, currentHouseholdPurokName);
        if (data.dynamic_households !== undefined && data.dynamic_population !== undefined) {
          updateMainTableDemographics(currentHouseholdPurokId, data.dynamic_households, data.dynamic_population);
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to save household.', 'danger');
        } else {
          alert(data.message);
        }
      }
    } catch (err) {
      btn.disabled = false;
      btn.innerText = 'Save';
      if (typeof showToast === 'function') {
        showToast('Network error while saving household.', 'danger');
      }
    }
  }

  function openEditHouseholdModal(hhId) {
    const hh = allLoadedHouseholds.find(h => parseInt(h.id) === parseInt(hhId));
    if (!hh) return;

    document.getElementById('editHhId').value = hh.id;
    document.getElementById('editHhFamilyName').value = hh.family_name;
    document.getElementById('editHhCode').value = hh.household_code;
    document.getElementById('editHhHouseNumber').value = hh.house_number || '';

    document.getElementById('editHhHeadName').value = hh.head_full_name;
    document.getElementById('editHhHeadPhone').value = hh.head_phone;
    document.getElementById('editHhHeadRel').value = hh.head_relation || 'Household Head';
    document.getElementById('editHhHeadGender').value = hh.head_gender || 'Male';
    document.getElementById('editHhHeadAge').value = hh.head_age || 38;

    document.getElementById('editHhTotalMembers').value = hh.total_members || 4;

    openModal('modalEditHousehold');
  }

  async function saveEditHousehold(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveEditHh');
    const f = document.getElementById('formEditHousehold');
    const formData = new FormData(f);

    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/update.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();

      btn.disabled = false;
      btn.innerText = 'Save Changes';

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Household updated.', 'success');
        }
        closeModal('modalEditHousehold');
        loadPurokHouseholds(currentHouseholdPurokId, currentHouseholdPurokName);
        if (data.dynamic_households !== undefined && data.dynamic_population !== undefined) {
          updateMainTableDemographics(currentHouseholdPurokId, data.dynamic_households, data.dynamic_population);
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to update.', 'danger');
        }
      }
    } catch (err) {
      btn.disabled = false;
      btn.innerText = 'Save Changes';
      if (typeof showToast === 'function') {
        showToast('Network error while saving.', 'danger');
      }
    }
  }

  function archiveHouseholdFamily(hhId, familyName) {
    if (typeof showConfirmModal === 'function') {
      showConfirmModal(
        'Archive Household',
        `Are you sure you want to archive "${familyName}"?`,
        () => performArchiveHousehold(hhId),
        'btn-danger',
        'Archive'
      );
    } else {
      if (confirm(`Archive "${familyName}"?`)) {
        performArchiveHousehold(hhId);
      }
    }
  }

  async function performArchiveHousehold(hhId) {
    try {
      const fd = new FormData();
      fd.append('id', hhId);

      const res = await fetch('<?= BASE_URL ?>/backend/functions/purok_households/delete.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json();

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Household archived.', 'success');
        }
        loadPurokHouseholds(currentHouseholdPurokId, currentHouseholdPurokName);
        if (data.dynamic_households !== undefined && data.dynamic_population !== undefined) {
          updateMainTableDemographics(currentHouseholdPurokId, data.dynamic_households, data.dynamic_population);
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Failed to archive.', 'danger');
        }
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast('Network error.', 'danger');
      }
    }
  }

  function updateMainTableDemographics(purokId, dynHh, dynPop) {
    if (!purokId) return;

    // 1. Update households cell in puroks.php and barangay.php
    const hhCell1 = document.getElementById(`purok_households_val_${purokId}`);
    if (hhCell1 && dynHh !== undefined && dynHh !== null) {
      hhCell1.innerText = Number(dynHh).toLocaleString();
    }
    const hhCell2 = document.getElementById(`brgy_purok_households_val_${purokId}`);
    if (hhCell2 && dynHh !== undefined && dynHh !== null) {
      hhCell2.innerText = Number(dynHh).toLocaleString();
    }

    // 2. Update population cell in puroks.php and barangay.php
    const popCell1 = document.getElementById(`purok_population_val_${purokId}`);
    if (popCell1 && dynPop !== undefined && dynPop !== null) {
      popCell1.innerText = Number(dynPop).toLocaleString();
    }
    const popCell2 = document.getElementById(`brgy_purok_population_val_${purokId}`);
    if (popCell2 && dynPop !== undefined && dynPop !== null) {
      popCell2.innerText = Number(dynPop).toLocaleString();
    }

    // 3. Update 'View Families (X)' buttons
    const btn1 = document.getElementById(`purok_families_btn_${purokId}`);
    if (btn1 && dynHh !== undefined && dynHh !== null) {
      btn1.innerText = `View Families (${dynHh})`;
    }
    const btn2 = document.getElementById(`brgy_purok_families_btn_${purokId}`);
    if (btn2 && dynHh !== undefined && dynHh !== null) {
      btn2.innerText = `View Families (${dynHh})`;
    }
    const fallbackBtns = document.querySelectorAll(`button[onclick*="openPurokHouseholdsModal(${purokId},"]`);
    fallbackBtns.forEach(btn => {
      if (dynHh !== undefined && dynHh !== null) {
        btn.innerText = `View Families (${dynHh})`;
      }
    });

    // 4. Update top metric cards
    recalculateTopMetrics();
  }

  function recalculateTopMetrics() {
    const hhCells = document.querySelectorAll('[id^="purok_households_val_"]');
    const popCells = document.querySelectorAll('[id^="purok_population_val_"]');

    if (hhCells.length > 0) {
      let totalHh = 0;
      hhCells.forEach(c => {
        const val = parseInt((c.innerText || '').replace(/,/g, ''), 10) || 0;
        totalHh += val;
      });
      const topHh = document.getElementById('topMetricTotalHouseholds');
      if (topHh) topHh.innerText = totalHh.toLocaleString();
    }

    if (popCells.length > 0) {
      let totalPop = 0;
      popCells.forEach(c => {
        const val = parseInt((c.innerText || '').replace(/,/g, ''), 10) || 0;
        totalPop += val;
      });
      const topPop = document.getElementById('topMetricTotalPopulation');
      if (topPop) topPop.innerText = totalPop.toLocaleString();
    }
  }

  function printPurokHouseholdRoster() {
    if (!allLoadedHouseholds || allLoadedHouseholds.length === 0) {
      alert('No household records available to print.');
      return;
    }

    const printWin = window.open('', '_blank', 'width=800,height=600');
    if (!printWin) return;

    let rowsHtml = '';
    allLoadedHouseholds.forEach((hh, idx) => {
      const regText = hh.is_registered_in_system ? 'Registered' : 'Not Registered';
      rowsHtml += `
        <tr>
          <td style="text-align:center;">${idx + 1}</td>
          <td>${escapeHtml(hh.household_code)}</td>
          <td>${escapeHtml(hh.family_name)}</td>
          <td>${escapeHtml(hh.head_full_name)} (${escapeHtml(hh.head_relation || 'Head')})</td>
          <td>${escapeHtml(hh.head_phone || '—')}</td>
          <td style="text-align:center;">${regText}</td>
          <td style="text-align:center;">${hh.total_members}</td>
          <td>${escapeHtml(hh.house_number || '—')}</td>
        </tr>
      `;
    });

    const docContent = `
      <!DOCTYPE html>
      <html>
      <head>
        <title>Household Roster — ${escapeHtml(currentHouseholdPurokName)}</title>
        <style>
          body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 11px; padding: 20px; color: #24313A; }
          h2 { margin: 0 0 4px 0; font-size: 14px; color: #17324D; }
          .meta { font-size: 10px; color: #66737D; margin-bottom: 12px; }
          table { width: 100%; border-collapse: collapse; font-size: 10px; }
          th { background: #F0F3F4; border: 1px solid #D9E0E3; padding: 6px 8px; text-align: left; }
          td { border: 1px solid #D9E0E3; padding: 6px 8px; }
          @media print { button { display: none; } }
        </style>
      </head>
      <body>
        <div style="text-align:right;margin-bottom:8px;">
          <button onclick="window.print()" style="padding:4px 10px;font-size:10px;cursor:pointer;">Print</button>
        </div>
        <h2>Household Families Roster — ${escapeHtml(currentHouseholdPurokName)}</h2>
        <div class="meta">Barangay <?= clean($barangay['name'] ?? 'Hinaplanon') ?></div>
        <table>
          <thead>
            <tr>
              <th style="width:25px;text-align:center;">#</th>
              <th style="width:75px;">HH Code</th>
              <th>Family Name</th>
              <th>1 Authorized Representative</th>
              <th>Contact</th>
              <th style="width:75px;text-align:center;">Registered</th>
              <th style="width:50px;text-align:center;">Members</th>
              <th>Address</th>
            </tr>
          </thead>
          <tbody>
            ${rowsHtml}
          </tbody>
        </table>
      </body>
      </html>
    `;

    printWin.document.open();
    printWin.document.write(docContent);
    printWin.document.close();
  }
</script>
