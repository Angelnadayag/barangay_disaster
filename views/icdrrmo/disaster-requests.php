<?php
// ============================================================================
// Views (ICDRRMO): Disaster Requests Management Module
// Full lifecycle: Review -> Run Decision Tree Recommender -> Allocation
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('icdrrmo');
$user = getCurrentUser();
$role = $user['role'];
$db = getDBConnection();

$pageTitle = "Disaster Requests Management";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Filter parameters
$filterBarangay = $_GET['barangay'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterSeverity = $_GET['severity'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Base query
$sql = "
    SELECT dr.*, b.name AS barangay_name, u.full_name AS submitted_by_name,
           r.id AS recommendation_id, r.status AS recommendation_status, r.confidence_score
    FROM disaster_requests dr
    JOIN barangays b ON dr.barangay_id = b.id
    JOIN users u ON dr.submitted_by = u.id
    LEFT JOIN recommendations r ON dr.id = r.disaster_request_id
    WHERE 1=1
";
$params = [];

if (!empty($filterBarangay)) {
    $sql .= " AND dr.barangay_id = ?";
    $params[] = $filterBarangay;
}

if (!empty($filterType)) {
    $sql .= " AND dr.disaster_type = ?";
    $params[] = $filterType;
}

if (!empty($filterSeverity)) {
    $sql .= " AND dr.severity = ?";
    $params[] = $filterSeverity;
}

if (!empty($filterStatus)) {
    $sql .= " AND dr.status = ?";
    $params[] = $filterStatus;
}

if (!empty($search)) {
    $sql .= " AND (dr.tracking_code LIKE ? OR dr.purok_name LIKE ? OR dr.requested_assistance LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY dr.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Fetch barangays for filter dropdown
$allBarangays = $db->query("SELECT id, name FROM barangays ORDER BY name ASC")->fetchAll();
?>

<main class="main-content">
  <!-- Workflow Visual Breadcrumb -->
  <div class="decision-pipeline">
    <div class="pipeline-step">
      <span class="pipeline-step-badge">Phase 1</span>
      <span class="pipeline-step-title">Submit Request</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-step">
      <span class="pipeline-step-badge">Phase 2</span>
      <span class="pipeline-step-title">ICDRRMO Review</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-step">
      <span class="pipeline-step-badge">Phase 3</span>
      <span class="pipeline-step-title">Decision Tree Analysis</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-step">
      <span class="pipeline-step-badge">Phase 4</span>
      <span class="pipeline-step-title">Human Review & Approval</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-step">
      <span class="pipeline-step-badge">Phase 5</span>
      <span class="pipeline-step-title">Resource Allocation</span>
    </div>
  </div>

  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Disaster Assistance Requests</h1>
      <p class="page-header-desc">Record, track, and validate disaster incidents requiring emergency relief and Decision Tree-based resource allocation.</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openModal('createRequestModal')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        New Disaster Request
      </button>
    </div>
  </div>

  <!-- Filters & Search Bar -->
  <div class="card" style="margin-bottom: var(--space-4);">
    <div class="card-body" style="padding: 10px 14px;">
      <form method="GET" class="filter-bar" style="margin-bottom:0;">
        <div class="filter-group">
          <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" name="search" class="form-control" placeholder="Search tracking # or purok..." value="<?= clean($search) ?>">
          </div>

          <select name="barangay" class="form-control" style="width: 140px;">
            <option value="">All Barangays</option>
            <?php foreach ($allBarangays as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $filterBarangay == $b['id'] ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="type" class="form-control" style="width: 120px;">
            <option value="">All Disasters</option>
            <option value="Flood" <?= $filterType === 'Flood' ? 'selected' : '' ?>>Flood</option>
            <option value="Typhoon" <?= $filterType === 'Typhoon' ? 'selected' : '' ?>>Typhoon</option>
            <option value="Flash Flood" <?= $filterType === 'Flash Flood' ? 'selected' : '' ?>>Flash Flood</option>
            <option value="Landslide" <?= $filterType === 'Landslide' ? 'selected' : '' ?>>Landslide</option>
            <option value="Earthquake" <?= $filterType === 'Earthquake' ? 'selected' : '' ?>>Earthquake</option>
            <option value="Fire" <?= $filterType === 'Fire' ? 'selected' : '' ?>>Fire</option>
            <option value="Storm Surge" <?= $filterType === 'Storm Surge' ? 'selected' : '' ?>>Storm Surge</option>
          </select>

          <select name="severity" class="form-control" style="width: 110px;">
            <option value="">All Severities</option>
            <option value="Low" <?= $filterSeverity === 'Low' ? 'selected' : '' ?>>Low</option>
            <option value="Moderate" <?= $filterSeverity === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
            <option value="High" <?= $filterSeverity === 'High' ? 'selected' : '' ?>>High</option>
            <option value="Critical" <?= $filterSeverity === 'Critical' ? 'selected' : '' ?>>Critical</option>
          </select>

          <select name="status" class="form-control" style="width: 130px;">
            <option value="">All Statuses</option>
            <option value="Submitted" <?= $filterStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="Recommendation Ready" <?= $filterStatus === 'Recommendation Ready' ? 'selected' : '' ?>>Recommendation Ready</option>
            <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Allocated" <?= $filterStatus === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
            <option value="Dispatched" <?= $filterStatus === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
          </select>

          <button type="submit" class="btn btn-outline">Filter</button>
          <?php if (!empty($search) || !empty($filterBarangay) || !empty($filterType) || !empty($filterSeverity) || !empty($filterStatus)): ?>
            <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php" class="btn btn-outline" style="color:var(--color-text-muted);">Reset</a>
          <?php endif; ?>
        </div>
        <div>
          <span style="font-size:10px;color:var(--color-text-muted);font-weight:500;">
            Showing <?= count($requests) ?> Request(s)
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Requests Table -->
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Tracking #</th>
          <th>Barangay & Purok</th>
          <th>Disaster Type</th>
          <th>Severity</th>
          <th>Urgency</th>
          <th>Affected Census</th>
          <th>Status</th>
          <th>Decision Support</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="9" style="text-align:center;padding:36px;">
              <div class="empty-state">
                <div class="empty-state-title">No Disaster Requests Found</div>
                <div class="empty-state-desc">There are currently no disaster requests matching your selected filter criteria.</div>
                <a href="<?= BASE_URL ?>/views/icdrrmo/disaster-requests.php" class="btn btn-outline btn-sm">Clear Filters</a>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td style="font-weight:600;font-family:var(--font-secondary);">
                <span style="color:var(--color-primary);"><?= clean($r['tracking_code']) ?></span>
                <div style="font-size:8px;color:var(--color-text-muted);"><?= formatDate($r['created_at'], 'M d, Y') ?></div>
              </td>
              <td>
                <div style="font-weight:600;color:var(--color-primary);"><?= clean($r['barangay_name']) ?></div>
                <div style="font-size:9px;color:var(--color-text-secondary);"><?= clean($r['purok_name']) ?></div>
              </td>
              <td>
                <span style="font-weight:500;"><?= clean($r['disaster_type']) ?></span>
              </td>
              <td><?= renderStatusBadge($r['severity']) ?></td>
              <td><?= renderStatusBadge($r['urgency']) ?></td>
              <td style="font-family:var(--font-secondary);">
                <div><strong><?= number_format($r['affected_families']) ?></strong> Families</div>
                <div style="font-size:9px;color:var(--color-danger);"><?= number_format($r['displaced_families']) ?> Displaced</div>
              </td>
              <td><?= renderStatusBadge($r['status']) ?></td>
              <td>
                <?php if (!empty($r['recommendation_id'])): ?>
                  <a href="<?= BASE_URL ?>/views/icdrrmo/recommendations.php?id=<?= $r['recommendation_id'] ?>" class="btn btn-outline btn-sm" style="color:var(--color-secondary);border-color:var(--color-secondary);">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    Rec (<?= number_format($r['confidence_score'], 0) ?>%)
                  </a>
                <?php else: ?>
                  <button class="btn btn-secondary btn-sm" onclick="triggerRecommender(<?= $r['id'] ?>, '<?= clean($r['tracking_code']) ?>')">
                    Run Recommender
                  </button>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn btn-outline btn-sm" onclick="viewRequestDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
                  Details
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- Modal: Create Disaster Request -->
<div class="modal-overlay" id="createRequestModal">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <h3 class="modal-title">Submit Disaster Assistance Request</h3>
      <button class="modal-close-btn" onclick="closeModal('createRequestModal')">&times;</button>
    </div>
    <form id="createRequestForm" method="POST" action="<?= BASE_URL ?>/backend/functions/disaster_requests/create.php">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div class="modal-body">
        <div style="background:#F0F4F8;border:1px solid #D2DFEB;border-radius:10px;padding:8px 12px;margin-bottom:12px;font-size:9px;color:var(--color-text-secondary);">
          <strong>Disaster Preparedness Protocol:</strong> All submitted figures directly feed into the Decision Tree Recommender for immediate supply allocation and validation.
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Target Barangay</label>
            <select name="barangay_id" id="reqBarangaySelect" class="form-control" required>
              <option value="">Select Barangay</option>
              <?php foreach ($allBarangays as $b): ?>
                <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label form-label-required">Purok / Specific Location</label>
            <input type="text" name="purok_name" id="reqPurokInput" class="form-control" placeholder="e.g. Purok Riverside 1" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Disaster Incident Type</label>
            <select name="disaster_type" class="form-control" required>
              <option value="Flood">Flood</option>
              <option value="Flash Flood">Flash Flood</option>
              <option value="Typhoon">Typhoon</option>
              <option value="Storm Surge">Storm Surge</option>
              <option value="Landslide">Landslide</option>
              <option value="Earthquake">Earthquake</option>
              <option value="Fire">Fire</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label form-label-required">Incident Severity</label>
            <select name="severity" class="form-control" required>
              <option value="Critical">Critical (Severe Damage / Life Threat)</option>
              <option value="High" selected>High (Significant Displacement)</option>
              <option value="Moderate">Moderate (Localized Impact)</option>
              <option value="Low">Low (Pre-emptive Standby)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label form-label-required">Urgency Level</label>
            <select name="urgency" class="form-control" required>
              <option value="Immediate">Immediate (Within 1-3 Hours)</option>
              <option value="High" selected>High (Within 6-12 Hours)</option>
              <option value="Medium">Medium (Within 24 Hours)</option>
              <option value="Low">Low (Precautionary)</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label form-label-required">Affected Families Count</label>
            <input type="number" name="affected_families" class="form-control" min="1" value="50" required>
          </div>
          <div class="form-group">
            <label class="form-label">Displaced Families (In Evacuation)</label>
            <input type="number" name="displaced_families" class="form-control" min="0" value="20">
          </div>
          <div class="form-group">
            <label class="form-label">Total Affected Individuals</label>
            <input type="number" name="affected_individuals" class="form-control" min="1" value="220">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Reported Casualties</label>
            <input type="number" name="casualties_count" class="form-control" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Injuries Count</label>
            <input type="number" name="injuries_count" class="form-control" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Missing Persons</label>
            <input type="number" name="missing_count" class="form-control" min="0" value="0">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required">Specific Assistance & Supplies Requested</label>
          <textarea name="requested_assistance" class="form-control" rows="2" placeholder="e.g. 50 Family Food Packs, 20 Hygiene Kits, Drinking water bottles, first aid medical supplies" required></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Situation Narrative / Ground Assessment</label>
          <textarea name="situation_overview" class="form-control" rows="2" placeholder="Describe water levels, road access condition, evacuation center status, electricity state..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createRequestModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitRequest">Submit to ICDRRMO</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Request Detail View -->
<div class="modal-overlay" id="viewRequestModal">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <h3 class="modal-title" id="viewReqTitle">Disaster Request Details</h3>
      <button class="modal-close-btn" onclick="closeModal('viewRequestModal')">&times;</button>
    </div>
    <div class="modal-body" id="viewReqBody">
      <!-- Populated via JS -->
    </div>
    <div class="modal-footer" id="viewReqFooter">
      <button type="button" class="btn btn-outline" onclick="closeModal('viewRequestModal')">Close</button>
    </div>
  </div>
</div>

<script>
async function handleRequestSubmit(e) {
  e.preventDefault();
  const form = document.getElementById('createRequestForm');
  const btn = document.getElementById('btnSubmitRequest');
  btn.disabled = true;
  btn.innerText = 'Submitting...';

  try {
    const formData = new FormData(form);
    const res = await fetch(`${BASE_URL}/backend/functions/disaster_requests/create.php`, {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      showToast(data.message, 'success');
      closeModal('createRequestModal');
      setTimeout(() => window.location.reload(), 800);
    } else {
      showToast(data.message || 'Submission failed.', 'danger');
    }
  } catch (err) {
    showToast('Network error during request submission.', 'danger');
  } finally {
    btn.disabled = false;
    btn.innerText = 'Submit to ICDRRMO';
  }
}

async function triggerRecommender(requestId, trackingCode) {
  showConfirmModal(
    'Run Decision Tree Recommender',
    `Execute Decision Tree resource allocation analysis for Request ${trackingCode}? This evaluates disaster conditions, displacement, and warehouse stock sufficiency.`,
    async () => {
      showToast('Evaluating Decision Tree model...', 'info', 2000);
      const fd = new FormData();
      fd.append('request_id', requestId);

      const res = await fetch(`${BASE_URL}/backend/functions/disaster_requests/run_recommender.php`, {
        method: 'POST',
        body: fd
      });
      const data = await res.json();
      if (data.success) {
        showToast(`Recommendation generated! Confidence: ${data.confidence}%`, 'success');
        setTimeout(() => {
          window.location.href = `${BASE_URL}/views/icdrrmo/recommendations.php?id=${data.recommendation_id}`;
        }, 1000);
      } else {
        showToast(data.message || 'Recommender failed.', 'danger');
      }
    }
  );
}

function viewRequestDetails(req) {
  document.getElementById('viewReqTitle').innerText = `Request ${req.tracking_code} — ${req.disaster_type}`;
  const body = document.getElementById('viewReqBody');
  body.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
      <div style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:10px;border:1px solid var(--color-border-light);">
        <div style="font-size:9px;color:var(--color-text-muted);font-weight:700;text-transform:uppercase;">Location & Origin</div>
        <div style="font-size:12px;font-weight:700;color:var(--color-primary);margin-top:2px;">Barangay ${escapeHtml(req.barangay_name)}</div>
        <div style="font-size:10px;color:var(--color-text-secondary);">${escapeHtml(req.purok_name)}</div>
        <div style="font-size:9px;color:var(--color-text-muted);margin-top:4px;">Submitted by: ${escapeHtml(req.submitted_by_name)} on ${escapeHtml(req.created_at)}</div>
      </div>
      <div style="background:var(--color-surface-subtle);padding:10px 12px;border-radius:10px;border:1px solid var(--color-border-light);">
        <div style="font-size:9px;color:var(--color-text-muted);font-weight:700;text-transform:uppercase;">Disaster Severity & Urgency</div>
        <div style="display:flex;gap:6px;margin-top:4px;">
          <span class="badge badge-danger">${escapeHtml(req.severity)} Severity</span>
          <span class="badge badge-warning">${escapeHtml(req.urgency)} Urgency</span>
          <span class="badge badge-info">${escapeHtml(req.status)}</span>
        </div>
      </div>
    </div>

    <div style="background:#FFF;border:1px solid var(--color-border);border-radius:10px;padding:10px 14px;margin-bottom:12px;">
      <div style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:6px;">Population & Impact Assessment</div>
      <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:8px;text-align:center;">
        <div style="padding:6px;background:var(--color-surface-subtle);border-radius:6px;">
          <div style="font-size:8px;color:var(--color-text-secondary);">Affected Families</div>
          <div style="font-size:14px;font-weight:700;color:var(--color-primary);">${req.affected_families}</div>
        </div>
        <div style="padding:6px;background:var(--color-danger-bg);border-radius:6px;">
          <div style="font-size:8px;color:var(--color-danger);">Displaced</div>
          <div style="font-size:14px;font-weight:700;color:var(--color-danger);">${req.displaced_families}</div>
        </div>
        <div style="padding:6px;background:var(--color-surface-subtle);border-radius:6px;">
          <div style="font-size:8px;color:var(--color-text-secondary);">Individuals</div>
          <div style="font-size:14px;font-weight:700;color:var(--color-primary);">${req.affected_individuals}</div>
        </div>
        <div style="padding:6px;background:var(--color-surface-subtle);border-radius:6px;">
          <div style="font-size:8px;color:var(--color-text-secondary);">Injuries</div>
          <div style="font-size:14px;font-weight:700;color:var(--color-primary);">${req.injuries_count}</div>
        </div>
        <div style="padding:6px;background:var(--color-surface-subtle);border-radius:6px;">
          <div style="font-size:8px;color:var(--color-text-secondary);">Casualties</div>
          <div style="font-size:14px;font-weight:700;color:var(--color-primary);">${req.casualties_count}</div>
        </div>
      </div>
    </div>

    <div style="margin-bottom:12px;">
      <label class="form-label" style="font-weight:700;">Requested Assistance</label>
      <div style="font-size:10px;background:var(--color-surface-subtle);padding:8px 12px;border-radius:10px;border:1px solid var(--color-border-light);">
        ${escapeHtml(req.requested_assistance)}
      </div>
    </div>

    <div>
      <label class="form-label" style="font-weight:700;">Ground Situation Overview</label>
      <div style="font-size:10px;background:var(--color-surface-subtle);padding:8px 12px;border-radius:10px;border:1px solid var(--color-border-light);">
        ${escapeHtml(req.situation_overview || 'No additional narrative provided.')}
      </div>
    </div>
  `;

  const footer = document.getElementById('viewReqFooter');
  if (req.recommendation_id) {
    footer.innerHTML = `
      <button type="button" class="btn btn-outline" onclick="closeModal('viewRequestModal')">Close</button>
      <a href="${BASE_URL}/views/icdrrmo/recommendations.php?id=${req.recommendation_id}" class="btn btn-primary">
        View Recommendation #${req.recommendation_id}
      </a>
    `;
  } else {
    footer.innerHTML = `
      <button type="button" class="btn btn-outline" onclick="closeModal('viewRequestModal')">Close</button>
      <button type="button" class="btn btn-secondary" onclick="closeModal('viewRequestModal'); triggerRecommender(${req.id}, '${req.tracking_code}')">
        Run Decision Tree Recommender
      </button>
    `;
  }

  openModal('viewRequestModal');
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
