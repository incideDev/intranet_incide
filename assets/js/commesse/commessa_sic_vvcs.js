(function () {
  'use strict';

  // prendo le utilità comuni; fallback locale per esc se manca
  const { esc, renderDocList } = (window.sicCommon || {});
  const localEsc = (s) => (typeof window.escapeHtml === 'function'
    ? window.escapeHtml(String(s ?? ''))
    : (s ?? '').toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])));
  const ESC = esc || localEsc;

  // tabella passata dal PHP
  const tabella = (window.__tabellaVVCS || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();

  let cache = [];
  let searchTimer = null;
  const $ = (q) => document.querySelector(q);

  function openModal() { toggleModal('modalVVCS', 'open'); }
  function closeModal() { toggleModal('modalVVCS', 'close'); }

  function resetForm() {
    $('#vvcsId').value = '';
    $('#modalTitle').textContent = 'Nuovo VVCS';
    for (const id of [
      'titolo', 'dataVisita', 'coordinatore', 'luogo', 'committente', 'lavoroDi',
      'impresePresenti', 'lavoratoriAutonomi', 'fasi',
      'conformita', 'nonConformita', 'notePrescrizioni', 'scadenzaInterventi',
      'pericolo', 'osservazioni', 'dataChiusura', 'firmaCoord', 'firmePresaVisione'
    ]) {
      const el = document.getElementById(id);
      if (el) el.value = '';
    }
  }

  function fillForm(item) {
    $('#vvcsId').value = item.id || '';
    $('#modalTitle').textContent = 'Modifica VVCS';
    const d = item.data || {};
    const map = {
      titolo: 'titolo', data_visita: 'dataVisita', coordinatore: 'coordinatore', luogo: 'luogo',
      committente: 'committente', lavoro_di: 'lavoroDi',
      imprese_presenti: 'impresePresenti', lavoratori_autonomi: 'lavoratoriAutonomi',
      fasi: 'fasi',
      conformita: 'conformita', non_conformita: 'nonConformita',
      note_prescrizioni: 'notePrescrizioni', scadenza_interventi: 'scadenzaInterventi',
      pericolo: 'pericolo',
      osservazioni: 'osservazioni', data_chiusura: 'dataChiusura', firma_coord: 'firmaCoord',
      firme_presa_visione: 'firmePresaVisione'
    };
    Object.keys(map).forEach(k => {
      const el = document.getElementById(map[k]);
      if (!el) return;
      el.value = (k in d ? String(d[k] ?? '') : (k === 'titolo' ? (item.titolo || '') : ''));
    });
  }

  function renderList() {
    const q = ($('#search')?.value || '').toLowerCase().trim();
    const list = $('#list'); list.innerHTML = '';
    const items = cache
      .filter(it => !q || (String(it.titolo || '').toLowerCase().includes(q)))
      .sort((a, b) => (String(b.updated_at || b.created_at || '') > String(a.updated_at || a.created_at || '')) ? 1 : -1);

    if (typeof renderDocList === 'function') {
      renderDocList(list, items, 'VVCS');
      return;
    }

    // fallback (se per caso non hai caricato main_core.js)
    if (!items.length) {
      list.innerHTML = '<div style="padding:12px;border:1px solid #eaeaea;border-radius:10px;color:#64748b;">Nessun verbale</div>';
      return;
    }
    items.forEach(it => {
      const row = document.createElement('div');
      row.className = 'doc-card';
      row.style.cssText = 'display:flex;align-items:center;gap:10px;border:1px solid #e4e4e4;border-radius:10px;padding:12px;background:#fff;';
      const when = ESC(it.updated_at || it.created_at || '');
      row.innerHTML = `
        <div style="font-weight:700;min-width:60px;text-align:center;" data-tooltip="Tipo">VVCS</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${ESC(it.titolo || 'Verbale')}</div>
          <div style="color:#64748b;font-size:.92em;">${when}</div>
        </div>
        <button class="action-icon btn-pdf" data-id="${ESC(it.id)}" data-tooltip="Esporta PDF" aria-label="Esporta PDF" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/pdf.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-open" data-id="${ESC(it.id)}" data-tooltip="Apri / Modifica" aria-label="Modifica" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/edit.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-del" data-id="${ESC(it.id)}" data-tooltip="Elimina" aria-label="Elimina" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/delete.png" alt="" width="16" height="16">
        </button>`;
      list.appendChild(row);
    });
  }

  async function loadList() {
    const res = await customFetch('commesse', 'listSicurezzaForms', {
      tabella, tipo: 'VVCS', q: ($('#search')?.value || '')
    });
    cache = (res && res.success && Array.isArray(res.items)) ? res.items : [];
    renderList();
  }

  async function saveForm() {
    const payload = {
      id: $('#vvcsId').value ? Number($('#vvcsId').value) : 0,
      tabella, tipo: 'VVCS',
      titolo: String($('#titolo').value || '').trim(),
      data: {
        titolo: $('#titolo').value || '',
        data_visita: $('#dataVisita').value || '',
        coordinatore: $('#coordinatore').value || '',
        luogo: $('#luogo').value || '',
        committente: $('#committente').value || '',
        lavoro_di: $('#lavoroDi').value || '',
        imprese_presenti: $('#impresePresenti').value || '',
        lavoratori_autonomi: $('#lavoratoriAutonomi').value || '',
        fasi: $('#fasi').value || '',
        conformita: $('#conformita').value || '',
        non_conformita: $('#nonConformita').value || '',
        note_prescrizioni: $('#notePrescrizioni').value || '',
        scadenza_interventi: $('#scadenzaInterventi').value || '',
        pericolo: $('#pericolo').value || '',
        osservazioni: $('#osservazioni').value || '',
        data_chiusura: $('#dataChiusura').value || '',
        firma_coord: $('#firmaCoord').value || '',
        firme_presa_visione: $('#firmePresaVisione').value || ''
      }
    };
    try { JSON.stringify(payload); } catch { showToast?.('JSON non valido', 'error'); return; }

    const res = await customFetch('commesse', 'saveSicurezzaForm', payload);
    if (res && res.success) { closeModal(); showToast?.($('#vvcsId').value ? 'Verbale aggiornato.' : 'Verbale creato.'); await loadList(); }
    else { showToast?.(res?.message || 'Errore salvataggio', 'error'); }
  }

  async function deleteForm(id) {
    showConfirm?.('Eliminare definitivamente questo verbale?', async () => {
      const res = await customFetch('commesse', 'deleteSicurezzaForm', { id: Number(id), tabella });
      if (res && res.success) { showToast?.('Verbale eliminato.'); await loadList(); }
      else { showToast?.(res?.message || 'Errore eliminazione', 'error'); }
    });
  }

  // ======= PRINT (mantengo SOLO la versione buona + SmartSplit) =======
  function buildVVCSPrintHTML(dati, titoloDoc = 'Verbale') {
    const safe = (s) => (typeof window.escapeHtml === 'function'
      ? escapeHtml(String(s ?? ''))
      : String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])));
    const V = (v) => safe(v || '');

    return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>VVCS - ${V(titoloDoc || dati.titolo || 'Verbale')}</title>
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
        function makeFragment(){
          const wrap=document.createElement('div'); wrap.className='field vvcs-fragment';
          const lab=document.createElement('div'); lab.className='label'; lab.innerHTML=labelHTML;
          const box=document.createElement('div'); box.className=area.className;
          wrap.appendChild(lab); wrap.appendChild(box); parentKv.appendChild(wrap); return box;
        }
        function fits(el, safetyPx){ safetyPx=safetyPx||10; const r=el.getBoundingClientRect();
          const top=r.top+window.scrollY; const bottom=top+r.height; const pageH=maxPageY; const pageStart=Math.floor(top/pageH)*pageH;
          return (bottom <= pageStart + pageH - safetyPx);
        }
        let i=0;
        for(; i<words.length; i++){ const test=chunk+words[i]; area.textContent=test; if(!fits(area,18)){ area.textContent=chunk.trimEnd(); break; } chunk=test; }
        while(i<words.length){ let box=makeFragment(); let buf=''; for(; i<words.length; i++){ const test=buf+words[i]; box.textContent=test; if(!fits(box,18)){ box.textContent=buf.trimEnd(); break; } buf=test; }
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
      <h1 class="doc-title">Verbale Visita in Cantiere <span class="badge">VVCS</span></h1>
      <div class="doc-meta">
        <div><strong>Titolo:</strong> ${V(titoloDoc || dati.titolo)}</div>
        <div><strong>Data visita:</strong> ${V(dati.data_visita)}</div>
      </div>
    </div>

    <div class="section">
      <div class="kv" style="margin:0 0 4mm;">
        <div class="field">
          <div class="label">Riferimenti normativi</div>
          <div class="area">
            Verbale redatto ai sensi dell’art. 92, comma 1, lett. a), D.Lgs. 9 aprile 2008, n. 81 e s.m.i., per la verifica del rispetto delle norme di sicurezza, dell’applicazione del PSC e delle relative procedure di lavoro.
          </div>
        </div>
      </div>
      <h3 class="section-title">Intestazione</h3>
      <div class="kv grid-2">
        <div class="field"><div class="label">Coordinatore</div><div class="area">${V(dati.coordinatore)}</div></div>
        <div class="field"><div class="label">Luogo cantiere</div><div class="area">${V(dati.luogo)}</div></div>
        <div class="field"><div class="label">Committente</div><div class="area">${V(dati.committente)}</div></div>
        <div class="field"><div class="label">Lavoro di</div><div class="area">${V(dati.lavoro_di)}</div></div>
      </div>
    </div>

    <div class="section">
      <h3 class="section-title">Presenze in cantiere</h3>
      <div class="kv grid-2">
        <div class="field"><div class="label">Imprese presenti</div><div class="area area-md">${V(dati.imprese_presenti)}</div></div>
        <div class="field"><div class="label">Lavoratori autonomi</div><div class="area area-md">${V(dati.lavoratori_autonomi)}</div></div>
      </div>
      <div class="kv" style="margin-top:4mm;">
        <div class="field"><div class="label">Fasi di lavoro in svolgimento</div><div class="area area-lg">${V(dati.fasi)}</div></div>
      </div>
    </div>

    <div class="section">
      <h3 class="section-title">Esito verifica</h3>
      <div class="kv">
        <div class="field"><div class="label">Condizioni di CONFORMITÀ</div><div class="area area-lg">${V(dati.conformita)}</div></div>
      </div>
      <div class="kv" style="margin-top:4mm;">
        <div class="field"><div class="label">Condizioni di NON CONFORMITÀ</div><div class="area area-lg">${V(dati.non_conformita)}</div></div>
      </div>
      <div class="kv" style="margin-top:4mm;">
        <div class="field"><div class="label">Prescrizioni / Ordini</div><div class="area area-md">${V(dati.note_prescrizioni)}</div></div>
        <div class="grid-2" style="margin-top:4mm;">
          <div class="field"><div class="label">Scadenza interventi</div><div class="area">${V(dati.scadenza_interventi)}</div></div>
          <div></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h3 class="section-title">Pericolo grave e imminente</h3>
      <div class="kv">
        <div class="field"><div class="label">Descrizione</div><div class="area area-lg">${V(dati.pericolo)}</div></div>
      </div>
    </div>

    <div class="section">
      <h3 class="section-title">Osservazioni e chiusura</h3>
      <div class="kv">
        <div class="field"><div class="label">Ulteriori osservazioni</div><div class="area area-md">${V(dati.osservazioni)}</div></div>
      </div>
      <div class="kv grid-2" style="margin-top:4mm;">
        <div class="field"><div class="label">Data chiusura</div><div class="area">${V(dati.data_chiusura)}</div></div>
        <div class="field"><div class="label">Firma Coordinatore</div><div class="area">${V(dati.firma_coord)}</div></div>
      </div>
      <div class="kv" style="margin-top:4mm;">
        <div class="field"><div class="label">Presa visione (firme / nominativi)</div><div class="area area-md">${V(dati.firme_presa_visione)}</div></div>
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
      setTimeout(function(){ window.print(); setTimeout(function(){ window.close(); }, 350); }, 50);
    });
  </script>
</body>
</html>`;
  }

  function exportVVCSRecordAsPDF(form) {
    const d = form?.data || {};
    const titolo = form?.titolo || d.titolo || 'Verbale';
    const html = buildVVCSPrintHTML(d, titolo);
    const win = window.open('', '_blank');
    if (!win) { showToast?.('Popup bloccato: abilita le popup per esportare.', 'info'); return; }
    win.document.open('text/html'); win.document.write(html); win.document.close();
  }

  function exportVVCSAsPDF() {
    const v = (id) => (document.getElementById(id)?.value || '').trim();
    const dati = {
      titolo: v('titolo'),
      data_visita: v('dataVisita'),
      coordinatore: v('coordinatore'),
      luogo: v('luogo'),
      committente: v('committente'),
      lavoro_di: v('lavoroDi'),
      imprese_presenti: v('impresePresenti'),
      lavoratori_autonomi: v('lavoratoriAutonomi'),
      fasi: v('fasi'),
      conformita: v('conformita'),
      non_conformita: v('nonConformita'),
      note_prescrizioni: v('notePrescrizioni'),
      scadenza_interventi: v('scadenzaInterventi'),
      pericolo: v('pericolo'),
      osservazioni: v('osservazioni'),
      data_chiusura: v('dataChiusura'),
      firma_coord: v('firmaCoord'),
      firme_presa_visione: v('firmePresaVisione')
    };
    const html = buildVVCSPrintHTML(dati, dati.titolo || 'Verbale');
    const win = window.open('', '_blank');
    if (!win) { showToast?.('Popup bloccato: abilita le popup per esportare.', 'info'); return; }
    win.document.open('text/html'); win.document.write(html); win.document.close();
  }

  // bootstrap
  document.addEventListener('DOMContentLoaded', () => {
    loadList();

    const titoloEl = () => document.getElementById('titolo');
    const btnSave = document.getElementById('btnSave');
    const btnExport = document.getElementById('btnExportPDF');

    function validateForm() {
      const ok = !!(titoloEl()?.value || '').trim();
      if (btnSave) btnSave.disabled = !ok;
      return ok;
    }

    function showModal() {
      openModal();
      if (btnExport) btnExport.style.display = '';
      setTimeout(() => titoloEl()?.focus(), 50);
      validateForm();
    }
    function hideModal() {
      closeModal();
      if (btnExport) btnExport.style.display = 'none';
    }

    document.getElementById('btnNew')?.addEventListener('click', () => { resetForm(); showModal(); });
    btnSave?.addEventListener('click', () => { if (validateForm()) saveForm(); });
    btnExport?.addEventListener('click', exportVVCSAsPDF);

    document.getElementById('search')?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(loadList, 220);
    });

    document.getElementById('list').addEventListener('click', async (e) => {
      const p = e.target.closest('.btn-pdf');
      const o = e.target.closest('.btn-open');
      const d = e.target.closest('.btn-del');

      if (p) {
        const id = Number(p.getAttribute('data-id'));
        try {
          const res = await customFetch('commesse', 'getSicurezzaForm', { id, tabella });
          if (res?.success && res.form) exportVVCSRecordAsPDF(res.form);
          else showToast?.(res?.message || 'Errore caricamento verbale', 'error');
        } catch (err) { console.error(err); showToast?.('Errore esportazione PDF', 'error'); }
        return;
      }

      if (o) {
        const id = Number(o.getAttribute('data-id'));
        const res = await customFetch('commesse', 'getSicurezzaForm', { id, tabella });
        if (res?.success && res.form) { resetForm(); fillForm(res.form); showModal(); }
        else { showToast?.(res?.message || 'Errore caricamento verbale', 'error'); }
        return;
      }

      if (d) {
        const id = Number(d.getAttribute('data-id'));
        deleteForm(id);
      }
    });

    // live validation
    document.getElementById('vvcsForm')?.addEventListener('input', (e) => {
      if (e.target && e.target.id === 'titolo') validateForm();
    });

    // ESC gestito dal KeyboardManager globale (main_core.js)
  });
})();
