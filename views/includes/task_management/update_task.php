<form action="index.php?page=update_task&id=<?php echo $task['id']; ?>" method="POST">
    <label for="titolo">Titolo:</label>
    <input type="text" id="titolo" name="titolo" value="<?php echo htmlspecialchars($task['titolo']); ?>" required>

    <label for="descrizione">Descrizione:</label>
    <textarea id="descrizione" name="descrizione" required><?php echo htmlspecialchars($task['descrizione']); ?></textarea>

    <label for="data_scadenza">Data Scadenza:</label>
    <input type="date" id="data_scadenza" name="data_scadenza" value="<?php echo htmlspecialchars($task['data_scadenza']); ?>" required>

    <!-- Aggiungi il select box per lo status dinamico -->
    <label for="status_id">Stato:</label>
    <select id="status_id" name="status_id" required>
        <?php foreach ($statuses as $status): ?>
            <option value="<?php echo $status['id']; ?>" <?php echo $task['status_id'] == $status['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($status['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="button">Aggiorna Task</button>
</form>
