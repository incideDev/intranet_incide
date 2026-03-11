<?php
$view = $_GET['view'] ?? 'kanban'; // Imposta la vista predefinita
?>

<?php include 'views/includes/kanban/kanban_scripts.php'; ?>

<div class="main-container" data-bacheca-id="<?= htmlspecialchars($_GET['bacheca_id'] ?? ''); ?>">
    <div class="view-selector">
        <div class="view-tabs">
            <div class="view-tab <?= $view === 'kanban' ? 'active' : ''; ?>" data-view="kanban">
                <img src="assets/icons/kanban.png" class="view-icon" alt="Kanban">
                Kanban
            </div>
            <div class="view-tab <?= $view === 'table' ? 'active' : ''; ?>" data-view="table">
                <img src="assets/icons/table.png" class="view-icon" alt="Tabella">
                Tabella
            </div>
            <div class="view-tab <?= $view === 'calendar' ? 'active' : ''; ?>" data-view="calendar">
                <img src="assets/icons/calendar.png" class="view-icon" alt="Calendario">
                Calendario
            </div>
        </div>

        <div class="action-icons">
            <img src="assets/icons/kanban.png" alt="Nuovo Stato" class="icon-btn" onclick="addNewKanbanColumn()">
            <a href="index.php?page=archived_states">
                <img src="assets/icons/archived.png" alt="Archiviati" class="icon-btn">
            </a>
        </div>
    </div>

    <div id="view-container">
        <?php
        switch ($view) {
            case 'table':
                include 'views/includes/task_management/task_management_table.php';
                break;
            case 'calendar':
                include 'views/includes/task_management/task_management_calendar.php';
                break;
            default:
                include 'views/includes/task_management/task_management_kanban.php';
                break;
        }
        ?>
    </div>
</div>

<div id="modalDetail" class="modal" style="display: none;">
    <div class="modal-content-expanded">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <div id="modal-dynamic-content"></div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const viewTabs = document.querySelectorAll('.view-tab');
    const viewContainer = document.getElementById('view-container');
    const bachecaId = document.querySelector('.main-container')?.dataset.bachecaId || '';

    function loadView(view) {
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('view', view);
        if (bachecaId) {
            newUrl.searchParams.set('bacheca_id', bachecaId);
        }
        window.history.pushState({ path: newUrl.href }, '', newUrl.href);

        viewTabs.forEach(t => t.classList.remove('active'));
        document.querySelector(`.view-tab[data-view="${view}"]`)?.classList.add('active');

        fetch(`views/includes/task_management/task_management_${view}.php?bacheca_id=${bachecaId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                viewContainer.innerHTML = html;
                if (view === "calendar") {
                    loadCalendarScripts();
                }
            })
            .catch(error => console.error("Errore nel caricamento della vista:", error));
    }

    viewTabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const selectedView = this.dataset.view;
            loadView(selectedView);
        });
    });

    window.addEventListener("popstate", function () {
        const urlParams = new URLSearchParams(window.location.search);
        const view = urlParams.get("view") || "kanban";
        loadView(view);
    });

    if ("<?= $view ?>" === "calendar") {
        loadCalendarScripts();
    }

    function loadCalendarScripts() {
        const script = document.createElement("script");
        script.src = "assets/js/calendar.js";
        document.body.appendChild(script);
    }
});
</script>
