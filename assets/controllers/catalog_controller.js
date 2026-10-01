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
    this.isNavigating = false;
    this.bookElement = this.bookTarget;
    this.bookPages = this.pageTargets;
    this.zoomShellElement = this.zoomShellTarget;
    this.currentIndex = 0;
    this.pdfDocuments = new Map();
    this.pdfRenders = new WeakMap();
    this.pdfjs = pdfjs;
    this.pdfjs.GlobalWorkerOptions.workerSrc = this.workerUrlValue;

    await this.preparePages(this.bookPages.slice(0, 1));
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
      this.loadingTarget.classList.add('is-hidden');
    });
    this.pageFlip.on('flip', (event) => this.update(Number(event.data)));
    this.pageFlip.on('changeState', (event) => this.stageTarget.classList.toggle('is-flipping', ['flipping', 'user_fold'].includes(event.data)));
    this.pageFlip.loadFromHTML(this.bookPages);
    this.setZoom(90);
  }

  disconnect() {
    this.isConnected = false;
    window.clearTimeout(this.zoomRefreshTimer);
    this.thumbnailObserver?.disconnect();
    if (this.pageFlip) {
      const book = this.bookElement.cloneNode(false);
      book.classList.remove('stf__parent');
      book.removeAttribute('style');
      this.bookPages.forEach((page) => {
        const copy = page.cloneNode(true);
        copy.removeAttribute('style');
        book.appendChild(copy);
      });
      this.pageFlip.destroy();
      this.zoomShellElement.appendChild(book);
      this.pageFlip = null;
    }
    if (this.pdfDocuments) {
      Promise.allSettled([...this.pdfDocuments.values()]).then((results) => {
        results.forEach((result) => {
          if (result.status === 'fulfilled' && typeof result.value.destroy === 'function') result.value.destroy();
        });
      });
      this.pdfDocuments.clear();
    }
  }

  previous() {
    const pages = this.pageFlip?.getPageCollection();
    const spread = pages?.getSpread()[pages.getCurrentSpreadIndex() - 1];
    if (spread) this.goToIndex(spread[0]);
  }
  next() {
    const pages = this.pageFlip?.getPageCollection();
    const spread = pages?.getSpread()[pages.getCurrentSpreadIndex() + 1];
    if (spread) this.goToIndex(spread[0]);
  }
  first() { this.goToIndex(0); }
  last() { this.goToIndex(this.bookPages.length - 1); }

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

  async goToIndex(index) {
    if (!this.pageFlip || this.isNavigating || this.pageFlip.getState() !== 'read') return;
    const safeIndex = Math.max(0, Math.min(index, this.bookPages.length - 1));
    const pages = this.pageFlip.getPageCollection();
    const spread = pages.getSpread()[pages.getSpreadIndexByPage(safeIndex)];
    this.isNavigating = true;
    try {
      await this.preparePages(spread.map((pageIndex) => this.bookPages[pageIndex]));
      if (this.isConnected) this.pageFlip.flip(safeIndex, 'top');
    } finally {
      this.isNavigating = false;
    }
  }

  update(index, announce = true) {
    this.currentIndex = Math.max(0, Math.min(index, this.bookPages.length - 1));
    const page = this.bookPages[this.currentIndex];
    const count = this.bookPages.length;
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
    this.preparePagesNear(this.currentIndex);
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
    const visiblePages = this.bookPages.slice(Math.max(0, this.currentIndex - 1), Math.min(this.bookPages.length, this.currentIndex + 3));
    const images = visiblePages.flatMap((page) => [...page.querySelectorAll('img')]);
    await Promise.allSettled(images.map((image) => image.decode()));
    if (!this.isConnected) return;
    images.forEach((image) => {
      image.style.opacity = '0.999';
      requestAnimationFrame(() => image.style.removeProperty('opacity'));
    });
    await this.preparePagesNear(this.currentIndex);
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

  async preparePages(pages) {
    await Promise.allSettled(pages.flatMap((page) => [...page.querySelectorAll('img')]).map((image) => {
      if (!image.dataset.pdfSource) {
        image.loading = 'eager';
        return image.decode();
      }
      const displayedWidth = this.pageFlip ? image.getBoundingClientRect().width || 595 : 595;
      const targetWidth = Math.max(1000, Math.min(1800, Math.ceil(displayedWidth * Math.min(window.devicePixelRatio || 1, 2) * 1.15)));
      return this.renderPdfImage(image, targetWidth);
    }));
  }

  setupThumbnailRendering() {
    if (!this.pdfjs) return;

    const images = this.thumbnailTargets
      .map((thumbnail) => thumbnail.querySelector('img[data-pdf-source]'))
      .filter(Boolean);
    if (!('IntersectionObserver' in window)) return;

    this.thumbnailObserver = new IntersectionObserver((entries) => {
      if (!this.menuTarget.classList.contains('is-visible')) return;
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        this.thumbnailObserver.unobserve(entry.target);
        this.renderPdfImage(entry.target, 260).catch(() => {});
      });
    }, {
      root: this.menuTarget.querySelector('.catalog-thumbnails'),
      rootMargin: '240px 0px',
    });
    images.forEach((image) => this.thumbnailObserver.observe(image));
  }

  renderThumbnailWindow(index) {
    if (!this.pdfjs) return;
    this.thumbnailTargets
      .slice(Math.max(0, index - 2), Math.min(this.thumbnailTargets.length, index + 8))
      .map((thumbnail) => thumbnail.querySelector('img[data-pdf-source]'))
      .filter(Boolean)
      .forEach((image) => this.renderPdfImage(image, 260).catch(() => {}));
  }

  preparePagesNear(index) {
    return this.preparePages(this.bookPages.slice(Math.max(0, index - 2), Math.min(this.bookPages.length, index + 5)));
  }

  async renderPdfImage(image, targetWidth) {
    if (!this.isConnected) return;
    if (Number(image.dataset.renderedWidth || 0) >= targetWidth) return;
    const pendingRender = this.pdfRenders.get(image);
    if (pendingRender) {
      await pendingRender;
      return this.renderPdfImage(image, targetWidth);
    }
    const rendering = this.renderPdfImageContent(image, targetWidth);
    this.pdfRenders.set(image, rendering);
    try {
      await rendering;
    } finally {
      this.pdfRenders.delete(image);
    }
  }

  async renderPdfImageContent(image, targetWidth) {
    try {
      const source = image.dataset.pdfSource;
      if (!this.pdfDocuments.has(source)) {
        this.pdfDocuments.set(source, this.pdfjs.getDocument({ url: source, disableAutoFetch: true, disableStream: true, httpHeaders: { 'Cache-Control': 'no-cache' } }).promise.catch((error) => {
          this.pdfDocuments.delete(source);
          throw error;
        }));
      }
      const document = await this.pdfDocuments.get(source);
      if (!this.isConnected) return;
      const page = await document.getPage(Number(image.dataset.pdfPage || 1));
      const viewport = page.getViewport({ scale: 1 });
      const renderViewport = page.getViewport({ scale: targetWidth / viewport.width });
      const canvas = window.document.createElement('canvas');
      canvas.width = Math.floor(renderViewport.width);
      canvas.height = Math.floor(renderViewport.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: renderViewport }).promise;
      if (!this.isConnected) return;
      image.src = canvas.toDataURL('image/jpeg', 0.9);
      await image.decode();
      image.dataset.renderedWidth = String(canvas.width);
      image.dataset.rendered = 'true';
      image.classList.remove('has-error');
      image.classList.add('is-ready');
    } catch (error) {
      image.classList.add('has-error');
      throw error;
    }
  }
}
