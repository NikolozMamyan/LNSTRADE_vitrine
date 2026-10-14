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
      if (!focused) {
        const positions = ['.admin-table-wrap', '.visitor-chart'].map((selector) => [
          selector, [...container.querySelectorAll(selector)].map((element) => element.scrollLeft),
        ]);
        container.replaceChildren(content);
        positions.forEach(([selector, offsets]) => {
          container.querySelectorAll(selector).forEach((element, index) => { element.scrollLeft = offsets[index] || 0; });
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
    delete container.dataset.visitorPolling;
    lifecycle.abort();
  };
  window.addEventListener('pagehide', stop, { once: true, signal: lifecycle.signal });
  document.addEventListener('turbo:before-cache', stop, { once: true, signal: lifecycle.signal });
};
