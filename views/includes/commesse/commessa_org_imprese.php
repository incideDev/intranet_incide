<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

global $database;

/* --- Parametri --- */
$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante nell'URL.</div>";
    return;
}

/* --- Dati commessa --- */
$commessa = $database->query(
    "SELECT b.id, b.titolo
     FROM commesse_bacheche b
     WHERE b.tabella = ? LIMIT 1",
    [$tabella],
    __FILE__
)->fetch(\PDO::FETCH_ASSOC);

if (!$commessa) {
    echo "<div class='error'>Commessa non trovata.</div>";
    return;
}

$commessaId = (int)$commessa['id'];

/* --- Carica organigramma imprese (JSON albero) --- */
$tree = CommesseService::getOrganigrammaImprese($tabella);
if (!is_array($tree)) $tree = []; // struttura: array di nodi { azienda_id:int, children:[] }

/* --- UI: stessa struttura del modulo persone --- */
?>
<div class="main-container commessa-organigramma">
    <?php renderPageTitle('Organigramma Imprese', '#2C3E50'); ?>

    <!-- VISTA ALBERO -->
    <div id="org-tree-view">
        <div class="org-main-wrap">
            <div class="org-canvas-area">
                <!-- pannello info impresa selezionata -->
                <aside id="org-azienda-panel" class="org-azienda-panel" aria-live="polite" aria-label="Dettagli impresa" hidden>
                    <div class="panel-header">
                        <div class="panel-title">Dettagli impresa</div>
                        <button id="org-close-panel" class="btn-icon" type="button" aria-label="Chiudi pannello" data-tooltip="Chiudi pannello">×</button>
                    </div>
                    <div id="org-azienda-summary" class="panel-summary">
                        <!-- riempiamo via JS -->
                    </div>
                    <div class="panel-section">
                        <div class="panel-subtitle">Documenti sicurezza</div>
                        <ul id="org-doc-list" class="doc-checklist">
                            <!-- riempiamo via JS -->
                        </ul>
                    </div>
                    <div class="panel-actions">
                        <?php $titoloUpper = strtoupper($tabella); ?>
                        <button id="org-open-pos"
                            class="btn small"
                            type="button"
                            data-doc-code="POS"
                            data-fallback-url="index.php?section=commesse&page=commessa&tabella=<?= htmlspecialchars($tabella) ?>&titolo=<?= htmlspecialchars($titoloUpper) ?>&view=sic_vpos"
                            data-tooltip="Apri POS">Apri POS</button>

                        <button id="org-open-vrtp"
                            class="btn small"
                            type="button"
                            data-doc-code="VRTP"
                            data-fallback-url="index.php?section=commesse&page=commessa&tabella=<?= htmlspecialchars($tabella) ?>&titolo=<?= htmlspecialchars($titoloUpper) ?>&view=sic_vrtp"
                            data-tooltip="Apri VRTP">Apri VRTP</button>

                        <button id="org-open-vcs"
                            class="btn small"
                            type="button"
                            data-doc-code="VCS"
                            data-fallback-url="index.php?section=commesse&page=commessa&tabella=<?= htmlspecialchars($tabella) ?>&titolo=<?= htmlspecialchars($titoloUpper) ?>&view=sic_vcs"
                            data-tooltip="Apri VCS">Apri VCS</button>
                    </div>
                </aside>

                <div class="org-tree-scrollwrap">
                    <div class="org-tree-inner">
                        <!-- area albero imprese (drop target generale) -->
                        <div id="org-fasce-area" class="org-fasce-area"></div>
                    </div>
                </div>
            </div>

            <!-- Sidebar strumenti (lista imprese da 'anagrafiche') -->
            <div id="org-sidebar" class="org-sidebar-toolbox">
                <div class="toolbox-header">Strumenti</div>

                <div class="toolbox-section">
                    <div class="toolbox-title">Imprese disponibili</div>
                    <input type="text" id="org-search-imprese" class="org-users-search" placeholder="Cerca impresa..." autocomplete="off" style="margin-bottom:6px;">
                    <div id="sidebar-imprese" class="org-users-scroll toolbox-badges" data-tooltip="Trascina un’impresa nell’albero"></div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    // Boot dati JS (ALBERO COMPLETO: { azienda_id: ?int|null, children: [] })
    window._orgImpreseInitial = <?= json_encode($tree, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window._tabellaCommessa = <?= json_encode($tabella, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    window._orgOpenDocViews = {
        'POS': 'sic_vpos',
        'VRTP': 'sic_vrtp',
        'VCS': 'sic_vcs',
        'VVCS': 'sic_vcs'
    };
</script>

<!-- JS specifico per organigramma imprese -->
<script src="/assets/js/commesse/commessa_org_imprese.js"></script>