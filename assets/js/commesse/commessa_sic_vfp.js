// /assets/js/commesse/commessa_sic_vfp.js
(function () {
  const ctx = (window.__VFP_CTX__ || {});
  const tabella = (ctx.tabella || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();
  const aziendaId = Number(ctx.aziendaId || 0);

  const COLS = [
    { code: 'ci', label: 'C.I.' },
    { code: 'is', label: 'I.S.' },
    { code: 'cons_dpi', label: 'Cons. DPI' },
    { code: 'ps', label: 'P.S.' },
    { code: 'f_generale', label: 'F. Generale' },
    { code: 'r_basso', label: 'R. Basso (4 h)' },
    { code: 'r_medio', label: 'R. Medio (8 h)' },
    { code: 'r_alto', label: 'R. Alto (12 h)' },
    { code: 'preposto', label: 'Preposto' },
    { code: 'dirigente', label: 'Dirigente' },
    { code: 'ddl', label: 'DDL' },
    { code: 'rspp', label: 'RSPP' },
    { code: 'rls', label: 'RLS' },
    { code: 'csp_cse', label: 'CSP/CSE' },
    { code: 'primo_socc', label: 'Primo Socc.' },
    { code: 'antincendio', label: 'Antincendio' },
    { code: 'lavori_quota', label: 'Lavori in quota' },
    { code: 'dpi3cat', label: 'DPI III°Cat' },
    { code: 'amb_conf', label: 'Amb. Conf.' },
    { code: 'pimus', label: 'PiMUS' },
    { code: 'ple', label: 'PLE' }
  ];
  const ALL_COL_CODES = COLS.map(c => c.code);

  // --- Gruppi per il Column Manager
  const COL_GROUPS = {
    doc:      ['ci','is'],
    base:     ['f_generale','cons_dpi','ps'],
    rischio:  ['r_basso','r_medio','r_alto'],
    ruoli:    ['preposto','dirigente','ddl','rspp','rls','csp_cse'],
    abilitaz: ['primo_socc','antincendio','lavori_quota','dpi3cat','amb_conf','pimus','ple']
  };
  const GROUP_LABEL = { doc:'Documenti', base:'Formazione base', rischio:'Rischio', ruoli:'Ruoli', abilitaz:'Abilitazioni' };

  // --- Util
  const $  = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));

  function todayStr() {
    const d = new Date();
    const m = ('' + (d.getMonth() + 1)).padStart(2, '0');
    const day = ('' + d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
  }

  function markExpired(td) {
    const inp = $('input[type="date"]', td);
    if (!inp || !inp.value) { td.classList.remove('vfp-expired'); return; }
    td.classList.toggle('vfp-expired', inp.value < todayStr());
  }

  async function ensureCustomFetch(maxMs = 5000) {
    const t0 = Date.now();
    while (typeof window.customFetch !== 'function') {
      if (Date.now() - t0 > maxMs) throw new Error('customFetch non disponibile');
      await new Promise(r => setTimeout(r, 50));
    }
  }

  // Normalizza YYYY-MM-DD; scarta anni fuori range
  function normalizeDateStr(s) {
    if (!s) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return '';
    const y = +m[1], mo = +m[2], d = +m[3];
    if (y < 1900 || y > 2100) return '';
    if (mo < 1 || mo > 12) return '';
    if (d < 1 || d > 31) return '';
    return `${m[1]}-${m[2]}-${m[3]}`;
  }

  function makePosizioneSelect(value) {
    const wrap = document.createElement('div');
    wrap.className = 'vfp-pos-wrap';
    const sel = document.createElement('select');
    sel.innerHTML = `
      <option value="">—</option>
      <option value="regolare">Regolare</option>
      <option value="irregolare">Irregolare</option>
    `;
    sel.value = (value || '').toLowerCase();
    sel.addEventListener('change', onPosizioneChanged);
    wrap.appendChild(sel);

    const pill = document.createElement('span');
    pill.className = 'pill-pos';
    wrap.appendChild(pill);

    updatePill(pill, sel.value);
    return wrap;
  }

  function updatePill(pill, val) {
    pill.textContent = val ? (val.charAt(0).toUpperCase() + val.slice(1)) : '—';
    pill.classList.remove('pill-regolare', 'pill-irregolare');
    if (val === 'regolare') pill.classList.add('pill-regolare');
    if (val === 'irregolare') pill.classList.add('pill-irregolare');
  }

  async function onPosizioneChanged(e) {
    const sel = e.target;
    const tr = sel.closest('tr[data-row-id]');
    if (!tr) return;
    const rowId = Number(tr.dataset.rowId);
    const val = sel.value || null;

    const pill = sel.parentElement.querySelector('.pill-pos');
    updatePill(pill, val);

    await saveRowField(rowId, 'posizione', val);
  }

  async function onUnilavChanged(e) {
    const inp = e.target;
    const tr = inp.closest('tr[data-row-id]');
    if (!tr) return;
    const rowId = Number(tr.dataset.rowId);
    await saveRowField(rowId, 'unilav', (inp.value || '').trim() || null);
  }

  async function onTextChanged(e) {
    const inp = e.target;
    const tr = inp.closest('tr[data-row-id]');
    if (!tr) return;
    const rowId = Number(tr.dataset.rowId);
    const field = inp.dataset.field;
    const value = (inp.value || '').trim() || null;
    await saveRowField(rowId, field, value);
  }

  async function onDateChanged(e) {
    const inp = e.target;
    const td = inp.closest('td');
    const tr = inp.closest('tr[data-row-id]');
    if (!tr) return;
    const rowId = Number(tr.dataset.rowId);
    const col = inp.dataset.col;

    const normalized = normalizeDateStr(inp.value || '');
    if (normalized !== inp.value) {
      inp.value = normalized;
      if (inp.value === '' && typeof window.showToast === 'function') {
        showToast('Data non valida. Usa formato YYYY-MM-DD (1900–2100).', 'warning');
      }
    }

    markExpired(td);
    await saveVfpCell(rowId, col, inp.value || null);
  }

  // ===== API (customFetch only) =====
  async function saveRowField(rowId, field, value) {
    try {
      await ensureCustomFetch();
      const payload = { tabella, azienda_id: aziendaId, row_id: rowId, field, value };
      const res = await customFetch('commesse', 'saveRowField', payload);
      if (!res || res.success !== true) {
        console.warn('[VFP] saveRowField fallita', res);
        showToast?.('Salvataggio non riuscito', 'error');
      }
      return res;
    } catch (err) {
      console.error('[vfp] saveRowField error', err);
      showToast?.('Errore di rete', 'error');
      return { success: false, error: String(err) };
    }
  }

  async function saveVfpCell(rowId, colCode, valueDate) {
    try {
      await ensureCustomFetch();
      const payload = { tabella, azienda_id: aziendaId, row_id: rowId, col_code: colCode, value_date: valueDate };
      const res = await customFetch('commesse', 'saveVfpCell', payload);
      if (!res || res.success !== true) {
        console.warn('[VFP] saveVfpCell fallita', res);
        showToast?.('Salvataggio non riuscito', 'error');
      }
      return res;
    } catch (err) {
      console.error('[vfp] saveVfpCell error', err);
      showToast?.('Errore di rete', 'error');
      return { success: false, error: String(err) };
    }
  }

  // ===== Column Manager =====
  const VIS_KEY   = `vfp_cols_visible_${tabella}_${aziendaId}`;
  const OPT_KEY   = `vfp_opts_${tabella}_${aziendaId}`;
  function loadVisible() {
    try { const v = JSON.parse(localStorage.getItem(VIS_KEY) || 'null'); if (Array.isArray(v) && v.length) return new Set(v); } catch{}
    return new Set([...COL_GROUPS.doc, ...COL_GROUPS.base, ...COL_GROUPS.rischio]); // default
  }
  function saveVisible(set){ localStorage.setItem(VIS_KEY, JSON.stringify([...set])); }
  function loadOpts(){
    try { return Object.assign({ hideEmpty:true, compact:false }, JSON.parse(localStorage.getItem(OPT_KEY) || '{}')); }
    catch{ return { hideEmpty:true, compact:false }; }
  }
  function saveOpts(o){ localStorage.setItem(OPT_KEY, JSON.stringify(o)); }

  let visibleCols = loadVisible();
  let opts = loadOpts();

  function countFilledByColumn() {
    const res = Object.fromEntries(ALL_COL_CODES.map(c=>[c,0]));
    $$('#vfp-table tbody tr').forEach(tr=>{
      COLS.forEach((c,i)=>{
        const td = tr.children[4 + i];
        const v = $('input[type="date"]', td)?.value || '';
        if (v) res[c.code] += 1;
      });
    });
    return res;
  }

  function computeHiddenBecauseEmpty() {
    if (!opts.hideEmpty) return new Set();
    const counts = countFilledByColumn();
    const hidden = new Set();
    ALL_COL_CODES.forEach(code=>{
      if ((counts[code]||0) === 0) hidden.add(code);
    });
    return hidden;
  }

  function applyColumnVisibility(){
    const header = document.querySelector('#vfp-table thead tr');
    if (!header) return;
    const ths = Array.from(header.children);
    const baseOffset = 4; // Cognome, Nome, Posizione, UNILAV
    const mapIndex = {}; COLS.forEach((c, i)=> mapIndex[c.code] = baseOffset + i);

    const hiddenEmpty = computeHiddenBecauseEmpty();

    // header
    Object.entries(mapIndex).forEach(([code, idx])=>{
      const th = ths[idx];
      const isVisible = visibleCols.has(code) && !hiddenEmpty.has(code);
      if (th) th.style.display = isVisible ? '' : 'none';
    });

    // body
    $$('#vfp-table tbody tr').forEach(tr=>{
      Object.entries(mapIndex).forEach(([code, idx])=>{
        const td = tr.children[idx];
        const isVisible = visibleCols.has(code) && !hiddenEmpty.has(code);
        if (td) td.style.display = isVisible ? '' : 'none';
      });
    });

    // hint “nascondi vuote”
    const hint = document.getElementById('vfp-colhint');
    if (hint) hint.textContent = opts.hideEmpty ? 'Colonne senza dati nascoste' : '';
  }

  function syncChecks(wrap){
    wrap.querySelectorAll('.col-toggle').forEach(ck=>{
      ck.checked = visibleCols.has(ck.dataset.col);
    });
    wrap.querySelectorAll('.grp-toggle').forEach(gt=>{
      const list = COL_GROUPS[gt.dataset.group] || [];
      const all = list.every(c=>visibleCols.has(c));
      const some = !all && list.some(c=>visibleCols.has(c));
      gt.checked = all; gt.indeterminate = some;
    });
    wrap.querySelector('#vfp-opt-empty')?.setAttribute('aria-checked', String(!!opts.hideEmpty));
    wrap.querySelector('#vfp-opt-empty')?.classList.toggle('on', !!opts.hideEmpty);
    wrap.querySelector('#vfp-opt-compact')?.setAttribute('aria-checked', String(!!opts.compact));
    wrap.querySelector('#vfp-opt-compact')?.classList.toggle('on', !!opts.compact);
  }

  function renderColumnManager() {
    const toolbar = document.querySelector('.vfp-toolbar');
    if (!toolbar || document.getElementById('vfp-colmgr-root')) return;

    const wrap = document.createElement('div');
    wrap.className = 'vfp-colmgr';
    wrap.id = 'vfp-colmgr-root';
    wrap.innerHTML = `
      <button type="button" class="vfp-btn" id="vfp-colmgr-btn" data-tooltip="Mostra/Nascondi colonne">Colonne</button>
      <div class="vfp-colmgr-pop">
        ${Object.entries(COL_GROUPS).map(([k, list]) => {
          const all = list.every(c => visibleCols.has(c));
          const some = !all && list.some(c => visibleCols.has(c));
          const indet = some ? 'data-indeterminate="1"' : '';
          const label = GROUP_LABEL[k] || k;
          const colsHtml = list.map(code=>{
            const colLabel = (COLS.find(x=>x.code===code)?.label)||code;
            const ck = visibleCols.has(code)?'checked':'';
            return `<label><input type="checkbox" class="col-toggle" data-col="${code}" ${ck}> ${colLabel}</label>`;
          }).join('');
          return `
            <div class="grp">
              <label class="grp-title">
                <input type="checkbox" class="grp-toggle" data-group="${k}" ${all?'checked':''} ${indet}>
                ${label}
              </label>
              <div class="grp-cols">${colsHtml}</div>
            </div>
          `;
        }).join('')}
        <div class="mgr-actions">
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" class="vfp-btn vfp-btn-light" id="vfp-show-min">Minimo</button>
            <button type="button" class="vfp-btn vfp-btn-light" id="vfp-show-all">Tutte</button>
          </div>
          <div style="display:flex;gap:12px;align-items:center;">
            <button type="button" class="vfp-btn vfp-btn-light" id="vfp-opt-empty" aria-checked="${opts.hideEmpty?'true':'false'}">Nascondi vuote</button>
            <button type="button" class="vfp-btn" id="vfp-opt-compact" aria-checked="${opts.compact?'true':'false'}">Compatta</button>
          </div>
        </div>
        <div id="vfp-colhint" class="vfp-colhint"></div>
      </div>
    `;
    toolbar.appendChild(wrap);

    const btn = wrap.querySelector('#vfp-colmgr-btn');
    const pop = wrap.querySelector('.vfp-colmgr-pop');
    btn.addEventListener('click', ()=> pop.classList.toggle('open'));
    document.addEventListener('click', (e)=> { if (!wrap.contains(e.target)) pop.classList.remove('open'); });
    wrap.querySelectorAll('input[data-indeterminate="1"]').forEach(el=> { el.indeterminate = true; });

    wrap.addEventListener('change', (e)=>{
      const t = e.target;
      if (t.classList.contains('col-toggle')) {
        const code = t.dataset.col;
        t.checked ? visibleCols.add(code) : visibleCols.delete(code);
        saveVisible(visibleCols); applyColumnVisibility();
        syncChecks(wrap);
      }
      if (t.classList.contains('grp-toggle')) {
        const g = t.dataset.group;
        const list = COL_GROUPS[g] || [];
        if (t.checked){ list.forEach(c=>visibleCols.add(c)); }
        else { list.forEach(c=>visibleCols.delete(c)); }
        saveVisible(visibleCols); applyColumnVisibility(); syncChecks(wrap);
        t.indeterminate = false;
      }
    });

    wrap.querySelector('#vfp-show-all')?.addEventListener('click',()=>{
      visibleCols = new Set(ALL_COL_CODES);
      saveVisible(visibleCols); applyColumnVisibility(); syncChecks(wrap);
    });
    wrap.querySelector('#vfp-show-min')?.addEventListener('click',()=>{
      visibleCols = new Set([...COL_GROUPS.doc, ...COL_GROUPS.base]);
      saveVisible(visibleCols); applyColumnVisibility(); syncChecks(wrap);
    });
    wrap.querySelector('#vfp-opt-empty')?.addEventListener('click',()=>{
      opts.hideEmpty = !opts.hideEmpty; saveOpts(opts);
      applyColumnVisibility(); syncChecks(wrap);
    });
    wrap.querySelector('#vfp-opt-compact')?.addEventListener('click',()=>{
      opts.compact = !opts.compact; saveOpts(opts);
      document.querySelector('.commessa-sic-vfp')?.classList.toggle('vfp-compact', !!opts.compact);
      syncChecks(wrap);
    });

    syncChecks(wrap);
    applyColumnVisibility();
  }

  function enableStickyFirstCols(){
    document.querySelector('#vfp-table')?.classList.add('vfp-freeze');
  }

  // ===== Rendering =====
  function renderTable(data) {
    const tbody = $('#vfp-table tbody');
    tbody.innerHTML = '';
    (data.rows || []).forEach(row => {
      const tr = document.createElement('tr');
      tr.dataset.rowId = row.id;

      // COGNOME
      let td = document.createElement('td'); td.className = 'vfp-cell-text';
      const inpC = document.createElement('input');
      inpC.type = 'text'; inpC.value = row.cognome || ''; inpC.dataset.field = 'cognome';
      inpC.placeholder = 'Cognome'; inpC.addEventListener('change', onTextChanged);
      td.appendChild(inpC); tr.appendChild(td);

      // NOME
      td = document.createElement('td'); td.className = 'vfp-cell-text';
      const inpN = document.createElement('input');
      inpN.type = 'text'; inpN.value = row.nome || ''; inpN.dataset.field = 'nome';
      inpN.placeholder = 'Nome'; inpN.addEventListener('change', onTextChanged);
      td.appendChild(inpN); tr.appendChild(td);

      // POSIZIONE (select + pill)
      td = document.createElement('td');
      const pos = makePosizioneSelect(row.posizione || '');
      td.appendChild(pos); tr.appendChild(td);

      // UNILAV
      td = document.createElement('td');
      const selU = document.createElement('select');
      selU.innerHTML = `
        <option value="">—</option>
        <option value="indeterminato">Indeterminato</option>
        <option value="determinato">Determinato</option>
      `;
      selU.value = (row.unilav || '').toLowerCase();
      selU.addEventListener('change', onUnilavChanged);
      td.appendChild(selU); tr.appendChild(td);

      // DATE
      COLS.forEach(c => {
        const tdc = document.createElement('td'); tdc.className = 'vfp-cell-date';
        const inp = document.createElement('input');
        inp.type = 'date';
        inp.value = (row.dates && normalizeDateStr(row.dates[c.code])) || '';
        inp.dataset.col = c.code;
        inp.addEventListener('change', onDateChanged);
        tdc.appendChild(inp);
        tr.appendChild(tdc);
        markExpired(tdc);
      });

      // azioni
      const tdAct = document.createElement('td'); tdAct.className = 'vfp-row-actions';
      const btnDel = document.createElement('button');
      btnDel.type = 'button'; btnDel.innerHTML = '&times;'; btnDel.title = 'Rimuovi riga';
      btnDel.setAttribute('data-tooltip', 'Rimuovi operatore');
      btnDel.addEventListener('click', () => deleteRow(row.id));
      tdAct.appendChild(btnDel); tr.appendChild(tdAct);

      tbody.appendChild(tr);
    });

    // Dopo il render, applica le opzioni UI
    enableStickyFirstCols();
    renderColumnManager();            // crea una sola volta (idempotente)
    document.querySelector('.commessa-sic-vfp')?.classList.toggle('vfp-compact', !!opts.compact);
    applyColumnVisibility();
  }

  // ===== CRUD righe =====
  async function addRow() {
    try {
      await ensureCustomFetch();
      const res = await customFetch('commesse', 'addVfpOperatore', { tabella, azienda_id: aziendaId });
      if (res && res.success && res.row) {
        const data = await loadData();
        renderTable(data);
      } else {
        showToast?.('Impossibile aggiungere riga', 'error');
      }
    } catch (e) {
      console.error(e);
      showToast?.('Errore rete', 'error');
    }
  }

  async function deleteRow(rowId) {
    if (!confirm('Rimuovere questo operatore?')) return;
    try {
      await ensureCustomFetch();
      const res = await customFetch('commesse', 'deleteVfpOperatore', { tabella, azienda_id: aziendaId, row_id: rowId });
      if (res && res.success) {
        const data = await loadData();
        renderTable(data);
      } else {
        showToast?.('Eliminazione non riuscita', 'error');
      }
    } catch (e) {
      console.error(e);
      showToast?.('Errore rete', 'error');
    }
  }

  // ===== Load =====
  async function loadData() {
    await ensureCustomFetch();
    const res = await customFetch('commesse', 'getVfpFormazione', { tabella, azienda_id: aziendaId });
    if (!res || res.success !== true) {
      console.warn('[VFP] getVfpFormazione errore', res);
      showToast?.('Errore caricamento dati VFP', 'error');
      return { rows: [] };
    }
    return res;
  }

  // ===== Bootstrap =====
  document.addEventListener('DOMContentLoaded', async () => {
    try {

      const data = await loadData();
      renderTable(data);

      const btnAdd = document.getElementById('btn-add-row');
      if (btnAdd) btnAdd.addEventListener('click', addRow);

      // aggiorna titolo con la ragione sociale
      try {
        await ensureCustomFetch();
        const dett = await customFetch('commesse', 'getImpresaDettagli', { tabella, azienda_id: aziendaId });
        const label = dett?.impresa?.label || null;
        if (label) {
          const h = document.querySelector('.page-title h1, h1.page-title, .page-title');
          if (h) h.textContent = 'VERIFICA VFP - ' + label;
        }
      } catch (e) {
        console.warn('[VFP] titolo azienda non aggiornato', e);
      }

    } catch (err) {
      console.error(err);
      showToast?.('Errore inizializzazione VFP', 'error');
    }
  });

})();
