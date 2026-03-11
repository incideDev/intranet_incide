<form id="form-certificato" class="chiusura-form" onsubmit="return false;">
    <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella ?? '') ?>">

    <!-- Blocco 1: Dati generali del contratto -->
    <fieldset class="chiusura-fieldset">
        <legend>Dati generali del contratto</legend>

        <!-- Sotto-sezione: Intestazioni / Protocollo -->
        <div class="chiusura-section">
            <h3>Intestazioni / Protocollo</h3>
            <div class="form-grid form-grid-3cols">
                <div class="form-group">
                    <label for="luogo_data_lettera">Luogo e data</label>
                    <input type="text" id="luogo_data_lettera" name="luogo_data_lettera"
                        value="<?= htmlspecialchars($luogo_data_lettera ?? '') ?>">
                </div>
                <!-- CAMPO RIMOSSO 2025-01-14: protocollo_numero non più necessario nel template Word -->
                <!-- <div class="form-group">
              <label for="protocollo_numero">Protocollo numero</label>
              <input type="text" id="protocollo_numero" name="protocollo_numero" value="">
            </div> -->
                <div class="form-group">
                    <label for="destinatario_spettabile">Destinatario
                        (Spettabile)</label>
                    <input type="text" id="destinatario_spettabile" name="destinatario_spettabile"
                        value="<?= htmlspecialchars($ragione_sociale_committente ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="destinatario_pec_email">Destinatario -
                        PEC/Email</label>
                    <input type="text" id="destinatario_pec_email" name="destinatario_pec_email"
                        value="<?= htmlspecialchars($pec_email_committente ?? '') ?>">
                </div>
                <div class="form-group form-group-span-2">
                    <label for="destinatario_indirizzo">Destinatario - Indirizzo</label>
                    <input type="text" id="destinatario_indirizzo" name="destinatario_indirizzo"
                        value="<?= htmlspecialchars($indirizzo_committente ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- INTESTAZIONE COMMITTENTE -->
        <div class="chiusura-section">
            <h3>INTESTAZIONE COMMITTENTE</h3>
            <div class="form-grid">
                <!-- CAMPO RIMOSSO 2025-01-14: intestazione_committente non più necessario nel template Word -->
                <!-- <div class="form-group">
              <label for="intestazione_committente">Intestazione committente</label>
              <input type="text" id="intestazione_committente" name="intestazione_committente"
                value="<?= htmlspecialchars($anagrafica_committente['ragionesociale'] ?? $datiPrecompilazione['committente'] ?? '') ?>">
            </div> -->
                <div class="form-group">
                    <label for="indirizzo_committente">Indirizzo committente</label>
                    <input type="text" id="indirizzo_committente" name="indirizzo_committente"
                        value="<?= htmlspecialchars($indirizzo_committente ?? '') ?>">
                </div>
                <!-- CAMPO RIMOSSO 2025-01-14: rup_riferimento non più necessario nel template Word -->
                <!-- <div class="form-group">
              <label for="rup_riferimento">RUP - Riferimento</label>
              <input type="text" id="rup_riferimento" name="rup_riferimento" value="">
            </div> -->
                <!-- CAMPO RIMOSSO 2025-01-14: oggetto_lettera non più necessario nel template Word -->
                <!-- <div class="form-group form-group-full-width">
              <label for="oggetto_lettera">Oggetto lettera</label>
              <textarea id="oggetto_lettera" name="oggetto_lettera"
                rows="1"><?= htmlspecialchars($datiPrecompilazione['oggetto_lettera'] ?? '') ?></textarea>
            </div> -->
            </div>
        </div>

        <div class="form-grid form-grid-3cols">
            <div class="form-group">
                <label for="codice_commessa">Codice Commessa <span class="required">*</span></label>
                <?php
                $codicePrecompilato = $datiPrecompilazione['codice_commessa'] ?? $commessa['codice_commessa'] ?? '';
                $isReadOnly = !empty($codicePrecompilato);
                ?>
                <input type="text" id="codice_commessa" name="codice_commessa"
                    value="<?= htmlspecialchars($codicePrecompilato) ?>" placeholder="Cerca o inserisci codice..."
                    <?= $isReadOnly ? 'readonly' : '' ?> required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="committente">Committente dell'opera <span class="required">*</span></label>
                <input type="text" id="committente" name="committente"
                    value="<?= htmlspecialchars($datiPrecompilazione['committente'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="titolo_progetto">Titolo / Nome progetto <span class="required">*</span></label>
                <input type="text" id="titolo_progetto" name="titolo_progetto"
                    value="<?= htmlspecialchars($datiPrecompilazione['titolo_progetto'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="riferimento_contratto">Estremi del contratto /
                    Rif. contratto <span class="required">*</span></label>
                <input type="text" id="riferimento_contratto" name="riferimento_contratto"
                    value="<?= htmlspecialchars($datiPrecompilazione['riferimento_contratto'] ?? '') ?>" required>
            </div>
            <div class="form-group form-group-full-width">
                <label for="oggetto_contratto">Oggetto del contratto <span class="required">*</span></label>
                <textarea id="oggetto_contratto" name="oggetto_contratto" rows="1"
                    required><?= htmlspecialchars($datiPrecompilazione['oggetto_contratto'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="cig">CIG</label>
                <input type="text" id="cig" name="cig"
                    value="<?= htmlspecialchars($datiPrecompilazione['cig'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="cup">CUP</label>
                <input type="text" id="cup" name="cup"
                    value="<?= htmlspecialchars($datiPrecompilazione['cup'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="importo_prestazioni">Importo prestazioni (CNIA,
                    IVA esclusa)</label>
                <input type="text" id="importo_prestazioni" name="importo_prestazioni" class="importo-money"
                    value="<?= htmlspecialchars($datiPrecompilazione['importo_prestazioni'] ?? '') ?>"
                    placeholder="0,00">
            </div>
            <div class="form-group">
                <label for="rup_nome">Responsabile del Procedimento (RUP)
                    <span class="required">*</span></label>
                <input type="text" id="rup_nome" name="rup_nome"
                    value="<?= htmlspecialchars($datiPrecompilazione['rup_nome'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="data_inizio_prestazione">Inizio prestazione
                    <span class="required">*</span></label>
                <input type="date" id="data_inizio_prestazione" name="data_inizio_prestazione"
                    value="<?= htmlspecialchars($datiPrecompilazione['data_inizio_prestazione'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="data_fine_prestazione">Conclusione prestazione
                    <span class="required">*</span></label>
                <input type="date" id="data_fine_prestazione" name="data_fine_prestazione"
                    value="<?= htmlspecialchars($datiPrecompilazione['data_fine_prestazione'] ?? '') ?>" required>
            </div>
        </div>
    </fieldset>

    <!-- Wrapper per i due fieldset affiancati -->
    <div class="chiusura-fieldset-row">
        <!-- Blocco Fasi di progettazione -->
        <fieldset class="chiusura-fieldset">
            <legend>FASE DI PROGETTAZIONE SVOLTA</legend>
            <div class="form-grid form-grid-2cols">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_sf" id="fase_sf" value="1">
                        <span>Studio di fattibilità (SF)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_pp" id="fase_pp" value="1">
                        <span>Progetto Preliminare (PP)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_pd" id="fase_pd" value="1">
                        <span>Progetto Definitivo (PD)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_pfte" id="fase_pfte" value="1">
                        <span>Progetto di fattibilità tecnico ed economica (PFTE)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_pe" id="fase_pe" value="1">
                        <span>Progetto Esecutivo (PE)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_dl" id="fase_dl" value="1">
                        <span>Direzione Lavori (DL)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_dos" id="fase_dos" value="1">
                        <span>Direzione Operativa strutture (DOS)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_doi" id="fase_doi" value="1">
                        <span>Direzione Operativa impianti (DOI)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_da" id="fase_da" value="1">
                        <span>Direzione artistica (DA)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_csp" id="fase_csp" value="1">
                        <span>Coordinamento Sicurezza in Fase progettazione (CSP)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="fase_cse" id="fase_cse" value="1">
                        <span>Coordinamento Sicurezza in Fase esecuzione (CSE)</span>
                    </label>
                </div>
            </div>
        </fieldset>

        <!-- Blocco Attività accessorie -->
        <fieldset class="chiusura-fieldset">
            <legend>ATTIVITÀ ACCESSORIE</legend>
            <div class="form-grid form-grid-2cols">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="att_bim" id="att_bim" value="1"
                            <?= (isset($commessa['business_unit']) && stripos($commessa['business_unit'], 'BIM') !== false) ? 'checked' : '' ?>>
                        <span>Progettazione in BIM</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="att_cam_dnsh" id="att_cam_dnsh" value="1">
                        <span>Progetto redatto in conformità ai CAM / DNSH</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="att_antincendio" id="att_antincendio" value="1">
                        <span>Progettazione antincendio</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="att_acustica" id="att_acustica" value="1">
                        <span>Progettazione acustica</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="att_relazione_geologica" id="att_relazione_geologica" value="1">
                        <span>Relazione Geologica</span>
                    </label>
                </div>
            </div>
        </fieldset>
    </div>

    <!-- Blocco Importo dei lavori -->
    <fieldset class="chiusura-fieldset">
        <legend>IMPORTO DEI LAVORI</legend>
        <div class="form-grid form-grid-3cols-fixed">
            <div class="form-group">
                <label for="importo_lavori_esclusi_oneri">Importo lavori (esclusi oneri)</label>
                <input type="text" id="importo_lavori_esclusi_oneri" name="importo_lavori_esclusi_oneri"
                    class="importo-money" value="" placeholder="0,00">
            </div>
            <div class="form-group">
                <label for="oneri_sicurezza">Oneri sicurezza</label>
                <input type="text" id="oneri_sicurezza" name="oneri_sicurezza" class="importo-money" value=""
                    placeholder="0,00">
            </div>
            <div class="form-group">
                <label for="importo_lavori_totale">Importo lavori
                    totale</label>
                <input type="text" id="importo_lavori_totale" name="importo_lavori_totale" class="importo-money"
                    value="" placeholder="0,00">
            </div>
        </div>
    </fieldset>

    <!-- Blocco Partecipanti -->
    <fieldset class="chiusura-fieldset">
        <legend>Soggetti partecipanti</legend>

        <input type="hidden" id="societa_incaricata" name="societa_incaricata"
            value="<?= htmlspecialchars($datiPrecompilazione['societa_incaricata'] ?? '') ?>">
        <div class="form-grid partecipanti-grid">
            <div class="form-group">
                <label for="societa_sede_legale">Società - Sede legale</label>
                <input type="text" id="societa_sede_legale" name="societa_sede_legale"
                    value="<?= htmlspecialchars($indirizzo_incide ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="societa_cf_piva">Società - CF/PIVA</label>
                <input type="text" id="societa_cf_piva" name="societa_cf_piva"
                    value="<?= htmlspecialchars($cf_piva_incide ?? '') ?>">
            </div>
        </div>
        <div class="partecipanti-layout">
            <div class="form-group">
                <label>Tipo incarico <span class="required">*</span></label>
                <div class="radio-group">
                    <label>
                        <input type="radio" name="tipo_incarico" value="singolo" checked required>
                        <span>Singolo</span>
                    </label>
                    <label>
                        <input type="radio" name="tipo_incarico" value="rtp" required>
                        <span>RTP</span>
                    </label>
                </div>
            </div>

            <!-- Wrapper per header + righe partecipanti -->
            <div class="partecipanti-table-wrapper">
                <!-- Header colonne partecipanti (visibile solo in RTP con più righe) -->
                <div id="partecipanti-header" class="partecipanti-header hidden">
                    <div class="partecipanti-row-grid partecipanti-row-grid-with-capogruppo">
                        <div class="partecipanti-capogruppo-group">
                            <span class="column-header">Capogruppo</span>
                        </div>
                        <div class="partecipanti-societa-group">
                            <span class="column-header">Società <span class="required">*</span></span>
                        </div>
                        <div class="partecipanti-percentuale-group">
                            <span class="column-header">% <span class="required">*</span></span>
                        </div>
                        <div class="partecipanti-actions-group">
                            <span class="column-header"></span>
                        </div>
                    </div>
                </div>

                <div id="partecipanti-container" class="partecipanti-container">
                    <!-- Righe partecipanti dinamiche -->
                    <div class="partecipante-row" data-row-index="0">
                        <div class="partecipanti-row-grid">
                            <!-- Radio capogruppo (nascosto in modalità singolo) -->
                            <div class="form-group partecipanti-capogruppo-group hidden">
                                <input type="radio" name="capogruppo" value="0" checked>
                            </div>
                            <div class="form-group partecipanti-societa-group">
                                <label for="societa_0" class="single-row-label">Società <span
                                        class="required">*</span></label>
                                <div class="custom-select-box societa-select-box" id="societa-select-0">
                                    <div class="custom-select-placeholder">
                                        <?= htmlspecialchars($datiPrecompilazione['societa_incaricata'] ?? '') ?>
                                    </div>
                                    <input type="hidden" name="societa_id[]" id="societa_id_0" value="">
                                    <input type="hidden" name="societa_nome[]" id="societa_nome_0"
                                        value="<?= htmlspecialchars($datiPrecompilazione['societa_incaricata'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="form-group partecipanti-percentuale-group">
                                <label for="percentuale_0" class="single-row-label">% <span
                                        class="required">*</span></label>
                                <input type="number" id="percentuale_0" name="percentuale[]" value="100" min="0"
                                    max="100" step="0.01" readonly required>
                            </div>
                            <div class="form-group partecipanti-actions-group">
                                <button type="button" class="btn-add-row btn-add-partecipante hidden"
                                    data-tooltip="Aggiungi partecipante">+</button>
                                <button type="button" class="btn-remove-row btn-remove-partecipante hidden"
                                    data-tooltip="Rimuovi partecipante">×</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </fieldset>

    <!-- Blocco 2: Categorie d'opera -->
    <fieldset class="chiusura-fieldset">
        <legend>Categorie d'opera (da computo metrico)</legend>
        <div class="table-wrapper">
            <table class="table-compact table-filterable" id="table-categorie-opera"
                data-table-key="chiusura_commessa_categorie">
                <thead>
                    <tr>
                        <th>Categoria (ID)</th>
                        <th>Descrizione</th>
                        <th>importo lavori</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="cat-opera-body">
                    <!-- Righe dinamiche via JS -->
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-right font-bold">Totale categorie:</td>
                        <td id="totale-categorie" class="font-bold">0 €</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <button type="button" id="btn-add-cat" class="button">+ Aggiungi categoria</button>
    </fieldset>

    <!-- Blocco 3: Suddivisione del servizio -->
    <fieldset class="chiusura-fieldset">
        <legend>Suddivisione del servizio</legend>
        <div class="table-wrapper">
            <table class="table-compact table-filterable" id="table-suddivisione-servizio"
                data-table-key="chiusura_commessa_suddivisione">
                <thead>
                    <tr>
                        <th>Società</th>
                        <th>Categoria (ID)</th>
                        <th>%</th>
                        <th>Servizi svolti</th>
                        <th>Importo (€)</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="suddivisione-servizio-body">
                    <!-- Righe dinamiche via JS -->
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right font-bold">Totale allocato:</td>
                        <td id="totale-soggetti" class="font-bold">0 €</td>
                        <td></td>
                    </tr>
                    <tr id="validazione-coerenza-row" class="hidden">
                        <td colspan="6" class="validazione-coerenza-cell">
                            <div id="validazione-coerenza-content"></div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <button type="button" id="btn-add-soggetto" class="button">+ Aggiungi soggetto</button>
    </fieldset>

    <!-- Blocco Incarico svolto da (MODIFICATO 2025-01-14: aggiunto Società e Qualifica) -->
    <fieldset class="chiusura-fieldset">
        <legend>Incarico svolto da</legend>
        <div id="incarico-container" class="incarico-container">
            <!-- Header colonne (visibile solo con 2+ righe) -->
            <div class="incarico-header hidden" id="incarico-header">
                <div class="incarico-row-grid">
                    <span class="column-header">Tecnico <span class="required">*</span></span>
                    <span class="column-header">Ruolo incaricato <span class="required">*</span></span>
                    <span class="column-header">Società <span class="required">*</span></span>
                    <span class="column-header">Qualifica <span class="required">*</span></span>
                    <span class="column-header"></span>
                </div>
            </div>

            <!-- Prima riga incarico -->
            <div class="incarico-row" data-row-index="0">
                <div class="incarico-row-grid">
                    <!-- Col 1: Tecnico (autocomplete personale) -->
                    <div class="form-group">
                        <label for="incarico_nome_0" class="single-row-label">Tecnico <span
                                class="required">*</span></label>
                        <div class="custom-select-box personale-select-box" id="personale-select-0"
                            data-autocomplete="personale">
                            <div class="custom-select-placeholder">Seleziona tecnico...</div>
                            <input type="hidden" name="incarico_nome[]" id="incarico_nome_0" value="">
                        </div>
                    </div>

                    <!-- Col 2: Ruolo incaricato (select predefinito) -->
                    <div class="form-group">
                        <label for="incarico_ruolo_0" class="single-row-label">Ruolo incaricato <span
                                class="required">*</span></label>
                        <select name="incarico_ruolo[]" id="incarico_ruolo_0" required>
                            <option value="">Seleziona ruolo...</option>
                            <optgroup label="Progettazione">
                                <option value="Progettista incaricato">Progettista incaricato</option>
                                <option value="Progettista architettonico">Progettista architettonico</option>
                                <option value="Progettista strutturale">Progettista strutturale</option>
                                <option value="Progettista impianti">Progettista impianti</option>
                            </optgroup>
                            <optgroup label="Direzione Lavori">
                                <option value="Direttore dei Lavori incaricato">Direttore dei Lavori incaricato</option>
                                <option value="Direttore Operativo - strutture">Direttore Operativo - strutture</option>
                                <option value="Direttore Operativo - impianti">Direttore Operativo - impianti</option>
                            </optgroup>
                            <optgroup label="Sicurezza">
                                <option value="Coordinatore della sicurezza in fase di progettazione (CSP)">CSP -
                                    Coordinatore
                                    sicurezza progettazione</option>
                                <option value="Coordinatore della sicurezza in fase di esecuzione (CSE)">CSE -
                                    Coordinatore
                                    sicurezza esecuzione</option>
                            </optgroup>
                            <optgroup label="Altro">
                                <option value="Geologo">Geologo</option>
                                <option value="Direttore artistico">Direttore artistico</option>
                                <option value="Collaudatore">Collaudatore</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Col 3: Società (popolato da partecipanti) -->
                    <div class="form-group">
                        <label for="incarico_societa_0" class="single-row-label">Società <span
                                class="required">*</span></label>
                        <select name="incarico_societa[]" id="incarico_societa_0" class="incarico-societa-select"
                            required>
                            <option value="">Seleziona società...</option>
                        </select>
                    </div>

                    <!-- Col 4: Qualifica nella società -->
                    <div class="form-group">
                        <label for="incarico_qualita_0" class="single-row-label">Qualifica <span
                                class="required">*</span></label>
                        <select name="incarico_qualita[]" id="incarico_qualita_0" required>
                            <option value="">Seleziona qualifica...</option>
                            <option value="Amministratore Unico">Amministratore Unico</option>
                            <option value="Legale Rappresentante">Legale Rappresentante</option>
                            <option value="Direttore Tecnico">Direttore Tecnico</option>
                            <option value="Socio">Socio</option>
                            <option value="Dipendente">Dipendente</option>
                            <option value="Collaboratore">Collaboratore</option>
                            <option value="Consulente esterno">Consulente esterno</option>
                        </select>
                    </div>

                    <!-- Col 5: Actions -->
                    <div class="form-group incarico-actions-group">
                        <button type="button" class="btn-add-row btn-add-incarico"
                            data-tooltip="Aggiungi incarico">+</button>
                        <button type="button" class="btn-remove-row btn-remove-incarico hidden"
                            data-tooltip="Rimuovi incarico">×</button>
                    </div>
                </div>
            </div>
        </div>
    </fieldset>

    <!-- Pulsanti azione -->
    <div class="chiusura-form-actions">
        <button type="submit" class="button button-primary">Salva</button>
        <button type="button" class="button btn-secondary" id="btn-export-word">Esporta Word</button>
        <button type="button" class="button btn-secondary" id="btn-reset-certificato">Reset</button>
    </div>
</form>