/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { PageFlip } from 'page-flip';
import * as pdfjs from 'pdfjs-dist';

export default class extends Controller {
  static values = { workerUrl: String };
  static targets = [
    'stage', 'book', 'page', 'loading', 'previous', 'next', 'first', 'last', 'stagePrevious', 'stageNext',
    'hint', 'currentTitle', 'progress', 'pageNumber', 'download', 'menu', 'menuButton', 'backdrop', 'thumbnail',
    'zoomShell', 'zoomButton', 'zoomPanel', 'zoomRange', 'zoomValue', 'zoomOut', 'zoomIn', 'fullscreen', 'announcement',
  ];

  async connect() {
    if (this.pageTargets.length === 0) return;

    this.isConnected = true;
    this.currentIndex = 0;
    this.pdfDocuments = new Map();
    this.loadingStartedAt = performance.now();

    await this.prepareInitialMedia();
    if (!this.isConnected) return;

    this.initializeBook();
    this.setupThumbnailRendering();
  }

  initializeBook() {
    this.pageFlip = new PageFlip(this.bookTarget, {
      width: 595,
      height: 842,
      size: 'stretch',
      minWidth: 280,
      maxWidth: 510,
      minHeight: 396,
      maxHeight: 721,
      drawShadow: true,
      flippingTime: 1050,
      usePortrait: true,
      autoSize: true,
      maxShadowOpacity: 0.52,
      showCover: true,
      mobileScrollSupport: false,
      swipeDistance: 28,
      clickEventForward: true,
      useMouseEvents: true,
      showPageCorners: false,
      disableFlipByClick: true,
    });
    this.pageFlip.on('init', () => {
      this.update(0, false);
      const remaining = Math.max(0, 900 - (performance.now() - this.loadingStartedAt));
      this.loadingTimer = window.setTimeout(() => this.loadingTarget.classList.add('is-hidden'), remaining);
    });
    this.pageFlip.on('flip', (event) => this.update(Number(event.data)));
    this.pageFlip.on('changeState', (event) => this.stageTarget.classList.toggle('is-flipping', ['flipping', 'user_fold'].includes(event.data)));
    this.pageFlip.loadFromHTML(this.pageTargets);
    this.setZoom(90);
  }

  disconnect() {
    this.isConnected = false;
    window.clearTimeout(this.zoomRefreshTimer);
    window.clearTimeout(this.loadingTimer);
    this.thumbnailObserver?.disconnect();
    this.pageFlip?.destroy();
    if (this.pdfDocuments) {
      Promise.allSettled([...this.pdfDocuments.values()]).then((results) => {
        results.forEach((result) => {
          if (result.status === 'fulfilled' && typeof result.value.destroy === 'function') result.value.destroy();
        });
      });
      this.pdfDocuments.clear();
    }
  }

  previous() { this.pageFlip?.flipPrev('top'); }
  next() { this.pageFlip?.flipNext('top'); }
  first() { this.goToIndex(0); }
  last() { this.goToIndex(this.pageTargets.length - 1); }

  returnToSite(event) {
    if (window.opener && !window.opener.closed) {
      event.preventDefault();
      window.opener.focus();
      window.close();
      return;
    }

    if (document.referrer) {
      const referrer = new URL(document.referrer);
      if (referrer.origin === window.location.origin && referrer.pathname !== window.location.pathname) {
        event.preventDefault();
        window.history.back();
      }
    }
  }

  goTo(event) {
    this.goToIndex(Number(event.currentTarget.dataset.pageIndex));
    this.closeMenu();
  }

  goToIndex(index) {
    const safeIndex = Math.max(0, Math.min(index, this.pageTargets.length - 1));
    this.pageFlip?.flip(safeIndex, 'top');
    this.update(safeIndex);
  }

  update(index, announce = true) {
    this.currentIndex = Math.max(0, Math.min(index, this.pageTargets.length - 1));
    const page = this.pageTargets[this.currentIndex];
    const count = this.pageTargets.length;
    this.currentTitleTarget.textContent = page.dataset.title;
    this.progressTarget.style.width = `${count > 1 ? (this.currentIndex / (count - 1)) * 100 : 100}%`;
    this.pageNumberTarget.textContent = `${String(this.currentIndex + 1).padStart(2, '0')} / ${String(count).padStart(2, '0')}`;
    [this.previousTarget, this.firstTarget, this.stagePreviousTarget].forEach((button) => { button.disabled = this.currentIndex === 0; });
    [this.nextTarget, this.lastTarget, this.stageNextTarget].forEach((button) => { button.disabled = this.currentIndex === count - 1; });
    this.stageTarget.classList.toggle('is-cover', this.currentIndex === 0);
    this.stageTarget.classList.toggle('is-back', this.currentIndex === count - 1);
    this.stageTarget.classList.toggle('is-open', this.currentIndex > 0 && this.currentIndex < count - 1);
    this.hintTarget.classList.toggle('is-hidden', this.currentIndex > 1);
    this.thumbnailTargets.forEach((thumbnail, thumbnailIndex) => thumbnail.classList.toggle('is-current', thumbnailIndex === this.currentIndex));

    const pdf = page.dataset.pdf;
    this.downloadTarget.hidden = !pdf;
    if (pdf) this.downloadTarget.href = pdf;
    else this.downloadTarget.removeAttribute('href');
    this.renderPdfPagesNear(this.currentIndex);
    if (announce) this.announcementTarget.textContent = `Page affichée : ${page.dataset.title}`;
  }

  toggleMenu() { this.menuTarget.classList.contains('is-visible') ? this.closeMenu() : this.openMenu(); }

  openMenu() {
    this.closeZoom();
    this.menuTarget.classList.add('is-visible');
    this.menuTarget.setAttribute('aria-hidden', 'false');
    this.menuButtonTarget.setAttribute('aria-expanded', 'true');
    this.backdropTarget.hidden = false;
    requestAnimationFrame(() => {
      this.backdropTarget.classList.add('is-visible');
      this.renderThumbnailWindow(this.currentIndex);
    });
    this.thumbnailTargets[this.currentIndex]?.focus();
  }

  closeMenu() {
    this.menuTarget.classList.remove('is-visible');
    this.menuTarget.setAttribute('aria-hidden', 'true');
    this.menuButtonTarget.setAttribute('aria-expanded', 'false');
    this.backdropTarget.classList.remove('is-visible');
    window.setTimeout(() => { this.backdropTarget.hidden = true; }, 220);
  }

  toggleZoom() {
    const willOpen = this.zoomPanelTarget.hidden;
    this.zoomPanelTarget.hidden = !willOpen;
    this.zoomButtonTarget.setAttribute('aria-expanded', String(willOpen));
    if (willOpen) this.zoomRangeTarget.focus();
  }

  closeZoom() {
    this.zoomPanelTarget.hidden = true;
    this.zoomButtonTarget.setAttribute('aria-expanded', 'false');
  }

  zoomChanged() { this.setZoom(Number(this.zoomRangeTarget.value)); }
  zoomAnnounced() { this.announcementTarget.textContent = `Zoom du catalogue : ${this.zoomRangeTarget.value} %`; }
  zoomOut() { this.setZoom(Number(this.zoomRangeTarget.value) - 5, true); }
  zoomIn() { this.setZoom(Number(this.zoomRangeTarget.value) + 5, true); }

  setZoom(value, announce = false) {
    const zoom = Math.max(75, Math.min(125, value));
    this.zoomRangeTarget.value = String(zoom);
    this.zoomValueTarget.value = `${zoom}%`;
    this.zoomValueTarget.textContent = `${zoom}%`;
    this.zoomShellTarget.style.transform = `scale(${zoom / 100})`;
    this.zoomOutTarget.disabled = zoom === 75;
    this.zoomInTarget.disabled = zoom === 125;
    window.clearTimeout(this.zoomRefreshTimer);
    this.zoomRefreshTimer = window.setTimeout(() => this.refreshZoomResolution(), 280);
    if (announce) this.announcementTarget.textContent = `Zoom du catalogue : ${zoom} %`;
  }

  async refreshZoomResolution() {
    this.pageFlip?.update();
    const visiblePages = this.pageTargets.slice(Math.max(0, this.currentIndex - 1), Math.min(this.pageTargets.length, this.currentIndex + 3));
    const images = visiblePages.flatMap((page) => [...page.querySelectorAll('img')]);
    await Promise.allSettled(images.map((image) => image.decode()));
    images.forEach((image) => {
      image.style.opacity = '0.999';
      requestAnimationFrame(() => image.style.removeProperty('opacity'));
    });
    await this.renderPdfPagesNear(this.currentIndex, true);
  }

  outsideClick(event) {
    if (!this.zoomPanelTarget.hidden && !this.zoomPanelTarget.contains(event.target) && !this.zoomButtonTarget.contains(event.target)) this.closeZoom();
  }

  async share() {
    const data = { title: document.title, text: this.currentTitleTarget.textContent, url: window.location.href };
    try {
      if (navigator.share) await navigator.share(data);
      else {
        await navigator.clipboard.writeText(data.url);
        this.announcementTarget.textContent = 'Le lien du catalogue a été copié.';
      }
    } catch (error) {
      if (error.name !== 'AbortError') this.announcementTarget.textContent = 'Le catalogue ne peut pas être partagé.';
    }
  }

  async fullscreen() {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    } catch { this.announcementTarget.textContent = 'Le plein écran n’est pas disponible.'; }
  }

  fullscreenChanged() {
    const active = Boolean(document.fullscreenElement);
    this.fullscreenTarget.setAttribute('aria-label', active ? 'Quitter le plein écran' : 'Plein écran');
    this.fullscreenTarget.title = active ? 'Quitter le plein écran' : 'Plein écran';
  }

  keyboard(event) {
    if (event.key === 'Escape' && !this.zoomPanelTarget.hidden) { this.closeZoom(); this.zoomButtonTarget.focus(); return; }
    if (event.key === 'Escape' && this.menuTarget.classList.contains('is-visible')) { this.closeMenu(); this.menuButtonTarget.focus(); return; }
    if (/INPUT|TEXTAREA/.test(event.target.tagName) || this.menuTarget.classList.contains('is-visible')) return;
    if (event.key === 'ArrowRight' || event.key === 'PageDown') this.next();
    if (event.key === 'ArrowLeft' || event.key === 'PageUp') this.previous();
    if (event.key === 'Home') this.first();
    if (event.key === 'End') this.last();
  }

  async prepareInitialMedia() {
    const initialPages = this.pageTargets.slice(0, Math.min(4, this.pageTargets.length));
    const images = initialPages.flatMap((page) => [...page.querySelectorAll('img')]);
    await Promise.allSettled(images.map((image) => image.decode()));

    const canvases = initialPages.map((page) => page.querySelector('.catalog-pdf-canvas')).filter(Boolean);
    if (canvases.length === 0) return;

    this.pdfjs = pdfjs;
    this.pdfjs.GlobalWorkerOptions.workerSrc = this.workerUrlValue;
    const results = await Promise.allSettled(canvases.map((canvas) => this.renderPdfCanvas(this.pdfjs, canvas, 1200)));
    results.forEach((result, index) => {
      if (result.status === 'rejected') canvases[index].classList.add('has-error');
    });
  }

  setupThumbnailRendering() {
    if (!this.pdfjs) return;

    const canvases = this.thumbnailTargets
      .map((thumbnail) => thumbnail.querySelector('canvas[data-pdf-source]'))
      .filter(Boolean);
    if (!('IntersectionObserver' in window)) return;

    this.thumbnailObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        this.thumbnailObserver.unobserve(entry.target);
        this.renderPdfCanvas(this.pdfjs, entry.target, 260).catch(() => entry.target.classList.add('has-error'));
      });
    }, {
      root: this.menuTarget.querySelector('.catalog-thumbnails'),
      rootMargin: '240px 0px',
    });
    canvases.forEach((canvas) => this.thumbnailObserver.observe(canvas));
  }

  renderThumbnailWindow(index) {
    if (!this.pdfjs) return;
    this.thumbnailTargets
      .slice(Math.max(0, index - 2), Math.min(this.thumbnailTargets.length, index + 8))
      .map((thumbnail) => thumbnail.querySelector('canvas[data-pdf-source]'))
      .filter(Boolean)
      .forEach((canvas) => this.renderPdfCanvas(this.pdfjs, canvas, 260).catch(() => canvas.classList.add('has-error')));
  }

  async renderPdfPagesNear(index, force = false) {
    if (!this.pdfjs) return;
    const candidates = this.pageTargets.slice(Math.max(0, index - 1), Math.min(this.pageTargets.length, index + 3));
    await Promise.all(candidates.map((page) => {
      const canvas = page.querySelector('.catalog-pdf-canvas');
      if (!canvas) return Promise.resolve();
      const displayedWidth = canvas.getBoundingClientRect().width || 595;
      const targetWidth = Math.max(1000, Math.min(1800, Math.ceil(displayedWidth * Math.min(window.devicePixelRatio || 1, 2) * 1.15)));
      return this.renderPdfCanvas(this.pdfjs, canvas, targetWidth, force);
    }));
  }

  async renderPdfCanvas(pdfjsLibrary, canvas, targetWidth, force = false) {
    const renderedWidth = Number(canvas.dataset.renderedWidth || 0);
    if (canvas.dataset.rendering === 'true' || (!force && renderedWidth >= targetWidth)) return;
    canvas.dataset.rendering = 'true';
    try {
      const source = canvas.dataset.pdfSource;
      if (!this.pdfDocuments.has(source)) {
        this.pdfDocuments.set(source, pdfjsLibrary.getDocument({ url: source }).promise);
      }
      const document = await this.pdfDocuments.get(source);
      const page = await document.getPage(Number(canvas.dataset.pdfPage || 1));
      const viewport = page.getViewport({ scale: 1 });
      const renderViewport = page.getViewport({ scale: targetWidth / viewport.width });
      canvas.width = Math.floor(renderViewport.width);
      canvas.height = Math.floor(renderViewport.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: renderViewport }).promise;
      canvas.dataset.renderedWidth = String(canvas.width);
      canvas.dataset.rendered = 'true';
      canvas.classList.add('is-ready');
    } finally {
      canvas.dataset.rendering = 'false';
    }
  }
}
