<?php
if (!defined('accessofileinterni')) define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare')) return;

$id_gara = $_GET['id_gara'] ?? null;
?>

<div class="main-container">
    <div class="anagrafiche-container">
        <!-- Titolo Dinamico per il Numero Gara -->
        <div class="gara-header">
            <h2 id="n_gara_titolo">Numero Gara</h2>
            <img src="assets/icons/edit.png" id="editIcon" alt="Modifica" class="icon-btn">
        </div>
        <hr class="gara-separator">

<div class="header-gara">
    <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr;">
        <div class="form-group full-width">
            <label for="oggetto_appalto">Oggetto dell'Appalto</label>
            <textarea id="oggetto_appalto" class="editable-field" readonly></textarea>
        </div>
        <div class="form-group">
            <label for="tipologia_gara">Tipologia Gara</label>
            <select id="tipologia_gara" class="editable-field dropdown-input" disabled>
                <option value="">Seleziona una tipologia</option>
                <option value="Incarico diretto">Incarico diretto</option>
                <option value="Gara sotto soglia">Gara sotto soglia</option>
                <option value="Gara Europea">Gara Europea</option>
                <option value="Appalto integrato">Appalto integrato</option>
                <option value="Concorso di progettazione">Concorso di progettazione</option>
            </select>
        </div>
        <div class="form-group">
            <label for="settore">Settore</label>
            <select id="settore" class="editable-field dropdown-input" disabled>
                <option value="">Seleziona</option>
            </select>
        </div>
        <div class="form-group">
            <label for="tipologia_appalto">Tipologia Appalto</label>
            <select id="tipologia_appalto" class="editable-field" disabled>
                <option value="">Seleziona una tipologia</option>
            </select>
        </div>
        <div class="form-group">
            <label for="stazione_appaltante">Stazione Appaltante</label>
            <input type="text" id="stazione_appaltante" class="editable-field" oninput="autocompleteAzienda()">
            <div class="autocomplete-list" id="autocomplete-stazione_appaltante"></div>
        </div>
        <div class="form-group">
            <label for="data_uscita_gara">Data Uscita Gara</label>
            <input type="date" id="data_uscita_gara" class="editable-field" readonly>
        </div>
        <div class="form-group">
            <label for="scadenza">Scadenza</label>
            <input type="date" id="scadenza" class="editable-field" readonly>
        </div>
        <div class="form-group">
            <label for="luogo">Luogo (Provincia)</label>
            <input type="text" id="luogo" class="editable-field" oninput="autocompleteProvincia()">
            <div class="autocomplete-list" id="autocomplete-luogo"></div>
        </div>
        <div class="form-group">
            <label for="sopralluogo">Sopralluogo Obbligatorio</label>
            <select id="sopralluogo" class="editable-field" disabled>
                <option value="Sì">Sì</option>
                <option value="No">No</option>
            </select>
        </div>
        <div class="form-group">
            <label for="link_portale">Link Portale S.A.</label>
            <textarea id="link_portale" class="editable-field" readonly></textarea>
        </div>
        <div class="form-group" style="position:relative;">
            <label for="gv-commessa">Commessa collegata</label>
            <input type="text" id="gv-commessa" class="editable-field" placeholder="Cerca codice commessa..." autocomplete="off" readonly>
            <div id="gv-commessa-suggestions" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5da;border-radius:0 0 6px 6px;z-index:200;max-height:220px;overflow-y:auto;display:none;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
        </div>
    </div>
</div>
<script>
(function() {
    var commessaInput = document.getElementById('gv-commessa');
    var suggestionsBox = document.getElementById('gv-commessa-suggestions');
    if (!commessaInput) return;

    // Pre-popola dal dato globale quando disponibile
    function tryPrefill() {
        if (window._garaDettaglioData && window._garaDettaglioData.codice_commessa) {
            commessaInput.value = window._garaDettaglioData.codice_commessa;
        }
    }
    tryPrefill();
    document.addEventListener('garaDataLoaded', tryPrefill);

    var debTimer;
    function debounce(fn, ms) { return function() { var a = arguments; clearTimeout(debTimer); debTimer = setTimeout(function() { fn.apply(null, a); }, ms); }; }

    function showSuggestions(items) {
        suggestionsBox.innerHTML = '';
        if (!items.length) { suggestionsBox.style.display = 'none'; return; }
        items.forEach(function(item) {
            var div = document.createElement('div');
            div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;';
            div.textContent = item.label || item.value;
            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                commessaInput.value = item.value;
                suggestionsBox.style.display = 'none';
                saveCommessa(item.value);
            });
            div.addEventListener('mouseenter', function() { div.style.background = '#f0f4ff'; });
            div.addEventListener('mouseleave', function() { div.style.background = ''; });
            suggestionsBox.appendChild(div);
        });
        suggestionsBox.style.display = '';
    }

    function saveCommessa(codice) {
        var jobId = window._garaDettaglioData && window._garaDettaglioData.job_id;
        if (!jobId) return;
        customFetch('gare', 'updateGaraField', { job_id: jobId, field: 'codice_commessa', value: codice })
            .then(function(res) {
                if (!res || !res.success) console.warn('Errore salvataggio commessa:', res);
            });
    }

    var doSearch = debounce(function(q) {
        if (q.length < 2) { suggestionsBox.style.display = 'none'; return; }
        customFetch('gare', 'searchCommesse', { q: q }).then(function(res) {
            showSuggestions((res && res.success && Array.isArray(res.data)) ? res.data : []);
        });
    }, 300);

    commessaInput.addEventListener('input', function() { doSearch(this.value); });
    commessaInput.addEventListener('blur', function() { setTimeout(function() { suggestionsBox.style.display = 'none'; }, 150); });

    // Abilita editing quando il campo è in modalità edit (segue il pattern readonly del form)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.id === 'editIcon') {
            commessaInput.removeAttribute('readonly');
        }
    });
    document.addEventListener('garaEditModeOff', function() { commessaInput.setAttribute('readonly', ''); });
})();
</script>

    </div>

    <!-- Tabs -->
    <div class="tab">
        <button class="tablinks" data-tab="DatiEconomici" onclick="openTab(event, 'DatiEconomici')">Dati Economici</button>
        <button class="tablinks" data-tab="DatiTecnici" onclick="openTab(event, 'DatiTecnici')">Dati Tecnici</button>
        <button class="tablinks" data-tab="Criteri" onclick="openTab(event, 'Criteri')">Criteri</button>
        <button class="tablinks" data-tab="DocumentiGara" onclick="openTab(event, 'DocumentiGara')">Documenti di Gara</button>
        <button class="tablinks" data-tab="RTP" onclick="openTab(event, 'RTP')">RTP</button>
    </div>

<div id="DatiEconomici" class="tabcontent">
    <!-- ✅ Bottone per aggiungere un nuovo record -->
    <img src="assets/icons/plus.png" alt="Aggiungi" class="icon-btn" onclick="apriModale('dati_economici')">

    <table id="dati-economici-table" class="table table-filterable dati_economici-table">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Parcella Base</th>
                <th>Importo Lavori</th>
                <th>Parcella Requisiti Progettazione</th>
                <th>Requisiti Servizi Punta</th>
            </tr>
        </thead>
        <tbody id="economici-body"></tbody>
    </table>
</div>

<div id="DatiTecnici" class="tabcontent">
    <img src="assets/icons/plus.png" alt="Aggiungi" class="icon-btn" onclick="apriModale('dati_tecnici')">
    <table id="dati-tecnici-table" class="table table-filterable dati_tecnici-table">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Importo Prestazioni</th>
                <th>Requisiti Figure Professionali</th>
                <th>Capacità Economica</th>
                <th>Requisiti Importo Servizi Tecnici</th>
                <th>Requisiti Servizi Punta</th>
            </tr>
        </thead>
        <tbody id="tecnici-body"></tbody>
    </table>
</div>

<div id="Criteri" class="tabcontent">
    <img src="assets/icons/plus.png" alt="Aggiungi" class="icon-btn" onclick="apriModale('criteri')">
    <table id="criteri-table" class="table table-filterable">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Categoria</th>
                <th>Criterio</th>
                <th>Elaborati</th>
                <th>Punteggio</th>
            </tr>
        </thead>
        <tbody id="criteri-body"></tbody>
    </table>
</div>

<div id="DocumentiGara" class="tabcontent">
    <img src="assets/icons/plus.png" alt="Aggiungi Documento" class="icon-btn" onclick="document.getElementById('fileInput').click()">
    <table id="documenti-table" class="table table-filterable documenti-table">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Nome File</th>
                <th>Tipologia</th>
                <th>Data Caricamento</th>
            </tr>
        </thead>
        <tbody id="documenti-body">
            <tr id="uploadRow" class="upload-row">
                <td colspan="4" class="dropzone">
                    Trascina qui i tuoi documenti oppure 
                    <span id="manualUploadTrigger">clicca qui</span> per caricarli manualmente.
                    <input type="file" id="fileInput" multiple hidden>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div id="RTP" class="tabcontent">
    <img src="assets/icons/plus.png" alt="Aggiungi" class="icon-btn" onclick="apriModaleRaggruppamento()">
    <table id="raggruppamento-table" class="table table-filterable raggruppamento-table">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Azienda</th>
                <th>Ruolo</th>
                <th>Quota %</th>
            </tr>
        </thead>
        <tbody id="raggruppamento-body"></tbody>
    </table>
</div>

<!-- Modale Universale -->
<div id="modalForm" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close-modal" onclick="chiudiModale()">&times;</span>
        <h3 id="modal-title">Aggiungi Nuovo</h3>
        <div id="modal-body">
            <!-- Il form verrà caricato dinamicamente -->
        </div>
        <div class="submit-modal-gare">
            <img src="assets/icons/save.png" alt="Salva" class="icon-btn" onclick="salvaDati()">
        </div>
    </div>
</div>

<script src="/assets/js/gare/gare_valutazione.js"></script>
