<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

// Parametri sanificati (stile progetto)
$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : '';
$titolo  = isset($_GET['titolo'])  ? trim(strip_tags($_GET['titolo'])) : 'Commessa';

if ($tabella === '') {
    echo "<div class='error'>Parametro 'tabella' mancante.</div>";
    return;
}

/**
 * Questa pagina mostra le IMPRESE dell’organigramma (partecipanti al progetto)
 * e, per ciascuna, i controlli VTP/VPOS/VFP.
 * I dati arrivano da CommesseService::listImpresePerControlli (via customFetch o fallback).
 */

// Proviamo un fallback server-side in caso JS/Ajax non risponda
$fallbackAziende = [];
if (method_exists(CommesseService::class, 'listImpresePerControlli')) {
    try {
        $res = CommesseService::listImpresePerControlli(['tabella' => $tabella]);
        if (is_array($res) && !empty($res['success']) && !empty($res['aziende']) && is_array($res['aziende'])) {
            $fallbackAziende = $res['aziende'];
        }
    } catch (\Throwable $e) {
        // niente: fallback vuoto gestito lato UI
    }
}

$knownViews = ['sic_vrtp', 'sic_vpos', 'sic_vfp'];
$hasVfp     = in_array('sic_vfp', $knownViews, true);

?>
<div class="main-container commessa-controlli-sicurezza">
    <?php renderPageTitle('Controlli di Sicurezza per Impresa', '#1F5F8B'); ?>

    <div class="info-box" style="background:#f6f6f6;border-radius:12px;padding:14px 18px;margin-bottom:14px;color:#555">
        Elenco imprese che partecipano alla commessa. Per ogni impresa puoi aprire i controlli: VTP, VPOS e VFP.
    </div>

    <div id="controlliAziende"
        data-tabella="<?= htmlspecialchars($tabella) ?>"
        data-titolo="<?= htmlspecialchars($titolo) ?>"
        data-known-views='<?= htmlspecialchars(json_encode($knownViews, JSON_UNESCAPED_SLASHES)) ?>'
        data-fallback='<?= htmlspecialchars(json_encode($fallbackAziende, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
        <!-- Il JS rimpiazzerà/riempirà -->
        <?php if (empty($fallbackAziende)): ?>
            <div class="placeholder" style="color:#777;">Caricamento imprese…</div>
        <?php else: ?>
            <?php foreach ($fallbackAziende as $az):
                $aziendaId  = (int)($az['azienda_id'] ?? 0);
                $nome       = trim((string)($az['nome'] ?? 'Impresa'));
                $ruolo      = trim((string)($az['ruolo'] ?? ''));
            ?>
                <div class="ctrl-card" data-azienda-id="<?= $aziendaId ?>">
                    <div class="ctrl-main">
                        <div class="ctrl-title"><?= htmlspecialchars($nome) ?></div>
                        <?php if ($ruolo !== ''): ?>
                            <div class="ctrl-subtitle"><?= htmlspecialchars($ruolo) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="ctrl-actions">
                        <a class="ctrl-pill"
                            data-tooltip="Apri VTP"
                            href="index.php?section=commesse&page=commessa&tabella=<?= urlencode($tabella) ?>&titolo=<?= urlencode($titolo) ?>&view=sic_vrtp&azienda_id=<?= $aziendaId ?>">
                            VTP
                        </a>
                        <a class="ctrl-pill"
                            data-tooltip="Apri VPOS"
                            href="index.php?section=commesse&page=commessa&tabella=<?= urlencode($tabella) ?>&titolo=<?= urlencode($titolo) ?>&view=sic_vpos&azienda_id=<?= $aziendaId ?>">
                            VPOS
                        </a>
                        <?php if ($hasVfp): ?>
                            <a class="ctrl-pill"
                                data-tooltip="Apri VFP"
                                href="index.php?section=commesse&page=commessa&tabella=<?= urlencode($tabella) ?>&titolo=<?= urlencode($titolo) ?>&view=sic_vfp&azienda_id=<?= $aziendaId ?>">
                                VFP
                            </a>
                        <?php else: ?>
                            <button class="ctrl-pill disabled" type="button" disabled data-tooltip="VFP non ancora disponibile">VFP</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<link rel="preload" as="script" href="assets/js/commesse/commessa_controlli_sicurezza.js">
<script src="assets/js/commesse/commessa_controlli_sicurezza.js" defer></script>

<style>
    /* Stili scopo-pagina, coerenti con organigramma ma a card verticali */
    .commessa-controlli-sicurezza .ctrl-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        border: 1px solid #e6e6e6;
        border-radius: 12px;
        padding: 14px 16px;
        margin: 10px 0;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .commessa-controlli-sicurezza .ctrl-main {
        min-width: 0;
    }

    .commessa-controlli-sicurezza .ctrl-title {
        font-size: 1.05rem;
        font-weight: 600;
        line-height: 1.25;
        color: #222;
    }

    .commessa-controlli-sicurezza .ctrl-subtitle {
        font-size: .9rem;
        color: #777;
        margin-top: 2px;
    }

    .commessa-controlli-sicurezza .ctrl-actions {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-left: 18px;
    }

    .commessa-controlli-sicurezza .ctrl-pill {
        display: inline-block;
        text-decoration: none;
        border: 1px solid #ff7a33;
        border-radius: 999px;
        padding: 6px 12px;
        font-weight: 600;
        font-size: .9rem;
        color: #ff7a33;
        background: #fff;
        transition: .15s ease-in-out;
        text-align: center;
        min-width: 82px;
    }

    .commessa-controlli-sicurezza .ctrl-pill:hover {
        background: #fff6f0;
    }

    .commessa-controlli-sicurezza .ctrl-pill.disabled {
        opacity: .5;
        cursor: not-allowed;
        border-color: #ccc;
        color: #888;
    }

    @media (max-width: 920px) {
        .commessa-controlli-sicurezza .ctrl-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .commessa-controlli-sicurezza .ctrl-actions {
            flex-direction: row;
            flex-wrap: wrap;
        }
    }
</style>