/**
 * REPEATABLE TABLE - Componente riusabile per tabelle ripetibili
 * 
 * Usato per: partecipanti, agenda, decisioni, action items
 * 
 * API:
 * - repeatableTable.create({rootEl, columns, initialRows, allowDelete})
 * - repeatableTable.getValue(rootEl) → {rows, deleteIds}
 * - repeatableTable.setValue(rootEl, rows)
 */

window.repeatableTable = (function () {
    'use strict';

    /**
     * Crea una tabella ripetibile
     *
     * @param {Object} config Configurazione
     * @param {HTMLElement} config.rootEl Elemento root dove creare la tabella
     * @param {Array} config.columns Array colonne: [{label, field, type, required, tooltip, options}]
     * @param {Array} config.initialRows Array righe iniziali (opzionale)
     * @param {boolean} config.allowDelete Se true, mostra pulsante elimina (default: true)
     * @param {Function} config.customActions Funzione per azioni custom: (rowData, rowElement) => HTMLElement[]
     * @param {boolean} config.externalAddButton Se true, non crea il bottone aggiungi automaticamente (default: false)
     */
    function create(config) {
        const rootEl = config.rootEl;
        const columns = config.columns || [];
        const initialRows = config.initialRows || [];
        const allowDelete = config.allowDelete !== false;
        const customActions = config.customActions || null;
        const externalAddButton = config.externalAddButton === true;

        if (!rootEl) {
            console.error('[repeatableTable] rootEl mancante');
            return;
        }

        // Salva configurazione per uso futuro
        rootEl.setAttribute('data-repeatable-config', JSON.stringify({ columns, allowDelete, hasCustomActions: !!customActions }));

        // Pulisci contenuto esistente
        rootEl.innerHTML = '';

        // Crea wrapper per table-filterable
        const wrapper = document.createElement('div');
        wrapper.className = 'table-filterable-wrapper';

        // Crea tabella
        const table = document.createElement('table');
        table.className = 'table table-filterable';
        table.setAttribute('data-repeatable-table', 'true');
        table.setAttribute('data-no-pagination', 'true'); // Disabilita paginazione per tabelle repeatable

        // Genera ID univoco per la tabella se non esiste
        if (!table.id) {
            const tableId = 'repeatable-table-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
            table.id = tableId;
        }

        // Header
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');

        columns.forEach(col => {
            const th = document.createElement('th');
            th.textContent = col.label || col.field;
            if (col.tooltip) {
                th.setAttribute('data-tooltip', col.tooltip);
            }
            headerRow.appendChild(th);
        });

        if (allowDelete || customActions) {
            const thAction = document.createElement('th');
            thAction.textContent = 'Azioni';
            thAction.className = 'azioni-colonna';
            headerRow.appendChild(thAction);
        }

        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Body
        const tbody = document.createElement('tbody');
        table.appendChild(tbody);

        // Pulsante aggiungi
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'button button-secondary';
        addBtn.textContent = '+ Aggiungi riga';
        addBtn.addEventListener('click', function () {
            addRow(tbody, columns, allowDelete, null, customActions);
        });

        wrapper.appendChild(table);
        rootEl.appendChild(wrapper);

        // Crea bottone aggiungi solo se non è esterno
        if (!externalAddButton) {
            rootEl.appendChild(addBtn);
        }

        // Aggiungi righe iniziali
        initialRows.forEach(row => {
            addRow(tbody, columns, allowDelete, row, customActions);
        });

        // Se non ci sono righe, aggiungi una vuota
        if (initialRows.length === 0) {
            addRow(tbody, columns, allowDelete, null, customActions);
        }

        // La tabella viene automaticamente inizializzata dal MutationObserver in table_resize.js
        // Non servono chiamate manuali
    }

    /**
     * Aggiunge una riga alla tabella
     * 
     * @param {HTMLElement} tbody Tbody della tabella
     * @param {Array} columns Colonne
     * @param {boolean} allowDelete Se mostrare pulsante elimina
     * @param {Object} rowData Dati riga (opzionale)
     * @param {Function} customActions Funzione per azioni custom (opzionale)
     */
    function addRow(tbody, columns, allowDelete, rowData, customActions) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-row-index', Date.now());

        // ID nascosto (se riga esistente)
        if (rowData && rowData.id) {
            const hiddenId = document.createElement('input');
            hiddenId.type = 'hidden';
            hiddenId.className = 'row-id';
            hiddenId.value = rowData.id;
            tr.appendChild(hiddenId);
        }

        // Task ID nascosto (se presente, per AI)
        if (rowData && rowData.taskId) {
            const hiddenTaskId = document.createElement('input');
            hiddenTaskId.type = 'hidden';
            hiddenTaskId.className = 'row-task-id';
            hiddenTaskId.value = rowData.taskId;
            tr.appendChild(hiddenTaskId);
        }

        // Campo nascosto per marcare eliminazione
        const deleteInput = document.createElement('input');
        deleteInput.type = 'checkbox';
        deleteInput.setAttribute('data-field', '_delete');
        deleteInput.style.display = 'none';
        deleteInput.checked = false;
        tr.appendChild(deleteInput);

        // Celle per ogni colonna
        columns.forEach(col => {
            const td = document.createElement('td');
            const input = createInput(col, rowData ? rowData[col.field] : null);
            td.appendChild(input);
            tr.appendChild(td);
        });

        // Cella azioni
        if (allowDelete || customActions) {
            const tdAction = document.createElement('td');
            tdAction.className = 'azioni-colonna';

            // Container per le icone (centrato)
            const actionsContainer = document.createElement('div');
            actionsContainer.style.display = 'inline-flex';
            actionsContainer.style.gap = '4px';
            actionsContainer.style.alignItems = 'center';
            actionsContainer.style.justifyContent = 'center';

            // Azioni custom (es. Crea/Apri Task per AI)
            if (customActions && typeof customActions === 'function') {
                const actions = customActions(rowData, tr);
                if (Array.isArray(actions)) {
                    actions.forEach(action => {
                        if (action && action.nodeType) {
                            actionsContainer.appendChild(action);
                        }
                    });
                }
            }

            // Pulsante elimina (icona)
            if (allowDelete) {
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'action-icon';
                deleteBtn.setAttribute('data-tooltip', 'Elimina');
                deleteBtn.innerHTML = '<img src="assets/icons/delete.png" alt="Elimina">';
                deleteBtn.addEventListener('click', function () {
                    // Invece di rimuovere la riga, marca per eliminazione
                    const deleteInput = tr.querySelector('input[data-field="_delete"]');
                    if (deleteInput) {
                        const isCurrentlyDeleted = deleteInput.checked;

                        if (!isCurrentlyDeleted) {
                            // Marca per eliminazione
                            deleteInput.checked = true;
                            tr.classList.add('row-deleted');
                            tr.style.opacity = '0.5';
                            deleteBtn.setAttribute('data-tooltip', 'Annulla eliminazione');
                            deleteBtn.innerHTML = '<img src="assets/icons/restore.png" alt="Annulla">';

                            // Aggiungi testo indicatore
                            const indicator = tr.querySelector('.delete-indicator');
                            if (!indicator) {
                                const newIndicator = document.createElement('span');
                                newIndicator.className = 'delete-indicator';
                                newIndicator.textContent = ' (da eliminare)';
                                newIndicator.style.color = '#dc2626';
                                newIndicator.style.fontStyle = 'italic';
                                const firstCell = tr.querySelector('td:first-child');
                                if (firstCell) {
                                    firstCell.appendChild(newIndicator);
                                }
                            }
                        } else {
                            // Annulla eliminazione
                            deleteInput.checked = false;
                            tr.classList.remove('row-deleted');
                            tr.style.opacity = '1';
                            deleteBtn.setAttribute('data-tooltip', 'Elimina');
                            deleteBtn.innerHTML = '<img src="assets/icons/delete.png" alt="Elimina">';

                            // Rimuovi testo indicatore
                            const indicator = tr.querySelector('.delete-indicator');
                            if (indicator) {
                                indicator.remove();
                            }
                        }
                    }
                });
                actionsContainer.appendChild(deleteBtn);
            }

            tdAction.appendChild(actionsContainer);
            tr.appendChild(tdAction);
        }

        tbody.appendChild(tr);
    }

    /**
     * Crea input in base al tipo colonna
     * 
     * @param {Object} col Configurazione colonna
     * @param {*} value Valore iniziale
     * @returns {HTMLElement} Elemento input
     */
    function createInput(col, value) {
        const field = col.field;
        const type = col.type || 'text';
        const required = col.required === true;
        const tooltip = col.tooltip;

        let input;

        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 3;
            input.value = value || '';
            input.className = 'select-box';
        } else if (type === 'date') {
            input = document.createElement('input');
            input.type = 'date';
            input.className = 'select-box';
            if (value) {
                // Converti da YYYY-MM-DD a formato input date
                input.value = value;
            }
        } else if (type === 'checkbox') {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.className = '';
            input.checked = value === true || value === 1 || value === '1';
        } else if (type === 'select' && col.options) {
            input = document.createElement('select');
            input.className = 'select-box';
            col.options.forEach(opt => {
                const option = document.createElement('option');
                option.value = typeof opt === 'string' ? opt : opt.value;
                option.textContent = typeof opt === 'string' ? opt : opt.label;
                if (value && option.value === String(value)) {
                    option.selected = true;
                }
                input.appendChild(option);
            });
        } else if (type === 'readonly') {
            input = document.createElement('input');
            input.type = 'text';
            input.value = value || '';
            input.className = 'select-box';
            input.readOnly = true;
            input.style.cssText = 'background-color: #f8f9fa; color: #6c757d;';
        } else if (type === 'link') {
            const wrapper = document.createElement('div');
            wrapper.style.display = 'flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.minHeight = '30px';
            wrapper.style.padding = '0 6px';
            
            if (value) {
                const a = document.createElement('a');
                a.className = 'link-incide mom-item-link-trigger';
                a.textContent = value;
                a.href = '#';
                a.onclick = function(e) { e.preventDefault(); };
                wrapper.appendChild(a);
            } else {
                const s = document.createElement('span');
                s.className = 'link-incide mom-item-link-trigger';
                s.textContent = '—';
                s.style.color = '#ccc';
                wrapper.appendChild(s);
            }
            
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.setAttribute('data-field', field);
            hiddenInput.value = value || '';
            
            wrapper.appendChild(hiddenInput);
            
            return wrapper;
        } else if (type === 'participantSelect') {
            // Crea select popolato con partecipanti del MOM
            input = document.createElement('select');
            input.className = 'select-box';
            input.setAttribute('data-field', field);
            if (required) {
                input.setAttribute('required', 'required');
            }
            if (tooltip) {
                input.setAttribute('data-tooltip', tooltip);
            }

            // Opzione vuota
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'Seleziona responsabile...';
            input.appendChild(emptyOption);

            // Popola con partecipanti attuali
            updateParticipantOptions(input, value);
            return input;
        } else if (type === 'customSelect') {
            // Crea custom-select-box per dropdown con ricerca
            input = document.createElement('div');
            input.className = 'custom-select-box' + (col.boxClass ? ' ' + col.boxClass : '');
            input.tabIndex = 0;
            input.style.cssText = 'width:100%; min-height:28px; cursor:pointer;';

            const placeholder = document.createElement('span');
            placeholder.className = 'custom-select-placeholder';
            placeholder.textContent = value || col.placeholder || 'Seleziona...';
            input.appendChild(placeholder);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.setAttribute('data-field', field);
            hiddenInput.value = value || '';
            if (required) {
                hiddenInput.setAttribute('required', 'required');
            }
            input.appendChild(hiddenInput);

            return input; // Ritorna subito, data-field è sull'hidden input
        } else {
            input = document.createElement('input');
            input.type = type;
            input.value = value || '';
            input.className = 'select-box';
        }
        input.setAttribute('data-field', field);
        if (required) {
            input.setAttribute('required', 'required');
        }
        if (tooltip) {
            input.setAttribute('data-tooltip', tooltip);
        }

        return input;
    }

    /**
     * Estrae valori dalla tabella
     * 
     * @param {HTMLElement} rootEl Elemento root
     * @returns {Object} {rows: Array, deleteIds: Array}
     */
    function getValue(rootEl) {
        const table = rootEl.querySelector('table[data-repeatable-table]');
        if (!table) {
            return { rows: [], deleteIds: [] };
        }

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return { rows: [], deleteIds: [] };
        }

        const rows = [];
        const deleteIds = [];

        tbody.querySelectorAll('tr').forEach(tr => {
            const rowId = tr.querySelector('.row-id');
            const id = rowId ? parseInt(rowId.value, 10) : null;

            const taskIdEl = tr.querySelector('.row-task-id');
            const taskId = taskIdEl ? parseInt(taskIdEl.value, 10) : null;

            // Se riga è stata eliminata ma ha ID, aggiungi a deleteIds
            // (gestito dal pulsante elimina che rimuove la riga)

            // Estrai valori
            const row = {};
            if (id !== null) {
                row.id = id;
            }
            if (taskId !== null) {
                row.taskId = taskId;
            }

            tr.querySelectorAll('[data-field]').forEach(input => {
                const field = input.getAttribute('data-field');
                let value;

                if (input.type === 'checkbox') {
                    value = input.checked;
                } else if (input.type === 'date') {
                    value = input.value || null;
                } else {
                    value = input.value ? input.value.trim() : '';
                }

                // Salta righe vuote (se titolo/nominativo obbligatorio è vuoto)
                if (field === 'titolo' || field === 'nominativo' || field === 'descrizione') {
                    if (!value) {
                        return; // Salta questa riga
                    }
                }

                row[field] = value;
            });

            // Aggiungi solo se ha almeno un campo valorizzato
            if (Object.keys(row).length > (id !== null ? 2 : 1)) {
                rows.push(row);
            }
        });

        return { rows, deleteIds };
    }

    /**
     * Imposta valori nella tabella
     * 
     * @param {HTMLElement} rootEl Elemento root
     * @param {Array} rows Array righe da impostare
     */
    function setValue(rootEl, rows) {
        const table = rootEl.querySelector('table[data-repeatable-table]');
        if (!table) {
            return;
        }

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        // Pulisci righe esistenti
        tbody.innerHTML = '';

        // Estrai configurazione colonne dal primo input esistente o dalla struttura
        const columns = [];
        const firstRow = tbody.querySelector('tr');
        if (firstRow) {
            firstRow.querySelectorAll('[data-field]').forEach(input => {
                columns.push({
                    field: input.getAttribute('data-field'),
                    type: input.type || 'text',
                    required: input.hasAttribute('required')
                });
            });
        }

        // Aggiungi righe
        rows.forEach(row => {
            // Troviamo le colonne dalla configurazione originale
            // Per ora, assumiamo che le colonne siano già state configurate
            // Se necessario, possiamo passare le colonne come parametro
        });
    }

    /**
     * Aggiorna opzioni select partecipanti
     *
     * @param {HTMLElement} selectElement Elemento select da aggiornare
     * @param {string} selectedValue Valore attualmente selezionato
     */
    function updateParticipantOptions(selectElement, selectedValue) {
        if (!selectElement) return;

        // Salva valore selezionato
        const currentValue = selectedValue || selectElement.value;

        // Rimuovi opzioni esistenti tranne quella vuota
        while (selectElement.options.length > 1) {
            selectElement.remove(1);
        }

        // Ottieni partecipanti dalla tabella partecipanti
        const partecipantiContainer = document.getElementById('mom-partecipanti-container');
        if (partecipantiContainer) {
            const partecipantiRows = partecipantiContainer.querySelectorAll('tbody tr');
            partecipantiRows.forEach(row => {
                const nominativoInput = row.querySelector('[data-field="partecipante"]');
                if (nominativoInput && nominativoInput.value.trim()) {
                    const option = document.createElement('option');
                    option.value = nominativoInput.value.trim();
                    option.textContent = nominativoInput.value.trim();
                    if (currentValue && option.value === currentValue) {
                        option.selected = true;
                    }
                    selectElement.appendChild(option);
                }
            });
        }
    }

    /**
     * Genera codice automatico per item basato sul tipo
     *
     * @param {string} itemType Tipo dell'item (AI, OBS, EVE)
     * @param {HTMLElement} itemsContainer Container della tabella items
     * @returns {string} Codice generato
     */
    function generateItemCode(itemType, itemsContainer) {
        if (!itemType || !itemsContainer) return '';

        // Raccogli tutti i codici esistenti di questo tipo (esclusi quelli marcati per eliminazione)
        const existingItems = itemsContainer.querySelectorAll('tbody tr');
        const existingNumbers = new Set();

        existingItems.forEach(row => {
            const typeSelect = row.querySelector('[data-field="itemType"]');
            const itemCodeInput = row.querySelector('[data-field="itemCode"]');
            const deleteCheckbox = row.querySelector('input[type="checkbox"][data-field="_delete"]');

            // Raccogli numeri esistenti solo se è dello stesso tipo e non è marcato per eliminazione
            if (typeSelect && typeSelect.value === itemType &&
                (!deleteCheckbox || !deleteCheckbox.checked) &&
                itemCodeInput && itemCodeInput.value &&
                itemCodeInput.value.startsWith(`${itemType}_`)) {
                const numberPart = itemCodeInput.value.split('_')[1];
                if (numberPart && /^\d+$/.test(numberPart)) {
                    existingNumbers.add(parseInt(numberPart, 10));
                }
            }
        });

        // Trova il primo numero disponibile (001, 002, 003, ...)
        let counter = 1;
        while (existingNumbers.has(counter)) {
            counter++;
        }

        return `${itemType}_${String(counter).padStart(3, '0')}`;
    }

    /**
     * Setup generazione automatica codici per tabella items
     *
     * @param {HTMLElement} itemsContainer Container della tabella items
     */
    function setupItemCodeGeneration(itemsContainer) {
        if (!itemsContainer) return;

        // Listener per cambiamenti del tipo che genera codice automaticamente
        itemsContainer.addEventListener('change', function (e) {
            const target = e.target;
            if (target.matches('[data-field="itemType"]')) {
                const row = target.closest('tr');
                if (row) {
                    const itemCodeInput = row.querySelector('[data-field="itemCode"]');
                    const itemType = target.value;

                    if (itemCodeInput && itemType) {
                        // Verifica se il campo è vuoto o contiene già un codice generato automaticamente
                        const currentValue = itemCodeInput.value;
                        const isAutoGenerated = currentValue && currentValue.match(/^(AI|OBS|EVE)_\d{3}$/);

                        if (!currentValue || isAutoGenerated) {
                            // Genera nuovo codice
                            const newCode = generateItemCode(itemType, itemsContainer);
                            if (newCode) {
                                itemCodeInput.value = newCode;
                            }
                        }
                    }
                }
            }
        });

        // Gestisci solo le nuove righe aggiunte (non rigenerare tutto)
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    // Per ogni nuova riga aggiunta, se ha un tipo selezionato, genera il codice
                    mutation.addedNodes.forEach(node => {
                        if (node.tagName === 'TR') {
                            const itemTypeSelect = node.querySelector('[data-field="itemType"]');
                            const itemCodeInput = node.querySelector('[data-field="itemCode"]');

                            if (itemTypeSelect && itemCodeInput && itemTypeSelect.value && !itemCodeInput.value) {
                                const newCode = generateItemCode(itemTypeSelect.value, itemsContainer);
                                if (newCode) {
                                    itemCodeInput.value = newCode;
                                }
                            }
                        }
                    });
                }
            });
        });

        const tbody = itemsContainer.querySelector('tbody');
        if (tbody) {
            observer.observe(tbody, { childList: true });
        }
    }

    /**
     * Setup aggiornamenti dinamici per dropdown partecipanti
     *
     * @param {HTMLElement} itemsContainer Container della tabella items
     * @param {HTMLElement} partecipantiContainer Container della tabella partecipanti
     */
    function setupParticipantSelectUpdates(itemsContainer, partecipantiContainer) {
        if (!itemsContainer || !partecipantiContainer) return;

        // Listener per cambiamenti nella tabella partecipanti
        const observer = new MutationObserver(function (mutations) {
            let shouldUpdate = false;

            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList' || mutation.type === 'characterData' || mutation.type === 'subtree') {
                    shouldUpdate = true;
                }
            });

            if (shouldUpdate) {
                // Aggiorna tutti i dropdown responsabili
                const participantSelects = itemsContainer.querySelectorAll('[data-field="responsabile"]');
                participantSelects.forEach(select => {
                    const currentValue = select.value;
                    updateParticipantOptions(select, currentValue);
                });
            }
        });

        const partecipantiTbody = partecipantiContainer.querySelector('tbody');
        if (partecipantiTbody) {
            observer.observe(partecipantiTbody, { childList: true, subtree: true, characterData: true });
        }

        // Listener per input diretti nei campi partecipante
        partecipantiContainer.addEventListener('input', function (e) {
            if (e.target.matches('[data-field="partecipante"]')) {
                // Aggiorna tutti i dropdown responsabili con un piccolo delay
                setTimeout(() => {
                    const participantSelects = itemsContainer.querySelectorAll('[data-field="responsabile"]');
                    participantSelects.forEach(select => {
                        const currentValue = select.value;
                        updateParticipantOptions(select, currentValue);
                    });
                }, 300);
            }
        });
    }

    /**
     * Aggiunge una riga a una tabella esistente (per uso esterno)
     *
     * @param {HTMLElement} rootEl Elemento root della tabella
     */
    function addRowExternal(rootEl) {
        const table = rootEl.querySelector('table[data-repeatable-table]');
        if (!table) {
            console.error('[repeatableTable] Tabella non trovata');
            return;
        }

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            console.error('[repeatableTable] Tbody non trovato');
            return;
        }

        // Estrai configurazione dalla tabella
        const configStr = rootEl.getAttribute('data-repeatable-config');
        if (!configStr) {
            console.error('[repeatableTable] Configurazione non trovata');
            return;
        }

        const config = JSON.parse(configStr);
        const columns = config.columns || [];
        const allowDelete = config.allowDelete !== false;
        const customActions = config.hasCustomActions ? null : null; // Per ora non supportato

        addRow(tbody, columns, allowDelete, null, customActions);
    }

    return {
        create: create,
        getValue: getValue,
        setValue: setValue,
        addRowExternal: addRowExternal,
        setupItemCodeGeneration: setupItemCodeGeneration,
        setupParticipantSelectUpdates: setupParticipantSelectUpdates
    };
})();
