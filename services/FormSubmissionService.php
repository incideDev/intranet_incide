<?php

namespace Services;

class FormSubmissionService
{
    /**
     * Aggiorna l'esito di una segnalazione, gestendo il cambio di stato e le notifiche.
     * 
     * @param string $formName Nome del form (es. 'segnalazione_it')
     * @param int $submissionId ID del record nella tabella del form
     * @param string $esitoStato Nuovo esito ('accettata', 'in_valutazione', 'rifiutata')
     * @param string $esitoNote Note opzionali
     * @param array $metaEsito Campi meta esito opzionali (data_apertura_esito, deadline_esito, ecc.)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function updateEsito(string $formName, int $submissionId, string $esitoStato, string $esitoNote = '', array $metaEsito = []): array
    {
        global $database;

        // 1. Validazione Input
        $formName = trim($formName);
        $esitoStato = trim($esitoStato);
        $esitoNote = trim($esitoNote);

        if ($formName === '' || $submissionId <= 0) {
            return ['success' => false, 'message' => 'Parametri mancanti (form_name, record_id)'];
        }

        // Valori consentiti
        $allowed = ['accettata', 'in_valutazione', 'rifiutata'];
        if (!in_array($esitoStato, $allowed, true)) {
            return ['success' => false, 'message' => 'Esito non valido. Valori ammessi: ' . implode(', ', $allowed)];
        }

        // 2. Recupero Form e Tabella
        $form = $database->query("SELECT * FROM forms WHERE name=:n LIMIT 1", [':n' => $formName], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$form) {
            return ['success' => false, 'message' => 'Form non trovato'];
        }
        $table = $form['table_name'];

        // 3. Recupero Record Esistente (lock for update per sicurezza transazionale, se supportato, altrimenti select normale)
        // Verifichiamo anche che esito_stato non stia tornando a NULL (se era già settato) - anche se la validazione sopra impedisce NULL/vuoto.
        $row = $database->query("SELECT * FROM `$table` WHERE id=:id LIMIT 1", [':id' => $submissionId], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Record non trovato'];
        }

        $currentEsito = $row['esito_stato'] ?? null;

        // Verifica vincolo: esito_stato non può tornare NULL (qui stiamo settando un valore valido, quindi ok. 
        // Ma se volessimo impedire sovrascrittura di un valore esistente... il requisito dice "una volta impostato non può tornare NULL", 
        // che è garantito dal fatto che accettiamo solo valori non-null in input).

        // Determina nuovo status_id
        $newStatusId = self::determineStatusId($form['id'], $esitoStato, (int) ($row['status_id'] ?? 1));

        // Verifica esistenza colonna assegnato_a
        $hasAssegnatoA = false;
        try {
            $chk = $database->query("SHOW COLUMNS FROM `$table` LIKE 'assegnato_a'", [], __FILE__ . " ==> " . __LINE__);
            $hasAssegnatoA = ($chk && $chk->rowCount() > 0);
        } catch (\Throwable $e) {
        }

        // 4. Esecuzione Transazione Atomica
        $database->query("START TRANSACTION", [], __FILE__ . ' ⇒ updateEsito.tx');
        try {
            // Update
            $sql = "UPDATE `$table` SET 
                    esito_stato = :esito, 
                    esito_note = :note, 
                    status_id = :status,
                    esito_data = IF(esito_data IS NULL, NOW(), esito_data)
                    WHERE id = :id";

            // Sync assegnato_a con assegnato_a_esito se presente e la colonna esiste
            if (!empty($metaEsito['assegnato_a_esito']) && $hasAssegnatoA) {
                $sql = "UPDATE `$table` SET 
                        esito_stato = :esito, 
                        esito_note = :note, 
                        status_id = :status,
                        esito_data = IF(esito_data IS NULL, NOW(), esito_data),
                        assegnato_a = :assegnato_a
                        WHERE id = :id";
            }

            $params = [
                ':esito' => $esitoStato,
                ':note' => $esitoNote,
                ':status' => $newStatusId,
                ':id' => $submissionId
            ];
            if (!empty($metaEsito['assegnato_a_esito']) && $hasAssegnatoA) {
                $val = $metaEsito['assegnato_a_esito'];
                // Se non è numerico, prova a risolvere ID dal nome (Nominativo in personale)
                if (!is_numeric($val)) {
                    $resolvedId = $database->query("SELECT user_id FROM personale WHERE Nominativo=:n LIMIT 1", [':n' => $val])->fetchColumn();
                    if ($resolvedId) {
                        $val = $resolvedId;
                    }
                }

                // Usa il valore solo se è numerico (ID valido) per evitare errore 1366
                if (is_numeric($val)) {
                    $params[':assegnato_a'] = $val;
                } else {
                    // Rimuovi assegnato_a dalla query se non valido per evitare crash
                    $sql = str_replace("assegnato_a = :assegnato_a", "esito_data = esito_data", $sql);
                    // (hack sporco: sostituisco con no-op per validità SQL, meglio rigenerare SQL ma questo basta qui)
                    // Pof, meglio rigenerare la stringa SQL pulita sopra.
                    // Facciamo fallback: se non valido, usiamo la query originale senza assegnato_a
                    $sql = "UPDATE `$table` SET 
                        esito_stato = :esito, 
                        esito_note = :note, 
                        status_id = :status,
                        esito_data = IF(esito_data IS NULL, NOW(), esito_data)
                        WHERE id = :id";
                }
            }
            $stmt = $database->query($sql, $params, __FILE__);

            if (!$stmt) {
                throw new \Exception("Errore durante l'aggiornamento del database");
            }

            // Legacy manual notification removed in favor of processRules (on_status_change) below

            // GESTIONE REGOLE NOTIFICA AVANZATE (Nuovo sistema)
            try {
                $statusData = $row; // Dati originali
                $statusData['esito_stato'] = $esitoStato;
                $statusData['esito_note'] = $esitoNote;
                $statusData['now'] = date('d/m/Y H:i');

                \Services\NotificationService::processRules($formName, 'on_status_change', $statusData);
            } catch (\Throwable $e) {
                error_log("Errore processRules on_status_change: " . $e->getMessage());
            }

            // AGGIORNAMENTO STATO SCHEDA: Segna la scheda 'esito' come 'submitted' per la logica dei tab
            try {
                \Services\PageEditorService::updateSchedaStatus([
                    'form_name' => $formName,
                    'record_id' => $submissionId,
                    'scheda_key' => 'esito',
                    'status' => 'submitted'
                ]);
            } catch (\Throwable $e) {
                // Non blocchiamo il processo principale se fallisce solo il log dello stato scheda
                error_log("Avviso: Impossibile aggiornare stato scheda esito: " . $e->getMessage());
            }

            // AGGIORNAMENTO STATUS_ID SUBTASK: Aggiorna anche lo status_id nella tabella form_schede_status
            // per la subtask 'esito', in modo che appaia con lo stato corretto nella lista
            try {
                $updateSubtaskStatus = "UPDATE form_schede_status 
                                       SET status_id = :status 
                                       WHERE form_name = :form_name 
                                       AND record_id = :record_id 
                                       AND scheda_key = :scheda_key";

                $database->query($updateSubtaskStatus, [
                    ':status' => $newStatusId,
                    ':form_name' => $formName,
                    ':record_id' => $submissionId,
                    ':scheda_key' => 'esito'
                ], __FILE__);
            } catch (\Throwable $e) {
                // Non blocchiamo il processo principale
                error_log("Avviso: Impossibile aggiornare status_id subtask esito: " . $e->getMessage());
            }

            $database->query("COMMIT", [], __FILE__ . ' ⇒ updateEsito.commit');

            // Salva meta_esito nella subtask (scheda_data JSON) se presenti
            if (!empty($metaEsito)) {
                $allowedMeta = ['data_apertura_esito', 'deadline_esito', 'assegnato_a_esito', 'priorita_esito', 'stato_esito'];
                $sanitized = [];
                foreach ($allowedMeta as $mk) {
                    if (isset($metaEsito[$mk])) {
                        $sanitized[$mk] = htmlspecialchars(trim((string) $metaEsito[$mk]), ENT_QUOTES, 'UTF-8');
                    }
                }
                if (!empty($sanitized)) {
                    try {
                        $jsonCheck = json_encode($sanitized);
                        if ($jsonCheck === false) {
                            error_log("Avviso: meta_esito JSON non valido: " . json_last_error_msg());
                        } else {
                            \Services\FormsDataService::saveSubtask([
                                'form_name' => $formName,
                                'parent_record_id' => $submissionId,
                                'scheda_label' => 'Esito',
                                'scheda_data' => $sanitized
                            ]);
                        }
                    } catch (\Throwable $e) {
                        error_log("Avviso: Impossibile salvare meta_esito: " . $e->getMessage());
                    }
                }
            }

            return ['success' => true, 'message' => 'Esito aggiornato con successo'];

        } catch (\Exception $e) {
            $database->query("ROLLBACK", [], __FILE__ . ' ⇒ updateEsito.rollback');
            error_log("Errore updateEsito: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore durante il salvataggio: ' . $e->getMessage()];
        }
    }

    /**
     * Determina lo status_id corretto basandosi su esito e configurazione form
     */
    private static function determineStatusId(int $formId, string $esitoStato, int $currentStatusId): int
    {
        global $database;

        // Cerca stati custom per questo form
        // base_group: 1=Aperta, 2=In corso, 3=Chiusa (convenzione PageEditorService)
        // Se non esistono stati custom, usiamo fallback standard

        $targetGroup = 0;
        if ($esitoStato === 'in_valutazione') {
            $targetGroup = 2; // In corso
        } elseif ($esitoStato === 'accettata' || $esitoStato === 'rifiutata') {
            $targetGroup = 3; // Chiusa
        } else {
            return $currentStatusId;
        }

        // Cerca status con base_group corrispondente
        $st = $database->query(
            "SELECT id FROM form_states WHERE form_id=:f AND base_group=:bg AND active=1 ORDER BY sort_order ASC LIMIT 1",
            [':f' => $formId, ':bg' => $targetGroup],
            __FILE__
        );
        $res = $st->fetch(\PDO::FETCH_ASSOC);

        if ($res) {
            return (int) $res['id'];
        }

        // Fallback standard if no custom states: 
        // 1=Aperta, 2=In corso, 3=Chiusa (Standard Intranet)
        if ($targetGroup === 2)
            return 2; // In corso
        if ($targetGroup === 3)
            return 3; // Chiusa

        return $currentStatusId;
    }

}
