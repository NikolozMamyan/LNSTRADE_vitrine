import './stimulus_bootstrap.js';
import './styles/app.css';

let initializedBody = null;
let pageController = null;
let revealObserver = null;
let skeletonTimer = null;
let skeletonShownAt = document.querySelector('[data-page-skeleton].is-active') ? performance.now() : 0;
const modalSkeletonTimers = new WeakMap();

const playModalSkeleton = (modal, duration = 360) => {
  const skeleton = modal?.querySelector('[data-modal-skeleton]');
  if (!skeleton) return;
  window.clearTimeout(modalSkeletonTimers.get(skeleton));
  skeleton.classList.add('is-active');
  const timer = window.setTimeout(() => {
    skeleton.classList.remove('is-active');
    modalSkeletonTimers.delete(skeleton);
  }, duration);
  modalSkeletonTimers.set(skeleton, timer);
};

const skeletonVariantForUrl = (value) => {
  const url = new URL(value, window.location.href);
  const segments = url.pathname.split('/').filter(Boolean);
  if (['en', 'fr'].includes(segments[0])) segments.shift();
  if (segments.length === 0) return 'home';
  if (['blog', 'insights'].includes(segments[0])) return segments.length > 1 ? 'article' : 'blog';
  if (segments[0].includes('trading')) return 'trading';
  if (segments[0].includes('ultrapop')) return 'ultrapop';
  if (segments[0].includes('grossiste') || segments[0].includes('wholesaler')) return 'wholesale';
  return 'home';
};

const showPageSkeleton = (targetUrl = window.location.href, variant = null) => {
  window.clearTimeout(skeletonTimer);
  skeletonTimer = null;
  const skeleton = document.querySelector('[data-page-skeleton]');
  if (!skeleton) return;
  skeleton.dataset.skeletonVariant = variant || skeletonVariantForUrl(targetUrl);
  skeleton.classList.add('is-active');
  document.documentElement.classList.add('skeleton-active');
  skeletonShownAt = performance.now();
};

const hidePageSkeleton = ({ immediate = false, resetScroll = true } = {}) => {
  if (document.documentElement.hasAttribute('data-turbo-preview')) return;
  window.clearTimeout(skeletonTimer);
  const skeleton = document.querySelector('[data-page-skeleton]');
  if (!skeleton) {
    document.documentElement.classList.remove('skeleton-active');
    return;
  }
  const elapsed = skeletonShownAt ? performance.now() - skeletonShownAt : 0;
  const delay = immediate ? 0 : Math.max(0, 320 - elapsed);
  skeletonTimer = window.setTimeout(() => {
    if (skeleton !== document.querySelector('[data-page-skeleton]')) return;
    if (resetScroll && !window.location.hash) {
      document.documentElement.style.scrollBehavior = 'auto';
      document.scrollingElement.scrollTop = 0;
      window.scrollTo(0, 0);
    }
    window.requestAnimationFrame(() => {
      skeleton.classList.remove('is-active');
      document.documentElement.classList.remove('skeleton-active');
      document.documentElement.style.removeProperty('scroll-behavior');
      skeletonShownAt = 0;
    });
  }, delay);
};

const parseUiCopy = () => {
  try {
    return JSON.parse(document.querySelector('#lns-ui-copy')?.textContent || '{}');
  } catch {
    return {};
  }
};

const setupRangeBuilder = ({ listen, modal, contactModal, catalogueModal, uiCopy }) => {
  if (!modal) return;

  const productCards = [...modal.querySelectorAll('[data-builder-product]')];
  const products = new Map(productCards.map((card) => [card.dataset.builderProduct, {
    id: card.dataset.builderProduct,
    category: card.dataset.productCategory,
    categoryLabel: card.dataset.productCategoryLabel,
    name: card.dataset.productName,
    license: card.dataset.productLicense,
    kind: card.dataset.productKind,
    image: card.dataset.productImage,
    card,
  }]));
  const categories = new Set(productCards.map((card) => card.dataset.productCategory));
  const storageKey = modal.dataset.storageKey || 'lns-range-builder-v1';
  const builderBody = modal.querySelector('.range-builder-body');
  const basketList = modal.querySelector('[data-builder-basket-list]');
  const emptyState = modal.querySelector('[data-builder-empty]');
  const miniThumbs = modal.querySelector('[data-builder-mini-thumbs]');
  const contactView = modal.querySelector('[data-builder-contact-view]');
  const successView = modal.querySelector('[data-builder-success]');
  const builderForm = modal.querySelector('[data-builder-form]');
  const selectionInput = modal.querySelector('[data-builder-selection]');
  const emailLink = modal.querySelector('[data-builder-email-link]');
  const mobileCart = modal.querySelector('.range-builder-mobile-cart');
  let state = { category: 'all', basket: {}, step: 1 };

  try {
    const savedState = JSON.parse(localStorage.getItem(storageKey) || '{}');
    const savedBasket = Object.fromEntries(Object.entries(savedState.basket || {})
      .filter(([id, quantity]) => products.has(id) && Number.isInteger(quantity) && quantity > 0)
      .map(([id, quantity]) => [id, Math.min(quantity, 99)]));
    state = {
      category: categories.has(savedState.category) || savedState.category === 'all' ? savedState.category : 'all',
      basket: savedBasket,
      step: Number.isInteger(savedState.step) && savedState.step >= 1 && savedState.step <= 4 ? savedState.step : 1,
    };
  } catch {
    state = { category: 'all', basket: {}, step: 1 };
  }

  const getTotals = () => ({
    references: Object.keys(state.basket).length,
    cases: Object.values(state.basket).reduce((sum, quantity) => sum + quantity, 0),
  });

  const setAllText = (selector, value) => {
    modal.querySelectorAll(selector).forEach((element) => { element.textContent = String(value); });
  };

  const saveState = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(state));
    } catch {
      // The configurator remains usable when browser storage is unavailable.
    }
  };

  const createQuantityControl = (id, quantity) => {
    const control = document.createElement('div');
    control.className = 'range-builder-quantity';

    const decrease = document.createElement('button');
    decrease.type = 'button';
    decrease.dataset.builderDecrease = id;
    decrease.setAttribute('aria-label', modal.querySelector(`[data-builder-decrease="${id}"]`)?.getAttribute('aria-label') || 'Decrease');
    decrease.textContent = '−';

    const output = document.createElement('output');
    output.textContent = String(quantity);

    const increase = document.createElement('button');
    increase.type = 'button';
    increase.dataset.builderIncrease = id;
    increase.setAttribute('aria-label', modal.querySelector(`[data-builder-increase="${id}"]`)?.getAttribute('aria-label') || 'Increase');
    increase.textContent = '+';

    control.append(decrease, output, increase);
    return control;
  };

  const createBasketItem = (product, quantity) => {
    const item = document.createElement('article');
    item.className = 'range-builder-basket-item';

    const image = document.createElement('img');
    image.src = product.image;
    image.alt = '';

    const copy = document.createElement('div');
    copy.className = 'range-builder-basket-item-copy';
    const category = document.createElement('small');
    category.textContent = product.categoryLabel;
    const name = document.createElement('strong');
    name.textContent = product.name;
    const details = document.createElement('span');
    details.textContent = `${product.license} · ${product.kind}`;
    copy.append(category, name, details);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'range-builder-remove';
    remove.dataset.builderRemove = product.id;
    remove.textContent = modal.dataset.labelRemove || 'Remove';

    item.append(image, copy, createQuantityControl(product.id, quantity), remove);
    return item;
  };

  const updateMobileCart = () => {
    const totals = getTotals();
    mobileCart?.classList.toggle('is-visible', state.step === 2 && totals.references > 0);
  };

  const renderBasket = () => {
    const totals = getTotals();
    setAllText('[data-builder-mini-count], [data-builder-summary-count], [data-builder-contact-count], [data-builder-mobile-count]', totals.references);
    setAllText('[data-builder-mini-cases], [data-builder-summary-cases], [data-builder-contact-cases], [data-builder-mobile-cases]', totals.cases);

    productCards.forEach((card) => {
      const id = card.dataset.builderProduct;
      const quantity = state.basket[id] || 0;
      const addButton = card.querySelector('[data-builder-add]');
      const output = card.querySelector('[data-builder-quantity]');
      card.classList.toggle('is-selected', quantity > 0);
      if (output) output.textContent = String(quantity);
      if (addButton) {
        addButton.setAttribute('aria-pressed', String(quantity > 0));
        addButton.firstChild.textContent = `${quantity > 0 ? modal.dataset.labelAdded : modal.dataset.labelAdd} `;
        const symbol = addButton.querySelector('span');
        if (symbol) symbol.textContent = quantity > 0 ? '✓' : '+';
      }
    });

    if (miniThumbs) {
      miniThumbs.replaceChildren(...Object.keys(state.basket).slice(0, 5).map((id) => {
        const image = document.createElement('img');
        image.src = products.get(id).image;
        image.alt = '';
        return image;
      }));
    }

    if (basketList) {
      basketList.replaceChildren(...Object.entries(state.basket).map(([id, quantity]) => createBasketItem(products.get(id), quantity)));
    }
    if (emptyState) emptyState.hidden = totals.references > 0;

    const continueButton = modal.querySelector('.range-builder-summary [data-builder-go="4"]');
    if (continueButton) {
      continueButton.disabled = totals.references === 0;
      continueButton.setAttribute('aria-disabled', String(totals.references === 0));
    }

    if (selectionInput) {
      selectionInput.value = JSON.stringify(Object.entries(state.basket).map(([id, quantity]) => ({
        id,
        name: products.get(id).name,
        license: products.get(id).license,
        quantity,
      })));
    }

    updateMobileCart();
    saveState();
  };

  const filterProducts = (category) => {
    state.category = categories.has(category) || category === 'all' ? category : 'all';
    modal.querySelectorAll('[data-builder-filter]').forEach((button) => {
      const isActive = button.dataset.builderFilter === state.category;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', String(isActive));
    });
    productCards.forEach((card) => {
      card.hidden = state.category !== 'all' && card.dataset.productCategory !== state.category;
    });
    saveState();
  };

  const goToStep = (requestedStep) => {
    const totals = getTotals();
    let step = Math.max(1, Math.min(4, Number(requestedStep) || 1));
    if (step === 4 && totals.references === 0) step = 2;
    state.step = step;

    modal.querySelectorAll('[data-builder-panel]').forEach((panel) => {
      const isActive = Number(panel.dataset.builderPanel) === step;
      panel.hidden = !isActive;
      panel.classList.toggle('is-active', isActive);
    });
    modal.querySelectorAll('[data-builder-progress]').forEach((item) => {
      const itemStep = Number(item.dataset.builderProgress);
      item.classList.toggle('is-active', itemStep === step);
      item.classList.toggle('is-complete', itemStep < step);
      if (itemStep === step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });

    if (step === 2) filterProducts(state.category);
    builderBody?.scrollTo({ top: 0, behavior: 'smooth' });
    renderBasket();
  };

  const setQuantity = (id, quantity) => {
    if (!products.has(id)) return;
    const nextQuantity = Math.max(0, Math.min(99, Number(quantity) || 0));
    if (nextQuantity === 0) delete state.basket[id];
    else state.basket[id] = nextQuantity;
    renderBasket();
  };

  const closeBuilder = () => {
    if (modal.open) modal.close();
    if (!contactModal?.open && !catalogueModal?.open) document.body.classList.remove('modal-open');
  };

  const openBuilder = () => {
    if (contactModal?.open) contactModal.close();
    if (catalogueModal?.open) catalogueModal.close();
    contactView?.removeAttribute('hidden');
    if (successView) successView.hidden = true;
    playModalSkeleton(modal, 380);
    if (!modal.open) modal.showModal();
    document.body.classList.add('modal-open');
    goToStep(state.step);
  };

  listen(modal, 'click', (event) => {
    if (event.target === modal) {
      closeBuilder();
      return;
    }

    const closeButton = event.target.closest('[data-close-builder]');
    if (closeButton) {
      closeBuilder();
      return;
    }

    const categoryButton = event.target.closest('[data-builder-choose-category]');
    if (categoryButton) {
      filterProducts(categoryButton.dataset.builderChooseCategory);
      goToStep(2);
      return;
    }

    const filterButton = event.target.closest('[data-builder-filter]');
    if (filterButton) {
      filterProducts(filterButton.dataset.builderFilter);
      return;
    }

    const addButton = event.target.closest('[data-builder-add]');
    if (addButton) {
      const id = addButton.dataset.builderAdd;
      if (!state.basket[id]) setQuantity(id, 1);
      return;
    }

    const increaseButton = event.target.closest('[data-builder-increase]');
    if (increaseButton) {
      const id = increaseButton.dataset.builderIncrease;
      setQuantity(id, (state.basket[id] || 0) + 1);
      return;
    }

    const decreaseButton = event.target.closest('[data-builder-decrease]');
    if (decreaseButton) {
      const id = decreaseButton.dataset.builderDecrease;
      setQuantity(id, (state.basket[id] || 0) - 1);
      return;
    }

    const removeButton = event.target.closest('[data-builder-remove]');
    if (removeButton) {
      setQuantity(removeButton.dataset.builderRemove, 0);
      return;
    }

    const goButton = event.target.closest('[data-builder-go]');
    if (goButton) {
      goToStep(goButton.dataset.builderGo);
      return;
    }

    if (event.target.closest('[data-builder-edit]')) {
      if (successView) successView.hidden = true;
      contactView?.removeAttribute('hidden');
      builderForm?.querySelector('input:not([type="hidden"])')?.focus();
      return;
    }

    if (event.target.closest('[data-builder-reset]')) {
      state = { category: 'all', basket: {}, step: 1 };
      try { localStorage.removeItem(storageKey); } catch { /* Storage can be unavailable. */ }
      builderForm?.reset();
      if (successView) successView.hidden = true;
      contactView?.removeAttribute('hidden');
      filterProducts('all');
      goToStep(1);
    }
  });

  listen(modal, 'close', () => {
    if (!contactModal?.open && !catalogueModal?.open) document.body.classList.remove('modal-open');
  });

  listen(builderForm, 'submit', (event) => {
    event.preventDefault();
    if (getTotals().references === 0) {
      goToStep(2);
      return;
    }

    const data = new FormData(builderForm);
    const mail = uiCopy.mail || {};
    const selectionLines = Object.entries(state.basket).map(([id, quantity]) => {
      const product = products.get(id);
      const caseLabel = quantity === 1 ? modal.dataset.labelCase : modal.dataset.labelCases;
      return `- ${product.name} — ${product.license}: ${quantity} ${caseLabel}`;
    });
    const subject = encodeURIComponent(`${modal.dataset.mailSubject} — ${data.get('company')}`);
    const body = encodeURIComponent([
      modal.dataset.mailSelection,
      ...selectionLines,
      '',
      modal.dataset.mailContact,
      `${mail.name || 'Name'}: ${data.get('name')}`,
      `${mail.company || 'Company'}: ${data.get('company')}`,
      `${mail.email || 'Email'}: ${data.get('email')}`,
      `${mail.phone || 'Phone'}: ${data.get('phone') || mail.empty || '-'}`,
      `${mail.vat || 'VAT'}: ${data.get('vat') || mail.empty || '-'}`,
      '',
      data.get('message') || mail.noMessage || '',
      '',
      `${mail.origin || 'Source'}: ${window.location.href}`,
    ].join('\n'));

    if (emailLink) emailLink.href = `mailto:info@lnstrade.fr?subject=${subject}&body=${body}`;
    contactView?.setAttribute('hidden', '');
    if (successView) successView.hidden = false;
    builderBody?.scrollTo({ top: 0, behavior: 'smooth' });
  });

  document.querySelectorAll('[data-open-builder]').forEach((button) => listen(button, 'click', openBuilder));
  filterProducts(state.category);
  goToStep(state.step);

  if (window.location.hash === '#range-builder') openBuilder();
};

const closePageUi = () => {
  const contactModal = document.querySelector('#contact-modal');
  const catalogueModal = document.querySelector('#catalogue-modal');
  const rangeBuilderModal = document.querySelector('#range-builder-modal');
  if (contactModal?.open) contactModal.close();
  if (catalogueModal?.open) catalogueModal.close();
  if (rangeBuilderModal?.open) rangeBuilderModal.close();

  const catalogueFrame = document.querySelector('[data-catalogue-frame]');
  const catalogueLoader = document.querySelector('[data-catalogue-loader]');
  catalogueFrame?.removeAttribute('src');
  catalogueFrame?.classList.remove('is-ready');
  catalogueLoader?.classList.remove('is-hidden');

  document.body?.classList.remove('modal-open');
  document.querySelector('.main-nav')?.classList.remove('open');
  document.querySelector('[data-language-switcher]')?.classList.remove('open');
  const menuToggle = document.querySelector('.menu-toggle');
  const languageTrigger = document.querySelector('.language-switcher-trigger');
  menuToggle?.setAttribute('aria-expanded', 'false');
  languageTrigger?.setAttribute('aria-expanded', 'false');
  document.querySelector('script[data-generated-breadcrumb]')?.remove();
};

const cleanupPage = () => {
  closePageUi();
  pageController?.abort();
  revealObserver?.disconnect();
  pageController = null;
  revealObserver = null;
  initializedBody = null;
};

const initializePage = () => {
  if (initializedBody === document.body) return;

  pageController?.abort();
  revealObserver?.disconnect();
  initializedBody = document.body;
  pageController = new AbortController();
  const { signal } = pageController;
  const listen = (target, type, handler, options = {}) => {
    target?.addEventListener(type, handler, { ...options, signal });
  };

  const header = document.querySelector('[data-header]');
  const menuToggle = document.querySelector('.menu-toggle');
  const mainNav = document.querySelector('.main-nav');
  const languageSwitcher = document.querySelector('[data-language-switcher]');
  const languageTrigger = document.querySelector('.language-switcher-trigger');
  const contactModal = document.querySelector('#contact-modal');
  const contactForm = document.querySelector('#contact-form');
  const catalogueModal = document.querySelector('#catalogue-modal');
  const rangeBuilderModal = document.querySelector('#range-builder-modal');
  const catalogueFrame = catalogueModal?.querySelector('[data-catalogue-frame]');
  const catalogueLoader = catalogueModal?.querySelector('[data-catalogue-loader]');
  const uiCopy = parseUiCopy();

  document.querySelector('.scroll-progress')?.remove();
  const scrollProgress = document.createElement('div');
  scrollProgress.className = 'scroll-progress';
  scrollProgress.setAttribute('aria-hidden', 'true');
  scrollProgress.innerHTML = '<span></span>';
  document.body.prepend(scrollProgress);

  const updateHeader = () => {
    header?.classList.toggle('scrolled', window.scrollY > 32);
    const scrollable = document.documentElement.scrollHeight - window.innerHeight;
    const progress = scrollable > 0 ? Math.min(1, Math.max(0, window.scrollY / scrollable)) : 0;
    document.documentElement.style.setProperty('--scroll-progress', progress.toFixed(4));
  };
  updateHeader();
  listen(window, 'scroll', updateHeader, { passive: true });

  const hero = document.querySelector('.hero');
  if (hero && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    listen(hero, 'pointermove', (event) => {
      const bounds = hero.getBoundingClientRect();
      hero.style.setProperty('--hero-x', `${event.clientX - bounds.left}px`);
      hero.style.setProperty('--hero-y', `${event.clientY - bounds.top}px`);
    }, { passive: true });
    listen(hero, 'pointerleave', () => {
      hero.style.removeProperty('--hero-x');
      hero.style.removeProperty('--hero-y');
    });
  }

  listen(menuToggle, 'click', () => {
    const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
    menuToggle.setAttribute('aria-expanded', String(!isOpen));
    mainNav?.classList.toggle('open', !isOpen);
  });
  mainNav?.querySelectorAll('a').forEach((link) => listen(link, 'click', () => {
    menuToggle?.setAttribute('aria-expanded', 'false');
    mainNav.classList.remove('open');
  }));

  const closeLanguageSwitcher = () => {
    languageSwitcher?.classList.remove('open');
    languageTrigger?.setAttribute('aria-expanded', 'false');
  };
  listen(languageTrigger, 'click', () => {
    const isOpen = languageSwitcher?.classList.toggle('open') || false;
    languageTrigger.setAttribute('aria-expanded', String(isOpen));
  });
  listen(document, 'click', (event) => {
    if (languageSwitcher && !languageSwitcher.contains(event.target)) closeLanguageSwitcher();
  });
  listen(document, 'keydown', (event) => {
    if (event.key === 'Escape') {
      closeLanguageSwitcher();
      if (contactModal?.open) contactModal.close();
      if (catalogueModal?.open) catalogueModal.close();
      if (rangeBuilderModal?.open) rangeBuilderModal.close();
      document.body.classList.remove('modal-open');
    }
  });

  const defaultJourney = {
    context: contactForm?.elements.namedItem('context')?.value || '',
    label: contactForm?.querySelector('[data-contact-journey-title]')?.textContent.trim() || '',
    benefits: [...(contactForm?.querySelectorAll('[data-contact-journey-tags] span') || [])].map((tag) => tag.textContent.trim()),
  };

  const updateContactJourney = ({ context, label, benefits }) => {
    const contextInput = contactForm?.elements.namedItem('context');
    const journeyTitle = contactForm?.querySelector('[data-contact-journey-title]');
    const journeyTags = contactForm?.querySelector('[data-contact-journey-tags]');
    if (contextInput) contextInput.value = context;
    if (journeyTitle) journeyTitle.textContent = label;
    if (journeyTags) {
      journeyTags.replaceChildren(...benefits.map((benefit) => {
        const tag = document.createElement('span');
        tag.textContent = benefit;
        return tag;
      }));
    }
  };

  const openContact = (journey = defaultJourney) => {
    if (!contactModal) return;
    if (catalogueModal?.open) catalogueModal.close();
    if (rangeBuilderModal?.open) rangeBuilderModal.close();
    updateContactJourney({ ...defaultJourney, ...journey });
    playModalSkeleton(contactModal);
    if (!contactModal.open) contactModal.showModal();
    document.body.classList.add('modal-open');
  };
  const closeContact = () => {
    if (contactModal?.open) contactModal.close();
    if (!catalogueModal?.open && !rangeBuilderModal?.open) document.body.classList.remove('modal-open');
  };

  const openCatalogue = () => {
    if (!catalogueModal) return;
    if (contactModal?.open) contactModal.close();
    if (rangeBuilderModal?.open) rangeBuilderModal.close();
    playModalSkeleton(catalogueModal);
    if (catalogueFrame && !catalogueFrame.hasAttribute('src')) {
      catalogueFrame.src = catalogueFrame.dataset.src;
      listen(catalogueFrame, 'load', () => {
        catalogueLoader?.classList.add('is-hidden');
        catalogueFrame.classList.add('is-ready');
      }, { once: true });
    }
    if (!catalogueModal.open) catalogueModal.showModal();
    document.body.classList.add('modal-open');
  };
  const closeCatalogue = () => {
    if (catalogueModal?.open) catalogueModal.close();
    if (!contactModal?.open && !rangeBuilderModal?.open) document.body.classList.remove('modal-open');
  };

  document.querySelectorAll('[data-open-contact]').forEach((button) => listen(button, 'click', () => {
    const context = button.dataset.interest || defaultJourney.context;
    if (context.toLocaleLowerCase().includes('catalog')) {
      openCatalogue();
      return;
    }
    openContact({
      context,
      label: button.dataset.contactLabel || context || defaultJourney.label,
      benefits: defaultJourney.benefits,
    });
  }));

  document.querySelectorAll('a[href*="online.flippingbook.com/link/845542"]').forEach((link) => {
    if (link.closest('#catalogue-modal')) return;
    listen(link, 'click', (event) => {
      event.preventDefault();
      openCatalogue();
      mainNav?.classList.remove('open');
      menuToggle?.setAttribute('aria-expanded', 'false');
    });
  });

  document.querySelectorAll('[data-contact-card]').forEach((card) => {
    const openCard = () => openContact({
      context: card.dataset.contactContext || defaultJourney.context,
      label: card.dataset.contactLabel || defaultJourney.label,
      benefits: (card.dataset.contactBenefits || defaultJourney.benefits.join('|')).split('|'),
    });
    listen(card, 'click', openCard);
    listen(card, 'keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openCard();
      }
    });
  });

  document.querySelectorAll('[data-close-contact]').forEach((button) => listen(button, 'click', closeContact));
  listen(contactModal, 'click', (event) => {
    if (event.target === contactModal) closeContact();
  });
  listen(contactModal, 'close', () => {
    if (!catalogueModal?.open && !rangeBuilderModal?.open) document.body.classList.remove('modal-open');
  });
  document.querySelectorAll('[data-close-catalogue]').forEach((button) => listen(button, 'click', closeCatalogue));
  listen(catalogueModal, 'click', (event) => {
    if (event.target === catalogueModal) closeCatalogue();
  });
  listen(catalogueModal, 'close', () => {
    if (!contactModal?.open && !rangeBuilderModal?.open) document.body.classList.remove('modal-open');
  });

  setupRangeBuilder({ listen, modal: rangeBuilderModal, contactModal, catalogueModal, uiCopy });

  if (window.location.hash === '#contact') openContact(defaultJourney);
  if (window.location.hash === '#catalogue') openCatalogue();

  listen(contactForm, 'submit', (event) => {
    event.preventDefault();
    const data = new FormData(contactForm);
    const copy = uiCopy.mail || {};
    const subject = encodeURIComponent(`${copy.subject || 'LNS Trade'} — ${data.get('company')}`);
    const body = encodeURIComponent([
      `${copy.context || 'Context'} : ${data.get('context') || defaultJourney.context}`,
      `${copy.name || 'Name'} : ${data.get('name')}`,
      `${copy.company || 'Company'} : ${data.get('company')}`,
      `${copy.email || 'Email'} : ${data.get('email')}`,
      `${copy.phone || 'Phone'} : ${data.get('phone') || copy.empty || '-'}`,
      `${copy.vat || 'VAT'} : ${data.get('vat') || copy.empty || '-'}`,
      '',
      data.get('message') || copy.noMessage || '',
      '',
      `${copy.origin || 'Source'} : ${window.location.href}`,
    ].join('\n'));
    const note = contactForm.querySelector('.form-note');
    if (note && copy.note) note.textContent = copy.note;
    window.location.href = `mailto:info@lnstrade.fr?subject=${subject}&body=${body}`;
  });

  if ('IntersectionObserver' in window) {
    revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver?.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -35px' });
    document.querySelectorAll('.reveal').forEach((element) => {
      const bounds = element.getBoundingClientRect();
      if (bounds.top < window.innerHeight && bounds.bottom > 0) element.classList.add('is-visible');
      else revealObserver.observe(element);
    });
  } else {
    document.querySelectorAll('.reveal').forEach((element) => element.classList.add('is-visible'));
  }

  document.querySelectorAll('[data-year]').forEach((element) => {
    element.textContent = new Date().getFullYear();
  });

  document.querySelector('script[data-generated-breadcrumb]')?.remove();
  const breadcrumb = document.querySelector('.breadcrumb');
  const canonical = document.querySelector('link[rel="canonical"]')?.href;
  if (breadcrumb && canonical) {
    const items = [...breadcrumb.querySelectorAll('a')].map((link, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: link.textContent.trim(),
      item: new URL(link.href, canonical).href,
    }));
    items.push({
      '@type': 'ListItem',
      position: items.length + 1,
      name: document.querySelector('h1')?.textContent.trim() || document.title,
      item: canonical,
    });
    const structuredData = document.createElement('script');
    structuredData.type = 'application/ld+json';
    structuredData.dataset.generatedBreadcrumb = '';
    structuredData.textContent = JSON.stringify({
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: items,
    });
    document.head.append(structuredData);
  }

  hidePageSkeleton();
};

document.addEventListener('turbo:before-visit', (event) => {
  const target = new URL(event.detail.url, window.location.href);
  const current = new URL(window.location.href);
  const isSamePageAnchor = target.pathname === current.pathname
    && target.search === current.search
    && target.hash
    && target.hash !== current.hash;
  if (!isSamePageAnchor) showPageSkeleton(target.href);
});
document.addEventListener('turbo:before-render', (event) => {
  const incoming = event.detail.newBody?.querySelector('[data-page-skeleton]');
  if (!incoming) return;
  const current = document.querySelector('[data-page-skeleton]');
  if (!current?.classList.contains('is-active')) showPageSkeleton(window.location.href, incoming.dataset.skeletonVariant);
  incoming.classList.add('is-active');
  incoming.dataset.skeletonVariant = incoming.dataset.skeletonVariant || 'home';
  document.documentElement.classList.add('skeleton-active');
});
document.addEventListener('turbo:fetch-request-error', () => hidePageSkeleton({ immediate: true, resetScroll: false }));
document.addEventListener('turbo:load', initializePage);
document.addEventListener('turbo:before-cache', cleanupPage);

if (document.readyState !== 'loading') initializePage();
