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

// Mobile Drawer
function initMobileMenu() {
  const toggleBtn = document.getElementById('mobileMenuToggle');
  const sidebar = document.getElementById('appSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (toggleBtn && sidebar && backdrop) {
    toggleBtn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('active');
    });

    backdrop.addEventListener('click', () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('active');
    });
  }
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
          const targetPage = (data.role === 'barangay_head') ? 'residents.php' : ((data.role === 'icdrrmo') ? 'users.php' : 'dashboard.php');
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
    else if (module === 'preparedness') window.location.href = `${BASE_URL}/views/${folder}/preparedness.php`;
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
