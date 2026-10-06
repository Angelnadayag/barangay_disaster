<?php
// ============================================================================
// Views (ICDRRMO): Preparedness Activities & Interactive Calendar Module
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Preparedness Activities & Drills";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$viewMode = $_GET['view'] ?? 'list'; // 'month', 'list'
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = "
    SELECT pa.*, b.name AS barangay_name, u.full_name AS creator_name 
    FROM preparedness_activities pa 
    LEFT JOIN barangays b ON pa.barangay_id = b.id 
    JOIN users u ON pa.created_by = u.id 
    WHERE 1=1
";
$params = [];

if (!empty($filterType)) {
    $sql .= " AND pa.activity_type = ?";
    $params[] = $filterType;
}

if (!empty($filterStatus)) {
    $sql .= " AND pa.status = ?";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY pa.start_datetime ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$barangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Barangay Disaster Preparedness Activities</h1>
      <p class="page-header-desc">
        Schedule, track, and evaluate disaster drills, first responder simulations, community seminars, and family preparedness campaigns.
      </p>
    </div>
    <div class="page-header-actions">
      <div style="display:flex;background:var(--color-surface);border:1px solid var(--color-border);border-radius:10px;padding:2px;gap:2px;">
        <a href="<?= BASE_URL ?>/views/icdrrmo/preparedness.php?view=list" class="btn btn-sm <?= $viewMode === 'list' ? 'btn-primary' : 'btn-outline' ?>" style="border:none;">List View</a>
        <a href="<?= BASE_URL ?>/views/icdrrmo/preparedness.php?view=month" class="btn btn-sm <?= $viewMode === 'month' ? 'btn-primary' : 'btn-outline' ?>" style="border:none;">Month View</a>
      </div>

      <button class="btn btn-primary" onclick="openModal('createActivityModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Schedule Activity
      </button>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding:10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <input type="hidden" name="view" value="<?= clean($viewMode) ?>">
        <div class="filter-group">
          <select name="type" class="form-control" style="width:180px;">
            <option value="">All Activity Types</option>
            <option value="Disaster Drill" <?= $filterType === 'Disaster Drill' ? 'selected' : '' ?>>Disaster Drill</option>
            <option value="First Aid / Rescuer Training" <?= $filterType === 'First Aid / Rescuer Training' ? 'selected' : '' ?>>First Aid / Rescuer Training</option>
            <option value="Community Seminar" <?= $filterType === 'Community Seminar' ? 'selected' : '' ?>>Community Seminar</option>
            <option value="IEC Campaign" <?= $filterType === 'IEC Campaign' ? 'selected' : '' ?>>IEC Campaign</option>
            <option value="Contingency Planning" <?= $filterType === 'Contingency Planning' ? 'selected' : '' ?>>Contingency Planning</option>
          </select>

          <select name="status" class="form-control" style="width:130px;">
            <option value="">All Statuses</option>
            <option value="Scheduled" <?= $filterStatus === 'Scheduled' ? 'selected' : '' ?>>Scheduled</option>
            <option value="Ongoing" <?= $filterStatus === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>

          <button type="submit" class="btn btn-outline">Filter</button>
          <?php if (!empty($filterType) || !empty($filterStatus)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/preparedness.php?view=<?= clean($viewMode) ?>" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($viewMode === 'month'): ?>
    <!-- Month Calendar View -->
    <div class="card">
      <div class="card-header" style="background:var(--color-surface-subtle);display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">October 2026 Preparedness Schedule</h3>
        <span style="font-size:10px;color:var(--color-text-muted);">Iligan City DRRM Calendar</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(7, 1fr);background:var(--color-border);gap:1px;border-bottom:1px solid var(--color-border);">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
          <div style="background:var(--color-surface-subtle);padding:6px;text-align:center;font-weight:700;font-size:9px;color:var(--color-text-secondary);text-transform:uppercase;">
            <?= $day ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(7, 1fr);background:var(--color-border);gap:1px;">
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span style="font-size:9px;color:var(--color-text-muted);">27</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span style="font-size:9px;color:var(--color-text-muted);">28</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span style="font-size:9px;color:var(--color-text-muted);">29</span></div>
        <div style="background:#FFF;min-height:85px;padding:4px;opacity:0.4;"><span style="font-size:9px;color:var(--color-text-muted);">30</span></div>

        <?php for ($d = 1; $d <= 31; $d++): ?>
          <?php
            $dateStr = sprintf('2026-10-%02d', $d);
            $dayActs = array_filter($activities, function($a) use ($dateStr) {
              return strpos($a['start_datetime'], $dateStr) === 0;
            });
          ?>
          <div style="background:#FFF;min-height:85px;padding:4px;display:flex;flex-direction:column;justify-content:space-between;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10px;font-weight:<?= count($dayActs) > 0 ? '700' : '400' ?>;color:<?= count($dayActs) > 0 ? 'var(--color-primary)' : 'var(--color-text-muted)' ?>;"><?= $d ?></span>
              <?php if (count($dayActs) > 0): ?>
                <span class="badge badge-info" style="font-size:7px;padding:1px 3px;"><?= count($dayActs) ?></span>
              <?php endif; ?>
            </div>
            <div style="margin-top:2px;display:flex;flex-direction:column;gap:2px;">
              <?php foreach ($dayActs as $da): ?>
                <div style="background:#EBF3FC;border-left:3px solid var(--color-secondary);padding:2px 4px;border-radius:3px;font-size:8px;line-height:1.2;cursor:pointer;"
                     onclick="viewActivityDetails(<?= htmlspecialchars(json_encode($da)) ?>)">
                  <strong style="color:var(--color-primary);"><?= clean(substr($da['title'], 0, 22)) ?>...</strong>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

  <?php else: ?>
    <!-- List View -->
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Schedule Date & Time</th>
            <th>Activity Title</th>
            <th>Activity Type</th>
            <th>Target Barangay & Venue</th>
            <th>Facilitator / Team</th>
            <th>Attendance Ratio</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($activities)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;">No preparedness activities scheduled.</td></tr>
          <?php else: ?>
            <?php foreach ($activities as $act): ?>
              <tr>
                <td style="font-family:var(--font-secondary);font-size:10px;white-space:nowrap;">
                  <div style="font-weight:600;color:var(--color-primary);"><?= formatDate($act['start_datetime'], 'M d, Y') ?></div>
                  <div style="font-size:8px;color:var(--color-text-muted);"><?= formatDate($act['start_datetime'], 'h:i A') ?> - <?= formatDate($act['end_datetime'], 'h:i A') ?></div>
                </td>
                <td>
                  <div style="font-weight:600;color:var(--color-primary);"><?= clean($act['title']) ?></div>
                  <div style="font-size:9px;color:var(--color-text-secondary);max-width:280px;"><?= clean(substr($act['description'], 0, 80)) ?>...</div>
                </td>
                <td><span class="badge badge-info"><?= clean($act['activity_type']) ?></span></td>
                <td>
                  <div style="font-weight:600;"><?= clean($act['barangay_name'] ?: 'City-Wide') ?></div>
                  <div style="font-size:9px;color:var(--color-text-muted);"><?= clean($act['venue']) ?></div>
                </td>
                <td style="font-size:10px;color:var(--color-text-secondary);"><?= clean($act['assigned_personnel']) ?></td>
                <td style="font-family:var(--font-secondary);">
                  <?php if ($act['status'] === 'Completed'): ?>
                    <span style="font-weight:700;color:var(--color-success);"><?= $act['actual_participants'] ?> / <?= $act['target_participants'] ?></span>
                  <?php else: ?>
                    <span style="color:var(--color-text-muted);">Target: <?= $act['target_participants'] ?></span>
                  <?php endif; ?>
                </td>
                <td><?= renderStatusBadge($act['status']) ?></td>
                <td>
                  <div style="display:flex;gap:4px;">
                    <button class="btn btn-outline btn-sm" onclick="viewActivityDetails(<?= htmlspecialchars(json_encode($act)) ?>)">
                      Details
                    </button>
                    <?php if ($act['status'] !== 'Completed'): ?>
                      <button class="btn btn-secondary btn-sm" onclick="openEvaluateModal(<?= htmlspecialchars(json_encode($act)) ?>)">
                        Evaluate
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<!-- Modal: Schedule Activity -->
<div class="modal-overlay" id="createActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Schedule Preparedness Activity / Drill</h3>
      <button class="modal-close-btn" onclick="closeModal('createActivityModal')">&times;</button>
    </div>
    <form id="createActivityForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label form-label-required">Activity Title</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Q4 City-Wide Flood Evacuation Simulation Drill" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Activity Type</label>
            <select name="activity_type" class="form-control" required>
              <option value="Disaster Drill">Disaster Drill</option>
              <option value="First Aid / Rescuer Training">First Aid / Rescuer Training</option>
              <option value="Community Seminar">Community Seminar</option>
              <option value="IEC Campaign">IEC Campaign</option>
              <option value="Contingency Planning">Contingency Planning</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Target Barangay</label>
            <select name="barangay_id" class="form-control">
              <option value="">City-Wide (All Barangays)</option>
              <?php foreach ($barangays as $b): ?>
                <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Venue / Exact Location</label>
          <input type="text" name="venue" class="form-control" placeholder="e.g. Hinaplanon Riverside & High School Gym" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Start Date & Time</label>
            <input type="datetime-local" name="start_datetime" class="form-control" required value="2026-10-15T08:00">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">End Date & Time</label>
            <input type="datetime-local" name="end_datetime" class="form-control" required value="2026-10-15T12:00">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Lead Facilitator / Team</label>
            <input type="text" name="assigned_personnel" class="form-control" placeholder="e.g. ICDRRMO Training Division" required>
          </div>
          <div class="form-group">
            <label class="form-label form-label-required">Target Participants</label>
            <input type="number" name="target_participants" class="form-control" min="10" value="100" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description & Objectives</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Drill scenarios, safety briefings, targeted community sectors..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createActivityModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Schedule Activity</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: View Activity Details -->
<div class="modal-overlay" id="viewActivityModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title" id="viewActTitle">Activity Details</h3>
      <button class="modal-close-btn" onclick="closeModal('viewActivityModal')">&times;</button>
    </div>
    <div class="modal-body" id="viewActBody">
      <!-- Populated via JS -->
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('viewActivityModal')">Close</button>
    </div>
  </div>
</div>

<!-- Modal: Evaluate Activity -->
<div class="modal-overlay" id="evaluateModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Activity Accomplishment Evaluation</h3>
      <button class="modal-close-btn" onclick="closeModal('evaluateModal')">&times;</button>
    </div>
    <form id="evaluateForm" method="POST" action="<?= BASE_URL ?>/backend/functions/activities/evaluate.php">
      <input type="hidden" name="action" value="evaluate">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="activity_id" id="evalActId">
      <div class="modal-body">
        <div style="font-weight:700;font-size:11px;color:var(--color-primary);margin-bottom:8px;" id="evalActName"></div>

        <div class="form-group">
          <label class="form-label form-label-required">Activity Status</label>
          <select name="status" class="form-control" required>
            <option value="Completed">Completed (Successfully Conducted)</option>
            <option value="Ongoing">Ongoing (Currently in Progress)</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Actual Participants Attended</label>
          <input type="number" name="actual_participants" id="evalActualInput" class="form-control" min="0" required>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Evaluation Summary & Accomplishment Report</label>
          <textarea name="evaluation_summary" class="form-control" rows="3" placeholder="Summary of drill execution, community turnout, gaps observed, recommendations..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('evaluateModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Accomplishment Report</button>
      </div>
    </form>
  </div>
</div>

<script>
function viewActivityDetails(act) {
  document.getElementById('viewActTitle').innerText = act.title;
  const body = document.getElementById('viewActBody');
  body.innerHTML = `
    <div style="margin-bottom:10px;">
      <span class="badge badge-info">${escapeHtml(act.activity_type)}</span>
      <span class="badge badge-${act.status === 'Completed' ? 'success' : 'warning'}">${escapeHtml(act.status)}</span>
    </div>
    <div style="font-size:10px;line-height:1.5;color:var(--color-text);">
      <div style="margin-bottom:6px;"><strong>Schedule:</strong> ${escapeHtml(act.start_datetime)} to ${escapeHtml(act.end_datetime)}</div>
      <div style="margin-bottom:6px;"><strong>Venue:</strong> ${escapeHtml(act.venue)} (Brgy: ${escapeHtml(act.barangay_name || 'City-Wide')})</div>
      <div style="margin-bottom:6px;"><strong>Facilitator:</strong> ${escapeHtml(act.assigned_personnel)}</div>
      <div style="margin-bottom:6px;"><strong>Participants:</strong> ${act.actual_participants} Attended (Target: ${act.target_participants})</div>
      <div style="margin-top:10px;padding:8px;background:var(--color-surface-subtle);border-radius:6px;">
        <strong>Description:</strong><br>${escapeHtml(act.description || 'No description.')}
      </div>
      ${act.evaluation_summary ? `
        <div style="margin-top:10px;padding:8px;background:#EAF4EB;border-left:3px solid var(--color-success);border-radius:4px;">
          <strong>Accomplishment Evaluation:</strong><br>${escapeHtml(act.evaluation_summary)}
        </div>
      ` : ''}
    </div>
  `;
  openModal('viewActivityModal');
}

function openEvaluateModal(act) {
  document.getElementById('evalActId').value = act.id;
  document.getElementById('evalActName').innerText = act.title;
  document.getElementById('evalActualInput').value = act.target_participants;
  openModal('evaluateModal');
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
