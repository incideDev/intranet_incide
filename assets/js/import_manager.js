document.addEventListener('DOMContentLoaded', function () {
    const tableSelect = document.getElementById('tableSelect');
    const fileInput = document.getElementById('fileInput');
    const modeSelect = document.getElementById('modeSelect');
    const keyFieldWrapper = document.getElementById('keyFieldWrapper');
    const keyFieldSelect = document.getElementById('keyFieldSelect');
    const updateFieldsWrapper = document.getElementById('updateFieldsWrapper');
    const skipExistingWrapper = document.getElementById('skipExistingWrapper');
    const skipExistingCheckbox = document.getElementById('skipExistingCheckbox');
    const previewBtn = document.getElementById('previewBtn');
    const importBtn = document.getElementById('importBtn');
    const previewArea = document.getElementById('previewArea');
    const importForm = document.getElementById('importForm');
    const importResult = document.getElementById('importResult');
    const newTableWrapper = document.getElementById('newTableWrapper');
    const newTableNameInput = document.getElementById('newTableName');
    let previewScrollCleanup = null;

    function setupPreviewScrollSync(root) {
        if (typeof previewScrollCleanup === 'function') {
            previewScrollCleanup();
            previewScrollCleanup = null;
        }
        const topWrapper = root.querySelector('.preview-scroll-top');
        const mainWrapper = root.querySelector('.preview-scroll-main');
        const spacer = root.querySelector('.preview-scroll-spacer');
        const table = mainWrapper?.querySelector('table');
        if (!topWrapper || !mainWrapper || !spacer || !table) return;

        const updateSpacerWidth = () => {
            const scrollWidth = table.scrollWidth;
            spacer.style.width = scrollWidth + 'px';
            if (scrollWidth > mainWrapper.clientWidth + 1) {
                topWrapper.classList.add('visible');
            } else {
                topWrapper.classList.remove('visible');
                topWrapper.scrollLeft = 0;
                mainWrapper.scrollLeft = 0;
            }
        };

        updateSpacerWidth();
        setTimeout(updateSpacerWidth, 50);

        const resizeHandler = () => updateSpacerWidth();
        window.addEventListener('resize', resizeHandler);

        let isSyncing = false;
        const syncFromTop = () => {
            if (isSyncing) return;
            isSyncing = true;
            mainWrapper.scrollLeft = topWrapper.scrollLeft;
            requestAnimationFrame(() => { isSyncing = false; });
        };
        const syncFromMain = () => {
            if (isSyncing) return;
            isSyncing = true;
            topWrapper.scrollLeft = mainWrapper.scrollLeft;
            requestAnimationFrame(() => { isSyncing = false; });
        };

        topWrapper.addEventListener('scroll', syncFromTop);
        mainWrapper.addEventListener('scroll', syncFromMain);

        previewScrollCleanup = () => {
            window.removeEventListener('resize', resizeHandler);
            topWrapper.removeEventListener('scroll', syncFromTop);
            mainWrapper.removeEventListener('scroll', syncFromMain);
        };
    }

    customFetch('import_manager', 'getTables')
        .then(data => {
            tableSelect.innerHTML = '<option value="">Seleziona…</option>';
            (data.tables || []).forEach(tab => {
                tableSelect.innerHTML += `<option value="${tab}">${tab}</option>`;
            });
        });

    modeSelect.addEventListener('change', function () {
        const isUpdate = this.value === 'update';
        keyFieldWrapper.style.display = isUpdate ? 'block' : 'none';
        skipExistingWrapper.style.display = isUpdate ? 'block' : 'none';
        // Mostra updateFieldsWrapper solo se update E skipExisting è deselezionato
        updateFieldsWrapper.style.display = (isUpdate && !skipExistingCheckbox.checked) ? 'block' : 'none';
        newTableWrapper.style.display = (this.value === 'create_new') ? 'block' : 'none';

        if (this.value === 'create_new') {
            tableSelect.value = '';
            tableSelect.disabled = true;
            previewBtn.textContent = 'Anteprima nuova tabella';
            importBtn.textContent = 'Crea e importa';
        } else if (this.value === 'update') {
            tableSelect.disabled = false;
            previewBtn.textContent = 'Anteprima aggiornamento';
            importBtn.textContent = skipExistingCheckbox.checked ? 'Importa nuovi' : 'Aggiorna dati';
        } else if (this.value === 'overwrite') {
            tableSelect.disabled = false;
            previewBtn.textContent = 'Anteprima sovrascrittura';
            importBtn.textContent = 'Sovrascrivi dati';
        } else {
            tableSelect.disabled = false;
            previewBtn.textContent = 'Anteprima dati';
            importBtn.textContent = 'Importa dati';
        }
    });

    // Gestisce la visibilità di updateFieldsWrapper in base al checkbox skipExisting
    skipExistingCheckbox.addEventListener('change', function() {
        updateFieldsWrapper.style.display = (modeSelect.value === 'update' && !this.checked) ? 'block' : 'none';
        importBtn.textContent = this.checked ? 'Importa nuovi' : 'Aggiorna dati';
    });

    let currentMapping = null;
    let currentSuggestions = null;

    previewBtn.addEventListener('click', function (e) {
        e.preventDefault();
        importBtn.disabled = true;
        importResult.innerHTML = '';
        previewArea.innerHTML = '<em>Caricamento preview...</em>';

        if (!fileInput.files.length || (!tableSelect.value && modeSelect.value !== 'create_new')) {
            alert('Seleziona una tabella o scegli "Crea nuova tabella" e un file!');
            previewArea.innerHTML = '';
            return;
        }
        let formData = new FormData();
        formData.append('mode', modeSelect.value);
        if (modeSelect.value === 'create_new') {
            const newTableName = newTableNameInput.value.trim();
            if (!newTableName.match(/^[a-zA-Z0-9_]+$/) || newTableName.length < 3) {
                alert("Inserisci un nome valido per la nuova tabella (solo lettere, numeri e underscore, almeno 3 caratteri).");
                previewArea.innerHTML = '';
                return;
            }
            formData.append('new_table_name', newTableName);
        }
        formData.append('table', tableSelect.value);
        formData.append('datafile', fileInput.files[0]);

        // Per modalità insert/update/overwrite, ottieni anche i suggerimenti di mapping
        if (modeSelect.value !== 'create_new' && tableSelect.value) {
            customFetch('import_manager', 'suggestMapping', formData)
                .then(suggestions => {
                    if (suggestions && !suggestions.error) {
                        currentSuggestions = suggestions;
                    } else {
                        currentSuggestions = null;
                    }
                    loadPreview(formData);
                })
                .catch((err) => {
                    console.warn('Errore nel caricamento suggerimenti mapping:', err);
                    currentSuggestions = null;
                    loadPreview(formData);
                });
        } else {
            currentSuggestions = null;
            loadPreview(formData);
        }
    });

    function loadPreview(formData) {
        customFetch('import_manager', 'previewFile', formData)
        .then(data => {
            if (data.error) {
                previewArea.innerHTML = '<span style="color:red;">' + data.error + '</span>';
                importBtn.disabled = true;
                return;
            }
            
            // Se abbiamo suggerimenti e non è create_new, mostra interfaccia mapping
            if (currentSuggestions && modeSelect.value !== 'create_new' && data.table_fields) {
                renderMappingInterface(data, currentSuggestions);
            } else {
                renderStandardPreview(data);
            }
        })
        .catch(() => {
            previewArea.innerHTML = '<span style="color:red;">Errore nella preview.</span>';
            importBtn.disabled = true;
        });
    }

    function renderMappingInterface(data, suggestions) {
        const fileHeaders = suggestions.file_headers || data.headers;
        const dbFields = suggestions.db_fields || data.table_fields;
        const reverseSuggestions = suggestions.reverse_suggestions || {};

        // Popola subito il dropdown del campo chiave con le colonne del file
        keyFieldSelect.innerHTML = '<option value="">Seleziona campo chiave...</option>';
        let suggestedKeyField = null;
        fileHeaders.forEach((h, idx) => {
            keyFieldSelect.innerHTML += `<option value="${h}" data-index="${idx}">${h}</option>`;
            // Auto-suggerisci "codice" o simili come campo chiave
            if (!suggestedKeyField && h.toLowerCase().includes('codice')) {
                suggestedKeyField = h;
            }
        });
        // Seleziona automaticamente il campo chiave suggerito
        if (suggestedKeyField) {
            keyFieldSelect.value = suggestedKeyField;
        }

        // Costruisci mapping iniziale dai suggerimenti
        currentMapping = {};
        if (suggestions.suggestions) {
            Object.keys(suggestions.suggestions).forEach(dbField => {
                const suggestion = suggestions.suggestions[dbField];
                if (suggestion && suggestion.file_header_index !== undefined) {
                    currentMapping[dbField] = fileHeaders[suggestion.file_header_index];
                }
            });
        }

        let mappingHtml = `
            <div class="mapping-container">
                <h3>Mapping Colonne</h3>
                <p style="color:#666;font-size:13px;margin-bottom:15px;">
                    Collega le colonne del file ai campi del database. I suggerimenti automatici sono evidenziati in verde.
                </p>
                <div class="mapping-list">
        `;

        dbFields.forEach(dbField => {
            if (dbField === 'id') return;
            
            const currentMatch = currentMapping[dbField] || '';
            const suggestion = suggestions.suggestions && suggestions.suggestions[dbField];
            const suggestionText = suggestion ? fileHeaders[suggestion.file_header_index] : '';
            const confidence = suggestion ? suggestion.confidence : 0;
            const matchType = suggestion ? suggestion.match_type : '';
            
            const matchTypeLabels = {
                'exact': 'Esatto',
                'normalized': 'Normalizzato',
                'fuzzy': 'Simile',
                'contains': 'Contiene',
                'similarity': 'Similitudine'
            };

            mappingHtml += `
                <div class="mapping-row" data-db-field="${dbField}">
                    <div class="mapping-field-info">
                        <strong>${dbField}</strong>
                        ${suggestion ? `<span class="suggestion-badge suggestion-${matchType}" title="Confidenza: ${confidence}%">${matchTypeLabels[matchType] || 'Suggerito'}</span>` : ''}
                    </div>
                    <select class="mapping-select" data-db-field="${dbField}">
                        <option value="">-- Nessuna colonna --</option>
                        ${fileHeaders.map((header, idx) => {
                            const isSuggested = suggestion && suggestion.file_header_index === idx;
                            const isSelected = currentMatch === header;
                            return `<option value="${header}" ${isSelected ? 'selected' : ''} ${isSuggested ? 'data-suggested="true"' : ''}>${header}</option>`;
                        }).join('')}
                    </select>
                    ${suggestion && suggestion.file_header_index !== undefined ? 
                        `<button class="btn-suggest" data-db-field="${dbField}" data-header-index="${suggestion.file_header_index}" title="Usa suggerimento">✓</button>` : 
                        ''}
                </div>
            `;
        });

        mappingHtml += `
                </div>
                <div style="margin-top:15px;padding-top:15px;border-top:1px solid #e0e0e0;">
                    <button id="applyMappingBtn" class="button btn-primary">Applica Mapping</button>
                    <button id="autoMapBtn" class="button" style="margin-left:8px;">Auto-Map Tutto</button>
                </div>
            </div>
            <div id="previewAfterMapping" style="display:none;margin-top:20px;">
                ${renderStandardPreviewHTML(data)}
            </div>
        `;

        previewArea.innerHTML = mappingHtml;

        // Event listeners per mapping
        previewArea.querySelectorAll('.mapping-select').forEach(select => {
            select.addEventListener('change', function() {
                const dbField = this.dataset.dbField;
                currentMapping[dbField] = this.value || null;
            });
        });

        previewArea.querySelectorAll('.btn-suggest').forEach(btn => {
            btn.addEventListener('click', function() {
                const dbField = this.dataset.dbField;
                const headerIndex = parseInt(this.dataset.headerIndex);
                const select = previewArea.querySelector(`.mapping-select[data-db-field="${dbField}"]`);
                if (select && fileHeaders[headerIndex]) {
                    select.value = fileHeaders[headerIndex];
                    currentMapping[dbField] = fileHeaders[headerIndex];
                }
            });
        });

        document.getElementById('autoMapBtn').addEventListener('click', function() {
            if (suggestions.suggestions) {
                Object.keys(suggestions.suggestions).forEach(dbField => {
                    const suggestion = suggestions.suggestions[dbField];
                    if (suggestion && suggestion.file_header_index !== undefined) {
                        const select = previewArea.querySelector(`.mapping-select[data-db-field="${dbField}"]`);
                        if (select) {
                            select.value = fileHeaders[suggestion.file_header_index];
                            currentMapping[dbField] = fileHeaders[suggestion.file_header_index];
                        }
                    }
                });
            }
        });

        document.getElementById('applyMappingBtn').addEventListener('click', function() {
            // Mostra preview con mapping applicato
            const previewDiv = document.getElementById('previewAfterMapping');
            previewDiv.style.display = 'block';
            previewDiv.innerHTML = renderStandardPreviewHTML(data);
            setupPreviewScrollSync(previewDiv);
            setupPreviewFilters(previewDiv);
            setupPreviewCheckboxes(previewDiv, data.headers);
            importBtn.disabled = false;
        });
    }

    function renderStandardPreview(data) {
        previewArea.innerHTML = renderStandardPreviewHTML(data);
        setupPreviewScrollSync(previewArea);
        setupPreviewFilters(previewArea);
        setupPreviewCheckboxes(previewArea, data.headers);
        importBtn.disabled = false;
    }

    function setupPreviewFilters(container) {
        const table = container.querySelector('.table-filterable');
        if (table) {
            const inputs = table.querySelectorAll('.table-filter-input');
            inputs.forEach((input, colIdx) => {
                input.addEventListener('input', function() {
                    const search = input.value.toLowerCase();
                    table.querySelectorAll('tbody tr').forEach(tr => {
                        const cell = tr.cells[colIdx + 1];
                        tr.style.display = (!search || (cell && cell.textContent.toLowerCase().includes(search))) ? '' : 'none';
                    });
                });
            });
        }
    }

    function setupPreviewCheckboxes(container, dataHeaders) {
        const checkAllRows = container.querySelector('#check-all-rows');
        if (checkAllRows) {
            checkAllRows.addEventListener('change', function() {
                const allRows = container.querySelectorAll('.row-checkbox');
                allRows.forEach(cb => { cb.checked = checkAllRows.checked; });
            });
        }

        // Popola il campo chiave con le colonne del FILE
        const headers = dataHeaders || Array.from(container.querySelectorAll('thead tr:nth-child(2) th')).slice(1).map(th => th.textContent.trim());
        keyFieldSelect.innerHTML = '<option value="">Seleziona campo chiave...</option>';
        let suggestedKeyField = null;
        headers.forEach((h, idx) => {
            keyFieldSelect.innerHTML += `<option value="${h}" data-index="${idx}">${h}</option>`;
            // Auto-suggerisci "codice" o simili come campo chiave
            if (!suggestedKeyField && h.toLowerCase().includes('codice')) {
                suggestedKeyField = h;
            }
        });
        // Seleziona automaticamente il campo chiave suggerito
        if (suggestedKeyField) {
            keyFieldSelect.value = suggestedKeyField;
        }
        
        // Popola la lista delle colonne da aggiornare
        const updateFieldsList = document.getElementById('updateFieldsList');
        if (updateFieldsList && modeSelect.value === 'update') {
            updateFieldsList.innerHTML = '';
            headers.forEach((header, idx) => {
                const checkboxId = `update-field-${idx}`;
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = checkboxId;
                checkbox.className = 'update-field-checkbox';
                checkbox.value = header;
                checkbox.checked = true;
                checkbox.setAttribute('data-header', header);
                checkbox.setAttribute('data-index', idx);
                
                const label = document.createElement('label');
                label.htmlFor = checkboxId;
                label.style.cssText = 'display:flex;align-items:center;gap:6px;padding:4px 0;cursor:pointer;';
                label.appendChild(checkbox);
                label.appendChild(document.createTextNode(header));
                
                updateFieldsList.appendChild(label);
            });
        }
    }

    function renderStandardPreviewHTML(data) {
        // 1. Selettore colonne: una checkbox sopra ogni header
        let colCheckboxRow = '<th><input type="checkbox" id="check-all-rows" checked title="Seleziona/deseleziona tutte le righe"></th>';
        colCheckboxRow += data.headers.map((h, idx) => 
            `<th style="text-align:center;">
                <input type="checkbox" class="col-checkbox" data-col="${idx}" checked title="Importa la colonna ${h}">
            </th>`
        ).join('');

        // 2. Header effettivo
        let headerRow = '<th></th>' + data.headers.map(h => `<th>${h}</th>`).join('');

        // 3. Filtro per colonne
        let filterRow = '<th></th>' + data.headers.map(() => `<th><input type="text" class="table-filter-input" style="width:95%;font-size:12px;padding:2px 4px;"></th>`).join('');

        // 4. Corpo con checkbox riga
        let bodyRows = data.preview.map((row, rowIdx) =>
            `<tr>
                <td style="text-align:center;"><input type="checkbox" class="row-checkbox" data-row="${rowIdx}" checked></td>
                ${row.map((cell, colIdx) => `<td data-col="${colIdx}">${cell}</td>`).join('')}
            </tr>`
        ).join('');

        // 5. Tabella finale
        return `<h3>Preview dati</h3>
        <div class="preview-table-scroll">
            <div class="preview-scroll-top"><div class="preview-scroll-spacer"></div></div>
            <div class="preview-scroll-main">
                <table class="table-filterable" style="font-size:13px;">
                    <thead>
                        <tr>${colCheckboxRow}</tr>
                        <tr>${headerRow}</tr>
                        <tr>${filterRow}</tr>
                    </thead>
                    <tbody>
                        ${bodyRows}
                    </tbody>
                </table>
            </div>
        </div>`;
    }

importForm.addEventListener('submit', function (e) {
    e.preventDefault();
    importBtn.disabled = true;

    // PATCH: Modale di conferma solo per OVERWRITE, poi prosegui solo se confermi
    if (modeSelect.value === 'overwrite') {
        window.toggleModal('global-confirm-modal', 'close');
        window.showSimpleConfirm(
            "ATTENZIONE: Questa operazione cancellerà TUTTE le righe della tabella selezionata.<br>Premi OK per procedere.",
            function () { doImport(); }
        );
        return;
    }
    doImport();
});

window.showSimpleConfirm = function (message, onOk) {
    document.getElementById("global-confirm-modal")?.remove();

    const overlay = document.createElement("div");
    overlay.id = "global-confirm-modal";
    overlay.className = "custom-confirm-overlay";

    overlay.innerHTML = `
        <div class="custom-confirm-box">
            <p>${message}</p>
            <div class="custom-confirm-buttons">
                <button class="button confirm-ok">OK</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelector(".confirm-ok").addEventListener("click", () => {
        overlay.remove();
        if (typeof onOk === "function") onOk();
    });
};

function doImport() {
    // Validazione: in modalità update, il campo chiave è obbligatorio
    if (modeSelect.value === 'update' && !keyFieldSelect.value) {
        alert('Devi selezionare un campo chiave per la modalità "Aggiorna su campo chiave".\n\nSeleziona la colonna del file (es: "Codice") che identifica univocamente i record.');
        importBtn.disabled = false;
        return;
    }

    let formData = new FormData(importForm);
    formData.set('mode', modeSelect.value);

    // Aggiungi mapping personalizzato se presente
    if (currentMapping && Object.keys(currentMapping).length > 0) {
        formData.set('column_mapping', JSON.stringify(currentMapping));
    }

    // Per modalità update, aggiungi skip_existing e le colonne selezionate da aggiornare
    if (modeSelect.value === 'update') {
        formData.set('skip_existing', skipExistingCheckbox.checked ? '1' : '0');

        if (!skipExistingCheckbox.checked) {
            const selectedUpdateFields = [];
            document.querySelectorAll('.update-field-checkbox:checked').forEach(cb => {
                selectedUpdateFields.push(cb.value);
            });
            if (selectedUpdateFields.length > 0) {
                formData.set('update_fields', JSON.stringify(selectedUpdateFields));
            }
        }
    }

    // Raccolta righe selezionate (per TUTTI i modi, non solo create_new)
    const selectedRows = [];
    document.querySelectorAll('.row-checkbox').forEach((cb, rowIdx) => {
        if (cb.checked) selectedRows.push(rowIdx);
    });
    formData.set('selected_rows', JSON.stringify(selectedRows));

    if (modeSelect.value === 'create_new') {
        const newTableName = newTableNameInput.value.trim();
        if (!newTableName.match(/^[a-zA-Z0-9_]+$/) || newTableName.length < 3) {
            alert("Inserisci un nome valido per la nuova tabella (solo lettere, numeri e underscore, almeno 3 caratteri).");
            importBtn.disabled = false;
            return;
        }
        formData.set('new_table_name', newTableName);

        const selectedCols = [];
        const selectedColIndexes = [];
        document.querySelectorAll('.col-checkbox').forEach((cb, idx) => {
            if (cb.checked) {
                const headerRow = previewArea.querySelector('thead tr:nth-child(2)');
                if (headerRow) {
                    const th = headerRow.querySelectorAll('th')[idx + 1];
                    if (th) {
                        selectedCols.push(th.textContent.trim());
                        selectedColIndexes.push(idx);
                    }
                }
            }
        });
        formData.set('selected_columns', JSON.stringify(selectedCols));
        const filteredPreviewData = [];
        previewArea.querySelectorAll('tbody tr').forEach((tr, rowIdx) => {
            if (selectedRows.includes(rowIdx)) {
                const cells = tr.querySelectorAll('td');
                const filteredRow = selectedColIndexes.map(i => cells[i + 1]?.textContent || '');
                filteredPreviewData.push(filteredRow);
            }
        });
        formData.set('filtered_preview', JSON.stringify(filteredPreviewData));
    }
    
    customFetch('import_manager', 'doImport', formData)
    .then(data => {
        let msg = '';
        let logHtml = '';
        if (data.log) {
            logHtml = `<pre style="font-size:12px;background:#fafafc;max-height:300px;overflow-y:auto;">${escapeHtml(data.log)}</pre>`;
        }
        
        const inserted = data.inserted || 0;
        const updated = data.updated || 0;
        const skipped = data.skipped || 0;
        const total = inserted + updated + skipped;
        
        if (data.error) {
            msg = `
                <div>
                    <span style="color:#a62e2e;"><strong>Errore durante l'importazione:</strong> ${escapeHtml(data.error)}</span><br><br>
                    <strong>Riepilogo parziale:</strong><br>
                    Inseriti: <b>${inserted}</b> | Aggiornati: <b>${updated}</b> | Saltati: <b>${skipped}</b><br>
                    ${logHtml}
                </div>
            `;
        } else {
            const successColor = skipped > 0 ? '#f59e0b' : '#10b981';
            msg = `
                <div style="color:${successColor};">
                    <strong>Importazione completata.</strong><br>
                    Inseriti: <b>${inserted}</b> | Aggiornati: <b>${updated}</b> | Saltati: <b>${skipped}</b><br>
                    ${logHtml}
                </div>
            `;
        }
        window.toggleModal('global-confirm-modal', 'close');
        window.showSimpleConfirm(msg, function(){});
    })
    .catch(() => {
        window.toggleModal('global-confirm-modal', 'close');
        window.showSimpleConfirm('<span style="color:#a62e2e;">Errore durante l\'importazione.</span>', function(){});
    })
    .finally(() => {
        importBtn.disabled = false;
    });
}

});
