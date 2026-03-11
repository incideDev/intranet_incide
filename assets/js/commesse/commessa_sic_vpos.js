(function () {
  if (window.__commessaVposInitDone) return;
  window.__commessaVposInitDone = true;

  // ========= Config base =========
  const TIPO_FORM = "VPOS"; // coerente con backend
  const TITOLO_FORM = "VERIFICA POS – Allegato XV";
  const AUTOSAVE_DELAY = 200;

  // ========= Helpers =========
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n | 0));
  const cleanStr = (v, max = 2000) =>
    ((v ?? "").toString().replace(/[\u0000-\u001F]+/g, "").trim()).slice(0, max);
  const qs = (sel, ctx = document) => ctx.querySelector(sel);
  const qsa = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  // Stato locale
  let currentTabella = "";     // es: codice commessa (logico)
  let currentFormId = 0;       // id record commesse_sicurezza_forms
  let dataMap = Object.create(null); // { [itemId]: { v: 'si'|'no'|'na'|null, note: string } }
  let noteGenerali = "";

  // ========= Schema gerarchico (fedelmente al Word) =========
  // Ogni item: { id, label, level, children? }
  // Le etichette usano i testi presi dal documento d'esempio fornito (Allegato XV sez. 3.2.1 / 3.2.2)
  // NB: Mettiamo gli ID stabili per il salvataggio.
  const SCHEMA = [
    {
      id: "3.2.1",
      level: 0,
      label: "3.2.1 Il POS è redatto a cura di ciascun datore di lavoro delle imprese esecutrici, ai sensi dell’articolo 17 del presente Decreto, e successive modificazioni, in riferimento al singolo cantiere interessato; esso contiene almeno i seguenti elementi:",
      children: [
        {
          id: "3.2.1.a",
          level: 1,
          label: "a) i dati identificativi dell’impresa esecutrice, che comprendono:",
          children: [
            { id: "3.2.1.a.1", level: 2, label: "1) il nominativo del datore di lavoro, gli indirizzi ed i riferimenti telefonici della sede legale e degli uffici di cantiere;" },
            { id: "3.2.1.a.2", level: 2, label: "2) la specifica attività e le singole lavorazioni svolte in cantiere dall’impresa esecutrice e dai lavoratori autonomi subaffidatari;" },
            { id: "3.2.1.a.3", level: 2, label: "3) i nominativi degli addetti al pronto soccorso, antincendio ed evacuazione dei lavoratori e, comunque, alla gestione delle emergenze in cantiere, del rappresentante dei lavoratori per la sicurezza, aziendale o territoriale, ove eletto o designato;" },
            { id: "3.2.1.a.4", level: 2, label: "4) il nominativo del medico competente ove previsto;" },
            { id: "3.2.1.a.5", level: 2, label: "5) il nominativo del responsabile del servizio di prevenzione e protezione;" },
            { id: "3.2.1.a.6", level: 2, label: "6) i nominativi del direttore tecnico di cantiere e del capocantiere;" },
            { id: "3.2.1.a.7", level: 2, label: "7) il numero e le relative qualifiche dei lavoratori dipendenti dell’impresa esecutrice e dei lavoratori autonomi operanti in cantiere per conto della stessa impresa;" },
          ]
        },
        { id: "3.2.1.b", level: 1, label: "b) le specifiche mansioni, inerenti la sicurezza, svolte in cantiere da ogni figura nominata allo scopo dall’impresa esecutrice;" },
        { id: "3.2.1.c", level: 1, label: "c) la descrizione dell’attività di cantiere, delle modalità organizzative e dei turni di lavoro;" },
        { id: "3.2.1.d", level: 1, label: "d) l’elenco dei ponteggi, dei ponti su ruote a torre e di altre opere provvisionali di notevole importanza, delle macchine e degli impianti utilizzati nel cantiere;" },
        { id: "3.2.1.e", level: 1, label: "e) l’elenco delle sostanze e preparati pericolosi utilizzati nel cantiere con le relative schede di sicurezza;" },
        { id: "3.2.1.f", level: 1, label: "f) l’esito del rapporto di valutazione del rumore, vibrazioni;" },
        { id: "3.2.1.g", level: 1, label: "g) l’individuazione delle misure preventive e protettive, integrative rispetto a quelle contenute nel PSC quando previsto, adottate in relazione ai rischi connessi alle proprie lavorazioni in cantiere;" },
        { id: "3.2.1.h", level: 1, label: "h) le procedure complementari e di dettaglio, richieste dal PSC quando previsto;" },
        { id: "3.2.1.i", level: 1, label: "i) l’elenco dei dispositivi di protezione individuale forniti ai lavoratori occupati in cantiere;" },
        { id: "3.2.1.l", level: 1, label: "l) la documentazione in merito all’informazione ed alla formazione fornite ai lavoratori occupati in cantiere." },
      ]
    },
    {
      id: "3.2.2",
      level: 0,
      label: "3.2.2 Ove non sia prevista la redazione del PSC, il PSS, quando previsto, è integrato con gli elementi del POS.",
    }
  ];

  // === Stato di collasso + indice gerarchia ===
  const collapsedIds = new Set();

  const parentOf = Object.create(null);
  const childrenOf = Object.create(null);
  const flatOrder = [];

  // indicizza SCHEMA una volta sola (parent/children/ordine)
  (function indexSchema() {
    const walk = (nodes, parentId = null) => {
      nodes.forEach(n => {
        const id = n.id;
        flatOrder.push(id);
        if (!childrenOf[id]) childrenOf[id] = [];
        if (parentId) {
          parentOf[id] = parentId;
          if (!childrenOf[parentId]) childrenOf[parentId] = [];
          childrenOf[parentId].push(id);
        }
        if (Array.isArray(n.children) && n.children.length) {
          walk(n.children, id);
        }
      });
    };
    walk(SCHEMA, null);
  })();

  // ========= Render =========
  function render() {
    const tbody = qs("#vpos-table tbody");
    if (!tbody) return;
    tbody.innerHTML = "";

    const getNodeById = (id) => {
      // piccolo lookup: percorri SCHEMA (cache semplice)
      // per performance puoi memoizzare in una mappa se vuoi
      let found = null;
      const walk = (nodes) => {
        for (const n of nodes) {
          if (n.id === id) { found = n; return; }
          if (n.children) walk(n.children);
          if (found) return;
        }
      };
      walk(SCHEMA);
      return found;
    };

    const hasChildren = (id) => (childrenOf[id] && childrenOf[id].length > 0);

    const isHiddenByCollapse = (id) => {
      let p = parentOf[id];
      while (p) {
        if (collapsedIds.has(p)) return true;
        p = parentOf[p];
      }
      return false;
    };

    flatOrder.forEach((id) => {
      const node = getNodeById(id);
      if (!node) return;

      const val = dataMap[id]?.v ?? null;
      const note = dataMap[id]?.note ?? "";
      const level = clamp(node.level || 0, 0, 6);
      const pad = level * 18;
      const parent = hasChildren(id);
      const collapsed = collapsedIds.has(id);

      // Radios
      const radios = ["si", "no", "na"].map(k => {
        const checked = (val === k) ? 'checked' : '';
        const label = (k === 'si') ? 'Sì' : (k === 'no' ? 'No' : 'N.A.');
        return `
                <label style="display:inline-flex;align-items:center;gap:6px;">
                    <input type="radio" name="rb-${id}" value="${k}" ${checked}
                           data-id="${id}" data-tooltip="${label}" aria-label="${label}">
                    <span>${label}</span>
                </label>
            `;
      });

      // caret se ha figli
      const caretHTML = parent
        ? `<span class="tree-caret" aria-hidden="true" style="display:inline-block;transform:rotate(${collapsed ? '-90' : '0'}deg);transition:transform .15s; margin-right:6px;">▾</span>`
        : `<span class="tree-caret-placeholder" style="display:inline-block;width:12px;margin-right:6px;"></span>`;

      const tr = document.createElement("tr");
      tr.dataset.id = id;
      tr.dataset.level = String(level);
      tr.className = parent ? "tree-row is-parent" : "tree-row";

      tr.style.display = isHiddenByCollapse(id) ? "none" : "";

      tr.innerHTML = `
            <td class="tree-cell" style="padding-left:${pad}px; cursor:${parent ? 'pointer' : 'default'};">
                ${caretHTML}
                <span class="tree-label">${node.label}</span>
            </td>
            <td style="text-align:center;">${radios[0]}</td>
            <td style="text-align:center;">${radios[1]}</td>
            <td style="text-align:center;">${radios[2]}</td>
            <td>
                <textarea class="vpos-note" data-id="${id}" rows="1" placeholder="Note" data-tooltip="Note">${note}</textarea>
            </td>
        `;
      tbody.appendChild(tr);
    });

    // Note generali
    const ng = qs('#vpos-note-generali');
    if (ng) ng.value = noteGenerali || "";

    bindRowEvents(); // rebinda handler (usa delega)
  }

  // ========= Event binding =========
  const saveTimers = new Map();

  function bindRowEvents() {
    const table = qs("#vpos-table");
    if (!table) return;

    table.addEventListener("change", onChange, true);
    table.addEventListener("input", onInput, true);

    // Toggle expand/collapse cliccando sulla prima cella delle righe che sono parent
    table.addEventListener("click", (e) => {
      const td = e.target.closest("td");
      const tr = e.target.closest("tr.tree-row");
      if (!td || !tr) return;
      if (td.cellIndex !== 0) return; // solo prima cella (Voce)

      const id = tr.dataset.id;
      // agisci solo se ha figli
      if (!childrenOf[id] || !childrenOf[id].length) return;

      // inverti stato
      if (collapsedIds.has(id)) collapsedIds.delete(id);
      else collapsedIds.add(id);

      // aggiorna caret della riga
      const caret = tr.querySelector(".tree-caret");
      if (caret) caret.style.transform = collapsedIds.has(id) ? "rotate(-90deg)" : "rotate(0deg)";

      // mostra/nascondi discendenti
      const hideBranch = collapsedIds.has(id);
      const stack = [...(childrenOf[id] || [])];
      while (stack.length) {
        const childId = stack.pop();
        const row = qs(`tr.tree-row[data-id="${childId}"]`);
        if (row) row.style.display = hideBranch || isHiddenByAnyAncestor(childId) ? "none" : "";

        // se anche il child è collassato, tutta la sua sotto-ramo rimane nascosta
        if (childrenOf[childId] && childrenOf[childId].length) {
          stack.push(...childrenOf[childId]);
        }
      }
    }, true);

    // helper locale: controlla se il nodo ha qualche antenato collassato
    function isHiddenByAnyAncestor(id) {
      let p = parentOf[id];
      while (p) {
        if (collapsedIds.has(p)) return true;
        p = parentOf[p];
      }
      return false;
    }

    // Note generali
    qs("#vpos-note-generali")?.addEventListener("input", (e) => {
      noteGenerali = cleanStr(e.target.value, 8000);
      scheduleSave();
    });
  }

  function onChange(e) {
    const rb = e.target.closest('input[type="radio"][name^="rb-"]');
    if (!rb) return;
    const id = rb.getAttribute("data-id");
    const v = rb.value; // si|no|na
    ensureEntry(id);
    dataMap[id].v = v;
    scheduleSave();
  }

  function onInput(e) {
    const ta = e.target.closest('textarea.vpos-note');
    if (!ta) return;
    const id = ta.getAttribute("data-id");
    ensureEntry(id);
    dataMap[id].note = cleanStr(ta.value, 4000);
    scheduleSave();
  }

  function ensureEntry(id) {
    if (!dataMap[id]) dataMap[id] = { v: null, note: "" };
  }

  function scheduleSave() {
    const key = "autosave";
    if (saveTimers.has(key)) clearTimeout(saveTimers.get(key));
    const t = setTimeout(doSave, AUTOSAVE_DELAY);
    saveTimers.set(key, t);
    setStatus("Salvataggio in corso…");
  }

  function setStatus(txt) {
    const el = qs(".vpos-status");
    if (el) el.textContent = txt;
  }

  // ========= Persistenza (backend commesse_sicurezza_forms) =========
  async function doSave() {
    try {
      // Prepara JSON
      const payloadData = {
        schemaVersion: 1,
        items: dataMap,
        noteGenerali: noteGenerali || ""
      };
      const json = JSON.stringify(payloadData);
      if (!json) {
        setStatus("JSON non valido");
        return;
      }

      const body = {
        id: currentFormId || 0,
        tabella: currentTabella,
        tipo: TIPO_FORM,
        titolo: TITOLO_FORM,
        data: payloadData
      };

      const res = await customFetch("commesse", "saveSicurezzaForm", body);
      if (!res?.success) {
        setStatus(res?.message || "Errore salvataggio");
        showToast?.(res?.message || "Errore salvataggio", "error");
        return;
      }
      if (!currentFormId && res.id) currentFormId = res.id;
      setStatus("Salvato");
    } catch (e) {
      console.error(e);
      setStatus("Errore di rete");
      showToast?.("Errore di rete", "error");
    }
  }

  async function loadExisting() {
    try {
      // Prendi ultimi/uno dei moduli VPOS di questa commessa
      const list = await customFetch("commesse", "listSicurezzaForms", {
        tabella: currentTabella,
        tipo: TIPO_FORM,
        q: ""
      });

      let id = 0;
      if (list?.success && Array.isArray(list.items) && list.items.length) {
        // prendi il più recente (sono già ordinati per updated_at desc nel service)
        id = list.items[0].id || 0;
      }

      if (id > 0) {
        const dett = await customFetch("commesse", "getSicurezzaForm", {
          id,
          tabella: currentTabella
        });
        if (dett?.success && dett.form) {
          currentFormId = dett.form.id || 0;
          const data = dett.form.data || {};
          if (data && typeof data === "object") {
            // idrata
            if (data.items && typeof data.items === "object") dataMap = data.items;
            noteGenerali = data.noteGenerali || "";
          }
        }
      }
    } catch (e) {
      console.error("loadExisting error", e);
    }
  }

  // ========= Boot =========
  async function start() {
    // Recupera "tabella" (commessa) dall'URL o da un meta/var globale, come fai nel resto del progetto
    const url = new URL(location.href);
    currentTabella = (url.searchParams.get('tabella') || window.currentTabella || "").replace(/[^a-z0-9_]/gi, '').toLowerCase();

    if (!currentTabella) {
      console.warn("tabella non trovata nell'URL (param ?tabella=...)");
    }

    await loadExisting();
    render();
    setStatus("Pronto");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
