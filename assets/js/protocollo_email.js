let aziendeCache = null;
let contattiCache = null;

handleTipologiaChange();

/* hardening client: rimuovo eventuali opzioni area non permesse anche se iniettate via dom */
document.addEventListener('DOMContentLoaded', function () {
    try {
        const area = document.getElementById('area');
        if (!area) return;
        const canGenerale = !!(window.VIS_SEZ && window.VIS_SEZ.generale);
        const canSingole = !!(window.VIS_SEZ && window.VIS_SEZ.singole_commesse);

        [...area.querySelectorAll('option[value="generale"]')].forEach(o => { if (!canGenerale) o.remove(); });
        [...area.querySelectorAll('option[value="commessa"]')].forEach(o => { if (!canSingole) o.remove(); });

        area.addEventListener('change', function () {
            if (this.value === 'generale' && !canGenerale) { this.value = ''; showToast('non autorizzato a: generale', 'error'); }
            if (this.value === 'commessa' && !canSingole) { this.value = ''; showToast('non autorizzato a: commessa', 'error'); }
        });
    } catch (e) { /* noop */ }
});

function caricaAziendeCached(callback) {
    if (aziendeCache) return callback(aziendeCache);
    customFetch('protocollo_email', 'caricaAziende', {}).then(res => {
        if (res.success) aziendeCache = res.data;
        callback(aziendeCache || []);
    });
}

// Espone funzione canonica globalmente per riuso in altre pagine
if (typeof window !== 'undefined') {
    window.caricaAziendeCached = caricaAziendeCached;
}

function caricaTuttiContattiCached(callback) {
    if (contattiCache) return callback(contattiCache);
    customFetch('protocollo_email', 'getTuttiContatti', {}).then(res => {
        if (res.success) contattiCache = res.data;
        callback(contattiCache || []);
    });
}

const subjectInput = document.getElementById('subject');
if (subjectInput) {
    subjectInput.addEventListener('input', function () {
        const commessa = document.getElementById('project')?.value || '';
        const tipologia = document.getElementById('nuova-tipologia')?.value || '';
        const oggetto = this.value.trim();

        if (commessa && tipologia && oggetto) {
            generateProtocolCode();
        } else {
            document.getElementById('final-code').value = '';
        }
    });
}

function setupDropdownCodiceDescrizione() {
    const selectbox = document.getElementById('custom-project-select');
    const area = document.getElementById('area');
    if (!selectbox || !area) return;

    function loadOptionsAndShow(force = false) {
        const areaVal = area.value;
        let opzioni = [];

        if (areaVal === 'commessa') {
            customFetch('protocollo_email', 'getCommesse').then(response => {
                const dati = response.success && Array.isArray(response.data) ? response.data : [];
                opzioni = dati.map(d => ({
                    value: d.codice,
                    label: d.oggetto,
                    code: d.codice
                }));
                window.showCustomDropdown(selectbox, opzioni, {
                    placeholder: "Cerca per codice o descrizione...",
                    valoreIniziale: document.getElementById('project')?.value || '',
                    onSelect: (opt) => {
                        selectbox.querySelector('.custom-select-placeholder').textContent =
                            `${opt.code} | ${opt.label}`;
                        document.getElementById('project').value = opt.value;
                        generateProtocolCode();
                    }
                });
            });
        } else if (areaVal === 'generale') {
            const generaleData = [
                { codice_commessa: 'GAR', descrizione: 'Gare' },
                { codice_commessa: 'AMM', descrizione: 'Amministrazione' },
                { codice_commessa: 'OFF', descrizione: 'Offerte' },
                { codice_commessa: 'ACQ', descrizione: 'Acquisti' },
                { codice_commessa: 'HRR', descrizione: 'Risorse umane' },
                { codice_commessa: 'SQQ', descrizione: 'Qualità' },
                { codice_commessa: 'GCO', descrizione: 'Gestione commesse' },
                { codice_commessa: 'CON', descrizione: 'Contratti' }
            ].sort((a, b) => a.codice_commessa.localeCompare(b.codice_commessa));
            opzioni = generaleData.map(d => ({
                value: d.codice_commessa,
                label: d.descrizione,
                code: d.codice_commessa
            }));
            window.showCustomDropdown(selectbox, opzioni, {
                placeholder: "Cerca per codice o descrizione...",
                valoreIniziale: document.getElementById('project')?.value || '',
                onSelect: (opt) => {
                    selectbox.querySelector('.custom-select-placeholder').textContent =
                        `${opt.code} | ${opt.label}`;
                    document.getElementById('project').value = opt.value;
                    generateProtocolCode();
                }
            });
        }
    }

    selectbox.onclick = function (e) {
        e.stopPropagation();
        loadOptionsAndShow();
    };

    area.addEventListener('change', function () {
        document.getElementById('project').value = '';
        selectbox.querySelector('.custom-select-placeholder').textContent = 'Seleziona un codice';
        loadOptionsAndShow(true);
    });
}
setupDropdownCodiceDescrizione();

function generateProtocolCode() {
    const editing_id = document.getElementById('protocol-editing-id')?.value || '';
    const final_code_input = document.getElementById('final-code');
    if (!final_code_input) return;

    if (editing_id) return;

    const project = document.getElementById('project')?.value || '';
    const type = 'M';
    const year = new Date().getFullYear().toString().slice(-2);
    const subject = document.getElementById('subject')?.value.trim() || '';

    if (!project || !subject) {
        final_code_input.value = '';
        return;
    }

    final_code_input.value = `${type}_${project}_..._${year}${subject ? ' - ' + subject : ''}`;

    customFetch('protocollo_email', 'getPreviewProtocollo', {
        commessa: project,
        oggetto: subject
    }).then(res => {
        // Se l'utente ha iniziato a modificare, non sovrascrivere il campo
        if (document.getElementById('protocol-editing-id')?.value) return;

        if (res && res.success && res.final_code) {
            final_code_input.value = res.final_code;
        } else {
            final_code_input.value = '';
        }
    });
}

const projectInput = document.getElementById('project');
if (projectInput) {
    projectInput.addEventListener('change', function () {
        const finalCode = document.getElementById('final-code');
        if (finalCode) finalCode.value = '';
    });
}

const tipologiaInput = document.getElementById('nuova-tipologia');
if (tipologiaInput) {
    tipologiaInput.addEventListener('change', function () {
        const finalCode = document.getElementById('final-code');
        if (finalCode) finalCode.value = '';
    });
}

function handleTipologiaChange() {
    const tipo = document.getElementById('nuova-tipologia')?.value || '';
    const button = document.getElementById('btn-generaEApri');
    const wrapper = document.getElementById('modello-lettera-wrapper');
    const modelloSelect = document.getElementById('modello-lettera');
    const ccContainer = document.getElementById('cc-container');
    const ccInput = document.getElementById('cc');

    if (!button || !wrapper || !modelloSelect) return;

    if (tipo === 'lettera') {
        wrapper.style.display = 'block';
        modelloSelect.disabled = false;
        button.textContent = 'Genera e Apri Lettera';
        if (ccContainer) ccContainer.style.display = 'none';
        if (ccInput) ccInput.value = '';
    } else if (tipo === 'email') {
        wrapper.style.display = 'none';
        modelloSelect.value = '';
        modelloSelect.disabled = true;
        button.textContent = 'Genera e Apri Email';
        if (ccContainer) ccContainer.style.display = '';
    } else {
        wrapper.style.display = 'none';
        modelloSelect.value = '';
        modelloSelect.disabled = true;
        button.textContent = 'Genera e Apri';
        if (ccContainer) ccContainer.style.display = 'none';
        if (ccInput) ccInput.value = '';
    }
}

function handleGenera() {
    const tipo = document.getElementById('nuova-tipologia')?.value || '';
    if (tipo === 'lettera') {
        generaLettera();
    } else {
        saveEmail();
    }
}

function createHiddenField(name, value) {
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = name;
    hidden.value = value;
    return hidden;
}

function isValidMultiEmail(str) {
    if (!str) return true;
    return str.split(';').every(email =>
        /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())
    );
}

function generaEApri() {
    console.log('⚡ CHIAMATA GENERA E APRI');
    const btn = document.getElementById('btn-generaEApri');
    if (!btn || btn.disabled) return;

    const editingId = document.getElementById('protocol-editing-id')?.value || '';
    if (editingId && Number(editingId) > 0) {
        salvaProtocolloModificato();
        return;
    }

    const dati = raccogliDatiForm();
    if (!dati.id && editingId) dati.id = editingId;
    const destinatari = estraiDestinatariTabella();
    dati.a = Array.isArray(destinatari.to) ? destinatari.to.join(';') : (destinatari.to || '');
    dati.cc = Array.isArray(destinatari.cc) ? destinatari.cc.join(';') : (destinatari.cc || '');
    dati.ditta = destinatari.ditta || '';
    dati.contatto_referente = destinatari.contatto_referente || '';
    dati.nome_referente = destinatari.nome_referente || '';
    dati.destinatari_json = JSON.stringify(destinatari.destinatariDettaglio || []);

    customFetch('protocollo_email', 'generaEApri', dati)
        .then(res => {
            if (res && res.success) {
                if (dati.tipologia === 'email') {
                    let toEmails = dati.a || "";
                    let mailto = "mailto:" + toEmails;
                    let params = [];
                    if (dati.cc) params.push("cc=" + encodeURIComponent(dati.cc));
                    if (dati.ccn) params.push("bcc=" + encodeURIComponent(dati.ccn));
                    if (res.final_code) params.push("subject=" + encodeURIComponent(res.final_code));
                    if (params.length) mailto += "?" + params.join("&");

                    setTimeout(() => { window.location.href = mailto; }, 2000);
                    showToast("Email protocollata con successo", "info");
                    updateArchiveTable();
                } else if (dati.tipologia === 'lettera' && res.url) {
                    showToast('Lettera generata con successo', 'success');
                    window.open(res.url, '_blank');
                    updateArchiveTable();
                }
                resetFormAndFields();
            } else {
                showToast('Errore: ' + (res && res.error ? res.error : 'Errore generico'), 'error');
            }
        })
        .catch(err => {
            console.error("Errore generaEApri:", err);
            showToast("Errore durante la generazione.", "error");
        });

    btn.disabled = true;
    setTimeout(() => { btn.disabled = false; }, 10000);
}

function genera() {
    console.log('⚡ CHIAMATA GENERA');
    const btn = document.getElementById('btn-genera');
    if (!btn || btn.disabled) return;

    // PATCH: se in editing, esci e salva
    const editingId = document.getElementById('protocol-editing-id')?.value || '';
    if (editingId && Number(editingId) > 0) {
        salvaProtocolloModificato();
        return;
    }

    const dati = raccogliDatiForm();
    const destinatari = estraiDestinatariTabella();
    dati.a = Array.isArray(destinatari.to) ? destinatari.to.join(';') : (destinatari.to || '');
    dati.cc = Array.isArray(destinatari.cc) ? destinatari.cc.join(';') : (destinatari.cc || '');
    dati.ditta = destinatari.ditta || '';
    dati.contatto_referente = destinatari.contatto_referente || '';
    dati.nome_referente = destinatari.nome_referente || '';
    dati.destinatari_json = JSON.stringify(destinatari.destinatariDettaglio || []);
    // NON SERVE PASSARE l'id qui perché genera è SOLO per nuovi

    if (!dati.commessa || !dati.tipologia || (dati.tipologia === 'lettera' && !dati.modello)) {
        showToast('Compilare tutti i campi obbligatori prima di proseguire.', 'error');
        return;
    }

    customFetch('protocollo_email', 'genera', dati)
        .then(res => {
            if (res && res.success) {
                showToast("Protocollo generato e salvato in archivio", "success");
                updateArchiveTable();
                resetFormAndFields();
            } else {
                showToast('Errore: ' + (res && res.error ? res.error : 'Errore generico'), 'error');
            }
        })
        .catch(err => {
            console.error("Errore genera:", err);
            showToast("Errore durante la generazione.", "error");
        });
    btn.disabled = true;
    setTimeout(() => { btn.disabled = false; }, 10000);
}

function raccogliDatiForm() {
    return {
        commessa: document.getElementById('project')?.value?.trim() || '',
        contatto_referente: document.getElementById('contatto')?.value?.trim() || '',
        nome_referente: document.getElementById('nome_referente')?.value?.trim() || '',
        cc: document.getElementById('cc')?.value?.trim() || '',
        ccn: document.getElementById('ccn')?.value?.trim() || '',
        oggetto: document.getElementById('subject')?.value?.trim() || '',
        tipologia: document.getElementById('nuova-tipologia')?.value?.trim() || '',
        inviato_da: window.inviato_da || '',
        modello: document.getElementById('modello-lettera')?.value?.trim() || ''
    };
}

function resetFormAndFields() {
    const campi = [
        'email-form', 'project', 'cc', 'contatto', 'nome_referente',
        'final-code', 'subject', 'nuova-tipologia', 'area'
    ];

    campi.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;

        if (el.tagName === 'SELECT') {
            el.selectedIndex = 0;
            el.disabled = false;
        } else {
            el.value = '';
            el.disabled = false;
            el.readOnly = false;
        }
    });

    const custom = document.getElementById('custom-project-select');
    if (custom?.querySelector('.custom-select-placeholder')) {
        custom.querySelector('.custom-select-placeholder').textContent = 'Seleziona un codice';
        custom.classList.remove('disabled');
        custom.style.pointerEvents = '';
        custom.style.opacity = '';
    }
    const project = document.getElementById('project');
    if (project) {
        project.value = '';
        project.disabled = false;
    }

    const area = document.getElementById('area');
    if (area) area.disabled = false;

    const contatto = document.getElementById('contatto');
    if (contatto) {
        contatto.value = '';
        contatto.disabled = true;
        contatto.readOnly = true;
    }

    document.getElementById('dropdown-recipient')?.classList.remove('open');
    document.getElementById('dropdown-contatto')?.classList.remove('open');
    document.getElementById('dropdown-recipient')?.style.setProperty('display', 'none');
    document.getElementById('dropdown-contatto')?.style.setProperty('display', 'none');

    const modelloWrapper = document.getElementById('modello-lettera-wrapper');
    const modelloSelect = document.getElementById('modello-lettera');
    if (modelloWrapper && modelloSelect) {
        modelloWrapper.style.display = 'none';
        modelloSelect.selectedIndex = 0;
        modelloSelect.value = '';
        modelloSelect.disabled = true;
    }

    document.getElementById('protocol-editing-id').value = '';
    document.getElementById('btn-salva-protocollo').style.display = 'none';
    document.getElementById('btn-genera').style.display = '';
    document.getElementById('btn-generaEApri').style.display = '';
    document.getElementById('final-code').readOnly = false;
    const annullaBtn = document.getElementById('btn-annulla-protocollo');
    if (annullaBtn) annullaBtn.style.display = 'none';

    // === 6 righe vuote sempre nella tabella destinatari ===
    const tabDest = document.querySelector('#tabella-destinatari tbody');
    if (tabDest) {
        let html = '';
        for (let i = 0; i < 6; i++) {
            html += `
                <tr>
                    <td>
                        <div class="custom-select-box destinatario-select-box" tabindex="0" style="width:100%;">
                            <span class="custom-select-placeholder">Seleziona destinatario</span>
                            <input type="hidden" value="" data-id="">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box referente-select-box" tabindex="0" style="width:100%;">
                            <span class="custom-select-placeholder">Seleziona referente</span>
                            <input type="hidden" value="" data-id="">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box contatto-select-box" tabindex="0" style="width:100%;">
                            <span class="custom-select-placeholder">Seleziona contatto</span>
                            <input type="hidden" value="" data-id="">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box type-select-box" tabindex="0" style="width:100%;">
                            <span class="custom-select-placeholder">Seleziona tipo</span>
                            <input type="hidden" value="">
                            <div class="custom-select-dropdown" style="display:none;">
                                <div class="custom-select-option" data-value="to">TO</div>
                                <div class="custom-select-option" data-value="cc">CC</div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        }
        tabDest.innerHTML = html;
        if (typeof popolaTabellaDestinatari === 'function') {
            popolaTabellaDestinatari();
        }
    }
}

function annullaProtocollo() {
    resetFormAndFields();
}

function updateArchiveTable() {
    // Verifica che siamo nella pagina protocollo_email (controlla elemento specifico)
    const tbody = document.getElementById('protocolTable_body');
    if (!tbody) {
        // Non siamo nella pagina protocollo_email, esci silenziosamente
        return;
    }

    const p = window.VIS_SEZ || {};
    const modeInput = document.getElementById('arch-mode');
    const mode = modeInput ? (modeInput.value || 'tutte') : null;

    let solo_aree = [];

    if (mode) {
        if (mode === 'tutte') {
            if (p.generale) solo_aree.push('generale');
            if (p.singole_commesse) solo_aree.push('commessa');
        } else if (mode === 'generale' && p.generale) {
            solo_aree.push('generale');
        } else if (mode === 'commessa' && p.singole_commesse) {
            solo_aree.push('commessa');
        }
    } else {
        // nessun controllo visibile: deduci in base ai permessi
        if (p.generale && !p.singole_commesse) solo_aree = ['generale'];
        else if (!p.generale && p.singole_commesse) solo_aree = ['commessa'];
        else {
            // entrambi o nessuno specificato → entrambe le aree consentite
            if (p.generale) solo_aree.push('generale');
            if (p.singole_commesse) solo_aree.push('commessa');
        }
    }

    // fallback di sicurezza
    if (solo_aree.length === 0) {
        if (p.generale) solo_aree.push('generale');
        if (p.singole_commesse) solo_aree.push('commessa');
    }

    customFetch('protocollo_email', 'getArchivio', {
        pagina: 1,
        limite: 1000,
        solo_aree
    })

        .then(response => {
            if (!response.success) {
                showToast("Errore nel caricamento archivio", "error");
                return;
            }
            const dati = response.data || [];
            const tbody = document.getElementById('protocolTable_body');
            if (!tbody) {
                // Elemento non presente (non siamo nella pagina protocollo_email)
                return;
            }
            tbody.innerHTML = '';

            if (!Array.isArray(dati) || dati.length === 0) {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td colspan="10" style="text-align:center;color:#888;">
                Compila il form qui sopra per creare la prima email da archiviare!
            </td>`;
                tbody.appendChild(tr);
                return;
            }

            dati.forEach(row => {
                const tr = document.createElement('tr');
                const utenteCorrente = window.CURRENT_USER_ID ? String(window.CURRENT_USER_ID) : "";
                const usernameCorrente = window.CURRENT_USERNAME ? String(window.CURRENT_USERNAME) : "";
                const idCreatore = row.inviato_da_id ? String(row.inviato_da_id) : "";
                const usernameCreatore = row.inviato_da ? String(row.inviato_da) : "";

                // === CALCOLO PERMESSI ===
                const isCreatore = (
                    (utenteCorrente && idCreatore && utenteCorrente === idCreatore) ||
                    (usernameCorrente && usernameCreatore && usernameCorrente === usernameCreatore)
                );
                const isAdmin = window.CURRENT_USER_IS_ADMIN === true || window.CURRENT_USER_ROLE_ID === 1;

                // === CALCOLO SOFT LOCK 1 ORA ===
                function parseDateTimeIso(str) {
                    if (!str) return null;
                    return new Date(str.replace(' ', 'T'));
                }
                const createdDate = parseDateTimeIso(row.data_iso || row.data);
                const now = new Date();
                const diffMinutes = createdDate ? (now - createdDate) / 1000 / 60 : 9999;
                const canRigenera = (isCreatore || isAdmin) && diffMinutes <= 60;

                // === COLONNA AZIONI ===
                let tdAzioni = `<td class="azioni-colonna" style="text-align:center; white-space:nowrap;">`;

                if (isCreatore && diffMinutes <= 60) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-edit-protocollo" 
                        data-id="${row.id}"
                        data-tooltip="Modifica (entro 1 ora dalla creazione)">
                        <img src="assets/icons/create.png" alt="Modifica">
                    </button>
                `;
                } else if (isCreatore && diffMinutes > 60) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-edit-protocollo"
                        data-id="${row.id}"
                        data-tooltip="Modifica non più consentita (oltre 1 ora)"
                        style="opacity:0.4;cursor:not-allowed;" disabled>
                        <img src="assets/icons/create.png" alt="Bloccato" style="filter: grayscale(1) brightness(0.7);">
                    </button>
                `;
                } else if (isAdmin) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-edit-protocollo admin-edit"
                        data-id="${row.id}"
                        data-tooltip="Modifica come amministratore">
                        <img src="assets/icons/create.png" alt="Modifica Admin" style="filter: grayscale(1) brightness(0.7);">
                    </button>
                `;
                } else {
                    tdAzioni += `<span style="color:#bbb;">—</span>`;
                }

                if (canRigenera) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-send-protocollo"
                        data-id="${row.id}"
                        data-tipologia="${row.tipologia || ''}"
                        data-modello-lettera="${row.modello_lettera || ''}"
                        data-tooltip="Invia/genera documento (entro 1 ora dalla creazione)">
                        <img src="assets/icons/mail.png" alt="Invia">
                    </button>
                `;
                } else if (isCreatore || isAdmin) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-send-protocollo"
                        data-id="${row.id}"
                        data-tipologia="${row.tipologia || ''}"
                        data-modello-lettera="${row.modello_lettera || ''}"
                        data-tooltip="Non più disponibile: rigenerabile solo entro 1 ora dalla creazione"
                        style="opacity:0.4;cursor:not-allowed;" disabled>
                        <img src="assets/icons/mail.png" alt="Invia (bloccato)" style="filter: grayscale(1) brightness(0.7);">
                    </button>
                `;
                }

                if ((isCreatore && diffMinutes <= 60) || isAdmin) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-delete-protocollo"
                        data-id="${row.id}"
                        data-tooltip="Elimina questa riga (entro 1 ora dalla creazione, o admin)">
                        <img src="assets/icons/delete.png" alt="Elimina">
                    </button>
                `;
                } else if (isCreatore && diffMinutes > 60) {
                    tdAzioni += `
                    <button 
                        class="action-icon btn-delete-protocollo"
                        data-id="${row.id}"
                        data-tooltip="Eliminazione non più consentita (oltre 1 ora)"
                        style="opacity:0.4;cursor:not-allowed;" disabled>
                        <img src="assets/icons/delete.png" alt="Elimina (bloccato)" style="filter: grayscale(1) brightness(0.7);">
                    </button>
                `;
                }
                tdAzioni += `</td>`;

                tr.innerHTML = `
                ${tdAzioni}
                <td>${row.protocollo || ''}</td>
                <td>${row.commessa || ''}</td>
                <td>${row.inviato_da || ''}</td>
                <td>${row.ditta_nome || ''}</td>
                <td>${row.contatto_referente || ''}</td>
                <td>${row.nome_referente || ''}</td>
                <td>${row.data || ''}</td>
                <td>${row.oggetto || ''}</td>
                <td>${row.tipologia || ''}</td>
            `;
                tbody.appendChild(tr);
            });

            tbody.querySelectorAll('.btn-edit-protocollo').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    const isAdminEdit = this.classList.contains('admin-edit');
                    const id = this.getAttribute('data-id');
                    if (isAdminEdit) {
                        showConfirm(
                            "Stai per modificare questo protocollo come amministratore.<br>Vuoi procedere?",
                            () => avviaModificaProtocollo(id),
                            { allowHtml: true }
                        );
                    } else {
                        avviaModificaProtocollo(id);
                    }
                });
            });
            tbody.querySelectorAll('.btn-send-protocollo').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    const id = this.getAttribute('data-id');
                    const tipologia = this.getAttribute('data-tipologia');
                    const modelloLettera = this.getAttribute('data-modello-lettera') || '';
                    if (tipologia === "email" || !modelloLettera) {
                        avviaInvioEmail(id);
                    } else if (tipologia === "lettera" && modelloLettera) {
                        avviaGeneraLettera(id, modelloLettera);
                    } else {
                        showToast("Tipologia sconosciuta o dati mancanti.", "error");
                    }
                });
            });
            tbody.querySelectorAll('.btn-delete-protocollo').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    const id = this.getAttribute('data-id');
                    showConfirm(
                        "Sei sicuro di voler eliminare DEFINITIVAMENTE questa comunicazione/protocollo?<br>Questa azione non è reversibile.",
                        () => eliminaProtocollo(id),
                        { allowHtml: true }
                    );
                });
            });
        })
        .catch(err => {
            console.error("Errore caricamento archivio:", err);
        });
}

function avviaModificaProtocollo(id) {
    customFetch('protocollo_email', 'getArchivio', { pagina: 1, limite: 1, id: id })
        .then(response => {
            if (!response.success || !response.data || response.data.length === 0) {
                showToast('Errore nel caricamento della riga selezionata', 'error');
                return;
            }
            const row = response.data[0];

            // --- Compila campi singoli
            document.getElementById('protocol-editing-id').value = row.id || '';
            document.getElementById('project').value = row.commessa || '';

            // Gestione area (commessa/generale)
            const area = document.getElementById('area');
            if (area) {
                if (["GAR", "AMM", "OFF", "ACQ", "HRR", "SQQ", "GCO", "CON"].includes(row.commessa)) {
                    area.value = "generale";
                } else {
                    area.value = "commessa";
                }
                area.disabled = true;
            }
            // Custom select
            const custom = document.getElementById('custom-project-select');
            if (custom && custom.querySelector('.custom-select-placeholder')) {
                custom.querySelector('.custom-select-placeholder').textContent = (row.commessa || '');
                custom.classList.add('disabled');
                custom.style.pointerEvents = 'none';
                custom.style.opacity = '0.75';
            }
            const projectInput = document.getElementById('project');
            if (projectInput) projectInput.disabled = true;

            // Altri campi
            const mapSet = [
                ['cc', row.cc || ''],
                ['ccn', row.ccn || ''],
                ['subject', row.oggetto || ''],
                ['nuova-tipologia', row.tipologia || ''],
                ['final-code', row.protocollo || '']
            ];
            mapSet.forEach(([id, val]) => {
                const el = document.getElementById(id);
                if (el) el.value = val;
            });

            const showList = [
                'btn-salva-protocollo', 'btn-annulla-protocollo'
            ];
            showList.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = '';
            });
            const hideList = [
                'btn-genera', 'btn-generaEApri'
            ];
            hideList.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });

            const finalCode = document.getElementById('final-code');
            if (finalCode) finalCode.readOnly = true;

            // --- POPOLA TABELLA DESTINATARI ---
            const tbody = document.querySelector('#tabella-destinatari tbody');
            tbody.innerHTML = '';

            customFetch('protocollo_email', 'getDestinatariDettaglio', { protocollo_email_id: row.id })
                .then(resDett => {
                    const dettagli = (resDett.success && Array.isArray(resDett.data)) ? resDett.data : [];

                    // NON facciamo più join qui: usiamo direttamente i campi che arrivano
                    const destinatariRows = dettagli.map(d => ({
                        tipo: d.tipo || '',
                        azienda_id: d.azienda_id || '',
                        azienda: d.azienda || '(azienda sconosciuta)',
                        contatto_id: d.contatto_id || '',
                        email: d.contatto_email || d.email || '',
                        referente: d.nome_referente || ''
                    }));

                    // Completa con righe vuote
                    while (destinatariRows.length < 6) destinatariRows.push({});

                    destinatariRows.forEach(d => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                    <td>
                        <div class="custom-select-box destinatario-select-box" tabindex="0" style="width:100%;">
                        <span class="custom-select-placeholder">${d.azienda ? window.escapeHtml(d.azienda) : 'Seleziona destinatario'}</span>
                        <input type="hidden" value="${d.azienda_id || ''}" data-id="">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box referente-select-box" tabindex="0" style="width:100%;">
                        <span class="custom-select-placeholder">${d.referente || 'Seleziona referente'}</span>
                        <input type="hidden" value="${d.referente || ''}">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box contatto-select-box" tabindex="0" style="width:100%;">
                        <span class="custom-select-placeholder">${d.email || 'Seleziona contatto'}</span>
                        <input type="hidden" value="${d.contatto_id || ''}">
                        </div>
                    </td>
                    <td>
                        <div class="custom-select-box type-select-box" tabindex="0" style="width:100%;">
                        <span class="custom-select-placeholder">${d.tipo ? d.tipo.toUpperCase() : 'Seleziona tipo'}</span>
                        <input type="hidden" value="${d.tipo || ''}">
                        <div class="custom-select-dropdown" style="display:none;">
                            <div class="custom-select-option" data-value="to">TO</div>
                            <div class="custom-select-option" data-value="cc">CC</div>
                        </div>
                        </div>
                    </td>`;
                        tbody.appendChild(tr);
                    });

                    popolaTabellaDestinatari();
                });
        });
}

function caricaAziendeList() {
    const aziendeList = document.getElementById('aziende-list');
    if (!aziendeList) return;
    aziendeList.innerHTML = '';

    caricaAziendeCached(function (data) {
        if (Array.isArray(data)) {
            data.forEach(azienda => {
                if (!azienda.ragionesociale) return;
                if ([...aziendeList.options].some(opt => opt.value === azienda.ragionesociale)) return;

                const option = document.createElement('option');
                option.value = azienda.ragionesociale;
                option.textContent = azienda.ragionesociale;
                aziendeList.appendChild(option);
            });
        } else {
            showToast("Nessun destinatario disponibile", "error");
        }
    });
}


function caricaEmailList(azienda) {
    const emailList = document.getElementById('email-list');
    const contattoInput = document.getElementById('contatto');
    if (!emailList) return;
    emailList.innerHTML = '';
    if (!azienda) {
        if (contattoInput) contattoInput.disabled = true;
        return;
    }
    customFetch('protocollo_email', 'getContattiByAzienda', { azienda })
        .then(response => {
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(contatto => {
                    if (!contatto.email) return;
                    const option = document.createElement('option');
                    option.value = contatto.email;
                    option.textContent = contatto.email;
                    emailList.appendChild(option);
                });
                if (contattoInput) contattoInput.disabled = false;
            } else {
                showToast("Nessun contatto disponibile", "info");
                if (contattoInput) contattoInput.disabled = false;
            }
        })
        .catch(err => {
            if (contattoInput) contattoInput.disabled = false;
            showToast("Errore caricamento contatti", "error");
            console.error('Errore caricamento contatti:', err);
        });
}

function salvaProtocolloModificato() {
    console.log('⚡ CHIAMATA MODIFICA PROTOCOLLO');
    document.getElementById('btn-salva-protocollo').disabled = true;
    document.getElementById('btn-genera').disabled = true;
    document.getElementById('btn-generaEApri').disabled = true;

    const id = document.getElementById('protocol-editing-id').value;
    if (!id) {
        showToast('ID protocollo non trovato', 'error');
        // RIABILITA I BOTTONI
        document.getElementById('btn-salva-protocollo').disabled = false;
        document.getElementById('btn-genera').disabled = false;
        document.getElementById('btn-generaEApri').disabled = false;
        return;
    }

    const dati = raccogliDatiForm();
    dati.id = id;
    const destinatari = estraiDestinatariTabella();
    dati.a = Array.isArray(destinatari.to) ? destinatari.to.join(';') : (destinatari.to || '');
    dati.cc = Array.isArray(destinatari.cc) ? destinatari.cc.join(';') : (destinatari.cc || '');
    dati.ditta = destinatari.ditta || '';
    dati.contatto_referente = destinatari.contatto_referente || '';
    dati.nome_referente = destinatari.nome_referente || '';
    dati.destinatari_json = JSON.stringify(destinatari.destinatariDettaglio || []);

    customFetch('protocollo_email', 'modificaProtocollo', dati)
        .then(res => {
            // RIABILITA I BOTTONI
            document.getElementById('btn-salva-protocollo').disabled = false;
            document.getElementById('btn-genera').disabled = false;
            document.getElementById('btn-generaEApri').disabled = false;

            if (res && res.success) {
                showToast('Protocollo aggiornato con successo!', 'success');
                resetFormAndFields();
                updateArchiveTable();
            } else {
                showToast('Errore durante il salvataggio: ' + (res.error || 'Errore'), 'error');
            }
        })
        .catch(err => {
            // RIABILITA I BOTTONI
            document.getElementById('btn-salva-protocollo').disabled = false;
            document.getElementById('btn-genera').disabled = false;
            document.getElementById('btn-generaEApri').disabled = false;
            showToast('Errore di comunicazione con il server', 'error');
            console.error(err);
        });
}

function avviaInvioEmail(id) {
    // 1. Carica il dettaglio archivio (serve per compilare oggetto e altri campi)
    customFetch('protocollo_email', 'getArchivio', { id: id, pagina: 1, limite: 1 })
        .then(res => {
            if (!res || !res.success || !res.data || !res.data.length) {
                showToast("Errore nel recupero dati protocollo.", "error");
                return;
            }
            const row = res.data[0];

            // 2. Carica i destinatari dettagliati da archivio_email_destinatari!
            customFetch('protocollo_email', 'getDestinatariDettaglio', { protocollo_email_id: id })
                .then(resDett => {
                    // Trova i campi come farebbe il form
                    const dettagli = (resDett.success && Array.isArray(resDett.data)) ? resDett.data : [];

                    // Costruisci TO e CC in base al tipo
                    const toList = [];
                    const ccList = [];
                    let primaDitta = '';
                    let primoReferenteEmail = '';
                    let primoNomeReferente = '';
                    let destinatari_json = [];

                    dettagli.forEach(d => {
                        if (d.tipo === 'to') {
                            if (d.email && !toList.includes(d.email)) toList.push(d.email);
                        } else if (d.tipo === 'cc') {
                            if (d.email && !ccList.includes(d.email)) ccList.push(d.email);
                        }
                        if (!primaDitta && d.azienda_id) primaDitta = d.azienda_id;
                        if (!primoReferenteEmail && d.email) primoReferenteEmail = d.email;
                        if (!primoNomeReferente && d.nome_referente) primoNomeReferente = d.nome_referente;
                        destinatari_json.push({
                            azienda_id: d.azienda_id,
                            contatto_id: d.contatto_id,
                            referente_id: d.referente_id,
                            tipo: d.tipo,
                            email: d.email,
                            nome_referente: d.nome_referente
                        });
                    });

                    // 3. Prepara il payload CORRETTO per generaEApri!
                    const payload = {
                        id: row.id,
                        action: "generaEApri",
                        section: "protocollo_email",
                        commessa: row.commessa || "",
                        oggetto: row.oggetto || "",
                        a: toList.join(';'),
                        cc: ccList.join(';'),
                        ccn: "archivio_mail@incide.it",
                        ditta: primaDitta,
                        contatto_referente: primoReferenteEmail,
                        nome_referente: primoNomeReferente,
                        destinatari_json: JSON.stringify(destinatari_json),
                        tipologia: row.tipologia || "",
                        inviato_da: row.inviato_da || "",
                        modello: row.modello_lettera || ""
                    };

                    // 4. Invio della richiesta come fa il form!
                    customFetch('protocollo_email', 'generaEApri', payload)
                        .then(res2 => {
                            if (res2 && res2.success && res2.mailto) {
                                window.location.href = res2.mailto.replace(/\+/g, '%20');
                            } else if (res2 && res2.success && res2.url) {
                                window.open(res2.url, '_blank');
                            } else {
                                showToast("Errore nell'invio/generazione.", "error");
                            }
                        })
                        .catch(() => {
                            showToast("Errore durante la generazione.", "error");
                        });
                })
                .catch(() => {
                    showToast("Errore caricamento destinatari.", "error");
                });
        })
        .catch(() => {
            showToast("Errore nella richiesta dati.", "error");
        });
}

function avviaGeneraLettera(id, modelloLettera) {
    customFetch('protocollo_email', 'getArchivio', { id: id, pagina: 1, limite: 1 })
        .then(res => {
            if (!res || !res.success || !res.data || !res.data.length) {
                showToast("Errore nel recupero dati protocollo.", "error");
                return;
            }
            const row = res.data[0];
            customFetch('protocollo_email', 'generaEApri', {
                ...row,
                tipologia: 'lettera',
                modello: modelloLettera
            })
                .then(res2 => {
                    if (res2 && res2.success && res2.url) {
                        window.open(res2.url, '_blank');
                    } else {
                        showToast("Errore nella generazione della lettera.", "error");
                    }
                })
                .catch(() => {
                    showToast("Errore durante la generazione della lettera.", "error");
                });
        })
        .catch(() => {
            showToast("Errore nella richiesta dati.", "error");
        });
}

function eliminaProtocollo(id) {
    if (!id) {
        showToast("ID protocollo mancante.", "error");
        return;
    }
    customFetch('protocollo_email', 'eliminaProtocollo', { id: id })
        .then(res => {
            if (res && res.success) {
                showToast("Protocollo eliminato con successo.", "success");
                updateArchiveTable();
            } else {
                showToast("Errore durante l'eliminazione: " + (res && res.error ? res.error : "Errore"), "error");
            }
        })
        .catch(() => {
            showToast("Errore di comunicazione con il server.", "error");
        });
}

// =========== POPOLAMENTO TABELLA DESTINATARI =============

let contattiByAzienda = {};

function popolaTabellaDestinatari() {
    caricaAziendeCached(function (response) {
        destinatariList = Array.isArray(response) ? response : [];

        const opzioniAziende = destinatariList.map(a => ({
            value: a.id,
            label: a.ragionesociale
        }));

        document.querySelectorAll('.destinatario-select-box').forEach(box => {
            box.onclick = function (e) {
                showCustomDropdownInputLibero(box, opzioniAziende, {
                    placeholder: "Cerca azienda...",
                    valoreIniziale: box.querySelector('input[type=hidden]').value,
                    onSelect: (opt, inputVal, isFree) => {
                        if (isFree && inputVal && inputVal.trim().length > 1) {
                            // Usa funzione globale per aggiungere nuova azienda
                            window.showAddCompanyModal(inputVal, function (nuovaAzienda) {
                                if (nuovaAzienda) {
                                    const placeholder = box.querySelector('.custom-select-placeholder');
                                    if (placeholder) placeholder.textContent = nuovaAzienda.ragionesociale;

                                    const hiddenInput = box.querySelector('input[type=hidden]');
                                    if (hiddenInput) hiddenInput.value = nuovaAzienda.id || '';

                                    // Reset referente e contatto
                                    const row = box.closest('tr');
                                    if (row) {
                                        const referenteBox = row.querySelector('.referente-select-box');
                                        const contattoBox = row.querySelector('.contatto-select-box');
                                        if (referenteBox) {
                                            referenteBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona referente';
                                            referenteBox.querySelector('input[type=hidden]').value = '';
                                        }
                                        if (contattoBox) {
                                            contattoBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
                                            contattoBox.querySelector('input[type=hidden]').value = '';
                                        }
                                        if (nuovaAzienda.id) {
                                            setupDropdownContattiReferenti(row, nuovaAzienda.id);
                                        }
                                    }
                                }
                            });
                            return;
                        }
                        if (opt) {
                            box.querySelector('.custom-select-placeholder').textContent = opt.label;
                            box.querySelector('input[type=hidden]').value = opt.value;
                            // Reset anche referente e contatto QUANDO CAMBI DESTINATARIO
                            const row = box.closest('tr');
                            const referenteBox = row.querySelector('.referente-select-box');
                            const contattoBox = row.querySelector('.contatto-select-box');
                            if (referenteBox) {
                                referenteBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona referente';
                                referenteBox.querySelector('input[type=hidden]').value = '';
                            }
                            if (contattoBox) {
                                contattoBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
                                contattoBox.querySelector('input[type=hidden]').value = '';
                            }
                            setupDropdownContattiReferenti(row, opt.value);
                        }
                    }
                });
            };
        });

        // Reset di default (solo se NON sei in modifica protocollo)
        const isEditing = !!(document.getElementById('protocol-editing-id') && document.getElementById('protocol-editing-id').value);
        if (!isEditing) {
            document.querySelectorAll('.contatto-select-box').forEach(box => {
                box.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
                box.querySelector('input[type=hidden]').value = '';
            });
            document.querySelectorAll('.referente-select-box').forEach(box => {
                box.querySelector('.custom-select-placeholder').textContent = 'Seleziona referente';
                box.querySelector('input[type=hidden]').value = '';
            });
        }

        // Dropdown TIPO (TO/CC)
        document.querySelectorAll('.type-select-box').forEach(box => {
            box.onclick = function (e) {
                const dropdown = box.querySelector('.custom-select-dropdown');
                if (dropdown.style.display === "block") {
                    dropdown.style.display = "none";
                    box.classList.remove('open');
                    return;
                }
                document.querySelectorAll('.type-select-box .custom-select-dropdown').forEach(dd => dd.style.display = 'none');
                box.classList.add('open');
                dropdown.style.display = "block";
            };
            // Gestione selezione tipo
            box.querySelectorAll('.custom-select-option').forEach(opt => {
                opt.onclick = function (ev) {
                    box.querySelector('.custom-select-placeholder').textContent = opt.textContent;
                    box.querySelector('input[type=hidden]').value = opt.dataset.value;
                    box.querySelector('.custom-select-dropdown').style.display = "none";
                    box.classList.remove('open');
                    ev.stopPropagation();
                };
            });
        });
    });
}

// Funzione che popola i dropdown contatto/referente dato l'azienda_id (robusto e BLINDATO, nessuna cache globale)
function setupDropdownContattiReferenti(row, aziendaId) {
    const contattoBox = row.querySelector('.contatto-select-box');
    const referenteBox = row.querySelector('.referente-select-box');
    // Pulizia dropdown SEMPRE, anche se non c'è azienda
    if (contattoBox) {
        contattoBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
        contattoBox.querySelector('input[type=hidden]').value = '';
    }
    if (referenteBox) {
        referenteBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona referente';
        referenteBox.querySelector('input[type=hidden]').value = '';
    }
    if (!aziendaId) return;

    // NIENTE cache globale: carica SOLO i contatti per questa azienda ogni volta!
    // USA servizio unificato 'contacts' / 'getCompanyContacts'
    customFetch('contacts', 'getCompanyContacts', { azienda_id: aziendaId })
        .then(res => {
            const data = (res.success && Array.isArray(res.data)) ? res.data : [];
            renderContattoReferenteDropdowns(contattoBox, referenteBox, data);
        })
        .catch(() => {
            if (contattoBox) {
                contattoBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
                contattoBox.querySelector('input[type=hidden]').value = '';
            }
            if (referenteBox) {
                referenteBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona referente';
                referenteBox.querySelector('input[type=hidden]').value = '';
            }
        });
}

function renderContattoReferenteDropdowns(contattoBox, referenteBox, data) {
    // Prepara opzioni per i dropdown
    const opzioniReferenti = data.map(c => ({
        value: c.id,
        // Supporta sia nomeCompleto (nuovo servizio) che cognome_e_nome (vecchio o raw)
        label: c.nomeCompleto || c.cognome_e_nome || ''
    }));
    const opzioniEmail = data.map(c => ({
        value: c.id,
        label: c.email
    }));

    referenteBox.onclick = function (e) {
        showCustomDropdownInputLibero(referenteBox, opzioniReferenti, {
            placeholder: "Cerca referente...",
            valoreIniziale: referenteBox.querySelector('input[type=hidden]').value,
            onSelect: (opt, inputVal, isFree) => {
                if (isFree && inputVal) {
                    // Usa funzione globale per aggiungere nuovo contatto
                    const tr = referenteBox.closest('tr');
                    const aziendaId = tr.querySelector('.destinatario-select-box input[type=hidden]').value || null;
                    window.showAddContactModal(inputVal, aziendaId, '', function (nuovoContatto) {
                        if (nuovoContatto) {
                            // Aggiorna referente
                            referenteBox.querySelector('.custom-select-placeholder').textContent = nuovoContatto.nomeCompleto || (nuovoContatto.cognome + ' ' + nuovoContatto.nome);
                            referenteBox.querySelector('input[type=hidden]').value = nuovoContatto.id || '';
                            // Aggiorna contatto/email
                            if (nuovoContatto.email) {
                                contattoBox.querySelector('.custom-select-placeholder').textContent = nuovoContatto.email;
                                contattoBox.querySelector('input[type=hidden]').value = nuovoContatto.id || '';
                            }
                        }
                    });
                    return;
                }
                // Se selezionato da elenco: opzioni già filtrate su azienda, non serve altro
                const referente = data.find(c => String(c.id) === String(opt.value));
                referenteBox.querySelector('.custom-select-placeholder').textContent = referente ? (referente.nomeCompleto || referente.cognome_e_nome) : 'Seleziona referente';
                referenteBox.querySelector('input[type=hidden]').value = referente ? referente.id : '';

                if (referente && referente.email) {
                    contattoBox.querySelector('.custom-select-placeholder').textContent = referente.email;
                    contattoBox.querySelector('input[type=hidden]').value = referente.id;
                } else {
                    contattoBox.querySelector('.custom-select-placeholder').textContent = 'Seleziona contatto';
                    contattoBox.querySelector('input[type=hidden]').value = '';
                }
            }
        });
    };

    // Dropdown CONTATTO/EMAIL
    contattoBox.onclick = function (e) {
        showCustomDropdownInputLibero(contattoBox, opzioniEmail, {
            placeholder: "Cerca contatto...",
            valoreIniziale: contattoBox.querySelector('input[type=hidden]').value,
            onSelect: (opt, inputVal, isFree) => {
                if (isFree && inputVal) {
                    // Usa funzione globale per aggiungere nuovo contatto
                    const tr = contattoBox.closest('tr');
                    const aziendaId = tr.querySelector('.destinatario-select-box input[type=hidden]').value || null;
                    window.showAddContactModal('', aziendaId, inputVal, function (nuovoContatto) {
                        if (nuovoContatto) {
                            // Aggiorna contatto/email
                            if (nuovoContatto.email) {
                                contattoBox.querySelector('.custom-select-placeholder').textContent = nuovoContatto.email;
                                contattoBox.querySelector('input[type=hidden]').value = nuovoContatto.id || '';
                            }
                            // Aggiorna referente
                            referenteBox.querySelector('.custom-select-placeholder').textContent = nuovoContatto.nomeCompleto || (nuovoContatto.cognome + ' ' + nuovoContatto.nome);
                            referenteBox.querySelector('input[type=hidden]').value = nuovoContatto.id || '';
                        }
                    });
                    return;
                }
                // Selezionato da elenco
                contattoBox.querySelector('.custom-select-placeholder').textContent = opt ? opt.label : 'Seleziona contatto';
                contattoBox.querySelector('input[type=hidden]').value = opt ? opt.value : '';

                const referente = data.find(c => String(c.id) === String(opt ? opt.value : ''));
                if (referente) {
                    referenteBox.querySelector('.custom-select-placeholder').textContent = referente.nomeCompleto || referente.cognome_e_nome;
                    referenteBox.querySelector('input[type=hidden]').value = referente.id;
                }
            }
        });
    };
}

// NOTA: showCustomDropdownInputLibero è ora definita in autocomplete_manager.js
// ed esposta su window.showCustomDropdownInputLibero per essere usata globalmente

// DEPRECATO: Usa window.showAddContactModal invece
// Manteniamo per compatibilità con codice esistente
function apriModaleNuovoContatto(nomeDefault, contattoBox, referenteBox, aziendaId, emailDefault) {
    window.showAddContactModal(nomeDefault, aziendaId, emailDefault || '', function (nuovoContatto) {
        if (nuovoContatto) {
            // Aggiorna dropdown contatto/email
            if (contattoBox && nuovoContatto.email) {
                const contPlaceholder = contattoBox.querySelector('.custom-select-placeholder');
                const contInput = contattoBox.querySelector('input[type=hidden]');
                if (contPlaceholder) contPlaceholder.textContent = nuovoContatto.email;
                if (contInput) contInput.value = nuovoContatto.id || '';
            }
            // Aggiorna dropdown referente
            if (referenteBox) {
                const refPlaceholder = referenteBox.querySelector('.custom-select-placeholder');
                const refInput = referenteBox.querySelector('input[type=hidden]');
                const nomeCompleto = nuovoContatto.nomeCompleto || (nuovoContatto.cognome + ' ' + nuovoContatto.nome);
                if (refPlaceholder) refPlaceholder.textContent = nomeCompleto;
                if (refInput) refInput.value = nuovoContatto.id || '';
            }
            // Ricarica dropdown con i nuovi dati
            if (aziendaId && contattoBox && referenteBox) {
                customFetch('contacts', 'getCompanyContacts', { azienda_id: aziendaId }).then(r => {
                    const data = (r.success && Array.isArray(r.data)) ? r.data : [];
                    renderContattoReferenteDropdowns(contattoBox, referenteBox, data);
                });
            }
        }
    });
}

function apriModaleNuovoDestinatario(nomeDefault, destinatarioBox) {
    const modal = document.getElementById('modal-nuovo-destinatario');
    modal.style.display = 'block';

    document.getElementById('dest-ragione').value = nomeDefault || '';
    document.getElementById('dest-piva').value = '';
    document.getElementById('dest-citta').value = '';
    document.getElementById('dest-email').value = '';
    document.getElementById('dest-tel').value = '';

    const form = document.getElementById('nuovo-destinatario-form');
    form.onsubmit = function (ev) {
        ev.preventDefault();
        const ragione = document.getElementById('dest-ragione').value.trim();
        if (!ragione) {
            showToast("Inserire la ragione sociale.", "error");
            return;
        }
        const dati = {
            ragionesociale: ragione,
            partitaiva: document.getElementById('dest-piva').value.trim(),
            citta: document.getElementById('dest-citta').value.trim(),
            email: document.getElementById('dest-email').value.trim(),
            telefono: document.getElementById('dest-tel').value.trim()
        };
        customFetch('protocollo_email', 'aggiungiAzienda', dati).then(res => {
            if (res && res.success) {
                showToast("Destinatario (azienda) aggiunta!", "success");
                resetAziendeContattiCache();
                modal.style.display = 'none';
                // Aggiorna dropdown con nuova azienda e seleziona subito
                caricaAziendeCached(function (list) {
                    if (!Array.isArray(list)) return;
                    const nuovo = list.find(a => (a.ragionesociale || '').toLowerCase() === ragione.toLowerCase());
                    if (nuovo && destinatarioBox) {
                        const placeholder = destinatarioBox.querySelector('.custom-select-placeholder');
                        if (placeholder) placeholder.textContent = nuovo.ragionesociale;

                        // Supporta sia protocollo_email (input[type=hidden]) che commessa_chiusura (input[name="societa_id[]"])
                        const hiddenInput = destinatarioBox.querySelector('input[type=hidden]');
                        const societaIdInput = destinatarioBox.querySelector('input[name="societa_id[]"]');
                        const societaNomeInput = destinatarioBox.querySelector('input[name="societa_nome[]"]');

                        if (hiddenInput) {
                            hiddenInput.value = nuovo.id;
                        }
                        if (societaIdInput) {
                            societaIdInput.value = nuovo.id || '';
                        }
                        if (societaNomeInput) {
                            societaNomeInput.value = nuovo.ragionesociale;
                        }

                        // Reset dei referenti/contatti come da flusso normale (solo per protocollo_email)
                        const row = destinatarioBox.closest('tr');
                        if (row) {
                            const referenteBox = row.querySelector('.referente-select-box');
                            const contattoBox = row.querySelector('.contatto-select-box');
                            if (referenteBox) {
                                const refPlaceholder = referenteBox.querySelector('.custom-select-placeholder');
                                const refInput = referenteBox.querySelector('input[type=hidden]');
                                if (refPlaceholder) refPlaceholder.textContent = 'Seleziona referente';
                                if (refInput) refInput.value = '';
                            }
                            if (contattoBox) {
                                const contPlaceholder = contattoBox.querySelector('.custom-select-placeholder');
                                const contInput = contattoBox.querySelector('input[type=hidden]');
                                if (contPlaceholder) contPlaceholder.textContent = 'Seleziona contatto';
                                if (contInput) contInput.value = '';
                            }
                            // Setup dropdown contatti/referenti solo se la funzione esiste (protocollo_email)
                            if (typeof setupDropdownContattiReferenti === 'function') {
                                setupDropdownContattiReferenti(row, nuovo.id);
                            }
                            // Per commessa_chiusura: ricarica autocompletamento
                            if (typeof setupSocietaAutocomplete === 'function') {
                                setupSocietaAutocomplete();
                            }
                        }
                    }
                });
            } else {
                showToast("Errore salvataggio: " + (res && res.error ? res.error : "Errore"), "error");
            }
        });
    };

    // Annulla: resetta e chiudi
    document.getElementById('btn-cancella-nuovo-destinatario').onclick = function () {
        modal.style.display = 'none';
    };
}

// Espone funzione canonica globalmente per riuso in altre pagine
if (typeof window !== 'undefined') {
    window.apriModaleNuovoDestinatario = apriModaleNuovoDestinatario;
}

// Inizializza su DOMContentLoaded
document.addEventListener("DOMContentLoaded", function () {
    // Verifica che siamo nella pagina protocollo_email prima di inizializzare
    const protocolTableBody = document.getElementById('protocolTable_body');
    if (!protocolTableBody) {
        // Non siamo nella pagina protocollo_email, esci silenziosamente
        return;
    }

    popolaTabellaDestinatari();

    const seg = document.getElementById('arch-segment');
    const modeInput = document.getElementById('arch-mode');
    if (seg && modeInput) {
        seg.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                seg.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                modeInput.value = btn.getAttribute('data-mode') || 'tutte';
                updateArchiveTable();
            });
        });
    }

    updateArchiveTable();
});

function estraiDestinatariTabella() {
    const toList = [];
    const ccList = [];
    let primaDitta = '';
    let primoReferenteEmail = '';
    let primoNomeReferente = '';
    const emailSet = new Set();

    // PATCH: salva anche struttura dettagliata
    let destinatariDettaglio = [];

    const rows = document.querySelectorAll('#tabella-destinatari tbody tr');
    rows.forEach(row => {
        // Estrai ID azienda
        const aziendaId = row.querySelector('.destinatario-select-box input[type=hidden]')?.value?.trim() || '';
        const referenteId = row.querySelector('.referente-select-box input[type=hidden]')?.value?.trim() || '';
        let nomeReferente = row.querySelector('.referente-select-box .custom-select-placeholder')?.textContent?.trim() || '';
        if (nomeReferente === 'Seleziona referente') nomeReferente = '';
        const contattoId = row.querySelector('.contatto-select-box input[type=hidden]')?.value?.trim() || '';
        const email = row.querySelector('.contatto-select-box .custom-select-placeholder')?.textContent?.trim() || '';
        let tipo = row.querySelector('.type-select-box input[type="hidden"]')?.value?.trim() || '';

        if (aziendaId || contattoId || tipo) {
            destinatariDettaglio.push({
                azienda_id: aziendaId,
                contatto_id: contattoId,
                referente_id: referenteId,
                tipo: tipo,
                email: email,
                nome_referente: nomeReferente
            });
        }

        if (tipo && email && email !== 'Seleziona contatto') {
            if (!primaDitta) primaDitta = aziendaId;
            if (!primoReferenteEmail) primoReferenteEmail = email;
            if (!primoNomeReferente) primoNomeReferente = nomeReferente;
            if (!emailSet.has(email)) {
                if (tipo === "to") toList.push(email);
                else if (tipo === "cc") ccList.push(email);
                emailSet.add(email);
            }
        }
    });

    // RESTITUISCI SIA IL VECCHIO CHE IL NUOVO FORMATO
    return {
        to: toList,
        cc: ccList,
        ditta: primaDitta,
        contatto_referente: primoReferenteEmail,
        nome_referente: primoNomeReferente,
        destinatariDettaglio
    };
}

function resetAziendeContattiCache() {
    aziendeCache = null;
    contattiCache = null;
}

// Espone funzione canonica globalmente per riuso in altre pagine
if (typeof window !== 'undefined') {
    window.resetAziendeContattiCache = resetAziendeContattiCache;
}
