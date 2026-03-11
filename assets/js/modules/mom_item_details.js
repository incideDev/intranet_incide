/**
 * MOM ITEM DETAILS DRAWER
 * Gestisce i dettagli estesi di un punto del MOM (Checklist e Log Attività)
 */

(function () {
  "use strict";

  if (!window.GlobalDrawer) return;

  // === STATE ===
  let state = {
    itemId: null,
    itemData: null,
    checklist: [],
    activities: [],
    currentTab: "overview",
  };

  // === HTML TEMPLATES ===
  function getHtmlTemplate() {
    return `
            <div class="mom-item-details-tabs">
                <button class="mom-item-details-tab active" data-tab="overview">Overview</button>
                <button class="mom-item-details-tab" data-tab="checklist">Checklist</button>
                <button class="mom-item-details-tab" data-tab="activity">Attività</button>
            </div>
            
            <div class="mom-item-tab-content" data-tab-content="overview">
                <div id="mom-item-overview-body" class="mom-item-details-body">
                    <div class="loading-spinner">Caricamento...</div>
                </div>
            </div>
            
            <div class="mom-item-tab-content" data-tab-content="checklist" style="display: none;">
                <div class="checklist-section">
                    <div class="checklist-header">
                        <span class="checklist-count" id="mom-checklist-count">0/0</span>
                    </div>
                    <div id="mom-checklist-items" class="checklist-items-container">
                        <!-- Checklist items here -->
                    </div>
                    <div class="checklist-add-form">
                        <input type="text" id="mom-checklist-new-label" placeholder="Aggiungi elemento alla checklist..." class="checklist-input">
                        <button id="mom-checklist-add-btn" class="checklist-add-btn">
                            <i class="fas fa-plus"></i> Aggiungi
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="mom-item-tab-content" data-tab-content="activity" style="display: none;">
                <div id="mom-item-activity-body" class="mom-activity-log">
                    <div class="loading-spinner">Caricamento attività...</div>
                </div>
            </div>

            <style>
                .mom-item-details-tabs {
                    display: flex;
                    border-bottom: 1px solid #ddd;
                    margin-bottom: 15px;
                    gap: 5px;
                }
                .mom-item-details-tab {
                    padding: 10px 15px;
                    border: none;
                    background: none;
                    cursor: pointer;
                    font-weight: 500;
                    color: #666;
                    border-bottom: 2px solid transparent;
                    transition: all 0.2s;
                }
                .mom-item-details-tab.active {
                    color: #c0392b;
                    border-bottom-color: #c0392b;
                }
                .mom-item-details-body {
                    display: grid;
                    gap: 15px;
                    padding: 10px;
                }
                .mom-detail-field {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                }
                .mom-detail-field label {
                    font-size: 11px;
                    font-weight: bold;
                    color: #888;
                    text-transform: uppercase;
                }
                .mom-detail-field .value {
                    font-size: 14px;
                    padding: 8px;
                    background: #f9f9f9;
                    border-radius: 4px;
                    border: 1px solid #eee;
                }
                .mom-detail-field .value-editable {
                    font-size: 14px;
                    padding: 8px;
                    background: #fff;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                    width: 100%;
                    box-sizing: border-box;
                    font-family: inherit;
                    color: inherit;
                }
                .mom-detail-field .value-editable:focus {
                    outline: none;
                    border-color: #3498DB;
                }
                .checklist-items-container {
                    max-height: 400px;
                    overflow-y: auto;
                    margin-bottom: 15px;
                    border: 1px solid #eee;
                    border-radius: 4px;
                }
                .checklist-item {
                    display: flex;
                    align-items: center;
                    padding: 10px;
                    border-bottom: 1px solid #eee;
                    gap: 10px;
                }
                .checklist-item:last-child { border-bottom: none; }
                .checklist-item:hover { background: #fcfcfc; }
                .checklist-item-checkbox {
                    width: 18px;
                    height: 18px;
                    cursor: pointer;
                }
                .checklist-item-label {
                    flex: 1;
                    font-size: 14px;
                }
                .checklist-item.done .checklist-item-label {
                    text-decoration: line-through;
                    color: #888;
                }
                .checklist-item-delete {
                    background: none;
                    border: none;
                    color: #ddd;
                    cursor: pointer;
                    font-size: 14px;
                    transition: color 0.2s;
                }
                .checklist-item-delete:hover { color: #c0392b; }
                .checklist-item-meta {
                    font-size: 10px;
                    color: #aaa;
                    margin-top: 2px;
                }
                .checklist-add-form {
                    display: flex;
                    gap: 8px;
                }
                .checklist-input {
                    flex: 1;
                    padding: 8px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .checklist-add-btn {
                    padding: 8px 15px;
                    background: #c0392b;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                }
                .mom-activity-log {
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                }
                .activity-item {
                    padding: 10px;
                    border-left: 2px solid #ddd;
                    background: #f9f9f9;
                    font-size: 13px;
                }
                .activity-header {
                    display: flex;
                    justify-content: space-between;
                    font-size: 11px;
                    color: #888;
                    margin-bottom: 5px;
                }
                .activity-action { font-weight: bold; color: #333; }
                .loading-spinner { padding: 20px; text-align: center; color: #888; }
            </style>
        `;
  }

  // === REGISTER VIEW ===
  window.GlobalDrawer.registerView("momItemDetails", (itemId) => {
    state.itemId = itemId;
    state.currentTab = "overview";

    return {
      title: "Dettagli Item MOM",
      html: getHtmlTemplate(),
      onReady: (container) => {
        setupEventListeners(container);
        loadItemData(itemId, container);
      },
      onClose: () => {
        state.itemId = null;
        state.itemData = null;
        state.checklist = [];
      },
    };
  });

  // === PRIVATE LOGIC ===

  function setupEventListeners(container) {
    // Tab switching
    const tabs = container.querySelectorAll(".mom-item-details-tab");
    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        const tabName = tab.getAttribute("data-tab");
        switchTab(tabName, container);
      });
    });

    // Checklist Add
    const addBtn = container.querySelector("#mom-checklist-add-btn");
    const addInput = container.querySelector("#mom-checklist-new-label");
    if (addBtn && addInput) {
      const handleAdd = async () => {
        const label = addInput.value.trim();
        if (!label) return;

        addBtn.disabled = true;
        const res = await window.customFetch("checklists", "add", {
          entityType: "mom_item",
          entityId: state.itemId,
          label,
        });
        addBtn.disabled = false;

        if (res.success) {
          addInput.value = "";
          loadChecklist(container);
          notifyChange();
        } else {
          window.showToast?.(res.message || "Errore", "error");
        }
      };
      addBtn.addEventListener("click", handleAdd);
      addInput.addEventListener("keypress", (e) => {
        if (e.key === "Enter") handleAdd();
      });
    }
  }

  function switchTab(tabName, container) {
    state.currentTab = tabName;

    container
      .querySelectorAll(".mom-item-details-tab")
      .forEach((t) =>
        t.classList.toggle("active", t.getAttribute("data-tab") === tabName),
      );

    container
      .querySelectorAll(".mom-item-tab-content")
      .forEach(
        (c) =>
          (c.style.display =
            c.getAttribute("data-tab-content") === tabName ? "block" : "none"),
      );

    if (tabName === "checklist") loadChecklist(container);
    if (tabName === "activity") loadActivity(container);
  }

  async function loadItemData(itemId, container) {
    try {
      const res = await window.customFetch("mom", "getItemDetails", { itemId });
      if (res.success) {
        state.itemData = res.data;
        renderOverview(container);
      } else {
        container.innerHTML = `<div class="p-3 text-red-500">${res.message}</div>`;
      }
    } catch (e) {
      console.error(e);
    }
  }

  function renderOverview(container) {
    const body = container.querySelector("#mom-item-overview-body");
    if (!body) return;

    const d = state.itemData;
    const typeMap = {
      AI: "Azione Immediata",
      OBS: "Osservazione",
      EVE: "Evento",
    };

    // Controlla se il campo nella tabella è readonly per applicarlo qui
    let isReadOnlyAttr = "";
    let isDisabledAttr = "";
    let rowResponsabileOptions = '<option value="">Seleziona...</option>';
    let rowStatoOptions = '<option value="">Seleziona...</option>';
    let activeResponsabile = d.responsabile || "";
    let activeStato = d.stato || "Aperta";
    let activeDataTarget = d.data_target ? d.data_target.split('T')[0] : "";

    const tableContainer = document.getElementById("mom-items-container");
    if (tableContainer) {
      const rows = tableContainer.querySelectorAll("tbody tr");
      for (let row of rows) {
        const idInput = row.querySelector("input.row-id");
        if (idInput && idInput.value == state.itemId) {
          const rowInput = row.querySelector('[data-field="titolo"]');
          if (rowInput && (rowInput.readOnly || rowInput.disabled)) {
            isReadOnlyAttr = "readonly";
            isDisabledAttr = "disabled";
          }
          
          const responsabileSelect = row.querySelector('[data-field="responsabile"]');
          if (responsabileSelect) {
            rowResponsabileOptions = responsabileSelect.innerHTML;
            activeResponsabile = responsabileSelect.value;
          }
          
          const statoSelect = row.querySelector('[data-field="stato"]');
          if (statoSelect) {
            rowStatoOptions = statoSelect.innerHTML;
            activeStato = statoSelect.value;
          }
          
          const dataInput = row.querySelector('[data-field="dataTarget"]');
          if (dataInput) {
            activeDataTarget = dataInput.value;
          }

          break;
        }
      }
    }

    body.innerHTML = `
            <div class="mom-detail-field">
                <label>Verbale / Protocollo</label>
                <div class="value" style="font-weight: 500;">${d.mom_protocollo || "—"}</div>
            </div>
            <div class="mom-detail-field">
                <label>Tipo</label>
                <div class="value">${typeMap[d.item_type] || d.item_type}</div>
            </div>
            <div class="mom-detail-field">
                <label>Codice Item</label>
                <div class="value">${d.item_code || "—"}</div>
            </div>
            <div class="mom-detail-field">
                <label>Titolo</label>
                <input type="text" id="mom-item-drawer-titolo" class="value-editable" value="${(window.escapeHtml?.(d.titolo) || d.titolo || "").replace(/"/g, "&quot;")}" ${isReadOnlyAttr}>
            </div>
            <div class="mom-detail-field">
                <label>Descrizione</label>
                <textarea id="mom-item-drawer-descrizione" class="value-editable" rows="4" style="resize:vertical;" ${isReadOnlyAttr}>${window.escapeHtml?.(d.descrizione) || d.descrizione || ""}</textarea>
            </div>
            <div class="mom-detail-field">
                <label>Responsabile</label>
                <select id="mom-item-drawer-responsabile" class="value-editable" ${isDisabledAttr}>
                    ${rowResponsabileOptions}
                </select>
            </div>
            <div class="mom-detail-field">
                <label>Data Target</label>
                <input type="date" id="mom-item-drawer-data" class="value-editable" value="${activeDataTarget}" ${isReadOnlyAttr}>
            </div>
            <div class="mom-detail-field">
                <label>Stato</label>
                <select id="mom-item-drawer-stato" class="value-editable" ${isDisabledAttr}>
                    ${rowStatoOptions}
                </select>
            </div>
            </div>
        `;

    // Initialize values for selects dynamically to avoid selected attribute issues
    const selectResponsabile = body.querySelector("#mom-item-drawer-responsabile");
    const selectStato = body.querySelector("#mom-item-drawer-stato");
    
    if (selectResponsabile) selectResponsabile.value = activeResponsabile;
    if (selectStato) selectStato.value = activeStato;

    // Sync inputs back to the main MOM table in real-time
    const inputTitolo = body.querySelector("#mom-item-drawer-titolo");
    const inputDescrizione = body.querySelector("#mom-item-drawer-descrizione");
    const inputData = body.querySelector("#mom-item-drawer-data");

    const syncToTable = (field, value) => {
      const tableContainer = document.getElementById("mom-items-container");
      if (!tableContainer) return;
      const rows = tableContainer.querySelectorAll("tbody tr");
      for (let row of rows) {
        const idInput = row.querySelector("input.row-id");
        if (idInput && idInput.value == state.itemId) {
          const targetInput = row.querySelector(`[data-field="${field}"]`);
          if (targetInput) {
            targetInput.value = value;
            targetInput.dispatchEvent(new Event("change", { bubbles: true }));
             if(targetInput.tagName === 'INPUT' || targetInput.tagName === 'TEXTAREA') {
                targetInput.dispatchEvent(new Event("input", { bubbles: true }));
             }
          }
          break;
        }
      }
    };

    if (inputTitolo) {
      inputTitolo.addEventListener("input", (e) =>
        syncToTable("titolo", e.target.value),
      );
    }
    if (inputDescrizione) {
      inputDescrizione.addEventListener("input", (e) =>
        syncToTable("descrizione", e.target.value),
      );
    }
    if (selectResponsabile) {
      selectResponsabile.addEventListener("change", (e) =>
        syncToTable("responsabile", e.target.value),
      );
    }
    if (inputData) {
      inputData.addEventListener("change", (e) =>
        syncToTable("dataTarget", e.target.value),
      );
    }
    if (selectStato) {
      selectStato.addEventListener("change", (e) =>
        syncToTable("stato", e.target.value),
      );
    }
  }

  async function loadChecklist(container) {
    const listContainer = container.querySelector("#mom-checklist-items");
    if (!listContainer) return;

    try {
      const res = await window.customFetch("checklists", "list", {
        entityType: "mom_item",
        entityId: state.itemId,
      });
      if (res.success) {
        state.checklist = res.data;
        renderChecklist(container);
      }
    } catch (e) {
      console.error(e);
    }
  }

  function renderChecklist(container) {
    const listContainer = container.querySelector("#mom-checklist-items");
    const countEl = container.querySelector("#mom-checklist-count");
    if (!listContainer) return;

    if (state.checklist.length === 0) {
      listContainer.innerHTML =
        '<div class="p-4 text-center text-gray-400">Nessun elemento nella checklist</div>';
      if (countEl) countEl.textContent = "0/0";
      return;
    }

    const doneCount = state.checklist.filter((i) => i.is_done).length;
    if (countEl) countEl.textContent = `${doneCount}/${state.checklist.length}`;

    listContainer.innerHTML = state.checklist
      .map(
        (item) => `
            <div class="checklist-item ${item.is_done ? "done" : ""}" data-id="${item.id}">
                <input type="checkbox" class="checklist-item-checkbox" ${item.is_done ? "checked" : ""}>
                <div class="checklist-item-label">
                    ${window.escapeHtml?.(item.label) || item.label}
                    ${
                      item.is_done
                        ? `
                        <div class="checklist-item-meta">
                            Completato da ${item.done_by_name || "Utente"} il ${new Date(item.done_at).toLocaleString()}
                        </div>
                    `
                        : ""
                    }
                </div>
                <button class="checklist-item-delete" title="Elimina"><i class="fas fa-times"></i></button>
            </div>
        `,
      )
      .join("");

    // Listeners for toggle and delete
    listContainer.querySelectorAll(".checklist-item").forEach((row) => {
      const id = row.getAttribute("data-id");
      row.querySelector(".checklist-item-checkbox").onchange = async (e) => {
        const checked = e.target.checked;
        const res = await window.customFetch("checklists", "toggle", { id });
        if (res.success) {
          loadChecklist(container);
          notifyChange();
        }
      };
      row.querySelector(".checklist-item-delete").onclick = async () => {
        if (!confirm("Eliminare questo elemento?")) return;
        const res = await window.customFetch("checklists", "delete", { id });
        if (res.success) {
          loadChecklist(container);
          notifyChange();
        }
      };
    });
  }

  async function loadActivity(container) {
    const body = container.querySelector("#mom-item-activity-body");
    if (!body) return;

    try {
      const res = await window.customFetch("mom", "getItemActivity", {
        itemId: state.itemId,
      });
      if (res.success) {
        state.activities = res.data;
        renderActivity(container);
      }
    } catch (e) {
      body.innerHTML =
        '<div class="p-3 text-red-500">Errore caricamento attività</div>';
    }
  }

  function renderActivity(container) {
    const body = container.querySelector("#mom-item-activity-body");
    if (!body) return;

    if (state.activities.length === 0) {
      body.innerHTML =
        '<div class="p-4 text-center text-gray-400">Nessuna attività registrata</div>';
      return;
    }

    const actionMap = {
      CHECKLIST_ADD: "Aggiunto elemento checklist",
      CHECKLIST_DONE: "Completato elemento checklist",
      CHECKLIST_UNDONE: "Ripristinato elemento checklist",
      CHECKLIST_DELETE: "Eliminato elemento checklist",
    };

    body.innerHTML = state.activities
      .map(
        (a) => `
            <div class="activity-item">
                <div class="activity-header">
                    <span>${a.user_name || "Utente"}</span>
                    <span>${new Date(a.created_at).toLocaleString()}</span>
                </div>
                <div class="activity-action">${actionMap[a.action] || a.action}</div>
                ${a.meta_json ? `<div class="activity-meta" style="font-size:11px; color:#888; margin-top:4px;">${JSON.parse(a.meta_json).label || ""}</div>` : ""}
            </div>
        `,
      )
      .join("");
  }

  function notifyChange() {
    // Notifica il modulo principale che l'item è stato aggiornato
    // (es. per aggiornare lo stato di completamento se visibile nella riga)
    const event = new CustomEvent("mom:itemUpdated", {
      detail: { itemId: state.itemId },
    });
    document.dispatchEvent(event);
  }

  // === PUBLIC API ===
  window.MomItemDetails = {
    open: (itemId) => window.GlobalDrawer.openView("momItemDetails", itemId),
    close: () => window.GlobalDrawer.close(),
  };
})();
