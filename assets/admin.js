import './stimulus_bootstrap.js';
import { setupVisitors } from './admin_visitors.js';

document.addEventListener('turbo:load', setupVisitors);
window.addEventListener('pageshow', (event) => {
  if (event.persisted) setupVisitors();
});

document.addEventListener('click', async (event) => {
  const button = event.target.closest?.('[data-prospect-copy]');
  if (!button || button.disabled) return;
  const content = document.getElementById(button.dataset.prospectCopy);
  if (!content) return;
  const text = content.textContent.trim();
  const label = button.querySelector('[data-copy-label]');
  button.disabled = true;
  let copied = false;

  if (navigator.clipboard?.write && typeof ClipboardItem !== 'undefined') {
    try {
      await navigator.clipboard.write([new ClipboardItem({
        'text/plain': new Blob([text], { type: 'text/plain' }),
        'text/html': new Blob([`<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6">${content.innerHTML.trim().replace(/\n/g, '<br>')}</div>`], { type: 'text/html' }),
      })]);
      copied = true;
    } catch {}
  }
  if (!copied && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      copied = true;
    } catch {}
  }
  if (!copied) {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
    document.body.append(field);
    field.select();
    try { copied = document.execCommand('copy'); } catch {}
    field.remove();
    button.focus({ preventScroll: true });
  }

  label.textContent = copied ? 'Copié ✓' : 'Copie impossible';
  button.classList.toggle('is-copied', copied);
  window.setTimeout(() => {
    label.textContent = 'Copier';
    button.classList.remove('is-copied');
    button.disabled = false;
  }, 2000);
});

const setupAdminUi = () => {
  setupVisitors();
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

  document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  }, true);
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupAdminUi, { once: true });
} else {
  setupAdminUi();
}
