import './styles/shop.css';

const { products, copy, locale } = JSON.parse(document.getElementById('shop-data').textContent);
const byReference = new Map(products.map((product) => [product.reference, product]));
const money = (cents) => new Intl.NumberFormat(locale === 'nb' ? 'nb-NO' : locale, { style: 'currency', currency: 'EUR' }).format(cents / 100);
const escape = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
const icon = (name) => `<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;
const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const storageKey = 'ultrapop-pro-cart-preview-v1';
const grid = document.getElementById('product-grid');
const modal = document.getElementById('product-modal');
const drawer = document.getElementById('cart-drawer');
const filterDrawer = document.getElementById('filter-drawer');
const dialogs = [modal, drawer, filterDrawer];
const cartContent = document.getElementById('cart-content');
const cartSummary = document.getElementById('cart-summary');
const addressForm = document.getElementById('address-form');
const preview = document.getElementById('checkout-preview');
const priceCeiling = Math.ceil(Math.max(0, ...products.map((product) => product.cartonPriceCents || 0)) / 100) * 100;
let filter = { group: 'all', license: 'all', query: '', available: false, sort: 'featured', minPrice: 0, maxPrice: priceCeiling };
let cart = {};
let toastTimer;
let selectedProduct = null;
let selectedQuantity = 1;

const canAdd = (product) => product.available && Number.isInteger(product.pcb) && product.pcb > 0;
const sanitizeCart = (value) => Object.fromEntries(Object.entries(value && typeof value === 'object' && !Array.isArray(value) ? value : {})
  .filter(([reference, quantity]) => byReference.has(reference) && canAdd(byReference.get(reference)) && Number.isInteger(quantity) && quantity > 0 && quantity <= 999));
try { cart = sanitizeCart(JSON.parse(localStorage.getItem(storageKey) || '{}')); } catch { cart = {}; }

const title = (product) => `${product.character} · ${product.flavor}`;
const cartonPrice = (product) => product.cartonPriceCents;
const totals = () => Object.entries(cart).reduce((sum, [reference, quantity]) => {
  const product = byReference.get(reference);
  return { cartons: sum.cartons + quantity, units: sum.units + quantity * product.pcb, price: sum.price + quantity * cartonPrice(product) };
}, { cartons: 0, units: 0, price: 0 });

const stepper = (reference, quantity, className = '') => `<div class="quantity-stepper ${className}"><button type="button" data-cart-action="minus" data-reference="${reference}" aria-label="${escape(copy.decrease)}">−</button><span aria-live="polite">${quantity}</span><button type="button" data-cart-action="plus" data-reference="${reference}" aria-label="${escape(copy.increase)}" ${quantity >= 999 ? 'disabled' : ''}>+</button></div>`;

const buyingControl = (product) => {
  if (!canAdd(product)) return `<button class="quick-add is-unavailable" type="button" data-product="${product.reference}" aria-label="${escape(copy.details + ': ' + title(product))}">${icon('arrow')}</button>`;
  return cart[product.reference] ? stepper(product.reference, cart[product.reference]) : `<button class="quick-add" type="button" data-cart-action="plus" data-reference="${product.reference}" aria-label="${escape(copy.add + ' 1 ' + copy.carton + ': ' + title(product))}"><span>${copy.add}</span><b aria-hidden="true">+</b></button>`;
};

const renderGrid = () => {
  const filtered = products.filter((product) => (filter.group === 'all' || product.group === filter.group)
    && (filter.license === 'all' || (filter.license === 'Dragon Ball' ? product.license.includes('Dragon Ball') : product.license === filter.license))
    && (!filter.available || product.available)
    && (product.pcb ? cartonPrice(product) >= filter.minPrice && cartonPrice(product) <= filter.maxPrice : filter.minPrice === 0 && filter.maxPrice === priceCeiling)
    && (!filter.query || normalize(`${product.name} ${product.reference} ${product.family} ${product.flavor} ${product.character}`).includes(filter.query)));
  if (filter.sort === 'price-asc' || filter.sort === 'price-desc') {
    const direction = filter.sort === 'price-asc' ? 1 : -1;
    filtered.sort((a, b) => a.pcb === null ? (b.pcb === null ? 0 : 1) : b.pcb === null ? -1 : direction * (cartonPrice(a) - cartonPrice(b)));
  } else if (filter.sort === 'name') filtered.sort((a, b) => title(a).localeCompare(title(b), locale));
  grid.innerHTML = filtered.map((product, index) => `<article class="product-card group-${product.group} ${!product.available ? 'out-of-stock' : ''}">
    <button class="product-visual" type="button" data-product="${product.reference}" aria-label="${escape(copy.details + ': ' + title(product))}">
      <span class="product-family">${escape(product.family)}</span>${product.bestseller ? `<span class="product-badge">${icon('star')} BEST-SELLER</span>` : ''}
      <img src="${escape(product.images[0])}" alt="${escape(title(product))}" width="360" height="360" loading="${index < 4 ? 'eager' : 'lazy'}" decoding="async">
      <span class="visual-quickview">${copy.details} ${icon('arrow')}</span>
    </button>
    <div class="product-info"><span class="product-license">${escape(product.license)}</span><button class="product-title" type="button" data-product="${product.reference}">${escape(product.character)}</button><p class="product-flavor">${escape(product.flavor)} <span>${escape(product.format)}</span></p>
    <p class="product-case ${!product.pcb ? 'case-pending' : ''}">${icon('box')}${product.pcb ? `${product.pcb} ${copy.units} / ${copy.carton}` : copy.pcbPending}</p>
    <div class="product-bottom"><div>${product.pcb ? `<strong>${money(cartonPrice(product))}</strong><span>${copy.perCarton}</span>` : `<span class="pending-price">${copy.pcbPending}</span>`}</div><div data-buy-region="${product.reference}">${buyingControl(product)}</div></div>
    ${!product.available ? `<span class="stock-message">${copy.unavailable}</span>` : ''}</div>
  </article>`).join('');
  document.getElementById('result-count').textContent = `${filtered.length} ${copy.results}`;
  document.getElementById('no-results').hidden = filtered.length > 0;
  document.querySelector('[data-filter-result-count]').textContent = filtered.length;
  const activeCount = Number(filter.group !== 'all') + Number(filter.license !== 'all') + Number(filter.available) + Number(filter.minPrice > 0 || filter.maxPrice < priceCeiling) + Number(Boolean(filter.query));
  document.querySelectorAll('[data-filter-count]').forEach((badge) => {
    badge.textContent = activeCount;
    badge.hidden = activeCount === 0;
  });
  document.querySelectorAll('[data-open-filters]').forEach((button) => button.classList.toggle('has-filters', activeCount > 0));
};

const renderCart = () => {
  const sum = totals();
  document.querySelectorAll('[data-cart-count]').forEach((element) => { element.textContent = sum.cartons; });
  const caseLabel = sum.cartons === 1 ? copy.carton : copy.cartons;
  document.querySelector('[data-floating-cart-count]').textContent = `${sum.cartons} ${caseLabel}`;
  document.querySelectorAll('[data-cart-total]').forEach((element) => { element.textContent = money(sum.price); });
  document.body.classList.toggle('has-cart', sum.cartons > 0);
  document.querySelectorAll('[data-buy-region]').forEach((element) => { element.innerHTML = buyingControl(byReference.get(element.dataset.buyRegion)); });
  if (sum.cartons === 0) {
    cartContent.innerHTML = `<div class="empty-cart"><span>${icon('bag')}</span><h3>${copy.emptyTitle}</h3><p>${copy.emptyText}</p><button class="primary-button" type="button" data-close-dialog>${copy.explore} ${icon('arrow')}</button></div>`;
    cartSummary.innerHTML = '';
    return;
  }
  cartContent.innerHTML = `<p class="cart-count-line">${sum.cartons} ${caseLabel} <span>·</span> ${sum.units} ${copy.units}</p>` + Object.entries(cart).map(([reference, quantity]) => {
    const product = byReference.get(reference);
    return `<article class="cart-item"><div class="cart-item-image group-${product.group}"><img src="${escape(product.images[0])}" alt="${escape(title(product))}" width="100" height="120"></div><div class="cart-item-info"><span class="product-license">${escape(product.family)} · ${escape(product.license)}</span><h3>${escape(product.character)}</h3><p>${escape(product.flavor)} ${escape(product.format)}</p><span class="cart-item-pack">${product.pcb} ${copy.units} / ${copy.carton}</span><div class="cart-item-actions">${stepper(reference, quantity)}<strong>${money(cartonPrice(product) * quantity)}</strong></div></div><button class="remove-item icon-button" type="button" data-cart-action="remove" data-reference="${reference}" aria-label="${escape(copy.remove + ': ' + title(product))}">${icon('trash')}</button></article>`;
  }).join('');
  cartSummary.innerHTML = `<div class="subtotal-row"><span>${copy.subtotal}</span><strong>${money(sum.price)}</strong></div><div class="shipping-row"><span>${copy.shipping}</span><span>${copy.shippingLater}</span></div><button class="primary-button checkout-button" type="button" data-checkout>${copy.continue} ${icon('arrow')}</button><p class="summary-note">${copy.priceNote}</p>`;
};

const notify = () => {
  const toast = document.getElementById('shop-toast');
  toast.querySelector('span').textContent = copy.added;
  toast.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.hidden = true; }, 3500);
};

const changeCart = (reference, action, amount = 1) => {
  const product = byReference.get(reference);
  if (!product || !canAdd(product)) return;
  const current = cart[reference] || 0;
  const quantity = action === 'remove' ? 0 : action === 'minus' ? current - 1 : current + amount;
  if (quantity > 0) cart[reference] = Math.min(999, quantity);
  else delete cart[reference];
  try { localStorage.setItem(storageKey, JSON.stringify(cart)); } catch {}
  renderCart();
  if (action === 'plus') notify();
};

const openDialog = (dialog) => {
  dialogs.forEach((other) => { if (other.open && other !== dialog) other.close(); });
  if (!dialog.open) dialog.showModal();
  document.body.classList.add('dialog-open');
};

const showCartStep = (step) => {
  cartContent.hidden = step !== 'cart';
  cartSummary.hidden = step !== 'cart';
  addressForm.hidden = step !== 'address';
  preview.hidden = step !== 'preview';
  document.querySelectorAll('[data-step-label]').forEach((label) => {
    label.classList.toggle('is-active', label.dataset.stepLabel === step || (step === 'preview' && label.dataset.stepLabel === 'address'));
  });
  drawer.scrollTop = 0;
};

const modalQuantity = () => Math.max(1, Math.min(999, Math.trunc(Number(document.getElementById('modal-quantity')?.value ?? selectedQuantity) || 1)));
const updateModalTotal = () => {
  const quantity = modalQuantity();
  selectedQuantity = quantity;
  document.getElementById('modal-quantity').value = quantity;
  document.getElementById('modal-total').textContent = money(cartonPrice(selectedProduct) * quantity);
  document.getElementById('modal-units').textContent = `${quantity * selectedProduct.pcb} ${copy.units}`;
};

const showProduct = (reference, switchingVariant = false) => {
  const product = byReference.get(reference);
  if (!product) return;
  const variants = products.filter((variant) => variant.variantGroup === product.variantGroup);
  const variantIndex = variants.findIndex((variant) => variant.reference === reference);
  const quantity = switchingVariant ? modalQuantity() : 1;
  selectedProduct = product;
  selectedQuantity = quantity;
  document.getElementById('product-modal-content').innerHTML = `<div class="product-detail-grid"><div class="detail-visual group-${product.group}"><span class="detail-family">${escape(product.family)}</span><img id="detail-main-image" src="${escape(product.images[0])}" alt="${escape(title(product))}" width="600" height="650">${product.images.length > 1 ? `<div class="detail-thumbnails">${product.images.map((image, index) => `<button type="button" data-thumbnail="${index}" aria-label="${escape(copy.details)} ${index + 1}" aria-pressed="${index === 0}"><img src="${escape(image)}" alt="" width="56" height="56"></button>`).join('')}</div>` : ''}</div>
    <div class="detail-copy"><header class="detail-header"><p class="eyebrow">${escape(product.family)} · ${escape(product.license)}</p><h2 id="product-modal-title">${escape(product.character)}</h2><p class="detail-flavor">${escape(product.flavor)} <span>${escape(product.format)}</span></p></header>
    <div class="detail-body">
    ${variants.length > 1 ? `<section class="detail-variants" aria-labelledby="variant-label"><div class="variant-heading"><h3 id="variant-label">${escape(copy.variants)} <span>${variantIndex + 1} / ${variants.length}</span></h3><div class="variant-navigation"><button type="button" data-variant-step="-1" aria-label="${escape(copy.previousVariants)}">${icon('arrow')}</button><button type="button" data-variant-step="1" aria-label="${escape(copy.nextVariants)}">${icon('arrow')}</button></div></div><div class="variant-options" role="group" aria-labelledby="variant-label">${variants.map((variant) => `<button type="button" class="variant-option ${!variant.available ? 'is-unavailable' : ''}" data-variant="${variant.reference}" aria-pressed="${variant.reference === product.reference}" aria-label="${escape(`${title(variant)} · ${variant.license}${!variant.available ? ` · ${copy.unavailable}` : ''}`)}"><span class="variant-picture group-${variant.group}"><img src="${escape(variant.variantImage || variant.images[0])}" alt="" width="88" height="88" loading="lazy">${variant.reference === product.reference ? `<span class="variant-check">${icon('check')}</span>` : ''}</span><strong>${escape(variant.character)}</strong><small>${escape(variant.flavor)}</small>${!variant.available ? `<span class="variant-stock">${escape(copy.unavailable)}</span>` : ''}</button>`).join('')}</div></section>` : ''}
    <div class="detail-pack">${icon('box')}<div><span>${copy.packaging}</span><strong>${product.pcb ? `1 ${copy.carton} = ${product.pcb} ${copy.units}` : copy.pcbPending}</strong></div></div>
    <details class="ingredients detail-information"><summary>${copy.ingredients}<span>+</span></summary><p class="detail-description">${escape(product.description)}</p><p class="detail-reference">${copy.reference} ${product.reference}${product.ean ? ` · EAN ${escape(product.ean)}` : ''}</p><p>${escape(product.ingredients || copy.noIngredients)}</p>${product.ingredients && product.ingredientsLocale === 'fr' && locale !== 'fr' ? `<small>${copy.originalFrench}</small>` : ''}<p class="detail-price-note">${copy.priceNote}</p></details></div>
    <footer class="detail-checkout"><div class="detail-offer"><div class="detail-price">${product.pcb ? `<strong>${money(cartonPrice(product))}</strong><span>${copy.perCarton}</span><small>${money(product.unitPriceCents)} ${copy.perUnit}</small>` : `<strong>${money(product.unitPriceCents)}</strong><span>${copy.perUnit}</span>`}</div><p class="detail-stock ${!product.available ? 'is-unavailable' : ''}"><span></span>${product.available ? copy.stock : copy.unavailable}</p></div>
    ${canAdd(product) ? `<div class="modal-purchase"><div class="purchase-label"><label for="modal-quantity">${copy.quantity}</label><span id="modal-units">${product.pcb * quantity} ${copy.units}</span></div><div class="modal-purchase-row"><div class="quantity-stepper modal-stepper"><button type="button" data-modal-quantity="-1" aria-label="${copy.decrease}">−</button><input id="modal-quantity" type="number" min="1" max="999" step="1" value="${quantity}" inputmode="numeric"><button type="button" data-modal-quantity="1" aria-label="${copy.increase}">+</button></div><button class="primary-button" type="button" data-modal-add>${icon('bag')}${copy.add}<span id="modal-total">${money(cartonPrice(product) * quantity)}</span></button></div></div>` : `<button class="primary-button unavailable-button" disabled>${product.available ? copy.pcbPending : copy.unavailable}</button>`}
    </footer></div></div>`;
  openDialog(modal);
  modal.scrollTop = 0;
  if (variants.length > 1) {
    const options = modal.querySelector('.variant-options');
    const selected = options.querySelector('[aria-pressed="true"]');
    options.scrollLeft = selected.offsetLeft - (options.clientWidth - selected.offsetWidth) / 2;
    if (switchingVariant) selected.focus({ preventScroll: true });
  }
};

document.addEventListener('click', (event) => {
  const target = event.target.closest('button, [data-back-cart]');
  if (!target) return;
  if (target.hasAttribute('data-open-filters')) {
    openDialog(filterDrawer);
  } else if (target.hasAttribute('data-apply-filters')) {
    filterDrawer.close();
    document.getElementById('collection').scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  } else if (target.hasAttribute('data-open-cart')) {
    showCartStep('cart');
    openDialog(drawer);
  } else if (target.hasAttribute('data-close-dialog')) target.closest('dialog')?.close();
  else if (target.dataset.product) showProduct(target.dataset.product);
  else if (target.dataset.variant) showProduct(target.dataset.variant, true);
  else if (target.hasAttribute('data-variant-step')) {
    const variants = [...modal.querySelectorAll('[data-variant]')];
    const current = variants.findIndex((button) => button.dataset.variant === selectedProduct.reference);
    const direction = Number(target.dataset.variantStep);
    showProduct(variants[(current + direction + variants.length) % variants.length].dataset.variant, true);
    modal.querySelector(`[data-variant-step="${direction}"]`).focus({ preventScroll: true });
  }
  else if (target.dataset.cartAction) {
    const reference = target.dataset.reference;
    const context = target.closest('.cart-item') ? cartContent : grid;
    const action = target.dataset.cartAction;
    changeCart(reference, action);
    context.querySelector(`[data-reference="${reference}"][data-cart-action="${action === 'remove' ? 'plus' : action}"]`)?.focus({ preventScroll: true });
  } else if (target.dataset.group) {
    filter.group = target.dataset.group;
    document.querySelectorAll('[data-group]').forEach((button) => {
      const active = button.dataset.group === filter.group;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active);
    });
    renderGrid();
  } else if (target.dataset.license) {
    filter.license = target.dataset.license;
    document.querySelectorAll('[data-license]').forEach((button) => {
      const active = button.dataset.license === filter.license;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active);
    });
    renderGrid();
    if (target.hasAttribute('data-scroll-collection')) document.getElementById('collection').scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  } else if (target.hasAttribute('data-reset')) {
    filter = { group: 'all', license: 'all', query: '', available: false, sort: 'featured', minPrice: 0, maxPrice: priceCeiling };
    document.getElementById('shop-search').value = '';
    document.getElementById('shop-sort').value = 'featured';
    document.getElementById('available-only').checked = false;
    syncPriceInputs();
    document.querySelectorAll('[data-group], [data-license]').forEach((button) => {
      const active = (button.dataset.group || button.dataset.license) === 'all';
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active);
    });
    renderGrid();
  } else if (target.hasAttribute('data-modal-quantity')) {
    document.getElementById('modal-quantity').value = modalQuantity() + Number(target.dataset.modalQuantity);
    updateModalTotal();
  } else if (target.hasAttribute('data-modal-add')) {
    changeCart(selectedProduct.reference, 'plus', modalQuantity());
    modal.close();
  } else if (target.hasAttribute('data-thumbnail')) {
    document.getElementById('detail-main-image').src = selectedProduct.images[Number(target.dataset.thumbnail)];
    modal.querySelectorAll('[data-thumbnail]').forEach((button) => button.setAttribute('aria-pressed', button === target));
  } else if (target.hasAttribute('data-checkout')) {
    if (totals().cartons === 0) return;
    showCartStep('address');
    addressForm.querySelector('input').focus();
  } else if (target.hasAttribute('data-back-cart')) showCartStep('cart');
});

document.getElementById('shop-search').addEventListener('input', (event) => { filter.query = normalize(event.target.value.trim()); renderGrid(); });
document.getElementById('shop-sort').addEventListener('change', (event) => { filter.sort = event.target.value; renderGrid(); });
document.getElementById('available-only').addEventListener('change', (event) => { filter.available = event.target.checked; renderGrid(); });
document.getElementById('shop-language').addEventListener('change', (event) => { window.location.href = event.target.value; });
const minPriceInput = document.getElementById('filter-price-min');
const maxPriceInput = document.getElementById('filter-price-max');
const priceRange = document.getElementById('filter-price-range');
const syncPriceInputs = () => {
  minPriceInput.value = filter.minPrice / 100;
  maxPriceInput.value = filter.maxPrice / 100;
  priceRange.value = filter.maxPrice / 100;
  priceRange.style.setProperty('--range-progress', `${priceCeiling ? filter.maxPrice / priceCeiling * 100 : 0}%`);
};
minPriceInput.max = maxPriceInput.max = priceRange.max = priceCeiling / 100;
minPriceInput.addEventListener('change', () => {
  filter.minPrice = Math.max(0, Math.min(priceCeiling, Math.round(Number(minPriceInput.value) * 100) || 0));
  filter.maxPrice = Math.max(filter.minPrice, filter.maxPrice);
  syncPriceInputs();
  renderGrid();
});
const setMaxPrice = (value) => {
  filter.maxPrice = Math.max(0, Math.min(priceCeiling, Math.round(Number(value) * 100) || 0));
  filter.minPrice = Math.min(filter.minPrice, filter.maxPrice);
  syncPriceInputs();
  renderGrid();
};
maxPriceInput.addEventListener('change', () => setMaxPrice(maxPriceInput.value === '' ? priceCeiling / 100 : maxPriceInput.value));
priceRange.addEventListener('input', () => setMaxPrice(priceRange.value));
const filterPanel = filterDrawer.querySelector('.filter-drawer-body');
const sidebarFilters = document.getElementById('desktop-filters');
const desktopFilters = window.matchMedia('(min-width:1024px)');
const syncFilterLayout = () => {
  if (desktopFilters.matches) {
    if (filterDrawer.open) filterDrawer.close();
    sidebarFilters.append(filterPanel);
  } else filterDrawer.insertBefore(filterPanel, filterDrawer.querySelector('.filter-drawer-footer'));
};
desktopFilters.addEventListener('change', syncFilterLayout);
syncFilterLayout();
const floatingFilters = document.querySelector('.floating-filter-trigger');
const filterEntrypoints = new Map([
  [document.querySelector('.shop-header'), false],
  [document.querySelector('.filter-trigger'), false],
]);
const filterVisibility = new IntersectionObserver((entries) => {
  entries.forEach((entry) => filterEntrypoints.set(entry.target, entry.isIntersecting));
  floatingFilters.hidden = [...filterEntrypoints.values()].some(Boolean);
});
filterEntrypoints.forEach((visible, element) => filterVisibility.observe(element));
modal.addEventListener('change', (event) => { if (event.target.id === 'modal-quantity') updateModalTotal(); });
modal.addEventListener('toggle', (event) => {
  const information = event.target;
  if (!information.matches('.detail-information') || !information.open) return;
  const content = information.closest('.detail-body');
  const top = content.scrollTop + information.getBoundingClientRect().top - content.getBoundingClientRect().top - 8;
  content.scrollTo({ top, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
}, { capture: true });
addressForm.addEventListener('submit', (event) => {
  event.preventDefault();
  if (!addressForm.reportValidity() || totals().cartons === 0) return;
  const fields = new FormData(addressForm);
  const lines = [fields.get('organization'), `${fields.get('given-name')} ${fields.get('family-name')}`, fields.get('street-address'), `${fields.get('postal-code')} ${fields.get('address-level2')}`, addressForm.querySelector('select').selectedOptions[0].textContent];
  document.getElementById('address-preview').replaceChildren(...lines.map((line) => {
    const p = document.createElement('p');
    p.textContent = line;
    return p;
  }));
  document.querySelector('[data-review-total]').textContent = `${copy.subtotal} : ${money(totals().price)}`;
  showCartStep('preview');
});

for (const dialog of dialogs) {
  dialog.addEventListener('close', () => {
    document.body.classList.toggle('dialog-open', dialogs.some((item) => item.open));
    if (dialog === filterDrawer) document.querySelectorAll('[data-open-filters]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
  });
  dialog.addEventListener('toggle', () => {
    if (dialog === filterDrawer) document.querySelectorAll('[data-open-filters]').forEach((button) => button.setAttribute('aria-expanded', String(filterDrawer.open)));
  });
  let backdropPointer = false;
  dialog.addEventListener('pointerdown', (event) => { backdropPointer = event.target === dialog; });
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog || !backdropPointer) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
  });
}
window.addEventListener('storage', (event) => {
  if (event.key !== storageKey) return;
  try { cart = sanitizeCart(JSON.parse(event.newValue || '{}')); } catch { cart = {}; }
  showCartStep('cart');
  renderCart();
});

syncPriceInputs();
renderGrid();
renderCart();
