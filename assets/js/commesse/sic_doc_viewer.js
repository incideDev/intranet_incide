// assets/js/commesse/sic_doc_viewer.js

/**
 * initSicDocViewer({
 *   rootSelector: '#sic-doc-viewer',
 *   provider: async () => [{ id, nome, ext, url, preview_url, uploaded_at, scadenza }],
 *   onSelect?: (doc) => void,
 *   storageKey?: 'vrtp|ade02c_a|7' // opzionale per ricordare ultima selezione
 * })
 */
function initSicDocViewer(opts) {
  if (!opts || typeof opts.rootSelector !== 'string' || typeof opts.provider !== 'function') return;

  const root = document.querySelector(opts.rootSelector);
  if (!root) return;

  const thumbsEl = root.querySelector('#sicv-thumbs');
  const stageEl  = root.querySelector('#sicv-stage');
  const metaEl   = root.querySelector('#sicv-meta');

  if (!thumbsEl || !stageEl || !metaEl) return;

  let docs = [];
  let activeIndex = -1;

  /* ========== Utils sicuri & accessibili ========== */
  const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const extLabel   = (ext) => (String(ext || '').toUpperCase() || 'FILE');

  // Accetta solo URL relative o http/https. Nega javascript:, data:, ecc.
  function sanitizeUrl(u) {
    const s = String(u || '').trim();
    if (!s) return '';
    if (s.startsWith('/')) return s;
    try {
      const url = new URL(s, window.location.origin);
      if (url.protocol === 'http:' || url.protocol === 'https:') return url.toString();
    } catch {}
    return '';
  }

  function setRovingTabIndex(index) {
    thumbsEl.querySelectorAll('.sicv-thumb').forEach((el, i) => {
      el.setAttribute('tabindex', i === index ? '0' : '-1');
      el.setAttribute('role', 'button');
      el.setAttribute('aria-label', el.getAttribute('data-tooltip') || 'Documento');
    });
  }

  function persistActive() {
    if (!opts.storageKey) return;
    try { sessionStorage.setItem('sicv:last:' + opts.storageKey, String(activeIndex)); } catch {}
  }
  function restoreActive() {
    if (!opts.storageKey) return null;
    try {
      const v = sessionStorage.getItem('sicv:last:' + opts.storageKey);
      const i = v != null ? parseInt(v, 10) : NaN;
      return Number.isFinite(i) ? i : null;
    } catch { return null; }
  }

  /* ========== Render thumbnails ========== */
  function renderThumbs() {
    thumbsEl.innerHTML = '';
    const frag = document.createDocumentFragment();

    docs.forEach((d, i) => {
      const a = document.createElement('a');
      a.href = '#';
      a.className = 'sicv-thumb';
      a.dataset.index = String(i);
      a.setAttribute('data-tooltip', d.nome || 'Documento');

      if (d.preview_url && /\.(jpe?g|png|gif|webp|avif)$/i.test(d.preview_url)) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = d.preview_url;
        a.appendChild(img);
      } else {
        a.textContent = (d.nome || '').slice(0, 12) || extLabel(d.ext);
      }

      const b = document.createElement('span');
      b.className = 'sicv-badge';
      b.textContent = extLabel(d.ext);
      a.appendChild(b);

      a.addEventListener('click', (e) => {
        e.preventDefault();
        setActive(i, true);
      });

      a.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          setActive(i, true);
        } else if (e.key === 'ArrowRight') {
          e.preventDefault(); next(true);
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault(); prev(true);
        }
      });

      frag.appendChild(a);
    });

    thumbsEl.appendChild(frag);
    setRovingTabIndex(activeIndex >= 0 ? activeIndex : 0);
  }

  /* ========== Render stage/viewer ========== */
  function renderStage() {
    stageEl.innerHTML = '';

    // Barra azioni (Prev/Next) + meta
    const actionsBar = document.createElement('div');
    actionsBar.className = 'sicv-actions';
    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'sicv-pill';
    prevBtn.textContent = '‹ Prec';
    prevBtn.setAttribute('data-tooltip', 'Documento precedente');
    prevBtn.addEventListener('click', () => prev(true));

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'sicv-pill';
    nextBtn.textContent = 'Succ ›';
    nextBtn.setAttribute('data-tooltip', 'Documento successivo');
    nextBtn.addEventListener('click', () => next(true));

    actionsBar.appendChild(prevBtn);
    actionsBar.appendChild(nextBtn);

    if (activeIndex < 0 || !docs[activeIndex]) {
      const empty = document.createElement('div');
      empty.className = 'sicv-empty';
      empty.textContent = 'Seleziona un documento per visualizzarlo.';
      stageEl.appendChild(actionsBar);
      stageEl.appendChild(empty);
      metaEl.textContent = '';
      return;
    }

    const d = docs[activeIndex];

    // Meta
    const bits = [];
    if (d.uploaded_at) bits.push(`Caricato: ${d.uploaded_at}`);
    if (d.scadenza)    bits.push(`Scadenza: ${d.scadenza}`);
    metaEl.textContent = bits.join(' · ');

    const url  = sanitizeUrl(d.url);
    const purl = sanitizeUrl(d.preview_url);

    const wrap = document.createElement('div');
    wrap.className = 'sicv-view';

    if (url && /\.(pdf)$/i.test(url)) {
      const ifr = document.createElement('iframe');
      ifr.src = url;
      ifr.title = d.nome || 'Documento PDF';
      wrap.appendChild(ifr);
    } else if (url && /\.(jpe?g|png|gif|webp|avif)$/i.test(url)) {
      const img = document.createElement('img');
      img.src = url;
      img.alt = d.nome || 'Immagine';
      wrap.appendChild(img);
    } else {
      const alt = document.createElement('div');
      alt.className = 'sicv-empty';
      alt.innerHTML = `
        Anteprima non disponibile per <b>${escapeHtml(d.nome || '')}</b>.
        <div style="margin-top:8px;">
          ${url ? `<a class="sicv-pill" href="${url}" target="_blank" rel="noopener" data-tooltip="Apri originale">Apri</a>` : ''}
        </div>`;
      wrap.appendChild(alt);
    }

    const openDl = document.createElement('div');
    openDl.className = 'sicv-actions';
    openDl.innerHTML = `
      ${url ? `<a class="sicv-pill" href="${url}" target="_blank" rel="noopener" data-tooltip="Apri originale">Apri</a>` : ''}
      ${url ? `<a class="sicv-pill" href="${url}" download data-tooltip="Scarica file">Scarica</a>` : ''}
    `;

    stageEl.appendChild(actionsBar);
    stageEl.appendChild(wrap);
    stageEl.appendChild(openDl);
  }

  /* ========== Selezione ========== */
  function setActive(index, userInitiated) {
    if (index < 0 || index >= docs.length) return;
    activeIndex = index;

    thumbsEl.querySelectorAll('.sicv-thumb').forEach((el, i) => {
      if (i === activeIndex) {
        el.dataset.active = '1';
        el.setAttribute('tabindex', '0');
      } else {
        el.removeAttribute('data-active');
        el.setAttribute('tabindex', '-1');
      }
    });

    renderStage();
    persistActive();

    // Event hook verso la pagina
    try {
      root.dispatchEvent(new CustomEvent('sicviewer:select', { detail: docs[activeIndex] }));
    } catch {}

    if (userInitiated && typeof opts.onSelect === 'function') {
      try { opts.onSelect(docs[activeIndex]); } catch {}
    }

    // Autoscroll alla thumb attiva
    const activeThumb = thumbsEl.querySelector(`.sicv-thumb[data-index="${activeIndex}"]`);
    if (activeThumb) {
      activeThumb.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      activeThumb.focus({ preventScroll: true });
    }
  }

  function next(userInitiated) {
    if (!docs.length) return;
    const i = Math.min(docs.length - 1, activeIndex + 1);
    setActive(i, !!userInitiated);
  }

  function prev(userInitiated) {
    if (!docs.length) return;
    const i = Math.max(0, activeIndex - 1);
    setActive(i, !!userInitiated);
  }

  /* ========== Tastiera globale ========== */
  function onKey(e) {
    if (!docs.length) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); next(true); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(true); }
  }

  /* ========== Caricamento ========== */
  async function load() {
    try {
      const arr = await opts.provider();
      docs = Array.isArray(arr)
        ? arr
            .filter(v => v && v.url)
            .map(v => ({
              id: v.id,
              nome: String(v.nome || ''),
              ext: String(v.ext || '').toLowerCase(),
              url: sanitizeUrl(v.url),
              preview_url: sanitizeUrl(v.preview_url),
              uploaded_at: v.uploaded_at || null,
              scadenza: v.scadenza || null
            }))
        : [];

      renderThumbs();
      renderStage();

      if (docs.length) {
        const restored = restoreActive();
        setActive(Number.isFinite(restored) && restored >= 0 && restored < docs.length ? restored : 0, false);
      }
    } catch (e) {
      console.error('[sic-viewer] provider error:', e);
      stageEl.innerHTML = `<div class="sicv-empty">Impossibile caricare i documenti.</div>`;
    }
  }

  document.addEventListener('keydown', onKey);
  load();

  // API pubblica
  root.__sicViewer = { reload: load, setActive, next, prev };
}

window.initSicDocViewer = initSicDocViewer;
