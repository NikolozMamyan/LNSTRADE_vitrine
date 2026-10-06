import * as pdfjs from 'pdfjs-dist';

const cacheName = 'lns-catalog-pages-v1';

export const createCatalogRenderer = (workerUrl) => {
  pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
  const documents = new Map();
  const loadingTasks = new Map();
  const renders = new Map();
  const cache = window.caches?.open(cacheName).catch(() => null);
  let destroyed = false;

  const renderPage = (source, pageNumber, targetWidth = 1400) => {
    const width = targetWidth <= 320 ? 320 : targetWidth <= 1000 ? 1000 : targetWidth <= 1400 ? 1400 : 1800;
    const key = new URL(source, window.location.href);
    key.searchParams.set('catalogPage', String(pageNumber));
    key.searchParams.set('catalogWidth', String(width));
    const cacheKey = key.href;
    if (renders.has(cacheKey)) return renders.get(cacheKey);
    const rendering = (async () => {
      if (destroyed) throw new DOMException('Catalogue fermé', 'AbortError');
      const storage = await cache;
      const cached = await storage?.match(cacheKey).catch(() => null);
      if (destroyed) throw new DOMException('Catalogue fermé', 'AbortError');
      if (cached) return cached.json();

      if (!documents.has(source)) {
        const task = pdfjs.getDocument({
          url: source,
          disableAutoFetch: true,
          disableStream: true,
        });
        loadingTasks.set(source, task);
        documents.set(source, task.promise.catch((error) => {
          documents.delete(source);
          loadingTasks.delete(source);
          task.destroy().catch(() => {});
          throw error;
        }));
      }
      const document = await documents.get(source);
      const page = await document.getPage(pageNumber);
      const viewport = page.getViewport({ scale: 1 });
      const renderViewport = page.getViewport({ scale: width / viewport.width });
      const canvas = window.document.createElement('canvas');
      canvas.width = Math.ceil(renderViewport.width);
      canvas.height = Math.floor(renderViewport.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: renderViewport }).promise;
      const preview = { src: canvas.toDataURL('image/jpeg', 0.9), width: canvas.width };
      canvas.width = canvas.height = 0;
      page.cleanup();
      storage?.put(cacheKey, new Response(JSON.stringify(preview), {
        headers: { 'Content-Type': 'application/json' },
      })).catch(() => {});
      return preview;
    })().finally(() => renders.delete(cacheKey));
    renders.set(cacheKey, rendering);
    return rendering;
  };

  return {
    renderPage,
    destroy() {
      destroyed = true;
      Promise.allSettled([...loadingTasks.values()].map((task) => task.destroy()));
      loadingTasks.clear();
      documents.clear();
    },
  };
};

export const preloadCatalog = async (url) => {
  if (!await window.caches?.open(cacheName).catch(() => null)) return;
  const controller = new AbortController();
  let renderer;
  const cancel = () => { controller.abort(); renderer?.destroy(); };
  window.addEventListener('pagehide', cancel, { once: true });
  try {
    const response = await fetch(url, { priority: 'low', signal: controller.signal });
    if (!response.ok) return;
    const document = new DOMParser().parseFromString(await response.text(), 'text/html');
    const workerUrl = document.body.dataset.catalogWorkerUrlValue;
    if (!workerUrl || controller.signal.aborted) return;
    renderer = createCatalogRenderer(new URL(workerUrl, response.url).href);
    for (const image of [...document.querySelectorAll('.catalog-book img')].slice(0, 3)) {
      if (controller.signal.aborted || window.document.visibilityState === 'hidden') break;
      if (image.dataset.pdfSource) {
        const source = new URL(image.dataset.pdfSource, response.url).href;
        const page = Number(image.dataset.pdfPage || 1);
        await renderer.renderPage(source, page, 1000).catch(() => {});
      } else if (image.getAttribute('src')) {
        await fetch(new URL(image.getAttribute('src'), response.url), { priority: 'low', signal: controller.signal }).catch(() => {});
      }
      await new Promise((resolve) => window.setTimeout(resolve, 0));
    }
  } finally {
    window.removeEventListener('pagehide', cancel);
    renderer?.destroy();
  }
};
