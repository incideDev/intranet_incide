<?php
/**
 * Elenco Documenti - Document List Management View
 *
 * Features:
 * - Document tracking with metadata (section, phase, zone, discipline, type, revision, status)
 * - Submittal management (transmission tracking)
 * - Transmission letter generation (PDF preview)
 * - Email capability for sending transmission letters
 */

// Quando inclusa come partial dentro commessa_elaborati, questa costante è già definita.
// In modalità standalone non è definita → comportamento originale invariato.
$_ed_embedded = defined('ED_EMBEDDED_IN_COMMESSA');

// Permission check — solo in modalità standalone
if (!$_ed_embedded && !userHasPermission('view_commesse')) {
    header('Location: /index.php?page=home');
    exit;
}

// Get project ID: in embedded usa $tabella dal contesto commessa, in standalone usa GET
$idProject = $_ed_embedded
    ? ($tabella ?? '')
    : filter_input(INPUT_GET, 'idProject', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$_ed_embedded && (!$idProject || strlen($idProject) > 32)) {
    header('Location: /index.php?page=commesse');
    exit;
}

// Page configuration.
// In modalità standalone: forza $Page = 'elenco_documenti' così main.php carica CSS/JS.
// In modalità embedded: $Page rimane 'commessa' (già impostato da index.php) e il
// caricamento di CSS/JS avviene tramite le condizioni su $view === 'elaborati' in main.php.
if (!$_ed_embedded) {
    $Page = 'elenco_documenti';
    $titolo_principale = 'Elenco Documenti';
}
?>

<?php if (!$_ed_embedded): ?>
<div class="main-container">
<?php endif; ?>
<div class="elenco-documenti-container">
    <!-- Header -->
    <div class="ed-header">
        <div class="ed-header-left">
            <h1 class="ed-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                Elenco Documenti
            </h1>
            <span class="ed-project-badge" id="projectBadge">Caricamento...</span>
        </div>
        <div class="ed-header-right">
            <div class="ed-stats">
                <div class="ed-stat">
                    <span class="ed-stat-value" id="tot-count">0</span>
                    <span class="ed-stat-label">Documenti</span>
                </div>
                <div class="ed-stat">
                    <span class="ed-stat-value" id="avg-prog">0%</span>
                    <span class="ed-stat-label">Avanzamento</span>
                </div>
                <div class="ed-stat">
                    <span class="ed-stat-value" id="issued-count">0</span>
                    <span class="ed-stat-label">Emessi</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="ed-toolbar">
        <div class="ed-toolbar-left">
            <div class="ed-filter-group">
                <div class="ed-filter-item">
                    <label>Stato</label>
                    <select id="filter-stato" class="ed-select">
                        <option value="">Tutti</option>
                        <option value="PIANIFICATO">Pianificato</option>
                        <option value="IN CORSO">In Corso</option>
                        <option value="EMESSO">Emesso</option>
                        <option value="IN REVISIONE">In Revisione</option>
                    </select>
                </div>
                <div class="ed-filter-item">
                    <label>Disciplina</label>
                    <select id="filter-disc" class="ed-select">
                        <option value="">Tutte</option>
                    </select>
                </div>
                <div class="ed-filter-item">
                    <label>Responsabile</label>
                    <select id="filter-resp" class="ed-select">
                        <option value="">Tutti</option>
                    </select>
                </div>
                <div class="ed-filter-item search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input type="text" id="filter-text" placeholder="Cerca documenti...">
                </div>
            </div>
        </div>
        <div class="ed-toolbar-right">
            <button class="btn btn-secondary ed-btn" id="btnSubmittalMgr" title="Gestione Submittal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M22 2L11 13"/>
                    <path d="M22 2L15 22l-4-9-9-4 20-7z"/>
                </svg>
                Submittal
                <span class="badge" id="submittalCount">0</span>
            </button>
            <?php if (userHasPermission('edit_commessa')): ?>
            <button class="btn btn-primary ed-btn" id="btnAddSection" title="Aggiungi sezione">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Nuova Sezione
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Document Sections Container -->
    <div class="ed-sections" id="seccont">
        <div class="ed-loading">
            <div class="spinner"></div>
            <span>Caricamento documenti...</span>
        </div>
    </div>

    <!-- Save Flash -->
    <div class="ed-save-flash" id="sf">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
        Modifiche salvate
    </div>
</div>

<!-- Properties Panel -->
<div class="ed-props-panel" id="propsPanel">
    <div class="ed-props-header">
        <div class="ed-props-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <span id="pp-code-disp">---</span>
        </div>
        <button class="ed-close-btn" onclick="ElencoDoc.closeProps()">&times;</button>
    </div>
    <div class="ed-props-subtitle" id="pp-title-disp">---</div>
    <div class="ed-props-body" id="pp-body"></div>
    <div class="ed-props-footer">
        <?php if (userHasPermission('edit_commessa')): ?>
        <button class="btn btn-secondary" id="btn-revisione" style="display:none" onclick="ElencoDoc.openRevDialog()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
            </svg>
            Nuova Revisione
        </button>
        <?php endif; ?>
        <div style="flex:1"></div>
        <button class="btn btn-secondary" onclick="ElencoDoc.closeProps()">Annulla</button>
        <?php if (userHasPermission('edit_commessa')): ?>
        <button class="btn btn-primary" onclick="ElencoDoc.saveProps()">Salva</button>
        <?php endif; ?>
    </div>
</div>

<!-- Revision Dialog -->
<div class="ed-dialog-overlay" id="revDialog" style="display:none">
    <div class="ed-dialog">
        <div class="ed-dialog-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
            </svg>
            <h3>Crea Nuova Revisione</h3>
        </div>
        <div class="ed-dialog-body">
            <p>Verrà creato un nuovo documento con:</p>
            <div class="ed-dialog-code" id="rdb-code">---</div>
            <p id="rdb-newrev-desc">---</p>
        </div>
        <div class="ed-dialog-footer">
            <button class="btn btn-secondary" onclick="ElencoDoc.closeRevDialog()">Annulla</button>
            <button class="btn btn-primary" onclick="ElencoDoc.confirmRevision()">Conferma</button>
        </div>
    </div>
</div>

<!-- Submittal Panel -->
<div class="ed-submittal-panel" id="subPanel">
    <div class="ed-submittal-box">
        <div class="ed-submittal-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M22 2L11 13"/>
                <path d="M22 2L15 22l-4-9-9-4 20-7z"/>
            </svg>
            <h2>Nuovo Submittal</h2>
            <span class="ed-submittal-count" id="subCount">0 documenti selezionati</span>
            <button class="ed-close-btn" onclick="ElencoDoc.closeSub()">&times;</button>
        </div>
        <div class="ed-submittal-body">
            <div class="ed-submittal-left">
                <div class="ed-submittal-code-row">
                    <div class="ed-form-group">
                        <label>Tipo</label>
                        <select id="cb-type" class="ed-select" onchange="ElencoDoc.updateSubCode()">
                            <option value="TR">TR - Trasmissione</option>
                            <option value="RQ">RQ - Richiesta</option>
                            <option value="AP">AP - Approvazione</option>
                        </select>
                    </div>
                    <div class="ed-form-group">
                        <label>Numero</label>
                        <input type="text" id="cb-num" class="ed-input" value="001" maxlength="3" onchange="ElencoDoc.updateSubCode()">
                    </div>
                    <div class="ed-form-group">
                        <label>Rev</label>
                        <input type="text" id="cb-rev" class="ed-input" value="A" maxlength="1" onchange="ElencoDoc.updateSubCode()">
                    </div>
                    <div class="ed-form-group">
                        <label>Codice</label>
                        <div class="ed-code-preview" id="subCodePreview">---</div>
                    </div>
                </div>
                <div class="ed-section-label">Documenti disponibili</div>
                <div class="ed-submittal-doc-list" id="subDocList"></div>
            </div>
            <div class="ed-submittal-right">
                <div class="ed-section-label">Documenti selezionati</div>
                <div class="ed-submittal-selected" id="subSelList">
                    <div class="ed-empty-state">Nessun documento selezionato</div>
                </div>
                <div class="ed-divider"></div>
                <div class="ed-section-label">Dettagli Trasmissione</div>
                <div class="ed-form-row">
                    <div class="ed-form-group">
                        <label>Data di consegna *</label>
                        <input type="date" id="sub-date" class="ed-input">
                    </div>
                    <div class="ed-form-group">
                        <label>Scopo *</label>
                        <select id="sub-scopo" class="ed-select">
                            <option>Per approvazione</option>
                            <option>Per informazione</option>
                            <option>Per commenti</option>
                            <option>Emissione definitiva</option>
                        </select>
                    </div>
                </div>
                <div class="ed-form-row">
                    <div class="ed-form-group">
                        <label>Destinatario *</label>
                        <select id="sub-dest" class="ed-select">
                            <option value="">— Seleziona —</option>
                        </select>
                    </div>
                    <div class="ed-form-group">
                        <label>Modalità</label>
                        <select id="sub-modalita" class="ed-select">
                            <option>E-mail</option>
                            <option>PEC</option>
                            <option>Portale committente</option>
                            <option>Consegna fisica</option>
                        </select>
                    </div>
                </div>
                <div class="ed-form-group">
                    <label>Oggetto</label>
                    <input type="text" id="sub-oggetto" class="ed-input" placeholder="Es. Trasmissione elaborati PD">
                </div>
                <div class="ed-form-group">
                    <label>Note</label>
                    <textarea id="sub-note" class="ed-textarea" rows="2" placeholder="Note facoltative..."></textarea>
                </div>
            </div>
        </div>
        <div class="ed-submittal-footer">
            <button class="btn btn-secondary" onclick="ElencoDoc.closeSub()">Annulla</button>
            <button class="btn btn-secondary" onclick="ElencoDoc.saveSub(false)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                Salva come pianificato
            </button>
            <button class="btn btn-primary" onclick="ElencoDoc.saveSub(true)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M22 2L11 13"/>
                    <path d="M22 2L15 22l-4-9-9-4 20-7z"/>
                </svg>
                Emetti Submittal
            </button>
        </div>
    </div>
</div>

<!-- Submittal Manager Panel -->
<div class="ed-smgr-panel" id="smgrPanel">
    <div class="ed-smgr-box">
        <div class="ed-smgr-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M22 2L11 13"/>
                <path d="M22 2L15 22l-4-9-9-4 20-7z"/>
            </svg>
            <h2>Gestione Submittal</h2>
            <span class="badge" id="smgrTot">0</span>
            <button class="ed-close-btn" onclick="ElencoDoc.closeSmgr()">&times;</button>
        </div>
        <div class="ed-smgr-body" id="smgrBody">
            <div class="ed-empty-state">Nessun submittal registrato. Crea il primo dal pannello documenti.</div>
        </div>
        <div class="ed-smgr-footer">
            <button class="btn btn-primary" onclick="ElencoDoc.closeSmgr();ElencoDoc.openSub();">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Nuovo Submittal
            </button>
        </div>
    </div>
</div>

<!-- Transmission Letter Panel -->
<div class="ed-ltr-panel" id="ltrPanel">
    <div class="ed-ltr-box">
        <div class="ed-ltr-toolbar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <h3>Lettera di Trasmissione</h3>
            <div style="flex:1"></div>
            <button class="btn btn-secondary ed-btn-sm" onclick="ElencoDoc.sendSubmittalMail()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,12 2,6"/>
                </svg>
                Componi mail
            </button>
            <button class="btn btn-secondary ed-btn-sm" onclick="ElencoDoc.downloadLtrPdf()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Scarica PDF
            </button>
            <button class="btn btn-primary ed-btn-sm" onclick="window.print()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Stampa
            </button>
            <button class="ed-close-btn" onclick="ElencoDoc.closeLtr()">&times;</button>
        </div>
        <div class="ed-ltr-content">
            <div class="ed-ltr-doc" id="ltrDoc"></div>
        </div>
    </div>
</div>

<!-- Mail Compose Panel -->
<div class="ed-mail-panel" id="mailPanel">
    <div class="ed-mail-box">
        <div class="ed-mail-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,12 2,6"/>
            </svg>
            <h3>Invia Trasmissione</h3>
            <button class="ed-close-btn" onclick="ElencoDoc.closeMailPanel()">&times;</button>
        </div>
        <div class="ed-mail-body">
            <div class="ed-mail-attach-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                La lettera di trasmissione PDF verrà allegata automaticamente all'invio
            </div>
            <div class="ed-form-group">
                <label>A</label>
                <input type="email" id="mailTo" class="ed-input" placeholder="destinatario@cliente.it">
            </div>
            <div class="ed-form-group">
                <label>CC</label>
                <input type="text" id="mailCc" class="ed-input" placeholder="cc@esempio.it, altro@esempio.it">
            </div>
            <div class="ed-form-group">
                <label>Oggetto</label>
                <input type="text" id="mailSubject" class="ed-input">
            </div>
            <div class="ed-form-group">
                <label>Testo</label>
                <textarea id="mailBody" class="ed-textarea" rows="6"></textarea>
            </div>
        </div>
        <div class="ed-mail-footer">
            <span class="ed-mail-status" id="mailStatus"></span>
            <button class="btn btn-secondary" onclick="ElencoDoc.closeMailPanel()">Annulla</button>
            <button class="btn btn-primary" id="mailSendBtn" onclick="ElencoDoc.dispatchMail()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                Invia
            </button>
        </div>
    </div>
</div>

<!-- Modal browser Nextcloud -->
<div id="ncBrowserModal" class="ed-ncb-overlay">
    <div class="ed-ncb-modal">
        <div class="ed-ncb-header">
            <div>
                <div class="ed-ncb-title">Sfoglia Nextcloud</div>
                <div class="ed-ncb-folder" id="ncb-folder"></div>
            </div>
            <button class="ed-close-btn" onclick="ElencoDoc.closeNcBrowser()">×</button>
        </div>
        <div class="ed-ncb-body">
            <div id="ncb-list" class="ed-ncb-list"></div>
        </div>
        <div class="ed-ncb-footer">
            <button class="btn btn-secondary" onclick="ElencoDoc.closeNcBrowser()">Annulla</button>
            <button class="btn btn-primary" id="ncb-attach-btn" disabled onclick="ElencoDoc.attachNcFileFromBrowser()">Allega selezionati</button>
        </div>
    </div>
</div>

<!-- Hidden project ID for JS -->
<input type="hidden" id="idProject" value="<?= htmlspecialchars($idProject) ?>">
<?php if (!$_ed_embedded): ?>
</div><!-- /.main-container -->
<?php endif; ?>

