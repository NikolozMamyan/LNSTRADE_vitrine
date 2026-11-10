/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createVideoPlayer } from '../catalog_video.js';

export default class extends Controller {
  static values = { url: String, label: String };

  connect() {
    this.page = this.element.closest('[data-catalog-target="page"]');
    this.visibilityChanged = (event) => this.setVisible(event.detail.visible);
    this.page?.addEventListener('catalog:visibility', this.visibilityChanged);
    this.setVisible(!this.page || this.page.dataset.catalogVisible === 'true');
  }

  setVisible(visible) {
    if (visible && !this.player) {
      this.player = createVideoPlayer(this.urlValue, this.labelValue);
      if (this.player) this.element.replaceChildren(this.player);
    } else if (!visible && this.player) {
      if (this.player.tagName === 'VIDEO') this.player.pause();
      else { this.player.remove(); this.player = null; }
    }
  }

  interact(event) { event.stopPropagation(); }
  disconnect() {
    this.page?.removeEventListener('catalog:visibility', this.visibilityChanged);
    if (this.player?.tagName === 'VIDEO') this.player.pause();
    this.player?.remove();
    this.player = null;
  }
}
