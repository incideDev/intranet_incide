<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accesso diretto non consentito.');
}

// Mostra breadcrumb se esiste la funzione
if (function_exists('renderBreadcrumb')) {
    echo '<div class="function-bar">';
    renderBreadcrumb();
} else {
    echo '<div class="function-bar"><!-- breadcrumb non disponibile -->';
}
?>

<div class="button-group">
    <!--Archivio -->
    <button id="archiveButton" class="btn-custom disabled">
        <img src="/assets/icons/folder.png" alt="Archivio" class="function-icon">
    </button>

    <!--Elenco -->
    <button id="listButton" class="btn-custom disabled">
        <img src="/assets/icons/list.png" alt="Elenco" class="function-icon">
    </button>

    <!--Kanban -->
    <button id="kanbanButton" class="btn-custom disabled">
        <img src="/assets/icons/kanban.png" alt="Kanban" class="function-icon">
    </button>

    <!--Calendario -->
    <button id="calendarButton" class="btn-custom disabled" data-tooltip="vista calendario">
        <img src="/assets/icons/calendar.png" alt="Calendario" class="function-icon">
    </button>

    <!--Gantt -->
    <button id="ganttButton" class="btn-custom disabled" data-tooltip="vista Gantt">
        <img src="/assets/icons/gantt.png" alt="Gantt" class="function-icon">
    </button>

    <!--Dashboard -->
    <button id="dashboardButton" class="btn-custom disabled">
        <img src="/assets/icons/dashboard.png" alt="Dashboard" class="function-icon">
    </button>

    <!--Aggiungi -->
    <button id="addButton" class="btn-custom disabled">
        <img src="/assets/icons/plus.png" alt="Aggiungi" class="function-icon">
    </button>

    <!--Indietro -->
    <button class="btn-custom" data-tooltip="Indietro" onclick="goBackCustom()">
        <img src="/assets/icons/left-arrow.png" alt="Indietro" class="function-icon">
    </button>

    <!--Avanti -->
    <button class="btn-custom" data-tooltip="Avanti" onclick="goForwardCustom()">
        <img src="/assets/icons/right-arrow.png" alt="Avanti" class="function-icon">
    </button>

    <!--Stampa/esporta -->
    <button class="btn-custom" data-tooltip="Stampa / Esporta" onclick="handleExportOrPrint()">
        <img src="/assets/icons/print.png" alt="Stampa o Esporta" class="function-icon">
    </button>



    <!--MOM -->
    <?php if (userHasPermission('view_mom') || isAdmin()): ?>
        <button id="momButton" class="btn-custom disabled" data-tooltip="Verbale riunione (MOM)">
            <img src="/assets/icons/mom.png" alt="MOM" class="function-icon">
        </button>
    <?php endif; ?>
</div>
</div>

<!--Script principale -->
<script src="/assets/js/function-bar.js"></script>

<!--Utility inline -->
<script>
    function goBackCustom() {
        const lastView = sessionStorage.getItem('lastView');
        if (lastView === 'protocolForm') {
            sessionStorage.setItem('lastView', 'archive');
            window.location.reload();
        } else {
            window.history.back();
        }
    }

    function goForwardCustom() {
        const lastView = sessionStorage.getItem('lastView');
        if (lastView === 'archive') {
            sessionStorage.setItem('lastView', 'protocolForm');
            window.location.reload();
        } else {
            window.history.forward();
        }
    }



    function printDocument() {
        // Richiama la funzione centrale globale (che gestisce anche export)
        if (typeof window.handleExportOrPrint === 'function') {
            window.handleExportOrPrint();
        } else {
            showToast("Funzione di stampa non disponibile.", "error");
        }
    }
</script>
