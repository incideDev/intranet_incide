<?php
// === Guard e include dinamici: coerenza con il progetto ===
if (!defined('AccessoFileInterni') && !defined('HostDbDataConnector')) {
    header('HTTP/1.0 403 Forbidden', true, 403);
    exit;
}

/**
 * Percorsi semi-dinamici (linea guida 7)
 * Esempio: radice del progetto fino a "/assets"
 */
$__baseDir = dirname(__FILE__);
$__rootCut = strpos($__baseDir, '/assets');
$__rootDir = ($__rootCut !== false) ? substr($__baseDir, 0, $__rootCut) : dirname(__FILE__);

// CSRF (linee guida): esponi il token in meta se gestisci AJAX lato client
$csrf = $_SESSION['csrf_token'] ?? '';
?>
<meta name="token-csrf" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

<div class="main-container commessa-sic-vpos">
    <?php
    // Titolo pagina
    if (function_exists('renderPageTitle')) {
        renderPageTitle("Verifica POS – Allegato XV D.Lgs 81/2008", "#cccccc");
    } else {
        echo '<h2 style="margin:0 0 16px;">Verifica POS – Allegato XV D.Lgs 81/2008</h2>';
    }
    ?>

    <!-- Intro / intestazione modulo -->
    <div class="vpos-intro" style="margin:8px 0 16px; color:#555;">
        <p style="margin:0;">
            Verifica POS secondo quanto riportato nell’Allegato XV D.Lgs 81/2008 e s.m.i – Contenuti minimi dei piani di sicurezza nei cantieri temporanei o mobili.
        </p>
    </div>

    <div class="vpos-status" style="margin:8px 0 16px;color:#888;font-size:13px;">—</div>

    <!-- Tabella checklist ad albero -->
    <div class="table-wrapper" style="overflow-x:auto;">
        <table class="table vpos-tree-table" id="vpos-table" style="width:100%;min-width:820px;">
            <thead>
                <tr>
                    <th style="width:44%;">Voce</th>
                    <th style="width:8%;text-align:center;">Sì</th>
                    <th style="width:8%;text-align:center;">No</th>
                    <th style="width:8%;text-align:center;">N.A.</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <!-- Righe generate via JS -->
            </tbody>
        </table>
    </div>

    <!-- Note finali (campo unico opzionale) -->
    <div style="margin-top:16px;">
        <label style="display:block;margin-bottom:6px;">NOTE GENERALI</label>
        <textarea id="vpos-note-generali" rows="4" style="width:100%;"></textarea>
    </div>
</div>

<!-- JS dedicato alla pagina -->
<script src="/assets/js/commesse/commessa_sic_vpos.js"></script>