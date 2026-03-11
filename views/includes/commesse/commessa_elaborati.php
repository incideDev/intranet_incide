<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Segnala alla view elenco_documenti che è inclusa come partial dentro commessa,
// così disabilita il suo permission check autonomo e il wrapper .main-container.
define('ED_EMBEDDED_IN_COMMESSA', true);

// $tabella è definita dal contesto padre (commessa.php) e contiene l'IdProject (es. "3DY01").
// La view elenco_documenti la legge tramite la variabile $tabella.

include __DIR__ . '/../../elenco_documenti.php';
