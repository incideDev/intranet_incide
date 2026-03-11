$titolo_principale = '';
$titolo_secondario = '';

switch ($page) {
    case 'home':
        $titolo_principale = 'HOME';
        break;
    case 'contacts':
        $titolo_principale = 'CONTATTI';
        break;
    case 'biblioteca':
        $titolo_principale = 'BIBLIOTECA';
        break;
    case 'archivio':
        $titolo_principale = 'ARCHIVIO';
        break;
    case 'mail':
        $titolo_principale = 'POSTA';
        break;
    // Aggiungi altri case per le pagine principali
    default:
        $titolo_principale = 'INTRANET';
        break;
}

// Determina il titolo secondario se c'è una subpage
if (isset($_GET['subpage'])) {
    switch ($_GET['subpage']) {
        case 'corrispondenza':
            $titolo_secondario = 'CORRISPONDENZA';
            break;
        // Aggiungi altri case per le sottopagine
    }
}

// Combina i titoli
$titolo_completo = $titolo_principale;
if ($titolo_secondario) {
    $titolo_completo .= ' / ' . $titolo_secondario;
}
