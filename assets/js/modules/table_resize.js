/**
 * Modulo per il resize delle colonne nelle tabelle table-filterable
 * Salva le larghezze in localStorage con persistenza di 30 giorni
 */

(function () {
    'use strict';

    const STORAGE_PREFIX = 'table_filterable_cols_';
    const EXPIRY_DAYS = 365; // Persistenza estesa a 1 anno

    /**
     * Genera una chiave univoca per la tabella basata su ID o URL + posizione
     */
    function getTableKey(table) {
        // Estrai SEMPRE sezione e pagina dall'URL per creare una chiave univoca per pagina
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section') || 'default';
        const page = urlParams.get('page') || 'default';

        // PRIORITÀ 1: data-table-key esplicito (sempre la scelta migliore)
        if (table.dataset.tableKey) {
            return `${table.dataset.tableKey}_${section}_${page}`;
        }

        // Helper per verificare se un ID è volatile
        function isVolatileId(id) {
            if (!id) return true;
            // Assicura che id sia una stringa
            const idStr = typeof id === 'string' ? id : String(id);
            return idStr.includes('repeatable-table-') ||
                /\d{13,}/.test(idStr) || // timestamp lungo
                /-[a-z0-9]{8,}/.test(idStr); // hash random tipo "-mtfg6ykqs"
        }

        // PRIORITÀ 2: Cerca il primo parent DIV/SECTION con ID stabile (non volatile)
        let currentElement = table.parentElement;
        let stableParent = null;

        while (currentElement && currentElement !== document.body) {
            if (currentElement.id && !isVolatileId(currentElement.id)) {
                stableParent = currentElement;
                break;
            }
            currentElement = currentElement.parentElement;
        }

        // Se trovato parent stabile, usa quello
        if (stableParent) {
            const tablesInContainer = stableParent.querySelectorAll('table.table-filterable');
            const indexInContainer = Array.from(tablesInContainer).indexOf(table);

            if (indexInContainer !== -1) {
                return `${stableParent.id}_tbl_${indexInContainer}_${section}_${page}`;
            }
        }

        // Verifica se l'ID della tabella è volatile
        const hasVolatileId = isVolatileId(table.id);

        // PRIORITÀ 3: ID della tabella (solo se stabile, non volatile)
        if (table.id && !hasVolatileId) {
            return `${table.id}_${section}_${page}`;
        }

        // PRIORITÀ 4: Ultimo fallback - indice globale (molto instabile)
        const tables = document.querySelectorAll('table.table-filterable');
        const index = Array.from(tables).indexOf(table);
        console.warn('[TableResize] Using unstable global index for table, consider adding data-table-key or stable parent container with ID');
        return `table_${section}_${page}_${index}`;
    }

    /**
     * Salva le larghezze delle colonne in localStorage
     * Usa debounce per evitare salvataggi multipli durante il resize
     * Salva anche in sessionStorage come backup per refresh improvvisi
     */
    const saveTimeouts = new Map();
    function saveColumnWidths(tableKey, widths, immediate = false) {
        // Cancella il timeout precedente se esiste
        if (saveTimeouts.has(tableKey) && !immediate) {
            clearTimeout(saveTimeouts.get(tableKey));
        }

        const saveFunction = () => {
            const data = {
                widths: widths,
                timestamp: Date.now(),
                expiry: Date.now() + (EXPIRY_DAYS * 24 * 60 * 60 * 1000),
                version: '1.0' // Versione per future migrazioni
            };
            const key = STORAGE_PREFIX + tableKey;
            const dataString = JSON.stringify(data);

            try {
                // Salva in localStorage (persistente)
                localStorage.setItem(key, dataString);

                // Salva anche in sessionStorage come backup (sopravvive a refresh hard)
                try {
                    sessionStorage.setItem(key, dataString);
                } catch (e) {
                    // Ignora errori di sessionStorage (meno critico)
                }

                saveTimeouts.delete(tableKey);
            } catch (e) {
                // Se localStorage è pieno, prova a pulire vecchi dati
                if (e.name === 'QuotaExceededError' || e.code === 22) {
                    console.warn('[TableResize] localStorage pieno, pulizia vecchi dati...');
                    cleanupOldWidths();
                    // Riprova dopo la pulizia
                    try {
                        localStorage.setItem(key, dataString);
                        // Prova anche sessionStorage
                        try {
                            sessionStorage.setItem(key, dataString);
                        } catch (e3) {
                            // Ignora
                        }
                    } catch (e2) {
                        console.error('[TableResize] Impossibile salvare le larghezze anche dopo pulizia:', e2);
                        // Ultimo tentativo: salva solo in sessionStorage
                        try {
                            sessionStorage.setItem(key, dataString);
                        } catch (e4) {
                            console.error('[TableResize] Impossibile salvare anche in sessionStorage:', e4);
                        }
                    }
                } else {
                    console.warn('[TableResize] Impossibile salvare le larghezze delle colonne:', e);
                    // Prova almeno sessionStorage come backup
                    try {
                        sessionStorage.setItem(key, dataString);
                        console.log('[TableResize] Larghezze salvate solo in sessionStorage (backup)');
                    } catch (e2) {
                        console.error('[TableResize] Impossibile salvare anche in sessionStorage:', e2);
                    }
                }
                saveTimeouts.delete(tableKey);
            }
        };

        if (immediate) {
            saveFunction();
        } else {
            // Debounce: salva dopo 300ms di inattività (più veloce per sicurezza)
            const timeout = setTimeout(saveFunction, 300);
            saveTimeouts.set(tableKey, timeout);
        }
    }

    /**
     * Pulisce le larghezze scadute o vecchie per liberare spazio
     */
    function cleanupOldWidths() {
        try {
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(STORAGE_PREFIX)) {
                    try {
                        const data = JSON.parse(localStorage.getItem(key));
                        if (data.expiry && Date.now() > data.expiry) {
                            keysToRemove.push(key);
                        }
                    } catch (e) {
                        // Se non è possibile parsare, rimuovi comunque (dati corrotti)
                        keysToRemove.push(key);
                    }
                }
            }
            keysToRemove.forEach(key => {
                localStorage.removeItem(key);
            });
        } catch (e) {
            console.warn('[TableResize] Errore durante pulizia:', e);
        }
    }

    /**
     * Carica le larghezze delle colonne da localStorage o sessionStorage (backup)
     */
    function loadColumnWidths(tableKey) {
        const key = STORAGE_PREFIX + tableKey;

        // Prova prima localStorage (persistente)
        let stored = null;
        let source = 'localStorage';

        // Loading column widths for key: see console only on errors

        try {
            stored = localStorage.getItem(key);
        } catch (e) {
            console.warn('[TableResize] Errore accesso localStorage:', e);
        }

        // Se non trovato in localStorage, prova sessionStorage (backup)
        if (!stored) {
            try {
                stored = sessionStorage.getItem(key);
                if (stored) {
                    source = 'sessionStorage';
                }
            } catch (e) {
                // Ignora errori di sessionStorage
            }
        }

        if (!stored) {
            return null;
        }

        try {
            const data = JSON.parse(stored);

            // Verifica che i dati siano validi
            if (!data || !Array.isArray(data.widths)) {
                console.warn('[TableResize] Dati non validi per:', tableKey);
                try {
                    localStorage.removeItem(key);
                    sessionStorage.removeItem(key);
                } catch (e) {
                    // Ignora
                }
                return null;
            }

            // Verifica scadenza (solo per localStorage, sessionStorage può essere più recente)
            if (data.expiry && Date.now() > data.expiry) {
                try {
                    localStorage.removeItem(key);
                    // Non rimuovere da sessionStorage se è un backup recente
                    if (source === 'localStorage') {
                        sessionStorage.removeItem(key);
                    }
                } catch (e) {
                    // Ignora
                }
                return null;
            }

            // Se caricato da sessionStorage, copia in localStorage per persistenza
            if (source === 'sessionStorage') {
                try {
                    localStorage.setItem(key, stored);
                } catch (e) {
                    // Ignora errori di copia
                }
            }

            return data.widths;
        } catch (e) {
            console.warn('[TableResize] Errore nel caricamento delle larghezze per', tableKey, ':', e);
            // Rimuovi dati corrotti
            try {
                localStorage.removeItem(key);
                sessionStorage.removeItem(key);
            } catch (e2) {
                // Ignora errori di rimozione
            }
            return null;
        }
    }

    /**
     * Applica le larghezze salvate alla tabella
     */
    function applySavedWidths(table, widths) {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        Array.from(headerRow.cells).forEach((cell, index) => {
            if (widths[index] && widths[index] > 0) {
                cell.style.width = widths[index] + 'px';
                cell.style.minWidth = widths[index] + 'px';
                cell.style.maxWidth = widths[index] + 'px';

                // Applica anche alle celle del body
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    tbody.querySelectorAll('tr').forEach(row => {
                        if (row.cells[index]) {
                            row.cells[index].style.width = widths[index] + 'px';
                            row.cells[index].style.minWidth = widths[index] + 'px';
                            row.cells[index].style.maxWidth = widths[index] + 'px';
                        }
                    });
                }

                // Applica anche alla riga di filtro se esiste
                const filterRow = table.querySelector('thead .filter-row');
                if (filterRow && filterRow.cells[index]) {
                    filterRow.cells[index].style.width = widths[index] + 'px';
                    filterRow.cells[index].style.minWidth = widths[index] + 'px';
                    filterRow.cells[index].style.maxWidth = widths[index] + 'px';
                }
            }
        });

        // Forza il reflow per aggiornare il layout immediatamente
        table.offsetHeight;
    }

    /**
     * Ottiene le larghezze attuali delle colonne
     */
    function getCurrentWidths(table) {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return [];

        return Array.from(headerRow.cells).map(cell => {
            return cell.offsetWidth || 0;
        });
    }

    /**
     * Crea un handle di resize per una colonna
     */
    function createResizeHandle(table, columnIndex) {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow || !headerRow.cells[columnIndex]) return null;

        const cell = headerRow.cells[columnIndex];

        // Non aggiungere resize handle alla prima colonna (azioni) se è stretta
        if (columnIndex === 0 && cell.classList.contains('azioni-colonna')) {
            return null;
        }

        const handle = document.createElement('div');
        handle.className = 'column-resize-handle';
        handle.style.cssText = `
            position: absolute;
            top: 0;
            right: -4px;
            width: 8px;
            height: 100%;
            cursor: col-resize;
            z-index: 10;
            background: transparent;
            user-select: none;
        `;

        // Aggiungi hover effect
        handle.addEventListener('mouseenter', function () {
            handle.style.background = 'rgba(205, 33, 29, 0.2)';
        });
        handle.addEventListener('mouseleave', function () {
            if (!handle.classList.contains('resizing')) {
                handle.style.background = 'transparent';
            }
        });

        let isResizing = false;
        let startX = 0;
        let startWidth = 0;

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();

            isResizing = true;
            handle.classList.add('resizing');
            handle.style.background = 'rgba(205, 33, 29, 0.4)';

            startX = e.pageX;
            startWidth = cell.offsetWidth;

            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';

            const tableKey = getTableKey(table);

            let lastSaveTime = 0;
            function onMouseMove(e) {
                if (!isResizing) return;

                const diff = e.pageX - startX;
                const newWidth = Math.max(50, startWidth + diff); // Minimo 50px

                // Applica alle celle header
                cell.style.width = newWidth + 'px';
                cell.style.minWidth = newWidth + 'px';
                cell.style.maxWidth = newWidth + 'px';

                // Applica anche alle celle del body
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    tbody.querySelectorAll('tr').forEach(row => {
                        if (row.cells[columnIndex]) {
                            row.cells[columnIndex].style.width = newWidth + 'px';
                            row.cells[columnIndex].style.minWidth = newWidth + 'px';
                            row.cells[columnIndex].style.maxWidth = newWidth + 'px';
                        }
                    });
                }

                // Applica anche alla riga di filtro se esiste
                const filterRow = table.querySelector('thead .filter-row');
                if (filterRow && filterRow.cells[columnIndex]) {
                    filterRow.cells[columnIndex].style.width = newWidth + 'px';
                    filterRow.cells[columnIndex].style.minWidth = newWidth + 'px';
                    filterRow.cells[columnIndex].style.maxWidth = newWidth + 'px';
                }

                // Forza il reflow per aggiornare il layout
                table.offsetHeight;

                // Salva durante il resize ogni 2 secondi per sicurezza
                // (backup in caso di crash o refresh improvviso)
                const now = Date.now();
                if (now - lastSaveTime > 2000) {
                    const widths = getCurrentWidths(table);
                    saveColumnWidths(tableKey, widths, false); // Debounced ma frequente
                    lastSaveTime = now;
                }
            }

            function onMouseUp() {
                if (!isResizing) return;

                isResizing = false;
                handle.classList.remove('resizing');
                handle.style.background = 'transparent';

                document.body.style.cursor = '';
                document.body.style.userSelect = '';

                // Salva le nuove larghezze IMMEDIATAMENTE quando l'utente rilascia
                // (non usare debounce qui perché è importante salvare subito)
                // Aspetta un tick per assicurarsi che il DOM sia aggiornato
                setTimeout(() => {
                    const widths = getCurrentWidths(table);
                    saveColumnWidths(tableKey, widths, true); // immediate = true
                }, 0);

                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });

        return handle;
    }

    /**
     * Applica le larghezze salvate immediatamente, prima del rendering
     * Questa funzione viene chiamata il prima possibile per evitare il flash visivo
     */
    function applySavedWidthsEarly(table) {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return false;

        const tableKey = getTableKey(table);
        const savedWidths = loadColumnWidths(tableKey);

        if (savedWidths && savedWidths.length > 0) {
            // Applica le larghezze immediatamente alle celle header
            Array.from(headerRow.cells).forEach((cell, index) => {
                if (savedWidths[index] && savedWidths[index] > 0) {
                    cell.style.width = savedWidths[index] + 'px';
                    cell.style.minWidth = savedWidths[index] + 'px';
                    cell.style.maxWidth = savedWidths[index] + 'px';
                }
            });

            // Applica anche alle celle del body se esistono
            const tbody = table.querySelector('tbody');
            if (tbody) {
                tbody.querySelectorAll('tr').forEach(row => {
                    Array.from(row.cells).forEach((cell, index) => {
                        if (savedWidths[index] && savedWidths[index] > 0) {
                            cell.style.width = savedWidths[index] + 'px';
                            cell.style.minWidth = savedWidths[index] + 'px';
                            cell.style.maxWidth = savedWidths[index] + 'px';
                        }
                    });
                });
            }

            // Applica anche alla riga di filtro se esiste
            const filterRow = table.querySelector('thead .filter-row');
            if (filterRow) {
                Array.from(filterRow.cells).forEach((cell, index) => {
                    if (savedWidths[index] && savedWidths[index] > 0) {
                        cell.style.width = savedWidths[index] + 'px';
                        cell.style.minWidth = savedWidths[index] + 'px';
                        cell.style.maxWidth = savedWidths[index] + 'px';
                    }
                });
            }

            // Forza il reflow immediatamente
            table.offsetHeight;
            return true;
        }
        return false;
    }

    /**
     * Mostra la tabella quando è completamente pronta
     */
    function showTableWhenReady(table) {
        // Aspetta che le larghezze siano applicate e la paginazione sia inizializzata
        requestAnimationFrame(() => {
            table.classList.add('table-ready');
        });
    }

    /**
     * Helper per misurazione precisa del testo
     * Usa un elemento DOM nascosto per replicare il rendering esatto del browser
     */
    const MeasureUtils = {
        element: null,
        init() {
            if (this.element) return;
            this.element = document.createElement('div');
            this.element.style.cssText = 'position:absolute; visibility:hidden; height:auto; width:auto; white-space:nowrap; top:-9999px; left:-9999px; pointer-events:none; border:0; margin:0;';
            document.body.appendChild(this.element);
        },
        measure(text, font, padding = 20) {
            if (!this.element) this.init();
            this.element.style.font = font;
            this.element.textContent = text;
            return this.element.getBoundingClientRect().width + padding; // + padding di sicurezza
        }
    };

    /**
     * Applica l'auto-sizing intelligente alle colonne
     * @param {HTMLTableElement} table 
     * @param {boolean} force Se true, sovrascrive anche se ci sono larghezze salvate (usato dal reset)
     */
    function applyAutoColumnSizing(table, force = false) {
        if (!table) return;

        // Se la tabella non è visibile, riprovare più tardi (max 5 tentativi)
        if (table.offsetParent === null || table.clientWidth === 0) {
            const retries = parseInt(table.dataset.autosizeRetries || '0');
            if (retries < 5) {
                table.dataset.autosizeRetries = retries + 1;
                setTimeout(() => applyAutoColumnSizing(table, force), 200 + (retries * 200));
            }
            return;
        }

        // Reset contatore retries se successo
        table.dataset.autosizeRetries = '0';

        const tableKey = getTableKey(table);

        // Se esistono preferenze salvate e non è un reset forzato, esci
        if (!force && loadColumnWidths(tableKey)) {
            return;
        }

        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        // NUOVO: Controlla se le colonne hanno già larghezze CSS definite (percentuali)
        // Se sì, salta l'auto-sizing per preservare il layout responsive
        const headerCells = Array.from(headerRow.cells);
        let hasPercentageWidths = false;

        if (headerCells.length > 0) {
            const firstCellStyle = window.getComputedStyle(headerCells[0]);
            const cssWidth = headerCells[0].style.width || firstCellStyle.width;

            // Controlla se almeno una colonna ha width percentuale nel CSS
            for (let i = 0; i < headerCells.length; i++) {
                const cell = headerCells[i];
                const computedStyle = window.getComputedStyle(cell);
                const inlineWidth = cell.style.width;

                // Se c'è una width inline (da resize manuale), non è percentuale CSS
                if (inlineWidth && inlineWidth.includes('px')) {
                    continue;
                }

                // Cerca width percentuale nel CSS (visibile in DevTools ma non sempre accessibile via JS)
                // Euristica: se la prima colonna non ha inline styles e la tabella è in container MOM,
                // probabilmente ha width CSS percentuali
                const tableParent = table.closest('#mom-partecipanti-container, #mom-items-container');
                if (tableParent && !inlineWidth) {
                    hasPercentageWidths = true;
                    break;
                }
            }
        }

        if (hasPercentageWidths) {
            // Skip auto-sizing: table has CSS percentage widths
            return;
        }

        const colCount = headerCells.length;
        const availableWidth = table.clientWidth; // Larghezza totale disponibile

        // Campionamento righe (max 30 per performance)
        const tbody = table.querySelector('tbody');
        const rows = tbody ? Array.from(tbody.querySelectorAll('tr')).slice(0, 30) : [];

        // Font styles per misurazione
        const headerFont = window.getComputedStyle(headerCells[0]).font;
        const bodyFont = rows.length > 0 && rows[0].cells[0] ? window.getComputedStyle(rows[0].cells[0]).font : headerFont;

        // Configurazioni colonne
        const colConfigs = headerCells.map((cell, index) => {
            const text = cell.textContent.trim().toLowerCase();
            const isAction = index === 0 && (cell.classList.contains('azioni-colonna') || text === '' || text === 'azioni');
            const isCheckbox = !!cell.querySelector('input[type="checkbox"]');

            // Definisce tipo e min/max width in base all'header (euristica)
            let min = 60, max = 400, type = 'text';
            let priority = 1; // 0=bassa (riducibile), 1=media, 2=alta (espandibile)

            if (isAction) {
                min = 50; max = 90; type = 'fixed'; priority = 0;
            } else if (isCheckbox) {
                min = 40; max = 50; type = 'fixed'; priority = 0;
            } else if (text === 'id' || text === '#') {
                min = 50; max = 80; type = 'code'; priority = 0;
            } else if (text.includes('data') || text.includes('date') || text.includes('scadenza')) {
                min = 100; max = 140; type = 'date'; priority = 0;
            } else if (text.includes('stato') || text.includes('status') || text.includes('codice') || text.includes('prot')) {
                min = 90; max = 160; type = 'code'; priority = 0;
            } else if (text.includes('descrizione') || text.includes('oggetto') || text.includes('titolo') || text.includes('note')) {
                min = 200; max = 800; type = 'long-text'; priority = 2;
            } else if (text.includes('email')) {
                min = 150; max = 300; type = 'text'; priority = 1;
            }

            return {
                index,
                text,
                type,
                min,
                max,
                priority,
                measuredWidth: 0,
                finalWidth: 0
            };
        });

        // Misurazione contenuto (Header + Body)
        MeasureUtils.init();

        colConfigs.forEach(conf => {
            let maxW = 0;

            // Misura header
            const headerText = headerCells[conf.index].textContent.trim();
            if (headerText) {
                maxW = Math.max(maxW, MeasureUtils.measure(headerText, headerFont, 25)); // + icona sort/filter
            }

            // Misura body rows
            rows.forEach(row => {
                const cell = row.cells[conf.index];
                if (cell) {
                    const txt = cell.textContent.trim();
                    if (txt.length > 0) {
                        // Ottimizzazione: se testo lunghissimo, non misurare tutto se type è long-text (andrà a capo)
                        // Ma se code/date, misura tutto
                        if (conf.type === 'long-text' && txt.length > 100) {
                            maxW = Math.max(maxW, 300); // euristica
                        } else {
                            // Misura precisa con MeasureUtils
                            const measured = MeasureUtils.measure(txt, bodyFont, 24); // padding leggermente aumentato
                            maxW = Math.max(maxW, measured);
                        }
                    }
                }
            });

            // Special handling per checkbox/icone: forza width fissa se rilevato
            if (conf.type === 'fixed') {
                // Se è una checkbox o icona, non vogliamo che si espanda troppo
                // Usa min come target primario
                conf.measuredWidth = conf.min;
            } else {
                // Clamp sui min/max euristici
                conf.measuredWidth = Math.max(conf.min, Math.min(maxW, conf.max));
            }

            // Se la tabella è vuota e colonna non speciale, usa default ragionevole
            if (rows.length === 0 && conf.measuredWidth === conf.min) {
                conf.measuredWidth = Math.min(conf.max, Math.max(conf.min, headerText.length * 10 + 30));
            }
        });

        // Calcolo distribuzione spazio
        const totalMeasured = colConfigs.reduce((sum, c) => sum + c.measuredWidth, 0);
        let remainingSpace = availableWidth - totalMeasured;

        // Se c'è spazio extra, distribuiscilo alle colonne 'priority 2' (long-text) o '1' (text)
        if (remainingSpace > 0) {
            const expandable = colConfigs.filter(c => c.priority >= 1);
            const totalWeight = expandable.reduce((sum, c) => sum + (c.priority === 2 ? 2 : 1), 0);

            if (totalWeight > 0) {
                expandable.forEach(c => {
                    const weight = c.priority === 2 ? 2 : 1;
                    const portion = Math.floor((remainingSpace * weight) / totalWeight);
                    c.measuredWidth += portion;
                });
            }
        }
        // Se manca spazio, riduci le colonne a bassa priorità
        else if (remainingSpace < 0) {
            // TODO: implementare riduzione intelligente se necessario
            // Per ora lasciamo lo scroll orizzontale che è standard nelle table-filterable
        }

        // Applica le larghezze finali
        const finalWidths = colConfigs.map(c => Math.floor(c.measuredWidth));

        // Applica al DOM
        applySavedWidths(table, finalWidths); // Riusa funzione esistente per applicare stili

        // IMPORTANTE: Non salviamo su localStorage (saveColumnWidths) 
        // L'auto-sizing è volatile finché l'utente non fa resize manuale
    }

    /**
     * Inizializza il resize per una tabella
     */
    function initTableResize(table) {
        if (!table) return;

        // Assicura key
        const tableKey = getTableKey(table);

        // Controllo se ci sono larghezze salvate
        let hasSaved = false;
        const savedWidths = loadColumnWidths(tableKey);

        if (savedWidths && savedWidths.length > 0) {
            applySavedWidths(table, savedWidths);
            hasSaved = true;
        } else {
            // Auto-sizing iniziale
            applyAutoColumnSizing(table);
        }

        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        // Preparazione UI per resize manuale
        Array.from(headerRow.cells).forEach(cell => {
            if (window.getComputedStyle(cell).position === 'static') {
                cell.classList.add('resizable-header');
            }
        });

        // Aggiunta handles se non esistono
        if (!table.querySelector('.column-resize-handle')) {
            Array.from(headerRow.cells).forEach((cell, index) => {
                if (index === 0 && cell.classList.contains('azioni-colonna')) return;
                const handle = createResizeHandle(table, index);
                if (handle) cell.appendChild(handle);
            });
        }

        showTableWhenReady(table);
    }

    /**
     * Applica le larghezze salvate immediatamente a tutte le tabelle visibili
     * Chiamata il prima possibile per evitare il flash visivo
     */
    function applyAllSavedWidthsEarly() {
        const tables = document.querySelectorAll('table.table-filterable');
        tables.forEach(table => {
            if (!table.dataset.widthsApplied) {
                applySavedWidthsEarly(table);
                table.dataset.widthsApplied = 'true';
            }
        });
    }

    /**
     * Inizializza il resize per tutte le tabelle table-filterable
     */
    function initAllTableResize() {
        document.querySelectorAll('table.table-filterable').forEach(table => {
            initTableResize(table);
            // Se la tabella non ha ancora la classe table-ready, aggiungila dopo un breve delay
            // per permettere alla paginazione di inizializzarsi (se non è già stata aggiunta)
            if (!table.classList.contains('table-ready')) {
                setTimeout(() => {
                    // Verifica che la tabella non sia già stata mostrata da altro codice
                    if (!table.classList.contains('table-ready')) {
                        table.classList.add('table-ready');
                    }
                }, 100);
            }
        });
    }

    // Applica le larghezze salvate IMMEDIATAMENTE quando lo script viene caricato
    // Questo evita il flash visivo durante il rendering iniziale
    function initializeTableWidths() {
        // Applica le larghezze salvate immediatamente senza nascondere nulla
        applyAllSavedWidthsEarly();

        // Inizializza il resize completo dopo un breve delay per permettere il rendering
        setTimeout(initAllTableResize, 50);
    }

    if (document.readyState === 'loading') {
        // Se il DOM non è ancora pronto, applica le larghezze non appena possibile
        document.addEventListener('DOMContentLoaded', function () {
            // Applica immediatamente quando il DOM è pronto
            initializeTableWidths();
        });
    } else {
        // DOM già pronto, applica immediatamente
        initializeTableWidths();
    }

    // Re-inizializza dopo eventuali caricamenti dinamici
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) { // Element node
                    if (node.classList && node.classList.contains('table-filterable')) {
                        // Applica le larghezze salvate immediatamente
                        if (!node.dataset.widthsApplied) {
                            applySavedWidthsEarly(node);
                            node.dataset.widthsApplied = 'true';
                        }
                        // Poi inizializza il resize completo
                        setTimeout(() => initTableResize(node), 50);
                    }
                    // Cerca anche tabelle dentro i nodi aggiunti
                    if (node.querySelectorAll) {
                        node.querySelectorAll('table.table-filterable').forEach(table => {
                            if (!table.querySelector('.column-resize-handle')) {
                                // Applica le larghezze salvate immediatamente
                                if (!table.dataset.widthsApplied) {
                                    applySavedWidthsEarly(table);
                                    table.dataset.widthsApplied = 'true';
                                }
                                // Poi inizializza il resize completo
                                setTimeout(() => initTableResize(table), 50);
                            }
                        });
                    }
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    /**
     * Salva tutte le larghezze prima di un refresh della pagina
     * Questo cattura anche i refresh hard (Ctrl+Shift+R)
     */
    function saveAllWidthsBeforeUnload() {
        const tables = document.querySelectorAll('table.table-filterable');
        tables.forEach(table => {
            const tableKey = getTableKey(table);
            const widths = getCurrentWidths(table);
            if (widths && widths.length > 0) {
                // Salva immediatamente senza debounce
                saveColumnWidths(tableKey, widths, true);
            }
        });
    }

    // Salva prima che la pagina venga scaricata (refresh, chiusura, navigazione)
    // IMPORTANTE: beforeunload funziona anche con Ctrl+Shift+R
    window.addEventListener('beforeunload', function () {
        saveAllWidthsBeforeUnload();
    });

    // Salva anche quando la pagina diventa nascosta (per sicurezza extra)
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            saveAllWidthsBeforeUnload();
        }
    });

    // Esponi funzione pubblica per re-inizializzazione manuale
    /**
     * Resetta le larghezze delle colonne e ri-applica auto-sizing
     */
    window.resetTableColumnWidths = function (table) {
        if (!table) return;
        const tableKey = getTableKey(table);
        const key = STORAGE_PREFIX + tableKey;

        try {
            localStorage.removeItem(key);
            sessionStorage.removeItem(key);
        } catch (e) { }

        // Rimuove stili width attuali
        const headerRow = table.querySelector('thead tr');
        if (headerRow) {
            Array.from(headerRow.cells).forEach(cell => {
                cell.style.width = '';
                cell.style.minWidth = '';
                cell.style.maxWidth = '';
            });
        }

        // Rimuove stili celle body
        const tbody = table.querySelector('tbody');
        if (tbody) {
            tbody.querySelectorAll('tr').forEach(row => {
                Array.from(row.cells).forEach(cell => {
                    cell.style.width = '';
                    cell.style.minWidth = '';
                    cell.style.maxWidth = '';
                });
            });
        }

        // Forza reflow e ricalcolo
        table.offsetHeight;
        applyAutoColumnSizing(table, true);
        console.log('[TableResize] Reset completato per', tableKey);
    };

    window.initTableResize = initTableResize;
    window.initAllTableResize = initAllTableResize;

})();

