const setupAdminUi = () => {
  const body = document.body;
  const openButton = document.querySelector('[data-admin-nav-open]');
  const closeButtons = document.querySelectorAll('[data-admin-nav-close]');

  const setNavigationOpen = (open) => {
    body.classList.toggle('admin-nav-open', open);
    openButton?.setAttribute('aria-expanded', String(open));
  };

  openButton?.addEventListener('click', () => setNavigationOpen(true));
  closeButtons.forEach((button) => button.addEventListener('click', () => setNavigationOpen(false)));
  document.querySelectorAll('.admin-nav-link').forEach((link) => {
    link.addEventListener('click', () => setNavigationOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setNavigationOpen(false);
  });

  const passwordToggle = document.querySelector('[data-password-toggle]');
  const passwordInput = document.querySelector('#admin-password');
  passwordToggle?.addEventListener('click', () => {
    const showPassword = passwordInput.type === 'password';
    passwordInput.type = showPassword ? 'text' : 'password';
    passwordToggle.setAttribute('aria-label', showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    passwordToggle.classList.toggle('is-visible', showPassword);
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupAdminUi, { once: true });
} else {
  setupAdminUi();
}
