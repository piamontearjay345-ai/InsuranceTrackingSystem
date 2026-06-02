/**
 * Administrator dashboard: stats, student table, logs, notifications, and superadmin user management.
 */

(function () {
  let currentPage = 1;
  let currentUser = null;
  const limit = 10;
  const editModal = document.getElementById('editModal');

  const addClickListener = (id, fn) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  };

  function showDashboardError(err, fallback = 'Request failed.') {
    if (err?.status === 401) {
      window.location.href = typeof appPath === 'function' ? appPath('login.html') : '/login.html';
      return;
    }
    showAlert(document.getElementById('alert-box'), err?.message || fallback, 'danger');
  }

  function activatePanel(panel) {
    document.querySelectorAll('.sidebar .nav-item').forEach(l => l.classList.remove('active'));
    const activeLink = document.querySelector(`.sidebar .nav-item[data-panel="${cssEscape(panel)}"]`);
    if (activeLink) activeLink.classList.add('active');
    document.querySelectorAll('[id^="panel-"]').forEach(p => p.classList.add('hidden'));
    const panelEl = document.getElementById('panel-' + panel);
    if (panelEl) panelEl.classList.remove('hidden');
    if (panel === 'students') loadStudents();
    if (panel === 'notifications') loadAdminNotifications();
    if (panel === 'failed') loadFailed();
    if (panel === 'beneficiary-requests') loadBeneficiaryRequests();
    if (panel === 'login-history') loadLoginHistory();
    if (panel === 'activity') loadActivity();
    if (panel === 'users') loadUsers();
    if (panel === 'admins') loadAdmins();
    if (panel === 'reports') loadReports();
    if (panel === 'settings') loadSettings();
  }

  document.querySelectorAll('.sidebar .nav-item[data-panel]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      activatePanel(link.dataset.panel);
    });
  });

  document.querySelectorAll('.stat-card[data-student-filter]').forEach(card => {
    const showFilteredStudents = () => {
      const statusFilter = document.getElementById('filter-status');
      const searchInput = document.getElementById('search-students');
      if (statusFilter) statusFilter.value = card.dataset.studentFilter || '';
      if (searchInput) searchInput.value = '';
      currentPage = 1;
      activatePanel('students');
    };
    card.addEventListener('click', showFilteredStudents);
    card.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        showFilteredStudents();
      }
    });
  });

  addClickListener('btn-search', () => {
    currentPage = 1;
    loadStudents();
  });

  addClickListener('btn-beneficiary-request-search', loadBeneficiaryRequests);
  addClickListener('btn-send-all-beneficiary-requests', sendAllBeneficiaryRequests);
  addClickListener('btn-user-search', loadUsers);
  addClickListener('save-edit-btn', saveEdit);
  addClickListener('modal-close', closeModal);
  addClickListener('modal-cancel', closeModal);
  addClickListener('btn-show-create-admin', () => {
    const createCard = document.getElementById('create-admin-card');
    const resetCard = document.getElementById('reset-admin-card');
    if (createCard) createCard.classList.toggle('hidden');
    if (resetCard) resetCard.classList.add('hidden');
  });
  addClickListener('btn-show-reset-password', () => {
    const createCard = document.getElementById('create-admin-card');
    const resetCard = document.getElementById('reset-admin-card');
    if (resetCard) resetCard.classList.toggle('hidden');
    if (createCard) createCard.classList.add('hidden');
  });
  addClickListener('create-admin-btn', createAdmin);
  addClickListener('reset-admin-btn', resetAdminPassword);
  addClickListener('btn-export-students', exportStudentReport);
  addClickListener('btn-print-report', printReport);
  addClickListener('save-settings-btn', saveSettings);
  if (editModal) {
    editModal.addEventListener('click', (e) => {
      if (e.target === editModal) closeModal();
    });
  }

  function openModal() { editModal.classList.add('show'); }
  function closeModal() { editModal.classList.remove('show'); }

  async function init() {
    const user = await requireAuth(window.SUPERADMIN_PAGE ? ['superadmin'] : ['admin', 'superadmin']);
    if (!user) return;
    currentUser = user;
    document.getElementById('admin-name').textContent = user.fullname;
    document.getElementById('role-badge').textContent = user.role;
    if (user.role === 'superadmin') {
      document.querySelectorAll('.superadmin-only').forEach(el => el.classList.remove('hidden'));
    }
    loadStats();
    setInterval(loadStats, 30000);
  }

  function showLoading(on) {
    document.getElementById('loading').classList.toggle('show', !!on);
  }

  async function loadStats() {
    try {
      const res = await API.get('/admin/stats');
      const d = res.data;
      // Update all dashboard stat cards if present
      const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = (value === undefined || value === null) ? '0' : String(value);
      };
      setText('stat-total', d.total_students);
      setText('stat-admins', d.total_admins ?? d.total_admin);
      setText('stat-beneficiaries', d.total_beneficiaries ?? d.total_beneficiary);
      setText('stat-updated', d.updated_records);
      setText('stat-not-updated', d.not_updated_records);
    } catch (err) {
      console.error('Failed to load stats', err);
      try { localStorage.setItem('sits_last_stats_error', String(err)); } catch (e) {}
      showDashboardError(err, 'Failed to load stats.');
    }
  }

  async function loadStudents() {
    const search = document.getElementById('search-students').value.trim();
    const status = document.getElementById('filter-status').value;
    let q = `/admin/students?page=${currentPage}&limit=${limit}`;
    if (search) q += '&search=' + encodeURIComponent(search);
    if (status) q += '&status=' + encodeURIComponent(status);

    try {
      const res = await API.get(q);
      const tbody = document.getElementById('students-tbody');
      const pagination = document.getElementById('students-pagination');
      const rows = res.data.students || [];
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--evsu-gray)">No records found.</td></tr>';
        if (pagination) pagination.innerHTML = '';
        return;
      }
      tbody.innerHTML = rows.map(row => {
        const ben = row.beneficiaries?.[0] || {};
        const st = ben.status || 'Not Updated';
        const cls = statusClass(st);
        return `<tr>
          <td>${esc(row.student_id)}</td>
          <td>${esc(row.fullname)}</td>
          <td>${esc(row.email)}</td>
          <td>${esc(ben.fullname || '-')}</td>
          <td><span class="status-badge ${cls}">${esc(st)}</span></td>
          <td>${ben.updated_at ? new Date(ben.updated_at).toLocaleDateString() : '-'}</td>
          <td><button type="button" class="btn btn-primary btn-edit" style="padding:8px 16px;font-size:13px"
            data-user-id="${esc(row.id)}"
            data-fullname="${esc(ben.fullname || '')}"
            data-relationship="${esc(ben.relationship || '')}"
            data-contact="${esc(ben.contact_number || '')}"
            data-address="${esc(ben.address || '')}">Edit</button></td>
        </tr>`;
      }).join('');

      tbody.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => openEdit(btn.dataset.userId, {
          fullname: btn.dataset.fullname,
          relationship: btn.dataset.relationship,
          contact_number: btn.dataset.contact,
          address: btn.dataset.address,
        }));
      });
      renderPagination(res.data.page);
    } catch (err) {
      showDashboardError(err, 'Failed to load students.');
    }
  }

  window.openEdit = function (userId, ben) {
    document.getElementById('edit-user-id').value = userId;
    document.getElementById('edit-fullname').value = ben.fullname || '';
    document.getElementById('edit-relationship').value = ben.relationship || '';
    document.getElementById('edit-contact').value = ben.contact_number || '';
    document.getElementById('edit-address').value = ben.address || '';
    openModal();
  };

  async function saveEdit() {
    const userId = document.getElementById('edit-user-id').value;
    try {
      await API.post('/beneficiary', {
        user_id: userId,
        fullname: document.getElementById('edit-fullname').value.trim(),
        relationship: document.getElementById('edit-relationship').value.trim(),
        contact_number: document.getElementById('edit-contact').value.trim(),
        address: document.getElementById('edit-address').value.trim(),
      });
      closeModal();
      showAlert(document.getElementById('alert-box'), 'Record updated.', 'success');
      loadStudents();
      loadStats();
    } catch (err) {
      showDashboardError(err, 'Failed to update record.');
    }
  }

  async function loadUsers() {
    if (currentUser?.role !== 'superadmin') return;
    const search = document.getElementById('search-users').value.trim();
    const role = document.getElementById('filter-user-role').value;
    let q = '/superadmin/users?limit=50';
    if (search) q += '&search=' + encodeURIComponent(search);
    if (role) q += '&role=' + encodeURIComponent(role);

    try {
      const res = await API.get(q);
      const tbody = document.getElementById('users-tbody');
      const rows = res.data.users || [];
      tbody.innerHTML = rows.map(userRow).join('') || '<tr><td colspan="6">No users found.</td></tr>';
      tbody.querySelectorAll('.btn-save-user').forEach(btn => btn.addEventListener('click', () => saveUser(btn.dataset.id)));
      tbody.querySelectorAll('.btn-toggle-user').forEach(btn => btn.addEventListener('click', () => toggleUser(btn.dataset.id, btn.dataset.deleted === 'true')));
    } catch (err) {
      showDashboardError(err, 'Failed to load users.');
    }
  }

  async function loadBeneficiaryRequests() {
    const search = document.getElementById('search-beneficiary-requests')?.value.trim() || '';
    let q = '/admin/beneficiary-update-requests';
    if (search) q += '?search=' + encodeURIComponent(search);

    try {
      const res = await API.get(q);
      const tbody = document.getElementById('beneficiary-requests-tbody');
      const rows = res.data.students || [];
      tbody.innerHTML = rows.map(student => {
        const status = student.beneficiaries?.[0]?.status || 'Not Updated';
        return `<tr>
          <td>${esc(student.student_id)}</td>
          <td>${esc(student.fullname)}</td>
          <td>${esc(student.email)}</td>
          <td><span class="status-badge ${statusClass(status)}">${esc(status)}</span></td>
          <td><button type="button" class="btn btn-primary btn-send-beneficiary-request" style="padding:8px 14px;font-size:13px" data-user-id="${esc(student.id)}">Send Notification</button></td>
        </tr>`;
      }).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--evsu-gray)">No students found.</td></tr>';

      tbody.querySelectorAll('.btn-send-beneficiary-request').forEach(btn => {
        btn.addEventListener('click', () => sendBeneficiaryRequest(btn));
      });
    } catch (err) {
      showDashboardError(err, 'Failed to load beneficiary update requests.');
    }
  }

  async function sendBeneficiaryRequest(btn) {
    setLoading(btn, true);
    try {
      const res = await API.post('/admin/beneficiary-update-request', {
        user_id: btn.dataset.userId,
      });
      const d = res.data || {};
      const detail = d.error ? ` ${d.error}` : (d.hint ? ` ${d.hint}` : '');
      showAlert(
        document.getElementById('alert-box'),
        (res.message || 'Notification processed.') + detail,
        d.status_updated ? (d.email_sent ? 'success' : 'info') : 'danger'
      );
      await Promise.all([loadBeneficiaryRequests(), loadStats()]);
    } catch (err) {
      showDashboardError(err, 'Failed to send notification.');
    } finally {
      setLoading(btn, false);
    }
  }

  async function sendAllBeneficiaryRequests() {
    const btn = document.getElementById('btn-send-all-beneficiary-requests');
    setLoading(btn, true);
    try {
      const res = await API.post('/admin/beneficiary-update-request/all', {});
      const d = res.data || {};
      showAlert(
        document.getElementById('alert-box'),
        `Processed ${d.total || 0} students. Emails sent: ${d.sent || 0}. Mail failed: ${d.mail_failed || 0}. Status failed: ${d.status_failed || 0}.`,
        (d.status_failed || 0) > 0 ? 'danger' : ((d.mail_failed || 0) > 0 ? 'info' : 'success')
      );
      await Promise.all([loadBeneficiaryRequests(), loadStats()]);
    } catch (err) {
      showDashboardError(err, 'Failed to send notifications.');
    } finally {
      setLoading(btn, false);
    }
  }

  async function loadAdmins() {
    if (currentUser?.role !== 'superadmin') return;
    let q = '/superadmin/users?role=admin&limit=50';
    try {
      const res = await API.get(q);
      const tbody = document.getElementById('admins-tbody');
      const rows = res.data.users || [];
      tbody.innerHTML = rows.map(userRow).join('') || '<tr><td colspan="6">No admin accounts found.</td></tr>';
      tbody.querySelectorAll('.btn-save-user').forEach(btn => btn.addEventListener('click', () => saveUser(btn.dataset.id)));
      tbody.querySelectorAll('.btn-toggle-user').forEach(btn => btn.addEventListener('click', () => toggleUser(btn.dataset.id, btn.dataset.deleted === 'true')));
    } catch (err) {
      showDashboardError(err, 'Failed to load admin accounts.');
    }
  }

  async function createAdmin() {
    const email = document.getElementById('admin-email')?.value.trim();
    const username = document.getElementById('admin-username')?.value.trim();
    const fullname = document.getElementById('admin-fullname')?.value.trim();
    const password = document.getElementById('admin-password')?.value;
    const role = document.getElementById('admin-role')?.value;

    if (!email || !username || !fullname || !password) {
      showAlert(document.getElementById('alert-box'), 'Please fill in all admin fields.', 'danger');
      return;
    }

    try {
      await API.post('/superadmin/user', {
        email,
        username,
        fullname,
        password,
        role,
      });
      showAlert(document.getElementById('alert-box'), 'Admin account created successfully.', 'success');
      loadAdmins();
    } catch (err) {
      showDashboardError(err, 'Failed to create admin.');
    }
  }

  async function resetAdminPassword() {
    const email = document.getElementById('reset-admin-email')?.value.trim();
    if (!email) {
      showAlert(document.getElementById('alert-box'), 'Please enter an admin email.', 'danger');
      return;
    }
    try {
      await API.post('/superadmin/user/reset', { email });
      showAlert(document.getElementById('alert-box'), 'Password reset request sent.', 'success');
    } catch (err) {
      showDashboardError(err, 'Failed to reset password.');
    }
  }

  function loadReports() {
    // No remote load required for the reports panel.
  }

  function exportCsv(filename, headers, dataRows) {
    const escapeCell = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [
      headers.map(escapeCell).join(','),
      ...dataRows.map((row) => row.map(escapeCell).join(',')),
    ];
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  async function fetchAllPaginated(path, listKey, pageSize = 100) {
    const all = [];
    let page = 1;
    for (;;) {
      const res = await API.get(`${path}${path.includes('?') ? '&' : '?'}limit=${pageSize}&page=${page}`);
      const batch = res.data[listKey] || [];
      all.push(...batch);
      if (batch.length < pageSize) break;
      page += 1;
    }
    return all;
  }

  async function exportStudentReport() {
    const btn = document.getElementById('btn-export-students');
    try {
      if (btn) btn.disabled = true;
      const students = await fetchAllPaginated('/admin/students', 'students');
      const headers = ['Student ID', 'Name', 'Email', 'Beneficiary', 'Status', 'Last Update'];
      const dataRows = students.map((s) => [
        s.student_id ?? '',
        s.fullname ?? '',
        s.email ?? '',
        s.beneficiaries?.[0]?.fullname ?? '',
        s.beneficiaries?.[0]?.status ?? 'Not Updated',
        s.beneficiaries?.[0]?.updated_at ?? '',
      ]);
      exportCsv('students-report.csv', headers, dataRows);
      showAlert(
        document.getElementById('alert-box'),
        `Exported ${students.length} student(s) to students-report.csv`,
        'success'
      );
    } catch (err) {
      showDashboardError(err, 'Failed to export students.');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function printReport() {
    window.print();
  }

  function loadSettings() {
    const values = JSON.parse(localStorage.getItem('sits_settings') || '{}');
    document.getElementById('setting-system-name').value = values.systemName || 'Insurance Tracking System';
    document.getElementById('setting-email-sender').value = values.emailSender || '';
    document.getElementById('setting-email-subject').value = values.emailSubject || '';
    document.getElementById('setting-admin-contact').value = values.adminContact || '';
  }

  function saveSettings() {
    const settings = {
      systemName: document.getElementById('setting-system-name').value.trim(),
      emailSender: document.getElementById('setting-email-sender').value.trim(),
      emailSubject: document.getElementById('setting-email-subject').value.trim(),
      adminContact: document.getElementById('setting-admin-contact').value.trim(),
    };
    localStorage.setItem('sits_settings', JSON.stringify(settings));
    showAlert(document.getElementById('alert-box'), 'Settings saved locally.', 'success');
  }

  function userRow(u) {
    const p = u.permissions || {};
    return `<tr data-user-id="${esc(u.id)}">
      <td>${esc(u.fullname)}<br><small class="muted">${esc(u.student_id || u.username)}</small></td>
      <td>${esc(u.email)}</td>
      <td>
        <select class="role-select" ${u.id === currentUser.id ? 'disabled' : ''}>
          <option value="student" ${u.role === 'student' ? 'selected' : ''}>Student</option>
          <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option>
          <option value="superadmin" ${u.role === 'superadmin' ? 'selected' : ''}>Superadmin</option>
        </select>
      </td>
      <td>
        <label class="permission-choice"><input type="checkbox" data-perm="manage_students" ${p.manage_students ? 'checked' : ''}> Student CRUD</label>
        <label class="permission-choice"><input type="checkbox" data-perm="track_insurance" ${p.track_insurance ? 'checked' : ''}> Track insurance</label>
        <label class="permission-choice"><input type="checkbox" data-perm="manage_notifications" ${p.manage_notifications ? 'checked' : ''}> Notifications</label>
        <label class="permission-choice"><input type="checkbox" data-perm="view_logs" ${p.view_logs ? 'checked' : ''}> Logs</label>
      </td>
      <td><span class="status-badge ${u.is_deleted ? 'not-updated' : 'updated'}">${u.is_deleted ? 'Disabled' : 'Active'}</span></td>
      <td class="action-stack">
        <button type="button" class="btn btn-primary btn-save-user" data-id="${esc(u.id)}">Save</button>
        <button type="button" class="btn btn-toggle-user" data-id="${esc(u.id)}" data-deleted="${u.is_deleted ? 'true' : 'false'}" ${u.id === currentUser.id ? 'disabled' : ''}>${u.is_deleted ? 'Restore' : 'Disable'}</button>
      </td>
    </tr>`;
  }

  async function saveUser(id) {
    const row = document.querySelector(`tr[data-user-id="${cssEscape(id)}"]`);
    if (!row) return;
    const permissions = {};
    row.querySelectorAll('[data-perm]').forEach(input => {
      permissions[input.dataset.perm] = input.checked;
    });
    try {
      await API.put('/superadmin/user', {
        id,
        role: row.querySelector('.role-select').value,
        permissions,
      });
      showAlert(document.getElementById('alert-box'), 'User saved.', 'success');
      loadUsers();
    } catch (err) {
      showDashboardError(err, 'Failed to save user.');
    }
  }

  async function toggleUser(id, isDeleted) {
    try {
      await API.put('/superadmin/user', { id, is_deleted: !isDeleted });
      showAlert(document.getElementById('alert-box'), isDeleted ? 'User restored.' : 'User disabled.', 'success');
      loadUsers();
    } catch (err) {
      showDashboardError(err, 'Failed to update user status.');
    }
  }

  async function loadAdminNotifications() {
    try {
      const res = await API.get('/admin/notifications');
      const tbody = document.getElementById('notifications-tbody');
      const rows = res.data.notifications || [];
      tbody.innerHTML = rows.map(n => `<tr>
        <td>${esc(n.users?.fullname || n.user_id)}</td>
        <td>${esc(n.title)}</td>
        <td>${esc(n.delivery_status)}</td>
        <td>${new Date(n.created_at).toLocaleString()}</td>
      </tr>`).join('') || '<tr><td colspan="4">No data</td></tr>';
    } catch (err) {
      showDashboardError(err, 'Failed to load notifications.');
    }
  }

  async function loadFailed() {
    try {
      const res = await API.get('/admin/failed-notifications');
      const tbody = document.getElementById('failed-tbody');
      const rows = res.data.failed || [];
      tbody.innerHTML = rows.map(f => `<tr>
        <td>${esc(f.recipient_email)}</td>
        <td>${esc(f.error_reason)}</td>
        <td>${new Date(f.created_at).toLocaleString()}</td>
        <td><button type="button" class="btn btn-primary btn-retry" style="padding:6px 12px;font-size:12px" data-id="${esc(f.id)}">Retry</button></td>
      </tr>`).join('') || '<tr><td colspan="4">No failed notifications</td></tr>';

      tbody.querySelectorAll('.btn-retry').forEach(btn => {
        btn.addEventListener('click', () => retryFailed(btn.dataset.id));
      });
    } catch (err) {
      showDashboardError(err, 'Failed to load failed notifications.');
    }
  }

  async function retryFailed(id) {
    try {
      await API.post('/admin/retry-notification', { id });
      showAlert(document.getElementById('alert-box'), 'Retry sent.', 'success');
      loadFailed();
    } catch (err) {
      showDashboardError(err, 'Failed to retry notification.');
    }
  }

  async function loadLoginHistory() {
    try {
      const res = await API.get('/admin/login-history');
      const tbody = document.getElementById('login-tbody');
      const rows = res.data.history || [];
      tbody.innerHTML = rows.map(h => `<tr>
        <td>${esc(h.email)}</td>
        <td>${esc(h.login_status)}</td>
        <td>${esc(h.role)}</td>
        <td>${esc(h.ip_address)}</td>
        <td>${new Date(h.created_at).toLocaleString()}</td>
      </tr>`).join('') || '<tr><td colspan="5">No history</td></tr>';
    } catch (err) {
      showDashboardError(err, 'Failed to load login history.');
    }
  }

  async function loadActivity() {
    try {
      const res = await API.get('/admin/activity-logs');
      const tbody = document.getElementById('activity-tbody');
      const rows = res.data.logs || [];
      tbody.innerHTML = rows.map(l => `<tr>
        <td>${esc(l.action)}</td>
        <td>${esc(l.affected_record)}</td>
        <td>${esc(l.severity_level)}</td>
        <td>${esc(l.ip_address)}</td>
        <td>${new Date(l.created_at).toLocaleString()}</td>
      </tr>`).join('') || '<tr><td colspan="5">No logs</td></tr>';
    } catch (err) {
      showDashboardError(err, 'Failed to load activity logs.');
    }
  }

  function renderPagination(page) {
    const ul = document.getElementById('students-pagination');
    ul.innerHTML = '';
    for (let i = 1; i <= Math.max(page, page + 1); i++) {
      if (i > page + 2) break;
      const li = document.createElement('li');
      if (i === page) li.className = 'active';
      li.innerHTML = `<a href="#">${i}</a>`;
      li.querySelector('a').addEventListener('click', (e) => {
        e.preventDefault();
        currentPage = i;
        loadStudents();
      });
      ul.appendChild(li);
    }
  }

  function cssEscape(s) {
    return String(s).replace(/"/g, '\\"');
  }

  function statusClass(status) {
    return status === 'Updated' ? 'updated' : 'not-updated';
  }

  function esc(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  init();
})();
