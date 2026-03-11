/**
 * commesse_table_filters.js
 *
 * Filtri SMART per tabelle commesse (elencoCommesseTable / archivioCommesseTable).
 * Opera client-side tramite data attributes sulle righe <tr>:
 *   data-scadenza, data-valore, data-bu, data-pm, data-stato
 *
 * Integrazione con paginazione:
 *   - Wrappa window.rowMatchesFilters per far rispettare data-smart-hidden
 *   - Dopo ogni filtraggio, chiama table._paginationUpdateView()
 *   - La paginazione ri-calcola automaticamente le righe visibili
 */
(function () {
    'use strict';

    // Wrap rowMatchesFilters PRIMA che la paginazione faccia il primo render.
    wrapRowMatchesFilters();

    document.addEventListener('DOMContentLoaded', function () {
        // Aspetta che initTableFilters + initClientSidePagination abbiano finito (500ms + margine)
        setTimeout(initSmartFilters, 800);
    });

    /**
     * Wrappa window.rowMatchesFilters per escludere righe con data-smart-hidden.
     * Se rowMatchesFilters non esiste ancora, ritenta.
     */
    function wrapRowMatchesFilters() {
        if (typeof window.rowMatchesFilters === 'function' && !window._rowMatchesFiltersWrapped) {
            var original = window.rowMatchesFilters;
            window.rowMatchesFilters = function (row, filters, mapping) {
                if (row.hasAttribute('data-smart-hidden')) return false;
                return original(row, filters, mapping);
            };
            window._rowMatchesFiltersWrapped = true;
        } else if (!window._rowMatchesFiltersWrapped) {
            setTimeout(wrapRowMatchesFilters, 100);
        }
    }

    function initSmartFilters() {
        var bars = document.querySelectorAll('.table-top-filters[data-table]');
        for (var i = 0; i < bars.length; i++) {
            setupFilterBar(bars[i]);
        }
    }

    function setupFilterBar(bar) {
        var tableId = bar.getAttribute('data-table');
        var mode = bar.getAttribute('data-mode'); // 'elenco' | 'archivio'
        var table = document.getElementById(tableId);
        if (!table) return;

        // Riferimenti elementi filtro
        var selectStato = bar.querySelector('[data-filter="stato"]');
        var selectBU = bar.querySelector('[data-filter="bu"]');
        var selectPM = bar.querySelector('[data-filter="pm"]');
        var selectScadenza = bar.querySelector('[data-filter="scadenza"]');
        var inputMin = bar.querySelector('[data-filter="valoreMin"]');
        var inputMax = bar.querySelector('[data-filter="valoreMax"]');
        var btnReset = bar.querySelector('[data-filter="reset"]');

        function applySmartFilters() {
            var tbody = table.querySelector('tbody');
            if (!tbody) return;

            var rows = tbody.querySelectorAll('tr');
            var statoVal = selectStato ? selectStato.value : '';
            var buVal = selectBU ? selectBU.value : '';
            var pmVal = selectPM ? selectPM.value : '';
            var presetVal = selectScadenza ? selectScadenza.value : 'tutte';
            var minVal = inputMin && inputMin.value !== '' ? parseFloat(inputMin.value) : null;
            var maxVal = inputMax && inputMax.value !== '' ? parseFloat(inputMax.value) : null;
            var today = getTodayString();

            for (var r = 0; r < rows.length; r++) {
                var row = rows[r];

                var passStato = statoVal === '' || (row.getAttribute('data-stato') || '') === statoVal;
                var passBU = buVal === '' || (row.getAttribute('data-bu') || '') === buVal;
                var passPM = pmVal === '' || (row.getAttribute('data-pm') || '') === pmVal;
                var passScadenza = checkScadenza(row.getAttribute('data-scadenza') || '', presetVal, mode, today);
                var passValore = checkValore(row.getAttribute('data-valore') || '', minVal, maxVal);

                if (passStato && passBU && passPM && passScadenza && passValore) {
                    row.removeAttribute('data-smart-hidden');
                } else {
                    row.setAttribute('data-smart-hidden', '1');
                }
            }

            refreshPagination(table);
        }

        // Bind events - selects
        var selects = [selectStato, selectBU, selectPM, selectScadenza];
        for (var s = 0; s < selects.length; s++) {
            if (selects[s]) {
                selects[s].addEventListener('change', applySmartFilters);
            }
        }

        // Bind events - inputs (debounced)
        var debouncedApply = debounce(applySmartFilters, 300);
        if (inputMin) inputMin.addEventListener('input', debouncedApply);
        if (inputMax) inputMax.addEventListener('input', debouncedApply);

        // Reset
        if (btnReset) {
            btnReset.addEventListener('click', function () {
                // Reset tutti i select smart
                if (selectStato) selectStato.value = '';
                if (selectBU) selectBU.value = '';
                if (selectPM) selectPM.value = '';
                if (selectScadenza) selectScadenza.value = 'tutte';
                if (inputMin) inputMin.value = '';
                if (inputMax) inputMax.value = '';

                // Rimuovi data-smart-hidden da tutte le righe
                var tbody = table.querySelector('tbody');
                if (tbody) {
                    var rows = tbody.querySelectorAll('tr[data-smart-hidden]');
                    for (var i = 0; i < rows.length; i++) {
                        rows[i].removeAttribute('data-smart-hidden');
                    }
                }

                // Reset filtri globali initTableFilters (filter-row inputs)
                resetGlobalFilters(table);

                refreshPagination(table);
            });
        }
    }

    /**
     * Controlla se la riga passa il filtro scadenza.
     */
    function checkScadenza(scadStr, preset, mode, todayStr) {
        if (preset === 'tutte') return true;

        var hasDate = scadStr !== '';

        if (mode === 'elenco') {
            switch (preset) {
                case 'senza':
                    return !hasDate;
                case 'scadute':
                    return hasDate && scadStr < todayStr;
                case 'entro7':
                    if (!hasDate) return false;
                    return scadStr >= todayStr && scadStr <= addDays(todayStr, 7);
                case 'entro30':
                    if (!hasDate) return false;
                    return scadStr >= todayStr && scadStr <= addDays(todayStr, 30);
                default:
                    return true;
            }
        } else {
            switch (preset) {
                case 'senza':
                    return !hasDate;
                case 'ultimo30':
                    if (!hasDate) return false;
                    return scadStr >= addDays(todayStr, -30);
                case 'ultimo90':
                    if (!hasDate) return false;
                    return scadStr >= addDays(todayStr, -90);
                default:
                    return true;
            }
        }
    }

    /**
     * Controlla se la riga passa il filtro valore min/max.
     */
    function checkValore(valStr, minVal, maxVal) {
        if (minVal === null && maxVal === null) return true;

        var hasVal = valStr !== '';
        var val = hasVal ? parseFloat(valStr) : 0;

        if (minVal !== null && !hasVal) return false;
        if (minVal !== null && val < minVal) return false;
        if (maxVal !== null && val > maxVal) return false;

        return true;
    }

    /**
     * Aggiorna la paginazione dopo il filtraggio smart.
     */
    function refreshPagination(table) {
        if (table._paginationUpdateView) {
            if (table._paginationState) {
                table._paginationState.currentPage = 1;
            }
            table._paginationUpdateView();
        } else {
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var rows = tbody.querySelectorAll('tr');
            for (var r = 0; r < rows.length; r++) {
                rows[r].style.display = rows[r].hasAttribute('data-smart-hidden') ? 'none' : '';
            }
        }
    }

    /**
     * Reset dei filtri globali generati da initTableFilters.
     */
    function resetGlobalFilters(table) {
        var filterRow = table.querySelector('thead tr.filter-row');
        if (!filterRow) return;

        var inputs = filterRow.querySelectorAll('input.table-col-search');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = '';
            inputs[i].dispatchEvent(new Event('input', { bubbles: true }));
        }

        var icons = table.querySelectorAll('.filter-icon.filter-active');
        for (var j = 0; j < icons.length; j++) {
            icons[j].classList.remove('filter-active');
        }
    }

    // --- Utility ---

    function getTodayString() {
        var d = new Date();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    function addDays(dateStr, days) {
        var parts = dateStr.split('-');
        var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        d.setDate(d.getDate() + days);
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, delay);
        };
    }

})();
