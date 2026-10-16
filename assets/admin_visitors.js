export const setupVisitors = () => {
  const container = document.querySelector('[data-visitor-refresh-url]');
  if (!container || container.dataset.visitorPolling) return;
  container.dataset.visitorPolling = '1';
  const status = document.querySelector('[data-visitor-status]');
  const lifecycle = new AbortController();
  const chart = container.querySelector('.visitor-chart');
  if (chart && window.matchMedia('(max-width: 680px)').matches) chart.scrollLeft = chart.scrollWidth;
  let timer;
  let controller;
  let stopped = false;
  let activeDay = null;

  const hideTooltip = () => {
    const tooltip = container.querySelector('[data-visitor-tooltip]');
    if (tooltip) tooltip.hidden = true;
    activeDay?.removeAttribute('aria-describedby');
    activeDay = null;
  };
  const showTooltip = (day, clientX, clientY) => {
    const area = day.closest('.visitor-chart-area');
    const tooltip = area.querySelector('[data-visitor-tooltip]');
    if (activeDay !== day) {
      const template = document.getElementById(`visitor-day-tooltip-${day.dataset.visitorDay}`);
      activeDay?.removeAttribute('aria-describedby');
      tooltip.replaceChildren(template.content.cloneNode(true));
      activeDay = day;
      day.setAttribute('aria-describedby', tooltip.id);
    }
    tooltip.hidden = false;
    const bounds = area.getBoundingClientRect();
    const width = tooltip.offsetWidth;
    const height = tooltip.offsetHeight;
    let left = clientX - bounds.left + 14;
    let top = clientY - bounds.top - height - 14;
    if (left + width > bounds.width - 8) left = clientX - bounds.left - width - 14;
    if (top < 8) top = clientY - bounds.top + 14;
    tooltip.style.left = `${Math.max(8, Math.min(left, bounds.width - width - 8))}px`;
    tooltip.style.top = `${Math.max(8, Math.min(top, bounds.height - height - 8))}px`;
  };
  container.addEventListener('pointermove', (event) => {
    if (event.pointerType === 'touch') return;
    const day = event.target.closest?.('[data-visitor-day]');
    if (day) showTooltip(day, event.clientX, event.clientY);
    else if (!activeDay?.contains(document.activeElement)) hideTooltip();
  }, { signal: lifecycle.signal });
  container.addEventListener('pointerleave', () => {
    if (!activeDay?.contains(document.activeElement)) hideTooltip();
  }, { signal: lifecycle.signal });
  container.addEventListener('focusin', (event) => {
    const day = event.target.closest?.('[data-visitor-day]');
    if (!day) return;
    const bounds = day.getBoundingClientRect();
    showTooltip(day, bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
  }, { signal: lifecycle.signal });
  container.addEventListener('focusout', hideTooltip, { signal: lifecycle.signal });
  container.addEventListener('scroll', () => {
    if (activeDay?.contains(document.activeElement)) {
      const bounds = activeDay.getBoundingClientRect();
      showTooltip(activeDay, bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
    } else hideTooltip();
  }, { capture: true, signal: lifecycle.signal });
  document.addEventListener('pointerdown', (event) => {
    const day = event.target.closest?.('[data-visitor-day]');
    if (day && container.contains(day) && event.pointerType !== 'mouse') showTooltip(day, event.clientX, event.clientY);
    else if (!day) hideTooltip();
  }, { signal: lifecycle.signal });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hideTooltip();
  }, { signal: lifecycle.signal });

  const refresh = async () => {
    window.clearTimeout(timer);
    if (stopped) return;
    if (document.visibilityState === 'hidden' || controller) {
      timer = window.setTimeout(refresh, 10000);
      return;
    }
    controller = new AbortController();
    const timeout = window.setTimeout(() => controller?.abort(), 8000);
    try {
      const response = await fetch(container.dataset.visitorRefreshUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      });
      if (response.redirected || response.status === 401 || response.status === 403) {
        status.textContent = 'Session admin expirée. Reconnectez-vous pour reprendre le suivi.';
        stopped = true;
        return;
      }
      if (!response.ok) throw new Error('Refresh failed');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const content = parsed.querySelector('[data-visitor-content]');
      if (!content) throw new Error('Invalid response');
      const focused = container.contains(window.document.activeElement);
      if (!focused && !activeDay) {
        const positions = ['.admin-table-wrap', '.visitor-chart', '.visitor-contribution-list'].map((selector) => [
          selector, [...container.querySelectorAll(selector)].map((element) => [element.scrollLeft, element.scrollTop]),
        ]);
        container.replaceChildren(content);
        positions.forEach(([selector, offsets]) => {
          container.querySelectorAll(selector).forEach((element, index) => {
            element.scrollLeft = offsets[index]?.[0] || 0;
            element.scrollTop = offsets[index]?.[1] || 0;
          });
        });
      }
      status.textContent = 'Actualisation toutes les 10 secondes';
      status.classList.remove('is-error');
    } catch {
      status.textContent = 'Actualisation indisponible. Nouvelle tentative dans 10 secondes.';
      status.classList.add('is-error');
    } finally {
      window.clearTimeout(timeout);
      controller = null;
      if (!stopped) timer = window.setTimeout(refresh, 10000);
    }
  };

  timer = window.setTimeout(refresh, 10000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
  }, { signal: lifecycle.signal });
  const stop = () => {
    stopped = true;
    window.clearTimeout(timer);
    controller?.abort();
    hideTooltip();
    delete container.dataset.visitorPolling;
    lifecycle.abort();
  };
  window.addEventListener('pagehide', stop, { once: true, signal: lifecycle.signal });
  document.addEventListener('turbo:before-cache', stop, { once: true, signal: lifecycle.signal });
};
