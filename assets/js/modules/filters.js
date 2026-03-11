// ========== SISTEMA FILTRI DINAMICI ==========
// Configurabile per pagina, zero duplicazione, facilmente sostituibile
//
// USO:
// 1. In PHP: <div id="filter-container" data-table="nome_tabella" data-filters='["col1","col2"]'></div>
// 2. In JS: window.loadFilters() oppure viene caricato automaticamente
// 3. Configurazione labels: window.FILTER_LABELS = { col1: "Nome visualizzato" }

document.addEventListener("DOMContentLoaded", function () {

    // Registry callback per containerId → fn(state)
    const _onApplyByContainer = new Map();

    // URL param keys (corti per non inquinare la querystring)
    const URL_KEY = { page: 'p', pageSize: 'ps', search: 'q', sort: 's', dir: 'd', filters: 'f' };
    // Soglia oltre la quale filters va in sessionStorage anziché in URL
    const URL_FILTERS_MAX_LEN = 1500;

    // ── URL persistence helpers ────────────────────────────────────

    /**
     * Scrive lo state nella querystring corrente (history.replaceState).
     * Se il JSON dei filtri supera URL_FILTERS_MAX_LEN, salva in sessionStorage.
     */
    function setUrlFromState(state) {
        var url = new URL(window.location.href);
        var sp = url.searchParams;

        // Scrivi campi scalari (solo se diversi da default)
        if (state.page && state.page > 1)   sp.set(URL_KEY.page, state.page);      else sp.delete(URL_KEY.page);
        if (state.pageSize && state.pageSize !== 50) sp.set(URL_KEY.pageSize, state.pageSize); else sp.delete(URL_KEY.pageSize);
        if (state.search)                    sp.set(URL_KEY.search, state.search);  else sp.delete(URL_KEY.search);
        if (state.sort)                      sp.set(URL_KEY.sort, state.sort);      else sp.delete(URL_KEY.sort);
        if (state.dir && state.dir !== 'asc') sp.set(URL_KEY.dir, state.dir);       else sp.delete(URL_KEY.dir);

        // Filtri: JSON compatto
        var filtersJson = JSON.stringify(state.filters || {});
        var ssKey = 'filters:' + state.containerId;
        if (filtersJson === '{}') {
            sp.delete(URL_KEY.filters);
            try { sessionStorage.removeItem(ssKey); } catch (e) { /* ignore */ }
        } else if (filtersJson.length > URL_FILTERS_MAX_LEN) {
            // Troppo lungo per URL → sessionStorage
            sp.delete(URL_KEY.filters);
            try { sessionStorage.setItem(ssKey, filtersJson); } catch (e) { /* ignore */ }
        } else {
            sp.set(URL_KEY.filters, filtersJson);
            try { sessionStorage.removeItem(ssKey); } catch (e) { /* ignore */ }
        }

        history.replaceState(null, '', url.toString());
    }

    /**
     * Legge lo state dall'URL corrente (o da sessionStorage per filtri lunghi).
     * Ritorna null se non ci sono parametri filtro nell'URL.
     */
    function loadStateFromUrl(containerId) {
        var sp = new URLSearchParams(window.location.search);

        // Controlla se esiste almeno un parametro filtro nostro
        var hasAny = [URL_KEY.page, URL_KEY.pageSize, URL_KEY.search,
                      URL_KEY.sort, URL_KEY.dir, URL_KEY.filters].some(function (k) { return sp.has(k); });

        // Controlla anche sessionStorage
        var ssKey = 'filters:' + containerId;
        var ssFilters = null;
        try { ssFilters = sessionStorage.getItem(ssKey); } catch (e) { /* ignore */ }

        if (!hasAny && !ssFilters) return null;

        // Ricostruisci filters
        var filters = {};
        var fParam = sp.get(URL_KEY.filters);
        if (fParam) {
            try { filters = JSON.parse(fParam); } catch (e) { filters = {}; }
        } else if (ssFilters) {
            try { filters = JSON.parse(ssFilters); } catch (e) { filters = {}; }
        }

        return {
            containerId: containerId,
            table: '',   // verrà sovrascritto dal form
            page:     parseInt(sp.get(URL_KEY.page), 10) || 1,
            pageSize: parseInt(sp.get(URL_KEY.pageSize), 10) || 50,
            search:   sp.get(URL_KEY.search) || '',
            sort:     sp.get(URL_KEY.sort) || null,
            dir:      sp.get(URL_KEY.dir) || 'asc',
            filters:  filters
        };
    }

    /**
     * Dopo che generateFilters ha creato i <select>, ripristina i valori
     * dal savedState (letto da URL).
     */
    function restoreSelectsFromState(form, savedFilters) {
        if (!savedFilters || typeof savedFilters !== 'object') return;
        Object.entries(savedFilters).forEach(function (entry) {
            var key = entry[0];
            var val = entry[1];
            // Ignora chiavi periodo (from_date, to_date) — gestite separatamente
            if (key === 'from_date' || key === 'to_date') return;
            var select = form.querySelector('select[name="' + CSS.escape(key) + '"]');
            if (select) {
                select.value = val;
            }
        });
    }

    // ── FilterState builder ────────────────────────────────────────

    /**
     * Costruisce lo stato canonico dai dati del form e dai data-* attributes.
     * @param {HTMLFormElement} form
     * @param {Object} filters - filtri già estratti (post-cleanup)
     * @returns {Object} FilterState
     */
    function buildFilterState(form, filters) {
        var containerId = form.dataset.containerId || "filter-container";
        return {
            containerId: containerId,
            table:    form.dataset.table || '',
            columns:  JSON.parse(form.dataset.columns || '[]'),
            page:     1,
            pageSize: 50,
            search:   '',
            sort:     null,
            dir:      'asc',
            filters:  filters
        };
    }

    // ── Configurazione centralizzata per pagina ────────────────────

    window.FILTER_CONFIGS = window.FILTER_CONFIGS || {
        gestione_segnalazioni: {
            table: 'forms',
            columns: ['form_name', 'status_id', 'responsabile'],
            labels: {
                form_name: 'Tipo Segnalazione',
                status_id: 'Stato',
                responsabile: 'Responsabile'
            },
            enablePeriodFilter: true
        },
        gare: {
            table: 'ext_jobs', // Ora usiamo ext_jobs
            columns: ['tipo', 'stato'],
            labels: {
                tipo: 'Tipologia',
                stato: 'Stato'
            },
            enablePeriodFilter: false
        }
    };

    // ── loadFilters ────────────────────────────────────────────────

    window.loadFilters = function (customConfig, containerId) {
        // containerId: default "filter-container" per backward compat
        containerId = containerId || (customConfig && customConfig.containerId) || "filter-container";

        var filterContainer = document.getElementById(containerId);
        if (!filterContainer) return;

        // Controllo più robusto per evitare chiamate duplicate
        if (filterContainer.dataset.loaded === "true") {
            return;
        }

        // Marca come "in caricamento" per evitare race conditions
        if (filterContainer.dataset.loading === "true") {
            return;
        }
        filterContainer.dataset.loading = "true";
        filterContainer.dataset.loaded = "true";

        // Determina configurazione (da parametro, data-attributes, o config centralizzata)
        var config = customConfig;

        if (!config) {
            var tableName = filterContainer.dataset.table;
            var allowedFilters = JSON.parse(filterContainer.dataset.filters || "[]");
            var enablePeriod = filterContainer.dataset.enablePeriodFilter === "true";

            if (tableName && allowedFilters.length > 0) {
                config = {
                    table: tableName,
                    columns: allowedFilters,
                    enablePeriodFilter: enablePeriod
                };
            }
        }

        if (!config) {
            // Cerca nella configurazione centralizzata
            var urlParams = new URLSearchParams(window.location.search);
            var currentPage = urlParams.get("page");
            config = window.FILTER_CONFIGS[currentPage];
        }

        if (!config || !config.table || !config.columns || config.columns.length === 0) {
            filterContainer.dataset.loading = "false";
            return;
        }

        // Associa containerId alla config per uso in generateFilters/applyFilters
        config._containerId = containerId;

        // Leggi eventuale stato salvato da URL
        config._savedState = loadStateFromUrl(containerId);

        // Registra callback onApply se fornito nella config
        if (typeof config.onApply === 'function') {
            _onApplyByContainer.set(containerId, config.onApply);
        }

        customFetch("filters", "getDynamicFilters", {
            table: config.table,
            columns: config.columns
        })
            .then(function (data) {
                if (!data.success) throw new Error(data.message);
                generateFilters(data.filters, config);
                filterContainer.dataset.loading = "false";
            })
            .catch(function (error) {
                console.error("Errore caricamento filtri:", error);
                filterContainer.dataset.loading = "false";
                filterContainer.dataset.loaded = "false"; // Permetti retry in caso di errore
            });
    };

    // ── generateFilters ────────────────────────────────────────────

    function generateFilters(filters, config) {
        var containerId = config._containerId || "filter-container";
        var container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = "";

        var form = document.createElement("form");
        form.setAttribute("id", "dynamic-filters");
        form.classList.add("filter-grid");
        // Salva meta sul form per applyFilters / buildFilterState
        form.dataset.containerId = containerId;
        form.dataset.table = config.table;
        form.dataset.columns = JSON.stringify(config.columns);

        // Labels: usa config.labels, poi window.FILTER_LABELS, poi fallback
        var labels = config?.labels || window.FILTER_LABELS || {
            form_name: "Tipo",
            status_id: "Stato",
            responsabile: "Responsabile",
            tipo: "Tipologia",
            stato: "Stato",
            priority: "Priorità",
            assegnato_a: "Assegnato a",
            ente: "Ente",
            luogo: "Luogo"
        };

        Object.entries(filters).forEach(function (entry) {
            var column = entry[0];
            var values = entry[1];

            var filterWrapper = document.createElement("div");
            filterWrapper.classList.add("filter-item", "filter-select-wrapper");

            var label = document.createElement("label");
            label.textContent = labels[column] || column;

            var select = document.createElement("select");
            select.setAttribute("name", column);
            select.classList.add("filter-select");

            // Opzione predefinita "Tutti"
            var defaultOption = document.createElement("option");
            defaultOption.value = "";
            defaultOption.textContent = "Tutti";
            select.appendChild(defaultOption);

            // Opzioni filtro
            values.forEach(function (val) {
                var option = document.createElement("option");
                option.value = val;

                // Formattazione valore
                if (column === "status_id" && window.STATI_MAP) {
                    option.textContent = window.STATI_MAP[val] || val;
                } else {
                    option.textContent = val;
                }

                select.appendChild(option);
            });

            // Evento change
            select.addEventListener("change", function () { applyFilters(); });

            filterWrapper.appendChild(label);
            filterWrapper.appendChild(select);
            form.appendChild(filterWrapper);
        });

        // Aggiunta filtro periodo (se abilitato)
        if (config?.enablePeriodFilter || container.dataset.enablePeriodFilter === "true") {
            var periodWrapper = document.createElement("div");
            periodWrapper.classList.add("filter-item", "filter-select-wrapper");

            var periodLabel = document.createElement("label");
            periodLabel.textContent = "Periodo:";

            var periodInput = document.createElement("input");
            periodInput.setAttribute("type", "text");
            periodInput.setAttribute("id", "period-picker");
            periodInput.setAttribute("readonly", "true");
            periodInput.setAttribute("placeholder", "Seleziona periodo...");
            periodInput.classList.add("date-range-picker", "filter-field");

            var clearBtn = document.createElement("span");
            clearBtn.classList.add("clear-filter");
            clearBtn.innerHTML = "&times;";
            clearBtn.style.visibility = "hidden";

            periodInput.addEventListener("input", function () {
                clearBtn.style.visibility = this.value ? "visible" : "hidden";
            });

            clearBtn.onclick = function () {
                periodInput.value = "";
                clearBtn.style.visibility = "hidden";
                applyFilters();
            };

            periodWrapper.appendChild(periodLabel);
            periodWrapper.appendChild(periodInput);
            periodWrapper.appendChild(clearBtn);
            form.appendChild(periodWrapper);

            // Inizializza flatpickr se disponibile
            setTimeout(function () {
                if (typeof flatpickr === "function" && typeof monthSelectPlugin === "function") {
                    flatpickr("#period-picker", {
                        mode: "range",
                        dateFormat: "F Y",
                        locale: {
                            firstDayOfWeek: 1,
                            weekdays: {
                                shorthand: ["Dom", "Lun", "Mar", "Mer", "Gio", "Ven", "Sab"],
                                longhand: ["Domenica", "Lunedì", "Martedì", "Mercoledì", "Giovedì", "Venerdì", "Sabato"]
                            },
                            months: {
                                shorthand: ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu", "Lug", "Ago", "Set", "Ott", "Nov", "Dic"],
                                longhand: ["Gennaio", "Febbraio", "Marzo", "Aprile", "Maggio", "Giugno", "Luglio", "Agosto", "Settembre", "Ottobre", "Novembre", "Dicembre"]
                            }
                        },
                        plugins: [new monthSelectPlugin({ shorthand: true, dateFormat: "F Y" })],
                        onClose: function (selectedDates, dateStr) {
                            periodInput.value = dateStr;
                            clearBtn.style.visibility = "visible";
                            applyFilters();
                        }
                    });
                }
            }, 100);
        }

        container.appendChild(form);

        // ── Restore da URL (se presente) ───────────────────────────
        var savedState = config._savedState;
        if (savedState && savedState.filters && Object.keys(savedState.filters).length > 0) {
            restoreSelectsFromState(form, savedState.filters);
            // Trigger applyFilters per sincronizzare stato + notificare consumer
            // setTimeout 0 per dare tempo al DOM di aggiornarsi
            setTimeout(function () { applyFilters(); }, 0);
        }
    }

    // ── applyFilters ───────────────────────────────────────────────

    function applyFilters() {
        var form = document.getElementById("dynamic-filters");
        if (!form) return;

        var formData = new FormData(form);
        var filters = Object.fromEntries(formData.entries());

        // Gestione filtro periodo (se presente)
        var periodInput = document.getElementById("period-picker");
        if (periodInput && periodInput.value) {
            var dateRange = periodInput.value.split(" to ");
            var monthMap = {
                "Gennaio": "01", "Febbraio": "02", "Marzo": "03", "Aprile": "04",
                "Maggio": "05", "Giugno": "06", "Luglio": "07", "Agosto": "08",
                "Settembre": "09", "Ottobre": "10", "Novembre": "11", "Dicembre": "12"
            };

            if (dateRange.length === 2) {
                var parts0 = dateRange[0].split(" ");
                var parts1 = dateRange[1].split(" ");
                if (monthMap[parts0[0]] && monthMap[parts1[0]]) {
                    filters["from_date"] = parts0[1] + "-" + monthMap[parts0[0]] + "-01";
                    filters["to_date"]   = parts1[1] + "-" + monthMap[parts1[0]] + "-31";
                }
            } else if (dateRange.length === 1) {
                var parts = dateRange[0].split(" ");
                if (monthMap[parts[0]]) {
                    filters["from_date"] = parts[1] + "-" + monthMap[parts[0]] + "-01";
                    filters["to_date"]   = parts[1] + "-" + monthMap[parts[0]] + "-31";
                }
            }
        }

        // Rimuovi filtri vuoti
        Object.keys(filters).forEach(function (key) {
            if (!filters[key] || filters[key] === '') delete filters[key];
        });

        // Costruisci stato canonico
        var state = buildFilterState(form, filters);

        // Salva stato globale (namespaced per container)
        window.__APPLIED_FILTERS__ = window.__APPLIED_FILTERS__ || {};
        window.__APPLIED_FILTERS__[state.containerId] = state;

        // Persisti in URL
        setUrlFromState(state);

        // Backward compat: meta come prima
        var meta = {
            containerId: state.containerId,
            table: state.table,
            columns: state.columns
        };

        // Dispatch evento con payload strutturato
        window.dispatchEvent(new CustomEvent('filters:applied', {
            detail: { state: state, filters: state.filters, meta: meta }
        }));

        // Callback: usa registry per containerId, altrimenti fallback generico
        var onApplyFn = _onApplyByContainer.get(state.containerId);
        if (typeof onApplyFn === 'function') {
            onApplyFn(state);
        } else if (typeof window.onFiltersApplied === 'function') {
            // Fallback generico per pagine non ancora migrate
            window.onFiltersApplied(state);
        }

        // Refresh viste calendario/gantt se presenti
        if (window.CalendarView?.refresh) window.CalendarView.refresh();
        if (window.GanttView?.refresh) window.GanttView.refresh();
    }

    // ── API pubblica ───────────────────────────────────────────────

    /**
     * Registra (o sostituisce) il callback onApply per un container specifico.
     * @param {string} containerId - ID del container filtri
     * @param {function} fn - callback(state)
     */
    window.setFilterOnApply = function (containerId, fn) {
        if (typeof containerId === 'string' && typeof fn === 'function') {
            _onApplyByContainer.set(containerId, fn);
        }
    };

    // Auto-carica filtri se il container esiste
    setTimeout(function () {
        var filterContainer = document.getElementById("filter-container");
        if (filterContainer && !filterContainer.dataset.loaded) {
            window.loadFilters();
        }
    }, 100);

});
