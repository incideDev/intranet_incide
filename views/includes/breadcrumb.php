<?php
// Definizione breadcrumb personalizzati per ogni pagina statica
$breadcrumbCustom = [
    'gare' => [
        'Gestione' => null,
        'Area Commerciale' => null,
        'Gare' => 'index.php?section=gestione&page=gare'
    ],
    'archivio_gare' => [
        'Gestione' => null,
        'Area Commerciale' => null,
        'Gare' => 'index.php?section=gestione&page=gare',
        'Archivio Gare' => '#'
    ],
    'gare_valutazione' => [
        'Gestione' => null,
        'Area Commerciale' => null,
        'Gare' => 'index.php?section=gestione&page=gare',
        'Archivio Gare' => 'index.php?section=gestione&page=gare&view=archivio_gare',
        'Scheda Gara' => null
    ],
    'contacts' => [
        'Gestione' => null,
        'Gestione Personale' => null,
        'Contatti' => 'index.php?section=gestione&page=contacts'
    ],
    'office_map_public' => [
        'Gestione' => null,
        'Gestione Personale' => null,
        'Mappa Ufficio' => 'index.php?section=gestione&page=office_map_public'
    ],
    'office_map' => [
        'Gestione' => null,
        'Gestione Personale' => null,
        'Gestione Ufficio' => 'index.php?section=gestione&page=office_map'
    ],
    'hr_dashboard' => [
        'Gestione' => null,
        'Gestione HR' => null,
        'Dashboard HR' => 'index.php?section=gestione&page=hr_dashboard'
    ],
    'candidate_selection_kanban' => [
        'Gestione' => null,
        'Gestione HR' => null,
        'Selezione Personale' => 'index.php?section=gestione&page=candidate_selection_kanban'
    ],
    'job_profile' => [
        'Gestione' => null,
        'Gestione HR' => null,
        'Job Profile' => 'index.php?section=gestione&page=job_profile'
    ],
    'open_search' => [
        'Gestione' => null,
        'Gestione HR' => null,
        'Apertura Ricerca' => 'index.php?section=gestione&page=open_search'
    ],
    'anagrafiche_hr' => [
        'Gestione' => null,
        'Gestione HR' => null,
        'Anagrafiche HR' => 'index.php?section=gestione&page=anagrafiche_hr'
    ],
    'mail' => [
        'Collaborazione' => null,
        'Comunicazione' => null,
        'Codice Protocollo' => 'index.php?section=collaborazione&page=mail'
    ],
    'messaggistica' => [
        'Collaborazione' => null,
        'Comunicazione' => null,
        'Messaggistica' => 'index.php?section=collaborazione&page=messaggistica'
    ]
];

$breadcrumbCustom['segnalazioni_dashboard'] = [
    'Collaborazione' => null,
    'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard'
];

$breadcrumbCustom['gestione_segnalazioni'] = [
    'Collaborazione' => null,
    'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
    'Gestione Segnalazioni' => null
];

$breadcrumbCustom['moduli_admin'] = [
    'Collaborazione' => null,
    'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
    'Gestione Moduli' => null
];

$breadcrumbCustom['task_management'] = [];

if (!empty($_GET['section']) && $_GET['section'] === 'collaborazione' && $_GET['page'] === 'task_management') {
    if (!isset($_GET['board']) && isset($_GET['dashboard'])) {
        $breadcrumbCustom['task_management'] = [
            'Collaborazione' => null,
            'Task Management' => null,
            'Dashboard Task' => 'index.php?section=collaborazione&page=task_management&dashboard=true'
        ];
    }
}

// **Gestione dinamica per i form delle  Segnalazioni**
if (!empty($_GET['form_name'])) {
    $formName = urldecode($_GET['form_name']);

    // Se siamo nella visualizzazione compilata
    if (!empty($_GET['page']) && $_GET['page'] === 'form_view') {
        $breadcrumbCustom['form_view'] = [
            'Collaborazione' => null,
            'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
            $formName => 'index.php?page=form&form_name=' . urlencode($formName)
        ];
    }

    // Se siamo nella compilazione/invio del form
    elseif (!empty($_GET['page']) && $_GET['page'] === 'form') {
        $breadcrumbCustom['form'] = [
            'Collaborazione' => null,
            'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
            $formName => null
        ];
    }
}

// 📁 Gestione dinamica per l'Archivio Documenti e sottosezioni
if (!empty($_GET['page']) && is_dir("Caricamento_Documenti/" . $_GET['page'])) {
    $folderName = str_replace('_', ' ', $_GET['page']);
    $breadcrumbCustom[$_GET['page']] = [
        'Collaborazione' => null,
        'Archivio Documenti' => 'index.php?page=archivio',
        ucwords($folderName) => null
    ];
} elseif ($_GET['page'] === 'archivio') {
    $breadcrumbCustom['archivio'] = [
        'Collaborazione' => null,
        'Archivio Documenti' => null
    ];
}

// **Gestione dinamica per le Bacheche di Task Management**
if (!empty($_GET['section']) && $_GET['section'] === 'collaborazione' && $_GET['page'] === 'task_management' && isset($_GET['board'])) {
    $boardId = intval($_GET['board']);

    // Inizialmente mostriamo "Bacheca #ID" nel breadcrumb come pagina corrente
    $breadcrumbCustom['task_management'] = [
        'Collaborazione' => null,
        'Task Management' => null,
        'Bacheca #' . $boardId => 'index.php?section=collaborazione&page=task_management&board=' . $boardId
    ];

    // **Script per aggiornare solo il testo della bacheca senza renderlo un link**
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            fetch('/PROJECTS/intra_incide/api/tasks/get_bacheca_nome.php?boardId=$boardId')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let breadcrumbItems = document.querySelectorAll('.breadcrumb ul li');
                        let lastItem = breadcrumbItems[breadcrumbItems.length - 1]; // Ultimo elemento (bacheca)
                        if (lastItem) {
                            lastItem.innerHTML = '<a href=\"index.php?section=collaborazione&page=task_management&board=$boardId\">' + data.nome + '</a>';
                        }
                    }
                })
                .catch(error => console.error('Errore nel caricamento della bacheca:', error));
        });
    </script>";
}


// Recupera la pagina corrente
$page = $_GET['page'] ?? null;
$breadcrumb = $breadcrumbCustom[$page] ?? null;
?>

<div class="breadcrumb" id="breadcrumb">
    <ul>
        <?php if ($breadcrumb): ?>
            <?php 
            $total = count($breadcrumb);
            $index = 0;
            foreach ($breadcrumb as $title => $link): 
                $index++;
                $isLast = ($index === $total);
            ?>
                <li>
                    <?php if (!$isLast && $link): ?> 
                        <!-- ✅ Ora le voci intermedie sono sempre cliccabili -->
                        <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php elseif ($isLast): ?>
                        <!-- ❌ L'ultima voce non è cliccabile perché siamo già su questa pagina -->
                        <span class="current"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        <!-- ❌ Voci senza link rimangono non cliccabili -->
                        <span class="no-link"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </li>
                <?php if (!$isLast): ?>
                    <li class="separator">/</li>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <li class="current">Breadcrumb non definito per questa pagina</li>
        <?php endif; ?>
    </ul>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        function updateBreadcrumb(view) {
            const breadcrumb = document.getElementById("breadcrumb");
            if (!breadcrumb) return;

            let newBreadcrumb = '';

            if (view === "archivio_gare") {
                newBreadcrumb = `
                    <ul>
                        <li><span class="no-link">Gestione</span></li>
                        <li class="separator">/</li>
                        <li><span class="no-link">Area Commerciale</span></li>
                        <li class="separator">/</li>
                        <li><a href="index.php?section=gestione&page=gare">Gare</a></li>
                        <li class="separator">/</li>
                        <li><span class="current">Archivio Gare</span></li>
                    </ul>
                `;
            } else if (view === "gare") {
                newBreadcrumb = `
                    <ul>
                        <li><span class="no-link">Gestione</span></li>
                        <li class="separator">/</li>
                        <li><span class="no-link">Area Commerciale</span></li>
                        <li class="separator">/</li>
                        <li><a href="index.php?section=gestione&page=gare" class="current">Gare</a></li>
                    </ul>
                `;
            }

            breadcrumb.innerHTML = newBreadcrumb;
        }

        // ✅ Usa toggle-view per cambiare il breadcrumb
        const toggleViewBtn = document.getElementById("toggle-view");
        if (toggleViewBtn) {
            toggleViewBtn.addEventListener("click", function () {
                const tableView = document.getElementById("table-view");
                const isArchivioVisibile = !tableView.classList.contains("hidden");
                updateBreadcrumb(isArchivioVisibile ? "gare" : "archivio_gare");
            });
        } else {
            console.warn("⚠️ Bottone 'toggle-view' non trovato nel DOM.");
        }

        // ✅ Gestione breadcrumb-archivio-gare per ricaricare la vista corretta
        const breadcrumbArchivioGare = document.getElementById("breadcrumb-archivio-gare");
        if (breadcrumbArchivioGare) {
            breadcrumbArchivioGare.addEventListener("click", function(event) {
                event.preventDefault(); // Evita il comportamento di default del link
                if (window.opener) {
                    // ✅ Se la pagina è stata aperta in una nuova finestra/tab, ricarica la vista corretta
                    window.opener.location.href = "index.php?section=gestione&page=gare";
                    window.close();
                } else if (typeof toggleArchivioGare === "function") {
                    // ✅ Attiva direttamente il toggle
                    toggleArchivioGare();
                } else {
                    console.error("❌ ERRORE: Funzione toggleArchivioGare non trovata.");
                    window.location.href = "index.php?section=gestione&page=gare&view=archivio_gare"; // ✅ Fallback
                }
            });
        }
    });
</script>
