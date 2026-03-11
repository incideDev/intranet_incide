document.addEventListener("DOMContentLoaded", () => {
    function setTaskFormEnabled(enabled) {
        const formDettagli = document.getElementById("form-dettagli-task");
        if (!formDettagli) return;

        [...formDettagli.elements].forEach(el => {
            // Questi id vanno sempre lasciati disabilitati (mai editabili)
            if (
                el.classList.contains("close-modal") ||
                el.type === "button" ||
                el.type === "reset" ||
                ['dt-referente-nome', 'dt-data-apertura', 'dt-chiusura'].includes(el.id)
            ) return;
            el.disabled = !enabled;
        });
    }

window.setupDropzoneNuovaTask = function() {
    const dropzone = document.getElementById("dropzone-nuova-task");
    if (!dropzone) return;
    const dzPreview = dropzone.querySelector('.upload-preview');
    const dzText = dropzone.querySelector('.dropzone-helper-text');
    const input = dropzone.querySelector('input[type="file"]');
    if (dzPreview) dzPreview.innerHTML = '';
    if (dzText) dzText.style.display = "";
    if (input) input.value = "";

    // Handler unico per tutti i metodi (click, drag, paste)
    function handleUpload(file) {
        if (!file) return;
        dzPreview.innerHTML = "";
        const wrapper = document.createElement("div");
        wrapper.style.position = "relative";
        wrapper.style.display = "inline-block";

        const previewImg = document.createElement("img");
        previewImg.style = "max-width:120px;max-height:90px;border-radius:5px;box-shadow:0 0 4px #0002;margin:3px;";
        previewImg.title = "Immagine caricata";

        // X di rimozione
        const removeX = document.createElement("span");
        removeX.textContent = "✕";
        removeX.setAttribute('data-tooltip', 'Rimuovi immagine');
        removeX.style = `
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 18px;
            font-weight: bold;
            color: #d33;
            background: #fff;
            border-radius: 50%;
            padding: 0 2px;
            cursor: pointer;
            z-index: 2;
            box-shadow: 0 1px 3px #0001;
            border: 3px solid #fff;
            transition: transform 0.12s, background 0.12s;
        `;

        removeX.onmouseenter = () => {
            removeX.style.transform = "scale(1.17)";
        };
        removeX.onmouseleave = () => {
            removeX.style.transform = "scale(1)";
        };

        removeX.onclick = function(e) {
            e.stopPropagation();
            dzPreview.innerHTML = "";
            if (dzText) dzText.style.display = "";
            if (input) input.value = "";
        };

        wrapper.appendChild(previewImg);
        wrapper.appendChild(removeX);

        const reader = new FileReader();
        reader.onload = function(ev) {
            previewImg.src = ev.target.result;
        };
        reader.readAsDataURL(file);

        dzPreview.appendChild(wrapper);
        if (dzText) dzText.style.display = "none";

        // *** ASSICURA che input contenga il file da caricare! ***
        if (input) {
            // reset input e riaggiungi file per triggerare il cambio (IMPORTANTE!)
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        }
    }

    // Click sulla dropzone (solo su area "libera", non su anteprima/X)
    dropzone.addEventListener('click', function(e) {
        if (e.target === dropzone || e.target.classList.contains('dropzone-helper-text')) {
            if (input) input.click();
        }
    });

    // Change via input file
    if (input) {
        input.onchange = function(e) {
            if (input.files && input.files[0]) {
                handleUpload(input.files[0]);
            }
        };
    }

    // Drag&Drop
    dropzone.addEventListener('dragover', e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => {
        dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            handleUpload(e.dataTransfer.files[0]);
        }
    });

    // Paste (solo se modale visibile)
    window.addEventListener('paste', function pasteListener(e) {
        const modal = document.getElementById('modal-nuova-task');
        if (!modal || modal.style.display === "none") return;
        if (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA'
        ) return;
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let idx in items) {
            const item = items[idx];
            if (item.kind === 'file') {
                const blob = item.getAsFile();
                handleUpload(blob);
                e.preventDefault();
                break;
            }
        }
    });
};

// --- INVOCA la funzione OGNI VOLTA che apri il modale NUOVA TASK
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("modal-nuova-task");
    if (modal) {
        // Intercetta apertura modale (anche via function-bar)
        modal.addEventListener("show", setupDropzoneNuovaTask);
    }
});

function setupDropzoneDettagliTask(screenshotPaths) {
    // Anteprima separata sopra
    const previewDiv = document.getElementById("dettagli-task-image-preview");
    if (previewDiv) {
        previewDiv.innerHTML = "";
        if (Array.isArray(screenshotPaths) && screenshotPaths.length && screenshotPaths[0].trim() !== "") {
            screenshotPaths.forEach(path => {
                if (!path || path.trim() === '') return;
                
                // Assicura che il path sia relativo alla root (se non inizia con /, aggiungilo)
                let imagePath = path.trim();
                if (imagePath && !imagePath.startsWith('/') && !imagePath.startsWith('http')) {
                    imagePath = '/' + imagePath;
                }
                
                const img = document.createElement("img");
                img.src = imagePath;
                img.className = "image-preview-thumb";
                img.style = "max-width:120px;max-height:90px;border-radius:5px;box-shadow:0 0 4px #0002;cursor:pointer;margin:3px;object-fit:cover;";
                img.title = "Clicca per ingrandire";
                img.alt = "Screenshot task";
                
                // Gestione errore caricamento immagine
                img.onerror = function() {
                    console.warn('Immagine non trovata:', imagePath);
                    this.style.display = 'none';
                    // Mostra placeholder invece di immagine rotta
                    const placeholder = document.createElement('div');
                    placeholder.textContent = '❌';
                    placeholder.style.cssText = img.style.cssText + 'display:inline-flex;align-items:center;justify-content:center;background:#f0f0f0;';
                    img.parentNode?.replaceChild(placeholder, img);
                };
                
                img.onclick = e => {
                    e.stopPropagation();
                    if (window.showImageModal) {
                        window.showImageModal(imagePath);
                    } else {
                        // Fallback: apri in nuova finestra
                        window.open(imagePath, '_blank');
                    }
                };
                previewDiv.appendChild(img);
            });
        }
    }

    // Reset dropzone
    const dropzone = document.getElementById("dropzone-dettagli-task");
    if (!dropzone) return;
    const dzPreview = dropzone.querySelector(".upload-preview");
    const dzText = dropzone.querySelector(".dropzone-helper-text");
    const removeBtn = dropzone.querySelector(".upload-remove-btn");
    const input = dropzone.querySelector('input[type="file"]');
    if (dzPreview) dzPreview.innerHTML = "";
    if (dzText) dzText.style.display = "";
    if (removeBtn) removeBtn.style.display = "none";
    if (input) input.value = "";
    dropzone.classList.remove("has-preview");

// Handler unico per qualsiasi upload (click/drag/paste)
function handleUpload(file) {
    if (!file) return;
    dzPreview.innerHTML = "";
    const wrapper = document.createElement("div");
    wrapper.style.position = "relative";
    wrapper.style.display = "inline-block";

    const previewImg = document.createElement("img");
    previewImg.style = "max-width:120px;max-height:90px;border-radius:5px;box-shadow:0 0 4px #0002;margin:3px;";
    previewImg.title = "Immagine caricata";

    // X di rimozione
    const removeX = document.createElement("span");
    removeX.textContent = "✕";
    removeX.setAttribute('data-tooltip', 'Rimuovi immagine');
    removeX.style = `
        position: absolute;
        top: -5px;
        right: -5px;
        font-size: 18px;
        font-weight: bold;
        color: #d33;
        background: #fff;
        border-radius: 50%;
        padding: 0 2px;
        cursor: pointer;
        z-index: 2;
        box-shadow: 0 1px 3px #0001;
        border: 3px solid #fff;
        transition: transform 0.12s, background 0.12s;
    `;

    removeX.onmouseenter = () => {
        removeX.style.transform = "scale(1.17)";
    };
    removeX.onmouseleave = () => {
        removeX.style.transform = "scale(1)";
    };

    removeX.onclick = function(e) {
        e.stopPropagation();
        // Svuota tutto
        dzPreview.innerHTML = "";
        if (dzText) dzText.style.display = "";
        if (input) input.value = "";
    };

    wrapper.appendChild(previewImg);
    wrapper.appendChild(removeX);

    const reader = new FileReader();
    reader.onload = function(ev) {
        previewImg.src = ev.target.result;
    };
    reader.readAsDataURL(file);

    dzPreview.appendChild(wrapper);
    if (dzText) dzText.style.display = "none";

    // --- PATCH: aggiorna sempre l'input file con il file selezionato, così FormData lo invia! ---
    if (input) {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
    }
}

    // BLOCCO di conferma PRIMA di cambiare input
    function checkAndConfirm(e, file, realUpload) {
        const img = previewDiv && previewDiv.querySelector("img");
        if (img) {
            window.showConfirm(
                "Caricando una nuova immagine verrà sovrascritta quella esistente. Vuoi continuare?",
                function() { realUpload(file); },
                function() {
                    if (input) input.value = ""; // annulla
                }
            );
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            return false;
        } else {
            realUpload(file);
        }
    }


// --- CLICK SULLA DROPZONE (apre l'explorer file)
dropzone.addEventListener('click', function(e) {
    // NON triggerare se clicchi su un bottone o su un'immagine di preview!
    if (e.target === dropzone || e.target.classList.contains('dropzone-helper-text')) {
        if (input) input.click();
    }
});

if (input) {
    input.onchange = function(e) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            checkAndConfirm(e, file, (f) => { handleUpload(f); });
        }
    };
}

    // DRAG&DROP
    dropzone.addEventListener('dragover', e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => {
        dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            const file = e.dataTransfer.files[0];
            checkAndConfirm(e, file, (f) => {
                // Aggiorna anche il campo file input (così il backend lo riceve)
                const dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
                handleUpload(f);
            });
        }
    });

    // INCOLLA (PASTE)
    window.addEventListener('paste', function pasteListener(e) {
        const modal = document.getElementById('modal-dettagli-task');
        if (!modal || modal.style.display === "none") return;
        if (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA'
        ) return;
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let idx in items) {
            const item = items[idx];
            if (item.kind === 'file') {
                const blob = item.getAsFile();
                checkAndConfirm(e, blob, (f) => {
                    const dt = new DataTransfer();
                    dt.items.add(f);
                    input.files = dt.files;
                    handleUpload(f);
                });
                e.preventDefault();
                break;
            }
        }
    });
}

    window.openNewTaskModal = () => {
        [
            "nt-titolo", "nt-data-scadenza", "nt-assegnato-a", "nt-fase-doc", "nt-spec",
            "nt-azione", "nt-path", "nt-chiusura"
        ].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = "";
        });
        const elDataApertura = document.getElementById("nt-data-apertura");
        if (elDataApertura) elDataApertura.value = new Date().toISOString().split('T')[0];
        const statoSelect = document.getElementById("nt-stato");
        if (statoSelect) statoSelect.value = "1";
        // Apri modale PRIMA
        toggleModal("modal-nuova-task", "open");
        // Poi, appena la modale è visibile, inizializza la dropzone
        setTimeout(() => {
            console.log("[DEBUG] setupDropzoneNuovaTask chiamata da openNewTaskModal");
            setupDropzoneNuovaTask();
            // E ulteriore debug:
            const dz = document.getElementById('dropzone-nuova-task');
            if (dz) {
                console.log("[DEBUG] Dopo setupDropzoneNuovaTask:", dz.innerHTML);
            } else {
                console.log("[DEBUG] dropzone-nuova-task NON trovata dopo apertura modale");
            }
        }, 100); // puoi aumentare a 100ms se serve
    };

    // ------- DETTAGLI TASK -------
    // MODIFICATO: Usa solo side panel TaskDetails per visualizzazione (no modale)
    // Il modale rimane disponibile solo per la creazione task
    window.openTaskDetails = async (taskId, tabella) => {
        // Se TaskDetails è disponibile, usa il side panel invece del modale
        if (window.TaskDetails && typeof window.TaskDetails.open === 'function') {
            window.TaskDetails.open(taskId);
            return;
        }
        
        // Fallback legacy (solo se TaskDetails non è disponibile)
        console.warn('TaskDetails non disponibile, usando modale legacy. Assicurati che task_details.js sia caricato.');
        
        // RESET dropzone PRIMA di caricare nuovi dati (evita persistenza immagini tra task)
        setupDropzoneDettagliTask([]);
        
        // Gestisce sia tabella come stringa (legacy) che come oggetto {contextType, contextId}
        let tabellaValue = tabella;
        if (typeof tabella === 'object' && tabella !== null) {
            // Nuovo formato: estrai contextId (che è la tabella logica)
            tabellaValue = tabella.contextId || tabella.tabella || '';
        } else if (typeof tabella === 'string') {
            // Legacy: usa direttamente la stringa
            tabellaValue = tabella;
        } else {
            // Fallback: prova a estrarre dal DOM
            const taskEl = document.querySelector(`[data-task-id="${taskId}"]`);
            if (taskEl) {
                tabellaValue = taskEl.getAttribute('data-tabella') || 
                              taskEl.closest('.kanban-container')?.getAttribute('data-entity-id') || 
                              '';
            }
        }
        
        const res = await customFetch("commesse", "getTaskDetails", { task_id: taskId, tabella: tabellaValue });
        if (!res.success || !res.data) {
            return showToast("Errore caricamento dettagli", "error");
        }
        const d = res.data;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value ?? "";
        };
        setValue("dt-task-id", taskId);
        setValue("dt-tabella", tabellaValue);
        setValue("dt-titolo", d.titolo);
        setValue("dt-data-scadenza", d.data_scadenza);
        // PATCH: Popola la select "assegnato a" solo coi partecipanti effettivi della commessa
        const select = document.getElementById("dt-assegnato-a");
        if (select) {
            select.innerHTML = '<option value="">—</option>';
            const tabella = d.tabella || window.TABELLA_ATTUALE || '';
            customFetch('commesse', 'getPartecipanti', { tabella }).then(res => {
                if (res.success && Array.isArray(res.utenti)) {
                    res.utenti.forEach(u => {
                        const opt = document.createElement('option');
                        opt.value = u.id;
                        opt.textContent = u.nominativo + (u.disciplina ? ` (${u.disciplina})` : '');
                        opt.setAttribute('data-tooltip', u.nominativo);
                        if (String(u.id) === String(d.assegnato_a)) opt.selected = true;
                        select.appendChild(opt);
                    });
                } else {
                    // Se non trova nulla, mostra comunque chi era assegnato
                    if (d.assegnato_a) {
                        const opt = document.createElement('option');
                        opt.value = d.assegnato_a;
                        opt.textContent = "Partecipante non trovato";
                        opt.selected = true;
                        select.appendChild(opt);
                    }
                }
            });
        }
        setValue("dt-fase-doc", d.fase_doc);
        setValue("dt-spec", d.specializzazione);
        setValue("dt-azione", d.descrizione_azione);
        setValue("dt-priority", d.priority);
        setValue("dt-path", d.path_allegato ?? "");
        const elDataApertura = document.getElementById("dt-data-apertura");
        if (elDataApertura) {
            if (d.data_apertura && /^\d{4}-\d{2}-\d{2}$/.test(d.data_apertura)) {
                elDataApertura.value = d.data_apertura;
            } else if (!elDataApertura.value) {
                elDataApertura.value = new Date().toISOString().slice(0,10);
            }
        }
        const elChiusura = document.getElementById("dt-chiusura");
        if (elChiusura) {
            if (d.status_id == 4 && d.data_chiusura && /^\d{4}-\d{2}-\d{2}$/.test(d.data_chiusura)) {
                elChiusura.value = d.data_chiusura;
            } else {
                elChiusura.value = "";
            }
        }
        const statoSelect = document.getElementById("dt-stato");
        if (statoSelect) statoSelect.value = String(d.status_id || 1);

        // SOLO PREVIEW IMMAGINI esistenti
        setupDropzoneDettagliTask((d.screenshot && d.screenshot.trim() !== "") ? d.screenshot.split(',') : []);

        // Modale (solo fallback legacy)
        document.getElementById("modale-task-title").textContent = "Modifica Task";
        toggleModal("modal-dettagli-task", "open");

        // Permessi
        const userId = Number(window.__userId);
        const isResponsabile = window.__userIsResponsabile === true || window.__userIsResponsabile === "true";
        const isTaskOwner = userId === Number(d.submitted_by);
        const isAssegnatario = userId === Number(d.assegnato_a);
        setTaskFormEnabled(isTaskOwner || isAssegnatario || isResponsabile);
    };

    // ------- SPOSTA TASK (KANBAN) -------
    // Usa TaskEngine se disponibile, altrimenti fallback a metodo legacy
    window.onKanbanTaskMoved = async ({ id, table, newStatus }) => {
        if (typeof window.TaskEngine !== 'undefined' && window.TaskEngine.moveTask) {
            // NUOVO SISTEMA: usa TaskEngine
            const context = {
                entity_type: 'commessa',
                entity_id: table.replace('com_', ''),
                table: table
            };
            const res = await window.TaskEngine.moveTask(`task-${table}_${id}`, parseInt(newStatus), context);
            if (res.success) {
                const statoCell = document.querySelector(`#table-view button[onclick*="loadTaskDetails(${id},"]`)?.closest('tr')?.querySelectorAll('td')[7];
                if (statoCell) {
                    const statiMap = { 1: "DA DEFINIRE", 2: "APERTO", 3: "IN CORSO", 4: "CHIUSO" };
                    statoCell.textContent = statiMap[newStatus] || "-";
                }
            }
        } else {
            // FALLBACK: metodo legacy
            const payload = {
                task_id: id,
                stato: newStatus,
                tabella: table
            };
            if (parseInt(newStatus) === 4) payload.data_chiusura = new Date().toISOString().split('T')[0];
            const res = await customFetch("commesse", "updateTaskStatus", payload);
            if (res.success) {
                showToast("Stato aggiornato");
                const statoCell = document.querySelector(`#table-view button[onclick*="loadTaskDetails(${id},"]`)?.closest('tr')?.querySelectorAll('td')[7];
                if (statoCell) {
                    const statiMap = { 1: "DA DEFINIRE", 2: "APERTO", 3: "IN CORSO", 4: "CHIUSO" };
                    statoCell.textContent = statiMap[newStatus] || "-";
                }
            } else {
                showToast("Errore aggiornamento stato", "error");
            }
        }
    };

    // ------- ELIMINA TASK -------
    // Usa TaskEngine se disponibile, altrimenti fallback a metodo legacy
    window.onKanbanTaskDelete = async (taskId, tabella) => {
        if (typeof window.TaskEngine !== 'undefined' && window.TaskEngine.deleteTask) {
            // NUOVO SISTEMA: usa TaskEngine
            const context = {
                entity_type: 'commessa',
                entity_id: tabella.replace('com_', ''),
                table: tabella
            };
            const res = await window.TaskEngine.deleteTask(parseInt(taskId), context);
            if (res.success) {
                const tableRow = document.querySelector(`#table-view button[onclick*="(${taskId},"]`)?.closest('tr');
                if (tableRow) tableRow.remove();
            }
        } else {
            // FALLBACK: metodo legacy
            showConfirm("Vuoi eliminare questa task?", async () => {
                const res = await customFetch('commesse', 'deleteTask', { task_id: taskId, tabella });
                if (res.success) {
                    showToast("Task eliminata");
                    const kanbanTask = document.getElementById(`task-${tabella}_${taskId}`);
                    if (kanbanTask) kanbanTask.remove();
                    const tableRow = document.querySelector(`#table-view button[onclick*="(${taskId},"]`)?.closest('tr');
                    if (tableRow) tableRow.remove();
                } else {
                    showToast(res.message || "Errore eliminazione", "error");
                }
            });
        }
    };

    // ------- SUBMIT NUOVA TASK -------
    const formNuovaTask = document.getElementById("form-nuova-task");
    if (formNuovaTask) {
        formNuovaTask.addEventListener("submit", async e => {
            e.preventDefault();
            const formData = new FormData(formNuovaTask);
            
            // Usa TaskEngine se disponibile, altrimenti fallback
            if (typeof window.TaskEngine !== 'undefined' && window.TaskEngine.createTask) {
                // NUOVO SISTEMA: usa TaskEngine
                const taskData = Object.fromEntries(formData);
                const context = {
                    entity_type: 'commessa',
                    entity_id: window.TABELLA_ATTUALE || '',
                    table: 'com_' + (window.TABELLA_ATTUALE || '').toLowerCase()
                };
                const res = await window.TaskEngine.createTask(taskData, context);
                if (res.success) {
                    showToast("Task creata");
                    toggleModal("modal-nuova-task", "close");
                    if (typeof window.refreshKanban === 'function') {
                        window.refreshKanban();
                    } else {
                        location.reload();
                    }
                } else {
                    showToast(res.message || "Errore salvataggio", "error");
                }
            } else {
                // FALLBACK: metodo legacy
                const res = await customFetch("commesse", "createTask", formData);
                if (res.success) {
                    showToast("Task creata");
                    toggleModal("modal-nuova-task", "close");
                    location.reload();
                } else {
                    showToast("Errore salvataggio", "error");
                }
            }
        });
        // Copia percorso (nuova task)
        const btnCopiaNTPath = document.getElementById("btn-copia-nt-path");
        if (btnCopiaNTPath) {
            btnCopiaNTPath.addEventListener("click", () => {
                const campo = document.getElementById("nt-path");
                if (!campo || !campo.value.trim()) return showToast("Nessun percorso", "info");
                navigator.clipboard.writeText(campo.value).then(() => showToast("Percorso copiato")).catch(() => showToast("Errore copia", "error"));
            });
        }
    }

    // ------- SUBMIT DETTAGLI TASK -------
    const formDettagli = document.getElementById("form-dettagli-task");
    if (formDettagli) {
        formDettagli.addEventListener("submit", async e => {
            e.preventDefault();
            const formData = new FormData(formDettagli);
            const isNew = !formData.get("task_id") || formData.get("task_id").trim() === "";
            
            // Verifica se ci sono file da caricare (screenshots)
            const hasFiles = formData.get('screenshots') && formData.get('screenshots').size > 0;
            
            // Se ci sono file, usa FormData direttamente (supporta upload)
            // Altrimenti usa TaskEngine se disponibile
            if (hasFiles || typeof window.TaskEngine === 'undefined') {
                // Usa FormData direttamente per supportare upload file
                const action = isNew ? "createTask" : "updateTask";
                const section = isNew ? "tasks" : "tasks"; // Usa sempre 'tasks' per nuovo sistema
                
                // Aggiungi contextType/contextId al FormData
                const contextId = window.TABELLA_ATTUALE || formData.get('tabella')?.replace('com_', '') || '';
                formData.append('section', section);
                formData.append('action', action);
                if (isNew) {
                    formData.append('contextType', 'commessa');
                    formData.append('contextId', contextId);
                } else {
                    formData.append('taskId', formData.get('task_id'));
                    formData.append('contextType', 'commessa');
                    formData.append('contextId', contextId);
                }
                
                const res = await customFetch(section, action, formData);

                if (res.success) {
                    showToast(isNew ? "Task creata" : "Task aggiornata");
                    toggleModal("modal-dettagli-task", "close");
                    if (typeof window.refreshKanban === 'function') {
                        window.refreshKanban();
                    } else {
                        location.reload();
                    }
                } else {
                    showToast(res.message || "Errore salvataggio", "error");
                }
            } else {
                // Nessun file: usa TaskEngine (più veloce)
                const taskData = Object.fromEntries(formData);
                const context = {
                    entity_type: 'commessa',
                    entity_id: window.TABELLA_ATTUALE || taskData.tabella?.replace('com_', '') || '',
                    table: taskData.tabella || 'com_' + (window.TABELLA_ATTUALE || '').toLowerCase()
                };
                
                let res;
                if (isNew) {
                    res = await window.TaskEngine.createTask(taskData, context);
                } else {
                    const taskId = parseInt(taskData.task_id);
                    delete taskData.task_id;
                    res = await window.TaskEngine.updateTask(taskId, taskData, context);
                }
                
                if (res.success) {
                    showToast(isNew ? "Task creata" : "Task aggiornata");
                    toggleModal("modal-dettagli-task", "close");
                    if (typeof window.refreshKanban === 'function') {
                        window.refreshKanban();
                    } else {
                        location.reload();
                    }
                } else {
                    showToast(res.message || "Errore salvataggio", "error");
                }
            }
        });
        // Copia percorso (dettagli)
        const btnCopiaPath = document.getElementById("btn-copia-path");
        if (btnCopiaPath) {
            btnCopiaPath.addEventListener("click", () => {
                const campo = document.getElementById("td-path");
                if (!campo || !campo.value.trim()) return showToast("Nessun percorso", "info");
                navigator.clipboard.writeText(campo.value).then(() => showToast("Percorso copiato")).catch(() => showToast("Errore copia", "error"));
            });
        }
    }
});
