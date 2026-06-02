/**
 * Authentication helpers for login, register, and dashboards.
 */

function appBase() {
  const pathname = window.location.pathname;
  return pathname.includes('/InsuranceTrackingSystem') ? '/InsuranceTrackingSystem' : '';
}

function appPath(subpath) {
  const base = appBase();
  const p = String(subpath || '').replace(/^\//, '');
  return base ? `${base}/${p}` : `/${p}`;
}

function showAlert(container, message, type = 'danger') {
  if (!container) return;
  const cls = type === 'success' ? 'alert-success' : (type === 'info' ? 'alert-info' : 'alert-danger');
  container.innerHTML = `<div class="alert ${cls}" role="alert">${message}</div>`;
}

function setLoading(btn, loading) {
  if (!btn) return;
  btn.disabled = loading;
  btn.classList.toggle('is-loading', loading);
  btn.setAttribute('aria-busy', loading ? 'true' : 'false');
}

async function requireAuth(role) {
  try {
    const res = await API.get('/auth/me');
    const user = res.data.user;
    const allowed = Array.isArray(role) ? role : (role ? [role] : []);
    if (allowed.length && !allowed.includes(user.role)) {
      const target =
        user.role === 'superadmin' || user.role === 'super_admin'
          ? appPath('superadmin/dashboard.html')
          : user.role === 'admin'
            ? appPath('admin/dashboard.html')
            : appPath('student/dashboard.html');
      window.location.href = target;
      return null;
    }
    return user;
  } catch {
    window.location.href = appPath('login.html');
    return null;
  }
}

async function logout() {
  try {
    if (!API.hasCsrf()) await API.init();
    await API.post('/auth/logout', {});
  } catch (_) { /* ignore */ }
  window.location.href = appPath('login.html');
}
