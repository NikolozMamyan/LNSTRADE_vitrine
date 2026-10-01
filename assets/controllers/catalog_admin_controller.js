/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as pdfjs from 'pdfjs-dist';
import { createCatalogRenderer } from '../catalog_media.js';

export default class extends Controller {
  static targets = ['pdfInput', 'pdfPageCount', 'pdfStatus', 'pdfSubmit', 'feedback', 'pageGrid', 'pagesCount', 'updatedAt', 'publishedStatus', 'emptyState', 'pdfPreview'];
  static values = { workerUrl: String };

  connect() {
    this.isSubmitting = false;
    this.uploadUrls = new Map();
    this.renderer = createCatalogRenderer(this.workerUrlValue);
    this.previewObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        this.previewObserver.unobserve(entry.target);
        this.renderPreview(entry.target.querySelector('[data-catalog-admin-target="pdfPreview"]'));
      });
    }, { rootMargin: '200px' });
    this.pdfPreviewTargets.forEach((image) => this.previewObserver.observe(image.parentElement));
  }

  disconnect() {
    this.previewObserver?.disconnect();
    this.renderer?.destroy();
    this.uploadUrls?.forEach((urls) => urls.forEach((url) => URL.revokeObjectURL(url)));
  }

  pdfPreviewTargetConnected(image) { this.previewObserver?.observe(image.parentElement); }
  pdfPreviewTargetDisconnected(image) { this.previewObserver?.unobserve(image.parentElement); }

  async renderPreview(image) {
    try {
      const preview = await this.renderer.renderPage(image.dataset.pdfSource, Number(image.dataset.pdfPage || 1), 260);
      if (!image.isConnected) return;
      image.src = preview.src;
      await image.decode();
      image.hidden = false;
      image.nextElementSibling.hidden = true;
    } catch {
      if (image.isConnected) image.nextElementSibling.querySelector('small').textContent = 'Aperçu indisponible';
    }
  }

  async submit(event) {
    if (event.defaultPrevented) return;
    event.preventDefault();
    if (this.isSubmitting) return;
    const form = event.target;
    const data = new FormData(form);
    const controls = [...form.elements].map((element) => [element, element.disabled]);
    const buttons = [...this.element.querySelectorAll('button[type="submit"]')].map((element) => [element, element.disabled]);
    const card = form.closest('.admin-catalog-page');
    this.isSubmitting = true;
    form.setAttribute('aria-busy', 'true');
    card?.classList.add('is-saving');
    controls.forEach(([element]) => { element.disabled = true; });
    buttons.forEach(([element]) => { element.disabled = true; });
    this.showFeedback('Enregistrement en cours…');
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error(response.url.includes('/admin/login') ? 'Votre session a expiré. Reconnectez-vous pour continuer.' : 'Le formulaire a expiré ou la demande a échoué. Rouvrez le constructeur puis réessayez.');
      }
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'L’enregistrement a échoué. Réessayez.');
      if (!this.element.isConnected) return;
      this.updatePages(result);
      if (['images', 'pdf'].includes(form.dataset.catalogOperation)) {
        form.reset();
        form.querySelectorAll('input[type="file"]').forEach((input) => this.clearPreviews(input));
        if (form.dataset.catalogOperation === 'pdf') {
          this.pdfPageCountTarget.value = '';
          this.pdfStatusTarget.textContent = 'Le nombre de pages sera détecté avant l’import.';
        }
      } else if (!form.isConnected) {
        form.querySelectorAll('input[type="file"]').forEach((input) => this.clearPreviews(input));
      }
      this.showFeedback(result.message);
    } catch (error) {
      if (this.element.isConnected) this.showFeedback(error.message || 'La connexion a été interrompue. Réessayez.', true);
    } finally {
      this.isSubmitting = false;
      form.removeAttribute('aria-busy');
      card?.classList.remove('is-saving');
      buttons.concat(controls).forEach(([element, disabled]) => { if (element.isConnected) element.disabled = disabled; });
      if (this.element.isConnected) this.updateMoveButtons();
    }
  }

  updatePages(result) {
    const grid = this.pageGridTarget;
    const cards = [...grid.querySelectorAll('[data-page-id]')];
    const positions = new Map(cards.map((card) => [Number(card.dataset.pageId), card.getBoundingClientRect()]));
    const scroll = { x: window.scrollX, y: window.scrollY };
    const active = document.activeElement;
    cards.filter((card) => !result.order.includes(Number(card.dataset.pageId))).forEach((card) => card.remove());
    result.pages.forEach((page) => {
      const template = document.createElement('template');
      template.innerHTML = page.html;
      const existing = grid.querySelector(`[data-page-id="${page.id}"]`);
      if (existing) existing.replaceWith(template.content);
      else grid.append(template.content);
    });
    grid.querySelector('.admin-catalog-empty')?.remove();
    result.order.forEach((id, index) => {
      const card = grid.querySelector(`[data-page-id="${id}"]`);
      if (grid.children[index] !== card) grid.insertBefore(card, grid.children[index] || null);
      card.querySelector('.admin-catalog-page-preview > i').textContent = String(index + 1).padStart(2, '0');
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && positions.has(id)) {
        const previous = positions.get(id);
        const current = card.getBoundingClientRect();
        if (previous.top !== current.top || previous.left !== current.left) card.animate([
          { transform: `translate(${previous.left - current.left}px, ${previous.top - current.top}px)` },
          { transform: 'translate(0, 0)' },
        ], { duration: 220, easing: 'ease-out' });
      }
    });
    if (result.order.length === 0) grid.append(this.emptyStateTarget.content.cloneNode(true));
    this.pagesCountTarget.textContent = `${result.order.length} page${result.order.length > 1 ? 's' : ''}`;
    this.updatedAtTarget.textContent = `Mis à jour le ${result.updatedAt}`;
    this.publishedStatusTarget.querySelector('i').classList.toggle('is-online', result.enabled);
    this.publishedStatusTarget.querySelector('strong').textContent = result.enabled ? 'Catalogue publié' : 'Catalogue désactivé';
    this.publishedStatusTarget.querySelector('small').textContent = result.enabled ? 'Le lecteur public est accessible.' : 'Le catalogue reste masqué tant qu’il n’est pas prêt.';
    if (active?.isConnected) active.focus({ preventScroll: true });
    window.scrollTo(scroll.x, scroll.y);
  }

  updateMoveButtons() {
    const cards = [...this.pageGridTarget.querySelectorAll('[data-page-id]')];
    cards.forEach((card, index) => {
      card.querySelector('[aria-label="Monter la page"]').disabled = index === 0;
      card.querySelector('[aria-label="Descendre la page"]').disabled = index === cards.length - 1;
    });
  }

  showFeedback(message, error = false) {
    this.feedbackTarget.textContent = message;
    this.feedbackTarget.hidden = false;
    this.feedbackTarget.classList.toggle('admin-flash-error', error);
    this.feedbackTarget.classList.toggle('admin-flash-success', !error);
  }

  clearPreviews(input) {
    this.uploadUrls.get(input)?.forEach((url) => URL.revokeObjectURL(url));
    this.uploadUrls.delete(input);
    const container = input.closest('form').querySelector('[data-upload-previews]');
    container.replaceChildren();
    container.hidden = true;
  }

  appendPreview(input, file, source) {
    const container = input.closest('form').querySelector('[data-upload-previews]');
    const figure = document.createElement('figure');
    const image = document.createElement('img');
    image.src = source;
    image.alt = `Aperçu de ${file.name}`;
    const caption = document.createElement('figcaption');
    caption.textContent = file.name;
    figure.append(image, caption);
    container.append(figure);
    container.hidden = false;
  }

  previewImages(event) {
    const input = event.currentTarget;
    this.clearPreviews(input);
    const files = [...input.files];
    const error = files.length > 50 ? 'Sélectionnez au maximum 50 images.' : files.some((file) => file.size > 15 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) ? 'Utilisez des images JPG, PNG ou WebP de 15 Mo maximum.' : '';
    input.setCustomValidity(error);
    if (error) { this.showFeedback(error, true); return; }
    const urls = files.map((file) => {
      const url = URL.createObjectURL(file);
      this.appendPreview(input, file, url);
      return url;
    });
    this.uploadUrls.set(input, urls);
  }

  async previewMedia(event) {
    const input = event.currentTarget;
    const file = input.files[0];
    if (!file || !/\.pdf$/i.test(file.name)) { this.previewImages(event); return; }
    this.clearPreviews(input);
    input.setCustomValidity(file.size > 90 * 1024 * 1024 ? 'Le PDF ne doit pas dépasser 90 Mo.' : '');
    if (!input.checkValidity()) { this.showFeedback(input.validationMessage, true); return; }
    let loadingTask;
    try {
      pdfjs.GlobalWorkerOptions.workerSrc = this.workerUrlValue;
      loadingTask = pdfjs.getDocument({ data: await file.arrayBuffer() });
      const document = await loadingTask.promise;
      await this.appendPdfPreview(document, input, file);
    } catch {
      this.showFeedback('L’aperçu de ce PDF n’est pas disponible.', true);
    } finally {
      await loadingTask?.destroy();
    }
  }

  async appendPdfPreview(document, input, file) {
    const page = await document.getPage(1);
    const viewport = page.getViewport({ scale: 1 });
    const renderViewport = page.getViewport({ scale: 300 / viewport.width });
    const canvas = window.document.createElement('canvas');
    canvas.width = Math.ceil(renderViewport.width);
    canvas.height = Math.ceil(renderViewport.height);
    await page.render({ canvasContext: canvas.getContext('2d'), viewport: renderViewport }).promise;
    if (input.isConnected && input.files[0] === file) this.appendPreview(input, file, canvas.toDataURL('image/jpeg', 0.9));
  }

  async inspectPdf() {
    const input = this.pdfInputTarget;
    const file = input.files[0];
    this.clearPreviews(input);
    input.setCustomValidity(file?.size > 90 * 1024 * 1024 ? 'Le PDF ne doit pas dépasser 90 Mo.' : '');
    this.pdfSubmitTarget.disabled = this.isSubmitting;
    this.pdfPageCountTarget.value = '';
    if (!file) {
      this.pdfStatusTarget.textContent = 'Le nombre de pages sera détecté avant l’import.';
      return;
    }
    if (!input.checkValidity()) { this.pdfStatusTarget.textContent = input.validationMessage; return; }

    this.pdfSubmitTarget.disabled = true;
    this.pdfStatusTarget.textContent = 'Analyse du PDF…';
    let loadingTask;
    try {
      pdfjs.GlobalWorkerOptions.workerSrc = this.workerUrlValue;
      loadingTask = pdfjs.getDocument({ data: await file.arrayBuffer() });
      const document = await loadingTask.promise;
      if (input.files[0] !== file || !input.isConnected) return;
      this.pdfPageCountTarget.value = String(document.numPages);
      this.pdfStatusTarget.textContent = `${document.numPages} page${document.numPages > 1 ? 's' : ''} détectée${document.numPages > 1 ? 's' : ''} · chaque page sera ajoutée au constructeur.`;
      await this.appendPdfPreview(document, input, file);
    } catch {
      if (input.isConnected && input.files[0] === file) this.pdfStatusTarget.textContent = 'Ce PDF ne peut pas être analysé dans le navigateur. Le serveur tentera de détecter ses pages.';
    } finally {
      await loadingTask?.destroy();
      if (input.isConnected && input.files[0] === file) this.pdfSubmitTarget.disabled = this.isSubmitting;
    }
  }
}
