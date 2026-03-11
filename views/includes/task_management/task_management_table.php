<?php
$bacheca_id = $_GET['bacheca_id'] ?? null;
if (!$bacheca_id) {
    die("<p style='color: red;'>❌ ERRORE: `bacheca_id` non è stato definito.</p>");
}
?>

<table class="task-table">
    <thead>
        <tr>
            <th>Titolo</th>
            <th>Responsabile</th>
            <th>Stato</th>
            <th>Data di Scadenza</th>
            <th>
                Azioni
                <!-- Icona per creare una nuova task -->
                <button onclick="showInputForNewTask()" class="add-task-btn">
                    <img src="assets/icons/add-task.png" alt="Aggiungi Task" class="task-icon">
                </button>
            </th>
        </tr>
    </thead>
    <tbody>
        <!-- RIGA PER NUOVA TASK -->
        <tr class="new-task" id="new-task-row" style="display: none;">
            <td contenteditable="true" onfocus="this.innerText=''" onblur="if(this.innerText==='') this.innerText='Titolo...';">Titolo...</td>
            <td>
                <select>
                    <?php foreach ($personale as $person) : ?>
                        <option value="<?php echo htmlspecialchars($person['Cod_Operatore']); ?>">
                            <?php echo htmlspecialchars($person['Nominativo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td contenteditable="true">Stato...</td>
            <td><input type="date" placeholder="Data Scadenza..."></td>
            <td><button onclick="saveNewTask()">Salva Task</button></td>
        </tr>

        <?php if (!empty($tasks) && is_array($tasks)) : ?>
            <?php foreach ($tasks as $task): ?>
                <tr id="task-<?php echo htmlspecialchars($task['id']); ?>" draggable="true" onclick="openTaskDetailModal(<?php echo $task['id']; ?>)">
                    <td contenteditable="true" class="editable" data-task-id="<?php echo htmlspecialchars($task['id']); ?>" data-field="titolo">
                        <?php echo htmlspecialchars($task['titolo'] ?? 'Task senza titolo'); ?>
                    </td>
                    <td><?php echo htmlspecialchars($task['responsabile_nome'] ?? 'N/A'); ?></td>
                    <td style="color: <?php echo htmlspecialchars($task['status_color'] ?? '#000'); ?>;">
                        <?php echo htmlspecialchars($task['status_name'] ?? 'Stato non assegnato'); ?>
                    </td>
                    <td><?php echo htmlspecialchars($task['data_scadenza'] ?? 'N/A'); ?></td>
                    <td>
                        <a href="#" class="edit-task-btn" data-task-id="<?php echo htmlspecialchars($task['id']); ?>">Modifica</a> |
                        <a href="index.php?page=delete_task&id=<?php echo htmlspecialchars($task['id']); ?>">Elimina</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" class="no-task-message">Nessuna task disponibile.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<script>
document.addEventListener("DOMContentLoaded", function () {
    fetch('/api/tables/get_table_data.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error("Errore nel recupero dei dati");
            const tbody = document.querySelector(".task-table tbody");
            tbody.innerHTML = ""; 
            data.tasks.forEach(task => {
                tbody.innerHTML += `
                    <tr>
                        <td>${task.titolo}</td>
                        <td>${task.responsabile_nome || 'N/A'}</td>
                        <td>${task.status_name || 'N/D'}</td>
                        <td>${task.data_scadenza || 'N/A'}</td>
                        <td><button>Modifica</button></td>
                    </tr>
                `;
            });
        })
        .catch(error => console.error("Errore nel caricamento delle task:", error));
});
</script>
