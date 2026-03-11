<?php

function ScanDocuments($dir) {            
    try {
        $documentiPath = (substr(dirname(__FILE__),0,strpos(dirname(__FILE__), '/views')).'/'.$dir);
        if ($documentiPath && is_dir($documentiPath)) {
            $folders = array_filter(glob($documentiPath . '/*'), 'is_dir');
            $menus = (array) null;

            foreach ($folders as $folder) {
                $folderName = basename($folder);
                $menus[] = [
                    'title' => ucfirst($folderName),
                    'link' => 'index.php?section=archivio&page=' . urlencode($folderName),
                ];
            }
            return json_encode($menus);
        } else {
            throw new Exception("Percorso non valido o inesistente: $documentiPath");
            //throw new Exception($data['error'] ?? 'Errore sconosciuto nell\'API.');
        }
    } catch (Exception $e) {
        error_log('Errore durante la chiamata API: ' . $e->getMessage());
        return [
            [
                'title' => 'Errore durante il caricamento',
                'link' => 'javascript:void(0);',
            ],
        ];
    }
}
$Scansione= ScanDocuments();
                
return [
    'archivio' => [
        'section' => 'archivio',
        'label' => 'Archivio',
        'menus' => $Scansione
    ],

    'collaborazione' => [
        'section' => 'collaborazione',
        'label' => 'Collaborazione',
        'menus' => [
            [
                'title' => 'Richieste e Segnalazioni',
                'api' => '/api/sidebar/get_forms_menu.php',
            ],
            [
                'title' => 'Comunicazione',
                'submenus' => [
                    [
                        'title' => 'Protocollo',
                        'link' => 'index.php?section=collaborazione&page=mail',
                    ],
                    [
                        'title' => 'Messaggistica',
                        'link' => 'index.php?section=collaborazione&page=messaggistica',
                    ],
                ],
            ],
            [
                'title' => 'Task Management',
                'api' => '/api/sidebar/get_boards_menu.php',
            ],
        ],
    ],


    'area-tecnica' => [
        'section' => 'area-tecnica',
        'label' => 'Area Tecnica',
        'menus' => [
            [
                'title' => 'Standard Progetti',
                'link' => 'index.php?section=area-tecnica&page=pagina_vuota',
            ],
            [
                'title' => 'Ingegneria Strutturale',
                'link' => 'index.php?section=area-tecnica&page=ingegneria_str',
            ],
            [
                'title' => 'Ingegneria MEP',
                'link' => 'index.php?section=area-tecnica&page=ingegneria_mep',
            ],
        ],
    ],

    'gestione' => [
        'section' => 'gestione',
        'label' => 'Gestione',
        'menus' => [
            [
                'title' => 'Area Commerciale',
                'submenus' => [
                    [
                        'title' => 'Gare',
                        'link' => 'index.php?section=gestione&page=gare',
                    ],
                    [
                        'title' => 'Offerte',
                        'link' => 'index.php?section=gestione&page=offerte',
                    ],
                    [
                        'title' => 'CRM',
                        'link' => 'index.php?section=gestione&page=crm',
                    ],
                ],
            ],
            [
                'title' => 'Amministrazione',
                'submenus' => [
                    [
                        'title' => 'Elenco Fornitori',
                        'link' => 'index.php?section=gestione&page=elenco_fornitori',
                    ],
                    [
                        'title' => 'Qualifica Fornitori',
                        'link' => 'index.php?section=gestione&page=qualifica_fornitori',
                    ],
                    [
                        'title' => 'Riesame Ordini',
                        'link' => 'index.php?section=gestione&page=riesame_ordini',
                    ],
                ],
            ],
            [
                'title' => 'Gestione Personale',
                'submenus' => [
                    [
                        'title' => 'Contatti',
                        'link' => 'index.php?section=gestione&page=contacts',
                    ],
                    [
                        'title' => 'Organigramma',
                        'link' => 'index.php?section=gestione&page=organigram',
                    ],
                    [
                        'title' => 'Mappa Ufficio',
                        'link' => 'index.php?section=gestione&page=office_map_public',
                    ],
                    [
                        'title' => 'Mappa Ufficio (Admin)',
                        'link' => 'index.php?section=gestione&page=office_map',
                    ],
                    [
                        'title' => 'Ferie e Permessi',
                        'link' => 'index.php?section=gestione&page=ferie_permessi',
                    ],
                    [
                        'title' => 'Rimborsi',
                        'link' => 'index.php?section=gestione&page=rimborsi',
                    ],
                    [
                        'title' => 'Formazione',
                        'link' => 'index.php?section=gestione&page=formazione',
                    ],
                    [
                        'title' => 'Sicurezza',
                        'link' => 'index.php?section=gestione&page=sicurezza',
                    ],
                ],
            ],
            [
                'title' => 'Gestione HR',
                'submenus' => [
                    [
                        'title' => 'Dashboard HR',
                        'link' => 'index.php?section=gestione&page=hr_dashboard',
                    ],
                    [
                        'title' => 'Selezione Personale',
                        'link' => 'index.php?section=gestione&page=candidate_selection_kanban',
                    ],
                    [
                        'title' => 'Job Profile',
                        'link' => 'index.php?section=gestione&page=job_profile',
                    ],
                    [
                        'title' => 'Apertura Ricerca',
                        'link' => 'index.php?section=gestione&page=open_search',
                    ],
                    [
                        'title' => 'Anagrafiche HR',
                        'link' => 'index.php?section=gestione&page=anagrafiche_hr',
                    ],
                ],
            ],
        ],
    ],

    'profile' => [
        'section' => 'profile',
        'label' => 'Profilo Utente',
        'menus' => [
            [
                'title' => 'Modifica Profilo',
                'link' => 'index.php?section=profile&page=profile&tab=personal-info',
            ],
            [
                'title' => 'Cambio Password',
                'link' => 'index.php?section=profile&page=profile&tab=password',
            ],
            [
                'title' => 'Bio',
                'link' => 'index.php?section=profile&page=profile&tab=bio',
            ],
            [
                'title' => 'Competenze',
                'link' => 'index.php?section=profile&page=profile&tab=competenze',
            ],
        ],
    ],

    'login' => [
        'section' => 'login',
        'label' => 'Login',
        'menus' => [
            [
                'title' => 'Login',
                'link' => 'index.php?section=login&page=login',
            ],
        ],
    ],

    'logout' => [
        'section' => 'logout',
        'label' => 'Logout',
        'menus' => [
            [
                'title' => 'Logout',
                'link' => 'index.php?section=login&page=logout',
            ],
        ],
    ],
];
