<header class="modal-header">
    <h1 id="modal-title"><?= htmlspecialchars($task['titolo'] ?? 'Titolo non disponibile') ?></h1>
</header>

<div class="task-layout">
    <!-- Colonna sinistra -->
    <div class="task-left">
        <!-- Stato -->
        <div class="task-field">
            <span class="task-icon">
                <img src="assets/icons/status.png" alt="Stato">
            </span>
            <label for="task-status">Stato</label>
            <select id="task-status" data-task-id="<?= $task['id'] ?? '' ?>" onchange="updateTaskStatus()">
                <!-- Opzioni dinamiche caricate dal database -->
            </select>
        </div>

        <!-- Date: Da e A -->
        <div class="task-field">
            <span class="task-icon">
                <img src="assets/icons/calendar.png" alt="Date">
            </span>
            <label for="task-start-date">Data inizio</label>
            <input type="date" id="task-start-date" data-task-id="<?= $task['id'] ?? '' ?>" value="<?= $task['data_inizio'] ?? '' ?>" onchange="updateTaskField('data_inizio', this.value)">
            <label for="task-end-date">Data fine</label>
            <input type="date" id="task-end-date" data-task-id="<?= $task['id'] ?? '' ?>" value="<?= $task['data_fine'] ?? '' ?>" onchange="updateTaskField('data_fine', this.value)">
        </div>

        <!-- Tag -->
        <div class="task-field">
            <span class="task-icon">
                <img src="assets/icons/tag.png" alt="Tag">
            </span>
            <label for="task-tags">Tag</label>
            <select id="task-tags" multiple>
                <!-- Opzioni dinamiche previste in futuro -->
            </select>
        </div>
    </div>

    <!-- Colonna destra -->
    <div class="task-right">
        <!-- Responsabili -->
<div class="task-field responsabile-field">
    <label for="task-responsabili">Responsabili</label>
    <div id="task-responsabili" class="responsabili-dropdown-container">
        <div class="responsabili-icons">
            <!-- Icone dinamiche -->
        </div>
        <div class="responsabile-menu hidden">
            <!-- Dropdown dinamico -->
        </div>
    </div>
</div>


        <!-- priority -->
        <div class="task-field">
            <span class="task-icon">
                <img src="assets/icons/flag.png" alt="priority">
            </span>
            <label for="task-priority">priority</label>
            <select id="task-priority" data-task-id="<?= $task['id'] ?? '' ?>" onchange="updateTaskField('priorita', this.value)">
                <option value="bassa" <?= ($task['priorita'] ?? '') === 'bassa' ? 'selected' : '' ?>>Bassa</option>
                <option value="media" <?= ($task['priorita'] ?? '') === 'media' ? 'selected' : '' ?>>Media</option>
                <option value="alta" <?= ($task['priorita'] ?? '') === 'alta' ? 'selected' : '' ?>>Alta</option>
                <option value="critica" <?= ($task['priorita'] ?? '') === 'critica' ? 'selected' : '' ?>>Critica</option>
            </select>
        </div>
    </div>
</div>

<!-- Selettore di schede -->
<div class="tab-container">
    <div class="tab">
        <button class="tab-link active" data-tab-id="details">Dettagli</button>
        <button class="tab-link" data-tab-id="checklist">Attività secondarie</button>
        <button class="tab-link" data-tab-id="attachments">Allegati</button>
    </div>

    <div id="details" class="tab-content" style="display: block;">
        <textarea id="task-details" placeholder="Aggiungi una descrizione..."></textarea>
    </div>

    <div id="checklist" class="tab-content" style="display: none;">
        <ul id="checklist-items">
            <!-- Elementi dinamici -->
        </ul>
        <button onclick="addChecklistItem()">Aggiungi elemento</button>
    </div>

    <div id="attachments" class="tab-content" style="display: none;">
        <input type="file" id="file-upload" multiple onchange="uploadFiles()">
        <div id="file-list">
            <!-- Lista file caricati -->
        </div>
    </div>
</div>
