// ========== MAPPE STATI CENTRALIZZATE ==========
// Single source of truth per tutti gli stati del sistema
// Usare SEMPRE queste funzioni invece di duplicare le mappe

(function() {
    'use strict';
    
    // Definizioni centrali stati
    window.STATUS_MAPS = {
        segnalazioni: {
            1: "Aperta",
            2: "In corso",
            3: "Chiusa"
        },
        gare: {
            1: "Bozza",
            2: "Pubblicata",
            3: "In Valutazione",
            4: "Aggiudicata",
            5: "Archiviata"
        },
        tasks: {
            1: "Da Fare",
            2: "In Corso",
            3: "Completato",
            4: "Bloccato"
        }
    };
    
    // Helper: ottieni label da ID
    window.getStatusLabel = function(statusId, type = 'segnalazioni') {
        const map = window.STATUS_MAPS[type];
        if (!map) return 'Sconosciuto';
        return map[parseInt(statusId)] || 'Sconosciuto';
    };
    
    // Helper: ottieni ID da label
    window.getStatusId = function(label, type = 'segnalazioni') {
        const map = window.STATUS_MAPS[type];
        if (!map) return null;
        
        const entry = Object.entries(map).find(([k, v]) => v === label);
        return entry ? parseInt(entry[0]) : null;
    };
    
    // Helper: ottieni mappa reverse (label -> ID)
    window.getStatusMapReverse = function(type = 'segnalazioni') {
        const map = window.STATUS_MAPS[type];
        if (!map) return {};
        return Object.fromEntries(Object.entries(map).map(([k, v]) => [v, parseInt(k)]));
    };
    
    // Retrocompatibilità: esponi le mappe usate più frequentemente
    window.STATI_MAP = window.STATUS_MAPS.segnalazioni;
    window.STATI_MAP_REVERSE = window.getStatusMapReverse('segnalazioni');
    
})();

