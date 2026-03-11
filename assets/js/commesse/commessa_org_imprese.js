document.addEventListener("DOMContentLoaded", async function () {
  // ——————————————————————— util ———————————————————————
  async function waitForCustomFetch(maxMs = 5000) {
    const t0 = Date.now();
    while (typeof window.customFetch !== 'function') {
      if (Date.now() - t0 > maxMs) throw new Error('customFetch non disponibile');
      await new Promise(r => setTimeout(r, 50));
    }
  }
  const esc = s => (s || '').toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  // ———————————————————— stato & boot ————————————————————
  const tabella = (window._tabellaCommessa || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();

  // Riceviamo ALBERO COMPLETO da PHP.
  let treeData = (window._orgImpreseInitial && typeof window._orgImpreseInitial === 'object')
    ? window._orgImpreseInitial
    : { azienda_id: null, children: [] };

  // Ensure shape
  if (!Array.isArray(treeData.children)) treeData.children = [];
  if (!('azienda_id' in treeData)) treeData.azienda_id = null;

  // Cache elenco imprese (sidebar)
  let imprese = []; // [{id,label,piva?}]

  // Drag state
  let dragAziendaId = null;

  // Selezione corrente
  let selectedAziendaId = null;
  let selectedDettagli = null;

  // —————————————————— helpers ——————————————————
  function getAllAziendaIds(node) {
    if (!node) return [];
    let ids = [];
    if (node.azienda_id) ids.push(Number(node.azienda_id));
    if (Array.isArray(node.children)) {
      node.children.forEach(child => { ids = ids.concat(getAllAziendaIds(child)); });
    }
    return ids;
  }

  function removeNodeByAziendaId(node, azId) {
    if (!node || !Array.isArray(node.children)) return;
    for (let i = node.children.length - 1; i >= 0; i--) {
      const ch = node.children[i];
      if (Number(ch.azienda_id) === Number(azId)) { node.children.splice(i, 1); continue; }
      if (ch.children) removeNodeByAziendaId(ch, azId);
    }
  }

  function clearSelection() {
    selectedAziendaId = null;
    selectedDettagli = null;
    const panel = document.getElementById('org-azienda-panel');
    if (panel) panel.hidden = true;
    // togli evidenziazione dai nodi
    document.querySelectorAll('.org-node.org-selected').forEach(n => n.classList.remove('org-selected'));
  }

  function highlightSelectedNode() {
    if (!selectedAziendaId) return;
    // togli eventuali selezioni precedenti
    document.querySelectorAll('.org-node.org-selected')
      .forEach(n => n.classList.remove('org-selected'));
    // evidenzia il nodo con l’azid corrente
    const el = document.querySelector(`.org-node[data-azid="${String(selectedAziendaId)}"]`);
    if (el) el.classList.add('org-selected');
  }

  async function selectAzienda(aziendaId) {
    if (!Number.isFinite(aziendaId)) return;
    selectedAziendaId = aziendaId;

    // evidenzia il nodo cliccato
    document.querySelectorAll('.org-node.org-selected').forEach(n => n.classList.remove('org-selected'));
    // (l’attuale nodo viene creato dentro renderAll, per sicurezza evidenziamo dopo render)
    await loadDettagliAzienda(aziendaId);
    renderAziendaPanel();
    highlightSelectedNode();
  }

  // —————————————————— RENDER (stessa struttura dell’org persone) ——————————————————
  function renderTree(node, container) {
    container.innerHTML = '';
    if (!node) return;
    container.appendChild(renderNode(node, true, null, null));
  }

  function renderNode(node, isRoot = false, parent = null, childIdx = null) {
    const wrap = document.createElement('div');
    wrap.className = 'org-node-wrap';

    const nodeDiv = document.createElement('div');
    if (node && node.azienda_id) {
      nodeDiv.dataset.azid = String(node.azienda_id);
    }
    nodeDiv.className = 'org-node' + (node.azienda_id ? '' : ' org-node-empty');

    if (node.azienda_id) {
      const meta = (Array.isArray(imprese) ? imprese : []).find(x => String(x.id) === String(node.azienda_id));
      const title = meta ? meta.label : `Impresa #${node.azienda_id}`;

      nodeDiv.innerHTML = `
        ${isRoot ? `<button class="org-node-remove" title="Svuota capofila" data-tooltip="Svuota capofila">×</button>`
          : `<button class="org-node-remove" title="Rimuovi" data-tooltip="Rimuovi da organigramma">×</button>`}
        <div class="org-node-avatar" 
            style="display:flex;align-items:center;justify-content:center;
                    width:64px;height:64px;border-radius:50%;
                    background:#eef5ed;color:#187943;font-weight:800;font-size:14px;">
          ${esc((title || '').slice(0, 2).toUpperCase())}
        </div>
        <div style="margin-top:6px;font-weight:700;font-size:.98em;text-align:center;">
          ${esc(title)}
        </div>
      `;

      // Rimozione
      nodeDiv.querySelector('.org-node-remove').onclick = function (e) {
        e.stopPropagation();
        if (isRoot) {
          node.azienda_id = null;
        } else {
          removeNodeByAziendaId(treeData, node.azienda_id);
        }
        // se stai rimuovendo l'azienda selezionata, chiudi pannello
        if (selectedAziendaId && Number(selectedAziendaId) === Number(node.azienda_id)) {
          clearSelection();
        }
        renderAll(true);
      };

      // Selezione impresa (apri/aggiorna pannello)
      nodeDiv.onclick = function (e) {
        // evita che il click sul bottone remove inneschi selezione
        if (e.target && e.target.classList.contains('org-node-remove')) return;
        selectAzienda(Number(node.azienda_id));
      };
    } else {
      nodeDiv.innerHTML = `<span class="org-node-placeholder">Trascina qui</span>`;
    }

    // DROP sugli slot (anche root quando è vuota o per rimpiazzare)
    nodeDiv.ondragover = function (e) {
      if (!e.dataTransfer) return;
      if (e.dataTransfer.types.includes('text/plain') || e.dataTransfer.types.length === 0) {
        e.preventDefault();
        nodeDiv.classList.add('drop-hover');
      }
    };
    nodeDiv.ondragleave = function () { nodeDiv.classList.remove('drop-hover'); };
    nodeDiv.ondrop = function (e) {
      e.preventDefault();
      nodeDiv.classList.remove('drop-hover');
      if (!dragAziendaId) return;

      const azIdNum = Number(dragAziendaId);
      if (!Number.isFinite(azIdNum)) return;

      // Evita duplicati globali
      if (getAllAziendaIds(treeData).includes(azIdNum)) {
        // Se stai calando la stessa azienda su questo slot ed è già in albero altrove, blocca
        // (se servono move/ricollocazioni in futuro, faremo drag node→node)
        if (node.azienda_id !== azIdNum) return;
      }

      node.azienda_id = azIdNum;
      renderAll(true);
    };

    wrap.appendChild(nodeDiv);

    // Linee + figli
    if (node.children && node.children.length) {
      const vline = document.createElement('div');
      vline.className = 'org-link-line';
      wrap.appendChild(vline);

      const lineAndChildren = document.createElement('div');
      lineAndChildren.className = 'org-children-fullrow';

      let hline = document.createElement('div');
      hline.className = 'org-link-horizontal' + (node.children.length > 1 ? '' : ' single');
      lineAndChildren.appendChild(hline);

      const childrenRow = document.createElement('div');
      childrenRow.className = 'org-children-wrap';

      node.children.forEach((child, i) => {
        const childWrap = document.createElement('div');
        childWrap.style.display = 'flex';
        childWrap.style.flexDirection = 'column';
        childWrap.style.alignItems = 'center';
        childWrap.style.position = 'relative';

        const vlink = document.createElement('div');
        vlink.className = 'org-link-vertical-between';
        childWrap.appendChild(vlink);

        childWrap.appendChild(
          renderNode(child, false, node, i)
        );
        childrenRow.appendChild(childWrap);
      });

      const addBtn = document.createElement('button');
      addBtn.className = 'org-add-child';
      addBtn.textContent = '+';
      addBtn.setAttribute('data-tooltip', 'Aggiungi sulla stessa riga');
      addBtn.onclick = function (e) {
        e.stopPropagation();
        node.children = node.children || [];
        node.children.unshift({ azienda_id: null, children: [] });
        renderAll(true);
      };
      childrenRow.insertBefore(addBtn, childrenRow.firstChild);

      lineAndChildren.appendChild(childrenRow);
      wrap.appendChild(lineAndChildren);
    } else {
      const childrenRow = document.createElement('div');
      childrenRow.className = 'org-children-wrap';
      const addBtn = document.createElement('button');
      addBtn.className = 'org-add-child';
      addBtn.textContent = '+';
      addBtn.setAttribute('data-tooltip', 'Aggiungi sotto-nodo');
      addBtn.onclick = function (e) {
        e.stopPropagation();
        node.children = node.children || [];
        node.children.push({ azienda_id: null, children: [] });
        renderAll(true);
      };
      childrenRow.appendChild(addBtn);
      wrap.appendChild(childrenRow);
    }
    if (node.azienda_id && selectedAziendaId && Number(node.azienda_id) === Number(selectedAziendaId)) {
      nodeDiv.classList.add('org-selected');
    }
    return wrap;
  }

  // —————————————————— Sidebar imprese (drag source) ——————————————————
  function renderSidebarImprese() {
    const div = document.getElementById("sidebar-imprese");
    const input = document.getElementById("org-search-imprese");
    const q = (input?.value || '').toLowerCase().trim();
    const used = getAllAziendaIds(treeData);

    div.innerHTML = '';
    (imprese || []).forEach(item => {
      if (used.includes(Number(item.id))) return; // evita duplicati
      if (q && !((item.label || '').toLowerCase().includes(q) || (item.piva || '').includes(q))) return;

      const tile = document.createElement("div");
      tile.className = "user-tile-persona";
      tile.draggable = true;
      tile.setAttribute("data-azid", String(item.id));
      tile.setAttribute("data-tooltip", item.label);

      tile.innerHTML = `
        <div class="user-tile-avatar" 
             style="display:flex;align-items:center;justify-content:center;
                    font-weight:800;font-size:12px;">
          ${esc((item.label || '').slice(0, 2).toUpperCase())}
        </div>
        <span class="user-tile-disciplina" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;">
          ${esc(item.label)}
        </span>
        ${item.piva ? `<span style="margin-left:auto;color:#64748b;font-size:.9em;">${esc(item.piva)}</span>` : ''}
      `;

      tile.ondragstart = function (e) {
        dragAziendaId = String(item.id);
        e.dataTransfer.setData("text/plain", dragAziendaId);
        e.dataTransfer.effectAllowed = "copy";
        this.classList.add('dragover');
      };
      tile.ondragend = function () {
        dragAziendaId = null;
        this.classList.remove('dragover');
      };

      div.appendChild(tile);
    });
  }

  // —————————————————— Autosave (debounced) ——————————————————
  let _saveTimer = null;
  function scheduleSave() {
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(salvaOrganigrammaAuto, 600);
  }
  async function salvaOrganigrammaAuto() {
    try {
      await waitForCustomFetch();
      // ORA salviamo l’ALBERO COMPLETO (root inclusa)
      const payload = { tabella, data: treeData };
      const json = JSON.stringify(payload);
      if (!json || json[0] !== '{') return;
      await customFetch('commesse', 'saveOrganigrammaImprese', payload);
    } catch (err) {
      console.error('[org_imprese] autosave error', err);
    }
  }

  // —————————————————— Render All ——————————————————
  function renderAll(triggerSave = false) {
    renderSidebarImprese();
    const area = document.getElementById("org-fasce-area");
    renderTree(treeData, area);
    adaptTreeZoom();

    // evidenzia nodo selezionato se presente
    if (selectedAziendaId) {
      const el = document.querySelector(`.org-node[data-azid="${String(selectedAziendaId)}"]`);
      if (el) el.classList.add('org-selected');
    }

    if (triggerSave) scheduleSave();
  }

  function adaptTreeZoom() {
    const scrollwrap = document.querySelector('.org-tree-scrollwrap');
    const inner = document.querySelector('.org-tree-inner');
    if (!scrollwrap || !inner) return;

    inner.style.transform = 'scale(1)';
    setTimeout(() => {
      const availW = scrollwrap.clientWidth;
      const availH = scrollwrap.clientHeight;
      const neededW = inner.scrollWidth;
      const neededH = inner.scrollHeight;
      let zoom = 1;

      if (neededW > availW || neededH > availH) {
        zoom = Math.min(availW / neededW, availH / neededH, 1);
      }
      inner.style.transform = 'scale(' + (zoom * 0.97) + ')';
    }, 10);
  }

  let _zoomTO = null;
  window.addEventListener('resize', function () {
    clearTimeout(_zoomTO);
    _zoomTO = setTimeout(adaptTreeZoom, 100);
  });

  document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'org-search-imprese') {
      renderSidebarImprese();
    }
  });

  async function loadDettagliAzienda(aziendaId) {
    selectedDettagli = null;
    try {
      await waitForCustomFetch();
      const payload = { tabella, azienda_id: Number(aziendaId) };
      const res = await customFetch('commesse', 'getImpresaDettagli', payload);
      // Ora l'API risponde con docs come ARRAY [{codice,nome,has,updated_at,file_url,open_url}, ...]
      if (res && res.success) {
        const arr = Array.isArray(res.docs) ? res.docs : [];
        const map = {};
        arr.forEach(d => { if (d && d.codice) map[String(d.codice).toUpperCase()] = d; });
        selectedDettagli = { success: true, impresa: res.impresa || {}, docsArray: arr, docsMap: map };
      } else {
        selectedDettagli = { success: false, error: (res && res.error) || 'Dettagli non disponibili' };
      }
    } catch (err) {
      console.error('[org_imprese] getImpresaDettagli error', err);
      selectedDettagli = { success: false, error: 'Errore di rete' };
    }
    window.__orgLastDettagli = selectedDettagli;
  }

  function renderAziendaPanel() {
    const panel = document.getElementById('org-azienda-panel');
    const sum = document.getElementById('org-azienda-summary');
    const ul = document.getElementById('org-doc-list');
    const btnPos = document.getElementById('org-open-pos');
    const btnVrtp = document.getElementById('org-open-vrtp');
    const btnVcs = document.getElementById('org-open-vcs');

    if (!panel || !sum || !ul) return;
    if (!selectedAziendaId || !selectedDettagli || selectedDettagli.success === false) {
      panel.hidden = true; return;
    }

    const imp = selectedDettagli.impresa || {};
    const docsArr = Array.isArray(selectedDettagli.docsArray) ? selectedDettagli.docsArray : [];
    const docsMap = selectedDettagli.docsMap || {};

    ['POS', 'VRTP', 'VCS'].forEach(k => {
      if (!docsMap[k]) {
        const view = (window._orgOpenDocViews && window._orgOpenDocViews[k]) || null;
        const titoloUpper = (tabella || '').toUpperCase();
        docsMap[k] = {
          codice: k,
          nome: k,
          has: false,
          updated_at: null,
          file_url: null,
          open_url: view
            ? `index.php?section=commesse&page=commessa&tabella=${encodeURIComponent(tabella)}&titolo=${encodeURIComponent(titoloUpper)}&view=${encodeURIComponent(view)}&azienda_id=${encodeURIComponent(String(selectedAziendaId))}`
            : null
        };
        docsArr.push(docsMap[k]);
      }
    });

    // Summary
    const title = esc(imp.label || `Impresa #${selectedAziendaId}`);
    const piva = imp.piva ? ` • P.IVA ${esc(imp.piva)}` : '';
    const tel = imp.telefono ? ` • Tel: ${esc(imp.telefono)}` : '';
    const mail = imp.email ? ` • ${esc(imp.email)}` : '';
    sum.innerHTML = `
    <div class="az-title">${title}</div>
    <div class="az-meta">${piva}${tel}${mail}</div>
  `;

    // Lista (usa l’ARRAY come da risposta reale)
    ul.innerHTML = docsArr.map(d => {
      const ok = d.has ? 'OK' : 'NON OK';
      const cls = d.has ? 'ok' : 'ko';
      const tip = d.has ? `Aggiornato: ${esc(d.updated_at || '')}` : 'Documento mancante o scaduto';
      const link = d.file_url ? ` – <a href="${d.file_url}" target="_blank" rel="noopener">file</a>` : '';
      return `<li class="doc-item ${cls}" data-tooltip="${esc(tip)}">
      <span class="doc-name">${esc(d.nome || d.codice)}</span>
      <span class="doc-state">${ok}</span>${link}
    </li>`;
    }).join('');

    // Bottoni “Apri …” -> usa OPEN_URL, non "url"
    const bindBtn = (btn, info) => {
      if (!btn) return;
      const fallback = btn.getAttribute('data-fallback-url') || '';
      const target = (info && info.open_url) ? info.open_url : fallback;
      if (target) {
        btn.disabled = false;
        btn.onclick = () => { location.href = target; };
      } else {
        btn.disabled = true;
        btn.onclick = null;
      }
    };
    bindBtn(btnPos, docsMap['POS']);
    bindBtn(btnVrtp, docsMap['VRTP']);
    // accetta VCS o eventuale VVCS
    bindBtn(btnVcs, docsMap['VCS'] || docsMap['VVCS']);

    panel.hidden = false;
  }

  // Chiudi pannello
  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'org-close-panel') {
      clearSelection();
    }
  });

  // —————————————————— Boot: carica imprese e disegna ——————————————————
  try {
    await waitForCustomFetch();
    const res = await customFetch('commesse', 'getImpreseAnagrafiche', { q: '' });
    if (res && res.success && Array.isArray(res.items)) {
      imprese = res.items;
    } else {
      imprese = [];
    }
  } catch (err) {
    console.error('[org_imprese] load imprese', err);
    imprese = [];
  }

  renderAll(false);
});

// ===== Helpers UI documenti impresa =====
function toggleDocButton(btn, url) {
  if (!btn) return;
  btn.classList.remove('disabled');
  btn.replaceWith(btn.cloneNode(true)); // rimuove vecchi listener
  const fresh = document.getElementById(btn.id);
  const fallback = fresh.getAttribute('data-fallback-url') || '';
  const finalUrl = url || fallback;

  if (finalUrl) {
    fresh.addEventListener('click', () => { location.href = finalUrl; }, { once: true });
  } else {
    // Se non abbiamo neppure un fallback, disattiva visivamente
    fresh.classList.add('disabled');
  }
}

// Popola il pannello “Dettagli impresa” con stato documenti
function renderDettagliImpresaDocs(payload, aziendaId) {
  const summaryEl = document.getElementById('org-azienda-summary');
  const listEl = document.getElementById('org-doc-list');

  const impresa = payload?.impresa || {};
  const docs = Array.isArray(payload?.docs) ? payload.docs : [];

  if (summaryEl) {
    const piva = impresa.piva ? `<div class="meta"><b>P.IVA:</b> ${escapeHtml(impresa.piva)}</div>` : '';
    const tel = impresa.telefono ? `<div class="meta"><b>Tel:</b> ${escapeHtml(impresa.telefono)}</div>` : '';
    const eml = impresa.email ? `<div class="meta"><b>Email:</b> ${escapeHtml(impresa.email)}</div>` : '';
    summaryEl.innerHTML = `
      <div class="azienda-title">${escapeHtml(impresa.label || ('Impresa #' + (impresa.id || '')))}</div>
      ${piva}${tel}${eml}
    `;
  }

  if (listEl) {
    listEl.innerHTML = docs.map(d => {
      const ok = d.has ? '✅' : '⚠️';
      const when = d.updated_at ? ` <span class="doc-date">(${escapeHtml(d.updated_at)})</span>` : '';
      const file = d.file_url ? ` – <a href="${d.file_url}" target="_blank" rel="noopener">file</a>` : '';
      return `<li class="doc-item ${d.has ? 'ok' : 'missing'}">${escapeHtml(d.nome || d.codice)} ${ok}${when}${file}</li>`;
    }).join('');
  }

  // Se il backend non ha restituito i "core", aggiungili in locale (così i bottoni diventano attivi comunque)
  const need = new Set(['POS', 'VRTP', 'VCS']);
  const have = new Set(docs.map(d => (d.codice || '').toUpperCase()));
  ['POS', 'VRTP', 'VCS'].forEach(k => {
    if (!have.has(k)) {
      const page = (window._orgOpenDocPages && window._orgOpenDocPages[k]) || null;
      const openUrl = page ? `index.php?section=commesse&page=${page}&tabella=${encodeURIComponent(window._tabellaCommessa || '')}&azienda_id=${encodeURIComponent(String(aziendaId || ''))}` : null;
      docs.push({ codice: k, nome: k, has: false, updated_at: null, file_url: null, open_url: openUrl });
    }
  });

  // Bottoni apri modulo (usa open_url se presente; in fallback costruisce dagli attributi)
  const map = {};
  docs.forEach(d => { map[(d.codice || '').toUpperCase()] = d; });

  const posBtn = document.getElementById('org-open-pos');
  const vrtpBtn = document.getElementById('org-open-vrtp');
  const vcsBtn = document.getElementById('org-open-vcs');

  toggleDocButton(posBtn, map['POS'] && map['POS'].open_url ? addAziendaId(map['POS'].open_url, aziendaId) : null);
  toggleDocButton(vrtpBtn, map['VRTP'] && map['VRTP'].open_url ? addAziendaId(map['VRTP'].open_url, aziendaId) : null);

  // accetta VCS o VVCS
  const vcsData = map['VCS'] || map['VVCS'];
  toggleDocButton(vcsBtn, vcsData && vcsData.open_url ? addAziendaId(vcsData.open_url, aziendaId) : null);

  // mostra il pannello se nascosto
  const panel = document.getElementById('org-azienda-panel');
  if (panel) panel.hidden = false;
}

function addAziendaId(url, aziendaId) {
  try {
    const u = new URL(url, location.origin);
    if (!u.searchParams.get('azienda_id')) u.searchParams.set('azienda_id', String(aziendaId || ''));
    return u.pathname + '?' + u.searchParams.toString();
  } catch {
    // fallback grezzo
    if (url.indexOf('azienda_id=') === -1) {
      return url + (url.indexOf('?') === -1 ? '?' : '&') + 'azienda_id=' + encodeURIComponent(String(aziendaId || ''));
    }
    return url;
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ===== Caricamento dettagli impresa (API) =====
async function loadDettagliImpresa(tabella, aziendaId) {
  const payload = { section: 'commesse', action: 'getImpresaDettagli', tabella, azienda_id: aziendaId };
  const headers = { 'Content-Type': 'application/json' };

  // CSRF se presente nei meta
  const meta = document.querySelector('meta[name="token-csrf"]');
  if (meta && meta.content) headers['X-CSRF-Token'] = meta.content;

  const res = await fetch('/ajax/commesse.php', {
    method: 'POST',
    headers,
    body: JSON.stringify(payload),
    credentials: 'same-origin',
    cache: 'no-store',
    redirect: 'manual'
  });

  if (!res.ok) throw new Error('HTTP ' + res.status);
  const json = await res.json();
  if (!json || json.success !== true) throw new Error(json?.error || 'Errore caricamento dettagli impresa');
  return json;
}

// ===== Wiring sulla selezione di un nodo impresa nell’albero =====
// Quando selezioni un’azienda nel tree, chiama questa funzione con l’ID selezionato.
async function onSelectAziendaInTree(aziendaId) {
  try {
    const tabella = window._tabellaCommessa || '';
    if (!tabella || !aziendaId) return;

    const data = await loadDettagliImpresa(tabella, aziendaId);
    renderDettagliImpresaDocs(data, aziendaId);
  } catch (err) {
    console.error(err);
  }
}

// Chiudi pannello
(function bindClosePanel() {
  const closeBtn = document.getElementById('org-close-panel');
  const panel = document.getElementById('org-azienda-panel');
  if (closeBtn && panel) {
    closeBtn.addEventListener('click', () => { panel.hidden = true; });
  }
})();
