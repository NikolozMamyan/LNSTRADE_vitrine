const storageKey = 'lns-newsletter-test-v1';
let memory = null;

const state = () => {
  if (memory) return memory;
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    memory = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    if (!['invite', 'control'].includes(memory.variant)) delete memory.variant;
  } catch { memory = {}; }
  return memory;
};

const save = () => {
  try { sessionStorage.setItem(storageKey, JSON.stringify(state())); } catch {}
};

export const newsletterExperimentData = () => {
  const visit = state();
  return visit.variant ? { newsletter_experiment: 'newsletter_v1', newsletter_variant: visit.variant } : {};
};

const track = (name) => {
  try { window.gtag?.('event', name, { ...newsletterExperimentData(), page_type: document.body.dataset.route }); } catch {}
};

export const recordNewsletterLead = () => {
  const visit = state();
  visit.dismissed = true;
  if (visit.variant && !visit.lead) {
    track(`newsletter_lead_${visit.variant}`);
    visit.lead = true;
  }
  save();
  const invite = document.querySelector('[data-newsletter]');
  if (invite) invite.hidden = true;
};

export const setupNewsletter = ({ signal, copy }) => {
  const root = document.querySelector('[data-newsletter]');
  if (!root || document.documentElement.hasAttribute('data-turbo-preview')) return;
  const visit = state();
  if (visit.dismissed || visit.subscribed || visit.offered) return;
  if (!visit.variant) {
    visit.variant = Math.random() < 0.5 ? 'invite' : 'control';
    track(`newsletter_test_${visit.variant}`);
    save();
  }
  if (visit.variant === 'control') return;

  const listen = (target, event, callback, options = {}) => target?.addEventListener(event, callback, { ...options, signal });
  const form = root.querySelector('[data-newsletter-form]');
  const status = root.querySelector('[data-newsletter-status]');
  const openButton = root.querySelector('[data-newsletter-open]');
  let elapsed = false;
  let shown = false;
  let closed = false;
  let cardViewed = false;

  const cardView = () => {
    if (!cardViewed) { track('newsletter_view'); cardViewed = true; }
  };
  const check = () => {
    if (closed || visit.dismissed || signal.aborted) return;
    const busy = document.hidden || document.body.classList.contains('modal-open') || document.querySelector('dialog[open], .main-nav.open');
    if (busy) { root.hidden = true; return; }
    if (!shown) {
      const scrollable = document.documentElement.scrollHeight - window.innerHeight;
      if (!elapsed || scrollable <= 0 || window.scrollY / scrollable < 0.4) return;
      shown = true;
      visit.offered = true;
      save();
      const mobile = window.matchMedia('(max-width: 680px)').matches;
      root.dataset.display = mobile ? 'teaser' : 'card';
      if (mobile) track('newsletter_teaser_view');
      else cardView();
    }
    root.hidden = false;
  };
  const dismiss = (manual = false) => {
    if (closed) return;
    if (manual && shown) track('newsletter_close');
    closed = true;
    visit.dismissed = true;
    save();
    root.hidden = true;
  };

  const timer = window.setTimeout(() => { elapsed = true; check(); }, 20000);
  const observer = new MutationObserver(check);
  observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  signal.addEventListener('abort', () => {
    window.clearTimeout(timer);
    observer.disconnect();
    root.hidden = true;
  }, { once: true });
  listen(window, 'scroll', check, { passive: true });
  listen(document, 'visibilitychange', check);
  listen(document, 'click', (event) => {
    if (event.target.closest('[data-open-contact], [data-contact-card], [data-open-builder]')) dismiss();
    else queueMicrotask(check);
  });
  listen(document, 'focusin', (event) => {
    if (event.target.closest('.contact-form, [data-builder-form]')) dismiss();
  });
  listen(document, 'keydown', (event) => {
    if (event.key === 'Escape' && !root.hidden) dismiss(true);
  });
  root.querySelectorAll('[data-newsletter-close]').forEach((button) => listen(button, 'click', () => dismiss(true)));
  listen(openButton, 'click', () => {
    root.dataset.display = 'card';
    openButton.setAttribute('aria-expanded', 'true');
    cardView();
    form.elements.namedItem('email').focus({ preventScroll: true });
  });
  listen(form, 'submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    if (button.disabled || !form.reportValidity()) return;
    button.disabled = true;
    status.hidden = false;
    status.textContent = copy.sending;
    status.classList.remove('is-error');
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { Accept: 'application/json' }, signal: AbortSignal.any([signal, AbortSignal.timeout(10000)]),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.ok) throw new Error(result.error === 'rate_limit' ? copy.rateLimit : result.error === 'validation' ? copy.invalid : copy.error);
      if (result.subscribed === true) {
        visit.subscribed = true;
        if (result.created === true) track('newsletter_subscribe');
      }
      save();
      root.querySelector('[data-newsletter-copy]').hidden = true;
      root.querySelector('[data-newsletter-success]').hidden = false;
    } catch (error) {
      if (!signal.aborted) {
        status.classList.add('is-error');
        status.textContent = error.name === 'TimeoutError' || error.name === 'AbortError' ? copy.error : error.message || copy.error;
      }
    } finally { button.disabled = false; }
  });
};
