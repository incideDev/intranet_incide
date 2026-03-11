<h2>Aggiungi Candidato</h2>
<form id="addCandidateForm">
    <label for="name">Nome:</label>
    <input type="text" id="name" name="name" required>

    <label for="email">Email:</label>
    <input type="email" id="email" name="email" required>

    <label for="position_applied">Posizione:</label>
    <input type="text" id="position_applied" name="position_applied" required>

    <label for="campaign">Campagna:</label>
    <select id="campaign" name="campaign_id" required>
        <option value="">Seleziona una campagna</option>
        <!-- Qui verranno aggiunte le opzioni dinamiche -->
    </select>

    <button type="button" onclick="submitAddCandidateForm()">Salva</button>
</form>
