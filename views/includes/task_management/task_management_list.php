<div class="task-list">
    <button onclick="showInputForNewTask()" class="add-task-btn">
        <img src="assets/icons/add-task.png" alt="Aggiungi Task" class="task-icon">
    </button>

    <!-- ELEMENTO NUOVA TASK PER LA LISTA -->
    <div class="task-list-item new-task" id="new-task-item" style="display: none;">
        <h3 contenteditable="true" onfocus="this.innerText=''" onblur="if(this.innerText==='') this.innerText='Titolo...';">Titolo...</h3>
        <p contenteditable="true" onfocus="this.innerText=''" onblur="if(this.innerText==='') this.innerText='Descrizione...';">Descrizione...</p>
        <p><input type="date" placeholder="Data Scadenza..."></p>
        <p>
            <select>
                <?php foreach ($personale as $person) : ?>
                    <option value="<?php echo htmlspecialchars($person['Cod_Operatore']); ?>">
                        <?php echo htmlspecialchars($person['Nominativo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <button onclick="saveNewTask()">Salva Task</button>
    </div>

    <?php if (!empty($tasks) && is_array($tasks)) : ?>
        <?php foreach ($tasks as $task): ?>
            <div class="task-list-item" id="task-<?php echo htmlspecialchars($task['id']); ?>" draggable="true" onclick="openTaskDetailModal(<?php echo $task['id']; ?>)">

                <!-- Dettagli della task esistente -->
                <h3 contenteditable="true" class="editable" data-task-id="<?php echo htmlspecialchars($task['id']); ?>" data-field="titolo">
                    <?php echo htmlspecialchars($task['titolo'] ?? 'Task senza titolo'); ?>
                </h3>
                <p><strong>Responsabile:</strong> <?php echo htmlspecialchars($task['responsabile_nome'] ?? 'N/A'); ?></p>
                <p><strong>Stato:</strong> <span style="color: <?php echo htmlspecialchars($task['status_color'] ?? '#000'); ?>;">
                    <?php echo htmlspecialchars($task['status_name'] ?? 'Stato non assegnato'); ?>
                </span></p>
                <p><strong>Scadenza:</strong> <?php echo htmlspecialchars($task['data_scadenza'] ?? 'N/A'); ?></p>
                <a href="#" class="edit-task-btn" data-task-id="<?php echo htmlspecialchars($task['id']); ?>">Modifica</a> |
                <a href="index.php?page=delete_task&id=<?php echo htmlspecialchars($task['id']); ?>">Elimina</a>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="no-task-message">Nessuna task disponibile in questa lista.</p>
    <?php endif; ?>
</div>
