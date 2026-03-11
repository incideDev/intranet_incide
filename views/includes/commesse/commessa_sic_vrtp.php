<div class="main-container commessa-sic-vrtp sic-grid">
    <div class="sic-left">
        <?php renderPageTitle("Verifica Requisiti Tecnico-Professionali (VRTP)", "#cccccc"); ?>

        <div class="impostazioni-box" style="background:#f6f6f6; border-radius:12px; padding:16px; margin-bottom:16px;">
            <p style="margin:0;color:#666">
                Verifica dei Requisiti Tecnico-Professionali secondo l’Allegato XVII del D.Lgs 81/2008 e s.m.i.
            </p>
        </div>

        <!-- Status (salvataggio/autosave) -->
        <div id="vrtp-status" class="vpos-status" style="margin:8px 0 16px;color:#888;font-size:13px;">—</div>

        <!-- Radice con parametri per lo script -->
        <div id="vrtp-root"
            data-tabella="<?php echo htmlspecialchars($tabellaSafe, ENT_QUOTES, 'UTF-8'); ?>"
            data-tipo="VRTP"></div>

        <div class="table-wrapper">
            <table class="table table-filterable" id="vrtp-table">
                <thead>
                    <tr>
                        <th style="width:52%;">Voce</th>
                        <th style="width:8%;text-align:center;">Sì</th>
                        <th style="width:8%;text-align:center;">No</th>
                        <th style="width:8%;text-align:center;">N.A.</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- Note generali -->
        <div style="margin-top:16px;">
            <label for="vrtp-note-generali" style="display:block;margin-bottom:6px;">Note generali</label>
            <textarea id="vrtp-note-generali" rows="4" style="width:100%;max-width:100%;" placeholder="Annotazioni generali" data-tooltip="Note generali"></textarea>
        </div>
    </div>

    <!-- ====== COLONNA DESTRA: VIEWER DOCUMENTI ====== -->
    <aside id="sic-doc-viewer" class="sic-right" role="complementary" data-tooltip="Documenti caricati">
        <div class="sicv-header">
            <h3 class="sicv-title">Documenti caricati</h3>
            <div class="sicv-meta" id="sicv-meta"></div>
        </div>

        <div class="sicv-thumbs-wrap">
            <div class="sicv-thumbs" id="sicv-thumbs" aria-label="Miniature documenti"></div>
        </div>

        <div class="sicv-stage" id="sicv-stage" aria-live="polite">
            <div class="sicv-empty">Seleziona un documento per visualizzarlo.</div>
        </div>
    </aside>
</div>

<!-- Asset viewer -->
<link rel="preload" as="script" href="/assets/js/commesse/sic_doc_viewer.js">
<script src="/assets/js/commesse/sic_doc_viewer.js" defer></script>

<!-- JS VRTP -->
<script src="/assets/js/commesse/commessa_sic_vrtp.js" defer></script>

<!-- Bootstrap viewer documenti -->
<script>
document.addEventListener('DOMContentLoaded', async () => {
    // prendo tabella e azienda_id dall'URL (sanificati)
    const urlp = new URLSearchParams(location.search);
    const tabella   = (urlp.get('tabella') || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();
    const aziendaId = parseInt(urlp.get('azienda_id') || '0', 10) || 0;

    const rootSel = '#sic-doc-viewer';
    if (!document.querySelector(rootSel)) return;

    // attendo che customFetch sia disponibile (stile progetto)
    async function ensureCustomFetch(maxMs = 5000) {
        const start = Date.now();
        while (typeof window.customFetch !== 'function') {
            if (Date.now() - start > maxMs) throw new Error('customFetch non disponibile');
            await new Promise(r => setTimeout(r, 50));
        }
    }
    await ensureCustomFetch();

    // key locale opzionale per ricordare l’ultimo selezionato
    const storageKey = `all|${tabella}|${aziendaId}`;

    // inizializzo il viewer chiedendo TUTTI i documenti associati a quell’azienda
    initSicDocViewer({
        rootSelector: rootSel,
        storageKey,
        provider: async () => {
            // section/action nel body + CSRF gestito da customFetch
            const res = await customFetch('commesse', 'listDocumentiSicurezza', {
                tabella,
                azienda_id: aziendaId
                // se vuoi filtrare per tipo in futuro: , tipo: 'VRTP'
            });
            return (res && res.success && Array.isArray(res.items)) ? res.items : [];
        },
        onSelect: (doc) => {
            // hook opzionale: puoi evidenziare riga tabella ecc.
        }
    });
});
</script>

<!-- CSS minimal per layout/viewport (puoi spostarlo nei tuoi css) -->
<style>
    .sic-grid {
        display: grid;
        grid-template-columns: 1fr 640px;
        gap: 16px;
        align-items: start
    }

    .sic-left {
        min-width: 0
    }

    .sic-right {
        position: sticky;
        top: 12px;
        border: 1px solid #e6e6e6;
        border-radius: 12px;
        background: #fff;
        padding: 10px 10px 12px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .03);
        min-height: 320px
    }

    .sicv-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 8px
    }

    .sicv-title {
        margin: 6px 0 8px;
        font-size: 1.05rem
    }

    .sicv-meta {
        color: #777;
        font-size: .85rem
    }

    .sicv-thumbs-wrap {
        position: sticky;
        top: 10px;
        background: #fff;
        padding: 6px 0 8px;
        border-bottom: 1px solid #f0f0f0;
        z-index: 2
    }

    .sicv-thumbs {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: thin
    }

    .sicv-thumb {
        flex: 0 0 auto;
        width: 72px;
        height: 54px;
        border: 1px solid #e6e6e6;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .75rem;
        color: #555;
        text-decoration: none;
        background: #fafafa;
        position: relative
    }

    .sicv-thumb[data-active="1"] {
        border-color: #ff7a33;
        box-shadow: 0 0 0 2px rgba(255, 122, 51, .15) inset
    }

    .sicv-thumb img {
        max-width: 100%;
        max-height: 100%;
        border-radius: 7px;
        display: block
    }

    .sicv-badge {
        position: absolute;
        right: 4px;
        bottom: 4px;
        background: #ff7a33;
        color: #fff;
        border-radius: 999px;
        padding: 1px 6px;
        font-size: .68rem;
        line-height: 1.5
    }

    .sicv-stage {
        margin-top: 10px;
        min-height: 220px
    }

    .sicv-empty {
        color: #777;
        font-size: .92rem;
        padding: 10px
    }

    .sicv-view {
        width: 100%;
        min-height: 320px;
        border: 1px solid #e6e6e6;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        background: #00000008
    }

    .sicv-view iframe,
    .sicv-view img {
        width: 100%;
        height: calc(100vh - 260px);
        display: block;
        border: 0
    }

    .sicv-actions {
        margin-top: 8px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .sicv-pill {
        display: inline-block;
        min-width: 86px;
        text-align: center;
        border: 1px solid #ff7a33;
        color: #ff7a33;
        background: #fff;
        border-radius: 999px;
        padding: 6px 12px;
        font-weight: 600;
        font-size: .9rem
    }

    .sicv-pill:hover {
        background: #fff6f0
    }

    @media(max-width:1024px) {
        .sic-grid {
            grid-template-columns: 1fr
        }

        .sic-right {
            position: static
        }

        .sicv-view img,
        .sicv-view iframe {
            height: 60vh
        }
    }
</style>