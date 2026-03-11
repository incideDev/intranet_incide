document.addEventListener("DOMContentLoaded", () => {
    function setTaskFormEnabled(enabled) {
        const formDettagli = document.getElementById("form-dettagli-task");
        if (!formDettagli) return;

        [...formDettagli.elements].forEach(el => {
            if (
                el.classList.contains("close-modal") ||
                el.type === "button" ||
                el.type === "reset" ||
                ['td-referente-nome', 'td-creazione-data', 'td-data-apertura', 'td-chiusura'].includes(el.id)
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
                const img = document.createElement("img");
                img.src = path;
                img.className = "image-preview-thumb";
                img.style = "max-width:120px;max-height:90px;border-radius:5px;box-shadow:0 0 4px #0002;cursor:pointer;margin:3px;";
                img.title = "Clicca per ingrandire";
                img.onclick = e => {
                    e.stopPropagation();
                    window.showImageModal && window.showImageModal(path);
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
    window.openTaskDetails = async (taskId, tabella) => {
        const res = await customFetch("commesse", "getTaskDetails", { task_id: taskId, tabella });
        if (!res.success || !res.data) {
            return showToast("Errore caricamento dettagli", "error");
        }
        const d = res.data;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value ?? "";
        };
        setValue("dt-task-id", taskId);
        setValue("dt-tabella", tabella);
        setValue("td-titolo", d.titolo);
        setValue("td-data-scadenza", d.data_scadenza);
        setValue("td-assegnato-a", d.assegnato_a);
        setValue("td-fase-doc", d.fase_doc);
        setValue("td-spec", d.specializzazione);
        setValue("td-azione", d.descrizione_azione);
        setValue("td-priority", d.priority);
        setValue("td-path", d.path_allegato ?? "");
        const elDataApertura = document.getElementById("td-data-apertura");
        if (elDataApertura) {
            if (d.data_apertura && /^\d{4}-\d{2}-\d{2}$/.test(d.data_apertura)) {
                elDataApertura.value = d.data_apertura;
            } else if (!elDataApertura.value) {
                elDataApertura.value = new Date().toISOString().slice(0,10);
            }
        }
        const elChiusura = document.getElementById("td-chiusura");
        if (elChiusura) {
            if (d.status_id == 4 && d.data_chiusura && /^\d{4}-\d{2}-\d{2}$/.test(d.data_chiusura)) {
                elChiusura.value = d.data_chiusura;
            } else {
                elChiusura.value = "";
            }
        }
        const statoSelect = document.getElementById("td-stato");
        if (statoSelect) statoSelect.value = String(d.status_id || 1);

        // SOLO PREVIEW IMMAGINI esistenti
        setupDropzoneDettagliTask((d.screenshot && d.screenshot.trim() !== "") ? d.screenshot.split(',') : []);

        // Modale
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
    window.onKanbanTaskMoved = async ({ id, table, newStatus }) => {
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
    };

    // ------- ELIMINA TASK -------
    window.onKanbanTaskDelete = (taskId, tabella) => {
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
    };

    // ------- SUBMIT NUOVA TASK -------
    const formNuovaTask = document.getElementById("form-nuova-task");
    if (formNuovaTask) {
        formNuovaTask.addEventListener("submit", async e => {
            e.preventDefault();
            const formData = new FormData(formNuovaTask);
            const res = await customFetch("commesse", "createTask", formData);
            if (res.success) {
                showToast("Task creata");
                toggleModal("modal-nuova-task", "close");
                location.reload();
            } else {
                showToast("Errore salvataggio", "error");
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
            const action = isNew ? "createTask" : "saveTaskDetails";
            const res = await customFetch("commesse", action, formData);

            if (res.success) {
                showToast(isNew ? "Task creata" : "Task aggiornata");
                toggleModal("modal-dettagli-task", "close");
                location.reload();
            } else {
                showToast("Errore salvataggio", "error");
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
