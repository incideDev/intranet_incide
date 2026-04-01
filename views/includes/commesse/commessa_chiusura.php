<?php
if (!defined('HostDbDataConnector')) {
  header('HTTP/1.0 403 Forbidden');
  exit;
}

$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
$titolo = isset($_GET['titolo']) ? trim($_GET['titolo']) : 'Commessa';

if (!$tabella) {
  echo "<div class='error'>Parametro 'tabella' mancante.</div>";
  return;
}

// Recupera dati della commessa per precompilare i campi
global $database;
$codice_commessa = strtoupper(trim($tabella));

// Recupera la bacheca associata
$bacheca = $database->query("SELECT id, titolo FROM commesse_bacheche WHERE tabella = ?", [$codice_commessa], __FILE__)->fetch(PDO::FETCH_ASSOC);
$bacheca_id = $bacheca ? $bacheca['id'] : null;

// Recupera anagrafica estesa (se esiste)
$anagrafica = null;
if ($bacheca_id) {
  $anagrafica = $database->query("SELECT * FROM commesse_anagrafica WHERE bacheca_id = ?", [$bacheca_id], __FILE__)->fetch(PDO::FETCH_ASSOC);
}

// Recupera TUTTI i dati disponibili dalla commessa
$commessa = $database->query("SELECT * FROM elenco_commesse WHERE codice = ?", [$codice_commessa], __FILE__)->fetch(PDO::FETCH_ASSOC);

// Applica fixMojibake ai dati della commessa
if ($commessa) {
  foreach ($commessa as $key => $value) {
    $commessa[$key] = fixMojibake($value ?? '');
  }
}

// Applica fixMojibake ai dati dell'anagrafica se presenti
if ($anagrafica) {
  foreach ($anagrafica as $key => $value) {
    $anagrafica[$key] = fixMojibake($value ?? '');
  }
}

// Risolvi nome responsabile se è un ID numerico
$responsabile_nome = '';
if ($commessa && !empty($commessa['responsabile_commessa'])) {
  $resp_raw = $commessa['responsabile_commessa'];
  if (is_numeric($resp_raw)) {
    $resp_nome = $database->query("SELECT Nominativo FROM personale WHERE user_id = ? LIMIT 1", [(int) $resp_raw], __FILE__)->fetchColumn();
    $responsabile_nome = $resp_nome ?: '';
  } else {
    $responsabile_nome = $resp_raw;
  }
}

/**
 * Normalizza una ragione sociale per il confronto (rimuove punteggiatura, varianti SRL/SPA, spazi multipli)
 * @param string $s - Ragione sociale da normalizzare
 * @return string - Stringa normalizzata per il match
 */
function normalizeRagioneSocialeForMatch($s)
{
  if (empty($s))
    return '';

  // Trim e lowercase
  $normalized = strtolower(trim($s));

  // Rimuovi punteggiatura leggera (punti, virgole, apostrofi)
  $normalized = str_replace(['.', ',', "'", '"'], '', $normalized);

  // Normalizza varianti SRL/SPA/SNC ecc (solo per confronto)
  $varianti = [
    's\.r\.l\.' => 'srl',
    's r l' => 'srl',
    's\.p\.a\.' => 'spa',
    's p a' => 'spa',
    's\.n\.c\.' => 'snc',
    's n c' => 'snc',
    's\.a\.s\.' => 'sas',
    's a s' => 'sas'
  ];
  foreach ($varianti as $pattern => $replacement) {
    $normalized = preg_replace('/\b' . $pattern . '\b/i', $replacement, $normalized);
  }

  // Collassa spazi multipli in uno solo
  $normalized = preg_replace('/\s+/', ' ', $normalized);

  return trim($normalized);
}

/**
 * Trova anagrafica committente con match robusto
 * @param mixed $db - Connessione database (MySQLDB)
 * @param array $commessa - Dati commessa
 * @return array|null - Anagrafica committente o null
 */
function findAnagraficaCommittente($db, $commessa)
{
  if (!$commessa)
    return null;

  // Strategia 1: match per codice_cliente
  if (!empty($commessa['codice_cliente'])) {
    $result = $db->query(
      "SELECT ragionesociale, indirizzo, cap, citt, comune, provincia, email, peccliente, partitaiva, codicefiscale, telefono FROM anagrafiche WHERE codicecliente = ? LIMIT 1",
      [$commessa['codice_cliente']],
      __FILE__
    )->fetch(PDO::FETCH_ASSOC);
    if ($result)
      return $result;
  }

  // Strategia 2 e 3: match per ragione sociale (esatto normalizzato e LIKE)
  if (empty($commessa['cliente']))
    return null;

  $clienteNormalized = normalizeRagioneSocialeForMatch($commessa['cliente']);
  if (empty($clienteNormalized))
    return null;

  // Prima prova match esatto normalizzato
  $allAnagrafiche = $db->query(
    "SELECT ragionesociale, indirizzo, cap, citt, comune, provincia, email, peccliente, partitaiva, codicefiscale, telefono FROM anagrafiche WHERE ragionesociale IS NOT NULL",
    [],
    __FILE__
  )->fetchAll(PDO::FETCH_ASSOC);

  $bestMatch = null;
  $bestScore = 0;

  foreach ($allAnagrafiche as $anag) {
    $anagNormalized = normalizeRagioneSocialeForMatch($anag['ragionesociale']);

    // Match esatto normalizzato
    if ($anagNormalized === $clienteNormalized) {
      return $anag;
    }

    // Match LIKE (prefisso più lungo = migliore)
    if (strpos($anagNormalized, $clienteNormalized) === 0) {
      $score = strlen($anagNormalized);
      if ($score > $bestScore) {
        $bestScore = $score;
        $bestMatch = $anag;
      }
    }
  }

  // Se trovato match LIKE, restituiscilo
  if ($bestMatch)
    return $bestMatch;

  // Fallback: LIKE SQL classico
  $result = $db->query(
    "SELECT ragionesociale, indirizzo, cap, citt, comune, provincia, email, peccliente, partitaiva, codicefiscale, telefono FROM anagrafiche WHERE ragionesociale LIKE ? LIMIT 1",
    [$commessa['cliente'] . '%'],
    __FILE__
  )->fetch(PDO::FETCH_ASSOC);

  return $result ?: null;
}

// Carica anagrafica committente usando la funzione riusabile
$anagrafica_committente = findAnagraficaCommittente($database, $commessa);

// Carica anagrafica Incide
$anagrafica_incide = null;
$anagrafica_incide = $database->query(
  "SELECT ragionesociale, indirizzo, cap, citt, comune, provincia, email, peccliente, partitaiva, codicefiscale, telefono FROM anagrafiche WHERE ragionesociale = 'INCIDE ENGINEERING S.R.L.' OR ragionesociale LIKE 'Incide Engineering%' LIMIT 1",
  [],
  __FILE__
)->fetch(PDO::FETCH_ASSOC);

// Prepara indirizzo committente come stringa unica
$indirizzo_committente = '';
if ($anagrafica_committente) {
  $parts = [];
  if (!empty($anagrafica_committente['indirizzo']))
    $parts[] = $anagrafica_committente['indirizzo'];
  if (!empty($anagrafica_committente['cap']))
    $parts[] = $anagrafica_committente['cap'];
  $citta = $anagrafica_committente['citt'] ?? $anagrafica_committente['comune'] ?? '';
  if ($citta) {
    $provincia = !empty($anagrafica_committente['provincia']) ? ' (' . $anagrafica_committente['provincia'] . ')' : '';
    $parts[] = $citta . $provincia;
  }
  $indirizzo_committente = implode(', ', $parts);
} elseif ($commessa && !empty($commessa['cliente'])) {
  // Fallback: usa solo il nome cliente se non c'è anagrafica
  $indirizzo_committente = $commessa['cliente'];
}

// Prepara PEC/Email committente
$pec_email_committente = '';
if ($anagrafica_committente) {
  $pec_email_committente = !empty($anagrafica_committente['peccliente'])
    ? $anagrafica_committente['peccliente']
    : ($anagrafica_committente['email'] ?? '');
}

// Prepara ragione sociale committente per destinatario
$ragione_sociale_committente = '';
if ($anagrafica_committente && !empty($anagrafica_committente['ragionesociale'])) {
  $ragione_sociale_committente = $anagrafica_committente['ragionesociale'];
} elseif ($commessa && !empty($commessa['cliente'])) {
  $ragione_sociale_committente = $commessa['cliente'];
}

// Prepara indirizzo Incide come stringa unica
$indirizzo_incide = '';
if ($anagrafica_incide) {
  $parts = [];
  if (!empty($anagrafica_incide['indirizzo']))
    $parts[] = $anagrafica_incide['indirizzo'];
  if (!empty($anagrafica_incide['cap']))
    $parts[] = $anagrafica_incide['cap'];
  $citta = $anagrafica_incide['citt'] ?? $anagrafica_incide['comune'] ?? '';
  if ($citta) {
    $provincia = !empty($anagrafica_incide['provincia']) ? ' (' . $anagrafica_incide['provincia'] . ')' : '';
    $parts[] = $citta . $provincia;
  }
  $indirizzo_incide = implode(', ', $parts);
}

// Prepara CF/PIVA Incide
$cf_piva_incide = '';
if ($anagrafica_incide) {
  $cf_piva_incide = !empty($anagrafica_incide['partitaiva']) ? $anagrafica_incide['partitaiva'] : ($anagrafica_incide['codicefiscale'] ?? '');
}

// Prepara PEC/Email Incide
$pec_email_incide = '';
if ($anagrafica_incide) {
  $pec_email_incide = !empty($anagrafica_incide['peccliente']) ? $anagrafica_incide['peccliente'] : ($anagrafica_incide['email'] ?? '');
}

// Prepara luogo_data_lettera (città committente + data odierna)
$luogo_data_lettera = '';
if ($anagrafica_committente) {
  $citta_committente = $anagrafica_committente['citt'] ?? $anagrafica_committente['comune'] ?? '';
  if ($citta_committente) {
    $luogo_data_lettera = $citta_committente . ', ' . date('d/m/Y');
  }
} else {
  // Fallback: solo data se non c'è città committente
  $luogo_data_lettera = date('d/m/Y');
}

// Funzione helper per convertire date/datetime in formato YYYY-MM-DD per input type="date"
$formatDateForInput = function ($date) {
  if (empty($date))
    return '';
  // Se è già nel formato corretto, restituiscilo
  if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
    return substr($date, 0, 10);
  }
  // Prova a convertire
  $timestamp = strtotime($date);
  return $timestamp ? date('Y-m-d', $timestamp) : '';
};

// Prepara dati per precompilazione completa
// Priorità: anagrafica > commessa (anagrafica ha dati più dettagliati)
// Nota: i dati in elenco_commesse sono la fonte primaria, commesse_anagrafica è un'estensione opzionale
$oggetto_commessa = '';
if (!empty($anagrafica['titolo'])) {
  $oggetto_commessa = $anagrafica['titolo'];
} elseif (!empty($commessa['oggetto'])) {
  $oggetto_commessa = $commessa['oggetto'];
}

$cliente_commessa = '';
if (!empty($anagrafica['cliente'])) {
  $cliente_commessa = $anagrafica['cliente'];
} elseif (!empty($commessa['cliente'])) {
  $cliente_commessa = $commessa['cliente'];
}

$nr_ordine = '';
if (!empty($anagrafica['numero_ordine'])) {
  $nr_ordine = $anagrafica['numero_ordine'];
} elseif (!empty($commessa['nr_ordine'])) {
  $nr_ordine = $commessa['nr_ordine'];
}

$cig = '';
if (!empty($anagrafica['cig'])) {
  $cig = $anagrafica['cig'];
} elseif (!empty($commessa['cig'])) {
  $cig = $commessa['cig'];
}

$cup = '';
if (!empty($anagrafica['cup'])) {
  $cup = $anagrafica['cup'];
} elseif (!empty($commessa['cup'])) {
  $cup = $commessa['cup'];
}

$referente_cliente = '';
if (!empty($anagrafica['referente_cliente'])) {
  $referente_cliente = $anagrafica['referente_cliente'];
} elseif (!empty($commessa['referente_cliente'])) {
  $referente_cliente = $commessa['referente_cliente'];
}

$referente_cliente_2 = '';
if (!empty($anagrafica['referente_cliente_2'])) {
  $referente_cliente_2 = $anagrafica['referente_cliente_2'];
} elseif (!empty($commessa['referente_cliente_2'])) {
  $referente_cliente_2 = $commessa['referente_cliente_2'];
}

$valore_prodotto = '';
if (!empty($anagrafica['importo_lavori'])) {
  $valore_prodotto = $anagrafica['importo_lavori'];
} elseif (!empty($commessa['valore_prodotto'])) {
  $valore_prodotto = $commessa['valore_prodotto'];
}

$importo_prestazioni = '';
if (!empty($valore_prodotto)) {
  // Converte valore_prodotto in numero (può essere stringa o numero)
  $valore_num = is_numeric($valore_prodotto) ? (float) $valore_prodotto : (float) str_replace(',', '.', $valore_prodotto);
  if ($valore_num > 0) {
    $importo_prestazioni = number_format($valore_num, 0, ',', '.');
  }
}

$datiPrecompilazione = [
  'committente' => $cliente_commessa,
  'titolo_progetto' => $anagrafica['titolo'] ?? $bacheca['titolo'] ?? $oggetto_commessa ?? $titolo ?? '',
  'oggetto_contratto' => $oggetto_commessa, // Precompilato con oggetto commessa
  'oggetto_lettera' => $oggetto_commessa, // Precompilato con oggetto commessa
  'riferimento_contratto' => !empty($nr_ordine) ? $nr_ordine : ($commessa['codice'] ?? ''),
  'cig' => $cig,
  'cup' => $cup,
  'importo_prestazioni' => $importo_prestazioni,
  'rup_nome' => !empty($referente_cliente) ? $referente_cliente : $referente_cliente_2,
  'societa_incaricata' => 'Incide Engineering S.r.l.', // Hardcoded o da configurazione
  'incarico_svolto_da' => '', // Da compilare manualmente
  'incarico_ruolo' => '', // Da compilare manualmente
  'data_inizio_prestazione' => $formatDateForInput($anagrafica['data_inizio'] ?? $commessa['data_inizio_prevista'] ?? ''),
  'data_fine_prestazione' => $formatDateForInput($anagrafica['data_fine'] ?? $commessa['data_fine_prevista'] ?? $commessa['data_chiusura'] ?? '')
];
?>
<?php renderPageTitle('Chiusura Commessa', '#cccccc'); ?>

  <!-- Card interne per navigazione -->
  <div id="chiusura-cards-wrapper" class="chiusura-subgrid">
    <div class="chiusura-subcard" data-panel="certificato" role="button" tabindex="0">
      <div class="commessa-card-title">Comprovante</div>
      <div class="commessa-card-preview"></div>
    </div>
  </div>

  <!-- Pannello Certificato -->
  <div id="comprovante-form-container">
  <section id="chiusura-certificato" class="chiusura-panel is-hidden">
    <form id="form-certificato" class="chiusura-form" onsubmit="return false;">
      <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella) ?>">

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
                value="<?= htmlspecialchars($luogo_data_lettera) ?>">
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
                value="<?= htmlspecialchars($ragione_sociale_committente) ?>">
            </div>
            <div class="form-group">
              <label for="destinatario_pec_email">Destinatario -
                PEC/Email</label>
              <input type="text" id="destinatario_pec_email" name="destinatario_pec_email"
                value="<?= htmlspecialchars($pec_email_committente) ?>">
            </div>
            <div class="form-group form-group-span-2">
              <label for="destinatario_indirizzo">Destinatario - Indirizzo</label>
              <input type="text" id="destinatario_indirizzo" name="destinatario_indirizzo"
                value="<?= htmlspecialchars($indirizzo_committente) ?>">
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
                value="<?= htmlspecialchars($anagrafica_committente['ragionesociale'] ?? $datiPrecompilazione['committente']) ?>">
            </div> -->
            <div class="form-group">
              <label for="indirizzo_committente">Indirizzo committente</label>
              <input type="text" id="indirizzo_committente" name="indirizzo_committente"
                value="<?= htmlspecialchars($indirizzo_committente) ?>">
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
            <label for="committente">Committente dell'opera <span class="required">*</span></label>
            <input type="text" id="committente" name="committente"
              value="<?= htmlspecialchars($datiPrecompilazione['committente']) ?>" required>
          </div>
          <div class="form-group">
            <label for="titolo_progetto">Titolo / Nome progetto <span class="required">*</span></label>
            <input type="text" id="titolo_progetto" name="titolo_progetto"
              value="<?= htmlspecialchars($datiPrecompilazione['titolo_progetto']) ?>" required>
          </div>
          <div class="form-group">
            <label for="riferimento_contratto">Estremi del contratto /
              Rif. contratto <span class="required">*</span></label>
            <input type="text" id="riferimento_contratto" name="riferimento_contratto"
              value="<?= htmlspecialchars($datiPrecompilazione['riferimento_contratto']) ?>" required>
          </div>
          <div class="form-group form-group-full-width">
            <label for="oggetto_contratto">Oggetto del contratto <span class="required">*</span></label>
            <textarea id="oggetto_contratto" name="oggetto_contratto" rows="1"
              required><?= htmlspecialchars($datiPrecompilazione['oggetto_contratto']) ?></textarea>
          </div>
          <div class="form-group">
            <label for="cig">CIG</label>
            <input type="text" id="cig" name="cig" value="<?= htmlspecialchars($datiPrecompilazione['cig']) ?>">
          </div>
          <div class="form-group">
            <label for="cup">CUP</label>
            <input type="text" id="cup" name="cup" value="<?= htmlspecialchars($datiPrecompilazione['cup']) ?>">
          </div>
          <div class="form-group">
            <label for="importo_prestazioni">Importo prestazioni (CNIA,
              IVA esclusa)</label>
            <input type="text" id="importo_prestazioni" name="importo_prestazioni" class="importo-money"
              value="<?= htmlspecialchars($datiPrecompilazione['importo_prestazioni'] ?? '') ?>" placeholder="0,00">
          </div>
          <div class="form-group">
            <label for="rup_nome">Responsabile del Procedimento (RUP)
              <span class="required">*</span></label>
            <input type="text" id="rup_nome" name="rup_nome"
              value="<?= htmlspecialchars($datiPrecompilazione['rup_nome']) ?>" required>
          </div>
          <div class="form-group">
            <label for="data_inizio_prestazione">Inizio prestazione
              <span class="required">*</span></label>
            <input type="date" id="data_inizio_prestazione" name="data_inizio_prestazione"
              value="<?= htmlspecialchars($datiPrecompilazione['data_inizio_prestazione']) ?>" required>
          </div>
          <div class="form-group">
            <label for="data_fine_prestazione">Conclusione prestazione
              <span class="required">*</span></label>
            <input type="date" id="data_fine_prestazione" name="data_fine_prestazione"
              value="<?= htmlspecialchars($datiPrecompilazione['data_fine_prestazione']) ?>" required>
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
                <input type="checkbox" name="att_bim" id="att_bim" value="1" <?= (isset($commessa['business_unit']) && stripos($commessa['business_unit'], 'BIM') !== false) ? 'checked' : '' ?>>
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
            <input type="text" id="importo_lavori_totale" name="importo_lavori_totale" class="importo-money" value=""
              placeholder="0,00">
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
              value="<?= htmlspecialchars($indirizzo_incide) ?>">
          </div>
          <div class="form-group">
            <label for="societa_cf_piva">Società - CF/PIVA</label>
            <input type="text" id="societa_cf_piva" name="societa_cf_piva"
              value="<?= htmlspecialchars($cf_piva_incide) ?>">
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
                    <label for="societa_0" class="single-row-label">Società <span class="required">*</span></label>
                    <div class="custom-select-box societa-select-box" id="societa-select-0">
                      <div class="custom-select-placeholder">
                        <?= htmlspecialchars($datiPrecompilazione['societa_incaricata'] ?: '') ?>
                      </div>
                      <input type="hidden" name="societa_id[]" id="societa_id_0" value="">
                      <input type="hidden" name="societa_nome[]" id="societa_nome_0"
                        value="<?= htmlspecialchars($datiPrecompilazione['societa_incaricata']) ?>">
                    </div>
                  </div>
                  <div class="form-group partecipanti-percentuale-group">
                    <label for="percentuale_0" class="single-row-label">% <span class="required">*</span></label>
                    <input type="number" id="percentuale_0" name="percentuale[]" value="100" min="0" max="100"
                      step="0.01" readonly required>
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
                <label for="incarico_nome_0" class="single-row-label">Tecnico <span class="required">*</span></label>
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
                    <option value="Coordinatore della sicurezza in fase di progettazione (CSP)">CSP - Coordinatore
                      sicurezza progettazione</option>
                    <option value="Coordinatore della sicurezza in fase di esecuzione (CSE)">CSE - Coordinatore
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
                <label for="incarico_societa_0" class="single-row-label">Società <span class="required">*</span></label>
                <select name="incarico_societa[]" id="incarico_societa_0" class="incarico-societa-select" required>
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
                <button type="button" class="btn-add-row btn-add-incarico" data-tooltip="Aggiungi incarico">+</button>
                <button type="button" class="btn-remove-row btn-remove-incarico hidden"
                  data-tooltip="Rimuovi incarico">×</button>
              </div>
            </div>
          </div>
        </div>
      </fieldset>

      <!-- FIELDSET RIMOSSO 2025-01-14: Dichiarazioni e firma non più necessario nel template Word -->
      <!-- <fieldset class="chiusura-fieldset">
        <legend>Dichiarazioni e firma</legend>
        <div class="form-grid">
          <div class="form-group">
            <label for="dichiarante_nome">Dichiarante - Nome</label>
            <input type="text" id="dichiarante_nome" name="dichiarante_nome"
              value="<?= htmlspecialchars($responsabile_nome) ?>">
          </div>
          <div class="form-group">
            <label for="dichiarante_qualifica">Dichiarante - Qualifica</label>
            <input type="text" id="dichiarante_qualifica" name="dichiarante_qualifica" value="">
          </div>
          <div class="form-group full-width">
            <label for="testo_dichiarazioni">Testo dichiarazioni</label>
            <textarea id="testo_dichiarazioni" name="testo_dichiarazioni"
              rows="4">Il sottoscritto dichiara, ai sensi del D.P.R. 445/2000, che le informazioni fornite sono veritiere e complete.</textarea>
          </div>
          <div class="form-group">
            <label for="responsabile_procedimento_amministrativo">Responsabile procedimento amministrativo</label>
            <input type="text" id="responsabile_procedimento_amministrativo"
              name="responsabile_procedimento_amministrativo" value="">
          </div>
          <div class="form-group">
            <label for="riferimento_contatto">Riferimento contatto</label>
            <input type="text" id="riferimento_contatto" name="riferimento_contatto" value="">
          </div>
        </div>
      </fieldset> -->

      <!-- Pulsanti azione -->
      <div class="chiusura-form-actions">
        <button type="submit" class="button button-primary">Salva</button>
        <button type="button" class="button btn-secondary" id="btn-export-word">Esporta Word</button>
        <button type="button" class="button btn-secondary" id="btn-reset-certificato">Reset</button>
      </div>
    </form>
  </section>
  </div><!-- /comprovante-form-container -->

  <!-- Pannello Consuntivo -->
  <section id="chiusura-consuntivo" class="chiusura-panel is-hidden">
    <h2>Consuntivo commessa</h2>
    <p class="muted">
      Qui andremo a sviluppare il modello di consuntivo (riepilogo ore, costi, stato economico).
    </p>
  </section>
<!-- Modal nuovo destinatario (riutilizzato da protocollo_email) -->
<div id="modal-nuovo-destinatario" class="modal modal-small hidden">
  <div class="modal-content">
    <span class="close-modal"
      onclick="if(typeof window.toggleModal === 'function') { window.toggleModal('modal-nuovo-destinatario', 'close'); } else { document.getElementById('modal-nuovo-destinatario').style.display = 'none'; }">&times;</span>
    <h3>Nuovo destinatario (azienda)</h3>
    <form id="nuovo-destinatario-form" autocomplete="off">
      <div class="modal-form-grid">
        <div>
          <label for="dest-ragione">Ragione sociale*:</label>
          <input type="text" id="dest-ragione" name="ragionesociale" required>
        </div>
        <div>
          <label for="dest-piva">Partita IVA:</label>
          <input type="text" id="dest-piva" name="partitaiva">
        </div>
        <div>
          <label for="dest-citta">Città:</label>
          <input type="text" id="dest-citta" name="citta">
        </div>
        <div>
          <label for="dest-email">Email:</label>
          <input type="email" id="dest-email" name="email">
        </div>
        <div>
          <label for="dest-tel">Telefono:</label>
          <input type="text" id="dest-tel" name="telefono">
        </div>
      </div>
      <div class="modal-btns">
        <button type="button" id="btn-cancella-nuovo-destinatario" class="button">Annulla</button>
        <button type="submit" id="btn-salva-nuovo-destinatario" class="button">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Carica autocomplete_manager.js PRIMA dello script inline (layout lo carica dopo) -->
<script src="/assets/js/modules/autocomplete_manager.js"></script>
<!-- Includi protocollo_email.js per caricaAziendeCached e apriModaleNuovoDestinatario -->
<script src="/assets/js/protocollo_email.js"></script>
<!-- Modulo comprovante (sorgente unica) -->
<script src="/assets/js/modules/comprovante_form.js"></script>

<script>
  (function () {
    'use strict';

    // Stato navigazione pagina
    let currentPanel = null;

    // Inizializzazione
    function init() {
      // Assicurati che tutti i pannelli siano nascosti all'inizio
      document.querySelectorAll('.chiusura-panel').forEach(panel => {
        panel.classList.add('is-hidden');
      });
      // Rimuovi active da tutte le card
      document.querySelectorAll('.chiusura-subcard').forEach(card => {
        card.classList.remove('active');
      });

      // --- Chiusura-specific: navigazione pagina ---
      setupCardListeners();
      setupTableFilterable();

      // --- Modulo comprovante (sorgente unica) ---
      const formApi = window.initComprovanteForm('#comprovante-form-container', {
        tabella: <?= json_encode($tabella) ?>,
        datiPrecompilazione: {
          societa_incaricata: <?= json_encode($datiPrecompilazione['societa_incaricata'] ?? '') ?>
        },
        onSave: function (res) {
          if (res && res.success) {
            showToast('Comprovante salvata correttamente.', 'success');
          } else {
            showToast('Errore salvataggio: ' + (res && res.error ? res.error : 'Errore'), 'error');
          }
        }
      });

      // Export Word (chiusura-specific UI: button feedback)
      const btnExportWord = document.getElementById('btn-export-word');
      if (btnExportWord && formApi) {
        btnExportWord.addEventListener('click', async function () {
          try {
            btnExportWord.disabled = true;
            btnExportWord.textContent = 'Generazione in corso...';
            await formApi.exportToWord();
          } catch (err) {
            console.error(err);
            showToast('Errore: ' + (err.message || 'Errore di rete'), 'error');
          } finally {
            btnExportWord.disabled = false;
            btnExportWord.textContent = 'Esporta Word';
          }
        });
      }

      window.chiusuraFormApi = formApi;

      // Apertura diretta del pannello comprovante (unico flusso richiesto)
      showPanel('certificato');
    }

    // Inizializza table-filterable per le tabelle (senza barre di ricerca, nascoste via CSS)
    function setupTableFilterable() {
      // Inizializza le tabelle con table-filterable dopo un breve delay per assicurarsi che siano nel DOM
      setTimeout(() => {
        const tableCategorie = document.getElementById('table-categorie-opera');
        const tableSuddivisione = document.getElementById('table-suddivisione-servizio');

        if (tableCategorie && typeof window.initTableFilters === 'function') {
          window.initTableFilters('table-categorie-opera');
          // Nascondi paginazione se non necessaria - chiama dopo che initClientSidePagination ha finito
          setTimeout(() => {
            hidePaginationIfNotNeeded(tableCategorie);
          }, 600); // Dopo il delay di initClientSidePagination (500ms)
        }

        if (tableSuddivisione && typeof window.initTableFilters === 'function') {
          window.initTableFilters('table-suddivisione-servizio');
          // Nascondi paginazione se non necessaria - chiama dopo che initClientSidePagination ha finito
          setTimeout(() => {
            hidePaginationIfNotNeeded(tableSuddivisione);
          }, 600); // Dopo il delay di initClientSidePagination (500ms)
        }
      }, 100);
    }

    // Nasconde la paginazione se non ci sono abbastanza righe per necessitare paginazione
    function hidePaginationIfNotNeeded(table) {
      if (!table) return;

      const tbody = table.querySelector('tbody');
      if (!tbody) return;

      // Conta tutte le righe nel tbody
      const allRows = Array.from(tbody.querySelectorAll('tr'));
      const totalRows = allRows.length;

      // Cerca il container di paginazione associato a questa tabella
      const scrollableWrapper = table.closest('.table-filterable-wrapper, .table-wrapper, .table-container');
      const parentContainer = scrollableWrapper ? scrollableWrapper.parentElement : table.parentElement;

      let paginationContainer = null;
      if (parentContainer) {
        // Cerca come sibling del wrapper
        if (scrollableWrapper && scrollableWrapper.nextElementSibling) {
          if (scrollableWrapper.nextElementSibling.classList.contains('table-pagination')) {
            paginationContainer = scrollableWrapper.nextElementSibling;
          }
        }
        // Cerca anche nel parent
        if (!paginationContainer) {
          paginationContainer = parentContainer.querySelector('.table-pagination');
        }
      }

      // Se ancora non trovato, cerca il container più vicino alla tabella
      if (!paginationContainer) {
        const allPaginationContainers = document.querySelectorAll('.table-pagination');
        for (const container of allPaginationContainers) {
          const containerTable = container.parentElement?.querySelector('table.table-filterable');
          if (containerTable === table) {
            paginationContainer = container;
            break;
          }
        }
      }

      if (paginationContainer) {
        // Ottieni il pageSize dalla tabella o dal select di paginazione
        const pageSizeSelect = paginationContainer.querySelector('.pagination-page-size');
        const pageSize = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 10;

        // Se ci sono 0 righe o meno/uguali righe rispetto al pageSize, nascondi la paginazione
        if (totalRows === 0 || totalRows <= pageSize) {
          paginationContainer.style.display = 'none';
        } else {
          paginationContainer.style.display = '';
        }

        // Osserva cambiamenti nel tbody e nel pageSize per aggiornare la visibilità
        const updateVisibility = () => {
          const currentRows = Array.from(tbody.querySelectorAll('tr')).length;
          const currentPageSize = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 10;
          if (currentRows === 0 || currentRows <= currentPageSize) {
            paginationContainer.style.display = 'none';
          } else {
            paginationContainer.style.display = '';
          }
        };

        // Observer per cambiamenti nel tbody
        const observer = new MutationObserver(updateVisibility);
        observer.observe(tbody, {
          childList: true,
          subtree: true
        });

        // Observer per cambiamenti nel pageSize
        if (pageSizeSelect) {
          pageSizeSelect.addEventListener('change', updateVisibility);
        }
      }
    }

    // Gestione toggle pannelli
    function setupCardListeners() {
      const cards = document.querySelectorAll('.chiusura-subcard');
      cards.forEach(card => {
        card.addEventListener('click', function () {
          const panelName = this.dataset.panel;
          showPanel(panelName);
        });

        // Supporto per tastiera
        card.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const panelName = this.dataset.panel;
            showPanel(panelName);
          }
        });
      });
    }

    function showPanel(panelName) {
      // Nascondi tutte le card
      const cardsWrapper = document.getElementById('chiusura-cards-wrapper');
      if (cardsWrapper) {
        cardsWrapper.classList.add('hide');
      }

      // Nascondi tutti i pannelli
      document.querySelectorAll('.chiusura-panel').forEach(panel => {
        panel.classList.add('is-hidden');
      });

      // Rimuovi active da tutte le card
      document.querySelectorAll('.chiusura-subcard').forEach(card => {
        card.classList.remove('active');
      });

      // Mostra il pannello selezionato
      const targetPanel = document.getElementById(`chiusura-${panelName}`);
      if (targetPanel) {
        targetPanel.classList.remove('is-hidden');
        currentPanel = panelName;

        // Scroll smooth al pannello (con piccolo delay per permettere il rendering)
        setTimeout(() => {
          targetPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
      }
    }

    function showCards() {
      // Mostra le card
      const cardsWrapper = document.getElementById('chiusura-cards-wrapper');
      if (cardsWrapper) {
        cardsWrapper.classList.remove('hide');
      }

      // Nascondi tutti i pannelli
      document.querySelectorAll('.chiusura-panel').forEach(panel => {
        panel.classList.add('is-hidden');
      });

      // Rimuovi active da tutte le card
      document.querySelectorAll('.chiusura-subcard').forEach(card => {
        card.classList.remove('active');
      });

      currentPanel = null;

      // Scroll alle card
      setTimeout(() => {
        if (cardsWrapper) {
          cardsWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }, 50);
    }

    // Esponi la funzione globalmente per il bottone
    window.chiusuraShowCards = showCards;

    // Avvia quando il DOM è pronto
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
</script>
