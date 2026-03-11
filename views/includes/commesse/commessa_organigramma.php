<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

global $database;

/* --- Parametri --- */
$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante nell'URL.</div>";
    return;
}

/* --- Dati commessa + responsabile_commessa --- */
$commessa = $database->query(
    "SELECT b.id, b.titolo, c.responsabile_commessa
     FROM commesse_bacheche b
     LEFT JOIN elenco_commesse c ON c.codice = b.tabella
     WHERE b.tabella = ? LIMIT 1",
    [$tabella],
    __FILE__
)->fetch(\PDO::FETCH_ASSOC);

// Applica fixMojibake ai dati letti
if ($commessa) {
    $commessa['titolo'] = fixMojibake($commessa['titolo'] ?? '');
    $commessa['responsabile_commessa'] = fixMojibake($commessa['responsabile_commessa'] ?? '');
}

if (!$commessa) {
    echo "<div class='error'>Commessa non trovata.</div>";
    return;
}

$commessa_id = (int) $commessa['id'];

/* --- Risoluzione robusta responsabile_commessa -> user_id --- */
$responsabile_raw = $commessa['responsabile_commessa'] ?? null;
$responsabile_id = null;

if ($responsabile_raw !== null && $responsabile_raw !== '') {
    // Caso 1: è (o sembra) numerico
    if (is_numeric($responsabile_raw)) {
        $cand = (int) $responsabile_raw;
        $exists = $database->query(
            "SELECT 1 FROM personale WHERE user_id = ? LIMIT 1",
            [$cand],
            __FILE__
        )->fetchColumn();
        if ($exists)
            $responsabile_id = $cand;
    }

    // Caso 2: testuale -> normalizzo e risolvo "Nome Cognome" vs "Cognome Nome"
    if (!$responsabile_id && !is_numeric($responsabile_raw)) {
        $norm = function (string $s) {
            $s = trim($s);
            $s = preg_replace('/\s+/', ' ', $s);
            return mb_strtolower($s, 'UTF-8');
        };

        $needle = $norm((string) $responsabile_raw);

        // Mappa normalizzata dei nominativi
        $righe = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $byNorm = [];
        $byNormReversed = []; // prova anche inversione "Nome Cognome" <-> "Cognome Nome"
        foreach ($righe as $r) {
            $full = $norm($r['Nominativo']);
            $byNorm[$full] = (int) $r['user_id'];

            // costruisco anche versione invertita
            $parts = explode(' ', $full);
            if (count($parts) >= 2) {
                $rev = $norm(implode(' ', array_reverse($parts)));
                $byNormReversed[$rev] = (int) $r['user_id'];
            }
        }

        if (isset($byNorm[$needle])) {
            $responsabile_id = $byNorm[$needle];
        } elseif (isset($byNormReversed[$needle])) {
            $responsabile_id = $byNormReversed[$needle];
        } else {
            // fallback LIKE caseless
            $try = $database->query(
                "SELECT user_id FROM personale
                 WHERE LOWER(TRIM(REPLACE(Nominativo,'  ',' '))) = LOWER(TRIM(REPLACE(?, '  ',' ')))
                 LIMIT 1",
                [(string) $responsabile_raw],
                __FILE__
            )->fetchColumn();
            if ($try)
                $responsabile_id = (int) $try;
        }
    }
}

/* --- Carica tutti gli utenti (per avatar/badge) --- */
$utenti_stmt = $database->query("SELECT user_id, Nominativo, Ruolo, Email_Aziendale FROM personale", [], __FILE__);
$utenti = $utenti_stmt ? $utenti_stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

// Applica fixMojibake ai dati del personale
if ($utenti) {
    foreach ($utenti as &$u) {
        $u['Nominativo'] = fixMojibake($u['Nominativo'] ?? '');
        $u['Ruolo'] = fixMojibake($u['Ruolo'] ?? '');
    }
    unset($u);
}
$utenti_map = [];
foreach ($utenti as $u) {
    $parsed = estraiDisciplinaDaRuolo($u['Ruolo'] ?? '');
    $img = function_exists('getProfileImage')
        ? getProfileImage($u['Nominativo'], 'nominativo')
        : 'assets/images/default_profile.png';
    $utenti_map[(int) $u['user_id']] = [
        'nome' => $u['Nominativo'],
        'email' => $u['Email_Aziendale'] ?? '',
        'img' => htmlspecialchars($img, ENT_QUOTES),
        'disciplina' => $parsed['disciplina'],
        'subdisciplina' => $parsed['subdisciplina'],
        'badge' => $parsed['badge'],
    ];
}

/* --- Carica organigramma salvato --- */
$organigramma_salvato = null;
$res = $database->query(
    "SELECT organigramma FROM commesse_bacheche WHERE id = ?",
    [$commessa_id],
    __FILE__
)->fetch();

if ($res && !empty($res['organigramma'])) {
    $tmp = json_decode($res['organigramma'], true);
    $organigramma_salvato = is_array($tmp) ? $tmp : null;
}
if (is_array($organigramma_salvato)) {
    if (!isset($organigramma_salvato['children']) || !is_array($organigramma_salvato['children'])) {
        $organigramma_salvato['children'] = [];
    }
}

/* ------------------------------------------------------------------
   VISTA TABELLA: flattiamo l’albero con eredità disciplina:
   - livello 0: Responsabile commessa (root)
   - livello 1: Responsabile disciplina (ha node.disciplines[0])
   - livello >=2: Membro (eredita disciplina dal responsabile di divisione)
------------------------------------------------------------------- */
$righeTabella = []; // array di: user_id, nome, ruolo, disciplina, livello, email, img

$pushRiga = function ($uid, $ruolo, $disc, $level) use (&$righeTabella, $utenti_map) {
    if (!$uid)
        return;
    $u = $utenti_map[(int) $uid] ?? null;
    $righeTabella[] = [
        'user_id' => (int) $uid,
        'nome' => $u['nome'] ?? '—',
        'email' => $u['email'] ?? '',
        'img' => $u['img'] ?? 'assets/images/default_profile.png',
        'ruolo' => $ruolo,
        'disciplina' => $disc ?: ($u['disciplina'] ?? ''),
        'livello' => (int) $level
    ];
};

/**
 * Ricorsione:
 * $node: { user_id, children[], disciplines? }
 * $level: 0 root / 1 divisione / 2+ membri
 * $discDivisione: disciplina “di fascia” (dai nodi livello 1)
 */
$flatten = function ($node, $level = 0, $discDivisione = null) use (&$flatten, $pushRiga, $responsabile_id) {
    if (!is_array($node))
        return;

    // definizione ruolo / disciplina corrente
    if ($level === 0) {
        // root (responsabile commessa)
        $pushRiga($node['user_id'] ?? $responsabile_id, 'Responsabile commessa', null, 0);
    } else {
        if ($level === 1) {
            // responsabile disciplina: usa la prima discipline se presente
            $disc = null;
            if (!empty($node['disciplines']) && is_array($node['disciplines'])) {
                $disc = $node['disciplines'][0] ?? null;
            }
            $pushRiga($node['user_id'] ?? null, 'Responsabile disciplina', $disc, 1);
            $discDivisione = $disc ?: $discDivisione; // fissa la divisione
        } else {
            // membro
            $pushRiga($node['user_id'] ?? null, 'Membro', $discDivisione, $level);
        }
    }

    // figli
    if (!empty($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $flatten($child, $level + 1, $discDivisione);
        }
    }
};

if ($organigramma_salvato) {
    // se root mancante nel JSON, mettiamo responsabile
    $rootNode = $organigramma_salvato;
    if (!isset($rootNode['user_id']) || $rootNode['user_id'] === null) {
        $rootNode['user_id'] = $responsabile_id;
    }
    $flatten($rootNode, 0, null);
}

// Ordine: per disciplina poi per ruolo (Resp commessa, Resp disciplina, Membro) poi nome
usort($righeTabella, function ($a, $b) {
    $k1 = ($a['ruolo'] === 'Responsabile commessa' ? 0 : ($a['ruolo'] === 'Responsabile disciplina' ? 1 : 2));
    $k2 = ($b['ruolo'] === 'Responsabile commessa' ? 0 : ($b['ruolo'] === 'Responsabile disciplina' ? 1 : 2));
    return [$a['disciplina'], $k1, $a['nome']] <=> [$b['disciplina'], $k2, $b['nome']];
});
?>
<div class="commessa-organigramma">
    <?php // renderPageTitle('Organigramma', '#C0392B'); ?>

    <!-- VISTA ALBERO -->
    <div id="org-tree-view">
        <div class="org-main-wrap">
            <div class="org-canvas-area">
                <div class="org-tree-scrollwrap">
                    <div class="org-tree-inner">
                        <div id="org-fasce-area" class="org-fasce-area"></div>
                    </div>
                </div>
            </div>
            <!-- Sidebar strumenti -->
            <div id="org-sidebar" class="org-sidebar-toolbox">
                <div class="toolbox-header">Strumenti</div>
                <div class="toolbox-section">
                    <div class="toolbox-title">Discipline disponibili</div>
                    <div id="sidebar-disciplines" class="toolbox-badges"></div>
                </div>
                <div class="toolbox-section">
                    <div class="toolbox-title">Persone disponibili</div>
                    <input type="text" id="org-search-users" class="org-users-search" placeholder="Cerca persona..."
                        autocomplete="off" style="margin-bottom:6px;">
                    <div id="sidebar-users" class="org-users-scroll toolbox-badges"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- VISTA TABELLA -->
    <div id="org-table-view" class="hidden">
        <div id="filter-container" data-table="organigramma_flat" data-filters='["nome","disciplina","ruolo"]'>
        </div>

        <table class="table table-filterable" id="orgTable" style="width:100%; margin-top:10px;">
            <thead>
                <tr>
                    <th style="min-width:46px;">&nbsp;</th>
                    <th>Nome</th>
                    <th>Disciplina</th>
                    <th>Ruolo</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($righeTabella as $r): ?>
                    <tr>
                        <td style="min-width:46px;">
                            <img src="<?= htmlspecialchars($r['img']) ?>" alt=""
                                style="width:26px;height:26px;border-radius:50%;object-fit:cover;vertical-align:middle;box-shadow:0 0 0 1.5px #ccc;">
                        </td>
                        <td>
                            <?= htmlspecialchars($r['nome'] ?? '—') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['disciplina'] ?: '—') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['ruolo'] ?? '—') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['email'] ?? '') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($righeTabella)): ?>
            <div style="padding:22px; color:#888;">Nessun componente nell’organigramma.</div>
        <?php endif; ?>
    </div>
</div>

<script>
    /* --- Boot dati JS per vista albero --- */
    window._orgUtenti = <?= json_encode($utenti_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window._orgInitialData = <?= json_encode($organigramma_salvato ?? []) ?>;
    window._commessaId = <?= (int) $commessa_id ?>;
    window._respCommessaId = <?= ($responsabile_id !== null) ? (int) $responsabile_id : 'null' ?>;
    // NON persistiamo la root in JSON: la root è sempre il responsabile
    window._orgDontPersistRoot = true;
</script>

<script src="/assets/js/commesse/commessa_organigramma.js"></script>