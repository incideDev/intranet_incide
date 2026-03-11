<?php
// Guard di sicurezza coerente con il progetto
if (!defined('AccessoFileInterni') && !defined('HostDbDataConnector')) {
    header('HTTP/1.0 403 Forbidden', true, 403);
    exit;
}

// Sanitize base (coerente con stile progetto)
$tabellaSafe = isset($tabella) ? preg_replace('/[^a-z0-9_]/i', '', (string)$tabella) : '';
?>
<div class="main-container commessa-impostazioni">
    <?php renderPageTitle("Impostazioni Gestione Sicurezza Cantieri", "#cccccc"); ?>

    <div class="impostazioni-box" style="background:#f6f6f6; border-radius:12px; padding:24px; margin-bottom:24px;">
        <p style="margin:0;color:#666">
            Definisci i dataset per la sicurezza legati a questa commessa (workspace). Le modifiche sono immediate.
        </p>
    </div>

    <!-- ===== Documenti Sicurezza (configurazione commessa) ===== -->
    <section class="settings-section" id="sec-doc-sicurezza">
        <h3 style="margin:18px 0 14px;">Documenti Sicurezza (configurazione commessa)</h3>

        <div class="inline-add" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="button btn-add-row" data-type="sic_docs" data-tooltip="Inserisci una nuova riga documento">+ Nuova riga</button>
            <span style="color:#888;font-size:13px;">Definisci i documenti da gestire: <i>Upload</i> oppure <i>Modulo</i>.</span>
        </div>

        <table class="table table-filterable" id="table-doc-sicurezza">
            <thead>
                <tr>
                    <th>Azioni</th>
                    <th>Codice</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Pagina modulo</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </section>

    <!-- ===== Ruoli Cantiere ===== -->
    <section class="settings-section" id="sec-ruoli">
        <h3 style="margin:18px 0 14px;">Tabella Ruoli</h3>
        <div class="inline-add" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="button btn-add-row" data-type="ruoli" data-tooltip="Inserisci una nuova riga">+ Nuova riga</button>
            <span style="color:#888;font-size:13px;">(compila direttamente nella tabella e premi Invio o esci dal campo per salvare)</span>
        </div>
        <table class="table table-filterable" id="table-ruoli">
            <thead>
                <tr>
                    <th>Azioni</th>
                    <th>Codice</th>
                    <th>Nome</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </section>

    <!-- ===== Tipi Impresa ===== -->
    <section class="settings-section" id="sec-tipi-impresa">
        <h3 style="margin:18px 0 14px;">Tabella tipi impresa</h3>
        <div class="inline-add" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="button btn-add-row" data-type="tipi_impresa" data-tooltip="Inserisci una nuova riga">+ Nuova riga</button>
            <span style="color:#888;font-size:13px;">(compila direttamente nella tabella e premi Invio o esci dal campo per salvare)</span>
        </div>
        <table class="table table-filterable" id="table-tipi-impresa">
            <thead>
                <tr>
                    <th>Azioni</th>
                    <th>Codice</th>
                    <th>Nome</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </section>
</div>

<script>
  // Boot var per tutte le chiamate per-commessa (sic_docs)
  window._tabellaCommessa = '<?= htmlspecialchars($tabellaSafe, ENT_QUOTES) ?>';
</script>
<script src="/assets/js/commesse/commessa_impostazioni.js"></script>
