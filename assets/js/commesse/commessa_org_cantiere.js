document.addEventListener("DOMContentLoaded", async function () {
  // ——— util
  async function waitForCustomFetch(maxMs = 5000) {
    const t0 = Date.now();
    while (typeof window.customFetch !== 'function') {
      if (Date.now() - t0 > maxMs) throw new Error('customFetch non disponibile');
      await new Promise(r => setTimeout(r, 50));
    }
  }
  const esc = s => (s || '').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

  // ——— stato
  const tabella = (window._tabellaCommessa || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();
  const roles   = Array.isArray(window._ruoliCantiere) ? window._ruoliCantiere : [];

  let treeData = (window._orgCantiereInitial && typeof window._orgCantiereInitial === 'object')
    ? window._orgCantiereInitial
    : { azienda_id: null, role: null, children: [] };

  if (!Array.isArray(treeData.children)) treeData.children = [];
  if (!('azienda_id' in treeData)) treeData.azienda_id = null;
  if (!('role' in treeData))       treeData.role = null;

  let dragAzId = null;  // trascinamento impresa
  let dragRole = null;  // trascinamento ruolo
  let impreseCache = []; // [{id,label,piva?}]

  // ——— helper albero
  function getAllAziendaIds(node) {
    if (!node) return [];
    let ids = [];
    if (node.azienda_id) ids.push(Number(node.azienda_id));
    if (node.children && Array.isArray(node.children)) {
      node.children.forEach(child => { ids = ids.concat(getAllAziendaIds(child)); });
    }
    return ids;
  }
  function removeNodeByAziendaId(node, azId) {
    if (!node || !node.children) return;
    for (let i = node.children.length - 1; i >= 0; i--) {
      if (Number(node.children[i].azienda_id) === Number(azId)) { node.children.splice(i, 1); continue; }
      if (node.children[i].children) removeNodeByAziendaId(node.children[i], azId);
    }
  }

  // ——— render
  function renderTree(node, container) {
    container.innerHTML = '';
    if (!node) return;
    container.appendChild(renderNode(node, true, null, null));
  }

  function renderNode(node, isRoot = false, parent = null, childIdx = null) {
    const wrap = document.createElement('div');
    wrap.className = 'org-node-wrap';

    const nodeDiv = document.createElement('div');
    nodeDiv.className = 'org-node' + (node.azienda_id ? '' : ' org-node-empty');

    if (node.azienda_id) {
      const meta = impreseCache.find(x => String(x.id) === String(node.azienda_id));
      const title = meta ? meta.label : `Impresa #${node.azienda_id}`;

      nodeDiv.innerHTML = `
        ${isRoot ? `<button class="org-node-remove" title="Svuota root" data-tooltip="Svuota root">×</button>`
                 : `<button class="org-node-remove" title="Rimuovi" data-tooltip="Rimuovi da organigramma">×</button>`}
        <div class="org-node-avatar" 
             style="display:flex;align-items:center;justify-content:center;">
          ${esc((title||'').slice(0,2).toUpperCase())}
        </div>
        ${node.role ? `<span class="org-node-root-label" data-tooltip="${esc(getRoleLabel(node.role))}">${esc(node.role)}</span>` : ''}
      `;

      nodeDiv.querySelector('.org-node-remove').onclick = function(e) {
        e.stopPropagation();
        if (isRoot) {
          node.azienda_id = null;
          node.role = null;
        } else {
          removeNodeByAziendaId(treeData, node.azienda_id);
        }
        renderAll(true);
      };
    } else {
      nodeDiv.innerHTML = `<span class="org-node-placeholder">Trascina qui</span>`;
    }

    // DROP su slot: ruolo o impresa
    nodeDiv.ondragover = function(e) {
      if (!e.dataTransfer) return;
      if (e.dataTransfer.types.includes('text/plain') || e.dataTransfer.types.includes('role')) {
        e.preventDefault();
        nodeDiv.classList.add('drop-hover');
      }
    };
    nodeDiv.ondragleave = function() { nodeDiv.classList.remove('drop-hover'); };
    nodeDiv.ondrop = function(e) {
      e.preventDefault();
      nodeDiv.classList.remove('drop-hover');

      // 1) ruolo
      const roleCode = e.dataTransfer.getData('role');
      if (roleCode) {
        if (roles.find(r => r.code === roleCode)) {
          node.role = roleCode;
          renderAll(true);
        }
        return;
      }

      // 2) impresa
      if (!dragAzId) return;
      const azIdNum = Number(dragAzId);
      if (!Number.isFinite(azIdNum)) return;

      if (getAllAziendaIds(treeData).includes(azIdNum)) {
        if (node.azienda_id !== azIdNum) return; // niente duplicati
      }

      node.azienda_id = azIdNum;
      renderAll(true);
    };

    wrap.appendChild(nodeDiv);

    // linee + children + plus
    if (node.children && node.children.length) {
      const vline = document.createElement('div'); vline.className = 'org-link-line'; wrap.appendChild(vline);

      const lineAndChildren = document.createElement('div'); lineAndChildren.className = 'org-children-fullrow';

      let hline = document.createElement('div');
      hline.className = 'org-link-horizontal' + (node.children.length > 1 ? '' : ' single');
      lineAndChildren.appendChild(hline);

      const childrenRow = document.createElement('div'); childrenRow.className = 'org-children-wrap';

      node.children.forEach((child, i) => {
        const childWrap = document.createElement('div');
        childWrap.style.display = 'flex';
        childWrap.style.flexDirection = 'column';
        childWrap.style.alignItems = 'center';
        childWrap.style.position = 'relative';

        const vlink = document.createElement('div');
        vlink.className = 'org-link-vertical-between';
        childWrap.appendChild(vlink);

        childWrap.appendChild(renderNode(child, false, node, i));
        childrenRow.appendChild(childWrap);
      });

      const addBtn = document.createElement('button');
      addBtn.className = 'org-add-child';
      addBtn.textContent = '+';
      addBtn.setAttribute('data-tooltip','Aggiungi sulla stessa riga');
      addBtn.onclick = function(e) {
        e.stopPropagation();
        node.children = node.children || [];
        node.children.unshift({ azienda_id: null, role: null, children: [] });
        renderAll(true);
      };
      childrenRow.insertBefore(addBtn, childrenRow.firstChild);

      lineAndChildren.appendChild(childrenRow);
      wrap.appendChild(lineAndChildren);
    } else {
      const childrenRow = document.createElement('div'); childrenRow.className = 'org-children-wrap';
      const addBtn = document.createElement('button');
      addBtn.className = 'org-add-child';
      addBtn.textContent = '+';
      addBtn.setAttribute('data-tooltip','Aggiungi sotto-nodo');
      addBtn.onclick = function(e) {
        e.stopPropagation();
        node.children = node.children || [];
        node.children.push({ azienda_id: null, role: null, children: [] });
        renderAll(true);
      };
      childrenRow.appendChild(addBtn);
      wrap.appendChild(childrenRow);
    }

    return wrap;
  }

  function getRoleLabel(code) {
    const r = roles.find(x => x.code === code);
    return r ? r.label : code;
  }

  // ——— sidebar: IMPRESE (da anagrafiche)
  function renderSidebarImprese() {
    const div = document.getElementById("sidebar-imprese");
    const input = document.getElementById("org-search-imprese");
    const q = (input?.value || '').toLowerCase().trim();
    const used = getAllAziendaIds(treeData);

    div.innerHTML = '';
    (impreseCache || []).forEach(item => {
      if (used.includes(Number(item.id))) return; // evita duplicati
      if (q && !((item.label || '').toLowerCase().includes(q) || (item.piva || '').includes(q))) return;

      const tile = document.createElement("div");
      tile.className = "user-tile-persona";
      tile.draggable = true;
      tile.setAttribute("data-azid", String(item.id));
      tile.setAttribute("data-tooltip", item.label);

      tile.innerHTML = `
        <div class="user-tile-avatar" 
             style="display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">
          ${esc((item.label||'').slice(0,2).toUpperCase())}
        </div>
        <span class="user-tile-disciplina" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;">
          ${esc(item.label)}
        </span>
        ${item.piva ? `<span style="margin-left:auto;color:#64748b;font-size:.9em;">${esc(item.piva)}</span>` : ''}
      `;

      tile.ondragstart = function(e) {
        dragAzId = String(item.id);
        e.dataTransfer.setData("text/plain", dragAzId);
        e.dataTransfer.effectAllowed = "copy";
        this.classList.add('dragover');
      };
      tile.ondragend = function() {
        dragAzId = null;
        this.classList.remove('dragover');
      };

      div.appendChild(tile);
    });
  }

  // ——— sidebar: RUOLI (badge)
  function renderSidebarRoles() {
    const div = document.getElementById("sidebar-roles");
    div.innerHTML = '';
    roles.forEach(r => {
      const badge = document.createElement('span');
      badge.className = 'badge-disciplina';
      badge.draggable = true;
      badge.style.background = r.color || '#888';
      badge.style.color = '#fff';
      badge.setAttribute('data-code', r.code);
      badge.setAttribute('data-tooltip', r.label);
      badge.textContent = r.code;

      badge.ondragstart = function(e) {
        dragRole = r.code;
        e.dataTransfer.setData("role", r.code);
        e.dataTransfer.effectAllowed = "copy";
      };
      badge.ondragend = function() { dragRole = null; };

      div.appendChild(badge);
    });
  }

  // autosave
  let _saveTimer = null;
  function scheduleSave() {
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(salvaOrganigrammaAuto, 600);
  }
  async function salvaOrganigrammaAuto() {
    try {
      await waitForCustomFetch();
      const payload = { tabella, data: treeData };
      const json = JSON.stringify(payload);
      if (!json || json[0] !== '{') return;
      await customFetch('commesse', 'saveOrganigrammaCantiere', payload);
    } catch (err) {
      console.error('[org_cantiere] autosave error', err);
    }
  }

  function renderAll(triggerSave = false) {
    renderSidebarRoles();
    renderSidebarImprese();
    const area = document.getElementById("org-fasce-area");
    renderTree(treeData, area);
    adaptTreeZoom();
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
  window.addEventListener('resize', function() {
    clearTimeout(_zoomTO);
    _zoomTO = setTimeout(adaptTreeZoom, 100);
  });

  document.addEventListener('input', function(e) {
    if (e.target && e.target.id === 'org-search-imprese') renderSidebarImprese();
  });

  // boot: carica imprese anagrafiche
  try {
    await waitForCustomFetch();
    const res = await customFetch('commesse', 'getImpreseAnagrafiche', { q: '' });
    if (res && res.success && Array.isArray(res.items)) {
      impreseCache = res.items;
    } else {
      impreseCache = [];
    }
  } catch (err) {
    console.error('[org_cantiere] load imprese', err);
    impreseCache = [];
  }

  renderAll(false);
});
