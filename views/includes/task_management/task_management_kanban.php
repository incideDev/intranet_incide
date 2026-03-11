<?php
function hexToRGBA($hex, $alpha = 1.0) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b, $alpha";
}

function getProfileImagePath($responsabileNome) {
    if (empty($responsabileNome)) {
        return "assets/images/default_profile.png";
    }

    $formattedName = strtolower(str_replace(' ', '_', $responsabileNome));
    $profileImagePath = "assets/images/profile_pictures/{$formattedName}.jpg";

    if (file_exists("C:/xampp/htdocs/" . $profileImagePath)) {
        return $profileImagePath;
    }

    return "assets/images/default_profile.png";
    }

    if (!isset($bacheca_id) || empty($bacheca_id)) {
        die("❌ ERRORE: `bacheca_id` non è stato definito. Assicurati che venga passato alla vista.");
    }

?>

<div class="kanban-container" data-bacheca-id="<?php echo htmlspecialchars($bacheca_id ?? ''); ?>">

    <?php if (isset($statuses) && is_array($statuses)) : ?>

        <?php
        if (empty($statuses)) {
            if (!isset($bacheca_id)) {
                die("Errore: `bacheca_id` non definito. Verifica il contesto di inclusione.");
            }

            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';

            // ✅ Recuperiamo SOLO gli stati associati alla bacheca corrente
            $stmt = $pdo->prepare("SELECT ts.* FROM task_statuses ts
                                   JOIN status_to_board stb ON ts.id = stb.status_id
                                   WHERE stb.bacheca_id = :bacheca_id
                                   ORDER BY ts.id ASC");
            $stmt->execute(['bacheca_id' => $bacheca_id]);
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        ?>
        
        <?php foreach ($statuses as $status): ?>
            <div class="kanban-column" 
                 data-status-id="<?php echo $status['id']; ?>" 
                 style="--column-color: rgba(<?php echo hexToRGBA($status['color'], 0.1); ?>); 
                        --header-color: <?php echo htmlspecialchars($status['color']); ?>;">
                <div class="kanban-header">
                    <span><?php echo htmlspecialchars($status['name']); ?></span>
                    <input type="color" 
                           id="color-picker-<?php echo $status['id']; ?>" 
                           class="color-picker"
                           value="<?php echo htmlspecialchars($status['color']); ?>"
                           onchange="updateColumnColor(<?php echo $status['id']; ?>, this.value)">
                    <button class="add-task-btn" onclick="showInputForNewTask(<?php echo $status['id']; ?>)">
                        <img src="assets/icons/add-task.png" alt="Aggiungi Task" class="task-icon">
                    </button>
                </div>
                <div class="task-container">
                    <?php
                    $tasks_for_status = array_filter($tasks ?? [], function ($task) use ($status) {
                        return $task['status_id'] == $status['id'];
                    });
                    ?>

                    <?php foreach ($tasks_for_status as $task): ?>
                        <div class="task" id="task-<?php echo $task['id']; ?>" draggable="true" data-responsabili='<?php echo json_encode($task['responsabili_info']); ?>'>

                            <!-- Titolo -->
                            <div class="editable-field title-field" 
                                 contenteditable="true" 
                                 data-task-id="<?php echo $task['id']; ?>" 
                                 data-field="titolo" 
                                 onblur="saveTaskField(this)">
                                <?php echo htmlspecialchars($task['titolo']); ?>
                            </div>

                            <!-- Descrizione -->
                            <div class="editable-field description-field" 
                                 contenteditable="true" 
                                 data-task-id="<?php echo $task['id']; ?>" 
                                 data-field="descrizione" 
                                 onblur="saveTaskField(this)">
                                <?php echo htmlspecialchars($task['descrizione']); ?>
                            </div>

                            <!-- Data di Scadenza -->
                            <div class="editable-field date-field">
                                <img src="assets/icons/calendar.png" alt="Calendario" class="icon">
                                <input type="date" 
                                       data-task-id="<?php echo $task['id']; ?>" 
                                       data-field="data_scadenza" 
                                       value="<?php echo htmlspecialchars($task['data_scadenza']); ?>" 
                                       onchange="saveTaskField(this)">
                            </div>

                            <!-- Responsabile con Modifica Inline -->
                            <div class="task-field responsabile-field">
                                <?php
                            $responsabili = !empty($task['responsabile_nominativo']) ? explode(',', $task['responsabile_nominativo']) : [];
                            ?>
                            <div class="responsabili-icons" onclick="toggleResponsabileMenu(this)">
                                <?php 
                                $responsabili = !empty($task['responsabili_info']) ? explode(',', $task['responsabili_info']) : [];
                                foreach ($responsabili as $responsabileData): 
                                    list($nominativo, $codOperatore, $imagePath) = explode('|', $responsabileData);
                                    $imagePath = !empty($imagePath) && file_exists("C:/xampp/htdocs/" . $imagePath) 
                                    ? $imagePath 
                                    : 'assets/images/default_profile.png';
                                ?>
                                    <img src="<?php echo htmlspecialchars($imagePath); ?>" 
                                         alt="<?php echo htmlspecialchars($nominativo); ?>" 
                                         class="profile-icon">
                                <?php endforeach; ?>
                                <span class="add-icon">+</span>
                            </div>

                                <div class="responsabile-menu hidden" data-task-id="<?php echo $task['id']; ?>">
                                    <div class="responsabile-menu-header">
                                        <input type="text" class="responsabile-search" placeholder="Cerca responsabile..." oninput="filterResponsabili(event)">
                                    </div>

                                    <?php foreach ($personale as $person): ?>
                                        <?php $imagePath = getProfileImagePath($person['Nominativo']); ?>
                                        <div class="responsabile-option"
                                             data-id="<?php echo htmlspecialchars($person['Cod_Operatore']); ?>"
                                             data-task-id="<?php echo $task['id']; ?>"
                                             onclick="toggleResponsabile(this, <?php echo $task['id']; ?>)">
                                            <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($person['Nominativo']); ?>" class="profile-icon">
                                            <span><?php echo htmlspecialchars($person['Nominativo']); ?></span>
                                            <span class="selection-indicator hidden">&#10004;</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>

                    <?php 
                    // Includiamo il form di creazione della nuova task per ogni colonna
                    include __DIR__ . '/create_task.php'; 
                    ?>

                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


