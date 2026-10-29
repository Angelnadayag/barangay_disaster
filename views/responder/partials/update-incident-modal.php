<?php
// ============================================================================
// Partial: Responder Update Incident Report Modal
// Allows tactical field responders to update active/ongoing incident reports
// Strictly restricted to Ongoing status (Completed reports are immutable)
// Anti-slop UI design adhering to palette: #17324D, #2F6F73, #EEF2F4
// ============================================================================

if (!isset($responderPuroks)) {
    $db = getDBConnection();
    $rBrgyId = (int)($user['barangay_id'] ?? 0);
    if ($rBrgyId === 0) {
        $bStmt = $db->prepare("SELECT barangay_id FROM users WHERE id = ?");
        $bStmt->execute([(int)$user['id']]);
        $rBrgyId = (int)$bStmt->fetchColumn();
    }
    $purokStmt = $db->prepare("SELECT id, name FROM puroks WHERE barangay_id = ? ORDER BY name ASC");
    $purokStmt->execute([$rBrgyId]);
    $responderPuroks = $purokStmt->fetchAll(PDO::FETCH_ASSOC);
    sortPuroksMainFirst($responderPuroks);
}

if (!isset($hazardTypes)) {
    $db = getDBConnection();
    $hazardTypesStmt = $db->query("SELECT name FROM hazard_type_options ORDER BY name ASC");
    $hazardTypes = $hazardTypesStmt ? $hazardTypesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (empty($hazardTypes)) {
        $hazardTypes = ['Flood', 'Flash Flood', 'Typhoon', 'Landslide', 'Earthquake', 'Fire', 'Storm Surge'];
    }
}
?>
<style>
/* Tactical Modal Overlay & Card Styling */
.tactical-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(16, 35, 54, 0.68);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
  z-index: 99999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 12px;
}

.tactical-modal-overlay.active {
  display: flex !important;
}

.tactical-modal-card {
  background: #FFFFFF;
  border: none;
  border-radius: 12px;
  width: 100%;
  max-width: 680px;
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 14px 40px rgba(16, 35, 54, 0.28);
  animation: tacticalModalFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes tacticalModalFadeIn {
  from { opacity: 0; transform: scale(0.97) translateY(8px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
</style>

<div class="tactical-modal-overlay" id="responderUpdateIncidentModal">
  <div class="tactical-modal-card" style="max-width: 680px; max-height: 90vh;">
    <!-- Modal Header -->
    <div style="padding: 14px 18px; background: #17324D; color: #FFFFFF; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
      <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <h3 style="margin: 0; font-family: 'Poppins', sans-serif; font-size: 14.5px; font-weight: 700; color: #FFFFFF; display: flex; align-items: center; gap: 6px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
          </svg>
          Update Incident Report
        </h3>
        <span id="resp_update_tracking_badge" style="font-size: 10px; font-family: monospace; font-weight: 700; background: rgba(255, 255, 255, 0.16); color: #FFFFFF; padding: 2px 7px; border-radius: 4px;">
          DR-0000
        </span>
        <span style="font-size: 9.5px; font-weight: 700; background: #EBF3F5; color: #2F6F73; padding: 2px 7px; border-radius: 4px;">
          Status: Ongoing
        </span>
      </div>
      <button type="button" onclick="closeResponderUpdateModal()" style="background: transparent; border: none; color: #FFFFFF; font-size: 20px; line-height: 1; cursor: pointer; opacity: 0.85;" title="Close Modal">&times;</button>
    </div>

    <!-- Modal Form Body -->
    <form id="responderUpdateForm" onsubmit="handleUpdateIncidentSubmit(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
      <input type="hidden" name="id" id="resp_update_id">
      <input type="hidden" name="status" value="Ongoing">

      <div style="padding: 16px 18px; overflow-y: auto; max-height: calc(90vh - 120px); display: flex; flex-direction: column; gap: 12px; font-family: 'Roboto', sans-serif;">
        
        <!-- Info Banner -->
        <div style="background: #F8FAFB; border-left: 3px solid #2F6F73; padding: 8px 12px; border-radius: 0 6px 6px 0; font-size: 10.5px; color: #556270; line-height: 1.4;">
          <strong>Field Responder Report Update:</strong> Modify real-time incident assessments, casualties, and aid requests. Concluded incidents are managed by Barangay disaster authorities.
        </div>

        <!-- Row 1: Purok & Specific Location -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
          <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
              Purok Cluster <span style="color: #C62828;">*</span>
            </label>
            <select name="purok_name" id="resp_update_purok_name" class="form-control" required style="font-size: 11px; height: 34px; border-radius: 6px;">
              <option value="">Select Purok Cluster</option>
              <option value="All Puroks">All Puroks (Barangay-wide)</option>
              <?php foreach ($responderPuroks as $p): ?>
                <option value="<?= clean($p['name']) ?>"><?= clean($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
              Specific Street / Landmark
            </label>
            <input type="text" name="incident_location" id="resp_update_incident_location" class="form-control" placeholder="e.g. Near Purok Chapel or Riverbank" style="font-size: 11px; height: 34px; border-radius: 6px;">
          </div>
        </div>

        <!-- Row 2: Hazard Type, Severity, Urgency -->
        <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 10px;">
          <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
              Hazard / Calamity Type <span style="color: #C62828;">*</span>
            </label>
            <select name="disaster_type" id="resp_update_disaster_type" class="form-control" required style="font-size: 11px; height: 34px; border-radius: 6px;">
              <option value="">Select Hazard</option>
              <?php foreach ($hazardTypes as $ht): ?>
                <option value="<?= clean($ht) ?>"><?= clean($ht) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
              Severity Level <span style="color: #C62828;">*</span>
            </label>
            <select name="severity" id="resp_update_severity" class="form-control" required style="font-size: 11px; height: 34px; border-radius: 6px;">
              <option value="Low">Low</option>
              <option value="Moderate">Moderate</option>
              <option value="High">High</option>
              <option value="Critical">Critical</option>
            </select>
          </div>
          <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
              Urgency <span style="color: #C62828;">*</span>
            </label>
            <select name="urgency" id="resp_update_urgency" class="form-control" required style="font-size: 11px; height: 34px; border-radius: 6px;">
              <option value="Immediate">Immediate</option>
              <option value="High">High</option>
              <option value="Medium">Medium</option>
              <option value="Low">Low</option>
            </select>
          </div>
        </div>

        <!-- Row 3: Families & Population Impact -->
        <div style="border-top: 1px solid #EEF2F4; padding-top: 10px;">
          <div style="font-size: 10px; font-weight: 700; color: #8A96A0; text-transform: uppercase; margin-bottom: 8px;">
            Population & Displacement Impact
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Affected Families
              </label>
              <input type="number" name="affected_families" id="resp_update_affected_families" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Affected Individuals
              </label>
              <input type="number" name="affected_individuals" id="resp_update_affected_individuals" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Displaced Families
              </label>
              <input type="number" name="displaced_families" id="resp_update_displaced_families" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
          </div>
        </div>

        <!-- Row 4: Casualties & Medical Impact -->
        <div>
          <div style="font-size: 10px; font-weight: 700; color: #8A96A0; text-transform: uppercase; margin-bottom: 8px;">
            Casualties & Safety Toll
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Casualties
              </label>
              <input type="number" name="casualties_count" id="resp_update_casualties_count" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Injuries
              </label>
              <input type="number" name="injuries_count" id="resp_update_injuries_count" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
            <div>
              <label style="display: block; font-size: 10.5px; font-weight: 600; color: #17324D; margin-bottom: 3px;">
                Missing Persons
              </label>
              <input type="number" name="missing_count" id="resp_update_missing_count" class="form-control" min="0" value="0" style="font-size: 11px; height: 34px; border-radius: 6px;">
            </div>
          </div>
        </div>

        <!-- Row 5: Ground Situation Overview -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
            Ground Situation Overview <span style="color: #C62828;">*</span>
          </label>
          <textarea name="situation_overview" id="resp_update_situation_overview" class="form-control" rows="2" required placeholder="Describe current water levels, structural damages, road blockages, or weather conditions..." style="font-size: 11px; border-radius: 6px;"></textarea>
        </div>

        <!-- Row 6: Requested Assistance -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 600; color: #17324D; margin-bottom: 4px;">
            Requested Assistance / Relief Support
          </label>
          <textarea name="requested_assistance" id="resp_update_requested_assistance" class="form-control" rows="2" placeholder="Specify needed medical aid, boats, food packs, rescue equipment, or evacuation transport..." style="font-size: 11px; border-radius: 6px;"></textarea>
        </div>

      </div>

      <!-- Modal Footer -->
      <div style="padding: 12px 18px; border-top: 1px solid #EEF2F4; background: #FFFFFF; display: flex; justify-content: flex-end; align-items: center; gap: 8px;">
        <button type="button" onclick="closeResponderUpdateModal()" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 6px 14px; border-color: #CBD5E1; color: #556270;">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 6px 16px; background: #17324D; border: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Save Incident Update
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openUpdateIncidentModal(inc) {
  if (!inc || inc.status === 'Completed') {
    alert('Cannot update: Only ongoing incident reports can be edited by responders.');
    return;
  }

  document.getElementById('resp_update_id').value = inc.id;
  document.getElementById('resp_update_tracking_badge').innerText = inc.tracking_code || 'DR-' + inc.id;
  
  const purokSelect = document.getElementById('resp_update_purok_name');
  if (purokSelect) purokSelect.value = inc.purok_name || '';

  const locInput = document.getElementById('resp_update_incident_location');
  if (locInput) locInput.value = inc.incident_location || '';

  const hazardSelect = document.getElementById('resp_update_disaster_type');
  if (hazardSelect) hazardSelect.value = inc.disaster_type || '';

  const sevSelect = document.getElementById('resp_update_severity');
  if (sevSelect) sevSelect.value = inc.severity || 'Moderate';

  const urgSelect = document.getElementById('resp_update_urgency');
  if (urgSelect) urgSelect.value = inc.urgency || 'Immediate';

  document.getElementById('resp_update_affected_families').value = inc.affected_families ?? 0;
  document.getElementById('resp_update_affected_individuals').value = inc.affected_individuals ?? inc.affected_families ?? 0;
  document.getElementById('resp_update_displaced_families').value = inc.displaced_families ?? 0;
  document.getElementById('resp_update_casualties_count').value = inc.casualties_count ?? 0;
  document.getElementById('resp_update_injuries_count').value = inc.injuries_count ?? 0;
  document.getElementById('resp_update_missing_count').value = inc.missing_count ?? 0;
  
  document.getElementById('resp_update_situation_overview').value = inc.situation_overview || '';
  document.getElementById('resp_update_requested_assistance').value = inc.requested_assistance || '';

  const modal = document.getElementById('responderUpdateIncidentModal');
  if (modal) {
    modal.classList.add('active');
  }
}

function closeResponderUpdateModal() {
  const modal = document.getElementById('responderUpdateIncidentModal');
  if (modal) {
    modal.classList.remove('active');
  }
}

async function handleUpdateIncidentSubmit(e) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = form.querySelector('button[type="submit"]');
  const origHtml = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = 'Updating...';

  const formData = new FormData(form);

  try {
    const res = await fetch('<?= BASE_URL ?>/backend/functions/disaster_requests/update.php', {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const data = await res.json();
    if (data.success) {
      alert(data.message || 'Incident report updated successfully.');
      closeResponderUpdateModal();
      window.location.reload();
    } else {
      alert(data.message || 'Failed to update report.');
      submitBtn.disabled = false;
      submitBtn.innerHTML = origHtml;
    }
  } catch (err) {
    console.error(err);
    alert('An error occurred while updating the incident report.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = origHtml;
  }
}
</script>
