/**
 * Gestione calcoli e riepilogo per la scheda Fatturato
 * Calcola somme ultimi N anni e migliori progetti
 */

(function() {
    'use strict';
    
    // Evita doppia inizializzazione
    if (window.__requisitiFatturatoInitDone) return;
    window.__requisitiFatturatoInitDone = true;
    
    let fatturatoData = [];
    let observer = null;
    
    /**
     * Parsa un importo formattato in italiano (es. "€ 1.234.567,89") e lo converte in numero
     */
    function parseFatturatoValue(formattedValue) {
        if (!formattedValue || typeof formattedValue !== 'string') return 0;
        
        // Rimuovi simbolo € e spazi
        let cleaned = formattedValue.replace(/€/g, '').trim();
        
        // Rimuovi punti (separatori migliaia) e sostituisci virgola (decimale) con punto
        cleaned = cleaned.replace(/\./g, '').replace(',', '.');
        
        const num = parseFloat(cleaned);
        return isNaN(num) ? 0 : num;
    }
    
    /**
     * Formatta un numero come valuta EUR in formato italiano
     */
    function formatCurrencyEUR(value) {
        if (typeof value !== 'number' || isNaN(value)) return '€ 0,00';
        
        return new Intl.NumberFormat('it-IT', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }
    
    /**
     * Legge la tabella fatturato e restituisce un array di oggetti { year: number, amount: number }
     */
    function parseFatturatoTable() {
        const tbody = document.querySelector('#fatturatoTable tbody');
        if (!tbody) return [];
        
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const data = [];
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 2) {
                const yearText = cells[0].textContent.trim();
                const fatturatoText = cells[1].textContent.trim();
                
                const year = parseInt(yearText, 10);
                const amount = parseFatturatoValue(fatturatoText);
                
                if (!isNaN(year) && amount > 0) {
                    data.push({ year, amount });
                }
            }
        });
        
        // Ordina per anno decrescente (più recente prima)
        data.sort((a, b) => b.year - a.year);
        
        return data;
    }
    
    /**
     * Calcola la somma degli ultimi N anni
     */
    function computeSumLastNYears(data, n) {
        if (!Array.isArray(data) || data.length === 0) return 0;
        
        // Prendi i primi N anni (già ordinati per anno decrescente)
        const lastN = data.slice(0, n);
        
        return lastN.reduce((sum, item) => sum + item.amount, 0);
    }
    
    /**
     * Calcola la somma dei migliori N progetti (per fatturato) negli ultimi M anni
     * Restituisce { total: number, years: number[] }
     */
    function computeBestProjects(data, topN, yearsWindow) {
        if (!Array.isArray(data) || data.length === 0) {
            return { total: 0, years: [] };
        }
        
        // Prendi gli ultimi M anni
        const lastMYears = data.slice(0, yearsWindow);
        
        if (lastMYears.length === 0) {
            return { total: 0, years: [] };
        }
        
        // Ordina per fatturato decrescente
        const sorted = [...lastMYears].sort((a, b) => b.amount - a.amount);
        
        // Prendi i primi N (limitando a quelli disponibili)
        const bestN = sorted.slice(0, Math.min(topN, sorted.length));
        
        const total = bestN.reduce((sum, item) => sum + item.amount, 0);
        const years = bestN.map(item => item.year).sort((a, b) => b - a);
        
        return { total, years };
    }
    
    /**
     * Aggiorna tutti i valori del pannello riepilogo
     */
    function updateSummaryPanel() {
        fatturatoData = parseFatturatoTable();
        
        // Aggiorna le tre righe "ultimi N anni"
        const sum10 = computeSumLastNYears(fatturatoData, 10);
        const sum5 = computeSumLastNYears(fatturatoData, 5);
        const sum3 = computeSumLastNYears(fatturatoData, 3);
        
        const elSum10 = document.getElementById('sum-last-10');
        const elSum5 = document.getElementById('sum-last-5');
        const elSum3 = document.getElementById('sum-last-3');
        
        if (elSum10) elSum10.textContent = formatCurrencyEUR(sum10);
        if (elSum5) elSum5.textContent = formatCurrencyEUR(sum5);
        if (elSum3) elSum3.textContent = formatCurrencyEUR(sum3);
        
        // Aggiorna "Fatturato migliori"
        updateBestProjects();
    }
    
    /**
     * Aggiorna solo la sezione "Fatturato migliori"
     */
    function updateBestProjects() {
        const inputUltimiAnni = document.getElementById('best-progetti'); // Input "ULTIMI ANNI"
        const selectMiglioriAnni = document.getElementById('best-anni'); // Select "MIGLIORI ANNI"
        const elResult = document.getElementById('best-result-text');
        const elYears = document.getElementById('best-result-years');
        
        if (!inputUltimiAnni || !selectMiglioriAnni || !elResult) return;
        
        // MIGLIORI ANNI (select) = quanti anni migliori prendere (topN)
        // ULTIMI ANNI (input) = finestra di anni su cui cercare (yearsWindow)
        const topN = parseInt(selectMiglioriAnni.value, 10) || 3;
        const yearsWindow = parseInt(inputUltimiAnni.value, 10) || 3;
        
        const result = computeBestProjects(fatturatoData, topN, yearsWindow);
        
        // Testo principale: "Totale migliori X anni su ultimi Y: € ..."
        elResult.textContent = `Totale migliori ${topN} ${topN === 1 ? 'anno' : 'anni'} su ultimi ${yearsWindow}: ${formatCurrencyEUR(result.total)}`;
        
        // Anni inclusi
        if (elYears) {
            if (result.years.length > 0) {
                elYears.textContent = `Anni: ${result.years.join(', ')}`;
            } else {
                elYears.textContent = '';
            }
        }
    }
    
    /**
     * Inizializza il modulo
     */
    function init() {
        const tbody = document.querySelector('#fatturatoTable tbody');
        
        if (tbody) {
            // Se la tabella ha già dati, calcola subito
            if (tbody.children.length > 0) {
                updateSummaryPanel();
            }
            
            // Osserva cambiamenti nella tabella (utile per aggiornamenti AJAX)
            if (typeof MutationObserver !== 'undefined') {
                observer = new MutationObserver((mutations) => {
                    // Ricalcola solo se ci sono state modifiche significative
                    let shouldUpdate = false;
                    mutations.forEach(mutation => {
                        if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                            shouldUpdate = true;
                        } else if (mutation.type === 'characterData' || 
                                   (mutation.type === 'childList' && mutation.removedNodes.length > 0)) {
                            shouldUpdate = true;
                        }
                    });
                    
                    if (shouldUpdate) {
                        // Debounce per evitare troppi ricalcoli
                        clearTimeout(window.__fatturatoUpdateTimer);
                        window.__fatturatoUpdateTimer = setTimeout(updateSummaryPanel, 150);
                    }
                });
                
                observer.observe(tbody, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });
            }
        }
        
        // Event listeners per input "Fatturato migliori"
        const inputUltimiAnni = document.getElementById('best-progetti'); // Input "ULTIMI ANNI"
        const selectMiglioriAnni = document.getElementById('best-anni'); // Select "MIGLIORI ANNI"
        
        // ULTIMI ANNI (input) controlla la finestra di anni su cui cercare
        if (inputUltimiAnni) {
            inputUltimiAnni.addEventListener('input', updateBestProjects);
            inputUltimiAnni.addEventListener('change', updateBestProjects);
        }
        
        // MIGLIORI ANNI (select) controlla quanti anni migliori prendere
        if (selectMiglioriAnni) {
            selectMiglioriAnni.addEventListener('change', updateBestProjects);
        }
        
        // Ricalcola quando la scheda fatturato diventa visibile
        const tabFatturato = document.getElementById('tab-fatturato');
        if (tabFatturato) {
            // Usa IntersectionObserver per rilevare quando la scheda diventa visibile
            if (typeof IntersectionObserver !== 'undefined') {
                const visibilityObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            // La scheda è visibile, ricalcola
                            setTimeout(updateSummaryPanel, 100);
                        }
                    });
                }, { threshold: 0.1 });
                
                visibilityObserver.observe(tabFatturato);
            }
        }
    }
    
    // Inizializza quando il DOM è pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Espone funzione per ricalcolo manuale (utile se la tabella viene aggiornata via AJAX)
    window.updateFatturatoSummary = updateSummaryPanel;
    
})();

