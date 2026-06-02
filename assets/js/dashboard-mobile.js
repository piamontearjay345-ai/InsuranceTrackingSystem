/**
 * Dashboard hamburger menu + drawer (student, admin, superadmin — all screen sizes).
 */
(function () {
  const body = document.body;
  const toggle = document.getElementById('dashboard-menu-toggle');
  const overlay = document.getElementById('dashboard-nav-overlay');

  const sidebar = document.getElementById('dashboard-sidebar');

  function openMenu() {
    body.classList.add('dashboard-nav-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (overlay) {
      overlay.hidden = false;
      overlay.removeAttribute('hidden');
    }
    if (sidebar) sidebar.setAttribute('aria-hidden', 'false');
    document.documentElement.style.overflow = 'hidden';
    body.style.overflow = 'hidden';
  }

  function closeMenu() {
    body.classList.remove('dashboard-nav-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (overlay) {
      overlay.hidden = true;
      overlay.setAttribute('hidden', '');
    }
    if (sidebar) sidebar.setAttribute('aria-hidden', 'true');
    document.documentElement.style.overflow = '';
    body.style.overflow = '';
  }

  window.closeDashboardMenu = closeMenu;

  if (sidebar) sidebar.setAttribute('aria-hidden', 'true');

  if (toggle) {
    toggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (body.classList.contains('dashboard-nav-open')) closeMenu();
      else openMenu();
    });
  }

  if (overlay) {
    overlay.addEventListener('click', closeMenu);
  }

  document.querySelectorAll('.dashboard-sidebar .nav-item[data-panel]').forEach((link) => {
    link.addEventListener('click', () => closeMenu());
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
})();
