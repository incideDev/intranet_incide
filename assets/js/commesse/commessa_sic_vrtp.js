(function () {
  if (window.__commessaSicVRTPInitDone) return;
  window.__commessaSicVRTPInitDone = true;

  // ===== Utilities coerenti con il progetto =====
  const qs = (s, r = document) => r.querySelector(s);
  const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n | 0));
  const cleanStr = (v, max = 1000) => ((v ?? "").toString().replace(/[\u0000-\u001F]+/g, "")).slice(0, max);
  const showToast = window.showToast || ((m) => console.log(m));

  // Prefisso “1.”, “1a)”, “2b)” in base all'id dello schema (es: "1", "1A", "2B")
  function formatPrefix(id) {
    if (!id) return "";
    // Solo numeri → "1."
    if (/^\d+$/.test(id)) return id + ".";
    // Numero + lettera → "1a)"
    const m = id.match(/^(\d+)([A-Za-z])$/);
    if (m) return m[1] + m[2].toLowerCase() + ")";
    // Fallback: id così com’è
    return id + ")";
  }

  // Parametri pagina
  const rootEl = qs('#vrtp-root');
  const tabella = (rootEl?.dataset.tabella || '').trim();
  const TIPO = (rootEl?.dataset.tipo || 'VRTP').trim();

  // Stato dei dati (mappa per id -> { v: 'si'|'no'|'na'|null, note: string })
  let dataMap = Object.create(null);
  let noteGenerali = "";
  let formId = 0;

  // Debounce salvataggio
  let saveTimer = null;
  const scheduleSave = (delay = 300) => {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveForm, delay);
  };

  // ======= SCHEMA VRTP (struttura ad albero) =======
  // Testi presi dal modello Word (riaccorpati su una riga quando necessario).
  const SCHEMA = [
    {
      id: "1",
      label: "Idoneità tecnico professionale dell’Impresa appaltatrice secondo quanto riportato nell’Allegato XVII D.Lgs 81/2008 e s.m.i. La verifica va eseguita dal Committente/Responsabile dei Lavori sia per l’Impresa appaltatrice che per quelle esecutrici.",
      level: 0,
      children: [
        { id: "1A", label: "Iscrizione alla CCIAA con oggetto sociale inerente all’appalto", level: 1 },
        { id: "1B", label: "Documento di Valutazione dei Rischi (art. 17, c.1 lett. a) o equivalenti ove ammessi", level: 1 },
        { id: "1C", label: "Documento Unico di Regolarità Contributiva (DURC)", level: 1 },
        { id: "1D", label: "Dichiarazione di non essere oggetto di provvedimenti di sospensione o interdittivi", level: 1 },
        { id: "1E", label: "Patente a punti o attestazione SOA IIIa cat.", level: 1 },
      ]
    },
    {
      id: "2",
      label: "Idoneità tecnico professionale dei Lavoratori autonomi secondo quanto riportato nell’Allegato XVII D.Lgs 81/2008 e s.m.i. La verifica va eseguita dal Committente/Responsabile dei lavori.",
      level: 0,
      children: [
        { id: "2A", label: "Iscrizione alla CCIAA con oggetto sociale inerente alla tipologia di appalto", level: 1 },
        { id: "2B", label: "Documentazione attestante la conformità di macchine, attrezzature e opere provvisionali", level: 1 },
        { id: "2C", label: "Elenco dei Dispositivi di Protezione Individuale in dotazione", level: 1 },
        { id: "2D", label: "Attestati inerenti la propria formazione e idoneità sanitaria, ove previsti dal D.Lgs 81/2008", level: 1 },
        { id: "2E", label: "Documento Unico di Regolarità Contributiva (DURC) – DM 24 ottobre 2007", level: 1 },
      ]
    },
    {
      id: "3",
      label: "Documentazione integrativa",
      level: 0,
      children: [
        { id: "3A", label: "Nomina RSPP; incaricati antincendio, evacuazione, primo soccorso e gestione emergenze; Medico Competente", level: 1 },
        { id: "3B", label: "Nominativo/i del/dei Rappresentante/i dei Lavoratori per la Sicurezza (RLS)", level: 1 },
        { id: "3C", label: "Attestati inerenti la formazione delle suddette figure e dei lavoratori prevista dal D.Lgs 81/2008", level: 1 },
        { id: "3D", label: "Elenco dei lavoratori risultanti dal libro unico e relativa idoneità sanitaria", level: 1 },
        { id: "3E", label: "Formazione secondo quanto stabilito dagli Accordi Stato-Regioni", level: 1 },
        { id: "3F", label: "Per lavori in quota: formazione e addestramento (utilizzo dei DPI di III categoria)", level: 1 },
      ]
    }
  ];

  // === Stato di collasso + indice gerarchia (uguale a VPOS) ===
  const collapsedIds = new Set();
  const parentOf = Object.create(null);
  const childrenOf = Object.create(null);
  const flatOrder = [];

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

  function getNodeById(id) {
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
  }
  const hasChildren = (id) => (childrenOf[id] && childrenOf[id].length > 0);

  function isHiddenByCollapse(id) {
    let p = parentOf[id];
    while (p) {
      if (collapsedIds.has(p)) return true;
      p = parentOf[p];
    }
    return false;
  }

  // ===== Render =====
  function render() {
    const tbody = qs("#vrtp-table tbody");
    if (!tbody) return;
    tbody.innerHTML = "";

    flatOrder.forEach((id) => {
      const node = getNodeById(id);
      if (!node) return;

      const val = dataMap[id]?.v ?? null;
      const note = dataMap[id]?.note ?? "";
      const level = clamp(node.level || 0, 0, 6);
      const pad = level * 18;
      const parent = hasChildren(id);
      const collapsed = collapsedIds.has(id);

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

      const caretHTML = parent
        ? `<span class="tree-caret" aria-hidden="true" style="display:inline-block;transform:rotate(${collapsed ? '-90' : '0'}deg);transition:transform .15s; margin-right:6px;">▾</span>`
        : `<span class="tree-caret-placeholder" style="display:inline-block;width:12px;margin-right:6px;"></span>`;

      const prefix = formatPrefix(id);

      const tr = document.createElement("tr");
      tr.dataset.id = id;
      tr.dataset.level = String(level);
      tr.className = parent ? "tree-row is-parent" : "tree-row";
      tr.style.display = isHiddenByCollapse(id) ? "none" : "";

      tr.innerHTML = `
        <td class="tree-cell" style="padding-left:${pad}px; cursor:${parent ? 'pointer' : 'default'};"
            ${parent ? 'data-tooltip="Clicca per espandere/comprimere"' : ''}>
            ${caretHTML}
            <strong class="tree-prefix" style="margin-right:6px;">${prefix}</strong>
            <span class="tree-label">${node.label}</span>
        </td>
        <td style="text-align:center;">${radios[0]}</td>
        <td style="text-align:center;">${radios[1]}</td>
        <td style="text-align:center;">${radios[2]}</td>
        <td>
            <textarea class="vrtp-note" data-id="${id}" rows="1" placeholder="Note" data-tooltip="Note">${note}</textarea>
        </td>
    `;
      tbody.appendChild(tr);
    });

    // Note generali
    const ng = qs('#vrtp-note-generali');
    if (ng) ng.value = noteGenerali || "";

    bindRowEvents();
  }

  // ===== Eventi (delega) =====
  function bindRowEvents() {
    const table = qs("#vrtp-table");
    if (!table) return;

    // Toggle expand/collapse clic su prima cella delle righe parent
    table.addEventListener("click", (e) => {
      const td = e.target.closest("td");
      const tr = e.target.closest("tr.tree-row");
      if (!td || !tr) return;
      if (td.cellIndex !== 0) return; // solo prima cella (Voce)

      const id = tr.dataset.id;
      if (!childrenOf[id] || !childrenOf[id].length) return;

      if (collapsedIds.has(id)) collapsedIds.delete(id);
      else collapsedIds.add(id);

      const caret = tr.querySelector(".tree-caret");
      if (caret) caret.style.transform = collapsedIds.has(id) ? "rotate(-90deg)" : "rotate(0deg)";

      // mostra/nascondi discendenti
      const hideBranch = collapsedIds.has(id);
      const stack = [...(childrenOf[id] || [])];
      while (stack.length) {
        const childId = stack.pop();
        const row = qs(`tr.tree-row[data-id="${childId}"]`);
        if (row) row.style.display = hideBranch || isHiddenByCollapse(childId) ? "none" : "";

        if (childrenOf[childId] && childrenOf[childId].length) {
          stack.push(...childrenOf[childId]);
        }
      }
    }, true);

    // Change radio: salva valore
    table.addEventListener("change", (e) => {
      const inp = e.target;
      if (!(inp instanceof HTMLInputElement)) return;
      if (inp.type !== 'radio') return;

      const id = inp.dataset.id;
      const val = inp.value; // si | no | na
      if (!dataMap[id]) dataMap[id] = { v: null, note: "" };
      dataMap[id].v = val;
      scheduleSave();
    }, true);

    // Input note: salva testo
    table.addEventListener("input", (e) => {
      const ta = e.target;
      if (!(ta instanceof HTMLTextAreaElement)) return;
      const id = ta.dataset.id;
      const val = cleanStr(ta.value, 2000);
      if (!dataMap[id]) dataMap[id] = { v: null, note: "" };
      dataMap[id].note = val;
      scheduleSave();
    }, true);

    // Note generali
    qs('#vrtp-note-generali')?.addEventListener('input', (e) => {
      noteGenerali = cleanStr(e.target.value, 5000);
      scheduleSave();
    });
  }

  // ===== Persistenza =====
  async function saveForm() {
    try {
      const payloadData = { items: dataMap, noteGenerali };
      const jsonOK = JSON.stringify(payloadData);
      if (!jsonOK) {
        showStatus("Dati non validi (JSON).", true);
        return;
      }
      const body = {
        id: formId || 0,
        tabella,
        tipo: TIPO,
        titolo: "VRTP - Verifica Requisiti Tecnico-Professionali",
        data: payloadData
      };
      const res = await customFetch("commesse", "saveSicurezzaForm", body);
      if (res?.success) {
        if (!formId && res.id) formId = parseInt(res.id, 10) || 0;
        showStatus("Salvato");
      } else {
        showStatus(res?.message || "Errore salvataggio", true);
      }
    } catch (err) {
      console.error(err);
      showStatus("Errore di rete", true);
    }
  }

  async function loadForm() {
    try {
      // prendo l’ultimo form VRTP per questa tabella (se esiste)
      const list = await customFetch("commesse", "listSicurezzaForms", { tabella, tipo: TIPO, q: "" });
      if (list?.success && Array.isArray(list.items) && list.items.length > 0) {
        const last = list.items[0]; // sono già in ordine per updated_at DESC
        formId = parseInt(last.id, 10) || 0;
        const det = await customFetch("commesse", "getSicurezzaForm", { id: formId, tabella });
        if (det?.success && det.form) {
          const d = det.form.data || {};
          if (d.items && typeof d.items === 'object') dataMap = d.items;
          noteGenerali = d.noteGenerali || "";
        }
      }
    } catch (err) {
      console.warn("loadForm fallback: inizio vuoto", err);
    }
  }

  function showStatus(msg, error = false) {
    const el = qs('#vrtp-status');
    if (!el) return;
    el.textContent = msg;
    el.style.color = error ? '#c62828' : '#888';
  }

  // ===== Init =====
  async function start() {
    if (!tabella) {
      showStatus("Tabella non specificata", true);
      return;
    }
    await loadForm();
    render();
    showStatus("Pronto");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
