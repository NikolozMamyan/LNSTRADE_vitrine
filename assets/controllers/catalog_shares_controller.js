/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createCatalogRenderer } from '../catalog_media.js';

export default class extends Controller {
  static targets = ['dialog', 'renameDialog', 'shareDialog', 'shareTitle', 'shareStatus', 'shareUrl', 'shareCopy', 'sharePage', 'sharePageEnabled', 'feedback', 'cover'];
  static values = { workerUrl: String };

  connect() {
    if (!this.hasWorkerUrlValue) return;
    this.renderer = createCatalogRenderer(this.workerUrlValue);
    this.observer = new IntersectionObserver((entries) => entries.forEach(async (entry) => {
      if (!entry.isIntersecting) return;
      this.observer.unobserve(entry.target);
      const image = entry.target.querySelector('img[data-pdf-source]');
      try {
        const preview = await this.renderer.renderPage(image.dataset.pdfSource, Number(image.dataset.pdfPage), 260);
        if (!image.isConnected) return;
        image.src = preview.src;
        image.hidden = false;
        image.nextElementSibling.hidden = true;
      } catch {}
    }));
    this.coverTargets.forEach((image) => this.observer.observe(image.parentElement));
  }

  disconnect() { if (this.hasDialogTarget) this.dialogTarget.close(); this.observer?.disconnect(); this.renderer?.destroy(); }
  openDialog() { this.dialogTarget.showModal(); }
  closeDialog() { this.dialogTarget.close(); }
  backdrop(event) { if (event.target === this.dialogTarget) this.closeDialog(); }
  closeModal(event) { event.currentTarget.closest('dialog').close(); }
  outside(event) { this.element.querySelectorAll('details[open]').forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; }); }

  filter(event) {
    const query = event.currentTarget.value.toLocaleLowerCase().trim();
    this.element.querySelectorAll('[data-library-row]').forEach((row) => { row.hidden = !row.dataset.title.toLocaleLowerCase().includes(query); });
  }

  rename(event) {
    const data = event.currentTarget.dataset;
    const form = this.renameDialogTarget.querySelector('form');
    form.action = data.url;
    form.elements.title.value = data.title;
    form.elements._token.value = data.token;
    event.currentTarget.closest('details').open = false;
    this.renameDialogTarget.showModal();
    form.elements.title.select();
  }

  async share(event) {
    const data = event.currentTarget.dataset;
    this.shareTitleTarget.textContent = data.title;
    this.shareStatusTarget.textContent = 'Préparation du lien…';
    this.shareUrlTarget.value = '';
    this.shareBase = null;
    this.shareCopyTarget.disabled = true;
    this.sharePageTarget.max = Math.max(1, Number(data.pages));
    this.sharePageTarget.value = '1';
    this.sharePageEnabledTarget.checked = false;
    this.shareDialogTarget.showModal();
    try {
      const result = await this.post(data.url, { _token: data.token });
      this.shareBase = result.url;
      this.shareUrlTarget.value = result.url;
      this.shareStatusTarget.textContent = result.enabled ? 'Votre lien est prêt à être envoyé.' : 'Le catalogue est désactivé. Activez-le pour rendre ce lien accessible.';
      this.shareCopyTarget.disabled = false;
    } catch (error) { this.shareStatusTarget.textContent = error.message; }
  }

  pageLink() {
    if (!this.shareBase) return;
    const url = new URL(this.shareBase);
    if (this.sharePageEnabledTarget.checked) url.searchParams.set('page', String(Math.min(Number(this.sharePageTarget.max), Math.max(1, Number(this.sharePageTarget.value) || 1))));
    this.shareUrlTarget.value = url.href;
  }

  async toggle(event) {
    const input = event.currentTarget;
    input.disabled = true;
    try {
      const result = await this.post(input.dataset.url, { _token: input.dataset.token, enabled: input.checked ? '1' : '0' });
      input.closest('[data-library-row]').querySelector('[data-library-state]').textContent = result.enabled ? 'Actif' : 'Désactivé';
    } catch (error) {
      input.checked = !input.checked;
      this.feedbackTarget.textContent = error.message;
      this.feedbackTarget.hidden = false;
    } finally { input.disabled = false; }
  }

  async post(url, data) {
    const response = await fetch(url, { method: 'POST', body: new URLSearchParams(data), headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
    if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'L’action a échoué.');
    return result;
  }

  async copy(event) {
    const button = event.currentTarget;
    const input = button.closest('[data-share-row]').querySelector('.admin-share-url');
    let copied = false;
    try {
      await navigator.clipboard.writeText(input.value);
      copied = true;
    } catch {
      input.focus();
      input.select();
      try { copied = document.execCommand('copy'); } catch {}
    }
    button.textContent = copied ? 'Lien copié ✓' : 'Sélectionnez le lien';
    if (copied) button.focus({ preventScroll: true });
    window.setTimeout(() => { if (button.isConnected) button.textContent = 'Copier le lien'; }, 2200);
  }
}
