/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as pdfjs from 'pdfjs-dist';

export default class extends Controller {
  static targets = ['pdfInput', 'pdfPageCount', 'pdfStatus', 'pdfSubmit'];
  static values = { workerUrl: String };

  async inspectPdf() {
    const file = this.pdfInputTarget.files[0];
    this.pdfPageCountTarget.value = '';
    if (!file) {
      this.pdfStatusTarget.textContent = 'Le nombre de pages sera détecté avant l’import.';
      return;
    }

    this.pdfSubmitTarget.disabled = true;
    this.pdfStatusTarget.textContent = 'Analyse du PDF…';
    try {
      pdfjs.GlobalWorkerOptions.workerSrc = this.workerUrlValue;
      const document = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
      this.pdfPageCountTarget.value = String(document.numPages);
      this.pdfStatusTarget.textContent = `${document.numPages} page${document.numPages > 1 ? 's' : ''} détectée${document.numPages > 1 ? 's' : ''} · chaque page sera ajoutée au constructeur.`;
      if (typeof document.destroy === 'function') await document.destroy();
    } catch {
      this.pdfStatusTarget.textContent = 'Ce PDF ne peut pas être analysé dans le navigateur. Le serveur tentera de détecter ses pages.';
    } finally {
      this.pdfSubmitTarget.disabled = false;
    }
  }
}
