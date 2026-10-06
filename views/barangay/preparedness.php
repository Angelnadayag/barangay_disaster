<?php
// ============================================================================
// Views (Barangay Head): Preparedness Activities & Community Events Module
// Features: Event Scheduling, Conditional Action Buttons (Not Started vs Started),
// Locked Start/End times on Edit if started, Resident Registration with live SMS dispatch.
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('barangay_head');
$user = getCurrentUser();
$barangayId = (int)$user['barangay_id'];
$db = getDBConnection();

$bStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$bStmt->execute([$barangayId]);
$barangay = $bStmt->fetch();

// Fetch activities for this barangay
$sql = "
    SELECT pa.*, b.name AS barangay_name, u.full_name AS creator_name 
    FROM preparedness_activities pa 
    LEFT JOIN barangays b ON pa.barangay_id = b.id 
    LEFT JOIN users u ON pa.created_by = u.id 
    WHERE (pa.barangay_id = ? OR pa.barangay_id IS NULL)
    ORDER BY pa.start_datetime ASC
";
$stmt = $db->prepare($sql);
$stmt->execute([$barangayId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch registered residents of this barangay for quick selection in event registration
$resStmt = $db->prepare("
    SELECT id, first_name, last_name, full_name, phone, purok 
    FROM residents 
    WHERE barangay_id = ? AND status != 'archived'
    ORDER BY first_name ASC, last_name ASC
");
$resStmt->execute([$barangayId]);
$barangayResidents = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all attendees for events in this barangay
$attStmt = $db->prepare("
    SELECT aa.*, pa.title AS activity_title 
    FROM activity_attendees aa
    JOIN preparedness_activities pa ON aa.activity_id = pa.id
    WHERE pa.barangay_id = ?
    ORDER BY aa.registered_at DESC
");
$attStmt->execute([$barangayId]);
$rawAttendees = $attStmt->fetchAll(PDO::FETCH_ASSOC);

$attendeesByActivity = [];
foreach ($rawAttendees as $att) {
    $attendeesByActivity[$att['activity_id']][] = $att;
}

// Fetch recent SMS dispatch logs
$smsLogsStmt = $db->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 50");
$recentSmsLogs = $smsLogsStmt ? $smsLogsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$now = time();

$pageTitle = "Manage Events — Barangay " . ($barangay['name'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<style>
/* Robust Modal Styling for Events Module */
.modal-overlay {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  background: rgba(15, 23, 42, 0.65) !important;
  backdrop-filter: blur(4px) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  z-index: 99999 !important;
  opacity: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
  transition: opacity 0.2s ease, visibility 0.2s ease !important;
  margin: 0 !important;
  padding: 16px !important;
  box-sizing: border-box !important;
}

.modal-overlay.active {
  opacity: 1 !important;
  visibility: visible !important;
  pointer-events: auto !important;
}

.modal-dialog {
  background: #ffffff !important;
  width: 100% !important;
  max-width: 600px !important;
  max-height: 92vh !important;
  border-radius: 12px !important;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
  border: 1px solid var(--color-border-light, #E2E8F0) !important;
  margin: auto !important;
}

.modal-header {
  padding: 14px 18px !important;
  border-bottom: 1px solid var(--color-border-light, #E2E8F0) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  background: #F8FAFC !important;
}

.modal-body {
  padding: 18px !important;
  overflow-y: auto !important;
  flex: 1 1 auto !important;
}

.modal-footer {
  padding: 12px 18px !important;
  border-top: 1px solid var(--color-border-light, #E2E8F0) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: flex-end !important;
  gap: 8px !important;
  background: #F8FAFC !important;
}

.sms-preview-card {
  background: #F8FAFC;
  border: 1px solid #CBD5E1;
  border-left: 4px solid var(--color-primary, #0284C7);
  border-radius: 6px;
  padding: 12px;
  font-family: monospace, sans-serif;
  font-size: 11px;
  line-height: 1.5;
  color: #1E293B;
  white-space: pre-wrap;
  margin-top: 6px;
}
</style>

  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Manage Events — <?= clean($barangay['name'] ?? '') ?></h1>
      <p class="page-header-desc">
        Community disaster simulations, tsunami/flood drills, family preparedness orientations, and rescuer training events.
      </p>
    </div>
    <div class="page-header-actions" style="display:flex;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="openModal('smsOutboxModal')" title="View recent SMS dispatches, delivery statuses, and gateway logs">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
        SMS Dispatch Logs (<?= count($recentSmsLogs) ?>)
      </button>
      <button class="btn btn-primary" onclick="openModal('createActivityModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Schedule Barangay Drill
      </button>
    </div>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Schedule Date & Time</th>
          <th>Activity Title</th>
          <th>Type</th>
          <th>Venue Location</th>
          <th>Lead Facilitator</th>
          <th>Attendance / Target</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($activities)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;">No preparedness activities scheduled for this barangay.</td></tr>
        <?php else: ?>
          <?php foreach ($activities as $act): ?>
            <?php
              $startTime = strtotime($act['start_datetime']);
              $endTime = strtotime($act['end_datetime']);
              $isStarted = ($now >= $startTime);
              $isCompleted = ($act['status'] === 'Completed');
              $isCancelled = ($act['status'] === 'Cancelled');
              $actAttendees = $attendeesByActivity[$act['id']] ?? [];
            ?>
            <tr>
              <td style="font-family:var(--font-secondary);font-size:10px;white-space:nowrap;">
                <div style="font-weight:600;color:var(--color-primary);"><?= formatDate($act['start_datetime'], 'M d, Y') ?></div>
                <div style="font-size:9px;color:var(--color-text-muted);">
                  <?= formatDate($act['start_datetime'], 'h:i A') ?> — <?= formatDate($act['end_datetime'], 'h:i A') ?>
                </div>
                <?php if (!$isStarted && !$isCompleted && !$isCancelled): ?>
                  <span style="display:inline-block;margin-top:2px;font-size:8.5px;color:var(--color-info,#0284C7);background:#E0F2FE;padding:1px 5px;border-radius:4px;font-weight:600;">Upcoming</span>
                <?php elseif ($isStarted && !$isCompleted && !$isCancelled): ?>
                  <span style="display:inline-block;margin-top:2px;font-size:8.5px;color:#16A34A;background:#DCFCE7;padding:1px 5px;border-radius:4px;font-weight:600;">● Started / In Progress</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);"><?= clean($act['title']) ?></div>
                <div style="font-size:9.5px;color:var(--color-text-secondary);"><?= clean($act['description']) ?></div>
              </td>
              <td><span class="badge badge-info"><?= clean($act['activity_type']) ?></span></td>
              <td><?= clean($act['venue']) ?></td>
              <td style="font-size:10px;color:var(--color-text-secondary);font-weight:500;"><?= clean($act['assigned_personnel']) ?></td>
              <td style="font-family:var(--font-secondary);">
                <?php if ($act['status'] === 'Completed'): ?>
                  <span style="font-weight:700;color:var(--color-success);"><?= (int)$act['actual_participants'] ?> / <?= (int)$act['target_participants'] ?> attended</span>
                <?php else: ?>
                  <div style="font-size:10.5px;font-weight:600;color:var(--color-primary);">
                    Registered: <?= count($actAttendees) ?> / <?= (int)$act['target_participants'] ?>
                  </div>
                  <?php if (count($actAttendees) > 0): ?>
                    <button type="button" class="btn btn-outline btn-xs" style="font-size:9.5px;padding:1px 5px;margin-top:2px;" onclick='openViewAttendeesModal(<?= json_encode($act) ?>, <?= json_encode($actAttendees) ?>)'>
                      View List (<?= count($actAttendees) ?>)
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td><?= renderStatusBadge($act['status']) ?></td>
              <td>
                <?php if ($isCancelled): ?>
                  <span style="font-size:10.5px;color:var(--color-danger);font-weight:600;">Cancelled</span>
                <?php elseif ($isCompleted): ?>
                  <div style="display:flex;gap:4px;align-items:center;">
                    <span style="font-size:10px;color:var(--color-success);font-weight:600;">✓ Accomplished</span>
                    <?php if (count($actAttendees) > 0): ?>
                      <button type="button" class="btn btn-outline btn-xs" style="font-size:9.5px;padding:2px 6px;" onclick='openViewAttendeesModal(<?= json_encode($act) ?>, <?= json_encode($actAttendees) ?>)'>
                        Attendees (<?= count($actAttendees) ?>)
                      </button>
                    <?php endif; ?>
                  </div>
                <?php elseif (!$isStarted): ?>
                  <!-- ======================================================== -->
                  <!-- EVENT NOT YET STARTED: Show ONLY Edit or Cancel           -->
                  <!-- ======================================================== -->
                  <div style="display:flex;gap:5px;align-items:center;flex-wrap:nowrap;">
                    <button type="button" class="btn btn-outline btn-sm" onclick='openEditActivityModal(<?= json_encode($act) ?>, false)' style="font-size:11px;padding:4px 8px;">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                      Edit
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick='confirmCancelActivity(<?= json_encode($act) ?>)' style="color:var(--color-danger);border-color:var(--color-danger);font-size:11px;padding:4px 8px;">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                      Cancel
                    </button>
                  </div>
                <?php else: ?>
                  <!-- ======================================================== -->
                  <!-- EVENT HAS STARTED: Show Register, Done, Edit, Cancel     -->
                  <!-- On edit: Start Date/Time & End Date/Time CANNOT be edited -->
                  <!-- ======================================================== -->
                  <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary btn-sm" onclick='openRegisterResidentModal(<?= json_encode($act) ?>, <?= json_encode($actAttendees) ?>)' style="font-size:11px;padding:4px 8px;" title="Register Resident & Send SMS Notice">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
                      Register Resident
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick='openEvaluateModal(<?= json_encode($act) ?>)' style="font-size:11px;padding:4px 8px;background:var(--color-success,#16A34A);border-color:var(--color-success,#16A34A);color:#fff;" title="Mark Event Done">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Done
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick='openEditActivityModal(<?= json_encode($act) ?>, true)' style="font-size:11px;padding:4px 8px;" title="Edit Event Details (Times Locked)">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                      Edit
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick='confirmCancelActivity(<?= json_encode($act) ?>)' style="color:var(--color-danger);border-color:var(--color-danger);font-size:11px;padding:4px 8px;" title="Cancel Event">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;vertical-align:-1px;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                      Cancel
                    </button>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<!-- ========================================================================= -->
<!-- Modal 1: Schedule Barangay Drill / Event                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Schedule Barangay Drill or Event</h3>
      <button class="modal-close-btn" onclick="closeModal('createActivityModal')">&times;</button>
    </div>
    <form id="createActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Activity Title</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Purok Riverside Flash Flood Evacuation Drill" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Type</label>
          <select name="activity_type" class="form-control" required>
            <option value="Disaster Drill">Disaster Drill</option>
            <option value="Community Seminar">Community Seminar</option>
            <option value="First Aid / Rescuer Training">First Aid Training</option>
            <option value="IEC Campaign">IEC Family Campaign</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Venue / Exact Location</label>
          <input type="text" name="venue" class="form-control" placeholder="e.g. Barangay Covered Court / Riverside Grounds" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Start Date & Time</label>
            <input type="datetime-local" name="start_datetime" class="form-control" required value="<?= date('Y-m-d\TH:00', strtotime('+1 day')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">End Date & Time</label>
            <input type="datetime-local" name="end_datetime" class="form-control" required value="<?= date('Y-m-d\TH:00', strtotime('+1 day +3 hours')) ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Lead Facilitator</label>
            <input type="text" name="assigned_personnel" class="form-control" placeholder="e.g. Barangay Disaster Committee, CDRRMO Facilitator" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Target Residents</label>
            <input type="number" name="target_participants" class="form-control" min="5" value="60" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Brief Description & Objectives</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Drill scenarios, safety briefings, assembly sites..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('createActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Schedule Activity</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: Edit Event Modal (Times Locked if Started)                       -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);" id="editModalHeaderTitle">Edit Event Details</h3>
      <button class="modal-close-btn" onclick="closeModal('editActivityModal')">&times;</button>
    </div>
    <form id="editActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/update.php">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="activity_id" id="edit_act_id">

      <div class="modal-body">
        <!-- Locked time notice banner -->
        <div id="edit_locked_time_notice" style="display:none;background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;padding:10px 12px;border-radius:6px;font-size:11.5px;font-weight:600;margin-bottom:12px;">
          ⚠️ This event has already started. In accordance with operational integrity, <strong>Start Date & Time and End Date & Time cannot be edited</strong>.
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Title</label>
          <input type="text" name="title" id="edit_act_title" class="form-control" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Type</label>
          <select name="activity_type" id="edit_act_type" class="form-control" required>
            <option value="Disaster Drill">Disaster Drill</option>
            <option value="Community Seminar">Community Seminar</option>
            <option value="First Aid / Rescuer Training">First Aid Training</option>
            <option value="IEC Campaign">IEC Family Campaign</option>
            <option value="Contingency Planning">Contingency Planning</option>
            <option value="Hazard Mapping Workshop">Hazard Mapping Workshop</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Venue / Exact Location</label>
          <input type="text" name="venue" id="edit_act_venue" class="form-control" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Start Date & Time</label>
            <input type="datetime-local" name="start_datetime" id="edit_act_start" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">End Date & Time</label>
            <input type="datetime-local" name="end_datetime" id="edit_act_end" class="form-control" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Lead Facilitator</label>
            <input type="text" name="assigned_personnel" id="edit_act_personnel" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Target Residents</label>
            <input type="number" name="target_participants" id="edit_act_target" class="form-control" min="5" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Brief Description & Objectives</label>
          <textarea name="description" id="edit_act_desc" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('editActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Mark Event as Done (Accomplished)                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="evaluateModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Mark Event as Done (Accomplished)</h3>
      <button class="modal-close-btn" onclick="closeModal('evaluateModal')">&times;</button>
    </div>
    <form id="evaluateForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/evaluate.php">
      <input type="hidden" name="action" value="evaluate">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="activity_id" id="evalActId">
      <div class="modal-body">
        <div style="font-weight:700;font-size:13px;color:var(--color-primary);margin-bottom:8px;" id="evalActName"></div>

        <div class="form-group">
          <label class="form-label form-label-required">Completion Status</label>
          <select name="status" class="form-control" required>
            <option value="Completed" selected>Completed (Successfully Conducted)</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Actual Participants Attended</label>
          <input type="number" name="actual_participants" id="evalActualInput" class="form-control" min="0" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Accomplishment Remarks & Outcomes</label>
          <textarea name="evaluation_summary" class="form-control" rows="3" placeholder="Summary of drill execution, community turnout, gaps observed, key takeaways..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('evaluateModal')">Cancel</button>
        <button type="submit" class="btn btn-success btn-sm">Mark as Done</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 4: Confirm Cancel Activity                                          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="cancelActivityModal">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-danger);">Cancel Event</h3>
      <button class="modal-close-btn" onclick="closeModal('cancelActivityModal')">&times;</button>
    </div>
    <form id="cancelActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/cancel.php">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="activity_id" id="cancel_act_id">
      <div class="modal-body">
        <p style="font-size:12.5px;color:var(--color-text);line-height:1.5;">
          Are you sure you want to cancel the event <strong id="cancel_act_name"></strong>? This will mark the activity as Cancelled and remove it from active execution.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('cancelActivityModal')">No, Keep Event</button>
        <button type="submit" class="btn btn-danger btn-sm">Yes, Cancel Event</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 5: Register Resident by Mobile & Send SMS Notice                    -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="registerResidentModal">
  <div class="modal-dialog" style="max-width:580px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">Register Resident & Send SMS Notice</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">Event has started — Register community attendees via mobile phone</div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('registerResidentModal')">&times;</button>
    </div>
    <form id="registerResidentForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/register_resident.php">
      <input type="hidden" name="action" value="register_resident">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="activity_id" id="reg_act_id">
      <input type="hidden" name="user_id" id="reg_user_id">

      <div class="modal-body">
        <!-- Event Identity Summary -->
        <div style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:12px;margin-bottom:14px;">
          <div style="font-size:13px;font-weight:700;color:#0369A1;" id="reg_display_event_title"></div>
          <div style="font-size:11px;color:#0C4A6E;margin-top:4px;" id="reg_display_event_meta"></div>
        </div>

        <!-- Quick Select Resident -->
        <div class="form-group">
          <label class="form-label">Select from Registered Barangay Residents (Optional)</label>
          <select id="reg_resident_select" class="form-control" onchange="onSelectResidentQuick(this)">
            <option value="">-- Choose Resident (Auto-fills name & mobile number) --</option>
            <?php foreach ($barangayResidents as $r): ?>
              <?php $displayName = clean($r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name'])); ?>
              <option value="<?= $r['id'] ?>" data-name="<?= $displayName ?>" data-phone="<?= clean($r['phone'] ?? '') ?>">
                <?= $displayName ?> <?= !empty($r['purok']) ? '(' . clean($r['purok']) . ')' : '' ?> <?= !empty($r['phone']) ? '— ' . clean($r['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Resident Full Name</label>
            <input type="text" name="resident_name" id="reg_resident_name" class="form-control" required placeholder="e.g. Maria Santos" oninput="updateSmsPreview()">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Resident Mobile Number</label>
            <input type="text" name="phone" id="reg_phone" class="form-control" required placeholder="09XXXXXXXXX" oninput="updateSmsPreview()">
          </div>
        </div>

        <!-- Live SMS Notice Preview -->
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
            <span>SMS Message to be Delivered:</span>
            <span style="font-size:10px;color:var(--color-primary);font-weight:600;">📱 Live Preview</span>
          </label>
          <div class="sms-preview-card" id="reg_sms_preview_box"></div>
        </div>

        <!-- Registered Attendees Count for this Event -->
        <div style="margin-top:12px;font-size:11px;color:var(--color-text-muted);" id="reg_attendees_summary_line"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('registerResidentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"></path><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
          Register & Send SMS
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 6: View Registered Attendees & SMS Logs                             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewAttendeesModal">
  <div class="modal-dialog" style="max-width:620px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);" id="view_att_modal_title">Event Registered Attendees</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">Attendance roster and SMS dispatch delivery log</div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('viewAttendeesModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="view_att_list_container"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('viewAttendeesModal')">Close</button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 7: SMS Outbox & System Delivery Logs                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="smsOutboxModal">
  <div class="modal-dialog" style="max-width:760px;">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" style="font-size:14px;font-weight:700;color:var(--color-primary);">SMS Outbox & Dispatch History</h3>
        <div style="font-size:10px;color:var(--color-text-muted);">
          Current Provider: <strong style="color:var(--color-primary);"><?= strtoupper(defined('SMS_PROVIDER') ? SMS_PROVIDER : 'textbee') ?></strong>
          <?php if (defined('SMS_PROVIDER') && SMS_PROVIDER === 'textbee'): ?>
            • Free Android Gateway
          <?php endif; ?>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('smsOutboxModal')">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Info notice on TextBee Free Gateway setup -->
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px;margin-bottom:14px;font-size:11.5px;color:#166534;line-height:1.5;">
        <strong>💡 Free SMS Feature (Zero Cost):</strong>
        This system supports <a href="https://textbee.dev" target="_blank" style="color:#15803D;font-weight:700;text-decoration:underline;">TextBee.dev</a>, which uses your Android phone to relay real SMS to Philippine mobile numbers (+63 / 09) using your SIM load/unli-text promo for free. Configure your <code>TEXTBEE_API_KEY</code> and <code>TEXTBEE_DEVICE_ID</code> in <code>.env</code>.
      </div>

      <?php if (empty($recentSmsLogs)): ?>
        <div style="text-align:center;padding:32px;color:var(--color-text-muted);font-size:12px;">
          No SMS dispatch logs found yet. When an event is scheduled or a resident is registered, messages appear here.
        </div>
      <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
          <table class="data-table" style="font-size:11px;width:100%;">
            <thead>
              <tr>
                <th style="width:110px;">Date & Time</th>
                <th>Recipient</th>
                <th>Message Content</th>
                <th style="width:90px;">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentSmsLogs as $log): ?>
                <?php
                  $isSent = ($log['status'] === 'sent');
                  $isSimulated = (strpos($log['status'], 'simulated') !== false);
                  $badgeClass = $isSent ? 'badge-success' : ($isSimulated ? 'badge-info' : 'badge-danger');
                  $badgeLabel = $isSent ? 'Sent ✓' : ($isSimulated ? 'Simulated' : 'Failed ✗');
                ?>
                <tr>
                  <td style="font-size:10px;white-space:nowrap;color:var(--color-text-muted);">
                    <?= date('M d, Y', strtotime($log['created_at'])) ?><br>
                    <?= date('h:i:s A', strtotime($log['created_at'])) ?>
                  </td>
                  <td>
                    <div style="font-weight:600;color:var(--color-primary);"><?= clean($log['recipient_name'] ?: 'Resident') ?></div>
                    <div style="font-family:monospace;font-size:10px;color:var(--color-text-muted);"><?= clean($log['phone']) ?></div>
                  </td>
                  <td>
                    <div style="max-height:60px;overflow-y:auto;white-space:pre-wrap;font-family:monospace;font-size:10.5px;background:#F8FAFC;padding:6px;border-radius:4px;border:1px solid #E2E8F0;line-height:1.4;">
                      <?= clean($log['message']) ?>
                    </div>
                    <?php if (!empty($log['api_response']) && !$isSent): ?>
                      <div style="font-size:9.5px;color:#991B1B;margin-top:3px;word-break:break-word;">
                        Note: <?= clean(substr($log['api_response'], 0, 160)) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $badgeClass ?>" style="font-size:9.5px;" title="<?= clean($log['status']) ?>">
                      <?= $badgeLabel ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('smsOutboxModal')">Close</button>
    </div>
  </div>
</div>

<script>
// Modal Controls
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('active');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
      if (e.target === this) closeModal(this.id);
    });
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
    }
  });
});

let currentActiveEventForRegistration = null;

// Open Edit Event Modal
function openEditActivityModal(act, isStarted) {
  document.getElementById('edit_act_id').value = act.id;
  document.getElementById('edit_act_title').value = act.title || '';
  document.getElementById('edit_act_type').value = act.activity_type || 'Disaster Drill';
  document.getElementById('edit_act_venue').value = act.venue || '';
  document.getElementById('edit_act_personnel').value = act.assigned_personnel || '';
  document.getElementById('edit_act_target').value = act.target_participants || 50;
  document.getElementById('edit_act_desc').value = act.description || '';

  const startInput = document.getElementById('edit_act_start');
  const endInput = document.getElementById('edit_act_end');
  const lockedNotice = document.getElementById('edit_locked_time_notice');

  // Format datetime strings to HTML datetime-local: YYYY-MM-DDTHH:MM
  if (act.start_datetime) {
    startInput.value = act.start_datetime.replace(' ', 'T').substring(0, 16);
  }
  if (act.end_datetime) {
    endInput.value = act.end_datetime.replace(' ', 'T').substring(0, 16);
  }

  if (isStarted) {
    // If event has started: start time and end time CANNOT be edited
    startInput.setAttribute('disabled', 'disabled');
    endInput.setAttribute('disabled', 'disabled');
    startInput.setAttribute('readonly', 'readonly');
    endInput.setAttribute('readonly', 'readonly');
    lockedNotice.style.display = 'block';
    document.getElementById('editModalHeaderTitle').innerText = 'Edit Event (Start & End Times Locked)';
  } else {
    // If event has NOT yet started: start time and end time CAN be edited
    startInput.removeAttribute('disabled');
    endInput.removeAttribute('disabled');
    startInput.removeAttribute('readonly');
    endInput.removeAttribute('readonly');
    lockedNotice.style.display = 'none';
    document.getElementById('editModalHeaderTitle').innerText = 'Edit Event Details';
  }

  openModal('editActivityModal');
}

// Confirm Cancel Event Modal
function confirmCancelActivity(act) {
  document.getElementById('cancel_act_id').value = act.id;
  document.getElementById('cancel_act_name').innerText = act.title;
  openModal('cancelActivityModal');
}

// Open Evaluate / Done Modal
function openEvaluateModal(act) {
  document.getElementById('evalActId').value = act.id;
  document.getElementById('evalActName').innerText = act.title;
  document.getElementById('evalActualInput').value = act.actual_participants || act.target_participants;
  openModal('evaluateModal');
}

// Open Register Resident Modal with live SMS preview
function openRegisterResidentModal(act, attendees) {
  currentActiveEventForRegistration = act;
  const form = document.getElementById('registerResidentForm');
  if (form) form.reset();

  document.getElementById('reg_act_id').value = act.id;
  document.getElementById('reg_user_id').value = '';
  document.getElementById('reg_display_event_title').innerText = act.title;

  const startFmt = new Date(act.start_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  const endFmt = new Date(act.end_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  document.getElementById('reg_display_event_meta').innerText = `Venue: ${act.venue} • Starts: ${startFmt} • Ends: ${endFmt} • Facilitator: ${act.assigned_personnel}`;

  const attCount = attendees ? attendees.length : (act.actual_participants || 0);
  document.getElementById('reg_attendees_summary_line').innerText = `Total Residents Registered: ${attCount} attendee(s)`;

  updateSmsPreview();
  openModal('registerResidentModal');
}

// Quick Select Resident from registered list
function onSelectResidentQuick(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) {
    document.getElementById('reg_user_id').value = '';
    return;
  }
  document.getElementById('reg_user_id').value = opt.value;
  document.getElementById('reg_resident_name').value = opt.getAttribute('data-name') || '';
  document.getElementById('reg_phone').value = opt.getAttribute('data-phone') || '';
  updateSmsPreview();
}

// Update live SMS text preview dynamically
function updateSmsPreview() {
  if (!currentActiveEventForRegistration) return;
  const act = currentActiveEventForRegistration;
  const residentName = document.getElementById('reg_resident_name').value.trim() || '[Resident Name]';

  const startFmt = new Date(act.start_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  const endFmt = new Date(act.end_datetime.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  const facilitator = act.assigned_personnel || 'Barangay Disaster Team';

  const rawBrgy = act.barangay_name || "<?= addslashes(clean($barangay['name'] ?? '')) ?>";
  const formattedBrgy = rawBrgy ? rawBrgy.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ') : '';
  const committeeHeader = formattedBrgy 
    ? `BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE (${formattedBrgy})`
    : `BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE`;

  const preview = `${committeeHeader}\n` +
    `Hi ${residentName}!\n` +
    `You are registered for: ${act.title}\n` +
    `Venue: ${act.venue}\n` +
    `Start: ${startFmt}\n` +
    `End: ${endFmt}\n` +
    `Lead Facilitator: ${facilitator}\n` +
    `Details: ${act.description || 'Community disaster preparedness activity.'}\n` +
    `Thank you for participating!`;

  document.getElementById('reg_sms_preview_box').innerText = preview;
}

// View Attendees Roster & SMS Log Modal
function openViewAttendeesModal(act, attendees) {
  document.getElementById('view_att_modal_title').innerText = `Attendees: ${act.title}`;
  const container = document.getElementById('view_att_list_container');

  if (!attendees || attendees.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-text-muted);font-size:12px;">No attendees registered yet for this event.</div>';
  } else {
    let html = `
      <div style="font-size:11px;font-weight:600;margin-bottom:8px;color:var(--color-primary);">
        Total Registered: ${attendees.length} resident(s)
      </div>
      <table class="data-table" style="font-size:11px;width:100%;">
        <thead>
          <tr>
            <th>Resident Name</th>
            <th>Mobile Number</th>
            <th>Registration Date</th>
            <th>SMS Delivery</th>
          </tr>
        </thead>
        <tbody>
    `;
    attendees.forEach(a => {
      html += `
        <tr>
          <td style="font-weight:600;">${a.resident_name || 'Resident'}</td>
          <td style="font-family:monospace;">${a.phone}</td>
          <td style="font-size:10px;color:var(--color-text-muted);">${a.registered_at || '—'}</td>
          <td>
            <span class="badge ${a.sms_status === 'sent' || a.sms_status === 'simulated' ? 'badge-success' : 'badge-warning'}" style="font-size:9px;">
              ${a.sms_status === 'sent' || a.sms_status === 'simulated' ? 'SMS Sent ✓' : a.sms_status}
            </span>
          </td>
        </tr>
      `;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
  }

  openModal('viewAttendeesModal');
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
