<?php
// === Guard coerente con il progetto (case sensitive) ===
if (!defined('hostdbdataconnector') && !defined('HostDbDataConnector')) {
    header('HTTP/1.0 403 Forbidden', true, 403);
    exit;
}

// Parametri GET sanificati
$tabella   = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string)$_GET['tabella']) : '';
$titolo    = isset($_GET['titolo'])  ? trim(strip_tags((string)$_GET['titolo'])) : 'Commessa';
$aziendaId = isset($_GET['azienda_id']) ? (int)$_GET['azienda_id'] : 0;

if ($tabella === '' || $aziendaId <= 0) {
    echo "<div class='error'>Parametri mancanti.</div>";
    return;
}

$csrf = $_SESSION['CSRFtoken'] ?? '';
?>
<meta name="token-csrf" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

<div class="main-container commessa-sic-vfp">
    <?php
    $nomeAzienda = 'Impresa #' . $aziendaId;
    renderPageTitle('VERIFICA VFP - ' . htmlspecialchars($nomeAzienda), '#cccccc');
    ?>

    <div class="vfp-toolbar" style="display:flex;gap:8px;align-items:center;margin:6px 0 14px;">
        <button id="btn-add-row" class="vfp-btn" data-tooltip="Aggiungi operatore" type="button">+ Operatore</button>
        <span style="color:#888;font-size:13px;">Autosave attivo. Le date antecedenti ad oggi sono evidenziate in rosso.</span>
    </div>

    <div class="table-wrapper" style="overflow-x:auto;">
        <table id="vfp-table" class="table vfp-table" style="width:100%;min-width:1200px;">
            <thead>
                <tr>
                    <th style="min-width:160px;">Cognome</th>
                    <th style="min-width:140px;">Nome</th>
                    <th style="min-width:120px;">Posizione</th>
                    <th style="min-width:120px;">UNILAV</th>
                    <th>C.I.</th>
                    <th>I.S.</th>
                    <th>Cons. DPI</th>
                    <th>P.S.</th>
                    <th>F. Generale</th>
                    <th>R. Basso (4 h)</th>
                    <th>R. Medio (8 h)</th>
                    <th>R. Alto (12 h)</th>
                    <th>Preposto</th>
                    <th>Dirigente</th>
                    <th>DDL</th>
                    <th>RSPP</th>
                    <th>RLS</th>
                    <th>CSP/CSE</th>
                    <th>Primo Socc.</th>
                    <th>Antincendio</th>
                    <th>Lavori in quota</th>
                    <th>DPI III°Cat</th>
                    <th>Amb. Conf.</th>
                    <th>PiMUS</th>
                    <th>PLE</th>
                    <th style="width:36px;"></th>
                </tr>
            </thead>
            <tbody>
                <!-- Righe via JS -->
            </tbody>
        </table>
    </div>
</div>

<!-- JS dedicato -->
<script>
    window.__VFP_CTX__ = {
        tabella: "<?= htmlspecialchars($tabella, ENT_QUOTES, 'UTF-8') ?>",
        aziendaId: <?= (int)$aziendaId ?>,
        titolo: "<?= htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8') ?>"
    };
</script>
<script src="/assets/js/commesse/commessa_sic_vfp.js"></script>

<style>
    .vfp-btn {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 6px 10px;
        background: #fff;
        cursor: pointer;
    }

    .vfp-table th,
    .vfp-table td {
        border: 1px solid #eee;
        padding: 6px 8px;
        vertical-align: middle;
    }

    .vfp-table thead th {
        position: sticky;
        top: 0;
        background: #fafafa;
        z-index: 1;
    }

    .vfp-cell-date input[type="date"] {
        width: 140px;
    }

    .vfp-cell-text input[type="text"] {
        width: 160px;
    }

    .pill-pos {
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
        font-weight: 600;
        font-size: .9rem;
        border: 1px solid transparent;
    }

    .pill-regolare {
        background: #e9f7ef;
        color: #178a52;
        border-color: #bfe6cf;
    }

    .pill-irregolare {
        background: #fdecea;
        color: #c0392b;
        border-color: #f5b7b1;
    }

    .vfp-expired input[type="date"] {
        background: #fdecea;
        border-color: #f5b7b1;
    }

    .vfp-row-actions button {
        border: none;
        background: transparent;
        color: #c00;
        font-size: 18px;
        cursor: pointer;
    }
</style>