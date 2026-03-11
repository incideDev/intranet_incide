<div id="addBoardModal" class="modal">
    <div class="modal-content">
        <span class="close" id="closeBoardModal" onclick="closeModal()">&times;</span>
        <form id="newBoardForm">
            <input type="hidden" name="bacheca_id" value="<?php echo isset($_GET['board']) ? htmlspecialchars($_GET['board']) : ''; ?>">

            <div class="modal-columns">
                <div class="modal-column-left">
                    <h2>Aggiungi nuova bacheca</h2>
                    <label for="boardName">Nome della bacheca:</label>
                    <input type="text" id="boardName" name="boardName" placeholder="Nome della bacheca" required>

                    <label for="modelName">Nome del Modello:</label>
                    <input type="text" id="modelName" name="modelName" placeholder="Nome del modello">

                    <label for="modelSelect">Modello di stato:</label>
                    <select id="modelSelect" name="model">
                        <option value="">Custom</option>
                        <?php if (isset($availableStatuses) && is_array($availableStatuses) && count($availableStatuses) > 0): ?>
                            <?php foreach ($availableStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status['id']); ?>"><?= htmlspecialchars($status['name']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <?php if (empty($availableStatuses)): ?>
                        <p>Nessuno stato disponibile.</p>
                    <?php endif; ?>

                    <label for="statusSelect">Seleziona Stato:</label>
                    <select id="statusSelect" onchange="addStateToList()">
                        <option value="">Seleziona Stato</option>
                        <option value="1">Da Fare</option>
                        <option value="2">In Corso</option>
                        <option value="3">Completato</option>
                        <?php if (isset($availableStatuses) && is_array($availableStatuses) && count($availableStatuses) > 0): ?>
                            <?php foreach ($availableStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status['id']); ?>"><?= htmlspecialchars($status['name']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <!-- Lista degli stati selezionati -->
                    <h3>Stati selezionati</h3>
                    <ul id="statusList"></ul>
                </div>

                <div class="modal-column-right">
                    <div class="statuses">
                        <div class="status-category">
                            <h3>Not started</h3>
                            <ul id="notStartedStates" class="status-list">
                                <li><button onclick="addState('notStarted', event)">+</button></li>
                            </ul>
                        </div>
                        <div class="status-category">
                            <h3>Active</h3>
                            <ul id="activeStates" class="status-list">
                                <li><button onclick="addState('active', event)">+</button></li>
                            </ul>
                        </div>
                        <div class="status-category">
                            <h3>Done</h3>
                            <ul id="doneStates" class="status-list">
                                <li><button onclick="addState('done', event)">+</button></li>
                            </ul>
                        </div>
                        <div class="status-category">
                            <h3>Closed</h3>
                            <ul id="closedStates" class="status-list">
                                <li><button onclick="addState('closed', event)">+</button></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="button">Crea Bacheca</button>
        </form>
    </div>
</div>

<script>
    function submitNewBoardForm(event) {
        event.preventDefault();
        
        const formData = new FormData(document.getElementById('newBoardForm'));
        
        fetch('index.php?page=task_management&action=addBoard', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Bacheca creata con successo!', 'success');
                document.getElementById('addBoardModal').style.display = 'none';
                loadBoards(); // Funzione per aggiornare la lista delle bacheche
            } else {
                showToast('Errore nella creazione della bacheca: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showToast('Errore durante la richiesta: ' + error, 'error');
            console.error('Errore:', error);
        });
    }
</script>
