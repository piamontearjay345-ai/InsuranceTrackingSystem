/**
 * Student dashboard — same layout/navigation pattern as admin dashboard.
 */

(function () {
  const alertBox = document.getElementById('alert-box');
  const loading = document.getElementById('loading');

  function showDashboardError(err, fallback = 'Request failed.') {
    if (err?.status === 401) {
      window.location.href = typeof appPath === 'function' ? appPath('login.html') : '/login.html';
      return;
    }
    showAlert(alertBox, err?.message || fallback, 'danger');
  }

  function activatePanel(panel) {
    document.querySelectorAll('.sidebar .nav-item').forEach((l) => l.classList.remove('active'));
    const activeLink = document.querySelector(`.sidebar .nav-item[data-panel="${cssEscape(panel)}"]`);
    if (activeLink) activeLink.classList.add('active');

    document.querySelectorAll('[id^="panel-"]').forEach((p) => p.classList.add('hidden'));
    const panelEl = document.getElementById('panel-' + panel);
    if (panelEl) panelEl.classList.remove('hidden');

    if (panel === 'overview') {
      loadBeneficiary();
      loadNotifications();
    }
    if (panel === 'beneficiary') loadBeneficiary();
    if (panel === 'notifications') loadNotifications();
  }

  document.querySelectorAll('.sidebar .nav-item[data-panel]').forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      activatePanel(link.dataset.panel);
    });
  });

  async function init() {
    const user = await requireAuth('student');
    if (!user) return;

    const nameEl = document.getElementById('student-name');
    if (nameEl) nameEl.textContent = user.fullname;

    await Promise.all([loadBeneficiary(), loadNotifications()]).catch(() => {});

    setInterval(() => {
      loadBeneficiary();
      loadNotifications();
    }, 60000);
  }

  async function loadBeneficiary() {
    const statusEl = document.getElementById('insurance-status');
    if (!statusEl) return;

    try {
      const res = await API.get('/beneficiary');
      const b = res.data.beneficiary;
      const form = document.getElementById('beneficiary-form');

      if (b && form) {
        document.getElementById('ben-fullname').value = b.fullname || '';
        document.getElementById('ben-relationship').value = b.relationship || '';
        document.getElementById('ben-contact').value = b.contact_number || '';
        document.getElementById('ben-address').value = b.address || '';
        statusEl.textContent = b.status || 'Not Updated';
        statusEl.style.color = b.status === 'Updated' ? '#2e7d32' : 'var(--evsu-dark-red)';
        const lastEl = document.getElementById('last-updated');
        if (lastEl) {
          lastEl.textContent = b.updated_at
            ? new Date(b.updated_at).toLocaleDateString()
            : '—';
        }
      } else {
        statusEl.textContent = 'Not Updated';
        statusEl.style.color = 'var(--evsu-dark-red)';
        const lastEl = document.getElementById('last-updated');
        if (lastEl) lastEl.textContent = '—';
      }
    } catch (err) {
      showDashboardError(err, 'Failed to load beneficiary information.');
    }
  }

  async function loadNotifications() {
    const tbody = document.getElementById('notifications-tbody');
    if (!tbody) return;

    try {
      const res = await API.get('/notifications');
      const rows = res.data.notifications || [];
      const countEl = document.getElementById('notify-count');
      if (countEl) countEl.textContent = String(rows.length);

      tbody.innerHTML = rows.map((n) => `<tr>
        <td>${esc(n.title)}</td>
        <td>${esc(n.message)}</td>
        <td>${esc(n.delivery_status)}</td>
        <td>${new Date(n.created_at).toLocaleString()}</td>
      </tr>`).join('') || '<tr><td colspan="4">No notifications yet.</td></tr>';
    } catch (err) {
      showDashboardError(err, 'Failed to load notifications.');
      tbody.innerHTML = '<tr><td colspan="4">Could not load notifications.</td></tr>';
    }
  }

  const form = document.getElementById('beneficiary-form');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('save-ben-btn');
      setLoading(btn, true);
      try {
        const res = await API.post('/beneficiary', {
          fullname: document.getElementById('ben-fullname').value.trim(),
          relationship: document.getElementById('ben-relationship').value.trim(),
          contact_number: document.getElementById('ben-contact').value.trim(),
          address: document.getElementById('ben-address').value.trim(),
        });
        showAlert(alertBox, res.message || 'Saved successfully.', 'success');
        await loadBeneficiary();
      } catch (err) {
        showDashboardError(err, 'Failed to save beneficiary information.');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  function cssEscape(s) {
    if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(String(s));
    return String(s).replace(/["\\]/g, '\\$&');
  }

  if (loading) {
    loading.classList.remove('show');
  }

  init();
})();
