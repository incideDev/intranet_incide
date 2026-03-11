<div id="createFormModal" class="modal custom-modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3 id="modal-title">Crea Modulo</h3>
        <div id="modal-header-line"></div>

        <form id="createForm" method="POST">
            <div class="modal-container">
            <div id="color-preview" style="height: 4px; background-color: #CCCCCC; margin-bottom: 10px;"></div>
                <!-- SEZIONE SINISTRA -->
                <div class="modal-left">
                    <div class="form-info">
                        <label for="form_name">Nome della Segnalazione:</label>
                        <input type="text" id="form_name" name="form_name" required>

                        <label for="description">Descrizione:</label>
                        <textarea id="description" name="description" required></textarea>

                        <label for="responsabile">Responsabile:</label>
                        <select id="responsabile" name="responsabile" required>
                            <option value="">-- Seleziona un Responsabile --</option>
                        </select>

                        <label for="assegnato_a">Assegnato a:</label>
                        <select id="assegnato_a" name="assegnato_a" disabled>
                            <option value="">-- Seleziona un Assegnatario --</option>
                        </select>

                        <label for="color">Colore della Segnalazione:</label>
                        <input type="color" id="color" name="color" value="#CCCCCC" required>

                    </div>

                    <hr class="section-divider" id="color-divider">

                    <div class="form-fields">
                        <h2>Campi della Segnalazione</h2>
                        <div id="form-fields-container"></div>

                        <!-- ARGOMENTAZIONE -->
                        <div class="form-group static-field">
                            <label for="argomentazione">Titolo della segnalazione (compilata dall'utente):</label>
                            <textarea id="argomentazione" name="argomentazione" placeholder="L’utente la compilerà nella segnalazione" disabled></textarea>
                        </div>

                        <div class="form-group static-field">
                            <label for="descrizione_dettagliata">Descrizione dettagliata (compilata dall'utente):</label>
                            <textarea id="descrizione_dettagliata" name="descrizione_dettagliata" placeholder="L’utente la compilerà nella segnalazione" disabled></textarea>
                        </div>

                        <!-- CAMPO `DEADLINE` SEMPRE PRESENTE E NON MODIFICABILE -->
                        <div class="form-group static-field">
                            <label for="deadline">Data di Scadenza (compilata dall'utente):</label>
                            <input type="date" id="deadline" name="deadline" disabled>
                        </div>

                        <div class="form-group static-field">
                            <label for="priority">Priorità (compilata dall'utente):</label>
                            <select id="priority" name="priority" disabled>
                                <option value="Bassa">Bassa</option>
                                <option value="Media" selected>Media</option>
                                <option value="Alta">Alta</option>
                            </select>
                        </div>

                        <div class="sticky-add-field">
                            <button type="button" class="button" id="addFieldBtn">+ Aggiungi Campo</button>
                        </div>
                    </div>
                </div>

                <!-- SEZIONE DESTRA (ANTEPRIMA FORM) -->
                <div class="modal-right">
                    <div id="form-preview-container">
                        <!-- L'anteprima del form si popolerà qui -->
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="button">Crea Segnalazione</button>
            </div>
        </form>
    </div>
</div>
