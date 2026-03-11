<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

global $database;

// Recupera codice commessa dalla GET, sempre MAIUSCOLO (come nel DB!)
$codice_commessa = strtoupper(trim($_GET['tabella'] ?? ''));

// Normalizza il codice (rimuove spazi e trattini per ricerca più robusta)
$codice_commessa_normalizzato = preg_replace('/[\s\-]/', '', $codice_commessa);

// 1. Recupera la bacheca associata
$bacheca = $database->query("SELECT id FROM commesse_bacheche WHERE tabella = ?", [$codice_commessa], __FILE__)->fetch(PDO::FETCH_ASSOC);
$bacheca_id = $bacheca ? $bacheca['id'] : null;

// 2. Recupera TUTTI i dati disponibili dalla commessa (ricerca multi-livello)
$commessa = null;

// Prova 1: ricerca esatta
if (!$commessa) {
    $commessa = $database->query("SELECT * FROM elenco_commesse WHERE codice = ?", [$codice_commessa], __FILE__)->fetch(PDO::FETCH_ASSOC);
}

// Prova 2: ricerca normalizzata (senza spazi/trattini)
if (!$commessa && $codice_commessa_normalizzato !== $codice_commessa) {
    $commessa = $database->query("SELECT * FROM elenco_commesse WHERE REPLACE(REPLACE(codice, ' ', ''), '-', '') = ?", [$codice_commessa_normalizzato], __FILE__)->fetch(PDO::FETCH_ASSOC);
}

// Prova 3: ricerca LIKE
if (!$commessa) {
    $commessa = $database->query("SELECT * FROM elenco_commesse WHERE codice LIKE ? LIMIT 1", ["%{$codice_commessa}%"], __FILE__)->fetch(PDO::FETCH_ASSOC);
}

// Applica fixMojibake ai dati della commessa
if ($commessa) {
    foreach ($commessa as $key => $value) {
        $commessa[$key] = fixMojibake($value ?? '');
    }
}

// Funzione helper per formattare valori monetari
function formatMoney($value) {
    if (empty($value) || $value === null || $value === '') {
        return null;
    }
    $num = is_numeric($value) ? floatval($value) : 0;
    return number_format($num, 2, ',', '.') . ' €';
}

// Funzione helper per formattare date
function formatDate($value) {
    if (empty($value) || $value === null || $value === '') {
        return null;
    }
    try {
        $date = new DateTime($value);
        return $date->format('d/m/Y');
    } catch (Exception $e) {
        return $value;
    }
}

// Funzione helper per formattare datetime
function formatDateTime($value) {
    if (empty($value) || $value === null || $value === '') {
        return null;
    }
    try {
        $date = new DateTime($value);
        return $date->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $value;
    }
}

// Organizza i dati in sezioni logiche
$sezioni = [];

if ($commessa) {
    // Sezione: Classificazione
    $sezioni['Classificazione'] = [
        'Settore Merceologico' => $commessa['settore_merceologico'] ?? null,
        'Categoria' => $commessa['categoria'] ?? null,
        'Business Unit' => $commessa['business_unit'] ?? null,
        'Prodotto' => $commessa['prodotto'] ?? null,
        'Tipo Logica' => $commessa['tipo_logica'] ?? null,
        'Offerta Collegata' => $commessa['offerta_collegata'] ?? null,
    ];

    // Sezione: Date
    $sezioni['Date'] = [
        'Data Ordine' => formatDate($commessa['data_ordine'] ?? null),
        'Data' => formatDateTime($commessa['data'] ?? null),
        'Data Inizio Prevista' => formatDate($commessa['data_inizio_prevista'] ?? null),
        'Data Fine Prevista' => formatDate($commessa['data_fine_prevista'] ?? null),
        'Data Consegna Prevista' => formatDate($commessa['data_consegna_prevista'] ?? null),
        'Ultima Data Consegna Fasi' => formatDate($commessa['ultima_data_consegna_fasi'] ?? null),
        'Data Chiusura' => formatDate($commessa['data_chiusura'] ?? null),
        'Data Creazione' => formatDateTime($commessa['data_creazione'] ?? null),
        'Data Ultima Modifica' => formatDateTime($commessa['data_ultima_modifica'] ?? null),
    ];

    // Sezione: Referenti
    $sezioni['Referenti'] = [
        'Responsabile Commessa' => $commessa['responsabile_commessa'] ?? null,
        'Referente Cliente' => $commessa['referente_cliente'] ?? null,
        'Referente Cliente 2' => $commessa['referente_cliente_2'] ?? null,
    ];

    // Sezione: Ordine e Codici
    $sezioni['Ordine e Codici'] = [
        'Numero Ordine' => $commessa['nr_ordine'] ?? null,
        'CIG' => $commessa['cig'] ?? null,
        'CUP' => $commessa['cup'] ?? null,
    ];

    // Sezione: Economici
    $sezioni['Economici'] = [
        'Valore Prodotto' => formatMoney($commessa['valore_prodotto'] ?? null),
        'Totale Milestone' => formatMoney($commessa['totale_milestone'] ?? null),
        'Totale Milestone (escluse sospese)' => formatMoney($commessa['totale_milestone_escluse_sospese'] ?? null),
        'Totale Avanzato' => formatMoney($commessa['totale_avanzato'] ?? null),
        'Da Avanzare' => formatMoney($commessa['da_avanzare'] ?? null),
        'Totale Fatturato' => formatMoney($commessa['totale_fatturato'] ?? null),
        'Da Fatturare' => formatMoney($commessa['da_fatturare'] ?? null),
        'Tot. Avanzato da Fatturare' => formatMoney($commessa['tot_avanzato_da_fatturare'] ?? null),
        'Backlog' => formatMoney($commessa['backlog'] ?? null),
        'Acconto' => formatMoney($commessa['acconto'] ?? null),
    ];

    // Sezione: Altri Dati
    $sezioni['Altri Dati'] = [
        'Stato Avanzamento' => $commessa['stato_avanzamento'] ?? null,
        'Progetto' => $commessa['progetto'] ?? null,
        'Sito' => $commessa['sito'] ?? null,
        'Indirizzi di Riferimento' => $commessa['indirizzi_di_riferimento'] ?? null,
        'Cliente Fatturazione' => $commessa['cliente_fatturazione'] ?? null,
        'Banca' => $commessa['banca'] ?? null,
        'Pagamento' => $commessa['pagamento'] ?? null,
        'Estero' => isset($commessa['estero']) ? ($commessa['estero'] ? 'Sì' : 'No') : null,
        'Allegati' => isset($commessa['allegati']) ? ($commessa['allegati'] ? 'Sì' : 'No') : null,
        'Sospesa' => isset($commessa['sospesa']) ? ($commessa['sospesa'] ? 'Sì' : 'No') : null,
        'Utente Creazione' => $commessa['utente_creazione'] ?? null,
        'Utente Ultima Modifica' => $commessa['utente_ultima_modifica'] ?? null,
        'Note Generali' => $commessa['note_generali'] ?? null,
    ];
}

// Campi principali per il riepilogo (unificato con Identificativi)
$riepilogo = [];
if ($commessa) {
    $riepilogo = [
        'Codice' => $commessa['codice'] ?? null,
        'Cliente' => $commessa['cliente'] ?? null,
        'Codice Cliente' => $commessa['codice_cliente'] ?? null,
        'Oggetto' => $commessa['oggetto'] ?? null,
        'Stato' => $commessa['stato'] ?? null,
        'Tipo' => $commessa['tipo'] ?? null,
        'Responsabile Commessa' => $commessa['responsabile_commessa'] ?? null,
    ];
}
?>

<?php if (!$commessa): ?>
        <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #721c24;">
            <strong>⚠️ Errore:</strong> Commessa non trovata.
            <?php if ($codice_commessa): ?>
                <br>Codice cercato: <code><?= htmlspecialchars($codice_commessa) ?></code>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="commessa-main-flex">
            <!-- Colonna SX -->
            <div class="commessa-left-col">
                <div class="commessa-summary-box">
                    <h2>Riepilogo Commessa</h2>
                    <table class="commessa-summary-table">
                        <?php foreach ($riepilogo as $key => $val): ?>
                            <tr>
                                <td class="summary-label"><?= htmlspecialchars($key) ?></td>
                                <td><?= ($val === null || trim($val) === '' || $val === '-') ? '<span class="label label-missing">Non disponibile</span>' : htmlspecialchars($val) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- Dettaglio Dati Commessa organizzato per sezioni -->
                <?php foreach ($sezioni as $nomeSezione => $campi): ?>
                    <?php
                    // Filtra solo i campi che hanno un valore
                    $campiConValore = array_filter($campi, function($val) {
                        return $val !== null && trim($val) !== '' && $val !== '-';
                    });
                    ?>
                    <?php if (!empty($campiConValore)): ?>
                        <div class="commessa-detail-card">
                            <h2><?= htmlspecialchars($nomeSezione) ?></h2>
                            <table class="commessa-summary-table">
                                <?php foreach ($campi as $label => $valore): ?>
                                    <tr>
                                        <td class="summary-label"><?= htmlspecialchars($label) ?></td>
                                        <td><?= ($valore === null || trim($valore) === '' || $valore === '-') ? '<span class="label label-missing">Non disponibile</span>' : htmlspecialchars($valore) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

            </div>
            <!-- Colonna DX -->
            <div class="commessa-right-col">
            <div class="commessa-box-card commessa-deadline-card">
                <h3 class="deadline-title"><span class="icon-calendar" aria-hidden="true"></span> Scadenze prossime</h3>
                <ul class="deadline-list">
                <?php
                $scadenze = [];
                if ($codice_commessa) {
                    $nome_tabella = 'com_' . strtolower($codice_commessa);
                    $oggi = date('Y-m-d');
                    $query = "SELECT id, titolo, deadline, priority FROM `$nome_tabella` WHERE deadline IS NOT NULL AND deadline >= ? AND (status_id IS NULL OR status_id != 4) ORDER BY deadline ASC LIMIT 8";
                    try {
                        $rows = $database->query($query, [$oggi], __FILE__);
                        foreach ($rows as $row) {
                            $scadenze[] = $row;
                        }
                    } catch (\Exception $e) {}
                }
                if (count($scadenze)) {
                    foreach ($scadenze as $t) {
                        $dead = strtotime($t['deadline']);
                        $diff = round(($dead - strtotime(date('Y-m-d')))/86400);

                        // Determina la classe del pallino in base alla priorità
                        $priority = strtolower(trim($t['priority'] ?? ''));
                        $dotClass = '';
                        if ($priority === 'bassa') $dotClass = 'dot-low';
                        elseif ($priority === 'media') $dotClass = 'dot-medium';
                        elseif ($priority === 'alta') $dotClass = 'dot-high';

                        echo '<li class="deadline-row">';
                        echo '<span class="deadline-dot ' . $dotClass . '"></span>';
                        echo '<span class="deadline-task">' . htmlspecialchars($t['titolo']) . '</span>';
                        echo '<span class="deadline-date">' . date('d/m/Y', $dead) . '</span>';
                        if ($diff > 0) echo '<span class="deadline-days">tra ' . $diff . ' giorni</span>';
                        elseif ($diff === 0) echo '<span class="deadline-days urgent">oggi</span>';
                        else echo '<span class="deadline-days overdue">scaduta</span>';
                        echo '</li>';
                    }
                } else {
                    echo '<li class="deadline-empty">Nessuna task in scadenza</li>';
                }
                ?>
                </ul>
            </div>

            <!-- BOX TEAM -->
            <div class="commessa-box-card">
                <h3>Team</h3>
                <ul class="commessa-team-list">
                <?php
                // Recupera membri reali dall'organigramma
                $org = null;
                $res = $database->query("SELECT organigramma FROM commesse_bacheche WHERE id = ?", [$bacheca_id], __FILE__)->fetch(PDO::FETCH_ASSOC);
                if ($res && !empty($res['organigramma'])) {
                    $tmp = json_decode($res['organigramma'], true);
                    $org = is_array($tmp) ? $tmp : null;
                }
                function estraiUserIdDaOrganigramma($node, &$ids = []) {
                    if (!$node || !is_array($node)) return;
                    if (isset($node['user_id']) && $node['user_id']) $ids[] = $node['user_id'];
                    if (!empty($node['children']) && is_array($node['children'])) {
                        foreach ($node['children'] as $child) {
                            estraiUserIdDaOrganigramma($child, $ids);
                        }
                    }
                }
                $ids = [];
                estraiUserIdDaOrganigramma($org, $ids);
                $ids = array_unique($ids);

                if (count($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pers = $database->query("SELECT user_id, Nominativo, Ruolo FROM personale WHERE user_id IN ($placeholders)", $ids, __FILE__);
                    $info = [];
                    foreach ($pers as $p) {
                        $info[$p['user_id']] = [
                            'nome' => $p['Nominativo'],
                            'ruolo' => $p['Ruolo'],
                            'img'   => function_exists('getProfileImage') ? getProfileImage($p['Nominativo'], 'nominativo') : 'assets/images/default_profile.png',
                        ];
                    }
                    foreach ($ids as $uid) {
                        $u = $info[$uid] ?? null;
                        if ($u) {
                            echo '<li class="commessa-team-member">';
                            echo '<span class="team-avatar-wrap">';
                            echo '<img src="' . htmlspecialchars($u['img']) . '" alt="" class="team-avatar">';
                            echo '</span>';
                            echo '<span class="team-info">';
                            echo '<span class="team-name">' . htmlspecialchars($u['nome']) . '</span>';
                            if ($u['ruolo']) echo '<span class="team-role">' . htmlspecialchars($u['ruolo']) . '</span>';
                            echo '</span>';
                            echo '</li>';
                        }
                    }
                } else {
                    echo '<li><span class="label label-missing">Nessun membro assegnato nell\'organigramma</span></li>';
                }
                ?>
                </ul>
            </div>

            <?php /*
            <div class="commessa-box-card">
                <h3>Ultimi documenti</h3>
                <ul class="commessa-ul-list">
                    <li>Relazione tecnica.pdf</li>
                    <li>Capitolato.docx</li>
                </ul>
            </div>
            */ ?>

            </div>
        </div>
    <?php endif; ?>
