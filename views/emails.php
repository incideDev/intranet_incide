<div class="main-container">

    <!-- FORM NUOVO PROTOCOLLO -->
    <div class="top-container" id="protocollo-form">
        <h3>Protocollo</h3>
        <hr>

        <div class="row half-width">
            <div>
                <label for="nuova-tipologia" class="text-label label-above">Tipologia:</label>
                <select id="nuova-tipologia" name="tipologia" class="select-box" onchange="handleTipologiaChange()">
                    <option value="">Seleziona tipologia</option>
                    <option value="email">Email</option>
                    <option value="lettera">Lettera</option>
                </select>
            </div>
            <div>
                <label for="area" class="text-label label-above">Area:</label>
                <select id="area" name="area" class="select-box" onchange="toggleCategory(this.value)">
                    <option value="" selected>Seleziona area</option>
                    <option value="commessa">Commessa</option>
                    <option value="generale">Generale</option>
                </select>
            </div>
        </div>

        <div class="row two-columns">
            <div>
                <label for="project" class="text-label label-above">Codice:</label>
                <select id="project" name="commessa" class="select-box" onchange="generateProtocolCode(); updateDescription();">
                    <option value="">Seleziona un codice</option>
                    <?php foreach ($commesse as $commessa): ?>
                        <option value="<?php echo htmlspecialchars($commessa['Codice_commessa'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-descrizione="<?php echo htmlspecialchars(strip_tags($commessa['Descrizione'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($commessa['Codice_commessa'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($generale as $gen): ?>
                        <option value="<?php echo htmlspecialchars($gen['codice_generale'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-descrizione="<?php echo htmlspecialchars(strip_tags($gen['tipologia'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($gen['codice_generale'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="descrizione" class="text-label label-above">Descrizione:</label>
                <input type="text" id="descrizione" name="descrizione" class="select-box readonly" readonly>
            </div>
        </div>

<div class="row half-width">
    <div>
        <label for="recipient" class="text-label label-above">Destinatario:</label>
        <select id="recipient" name="ditta" class="select-box" onchange="updateContatti()">
            <option value="">Seleziona destinatario</option>
            <?php foreach ($destinatari as $destinatario): ?>
                <option value="<?php echo htmlspecialchars($destinatario['Azienda']); ?>">
                    <?php echo htmlspecialchars($destinatario['Azienda']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="nome_referente" class="text-label label-above">Nome Referente:</label>
        <input type="text" id="nome_referente" name="nome_referente" class="select-box">
    </div>
    <div>
        <label for="contatto" class="text-label label-above">Contatto:</label>
        <select id="contatto" name="contatto_referente" class="select-box" disabled>
            <option value="">Seleziona contatto</option>
        </select>
    </div>
</div>


        <div class="row full-width protocollo">
            <label for="subject" class="text-label label-inline">Oggetto:</label>
            <input type="text" id="subject" name="oggetto" class="select-box" oninput="generateProtocolCode()">
        </div>

        <div class="row full-width protocollo">
            <label for="final-code" class="text-label label-inline">Protocollo:</label>
            <input type="text" id="final-code" class="select-box readonly">
        </div>

        <!-- Bottone per generare email o documento Word -->
        <div class="row full-width protocollo">
            <button type="button" class="button centered" id="genera-button" onclick="handleGenera()">Genera</button>
        </div>
    </div>

    <!-- ARCHIVIO TABELLARE -->
    <div class="archive-container" id="archive-container">
        <h3>Archivio</h3>    
        <hr>

        <?php 
        include 'views/components/table_component.php';

        $table_columns = [
            "Protocollo", "Commessa", "Inviato Da", "Ditta", 
            "Referente Email", "Nome Referente", "Data", "Oggetto", "Tipologia"
        ];

        renderTable("protocolTable", $table_columns, "api/protocol/get_Archivio.php");
        ?>

    </div>
</div>

<!-- Passaggio delle variabili PHP a JavaScript -->
<script type="text/javascript">
    var inviato_da = '<?php echo $_SESSION['username']; ?>';
    var nextId = '<?php echo json_encode($nextId); ?>';
    var commesse = <?php echo json_encode($commesse); ?>;
    var generale = <?php echo json_encode($generale); ?>;
</script>
