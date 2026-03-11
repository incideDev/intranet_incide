<div class="main-container">
    <h2>Nuovo Stato</h2>
    <form id="createStateForm" action="index.php?page=task_management&action=create_state" method="POST">
        <label for="name">Nome Stato:</label>
        <input type="text" id="name" name="name" required>

        <label for="color">Colore Stato:</label>
        <input type="color" id="color" name="color" required>

        <input type="hidden" name="bacheca_id" value="<?= htmlspecialchars($bacheca_id); ?>">

        <button type="submit" class="button">Aggiungi Stato</button>
    </form>
</div>
