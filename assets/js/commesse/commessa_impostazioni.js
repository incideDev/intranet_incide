(function () {
    if (window.__commessaImpostazioniInitDone) return;
    window.__commessaImpostazioniInitDone = true;

    const start = async () => {
        // workspace/commessa corrente (serve per i setting per-commessa)
        const tabella = (window._tabellaCommessa || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();

        // === Helpers ===
        const clamp = (n, min, max) => Math.max(min, Math.min(max, n | 0));
        const cleanStr = (v, max = 120) =>
            ((v ?? "").toString().replace(/[\u0000-\u001F]+/g, "").trim()).slice(0, max);
        const cleanCode = (v) =>
            cleanStr((v ?? "").toString().replace(/[^A-Za-z0-9_\-\.]/g, ""), 32);

        // Tabelloni gestiti nella pagina impostazioni
        const tables = [
            { type: "tipi_documento", el: "#table-tipi-documento", title: "Tipo documento", kind: "base" },
            { type: "sic_docs", el: "#table-doc-sicurezza", title: "Documento sicurezza", kind: "sic" },
            { type: "ruoli", el: "#table-ruoli", title: "Ruolo", kind: "base" },
            { type: "tipi_impresa", el: "#table-tipi-impresa", title: "Tipo impresa", kind: "base" },
        ];

        // Pagine modulo ammesse (hardcoded, come richiesto)
        const MODULE_PAGES = {
            sic_vpos: "Verifica POS",
            sic_vrtp: "Verifica Requisiti Tec.-Prof.",
            sic_vcs: "Verbale Riunione Coordinamento",
            sic_vvcs: "Verbale Visita in Cantiere"
        };

        // debounce per autosave per-riga
        const saveTimers = new WeakMap();
        function scheduleSave(type, tr, delay = 150) {
            if (saveTimers.has(tr)) clearTimeout(saveTimers.get(tr));
            const t = setTimeout(() => saveRow(type, tr), delay);
            saveTimers.set(tr, t);
        }

        // ==== HTML cella azioni (unica funzione, niente duplicazioni) ====
        function actionCellHTML(isNew) {
            const tip = isNew ? "Annulla" : "Elimina";
            return `
        <td class="azioni-colonna" style="white-space:nowrap;">
          <button class="action-icon" data-tooltip="${tip}" aria-label="${tip}">
            <img src="/assets/icons/delete.png" alt="${tip}" class="icon-delete">
          </button>
        </td>`;
        }

        // === RENDER ===
        function renderRow(tbody, type, row, kind = "base") {
            const tr = document.createElement("tr");
            tr.dataset.id = row.id;

            if (kind === "sic") {
                const codice = row.codice ?? "";
                const nome = row.nome ?? "";
                const tipo = (row.tipo || "upload").toLowerCase();
                const pagina = row.pagina || "";

                const pageOptions = ['<option value="">—</option>']
                    .concat(Object.entries(MODULE_PAGES).map(([k, lbl]) =>
                        `<option value="${k}" ${pagina === k ? "selected" : ""}>${lbl}</option>`
                    )).join("");

                tr.innerHTML = `
          ${actionCellHTML(false)}
          <td><input class="in-code" value="${codice}" maxlength="32" data-tooltip="Codice"></td>
          <td><input class="in-nome" value="${nome}" maxlength="120" data-tooltip="Nome"></td>
          <td>
            <select class="sel-tipo" data-tooltip="Scegli se è un Upload o un Modulo">
              <option value="upload" ${tipo === "upload" ? "selected" : ""}>Upload</option>
              <option value="modulo" ${tipo === "modulo" ? "selected" : ""}>Modulo</option>
            </select>
          </td>
          <td>
            <select class="sel-pagina" ${tipo === "modulo" ? "" : "disabled"} data-tooltip="Pagina modulo (se Tipo=Modulo)">
              ${pageOptions}
            </select>
          </td>`;
            } else {
                tr.innerHTML = `
          ${actionCellHTML(false)}
          <td><input class="in-code" value="${row.codice ?? ""}" maxlength="32" data-tooltip="Codice"></td>
          <td><input class="in-nome" value="${row.nome ?? ""}" maxlength="120" data-tooltip="Nome"></td>`;
            }

            tbody.appendChild(tr);
            attachAutosaveHandlers(type, tr, kind);
        }

        function insertNewRowAtTop(type, tableSelector, kind = "base") {
            const table = document.querySelector(tableSelector);
            const tbody = table?.querySelector("tbody");
            if (!tbody) return;

            const tr = document.createElement("tr");
            tr.dataset.id = "0";

            if (kind === "sic") {
                const pageOptions = ['<option value="">—</option>']
                    .concat(Object.entries(MODULE_PAGES).map(([k, lbl]) => `<option value="${k}">${lbl}</option>`))
                    .join("");

                tr.innerHTML = `
          ${actionCellHTML(true)}
          <td><input class="in-code" value="" maxlength="32" placeholder="Codice" data-tooltip="Codice (opzionale)"></td>
          <td><input class="in-nome" value="" maxlength="120" placeholder="Nome *" data-tooltip="Nome obbligatorio"></td>
          <td>
            <select class="sel-tipo" data-tooltip="Scegli se è un Upload o un Modulo">
              <option value="upload" selected>Upload</option>
              <option value="modulo">Modulo</option>
            </select>
          </td>
          <td>
            <select class="sel-pagina" disabled data-tooltip="Pagina modulo (se Tipo=Modulo)">
              ${pageOptions}
            </select>
          </td>`;
            } else {
                tr.innerHTML = `
          ${actionCellHTML(true)}
          <td><input class="in-code" value="" maxlength="32" placeholder="Codice" data-tooltip="Codice (opzionale)"></td>
          <td><input class="in-nome" value="" maxlength="120" placeholder="Nome *" data-tooltip="Nome obbligatorio"></td>`;
            }

            tbody.prepend(tr);
            attachAutosaveHandlers(type, tr, kind);
            tr.querySelector(".in-nome")?.focus();
        }

        function attachAutosaveHandlers(type, tr, kind = "base") {
            const codeEl = tr.querySelector(".in-code");
            const nomeEl = tr.querySelector(".in-nome");

            [codeEl, nomeEl].forEach((inp) => {
                if (!inp) return;
                const scheduleIfOk = () => {
                    const nomeOk = (nomeEl?.value || "").trim() !== "";
                    if (nomeOk) scheduleSave(type, tr);
                };
                inp.addEventListener("blur", scheduleIfOk);
                inp.addEventListener("change", scheduleIfOk);
                inp.addEventListener("keydown", (e) => {
                    if (e.key === "Enter") { e.preventDefault(); saveRow(type, tr); }
                });
            });

            if (kind === "sic") {
                const selTipo = tr.querySelector(".sel-tipo");
                const selPagina = tr.querySelector(".sel-pagina");

                selTipo?.addEventListener("change", () => {
                    if (selPagina) selPagina.disabled = (selTipo.value !== "modulo");
                    scheduleSave(type, tr);
                });
                selPagina?.addEventListener("change", () => scheduleSave(type, tr));
            }
        }

        // === API ===
        async function loadTable(type, tableSelector) {
            const table = document.querySelector(tableSelector);
            const tbody = table?.querySelector("tbody");
            if (!tbody) return;
            tbody.innerHTML = "";

            const cfg = tables.find(t => t.type === type);
            const kind = cfg?.kind || "base";

            try {
                const payload = (type === "sic_docs") ? { type, tabella } : { type };
                const res = await customFetch("commesse", "listSettings", payload);
                if (!res?.success || !Array.isArray(res.rows)) {
                    showToast?.("Errore caricamento " + (cfg?.title || type), "error");
                    return;
                }
                res.rows.forEach((r) => renderRow(tbody, type, r, kind));

                if (typeof window.makeTableFilterable === "function") {
                    window.makeTableFilterable(table);
                } else if (typeof window.initFilterableTables === "function") {
                    window.initFilterableTables();
                }
            } catch (e) {
                console.error(e);
                showToast?.("Errore di rete", "error");
            }
        }

        async function saveRow(type, tr) {
            const id = parseInt(tr.dataset.id || "0", 10) || 0;
            const code = cleanCode(tr.querySelector(".in-code")?.value || "");
            const nome = cleanStr(tr.querySelector(".in-nome")?.value || "", 120);

            if (!nome) {
                showToast?.("Nome obbligatorio", "error");
                tr.querySelector(".in-nome")?.focus();
                return;
            }

            const cfg = tables.find(t => t.type === type);
            const kind = cfg?.kind || "base";
            const isSic = (kind === "sic");

            let payload = { type, id, codice: code, nome };

            if (isSic) {
                const tipo = (tr.querySelector(".sel-tipo")?.value || "upload").toLowerCase();
                const pagina = (tr.querySelector(".sel-pagina")?.value || "").trim();

                if (tipo === "modulo" && !pagina) {
                    showToast?.("Se Tipo=Modulo seleziona una Pagina modulo", "error");
                    tr.querySelector(".sel-pagina")?.focus();
                    return;
                }
                payload.tabella = tabella;
                payload.tipo = tipo;
                payload.pagina = pagina;
            }

            const res = await customFetch("commesse", "saveSetting", payload);
            if (!res?.success) {
                showToast?.(res?.error || "Salvataggio non riuscito", "error");
                return;
            }
            showToast?.("Salvato");

            if (!id) {
                await loadTable(type, cfg.el);
            } else {
                tr.dataset.id = String(res.id || id);
            }
        }

        async function deleteRow(type, id) {
            try {
                const payload = (type === "sic_docs") ? { type, id, tabella } : { type, id };
                const res = await customFetch("commesse", "deleteSetting", payload);
                if (!res?.success) {
                    showToast?.(res?.error || "Eliminazione non riuscita", "error");
                    return;
                }
                showToast?.("Eliminato");
                await loadTable(type, tables.find((t) => t.type === type).el);
            } catch (e) {
                console.error(e);
                showToast?.("Errore di rete", "error");
            }
        }

        // === Bind (deleghe) ===
        function bindActions() {
            // Nuova riga (pulsanti sopra le tabelle)
            document.addEventListener("click", (ev) => {
                const btn = ev.target.closest(".btn-add-row");
                if (!btn) return;
                const type = btn.getAttribute("data-type");
                const cfg = tables.find((t) => t.type === type);
                if (!cfg) return;
                insertNewRowAtTop(type, cfg.el, cfg.kind || "base");
            });

            // Click elimina/annulla nelle righe (unificato)
            tables.forEach((t) => {
                const table = document.querySelector(t.el);
                if (!table) return;

                table.addEventListener("click", (ev) => {
                    const tr = ev.target.closest("tr");
                    if (!tr) return;

                    const btnDel = ev.target.closest(".action-icon");
                    if (btnDel) {
                        const id = parseInt(tr.dataset.id || "0", 10) || 0;
                        if (!id) { tr.remove(); return; } // riga nuova non salvata → annulla a vista
                        const confirmFn = (typeof window.showConfirm === "function")
                            ? () => showConfirm("Eliminare questa riga?", () => deleteRow(t.type, id))
                            : () => (window.confirm("Eliminare questa riga?") && deleteRow(t.type, id));
                        confirmFn();
                    }
                });
            });
        }

        // === Init ===
        bindActions();
        try {
            await Promise.all(tables.map((t) => loadTable(t.type, t.el)));
        } catch (e) {
            console.error("[impostazioni-sicurezza] init fallito:", e);
        }
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
