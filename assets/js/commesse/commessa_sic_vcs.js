(function () {
  // === Utility identiche al VVCS ===
  async function ensureCustomFetch(ms = 5000) {
    const t = Date.now();
    while (typeof window.customFetch !== 'function') {
      if (Date.now() - t > ms) throw new Error('customFetch non disponibile');
      await new Promise(r => setTimeout(r, 50));
    }
  }
  const esc = (s) => (typeof window.escapeHtml === 'function'
    ? window.escapeHtml(String(s ?? ''))
    : (s || '').toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])));

  function cf(service, action, data = {}) {
    const tok = document.querySelector('meta[name="token-csrf"]')?.getAttribute('content') || '';
    const payload = tok ? { ...data, _csrf: tok } : data;
    return customFetch(service, action, payload);
  }

  const tabella = (window.__tabellaVCS || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();

  let cache = [];
  let searchTimer = null;
  const $ = (q) => document.querySelector(q);

  function openModal() { toggleModal('modalVCS', 'open'); }
  function closeModal() { toggleModal('modalVCS', 'close'); }

  function resetForm() {
    $('#vcsId').value = '';
    $('#modalTitleVCS').textContent = 'Nuovo VCS';
    for (const id of [
      'titolo','luogoRiunione','dataRiunione','oraRiunione',
      'committente','cantiereDi','lavoroDi','direttoreLavori','responsabileLavori','coordinatoreEsecuzione',
      'imprese','lavoratoriAutonomi','argomenti','decisioni','procedure',
      'oraFine','firmaCSE','partecipanti'
    ]) {
      const el = document.getElementById(id);
      if (el) el.value = '';
    }
  }

  function fillForm(item) {
    $('#vcsId').value = item.id || '';
    $('#modalTitleVCS').textContent = 'Modifica VCS';
    const d = item.data || {};
    const map = {
      titolo: 'titolo',
      luogo_riunione: 'luogoRiunione',
      data_riunione: 'dataRiunione',
      ora_riunione: 'oraRiunione',
      committente: 'committente',
      cantiere_di: 'cantiereDi',
      lavoro_di: 'lavoroDi',
      direttore_lavori: 'direttoreLavori',
      responsabile_lavori: 'responsabileLavori',
      coordinatore_esecuzione: 'coordinatoreEsecuzione',
      imprese: 'imprese',
      lavoratori_autonomi: 'lavoratoriAutonomi',
      argomenti: 'argomenti',
      decisioni: 'decisioni',
      procedure: 'procedure',
      ora_fine: 'oraFine',
      firma_cse: 'firmaCSE',
      partecipanti: 'partecipanti'
    };
    Object.keys(map).forEach(k => {
      const el = document.getElementById(map[k]);
      if (!el) return;
      el.value = (k in d ? String(d[k] ?? '') : (k === 'titolo' ? (item.titolo || '') : ''));
    });
  }

  function renderList() {
    const q = ($('#searchVCS')?.value || '').toLowerCase().trim();
    const list = $('#listVCS'); list.innerHTML = '';
    const items = cache
      .filter(it => !q || (String(it.titolo || '').toLowerCase().includes(q)))
      .sort((a, b) => (String(b.updated_at || b.created_at || '') > String(a.updated_at || a.created_at || '')) ? 1 : -1);

    if (!items.length) {
      list.innerHTML = '<div style="padding:12px;border:1px solid #eaeaea;border-radius:10px;color:#64748b;">Nessun verbale</div>';
      return;
    }

    items.forEach(it => {
      const row = document.createElement('div');
      row.className = 'doc-card';
      row.style.cssText = 'display:flex;align-items:center;gap:10px;border:1px solid #e4e4e4;border-radius:10px;padding:12px;background:#fff;';
      const when = esc(it.updated_at || it.created_at || '');
      row.innerHTML = `
        <div style="font-weight:700;min-width:60px;text-align:center;" data-tooltip="Tipo">VCS</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(it.titolo || 'Verbale')}</div>
          <div style="color:#64748b;font-size:.92em;">${when}</div>
        </div>
        <button class="action-icon btn-pdf" data-id="${esc(it.id)}" data-tooltip="Esporta PDF" aria-label="Esporta PDF" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/pdf.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-open" data-id="${esc(it.id)}" data-tooltip="Apri / Modifica" aria-label="Modifica" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/edit.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-del" data-id="${esc(it.id)}" data-tooltip="Elimina" aria-label="Elimina" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/delete.png" alt="" width="16" height="16">
        </button>
      `;
      list.appendChild(row);
    });
  }

  async function loadList() {
    await ensureCustomFetch();
    const res = await cf('commesse', 'listSicurezzaForms', { tabella, tipo: 'VCS', q: ($('#searchVCS')?.value || '') });
    cache = (res && res.success && Array.isArray(res.items)) ? res.items : [];
    renderList();
  }

  async function saveForm() {
    await ensureCustomFetch();
    const payload = {
      id: $('#vcsId').value ? Number($('#vcsId').value) : 0,
      tabella, tipo: 'VCS',
      titolo: String($('#titolo').value || '').trim(),
      data: {
        titolo: $('#titolo').value || '',
        luogo_riunione: $('#luogoRiunione').value || '',
        data_riunione: $('#dataRiunione').value || '',
        ora_riunione: $('#oraRiunione').value || '',
        committente: $('#committente').value || '',
        cantiere_di: $('#cantiereDi').value || '',
        lavoro_di: $('#lavoroDi').value || '',
        direttore_lavori: $('#direttoreLavori').value || '',
        responsabile_lavori: $('#responsabileLavori').value || '',
        coordinatore_esecuzione: $('#coordinatoreEsecuzione').value || '',
        imprese: $('#imprese').value || '',
        lavoratori_autonomi: $('#lavoratoriAutonomi').value || '',
        argomenti: $('#argomenti').value || '',
        decisioni: $('#decisioni').value || '',
        procedure: $('#procedure').value || '',
        ora_fine: $('#oraFine').value || '',
        firma_cse: $('#firmaCSE').value || '',
        partecipanti: $('#partecipanti').value || ''
      }
    };
    try { JSON.stringify(payload); } catch (e) { showToast?.('JSON non valido', 'error'); return; }

    const res = await cf('commesse', 'saveSicurezzaForm', payload);
    if (res && res.success) { closeModal(); showToast?.($('#vcsId').value ? 'Verbale aggiornato.' : 'Verbale creato.'); await loadList(); }
    else { showToast?.(res?.message || 'Errore salvataggio', 'error'); }
  }

  async function deleteForm(id) {
    await ensureCustomFetch();
    showConfirm?.('Eliminare definitivamente questo verbale?', async () => {
      const res = await cf('commesse', 'deleteSicurezzaForm', { id: Number(id), tabella });
      if (res && res.success) { showToast?.('Verbale eliminato.'); await loadList(); }
      else { showToast?.(res?.message || 'Errore eliminazione', 'error'); }
    });
  }

  // === Stampa: stesso stile/algoritmo del VVCS (SmartSplit) ===
  function buildVCSPrintHTML(dati, titoloDoc = 'Verbale VCS') {
    const safe = (s)=> window.escapeHtml ? escapeHtml(String(s??'')) : String(s??'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const V = (v)=> safe(v || '');

    return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>VCS - ${V(titoloDoc || dati.titolo || 'Verbale')}</title>
  <style>
    :root{ --brand:#1F5F8B; --ink:#1b2430; --muted:#58677a; --line:#dbe4ee; --bg:#f4f7fb; }
    @page{ size:A4; margin:0; }
    html,body{ height:100%; margin:0; padding:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    body{ background:var(--bg); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif; color:var(--ink); }
    .sheet{ width:210mm; min-height:297mm; margin:0 auto; background:#fff; box-shadow:0 8px 28px rgba(0,0,0,.12); }
    .sheet-inner{ padding:10mm 12mm; box-sizing:border-box; }

    .doc-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:8mm; border-bottom:2px solid var(--brand); padding-bottom:6mm; }
    .doc-title{ font-size:20pt; font-weight:700; color:var(--brand); margin:0; }
    .badge{ display:inline-block; padding:2px 8px; border-radius:999px; background:#e9f2fb; color:var(--brand); font-weight:700; font-size:9pt; margin-left:6px; }
    .doc-meta{ text-align:right; font-size:10pt; color:var(--muted); }

    .section{ margin:0 0 7mm; }
    .section-title{ font-size:12.5pt; color:var(--brand); margin:0 0 3.5mm; font-weight:700; break-after:avoid; }
    .kv{ border:1px solid var(--line); border-radius:8px; padding:4.5mm; background:#fff; break-inside:auto; page-break-inside:auto; -webkit-column-break-inside:auto; }
    .kv + .kv{ margin-top:4mm; }

    .field{ display:flex; flex-direction:column; }
    .label{ font-size:10pt; color:var(--muted); font-weight:700; margin:0 0 2.2mm; break-after:avoid; }
    .area{
      border:1px solid var(--line); border-radius:6px; padding:3.5mm;
      background:
        radial-gradient(circle at 0 100%, rgba(0,0,0,.18) .6px, transparent .7px) 0 22px/6px 22px repeat-x,
        radial-gradient(circle at 0 100%, rgba(0,0,0,.10) .6px, transparent .7px) 3px 44px/6px 22px repeat-x;
      font-size:11pt; line-height:1.35; white-space:pre-wrap; min-height:18mm;
      break-inside:auto; page-break-inside:auto; -webkit-column-break-inside:auto;
    }
    .vvcs-fragment{ margin-top:3mm; }
    .vvcs-fragment .label::after{ content:" (continua)"; font-weight:700; }

    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:4mm; }
    .signature{ display:grid; grid-template-columns:1fr 1fr; gap:10mm; margin-top:6mm; }
    .sign-box{ border-top:1px solid var(--line); padding-top:3mm; text-align:center; font-size:10pt; color:var(--muted); }

    p, .area { orphans:3; widows:3; }
    @media print{ body{ background:#fff; } .sheet{ box-shadow:none; } }
  </style>

  <script>
    // ===== SmartSplit (stesso di VVCS) =====
    (function(){
      function pxPerMm(){ return 96/25.4; }
      function getPageInnerHeightPx(){ const mm = 297 - (10+10); return mm * pxPerMm(); }
      function splitArea(area){
        const maxPageY = getPageInnerHeightPx();
        if (area.scrollHeight <= area.clientHeight && area.getBoundingClientRect().height <= maxPageY) return;
        const field = area.closest('.field'); if (!field) return;
        const labelEl = field.querySelector('.label'); const labelHTML = labelEl ? labelEl.innerHTML : '';
        const parentKv = field.closest('.kv') || field.parentElement;
        const originalText = area.textContent || '';
        const words = originalText.split(/(\\s+)/); let chunk = ''; area.textContent = '';

        function makeFragment(){ const wrap=document.createElement('div'); wrap.className='field vvcs-fragment';
          const lab=document.createElement('div'); lab.className='label'; lab.innerHTML=labelHTML;
          const box=document.createElement('div'); box.className=area.className; wrap.appendChild(lab); wrap.appendChild(box);
          parentKv.appendChild(wrap); return box; }

        function fits(el, safetyPx){ safetyPx = safetyPx || 10; const r=el.getBoundingClientRect();
          const top=r.top+window.scrollY; const bottom=top+r.height; const pageH=maxPageY; const pageStart=Math.floor(top/pageH)*pageH;
          return (bottom <= pageStart + pageH - safetyPx); }

        let i=0;
        for(; i<words.length; i++){ const test = chunk + words[i]; area.textContent = test; if(!fits(area,18)){ area.textContent = chunk.trimEnd(); break; } chunk = test; }
        while(i<words.length){ let box = makeFragment(); let buf=''; for(; i<words.length; i++){ const test=buf+words[i]; box.textContent=test;
            if(!fits(box,18)){ box.textContent=buf.trimEnd(); break; } buf=test; }
          if(buf.trim().length===0 && i<words.length){ box.textContent = words[i]; i++; }
        }
      }
      function runSmartSplit(){ document.querySelectorAll('.area').forEach(splitArea); }
      window.__vvcsSmartSplit = { runSmartSplit };
    })();
  </script>
</head>

<body>
  <div class="sheet"><div class="sheet-inner">

    <div class="doc-header">
      <h1 class="doc-title">Verbale Riunione di Coordinamento <span class="badge">VCS</span></h1>
      <div class="doc-meta">
        <div><strong>Titolo:</strong> ${V(titoloDoc || dati.titolo)}</div>
        <div><strong>Data:</strong> ${V(dati.data_riunione)} — <strong>Ora:</strong> ${V(dati.ora_riunione)}</div>
        <div><strong>Luogo:</strong> ${V(dati.luogo_riunione)}</div>
      </div>
    </div>

    <!-- A. Intestazione -->
    <div class="section">
      <h3 class="section-title">Intestazione</h3>
      <div class="kv grid-2">
        <div class="field"><div class="label">Committente</div><div class="area">${V(dati.committente)}</div></div>
        <div class="field"><div class="label">Cantiere di</div><div class="area">${V(dati.cantiere_di)}</div></div>
        <div class="field"><div class="label">Lavoro di</div><div class="area">${V(dati.lavoro_di)}</div></div>
        <div class="field"><div class="label">Direttore lavori</div><div class="area">${V(dati.direttore_lavori)}</div></div>
        <div class="field"><div class="label">Responsabile lavori</div><div class="area">${V(dati.responsabile_lavori)}</div></div>
        <div class="field"><div class="label">Coordinatore per l’esecuzione</div><div class="area">${V(dati.coordinatore_esecuzione)}</div></div>
      </div>
    </div>

    <!-- B. Presenze -->
    <div class="section">
      <h3 class="section-title">Imprese e Lavoratori Autonomi</h3>
      <div class="kv grid-2">
        <div class="field"><div class="label">Imprese</div><div class="area area-md">${V(dati.imprese)}</div></div>
        <div class="field"><div class="label">Lavoratori autonomi</div><div class="area area-md">${V(dati.lavoratori_autonomi)}</div></div>
      </div>
    </div>

    <!-- C. Argomenti/Decisioni/Procedure -->
    <div class="section">
      <h3 class="section-title">Argomenti discussi</h3>
      <div class="kv"><div class="field"><div class="label">Dettagli</div><div class="area area-lg">${V(dati.argomenti)}</div></div></div>
    </div>

    <div class="section">
      <h3 class="section-title">Decisioni e linee</h3>
      <div class="kv"><div class="field"><div class="label">Decisioni</div><div class="area area-lg">${V(dati.decisioni)}</div></div></div>
    </div>

    <div class="section">
      <h3 class="section-title">Procedure fino al prossimo incontro</h3>
      <div class="kv"><div class="field"><div class="label">Procedure</div><div class="area area-lg">${V(dati.procedure)}</div></div></div>
    </div>

    <!-- D. Chiusura -->
    <div class="section">
      <h3 class="section-title">Chiusura e firme</h3>
      <div class="kv grid-2">
        <div class="field"><div class="label">Ora fine riunione</div><div class="area">${V(dati.ora_fine)}</div></div>
        <div class="field"><div class="label">Firma CSE</div><div class="area">${V(dati.firma_cse)}</div></div>
      </div>
      <div class="kv" style="margin-top:4mm;">
        <div class="field"><div class="label">Partecipanti (firme / nominativi)</div><div class="area area-md">${V(dati.partecipanti)}</div></div>
      </div>

      <div class="signature">
        <div class="sign-box">Firma Coordinatore</div>
        <div class="sign-box">Firma Impresa / RLS</div>
      </div>
    </div>

  </div></div>

  <script>
    window.addEventListener('load', function(){
      try { window.__vvcsSmartSplit.runSmartSplit(); } catch(e) {}
      setTimeout(function(){
        window.print();
        setTimeout(function(){ window.close(); }, 350);
      }, 50);
    });
  </script>
</body>
</html>`;
  }

  function exportVCSRecordAsPDF(form) {
    const d = form?.data || {};
    const titolo = form?.titolo || d.titolo || 'Verbale';
    const html = buildVCSPrintHTML(d, titolo);
    const win = window.open('', '_blank');
    if (!win) { showToast?.('Popup bloccato: abilita le popup per esportare.', 'info'); return; }
    win.document.open('text/html'); win.document.write(html); win.document.close();
  }

  function exportVCSAsPDF() {
    const v = (id) => (document.getElementById(id)?.value || '').trim();
    const d = {
      titolo: v('titolo'),
      luogo_riunione: v('luogoRiunione'),
      data_riunione: v('dataRiunione'),
      ora_riunione: v('oraRiunione'),
      committente: v('committente'),
      cantiere_di: v('cantiereDi'),
      lavoro_di: v('lavoroDi'),
      direttore_lavori: v('direttoreLavori'),
      responsabile_lavori: v('responsabileLavori'),
      coordinatore_esecuzione: v('coordinatoreEsecuzione'),
      imprese: v('imprese'),
      lavoratori_autonomi: v('lavoratoriAutonomi'),
      argomenti: v('argomenti'),
      decisioni: v('decisioni'),
      procedure: v('procedure'),
      ora_fine: v('oraFine'),
      firma_cse: v('firmaCSE'),
      partecipanti: v('partecipanti')
    };
    const html = buildVCSPrintHTML(d, d.titolo || 'Verbale');
    const win = window.open('', '_blank');
    if (!win) { showToast?.('Popup bloccato: abilita le popup per esportare.', 'info'); return; }
    win.document.open('text/html'); win.document.write(html); win.document.close();
  }

  // === bootstrap (identico a VVCS) ===
  document.addEventListener('DOMContentLoaded', async () => {
    try {
      await loadList();
      $('#btnNewVCS')?.addEventListener('click', () => { resetForm(); openModal(); });
      $('#btnSaveVCS')?.addEventListener('click', saveForm);
      $('#searchVCS')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 220); });
      $('#listVCS').addEventListener('click', async (e) => {
        const p = e.target.closest('.btn-pdf');
        const o = e.target.closest('.btn-open');
        const d = e.target.closest('.btn-del');

        if (p) {
          const id = Number(p.getAttribute('data-id'));
          try {
            await ensureCustomFetch();
            const res = await cf('commesse','getSicurezzaForm',{ id, tabella });
            if (res?.success && res.form) exportVCSRecordAsPDF(res.form);
            else showToast?.(res?.message || 'Errore caricamento verbale', 'error');
          } catch (err) { console.error(err); showToast?.('Errore esportazione PDF', 'error'); }
          return;
        }

        if (o) {
          const id = Number(o.getAttribute('data-id'));
          await ensureCustomFetch();
          const res = await cf('commesse','getSicurezzaForm',{ id, tabella });
          if (res?.success && res.form) { resetForm(); fillForm(res.form); openModal(); }
          else { showToast?.(res?.message || 'Errore caricamento verbale', 'error'); }
          return;
        }

        if (d) {
          const id = Number(d.getAttribute('data-id'));
          deleteForm(id);
        }
      });

      // ESC gestito dal KeyboardManager globale (main_core.js)
    } catch (err) { console.error('[VCS] bootstrap error', err); showToast?.('Errore inizializzazione VCS', 'error'); }
  });
})();
