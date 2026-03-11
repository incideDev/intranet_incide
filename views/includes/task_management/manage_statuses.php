<?php
// Usa il database globale del progetto
if (!isset($database) || !isset($database->connection)) {
    die("Database non disponibile");
}
$pdo = $database->connection;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_status') {
        // Aggiungi logica per creare un nuovo stato
        $status_name = $_POST['status_name'];
        
        // Query per inserire un nuovo stato nel database `task_statuses`
        $stmt = $pdo->prepare("INSERT INTO task_statuses (name) VALUES (:status_name)");
        $stmt->bindParam(':status_name', $status_name);
        $stmt->execute();

        // Reindirizza o gestisci la risposta
        header('Location: manage_statuses.php');
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create_board') {
        // Aggiungi logica per creare una nuova bacheca
        $boardName = $_POST['boardName'];
        $selectedStatuses = $_POST['selectedStatuses'];
        
        // Inserimento della bacheca e relazione con gli stati
        $stmt = $pdo->prepare("INSERT INTO boards (board_name) VALUES (:board_name)");
        $stmt->bindParam(':board_name', $boardName);
        $stmt->execute();
        $boardId = $pdo->lastInsertId();

        foreach ($selectedStatuses as $statusId) {
            $stmt = $pdo->prepare("INSERT INTO status_to_board (board_id, status_id) VALUES (:board_id, :status_id)");
            $stmt->bindParam(':board_id', $boardId);
            $stmt->bindParam(':status_id', $statusId);
            $stmt->execute();
        }

        header('Location: manage_statuses.php');
        exit;
    }
}

// Carica tutti gli stati esistenti dalla tabella `task_statuses` per visualizzarli
$stmt = $pdo->prepare("SELECT * FROM task_statuses");
$stmt->execute();
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<form action="manage_statuses.php" method="POST">
    <input type="hidden" name="action" value="create_status">
    <label for="status_name">Nuovo Stato:</label>
    <input type="text" id="status_name" name="status_name" required>
    <button type="submit" class="button">Aggiungi Stato</button>
</form>

<!-- Lista degli stati esistenti -->
<h2>Stati Esistenti</h2>
<ul>
    <?php foreach ($statuses as $status): ?>
        <li><?php echo htmlspecialchars($status['name']); ?></li>
    <?php endforeach; ?>
</ul>
