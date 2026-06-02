/**
 * Mobile dashboard: hamburger menu + drawer (admin, student, superadmin).
 */
(function () {
  const body = document.body;
  const toggle = document.getElementById('dashboard-menu-toggle');
  const overlay = document.getElementById('dashboard-nav-overlay');

  function openMenu() {
    body.classList.add('dashboard-nav-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (overlay) overlay.hidden = false;
    document.documentElement.style.overflow = 'hidden';
  }

  function closeMenu() {
    body.classList.remove('dashboard-nav-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (overlay) overlay.hidden = true;
    document.documentElement.style.overflow = '';
  }

  window.closeDashboardMenu = closeMenu;

  if (toggle) {
    toggle.addEventListener('click', () => {
      if (body.classList.contains('dashboard-nav-open')) closeMenu();
      else openMenu();
    });
  }

  if (overlay) {
    overlay.addEventListener('click', closeMenu);
  }

  document.querySelectorAll('.dashboard-sidebar .nav-item[data-panel]').forEach((link) => {
    link.addEventListener('click', () => {
      if (window.matchMedia('(max-width: 768px)').matches) closeMenu();
    });
  });

  document.querySelectorAll('[data-action="open-menu"]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openMenu();
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  window.addEventListener('resize', () => {
    if (window.matchMedia('(min-width: 769px)').matches) closeMenu();
  });
})();
