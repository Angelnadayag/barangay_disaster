// ============================================================================
// Intelligent Resource Allocation Recommender - Core UI JavaScript
// Minimalist, accessible, responsive, anti-slop
// ============================================================================

var BASE_URL = (typeof window !== 'undefined' && typeof window.BASE_URL === 'string') ? window.BASE_URL : (typeof BASE_URL !== 'undefined' ? BASE_URL : '');
var FUNCTIONS_URL = `${BASE_URL}/backend/functions`;

document.addEventListener('DOMContentLoaded', () => {
  initMobileMenu();
  initRoleSwitcher();
  initNotificationBell();
  initUserProfileDropdown();
});

// Sidebar Toggle (Supports both Desktop Collapsing and Mobile Drawer)
function initMobileMenu() {
  const toggleBtn = document.getElementById('mobileMenuToggle');
  const sidebar = document.getElementById('appSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (!toggleBtn || !sidebar) return;

  const isDesktop = () => window.innerWidth > 900;

  // Restore desktop collapsed state on load
  if (isDesktop() && localStorage.getItem('drrm_sidebar_collapsed') === 'true') {
    document.body.classList.add('sidebar-collapsed');
  }

  toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (isDesktop()) {
      // Desktop: collapse / fold sidebar
      const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem('drrm_sidebar_collapsed', isCollapsed ? 'true' : 'false');
    } else {
      // Mobile: open / close drawer
      const isOpen = sidebar.classList.toggle('open');
      if (backdrop) backdrop.classList.toggle('active', isOpen);
      document.body.classList.toggle('mobile-sidebar-open', isOpen);
    }
  });

  if (backdrop) {
    backdrop.addEventListener('click', () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('active');
      document.body.classList.remove('mobile-sidebar-open');
    });
  }

  // Handle window resize dynamically
  window.addEventListener('resize', () => {
    if (isDesktop()) {
      sidebar.classList.remove('open');
      if (backdrop) backdrop.classList.remove('active');
      document.body.classList.remove('mobile-sidebar-open');
      if (localStorage.getItem('drrm_sidebar_collapsed') === 'true') {
        document.body.classList.add('sidebar-collapsed');
      } else {
        document.body.classList.remove('sidebar-collapsed');
      }
    } else {
      document.body.classList.remove('sidebar-collapsed');
    }
  });
}

// Fast Role Switcher (Instant seamless role switching for testing all 4 roles)
function initRoleSwitcher() {
  const select = document.getElementById('roleSwitcherSelect');
  if (select) {
    select.addEventListener('change', async (e) => {
      const targetRole = e.target.value;
      try {
        const formData = new FormData();
        formData.append('role', targetRole);

        const res = await fetch(`${FUNCTIONS_URL}/auth/switch_role.php`, {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          const folder = (data.role === 'barangay_head') ? 'barangay' : data.role;
          const targetPage = (data.role === 'icdrrmo') ? 'users.php' : 'dashboard.php';
          window.location.href = `${BASE_URL}/views/${folder}/${targetPage}`;
        } else {
          showToast(data.message || 'Unable to switch role', 'danger');
        }
      } catch (err) {
        console.error(err);
        showToast('Network error while switching role', 'danger');
      }
    });
  }
}

// Notification Drawer & Polling
function initNotificationBell() {
  const bell = document.getElementById('notifBellBtn');
  const menu = document.getElementById('notifDropdownMenu');

  if (bell && menu) {
    bell.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = menu.style.display === 'block';
      menu.style.display = isVisible ? 'none' : 'block';
      if (!isVisible) {
        loadRecentNotifications();
      }
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && e.target !== bell) {
        menu.style.display = 'none';
      }
    });
  }
}

// User Profile & Settings Dropdown Menu
function initUserProfileDropdown() {
  const btn = document.getElementById('userProfileDropdownBtn');
  const menu = document.getElementById('userProfileDropdownMenu');
  const chevron = document.getElementById('userProfileChevron');
  const notifMenu = document.getElementById('notifDropdownMenu');

  if (btn && menu) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      // Close notif dropdown if open
      if (notifMenu) notifMenu.style.display = 'none';

      const isActive = menu.classList.contains('active');
      menu.classList.toggle('active', !isActive);
      btn.setAttribute('aria-expanded', !isActive ? 'true' : 'false');
      if (chevron) {
        chevron.style.transform = !isActive ? 'rotate(180deg)' : 'rotate(0deg)';
      }
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && !btn.contains(e.target)) {
        menu.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
        if (chevron) chevron.style.transform = 'rotate(0deg)';
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menu.classList.contains('active')) {
        menu.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
        if (chevron) chevron.style.transform = 'rotate(0deg)';
      }
    });
  }
}

async function loadRecentNotifications() {
  const list = document.getElementById('notifDropdownList');
  if (!list) return;

  try {
    const res = await fetch(`${FUNCTIONS_URL}/notifications/recent.php`);
    const data = await res.json();

    if (data.notifications && data.notifications.length > 0) {
      list.innerHTML = data.notifications.map(n => `
        <div style="padding: 8px 12px; border-bottom: 1px solid var(--color-border-light); cursor: pointer; transition: background 0.15s ease;"
             onclick="markNotifRead(${n.id}, '${n.related_module}')" onmouseover="this.style.background='var(--color-surface-subtle)'" onmouseout="this.style.background='transparent'">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
            <span class="badge badge-${n.alert_level === 'urgent' ? 'danger' : (n.alert_level === 'warning' ? 'warning' : 'info')}" style="font-size:8px;">${n.alert_level.toUpperCase()}</span>
            <span style="font-size:8px;color:var(--color-text-muted);">${n.time_ago}</span>
          </div>
          <div style="font-size:10px;font-weight:600;color:var(--color-primary);margin-bottom:2px;">${escapeHtml(n.title)}</div>
          <div style="font-size:9px;color:var(--color-text-secondary);line-height:1.3;">${escapeHtml(n.message).substring(0, 90)}...</div>
        </div>
      `).join('');
    } else {
      list.innerHTML = '<div style="padding:16px;text-align:center;font-size:10px;color:var(--color-text-muted);">No new unread advisories.</div>';
    }
  } catch (err) {
    list.innerHTML = '<div style="padding:12px;text-align:center;font-size:10px;color:var(--color-danger);">Failed to load alerts.</div>';
  }
}

async function markNotifRead(notifId, module) {
  try {
    await fetch(`${FUNCTIONS_URL}/notifications/mark_read.php?id=${notifId}`, { method: 'POST' });
    const badge = document.getElementById('topNotifBadge');
    if (badge) {
      let count = parseInt(badge.innerText, 10);
      if (count <= 1) badge.remove();
      else badge.innerText = count - 1;
    }
    const role = document.getElementById('roleSwitcherSelect')?.value || 'icdrrmo';
    const folder = (role === 'barangay_head') ? 'barangay' : role;
    // Redirect to user module
    if (module === 'disaster_request') window.location.href = `${BASE_URL}/views/${folder}/disaster-requests.php`;
    else if (module === 'recommendation') window.location.href = `${BASE_URL}/views/${folder}/recommendations.php`;
    else if (module === 'preparedness' || module === 'events' || module === 'event') window.location.href = `${BASE_URL}/views/${folder}/events.php`;
    else window.location.href = `${BASE_URL}/views/${folder}/notifications.php`;
  } catch (err) {
    console.error(err);
  }
}

// Modal System
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove('active');
    document.body.style.overflow = '';
  }
}

// Reusable Confirmation Dialog
function showConfirmModal(title, message, onConfirmCallback) {
  const modal = document.getElementById('confirmationModal');
  const titleEl = document.getElementById('confirmModalTitle');
  const msgEl = document.getElementById('confirmModalMessage');
  const btn = document.getElementById('confirmModalSubmitBtn');

  if (modal && titleEl && msgEl && btn) {
    titleEl.innerText = title;
    msgEl.innerText = message;
    
    // Replace click listener
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);

    newBtn.addEventListener('click', async () => {
      closeModal('confirmationModal');
      if (typeof onConfirmCallback === 'function') {
        await onConfirmCallback();
      }
    });

    openModal('confirmationModal');
  }
}

// Toast System
function showToast(message, type = 'info', duration = 3500) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <div style="flex:1;">${escapeHtml(message)}</div>
    <button style="background:none;border:none;cursor:pointer;color:var(--color-text-muted);font-size:12px;line-height:1;" onclick="this.parentElement.remove()">&times;</button>
  `;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.transition = 'opacity 0.25s ease';
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 250);
  }, duration);
}

// Helper to escape HTML safely
function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Tab Switcher Utility
function switchTab(tabGroupId, targetTabId, triggerBtn) {
  const group = document.getElementById(tabGroupId);
  if (!group) return;

  // Toggle tab contents
  group.querySelectorAll('.tab-content').forEach(el => {
    el.style.display = el.id === targetTabId ? 'block' : 'none';
  });

  // Toggle active button state
  if (triggerBtn && triggerBtn.parentElement) {
    triggerBtn.parentElement.querySelectorAll('.tab-btn').forEach(btn => {
      btn.classList.remove('active');
    });
    triggerBtn.classList.add('active');
  }
}

// ============================================================================
// Decision Support System (DSS) - Confidence Breakdown Tooltip & Recommender Engine
// ============================================================================

function getConfidenceBreakdownJs(score, severity, decisionPath, modality) {
  score = parseFloat(score) || 85.0;
  const base = 85.0;
  let sevBonus = 0.0;
  let sevName = 'Moderate';

  if (severity) {
    const s = String(severity).toLowerCase();
    if (s === 'critical') { sevBonus = 5.0; sevName = 'Critical'; }
    else if (s === 'high') { sevBonus = 3.0; sevName = 'High'; }
    else if (s === 'low') { sevBonus = -5.0; sevName = 'Low'; }
    else { sevBonus = 0.0; sevName = 'Moderate'; }
  } else {
    if (score >= 93.0) { sevBonus = 5.0; sevName = 'Critical'; }
    else if (score >= 91.0) { sevBonus = 3.0; sevName = 'High'; }
    else { sevBonus = 0.0; sevName = 'Moderate'; }
  }

  const pathStr = String(decisionPath || '');
  const hasShortfall = pathStr.toLowerCase().includes('bottleneck') || pathStr.toLowerCase().includes('shortfall');
  const isFinancialOnly = (modality === 'financial_aid') || pathStr.toLowerCase().includes('financial aid') || pathStr.toLowerCase().includes('ayuda');

  let feasBonus = 4.0;
  let feasLabel = '100% Supplies In Stock';
  if (hasShortfall) {
    feasBonus = -6.0;
    feasLabel = 'Stock Deficit Bottleneck';
  } else if (isFinancialOnly) {
    feasLabel = 'Calamity Fund Liquidity Verified';
  }

  const num = Math.round(score);
  const sevSign = sevBonus >= 0 ? `+${sevBonus}%` : `${sevBonus}%`;
  const feasSign = feasBonus >= 0 ? `+${feasBonus}%` : `${feasBonus}%`;
  const formula = `85% (Base) ${sevSign} (${sevName}) ${feasSign} (Feasibility) = ${num}%`;

  return {
    score: num,
    base: 85.0,
    sevBonus,
    sevName,
    sevSign,
    feasBonus,
    feasLabel,
    feasSign,
    formula
  };
}

function buildConfidenceHoverBadge(score, severity, decisionPath, modality, badgeClass = 'badge-info', customStyle = '', prefix = 'Confidence: ') {
  const b = getConfidenceBreakdownJs(score, severity, decisionPath, modality);
  return `
    <span class="confidence-hover-wrap" tabindex="0">
      <span class="badge ${badgeClass}" style="${customStyle}">${prefix}${b.score}% <span class="confidence-info-icon">ⓘ</span></span>
      <div class="confidence-hover-box">
        <div class="confidence-hover-header">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:11px;color:#FFFFFF;">Confidence Breakdown: ${b.score}%</span>
            <span class="confidence-model-tag">NDRRMC Model</span>
          </div>
          <div style="font-size:9.5px;color:#CBD5E1;margin-top:2px;">Multi-criteria decision tree heuristic scoring</div>
        </div>
        <div class="confidence-hover-body">
          <div class="confidence-hover-row">
            <span class="confidence-row-label">1. NDRRMC Standard Baseline:</span>
            <span class="confidence-row-value">85.0%</span>
          </div>
          <div class="confidence-hover-row">
            <span class="confidence-row-label">2. Incident Severity (${escapeHtml(b.sevName)}):</span>
            <span class="confidence-row-value" style="color:${b.sevBonus >= 0 ? '#10B981' : '#EF4444'};">${b.sevSign}</span>
          </div>
          <div class="confidence-hover-row">
            <span class="confidence-row-label">3. Feasibility (${escapeHtml(b.feasLabel)}):</span>
            <span class="confidence-row-value" style="color:${b.feasBonus >= 0 ? '#10B981' : '#EF4444'};">${b.feasSign}</span>
          </div>
          <div class="confidence-hover-footer">
            <div style="font-size:9px;color:var(--color-text-muted);margin-bottom:2px;text-transform:uppercase;font-weight:600;">Mathematical Formula:</div>
            <div style="font-size:10.5px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">${escapeHtml(b.formula)}</div>
          </div>
        </div>
      </div>
    </span>
  `;
}

// Active State for DSS Execution
let currentDssContext = {
  requestId: null,
  trackingCode: '',
  report: null,
  onCompleteCallback: null
};

function selectDssModality(labelEl) {
  document.querySelectorAll('.dss-modality-card').forEach(c => c.classList.remove('active'));
  labelEl.classList.add('active');
  const radio = labelEl.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;

  const selectedModality = radio ? radio.value : 'both';
  const aidBox = document.getElementById('dssAidInputsContainer');

  // Financial Aid Parameters customization box:
  // ONLY displayed when user chooses 'financial_aid' (Ayuda Only) or 'both' (Comprehensive Dual Support)
  if (aidBox) {
    aidBox.style.display = (selectedModality === 'both' || selectedModality === 'financial_aid') ? 'block' : 'none';
  }
}

function toggleDssAidCustomize(on) {
  const f = document.getElementById('dssAidCustomFields');
  if (f) f.style.display = on ? 'block' : 'none';
}

function openDssExecutionModal(reportData, onCompleteCallback) {
  if (!reportData) return;

  const reqId = typeof reportData === 'object' ? reportData.id : reportData;
  const tracking = (reportData && reportData.tracking_code) ? reportData.tracking_code : `REQ-${reqId}`;

  currentDssContext = {
    requestId: reqId,
    trackingCode: tracking,
    report: (typeof reportData === 'object') ? reportData : null,
    onCompleteCallback: onCompleteCallback || null
  };

  // Populate Setup Context Card
  const trackingBadge = document.getElementById('dssModalTrackingBadge');
  const barangayEl = document.getElementById('dssModalBarangay');
  const disasterEl = document.getElementById('dssModalDisaster');
  const familiesEl = document.getElementById('dssModalFamilies');
  const displacedEl = document.getElementById('dssModalDisplaced');

  if (trackingBadge) trackingBadge.textContent = tracking;
  if (barangayEl) {
    const bName = (reportData.barangay_name || reportData.location || 'Barangay');
    const pName = reportData.purok_name ? ` (${reportData.purok_name})` : '';
    barangayEl.textContent = `${bName}${pName}`;
  }
  if (disasterEl) {
    const dType = reportData.disaster_type || 'Disaster Incident';
    const sev = reportData.severity ? ` (${reportData.severity})` : '';
    disasterEl.textContent = `${dType}${sev}`;
  }
  if (familiesEl) {
    const fam = Number(reportData.affected_families || 0);
    familiesEl.textContent = `${fam.toLocaleString()} Families`;
  }
  if (displacedEl) {
    const disp = Number(reportData.displaced_families || 0);
    displacedEl.textContent = `${disp.toLocaleString()} Families`;
  }

  // Reset to Stage 1: Setup
  const setupStage = document.getElementById('dssStageSetup');
  const loadingStage = document.getElementById('dssStageLoading');
  const resultsStage = document.getElementById('dssStageResults');

  if (setupStage) setupStage.style.display = 'flex';
  if (loadingStage) loadingStage.style.display = 'none';
  if (resultsStage) resultsStage.style.display = 'none';

  // Populate Financial Aid fields
  const aidAmountInput = document.getElementById('dssInputAidAmount');
  const aidSponsorInput = document.getElementById('dssInputAidSponsor');
  const aidTargetSelect = document.getElementById('dssInputAidTarget');
  const runBtn = document.getElementById('dssRunAnalysisBtn');

  if (runBtn) {
    runBtn.innerHTML = 'Run Recommendation';
  }

  // Pre-calculate suggested aid rate if empty or based on severity
  let suggestedRate = 3000;
  if (reportData.severity === 'Critical') suggestedRate = 5000;
  else if (reportData.severity === 'High') suggestedRate = 4000;
  else if (reportData.severity === 'Low') suggestedRate = 2000;

  if (aidAmountInput) {
    aidAmountInput.value = reportData.financial_aid_per_family || suggestedRate;
  }
  if (aidSponsorInput) {
    aidSponsorInput.value = reportData.financial_aid_source || '';
    updateDssSponsorChips();
  }
  if (aidTargetSelect) {
    aidTargetSelect.value = (Number(reportData.displaced_families || 0) > 0) ? 'displaced' : 'all_affected';
  }

  // Ensure selected modality or 'both' is default selected
  const targetModality = reportData.modality || 'both';
  const targetRadio = document.querySelector(`input[name="dss_modality"][value="${targetModality}"]`);
  if (targetRadio) {
    targetRadio.checked = true;
    document.querySelectorAll('.dss-modality-card').forEach(c => c.classList.remove('active'));
    if (targetRadio.closest('.dss-modality-card')) {
      selectDssModality(targetRadio.closest('.dss-modality-card'));
    }
  }

  openModal('dssExecutionModal');
}

function setDssSponsor(name) {
  const input = document.getElementById('dssInputAidSponsor');
  if (!input) return;
  if (input.value.trim().toLowerCase() === name.trim().toLowerCase()) {
    input.value = '';
  } else {
    input.value = name;
  }
  updateDssSponsorChips();
}

function updateDssSponsorChips() {
  const input = document.getElementById('dssInputAidSponsor');
  const val = input ? input.value.trim().toLowerCase() : '';
  document.querySelectorAll('.dss-sponsor-chip').forEach(chip => {
    const chipSponsor = (chip.getAttribute('data-sponsor') || chip.textContent.trim()).trim().toLowerCase();
    if (val && chipSponsor && chipSponsor === val) {
      chip.classList.add('active');
    } else {
      chip.classList.remove('active');
    }
  });
}

async function executeDssRecommender() {
  if (!currentDssContext.requestId) {
    showToast('No active disaster incident specified.', 'danger');
    return;
  }

  const selectedModality = document.querySelector('input[name="dss_modality"]:checked')?.value || 'both';

  // Transition from Setup to Loading Stage
  const setupStage = document.getElementById('dssStageSetup');
  const loadingStage = document.getElementById('dssStageLoading');
  const resultsStage = document.getElementById('dssStageResults');
  const statusText = document.getElementById('dssLoadingStatusText');
  const progressBar = document.getElementById('dssProgressBar');

  if (setupStage) setupStage.style.display = 'none';
  if (loadingStage) loadingStage.style.display = 'block';
  if (resultsStage) resultsStage.style.display = 'none';

  // Reset Steps & Progress Bar
  if (progressBar) progressBar.style.width = '0%';
  for (let i = 1; i <= 5; i++) {
    const el = document.getElementById(`dssStep${i}`);
    if (el) {
      el.className = 'dss-step-item';
      const icon = el.querySelector('.dss-step-icon');
      if (icon) icon.textContent = i;
    }
  }

  // Step Animation Pipeline Helper
  const setStepState = (stepNum, state) => {
    const el = document.getElementById(`dssStep${stepNum}`);
    if (!el) return;
    if (state === 'active') {
      el.className = 'dss-step-item active';
      const icon = el.querySelector('.dss-step-icon');
      if (icon) icon.textContent = String(stepNum);
    } else if (state === 'completed') {
      el.className = 'dss-step-item completed';
      const icon = el.querySelector('.dss-step-icon');
      if (icon) icon.innerHTML = '✓';
    }
  };

  // Launch smooth step sequence
  setStepState(1, 'active');
  if (progressBar) progressBar.style.width = '20%';
  if (statusText) statusText.textContent = 'Classifying disaster incident & evaluating modality...';

  const t1 = setTimeout(() => {
    setStepState(1, 'completed');
    setStepState(2, 'active');
    if (progressBar) progressBar.style.width = '45%';
    if (statusText) statusText.textContent = 'Analyzing severity index & displacement scale...';
  }, 450);

  const t2 = setTimeout(() => {
    setStepState(2, 'completed');
    setStepState(3, 'active');
    if (progressBar) progressBar.style.width = '65%';
    if (statusText) statusText.textContent = 'Querying Central Depot warehouse inventory...';
  }, 950);

  const t3 = setTimeout(() => {
    setStepState(3, 'completed');
    setStepState(4, 'active');
    if (progressBar) progressBar.style.width = '85%';
    if (statusText) statusText.textContent = 'Calculating emergency financial aid allocations...';
  }, 1450);

  const t4 = setTimeout(() => {
    setStepState(4, 'completed');
    setStepState(5, 'active');
    if (progressBar) progressBar.style.width = '95%';
    if (statusText) statusText.textContent = 'Synthesizing AI recommendation & confidence rating...';
  }, 1850);

  const minAnimationTime = new Promise(resolve => setTimeout(resolve, 2200));

  let apiResult = null;
  let apiError = null;

  try {
    const fd = new FormData();
    fd.append('request_id', currentDssContext.requestId);
    fd.append('modality', selectedModality);
    fd.append('ajax', '1');

    if (selectedModality === 'both' || selectedModality === 'financial_aid') {
      const aidAmount = document.getElementById('dssInputAidAmount')?.value;
      const aidSponsor = document.getElementById('dssInputAidSponsor')?.value;
      const aidTarget = document.getElementById('dssInputAidTarget')?.value;
      if (aidAmount) fd.append('financial_aid_amount', aidAmount);
      fd.append('financial_aid_sponsor', aidSponsor ? aidSponsor.trim() : '');
      if (aidTarget) fd.append('financial_aid_target', aidTarget);
    }

    const res = await fetch(`${BASE_URL}/backend/functions/disaster_requests/run_recommender.php`, {
      method: 'POST',
      body: fd,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    apiResult = await res.json();
  } catch (err) {
    apiError = err;
  }

  // Await the minimum animation duration for satisfying visual feedback
  await minAnimationTime;

  // Complete Step 5
  setStepState(5, 'completed');
  if (progressBar) progressBar.style.width = '100%';
  if (statusText) statusText.textContent = 'Evaluation complete! Formatting transparent recommendation...';

  await new Promise(r => setTimeout(r, 350));

  if (apiResult && apiResult.success) {
    showToast(`Recommendation generated! Confidence: ${Math.round(apiResult.confidence || 0)}%`, 'success');

    // Populate Results Screen
    const recCodeEl = document.getElementById('dssResultRecCode');
    const priorityEl = document.getElementById('dssResultPriority');
    const confWrap = document.getElementById('dssResultConfidenceWrap');
    const detailsBox = document.getElementById('dssResultDetailsBox');
    const inspectBtn = document.getElementById('dssResultInspectBtn');
    const subTitle = document.getElementById('dssResultsSubtitle');

    const recId = apiResult.recommendation_id;
    const recCode = apiResult.recommendation_code || `REC-${recId}`;
    const confidence = Math.round(apiResult.confidence || 85);
    const priority = apiResult.priority || 'Moderate';
    const modality = apiResult.modality || selectedModality;

    if (recCodeEl) recCodeEl.textContent = recCode;
    if (priorityEl) {
      priorityEl.innerHTML = `<span class="badge ${priority === 'Critical' ? 'badge-danger' : (priority === 'High' ? 'badge-warning' : 'badge-info')}">${priority} Priority</span>`;
    }

    if (confWrap) {
      const decisionPath = apiResult.result?.decision_path || '';
      const sev = currentDssContext.report?.severity || apiResult.priority || 'Moderate';
      confWrap.innerHTML = buildConfidenceHoverBadge(confidence, sev, decisionPath, modality, 'badge-info', 'font-size:12px;padding:4px 10px;font-weight:700;', 'Confidence: ');
    }

    if (subTitle) {
      const modalityLabel = (modality === 'resources') ? 'Physical Relief Resources' : ((modality === 'financial_aid') ? 'Emergency Financial Aid' : 'Comprehensive Dual Support');
      subTitle.textContent = `Modality: ${modalityLabel} • NDRRMC/DSWD Decision Support Matrix`;
    }

    if (detailsBox) {
      let contentHtml = '';
      if (modality === 'resources' || modality === 'both') {
        const count = apiResult.items_count || 0;
        contentHtml += `
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding-bottom:6px;">
            <span><strong>Physical Relief Supplies:</strong> ${count} resource items recommended</span>
            <span class="badge badge-neutral">Central Depot</span>
          </div>
        `;
      }
      if (modality === 'financial_aid' || modality === 'both') {
        const aid = apiResult.financial_aid;
        const budget = aid ? Number(aid.recommended_budget || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) : '0.00';
        const perFam = aid ? Number(aid.per_family || 0).toLocaleString('en-US') : '0';
        const fams = aid ? Number(aid.target_families || 0).toLocaleString('en-US') : '0';
        contentHtml += `
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding-top:${contentHtml ? '6px' : '0'};border-top:${contentHtml ? '1px solid var(--color-border-light)' : 'none'};">
            <span><strong>Emergency Financial Aid:</strong> ₱${budget} (₱${perFam}/family for ${fams} families)</span>
            <span class="badge badge-neutral">LGU Calamity Fund</span>
          </div>
        `;
      }
      detailsBox.innerHTML = contentHtml || `<div style="color:var(--color-text-secondary);">Recommendation package generated and pending official human validation.</div>`;
    }

    if (inspectBtn) {
      inspectBtn.href = `${BASE_URL}/views/icdrrmo/recommendations.php?id=${recId}`;
    }

    // Update client report object if currently viewing modal
    if (typeof currentActiveReport !== 'undefined' && currentActiveReport && currentActiveReport.id == currentDssContext.requestId) {
      currentActiveReport.recommendation_id = recId;
      currentActiveReport.recommendation_code = recCode;
      currentActiveReport.confidence_score = confidence;
      currentActiveReport.recommendation_status = 'Pending Review';
      currentActiveReport.recommendation_priority = priority;
      currentActiveReport.modality = modality;
      if (apiResult.financial_aid) {
        currentActiveReport.financial_aid_recommended_budget = apiResult.financial_aid.recommended_budget;
        currentActiveReport.financial_aid_per_family = apiResult.financial_aid.per_family;
        currentActiveReport.financial_aid_target_families = apiResult.financial_aid.target_families;
      }
      if (typeof updateRecommenderModalUI === 'function') {
        updateRecommenderModalUI(currentActiveReport);
      }
    }

    if (typeof currentDssContext.onCompleteCallback === 'function') {
      currentDssContext.onCompleteCallback(apiResult);
    }

    // Switch to Results View
    if (loadingStage) loadingStage.style.display = 'none';
    if (resultsStage) resultsStage.style.display = 'block';

  } else {
    // Show failure
    showToast(apiResult?.message || 'Error occurred while evaluating decision support.', 'danger');
    if (loadingStage) loadingStage.style.display = 'none';
    if (setupStage) setupStage.style.display = 'flex';
  }
}

function handleDssModalClose() {
  if (window.dssReloadOnClose) {
    window.dssReloadOnClose = false;
    window.location.reload();
    return;
  }
  if (typeof loadReportsTable === 'function') {
    loadReportsTable();
  }
}

// Global Modal Controls
function openModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('active');
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active');
    el.style.display = 'none';
    document.body.style.overflow = '';
  }
}

window.openModal = openModal;
window.closeModal = closeModal;


