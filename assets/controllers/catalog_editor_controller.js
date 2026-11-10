/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createCatalogRenderer } from '../catalog_media.js';
import { createVideoPreview } from '../catalog_video.js';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
export default class extends Controller {
  static targets = ['status', 'save', 'thumbnail', 'sheet', 'image', 'elements', 'properties', 'empty', 'label', 'url', 'x', 'y', 'width', 'height', 'dialog', 'dialogTitle', 'dialogHint'];
  static values = { pages: Array, workerUrl: String, token: String, returnUrl: String };

  connect() {
    this.pages = structuredClone(this.pagesValue);
    this.dirty = new Set();
    this.renderer = createCatalogRenderer(this.workerUrlValue);
    this.current = 0;
    this.selected = -1;
    this.observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      this.observer.unobserve(entry.target);
      const image = entry.target.querySelector('img[data-pdf-source]');
      this.renderer.renderPage(image.dataset.pdfSource, Number(image.dataset.pdfPage), 260).then((preview) => {
        if (!image.isConnected) return;
        image.src = preview.src; image.hidden = false; image.nextElementSibling.hidden = true;
      }).catch(() => {});
    }));
    this.thumbnailTargets.filter((button) => button.querySelector('[data-pdf-source]')).forEach((button) => this.observer.observe(button));
    this.showPage();
  }

  disconnect() { this.renderer.destroy(); this.observer.disconnect(); window.clearTimeout(this.previewTimer); }
  selectPage(event) { this.current = Number(event.currentTarget.dataset.index); this.selected = -1; this.showPage(); }

  async showPage() {
    const index = this.current;
    const page = this.pages[index];
    this.thumbnailTargets.forEach((button, i) => button.setAttribute('aria-current', String(i === index)));
    this.imageTarget.style.visibility = 'hidden';
    this.elementsTarget.replaceChildren();
    this.statusTarget.textContent = 'Chargement de la page…';
    try {
      const source = page.image || (await this.renderer.renderPage(page.pdf, Number(page.pdfPage), 1400)).src;
      if (!this.element.isConnected || this.current !== index) return;
      this.imageTarget.src = source;
      await this.imageTarget.decode();
      if (this.current !== index) return;
      this.sheetTarget.style.aspectRatio = this.imageTarget.naturalWidth + ' / ' + this.imageTarget.naturalHeight;
      this.imageTarget.style.visibility = '';
      this.statusTarget.textContent = `Page ${index + 1} sur ${this.pages.length}`;
      this.render();
    } catch { this.statusTarget.textContent = 'La page ne peut pas être chargée. Réessayez en la sélectionnant.'; }
  }

  add(event) {
    this.kind = event.currentTarget.dataset.kind;
    this.dialogTitleTarget.textContent = this.kind === 'video' ? 'Ajouter une vidéo' : 'Ajouter un lien';
    this.dialogHintTarget.textContent = this.kind === 'video' ? 'YouTube, Vimeo, Wistia ou lien HTTPS vers un fichier MP4 / WebM.' : 'Site web, page du site, adresse e-mail ou téléphone.';
    this.dialogTarget.querySelector('form').reset();
    this.dialogTarget.showModal();
  }
  closeDialog() { this.dialogTarget.close(); }
  confirm(event) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const pageRatio = this.imageTarget.naturalWidth / this.imageTarget.naturalHeight || 595 / 842;
    this.pages[this.current].elements.push({ kind: this.kind, url: data.get('url').trim(), label: data.get('label').trim(), x: 25, y: 25, width: this.kind === 'video' ? 50 : 40, height: this.kind === 'video' ? Math.min(60, 50 * pageRatio * 9 / 16) : 12 });
    this.selected = this.pages[this.current].elements.length - 1;
    this.dirty.add(this.current);
    this.closeDialog();
    this.render();
  }

  render() {
    this.elementsTarget.replaceChildren();
    this.pages[this.current].elements.forEach((element, index) => {
      const box = document.createElement('div');
      box.className = 'catalog-editor-hotspot' + (element.kind === 'video' ? ' is-video' : '') + (index === this.selected ? ' is-selected' : '');
      box.style.cssText = `left:${element.x}%;top:${element.y}%;width:${element.width}%;height:${element.height}%`;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'catalog-editor-handle';
      button.textContent = (element.kind === 'video' ? '▷ ' : '↗ ') + (element.label || (element.kind === 'video' ? 'Vidéo' : 'Lien'));
      button.addEventListener('click', () => { this.selected = index; this.render(); });
      const resize = document.createElement('button');
      resize.type = 'button'; resize.className = 'catalog-editor-resize'; resize.setAttribute('aria-label', 'Redimensionner');
      if (element.kind === 'video') box.append(createVideoPreview(element.url, element.label));
      box.append(button, resize);
      box.addEventListener('pointerdown', (event) => this.drag(event, index, event.target === resize));
      this.elementsTarget.append(box);
    });
    const element = this.pages[this.current].elements[this.selected];
    this.emptyTarget.hidden = !!element; this.propertiesTarget.hidden = !element;
    if (element) ['label', 'url', 'x', 'y', 'width', 'height'].forEach((key) => { this[`${key}Target`].value = element[key]; });
  }

  drag(event, index, resize) {
    if (event.button !== 0) return;
    event.preventDefault();
    this.selected = index;
    const box = event.currentTarget;
    const rect = this.sheetTarget.getBoundingClientRect();
    const element = this.pages[this.current].elements[index];
    const start = { ...element, clientX: event.clientX, clientY: event.clientY };
    box.setPointerCapture(event.pointerId);
    box.classList.add('is-selected');
    const move = (point) => {
      const dx = (point.clientX - start.clientX) / rect.width * 100;
      const dy = (point.clientY - start.clientY) / rect.height * 100;
      if (resize) {
        element.width = clamp(start.width + dx, 1, 100 - element.x);
        element.height = clamp(start.height + dy, 1, 100 - element.y);
      } else {
        element.x = clamp(start.x + dx, 0, 100 - element.width);
        element.y = clamp(start.y + dy, 0, 100 - element.height);
      }
      box.style.cssText = `left:${element.x}%;top:${element.y}%;width:${element.width}%;height:${element.height}%`;
      this.dirty.add(this.current);
    };
    const end = () => { box.removeEventListener('pointermove', move); box.removeEventListener('pointerup', end); box.removeEventListener('pointercancel', end); this.render(); };
    box.addEventListener('pointermove', move); box.addEventListener('pointerup', end); box.addEventListener('pointercancel', end);
  }

  property(event) {
    const element = this.pages[this.current].elements[this.selected];
    const key = event.currentTarget.dataset.field;
    element[key] = ['label', 'url'].includes(key) ? event.currentTarget.value : clamp(Number(event.currentTarget.value) || 0, key === 'width' || key === 'height' ? 1 : 0, 100);
    element.width = Math.min(element.width, 100 - element.x); element.height = Math.min(element.height, 100 - element.y);
    if (element.width < 1) { element.x = 99; element.width = 1; }
    if (element.height < 1) { element.y = 99; element.height = 1; }
    this.dirty.add(this.current);
    if (!['label', 'url'].includes(key)) this.render();
    else {
      const box = this.elementsTarget.children[this.selected];
      box.querySelector('.catalog-editor-handle').textContent = (element.kind === 'video' ? '▷ ' : '↗ ') + (element.label || element.kind);
      if (element.kind === 'video' && key === 'url') {
        window.clearTimeout(this.previewTimer);
        this.previewTimer = window.setTimeout(() => {
          if (box.isConnected) box.querySelector('.catalog-editor-video-preview').replaceWith(createVideoPreview(element.url, element.label));
        }, 350);
      }
    }
  }
  remove() { this.pages[this.current].elements.splice(this.selected, 1); this.selected = -1; this.dirty.add(this.current); this.render(); }

  async save() {
    this.saveTarget.disabled = true;
    this.statusTarget.textContent = 'Enregistrement…';
    try {
      for (const index of [...this.dirty]) {
        const page = this.pages[index];
        const response = await fetch(page.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' }, body: JSON.stringify({ elements: page.elements }) });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée. Reconnectez-vous avant de réessayer.');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(`Page ${index + 1} : ${result.message || 'enregistrement impossible.'}`);
        page.elements = result.elements; this.dirty.delete(index);
      }
      window.location.assign(this.returnUrlValue);
    } catch (error) { this.statusTarget.textContent = error.message; this.saveTarget.disabled = false; }
  }
}
