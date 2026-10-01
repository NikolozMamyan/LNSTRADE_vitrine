import * as pdfjs from 'pdfjs-dist';

const cacheName = 'lns-catalog-pages-v1';

export const createCatalogRenderer = (workerUrl) => {
  pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
  const documents = new Map();
  const renders = new Map();
  const cache = window.caches?.open(cacheName).catch(() => null);
  let destroyed = false;

  const renderPage = (source, pageNumber, targetWidth = 1400) => {
    const width = targetWidth > 1400 ? 1800 : 1400;
    const key = new URL(source, window.location.href);
    key.searchParams.set('catalogPage', String(pageNumber));
    key.searchParams.set('catalogWidth', String(width));
    const cacheKey = key.href;
    if (renders.has(cacheKey)) return renders.get(cacheKey);
    let documentPromise;

    const rendering = (async () => {
      const storage = await cache;
      const cached = await storage?.match(cacheKey).catch(() => null);
      if (cached) return cached.json();
      if (destroyed) throw new DOMException('Catalogue fermé', 'AbortError');

      if (!documents.has(source)) {
        documents.set(source, pdfjs.getDocument({
          url: source,
          disableAutoFetch: true,
          disableStream: true,
          httpHeaders: { 'Cache-Control': 'no-cache' },
        }).promise.catch((error) => {
          documents.delete(source);
          throw error;
        }));
      }
      documentPromise = documents.get(source);
      const document = await documentPromise;
      const page = await document.getPage(pageNumber);
      const viewport = page.getViewport({ scale: 1 });
      const renderViewport = page.getViewport({ scale: width / viewport.width });
      const canvas = window.document.createElement('canvas');
      canvas.width = Math.ceil(renderViewport.width);
      canvas.height = Math.floor(renderViewport.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: renderViewport }).promise;
      const preview = { src: canvas.toDataURL('image/jpeg', 0.9), width: canvas.width };
      page.cleanup();
      await storage?.put(cacheKey, new Response(JSON.stringify(preview), {
        headers: { 'Content-Type': 'application/json' },
      })).catch(() => {});
      return preview;
    })().catch((error) => {
      if (documentPromise && documents.get(source) === documentPromise) {
        documents.delete(source);
        documentPromise.then((document) => document.loadingTask.destroy()).catch(() => {});
      }
      throw error;
    }).finally(() => renders.delete(cacheKey));
    renders.set(cacheKey, rendering);
    return rendering;
  };

  return {
    renderPage,
    destroy() {
      destroyed = true;
      Promise.allSettled([...documents.values()].map((document) => document.then((value) => value.loadingTask.destroy())));
      documents.clear();
    },
  };
};

export const preloadCatalog = async (url) => {
  if (!await window.caches?.open(cacheName).catch(() => null)) return;
  const response = await fetch(url, { priority: 'low' });
  if (!response.ok) return;
  const document = new DOMParser().parseFromString(await response.text(), 'text/html');
  const workerUrl = document.body.dataset.catalogWorkerUrlValue;
  if (!workerUrl) return;
  const renderer = createCatalogRenderer(new URL(workerUrl, response.url).href);
  try {
    for (const image of document.querySelectorAll('.catalog-book img')) {
      if (image.dataset.pdfSource) {
        const source = new URL(image.dataset.pdfSource, response.url).href;
        const page = Number(image.dataset.pdfPage || 1);
        await renderer.renderPage(source, page).catch(() => renderer.renderPage(source, page)).catch(() => {});
      } else if (image.getAttribute('src')) {
        await fetch(new URL(image.getAttribute('src'), response.url), { priority: 'low' }).catch(() => {});
      }
      await new Promise((resolve) => window.setTimeout(resolve, 0));
    }
  } finally {
    renderer.destroy();
  }
};
