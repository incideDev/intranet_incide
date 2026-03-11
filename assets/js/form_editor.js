document.addEventListener("DOMContentLoaded", async function () {
    const formName = document.getElementById("form-name-editor").value;
    const preview = document.getElementById("form-fields-preview");
    const saveBtn = document.getElementById("save-form-btn");
    const fieldDashboard = document.querySelector(".field-dashboard");

    let fields = [];

    const CAMPI_FISSI = [
        { name: "titolo", type: "text", options: [], is_fixed: true },
        { name: "descrizione", type: "textarea", options: [], is_fixed: true },
        { name: "deadline", type: "date", options: [], is_fixed: true },
        { name: "priority", type: "select", options: ["Bassa", "Media", "Alta"], is_fixed: true },
        { name: "assegnato_a", type: "text", options: [], is_fixed: true }
    ];
    
    // Carica struttura iniziale
    async function loadFields() {
        preview.innerHTML = '<div class="loader">Caricamento...</div>';
        const data = await customFetch('page_editor', 'getForm', { form_name: formName });

        if (!data.success) {
            preview.innerHTML = `<p class="error">${data.error || "Errore nel caricamento struttura modulo."}</p>`;
            return;
        }

        // PRENDI TUTTI I CAMPI DINAMICI (non fissi)
        let dinamici = (data.fields || [])
            .filter(f => !CAMPI_FISSI.map(cf => cf.name).includes(f.field_name))
            .map(f => ({
                name: f.field_name,
                type: f.field_type,
                options: f.field_options ? JSON.parse(f.field_options) : [],
                is_fixed: f.is_fixed === 1 || f.is_fixed === true,
                parent_section_uid: f.parent_section_uid || ''
            }));

        // Elimina DOPPIONI: usa chiave composita (nome + tipo + parent_section_uid) per identificazione univoca
        // Questo è coerente con la logica backend che usa field_name + parent_section_uid
        const uniqueMap = {};
        dinamici = dinamici.filter(f => {
            const parentUid = (f.parent_section_uid || '').toLowerCase();
            const key = (f.name + "|" + f.type + "|" + parentUid).toLowerCase();
            if (uniqueMap[key]) return false;
            uniqueMap[key] = true;
            return true;
        });

        // Inserisci SEMPRE i campi fissi in testa
        fields = CAMPI_FISSI.map(cf => ({ ...cf })).concat(dinamici);

        renderPreview();
    }

    // Drag della Field dashboard 
    (function() {
        const dashboard = document.getElementById("field-dashboard");
        const dragHandle = document.getElementById("dashboard-drag-handle");
        let offsetX, offsetY, isDragging = false;
    
        dragHandle.addEventListener('mousedown', function(e) {
            isDragging = true;
            offsetX = e.clientX - dashboard.getBoundingClientRect().left;
            offsetY = e.clientY - dashboard.getBoundingClientRect().top;
            dashboard.style.transition = 'none';
            document.body.style.userSelect = "none";
            // Previeni selezione testo
            e.preventDefault();
        });
    
        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            dashboard.style.left = (e.clientX - offsetX) + 'px';
            dashboard.style.top = (e.clientY - offsetY) + 'px';
        });
    
        document.addEventListener('mouseup', function(e) {
            isDragging = false;
            document.body.style.userSelect = "";
            dashboard.style.transition = "";
        });
    })();
    
    // Renderizza l'anteprima drag&drop e modificabile
    function renderPreview() {
        preview.innerHTML = "";
    
        // Wrapper griglia per due colonne
        const grid = document.createElement("div");
        grid.style.display = "grid";
        grid.style.gridTemplateColumns = "1fr 1fr";
        grid.style.gap = "12px 12px"; // spazio tra colonne e righe
    
        fields.forEach((field, i) => {
            // CAMPI FISSI → solo preview super compatta
            if (field.is_fixed) {
                const group = document.createElement("div");
                group.className = "view-form-group editor-group";
                group.style.background = "#f5f5f5";
                group.style.padding = "10px 10px";
                group.style.border = "1px solid #e3e3e3";
                group.style.borderRadius = "7px";
                group.style.position = "relative";
                group.style.display = "flex";
                group.style.flexDirection = "column";
                group.style.minHeight = "45px";
                group.style.maxWidth = "310px";
                group.style.opacity = "0.8";
        
                // Inline editing: field_name (disabilitato)
                const fieldNameWrap = document.createElement("div");
                fieldNameWrap.style.display = "grid";
                fieldNameWrap.style.gridTemplateColumns = "90px 1fr";
                fieldNameWrap.style.alignItems = "center";
                fieldNameWrap.style.columnGap = "8px";
                fieldNameWrap.style.marginBottom = "0"; // Niente spazio sotto
        
                const fieldNameLabel = document.createElement("label");
                fieldNameLabel.textContent = "Nome campo:";
                fieldNameLabel.style.fontWeight = "600";
                fieldNameLabel.style.whiteSpace = "nowrap";
                fieldNameLabel.style.justifySelf = "end";
                fieldNameLabel.style.paddingRight = "3px";
                fieldNameWrap.appendChild(fieldNameLabel);
        
                const fieldNameInput = document.createElement("input");
                fieldNameInput.type = "text";
                fieldNameInput.value = field.name;
                fieldNameInput.className = "editor-fieldname-input";
                fieldNameInput.style.width = "120px";
                fieldNameInput.style.maxWidth = "100%";
                fieldNameInput.style.padding = "4px 8px";
                fieldNameInput.style.border = "1px solid #ddd";
                fieldNameInput.style.borderRadius = "4px";
                fieldNameInput.style.boxSizing = "border-box";
                fieldNameInput.style.background = "#ececec";
                fieldNameInput.style.color = "#aaa";
                fieldNameInput.disabled = true;
                fieldNameInput.title = "Campo fisso, non modificabile";
                fieldNameWrap.appendChild(fieldNameInput);
        
                group.appendChild(fieldNameWrap);
        
                grid.appendChild(group);
                return;
            }

            // Campi dinamici
            const group = document.createElement("div");
            group.className = "view-form-group editor-group";
            group.draggable = !field.is_fixed;
            if (field.is_fixed) group.style.opacity = "0.94";            
            group.dataset.index = i;
            group.style.background = "#fafcff";
            group.style.padding = "16px 14px";
            group.style.border = "1px solid #dde3e6";
            group.style.borderRadius = "7px";
            group.style.position = "relative";
            group.style.display = "flex";
            group.style.flexDirection = "column";
            group.style.minHeight = "110px";
            group.style.maxWidth = "310px";
    
            // Drag handle (per riordino)
            if (!field.is_fixed) {
                const dragHandle = document.createElement("span");
                dragHandle.className = "drag-handle";
                dragHandle.title = "Trascina per riordinare";
                dragHandle.innerHTML = "⠿";
                dragHandle.style.position = "absolute";
                dragHandle.style.left = "7px";
                dragHandle.style.top = "8px";
                dragHandle.style.cursor = "grab";
                group.appendChild(dragHandle);
            }
            
            // Inline editing: field_name
            const fieldNameWrap = document.createElement("div");
            fieldNameWrap.style.display = "grid";
            fieldNameWrap.style.gridTemplateColumns = "90px 1fr";
            fieldNameWrap.style.alignItems = "center";
            fieldNameWrap.style.columnGap = "8px";
            fieldNameWrap.style.marginBottom = "7px";

            const fieldNameLabel = document.createElement("label");
            fieldNameLabel.textContent = "Nome campo:";
            fieldNameLabel.style.fontWeight = "600";
            fieldNameLabel.style.whiteSpace = "nowrap";
            fieldNameLabel.style.justifySelf = "end";
            fieldNameLabel.style.paddingRight = "3px";
            fieldNameWrap.appendChild(fieldNameLabel);

            const fieldNameInput = document.createElement("input");
            fieldNameInput.type = "text";
            fieldNameInput.value = field.name;
            fieldNameInput.className = "editor-fieldname-input";
            fieldNameInput.style.width = "140px";
            fieldNameInput.style.maxWidth = "100%";
            fieldNameInput.style.padding = "4px 8px";
            fieldNameInput.style.border = "1px solid #ddd";
            fieldNameInput.style.borderRadius = "4px";
            fieldNameInput.style.boxSizing = "border-box";
            fieldNameInput.disabled = !!field.is_fixed;
            if (field.is_fixed) {
                fieldNameInput.style.background = "#f3f3f3";
                fieldNameInput.style.color = "#999";
                fieldNameInput.title = "Campo fisso, non modificabile";
            }
            fieldNameInput.addEventListener("input", function (e) {
                field.name = e.target.value.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
            });
            fieldNameWrap.appendChild(fieldNameInput);

            group.appendChild(fieldNameWrap);

            // Inline editing: tipo campo
            const typeWrap = document.createElement("div");
            typeWrap.style.display = "flex";
            typeWrap.style.alignItems = "center";
            typeWrap.style.marginBottom = "7px";
            const typeLabel = document.createElement("label");
            typeLabel.textContent = "Tipo:";
            typeLabel.style.marginRight = "7px";
            typeWrap.appendChild(typeLabel);
    
            if (!field.is_fixed) {
                const typeSelect = document.createElement("select");
                ["text", "textarea", "select", "checkbox", "radio", "file", "date"].forEach(opt => {
                    const o = document.createElement("option");
                    o.value = opt;
                    o.textContent = opt;
                    if (opt === field.type) o.selected = true;
                    typeSelect.appendChild(o);
                });
                typeSelect.style.padding = "4px 8px";
                typeSelect.style.borderRadius = "4px";
                typeSelect.onchange = e => {
                    field.type = e.target.value;
                    if (!["select", "checkbox", "radio"].includes(field.type)) {
                        field.options = [];
                    }
                    renderPreview();
                };
                typeWrap.appendChild(typeSelect);
            } else {
                const typeDisplay = document.createElement("span");
                typeDisplay.className = "editor-type-fixed";
                typeDisplay.textContent = field.type;
                typeDisplay.style.fontWeight = "bold";
                typeWrap.appendChild(typeDisplay);
            }
    
            group.appendChild(typeWrap);
    
            // Inline editing: opzioni (solo select, checkbox, radio)
            if (["select", "checkbox", "radio"].includes(field.type)) {
                const optionList = document.createElement("div");
                optionList.className = "option-list";
                optionList.style.display = "flex";
                optionList.style.flexDirection = "column";
                (field.options || []).forEach((opt, idx) => {
                    const row = document.createElement("div");
                    row.className = "option-row";
                    row.style.display = "flex";
                    row.style.alignItems = "center";
                    row.style.marginBottom = "4px";
                    const input = document.createElement("input");
                    input.type = "text";
                    input.value = opt;
                    input.style.flex = "1";
                    input.style.padding = "2px 7px";
                    input.style.border = "1px solid #eee";
                    input.style.marginRight = "7px";
                    input.oninput = e => field.options[idx] = e.target.value;
                    row.appendChild(input);
    
                    // Rimuovi opzione
                    const del = document.createElement("span");
                    del.className = "option-remove";
                    del.innerHTML = "✖";
                    del.title = "Rimuovi opzione";
                    del.style.cursor = "pointer";
                    del.onclick = () => {
                        field.options.splice(idx, 1);
                        renderPreview();
                    };
                    row.appendChild(del);
                    optionList.appendChild(row);
                });
    
                // Aggiungi opzione
                const addOpt = document.createElement("button");
                addOpt.type = "button";
                addOpt.className = "button small";
                addOpt.textContent = "+ Aggiungi Opzione";
                addOpt.style.marginTop = "3px";
                addOpt.onclick = e => {
                    e.preventDefault();
                    field.options.push("");
                    renderPreview();
                };
                optionList.appendChild(addOpt);
                group.appendChild(optionList);
            }
    
            // Elimina campo (solo se non fisso)
            if (!field.is_fixed) {
                const remove = document.createElement("img");
                remove.className = "field-remove";
                remove.src = "assets/icons/delete.png";
                remove.alt = "Rimuovi";
                remove.title = "Rimuovi campo";
                remove.style.position = "absolute";
                remove.style.top = "7px";
                remove.style.right = "8px";
                remove.style.cursor = "pointer";
                remove.style.width = "14px";
                remove.style.height = "14px";
                remove.onclick = () => {
                    fields.splice(i, 1);
                    renderPreview();
                };
                group.appendChild(remove);
            }            
            grid.appendChild(group);
        });
    
        if (fields.length === 0) {
            preview.innerHTML = "<p class='empty'>Trascina un campo dalla destra o crea un nuovo modulo.</p>";
        } else {
            preview.appendChild(grid);
        }
    }
    
    // Drag&Drop riordino
    let draggedIndex = null;

    preview.addEventListener("dragstart", function (e) {
        const target = e.target.closest(".editor-group");
        if (!target) return;
        draggedIndex = +target.dataset.index;
        setTimeout(() => target.classList.add("dragging"), 1);
    });

    preview.addEventListener("dragend", function (e) {
        const target = e.target.closest(".editor-group");
        if (!target) return;
        target.classList.remove("dragging");
    });

    preview.addEventListener("dragover", function (e) {
        e.preventDefault();
        const target = e.target.closest(".editor-group");
        if (!target || target.classList.contains("dragging")) return;
        const overIndex = +target.dataset.index;
        if (draggedIndex === null || overIndex === draggedIndex) return;
        // Riordina
        const field = fields.splice(draggedIndex, 1)[0];
        fields.splice(overIndex, 0, field);
        draggedIndex = overIndex;
        renderPreview();
    });

    // Drop NUOVO campo da dashboard
    fieldDashboard.querySelectorAll(".field-card").forEach(card => {
        card.addEventListener("dragstart", function (e) {
            e.dataTransfer.setData("field-type", this.dataset.type);
            e.dataTransfer.effectAllowed = "copy";
        });
    });

    preview.addEventListener("dragover", function (e) {
        e.preventDefault();
    });

    preview.addEventListener("drop", function (e) {
        e.preventDefault();
        const fieldType = e.dataTransfer.getData("field-type");

        // BLOCCO campo upload multiplo
        if (fieldType === "file" && fields.some(f => f.type === "file")) {
            showToast("È consentito un solo campo upload per modulo.", "error");
            return;
        }

        if (fieldType) {
            fields.push({
                name: "",
                type: fieldType,
                options: ["select", "checkbox", "radio"].includes(fieldType) ? ["Opzione 1"] : [],
                is_fixed: false
            });
            renderPreview();
            setTimeout(() => {
                const inputs = preview.querySelectorAll(".editor-fieldname-input");
                if (inputs.length) {
                    inputs[inputs.length - 1].focus();
                }
            }, 0);            
        }
        draggedIndex = null;
    });

    // Salvataggio massivo via AJAX
    saveBtn.onclick = function () {
        // Chiedi conferma SEMPRE prima di salvare, con messaggio diverso se i campi sono vuoti
        if (fields.length === 0) {
            showConfirm("Stai per salvare un modulo vuoto, verranno rimossi tutti i campi. Sei sicuro?", function () {
                doSaveForm();
            });
        } else {
            showConfirm("Sei sicuro di voler salvare la nuova struttura del modulo?", function () {
                doSaveForm();
            });
        }
    };
    
    async function doSaveForm() {
        // Valida
        for (let f of fields) {
            if (!f.name || !f.name.trim()) {
                showToast("Tutti i campi devono avere un nome valido.", "error");
                return;
            }
            // Valida SOLO i campi NON fissi
            if (!f.is_fixed && ["select", "checkbox", "radio"].includes(f.type)) {
                if (!f.options || f.options.length === 0 || f.options.some(opt => !opt.trim())) {
                    showToast("Tutti i campi con opzioni devono avere almeno una opzione compilata.", "error");
                    return;
                }
            }
        }
        
        // Prepara JSON
        const payload = fields.map(f => ({
            field_name: f.name,
            field_type: f.type,
            field_options: f.options,
            is_fixed: f.is_fixed ? 1 : 0,
            parent_section_uid: f.parent_section_uid || ''
        }));
        
        saveBtn.disabled = true;
        saveBtn.textContent = "Salvataggio...";
    
        const result = await customFetch('page_editor', 'saveFormStructure', {
            form_name: formName,
            fields: payload
        });
    
        saveBtn.disabled = false;
        saveBtn.textContent = "💾 Salva Modifiche";
    
        if (result.success) {
            showToast("Modulo salvato con successo!", "success");
            setTimeout(() => {
                window.location.href = "index.php?section=collaborazione&page=view_form&form_name=" + encodeURIComponent(formName);
            }, 800); // leggero delay per vedere la toast
        } else {
            showToast(result.message || "Errore nel salvataggio.", "error");
        }
        
    }
    
    // Avvio
    loadFields();
});
