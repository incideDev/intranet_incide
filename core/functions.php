<?php
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accesso diretto non consentito.');
}

function norm(string $s): string
{
    return preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
}

function getGestioneIntranetMenu()
{
    return [
        'impostazioni_generali' => [
            'section' => 'gestione_intranet',
            'label' => 'Impostazioni Generali',
            'menus' => [
                [
                    'title' => 'Impostazioni Generali',
                    'submenus' => [
                        [
                            'title' => 'Pagine',
                            'link' => 'index.php?section=gestione_intranet&page=impostazioni_moduli'
                        ],
                        [
                            'title' => 'Modalità Manutenzione',
                            'link' => 'index.php?section=gestione_intranet&page=maintenance_settings'
                        ],
                        [
                            'title' => 'Datasources (Whitelist DB)',
                            'link' => 'index.php?section=gestione_intranet&page=sys_datasources'
                        ],

                    ]
                ]
            ]
        ],
        'gestione_user' => [
            'section' => 'gestione_intranet',
            'label' => 'Gestione User',
            'menus' => [
                [
                    'title' => 'Gestione User',
                    'submenus' => [
                        [
                            'title' => 'Gestione Utenti',
                            'link' => 'index.php?section=gestione_intranet&page=reset_user'
                        ],
                        [
                            'title' => 'Gestione Ruoli',
                            'link' => 'index.php?section=gestione_intranet&page=gestione_ruoli'
                        ]
                    ]
                ]
            ]
        ],
        'tool' => [
            'section' => 'gestione_intranet',
            'label' => 'Tool',
            'menus' => [
                [
                    'title' => 'Tool',
                    'submenus' => [
                        [
                            'title' => 'Import Manager',
                            'link' => 'index.php?section=gestione_intranet&page=import_manager'
                        ]
                    ]
                ]
            ]
        ],
    ];
}

function getStaticMenu($forceSection = null, $fullMenu = false, $injectNavId = false)
{
    // NON usare $section come variabile locale (creava shadowing nel foreach)
    $currentSection = $forceSection ?? ($_GET['section'] ?? null);

    // Se chiesto solo gestione_intranet e NON vuoi il fullMenu (cioè SOLO la sidebar di quella sezione)
    if ($currentSection === 'gestione_intranet' && !$fullMenu) {
        $giMenu = getGestioneIntranetMenu();
        return [
            'gestione_intranet' => [
                'section' => 'gestione_intranet',
                'label' => 'Gestione Intranet',
                'menus' => array_merge(
                    $giMenu['impostazioni_generali']['menus'],
                    $giMenu['gestione_user']['menus'],
                    $giMenu['tool']['menus']
                )
            ]
        ];
    }
    $menu = [
        'collaborazione' => [
            'section' => 'collaborazione',
            'label' => 'Collaborazione',
            'menus' => array_filter([
                userHasPermission('view_segnalazioni') ? [
                    'title' => 'Segnalazioni',
                    'submenus' => getSegnalazioniMenu()
                ] : null,
                userHasPermission('view_protocollo_email') ? [
                    'title' => 'Comunicazione',
                    'submenus' => [
                        [
                            'title' => 'Protocollo Comunicazioni',
                            'link' => 'index.php?section=collaborazione&page=protocollo_email'
                        ]
                    ]
                ] : null,
                userHasPermission('view_mom') ? [
                    'title' => 'Verbali Riunione',
                    'submenus' => [
                        [
                            'title' => 'MOM (Verbali Riunione)',
                            'link' => 'index.php?section=collaborazione&page=mom'
                        ]
                    ]
                ] : null
            ])
        ],

        'commesse' => [
            'section' => 'commesse',
            'label' => 'Commesse',
            'menus' => array_filter([
                userHasPermission('view_commesse') ? [
                    'title' => 'Gestione Commesse',
                    'submenus' => [
                        [
                            'title' => 'Dashboard',
                            'link' => 'index.php?section=commesse&page=dashboard_commesse'
                        ],
                        [
                            'title' => 'Elenco Commesse',
                            'link' => 'index.php?section=commesse&page=elenco_commesse'
                        ],
                        [
                            'title' => 'Archivio Commesse',
                            'link' => 'index.php?section=commesse&page=archivio_commesse'
                        ]
                    ]
                ] : null,
                userHasPermission('view_dashboard_ore') ? [
                    'title' => 'Gestione Ore',
                    'submenus' => [
                        [
                            'title' => 'Dashboard Ore',
                            'link' => 'index.php?section=commesse&page=dashboard_ore'
                        ],
                        [
                            'title' => 'Business Unit',
                            'link' => 'index.php?section=commesse&page=ore_business_unit'
                        ],
                        [
                            'title' => 'Dettaglio Utente',
                            'link' => 'index.php?section=commesse&page=ore_dettaglio_utente'
                        ]
                    ]
                ] : null,
                userHasPermission('view_dashboard_economica') ? [
                    'title' => 'Dashboard Economica',
                    'link' => 'index.php?section=commesse&page=dashboard_economica'
                ] : null,
            ])
        ],

        'area-tecnica' => [
            'section' => 'area-tecnica',
            'label' => 'Area Tecnica',
            'menus' => [
                ['title' => 'Standard Progetti', 'link' => 'index.php?section=area-tecnica&page=pagina_vuota'],
                ['title' => 'Ingegneria Strutturale', 'link' => 'index.php?section=area-tecnica&page=ingegneria_str'],
                ['title' => 'Ingegneria MEP', 'link' => 'index.php?section=area-tecnica&page=ingegneria_mep']
            ],
        ],

        'hr' => [
            'section' => 'hr',
            'label' => 'HR',
            'menus' => [
                [
                    'title' => 'Gestione Personale',
                    'submenus' => array_filter([
                        userHasPermission('view_contatti') ? ['title' => 'Contatti', 'link' => 'index.php?section=hr&page=contacts'] : null,
                        userHasPermission('view_mappa') ? ['title' => 'Mappa Ufficio', 'link' => 'index.php?section=hr&page=office_map_public'] : null,
                        userHasPermission('view_mappa_admin') ? ['title' => 'Mappa Ufficio Admin', 'link' => 'index.php?section=hr&page=office_map'] : null
                    ])
                ],
                [
                    'title' => 'Recruiting',
                    'submenus' => [
                        ['title' => 'Gestione CV', 'link' => 'index.php?section=hr&page=cv_manager']
                    ]
                ]
            ]
        ],

        'commerciale' => [
            'section' => 'commerciale',
            'label' => 'Commerciale',
            'menus' => [
                [
                    'title' => 'Gare',
                    'submenus' => array_filter([
                        userHasPermission('view_gare') ? [
                            'title' => 'Estrazione Bandi',
                            'link' => 'index.php?section=commerciale&page=estrazione_bandi'
                        ] : null,
                        userHasPermission('view_gare') ? [
                            'title' => 'Elenco Gare',
                            'link' => 'index.php?section=commerciale&page=elenco_gare'
                        ] : null,
                        userHasPermission('view_gare') ? [
                            'title' => 'Requisiti',
                            'link' => 'index.php?section=commerciale&page=requisiti'
                        ] : null,
                    ])
                ],
                userHasPermission('view_mom') ? [
                    'title' => 'Verbali (MOM)',
                    'link' => 'index.php?section=commerciale&page=mom'
                ] : null
            ]
        ],

        'gestione' => [
            'section' => 'gestione',
            'label' => 'Gestione',
            'menus' => [
                // Sezione gestione mantenuta vuota o per altri scopi futuri
            ]
        ],


        'profilo' => [
            'section' => 'profilo',
            'label' => 'Gestione Profilo',
            'menus' => [
                [
                    'title' => 'Gestione Profilo',
                    'submenus' => [
                        ['title' => 'Modifica Profilo', 'link' => 'index.php?section=profilo&page=gestione_profilo&tab=personal-info'],
                        ['title' => 'Cambio Password', 'link' => 'index.php?section=profilo&page=gestione_profilo&tab=password'],
                        ['title' => 'Bio', 'link' => 'index.php?section=profilo&page=gestione_profilo&tab=bio'],
                    ]
                ],
                userHasPermission('view_gestione_ruoli') ? [
                    'title' => 'Gestione Ruoli',
                    'submenus' => [
                        ['title' => 'Gestisci Ruoli', 'link' => 'index.php?section=profilo&page=gestione_ruoli&tab=gestione'],
                        ['title' => 'Assegna Ruoli', 'link' => 'index.php?section=profilo&page=gestione_ruoli&tab=assegnazione'],
                    ]
                ] : null,
                userHasPermission('view_gestione_ruoli') ? [
                    'title' => 'Gestione Utenti',
                    'submenus' => [
                        ['title' => 'Reset Password Utente', 'link' => 'index.php?section=profilo&page=reset_user']
                        // Qui in futuro aggiungi altre voci tipo "Elimina utente", "Sospendi utente", ecc
                    ]
                ] : null
            ],
        ],

        'notifiche' => [
            'section' => 'notifiche',
            'label' => 'Notifiche',
            'menus' => [
                ['title' => 'Centro Notifiche', 'link' => 'index.php?section=notifiche&page=centro_notifiche']
            ],
        ],

        'changelog' => [
            'section' => 'Intranet News',
            'label' => 'Intranet News',
            'menus' => array_filter([
                [
                    'title' => 'Novità e Miglioramenti',
                    'link' => 'index.php?section=changelog&page=changelog'
                ],
                userHasPermission('view_gestione_changelog') ? [
                    'title' => 'Gestione Changelog',
                    'link' => 'index.php?section=changelog&page=changelog_admin'
                ] : null
            ])
        ],
    ];

    // Iniezione dinamica aree dal DocumentAreaRegistry
    $registry = \Services\DocumentAreaRegistry::getRegistry();
    foreach ($registry as $areaKey => $areaConfig) {
        if (!userHasPermission($areaConfig['permissions']['view'])) {
            continue;
        }
        $uiHost = $areaConfig['ui_host'];
        $targetSection = ($uiHost === 'root') ? $areaKey : $uiHost;

        if (!isset($menu[$targetSection])) {
            $menu[$targetSection] = [
                'section' => $targetSection,
                'label' => $areaConfig['label'],
                'menus' => []
            ];
        }

        if ($uiHost === 'root') {
            $menu[$targetSection]['menus'][] = [
                'title' => 'Dashboard ' . $areaConfig['label'],
                'link' => 'index.php?section=' . urlencode($areaKey) . '&page=' . urlencode($areaKey)
            ];
        } else {
            // Area ospitata in altra sezione (es. formazione dentro hr)
            // Carica le pagine e genera sotto-voci dinamiche
            $areaPages = getDocumentAreaPages($areaKey);
            $submenus = array_map(function ($riga) use ($uiHost, $areaKey) {
                return [
                    'title' => ucfirst(str_replace('_', ' ', $riga['titolo'])),
                    'link' => 'index.php?section=' . urlencode($uiHost) . '&page=' . urlencode($riga['slug'])
                ];
            }, $areaPages);

            // Aggiungi sempre il link dashboard come prima voce
            array_unshift($submenus, [
                'title' => 'Dashboard ' . $areaConfig['label'],
                'link' => 'index.php?section=' . urlencode($uiHost) . '&page=' . urlencode($areaKey)
            ]);

            $menu[$targetSection]['menus'][] = [
                'title' => $areaConfig['label'],
                'submenus' => $submenus
            ];
        }
    }

    foreach ($menu as $sectionKey => &$sectionData) {
        if (!isset($sectionData['menus']) || !is_array($sectionData['menus'])) {
            unset($menu[$sectionKey]);
            continue;
        }
        $sectionData['menus'] = array_filter($sectionData['menus'], function ($menuItem) {
            if (isset($menuItem['submenus']) && is_array($menuItem['submenus'])) {
                foreach ($menuItem['submenus'] as $sm) {
                    if (!empty($sm['title']))
                        return true;
                }
                return false;
            }
            return isset($menuItem['link']) || isset($menuItem['api']);
        });
        $sectionData['menus'] = array_values($sectionData['menus']);
        if (empty($sectionData['menus'])) {
            unset($menu[$sectionKey]);
        }
    }

    // Filtro finale dinamico: rimuovi menu senza pagine (submenus vuoti) per tutte le aree Registry
    foreach (\Services\DocumentAreaRegistry::getRegistry() as $areaKey => $areaConf) {
        $target = ($areaConf['ui_host'] === 'root') ? $areaKey : $areaConf['ui_host'];
        if (isset($menu[$target]['menus']) && is_array($menu[$target]['menus'])) {
            $menu[$target]['menus'] = array_filter($menu[$target]['menus'], function ($menuItem) {
                if ($menuItem === null)
                    return false;
                if (isset($menuItem['submenus']) && is_array($menuItem['submenus'])) {
                    return !empty($menuItem['submenus']);
                }
                return isset($menuItem['link']) || isset($menuItem['api']);
            });
            $menu[$target]['menus'] = array_values($menu[$target]['menus']);
        }
    }

    // (Block moved to end of function)
    global $database;
    $resCustom = $database->query(
        "SELECT id, section, parent_title, title, link, attivo
         FROM menu_custom
         WHERE attivo = 1
         ORDER BY ordinamento ASC, id ASC",
        [],
        __FILE__ . ' ⇒ menu_custom'
    );

    if ($resCustom) {
        while ($row = $resCustom->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            $sec = (string) ($row['section'] ?? '');
            $parent = (string) ($row['parent_title'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $link = (string) ($row['link'] ?? '');

            // FIX: Evita duplicati per Segnalazioni, gestite separatamente da getSegnalazioniMenu()
            if ($sec === 'collaborazione' && mb_strtolower($parent) === 'segnalazioni') {
                continue;
            }

            // Genera nav_id univoco
            $navId = 'custom_' . $id;

            // Iniettalo nel link subito (SOLO se richiesto)
            if ($injectNavId && $link !== '' && strpos($link, 'nav_id=') === false) {
                $separator = (strpos($link, '?') !== false) ? '&' : '?';
                $link .= $separator . 'nav_id=' . $navId;
            }

            if ($sec === '' || $parent === '' || $title === '' || $link === '')
                continue;
            // Gestione speciale per sezioni Document Manager dinamiche tramite Registry
            if (\Services\DocumentAreaRegistry::isValid($sec)) {
                $areaConf = \Services\DocumentAreaRegistry::getDocumentAreaConfig($sec);
                $uiHost = $areaConf['ui_host'];
                $targetHost = ($uiHost === 'root') ? $sec : $uiHost;

                if (!isset($menu[$targetHost]) || !isset($menu[$targetHost]['menus']) || !is_array($menu[$targetHost]['menus'])) {
                    continue;
                }

                // Cerca se esiste già un menu con questo title
                $menuFound = false;
                foreach ($menu[$targetHost]['menus'] as &$m) {
                    if (isset($m['title']) && mb_strtolower(trim($m['title'])) === mb_strtolower(trim($title))) {
                        // Menu già presente: aggiorna submenus con pagine archivio per questo menu
                        if (!isset($m['submenus']) || !is_array($m['submenus'])) {
                            $m['submenus'] = [];
                        }
                        $pagine = getDocumentAreaPages($sec, $title);
                        // Se non ci sono pagine, non aggiungere/aggiornare il menu
                        if (empty($pagine)) {
                            $m = null; // Marca per rimozione
                        } else {
                            $m['submenus'] = array_map(function ($riga) use ($sec, $uiHost) {
                                return [
                                    'title' => ucfirst(str_replace('_', ' ', $riga['titolo'])),
                                    'link' => 'index.php?section=' . urlencode($uiHost === 'root' ? $sec : $uiHost) . '&page=' . urlencode($riga['slug'])
                                ];
                            }, $pagine);
                        }
                        if ($m)
                            $m['title'] = str_replace('_', ' ', $m['title']);
                        $menuFound = true;
                        break;
                    }
                }
                unset($m);

                // Se non esiste, crea nuovo menu contenitore
                if (!$menuFound) {
                    $pagine = getDocumentAreaPages($sec, $title);
                    if (!empty($pagine)) {
                        $menu[$targetHost]['menus'][] = [
                            'title' => str_replace('_', ' ', $title),
                            'submenus' => array_map(function ($riga) use ($sec, $uiHost) {
                                return [
                                    'title' => ucfirst($riga['titolo']),
                                    'link' => 'index.php?section=' . urlencode($uiHost === 'root' ? $sec : $uiHost) . '&page=' . urlencode($riga['slug'])
                                ];
                            }, $pagine)
                        ];
                    }
                }
                continue; // Skip la logica normale per aree document manager
            }

            /* Normalizza: se il link punta a form/view_form/form_viewer con form_name,
                la sidebar deve aprire la vista tabella (gestione_segnalazioni) */
            $normalizedLink = $link;
            $parsed = @parse_url($link);
            $formNameQ = '';
            $pageQ = '';

            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $qs);
                $pageQ = strtolower((string) ($qs['page'] ?? ''));
                $formNameQ = isset($qs['form_name']) ? (string) $qs['form_name'] : '';
                $sectionQ = (string) ($qs['section'] ?? $sec);

                if (in_array($pageQ, ['form', 'view_form', 'form_viewer'], true) && $formNameQ !== '') {
                    $normalizedLink = 'index.php?section=' . rawurlencode($sectionQ)
                        . '&page=gestione_segnalazioni'
                        . '&form_name=' . urlencode($formNameQ);
                }
            }

            // CONTROLLO PERMESSI: Se il link punta a una pagina page_editor, verifica permessi
            if (!empty($formNameQ)) {
                // Verifica se esiste un form con questo nome
                $formCheck = $database->query(
                    "SELECT id FROM forms WHERE name = :n LIMIT 1",
                    [':n' => mb_strtolower(trim($formNameQ))],
                    __FILE__ . ' ⇒ menu_custom.form_check'
                );
                $formRow = $formCheck ? $formCheck->fetch(\PDO::FETCH_ASSOC) : null;

                if ($formRow) {
                    // Form esiste: verifica permessi con whitelist
                    $formId = (int) $formRow['id'];
                    if (!canCurrentUserViewFormById($formId)) {
                        // Utente non autorizzato: salta questa voce del menu
                        if (defined('APP_ENV') && APP_ENV === 'dev') {
                            error_log(sprintf(
                                "[MENU DEBUG] Esclusa voce menu: form_name=%s, form_id=%d, user_id=%d",
                                $formNameQ,
                                $formId,
                                $_SESSION['user_id'] ?? 0
                            ));
                        }
                        continue; // Salta questa voce, non aggiungerla al menu
                    }
                }
            }

            $parentFound = false;
            foreach ($menu[$sec]['menus'] as &$m) {
                if (!isset($m['title']))
                    continue;

                // Normalizzazione più robusta: rimuovi spazi multipli e confronta case-insensitive
                $menuTitleNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($m['title'])));
                $parentNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($parent)));

                if ($menuTitleNormalized === $parentNormalized) {
                    $parentFound = true;
                    if (!isset($m['submenus']) || !is_array($m['submenus'])) {
                        $m['submenus'] = [];
                    }

                    // evita duplicati (stesso title+link) usando il link normalizzato
                    $already = false;
                    foreach ($m['submenus'] as $sm) {
                        if (
                            isset($sm['title'], $sm['link']) &&
                            mb_strtolower(trim($sm['title'])) === mb_strtolower(trim($title)) &&
                            trim($sm['link']) === trim($normalizedLink)
                        ) {
                            $already = true;
                            break;
                        }
                    }

                    if (!$already) {
                        // Sostituisci underscore con spazi nel title per la visualizzazione
                        $displayTitle = str_replace('_', ' ', $title);
                        $m['submenus'][] = [
                            'title' => $displayTitle,
                            'link' => $normalizedLink,
                            'nav_id' => $navId // Passiamo l'ID esplicito
                        ];
                    }
                }
            }
            unset($m);

            // Se il parent non è stato trovato, crea il menu contenitore (fallback)
            if (!$parentFound) {
                // Cerca se esiste già un menu con questo parent_title (potrebbe essere stato creato da un altro modulo)
                $menuExists = false;
                foreach ($menu[$sec]['menus'] as &$m) {
                    if (isset($m['title'])) {
                        $menuTitleNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($m['title'])));
                        $parentNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($parent)));
                        if ($menuTitleNormalized === $parentNormalized) {
                            $menuExists = true;
                            break;
                        }
                    }
                }
                unset($m);

                // Se non esiste, crea il menu contenitore
                if (!$menuExists) {
                    // Sostituisci underscore con spazi per la visualizzazione
                    $displayTitle = str_replace('_', ' ', $title);
                    $displayParent = str_replace('_', ' ', $parent);
                    $menu[$sec]['menus'][] = [
                        'title' => $displayParent,
                        'submenus' => [
                            [
                                'title' => $displayTitle,
                                'link' => $normalizedLink,
                                'nav_id' => $navId // Passiamo l'ID esplicito
                            ]
                        ]
                    ];
                }
            }
        }
    }



    // --- AGGIUNTA NAV_ID AI MENU STATICI ---
    // Funzione helper locale per arricchire i menu con nav_id
    $enrichWithIds = function (&$items) use (&$enrichWithIds, $injectNavId) {
        foreach ($items as &$item) {
            // A) GENERAZIONE ID (Sempre)
            if (!isset($item['nav_id'])) {
                $seed = $item['title'] ?? 'untitled';
                $item['nav_id'] = 'static_' . substr(md5($seed), 0, 8);
            }

            // B) INIEZIONE NEL LINK (Solo se richiesto)
            if ($injectNavId && isset($item['link']) && strpos($item['link'], 'nav_id=') === false) {
                $separator = (strpos($item['link'], '?') !== false) ? '&' : '?';
                $item['link'] .= $separator . 'nav_id=' . $item['nav_id'];
            }

            if (isset($item['submenus']) && is_array($item['submenus'])) {
                $enrichWithIds($item['submenus']);
            }
        }
    };

    // Applica a tutte le sezioni
    foreach ($menu as &$section) {
        if (isset($section['menus'])) {
            $enrichWithIds($section['menus']);
        }
    }
    unset($section); // break reference

    return $menu;
}

/**
 * Ritorna tutte le sezioni e i rispettivi "parent menu" (i titoli dei blocchi che hanno submenus),
 * per popolare le select dell'admin.
 * [
 *   'archivio' => ['Modulistica', 'Dashboard Archivio', ...],
 *   'collaborazione' => ['Segnalazioni', 'Comunicazione', ...],
 *   ...
 * ]
 */
function getAllSectionsAndParents(): array
{
    $full = getStaticMenu(null, true); // fullMenu=true → tutte le sezioni
    $out = [];
    foreach ($full as $secKey => $sec) {
        if (!isset($sec['menus']) || !is_array($sec['menus']))
            continue;
        foreach ($sec['menus'] as $m) {
            // un "parent" valido è un item che possiede submenus
            if (!empty($m['title']) && isset($m['submenus']) && is_array($m['submenus']) && count($m['submenus']) > 0) {
                $out[$secKey][] = $m['title'];
            }
        }
        // dedup & sort
        if (isset($out[$secKey])) {
            $out[$secKey] = array_values(array_unique($out[$secKey]));
            sort($out[$secKey], SORT_NATURAL | SORT_FLAG_CASE);
        }
    }
    ksort($out);
    return $out;
}

/**
 * Funzione generica per ottenere i moduli di una sezione specifica
 */
function getModuliBySection($section = 'collaborazione', $parent_title = 'segnalazioni')
{
    global $database;

    $menus = [];

    // sanitizzazione base
    $section = preg_replace('/[^a-z0-9_\-]/i', '', (string) $section);
    $parent_title = trim(preg_replace('/\s+/u', ' ', (string) $parent_title));

    if ($section === '' || $parent_title === '')
        return $menus;

    // prendi le voci di menu_custom attive sotto il parent scelto (ordine coerente con sidebar)
    $stmt = $database->query(
        "select id, title, link
           from menu_custom
          where section = :s
            and parent_title = :p
            and coalesce(attivo,1) = 1
          order by coalesce(ordinamento,100) asc, id asc",
        [':s' => $section, ':p' => $parent_title],
        __FILE__ . ' ⇒ getModuliBySection.menu_custom'
    );

    if (!$stmt)
        return $menus;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $form_name = (string) ($row['title'] ?? '');
        $form_name_norm = mb_strtolower(trim($form_name));
        if ($form_name_norm === '')
            continue;

        // esiste davvero il form?
        $f = $database->query(
            "select id from forms where name = :n limit 1",
            [':n' => $form_name_norm],
            __FILE__ . ' ⇒ getModuliBySection.forms_check'
        );
        $fr = $f ? $f->fetch(PDO::FETCH_ASSOC) : null;
        if (!$fr)
            continue;

        // visibilità: usa la tua guard già pronta (gestisce anche admin)
        if (!canCurrentUserViewFormById((int) $fr['id']))
            continue;

        // link: se in menu_custom hai già il link usalo; altrimenti costruiscilo standard
        $link = (string) ($row['link'] ?? '');
        $id = (int) ($row['id'] ?? 0);
        $navId = 'custom_' . $id;

        if ($link === '') {
            $link = 'index.php?section=' . rawurlencode($section)
                . '&page=gestione_segnalazioni'
                . '&form_name=' . urlencode($form_name_norm);
        }

        // Append nav_id
        if (strpos($link, 'nav_id=') === false) {
            $link .= (strpos($link, '?') !== false ? '&' : '?') . 'nav_id=' . $navId;
        }

        $menus[] = [
            'title' => htmlspecialchars(capitalize($form_name_norm), ENT_QUOTES, 'UTF-8'),
            'link' => $link,
            'nav_id' => $navId
        ];
    }

    return $menus;
}

function getSegnalazioniMenu()
{
    global $database;

    $menus = [];

    // Dashboard sempre visibile
    $menus[] = [
        'title' => 'Dashboard Segnalazioni',
        'link' => 'index.php?section=collaborazione&page=segnalazioni_dashboard'
    ];

    // Moduli dalla tabella menu_custom (normalizziamo i link come nella fase di iniezione)
    $customMenus = $database->query(
        "SELECT id, title, link
           FROM menu_custom
          WHERE section = 'collaborazione'
             AND parent_title = 'Segnalazioni'
            AND attivo = 1
            AND title != 'Dashboard Segnalazioni'
          ORDER BY ordinamento ASC, title ASC",
        [],
        __FILE__ . ' ⇒ getSegnalazioniMenu'
    );

    if ($customMenus) {
        while ($row = $customMenus->fetch(PDO::FETCH_ASSOC)) {
            $title = htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $link = (string) ($row['link'] ?? '');

            if ($title === '' || $link === '')
                continue;

            // Normalizza eventuali link a form/view_form/form_viewer in gestione_segnalazioni
            $normalizedLink = $link;
            $parsed = @parse_url($link);
            $formNameQ = '';
            $pageQ = '';

            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $qs);
                $pageQ = strtolower((string) ($qs['page'] ?? ''));
                $formNameQ = isset($qs['form_name']) ? (string) $qs['form_name'] : '';
                $sectionQ = (string) ($qs['section'] ?? 'collaborazione');

                if (in_array($pageQ, ['form', 'view_form', 'form_viewer'], true) && $formNameQ !== '') {
                    $normalizedLink = 'index.php?section=' . rawurlencode($sectionQ)
                        . '&page=gestione_segnalazioni'
                        . '&form_name=' . urlencode($formNameQ);
                }
            }

            // CONTROLLO PERMESSI: Se il link punta a una pagina page_editor, verifica permessi
            if (!empty($formNameQ)) {
                // Verifica se esiste un form con questo nome
                $formCheck = $database->query(
                    "SELECT id FROM forms WHERE name = :n LIMIT 1",
                    [':n' => mb_strtolower(trim($formNameQ))],
                    __FILE__ . ' ⇒ getSegnalazioniMenu.form_check'
                );
                $formRow = $formCheck ? $formCheck->fetch(\PDO::FETCH_ASSOC) : null;

                if ($formRow) {
                    // Form esiste: verifica permessi con whitelist
                    $formId = (int) $formRow['id'];
                    if (!canCurrentUserViewFormById($formId)) {
                        // Utente non autorizzato: salta questa voce del menu
                        if (defined('APP_ENV') && APP_ENV === 'dev') {
                            error_log(sprintf(
                                "[MENU DEBUG] getSegnalazioniMenu: Esclusa voce menu: form_name=%s, form_id=%d",
                                $formNameQ,
                                $formId
                            ));
                        }
                        continue; // Salta questa voce, non aggiungerla al menu
                    }
                }
            }

            // Evita duplicati locali (stesso titolo o stesso link)
            $already = false;
            foreach ($menus as $m) {
                if (mb_strtolower($m['title']) === mb_strtolower($title)) {
                    $already = true;
                    break;
                }
                if (isset($m['link']) && trim($m['link']) === trim($normalizedLink)) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $customId = (int) ($row['id'] ?? 0);
                $navId = 'custom_' . $customId;

                // Append nav_id
                if (strpos($normalizedLink, 'nav_id=') === false) {
                    $normalizedLink .= (strpos($normalizedLink, '?') !== false ? '&' : '?') . 'nav_id=' . $navId;
                }

                $menus[] = [
                    'title' => $title,
                    'link' => $normalizedLink,
                    'nav_id' => $navId
                ];
            }
        }
    }

    return $menus;
}

function getCommesseMenu()
{
    global $database;
    $result = [];

    if (userHasPermission('view_commesse')) {
        $res = $database->query("SELECT titolo, tabella FROM commesse_bacheche ORDER BY id ASC", [], __FILE__);
        $rows = $res->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $result[] = [
                    'title' => capitalize($row['tabella']),
                    'link' => 'index.php?section=commesse&page=commessa&tabella=' . urlencode(strtoupper($row['tabella'])) . '&titolo=' . urlencode(strtoupper($row['tabella']))
                ];
            }
        }

        if (count($result) === 0) {
            $result[] = [
                'title' => 'Nessuna bacheca disponibile',
                'link' => '#'
            ];
        }
    }

    return $result;
}

function getCommesseBacheche()
{
    global $database;
    $res = $database->query("SELECT titolo, tabella FROM commesse_bacheche ORDER BY id ASC", [], __FILE__);
    return $res->fetchAll(PDO::FETCH_ASSOC);
}

// Funzione per generare il breadcrumb dinamico
function getDynamicBreadcrumb(): array
{
    $page = $_GET['page'] ?? '';
    $section = $_GET['section'] ?? '';
    $formName = isset($_GET['form_name']) ? urldecode($_GET['form_name']) : null;
    $breadcrumbs = [];

    // Statici
    $map = [
        'gare' => ['Commerciale', 'Gare', 'Estrazione Bandi'],
        'estrazione_bandi' => ['Commerciale', 'Gare', 'Estrazione Bandi'],
        'elenco_gare' => ['Commerciale', 'Gare', 'Elenco Gare'],
        'archivio_gare' => ['Commerciale', 'Gare', 'Archivio Gare'],
        'gare_dettaglio' => ['Commerciale', 'Gare', 'Dettaglio Gara'],
        'requisiti' => ['Commerciale', 'Requisiti', 'Requisiti'],
        'impostazioni_moduli' => ['Gestione Intranet', 'Impostazioni Generali', 'Pagine'],

        'impostazioni_protocollo_email' => ['Gestione Intranet', 'Impostazioni Generali', 'Protocollo Email'],
        'contacts' => ['HR', 'Gestione Personale', 'Contatti'],
        'office_map_public' => ['HR', 'Gestione Personale', 'Mappa Ufficio'],
        'office_map' => ['HR', 'Gestione Personale', 'Mappa Ufficio Admin'],
        'hr_dashboard' => ['Gestione', 'Gestione HR', 'Dashboard HR'],
        'candidate_selection_kanban' => ['Gestione', 'Gestione HR', 'Selezione Personale'],
        'job_profile' => ['Gestione', 'Gestione HR', 'Job Profile'],
        'open_search' => ['Gestione', 'Gestione HR', 'Apertura Ricerca'],
        'anagrafiche_hr' => ['Gestione', 'Gestione HR', 'Anagrafiche HR'],
        'mail' => ['Collaborazione', 'Comunicazione', 'Codice Protocollo'],
        'messaggistica' => ['Collaborazione', 'Comunicazione', 'Messaggistica'],
        'segnalazioni_home' => ['Collaborazione', 'Segnalazioni', 'Gestione Segnalazioni'],
        'segnalazioni_dashboard' => ['Collaborazione', 'Segnalazioni'],
        'moduli_admin' => ['Collaborazione', 'Segnalazioni', 'Gestione Moduli'],
        'form_editor' => ['Collaborazione', 'Segnalazioni', 'Gestione Moduli'],
        'protocollo_email' => ['Collaborazione', 'Comunicazione', 'Protocollo Comunicazioni'],
        // 'mom' è gestito dinamicamente sotto in base alla sezione
        'changelog_admin' => ['Intranet News', 'Gestione Changelog'],
        'changelog' => ['Intranet News', 'Novità e Miglioramenti'],
        'page_editor' => ($section === 'gestione_intranet' ? ['Gestione Intranet', 'Gestione Moduli'] : ['Collaborazione', 'Segnalazioni', 'Gestione Moduli']),
        'cv_manager' => ['HR', 'Recruiting', 'Gestione CV'],
    ];

    if (isset($map[$page])) {
        foreach ($map[$page] as $i => $item) {
            $link = null;
            switch ($item) {
                case 'Commerciale':
                    $link = 'index.php?section=commerciale&page=estrazione_bandi';
                    break;
                case 'HR':
                    $link = 'index.php?section=hr&page=contacts';
                    break;
                case 'Gare':
                    $link = 'index.php?section=commerciale&page=estrazione_bandi';
                    break;
                case 'Archivio Gare':
                    $link = 'index.php?section=commerciale&page=estrazione_bandi&view=archivio_gare';
                    break;
                case 'Estrazione Bandi':
                    $link = 'index.php?section=commerciale&page=estrazione_bandi';
                    break;
                case 'Elenco Gare':
                    $link = 'index.php?section=commerciale&page=elenco_gare';
                    break;
                case 'Gestione Personale':
                    $link = 'index.php?section=hr&page=contacts';
                    break;
                case 'Segnalazioni':
                    $link = 'index.php?section=collaborazione&page=segnalazioni_dashboard';
                    break;
                case 'Gestione Moduli':
                    $link = ($section === 'gestione_intranet')
                        ? 'index.php?section=gestione_intranet&page=impostazioni_moduli'
                        : 'index.php?section=collaborazione&page=moduli_admin';
                    break;
                case 'Verbali Riunione':
                    // Supporta sia commerciale che collaborazione
                    $link = 'index.php?section=' . ($section === 'commerciale' ? 'commerciale' : 'collaborazione') . '&page=mom';
                    break;
                case 'MOM':
                    // Supporta sia commerciale che collaborazione
                    $link = 'index.php?section=' . ($section === 'commerciale' ? 'commerciale' : 'collaborazione') . '&page=mom';
                    break;
                case 'Verbali (MOM)':
                    $link = 'index.php?section=commerciale&page=mom';
                    break;
                default:
                    break;
            }
            $breadcrumbs[$item] = $link;
        }
    }

    $matchedArea = null;
    $hostSectionForLink = $section;

    if (\Services\DocumentAreaRegistry::isValid($section)) {
        $matchedArea = $section;
    } else {
        foreach (\Services\DocumentAreaRegistry::getRegistry() as $areaKey => $areaConf) {
            if ($areaConf['ui_host'] === $section && $areaConf['ui_host'] !== 'root') {
                if ($page === $areaKey) {
                    $matchedArea = $areaKey;
                    break;
                }
                $pagineArea = getDocumentAreaPages($areaKey);
                foreach ($pagineArea as $p) {
                    if ($p['slug'] === $page) {
                        $matchedArea = $areaKey;
                        break 2;
                    }
                }
            }
        }
    }

    if ($matchedArea) {
        $areaConf = \Services\DocumentAreaRegistry::getDocumentAreaConfig($matchedArea);
        $pagine = getDocumentAreaPages($matchedArea);
        $hostSectionForLink = $areaConf['ui_host'] === 'root' ? $matchedArea : $areaConf['ui_host'];

        // Se l'area è ospitata, aggiungi breadcrumb base
        if ($areaConf['ui_host'] === 'hr') {
            $breadcrumbs['HR'] = null;
        } elseif ($areaConf['ui_host'] === 'commerciale') {
            $breadcrumbs['Commerciale'] = null;
        }

        $titoloPagina = null;
        if ($page && $page !== $matchedArea) {
            foreach ($pagine as $p) {
                if ($p['slug'] === $page) {
                    $titoloPagina = $p['titolo'];
                    break;
                }
            }
            $breadcrumbs[$areaConf['label']] = 'index.php?section=' . $hostSectionForLink . '&page=' . $matchedArea;
            $breadcrumbs[$titoloPagina ?: ucfirst($page)] = null;
        } else {
            $breadcrumbs[$areaConf['label']] = null;
        }
    }

    // Dinamica per i form
    if ($formName && ($page === 'form_editor' || $page === 'page_editor')) {
        // Normalizza il nome del form sostituendo underscore con spazi
        $formNameDisplay = str_replace('_', ' ', $formName);

        if ($section === 'gestione_intranet') {
            $breadcrumbs = [
                'Gestione Intranet' => null,
                'Gestione Moduli' => 'index.php?section=gestione_intranet&page=impostazioni_moduli',
                "Modifica: $formNameDisplay" => null
            ];
        } else {
            $breadcrumbs = [
                'Collaborazione' => null,
                'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
                'Gestione Moduli' => 'index.php?section=collaborazione&page=moduli_admin',
                "Modifica: $formNameDisplay" => null
            ];
        }
    } elseif ($page === 'page_editor' && !$formName) {
        if ($section === 'gestione_intranet') {
            $breadcrumbs = [
                'Gestione Intranet' => null,
                'Gestione Moduli' => 'index.php?section=gestione_intranet&page=impostazioni_moduli',
                'Crea Nuova Pagina' => null
            ];
        } else {
            $breadcrumbs = [
                'Collaborazione' => null,
                'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
                'Gestione Moduli' => 'index.php?section=collaborazione&page=moduli_admin',
                'Crea Nuova Pagina' => null
            ];
        }
    }

    // Breadcrumb per gestione segnalazioni
    if ($section === 'collaborazione' && $page === 'gestione_segnalazioni') {
        $formNameDecoded = isset($_GET['form_name']) ? urldecode($_GET['form_name']) : null;
        // Normalizza il nome del form sostituendo underscore con spazi
        $formNameDisplay = $formNameDecoded ? str_replace('_', ' ', $formNameDecoded) : null;
        $breadcrumbs = [
            'Collaborazione' => null,
            'Segnalazioni' => 'index.php?section=collaborazione&page=segnalazioni_dashboard',
            $formNameDisplay ?: 'Gestione Segnalazioni' => null
        ];
    }

    // Breadcrumb per visualizzazione form (dinamico sulla sezione corrente)
    if (in_array($page, ['view_form', 'form_viewer', 'form'], true) && isset($_GET['form_name'])) {
        $formNameDecoded = urldecode($_GET['form_name']);
        // Normalizza il nome del form sostituendo underscore con spazi
        $formNameDisplay = str_replace('_', ' ', $formNameDecoded);

        // Etichetta sezione (presa dal menu statico, altrimenti ucfirst)
        $menuFull = getStaticMenu($section, true);
        $sectionLabel = $menuFull[$section]['label'] ?? ucfirst($section);

        // Link "home" di sezione (facoltativo): tieni quello storico per Collaborazione
        $sectionLink = null;
        if ($section === 'collaborazione') {
            $sectionLink = 'index.php?section=collaborazione&page=segnalazioni_dashboard';
        } elseif ($section === 'gestione') {
            // Se vuoi un'home per Gestione, scegline una (es. Contatti). Altrimenti lascia null.
            $sectionLink = 'index.php?section=hr&page=contacts';
        } elseif ($section === 'hr') {
            $sectionLink = 'index.php?section=hr&page=contacts';
        } elseif ($section === 'commerciale') {
            $sectionLink = 'index.php?section=commerciale&page=estrazione_bandi';
        }

        $breadcrumbs = [
            $sectionLabel => $sectionLink,
            $formNameDisplay => null
        ];
    }

    // MOM dinamico - breadcrumb basato sulla sezione corrente
    if ($page === 'mom') {
        $sectionLabels = [
            'commerciale' => 'Commerciale',
            'collaborazione' => 'Collaborazione',
            'hr' => 'HR',
            'archivio' => 'Archivio',
            'commesse' => 'Commesse'
        ];
        $sectionLabel = $sectionLabels[$section] ?? 'Collaborazione';
        $sectionLink = 'index.php?section=' . $section . '&page=mom';

        $breadcrumbs = [
            $sectionLabel => $sectionLink,
            'Verbali Riunione' => $sectionLink,
            'MOM' => null
        ];
        return $breadcrumbs;
    }

    // Bacheca dinamica
    if ($section === 'collaborazione' && $page === 'task_management') {
        if (!empty($_GET['dashboard'])) {
            $breadcrumbs = [
                'Collaborazione' => null,
                'Task Management' => null,
                'Dashboard Task' => 'index.php?section=collaborazione&page=task_management&dashboard=true'
            ];
        } elseif (!empty($_GET['board'])) {
            $id = intval($_GET['board']);
            $breadcrumbs = [
                'Collaborazione' => null,
                'Task Management' => null,
                "Bacheca #$id" => "index.php?section=collaborazione&page=task_management&board=$id"
            ];
        }
    }

    // ===== SPECIAL-CASE: Commesse (gestione completa e dinamica) =====
    if ($section === 'commesse') {
        $menu = getStaticMenu('commesse', true);
        $sectionLabel = $menu['commesse']['label'] ?? 'Commesse';

        // Gestione Ore: Dashboard Ore
        if ($page === 'dashboard_ore') {
            $breadcrumbs[$sectionLabel] = 'index.php?section=commesse&page=elenco_commesse';
            $breadcrumbs['Gestione Ore'] = 'index.php?section=commesse&page=dashboard_ore';
            $breadcrumbs['Dashboard Ore'] = null;
            return $breadcrumbs;
        }

        // Gestione Ore: Business Unit
        if ($page === 'ore_business_unit') {
            $breadcrumbs[$sectionLabel] = 'index.php?section=commesse&page=elenco_commesse';
            $breadcrumbs['Gestione Ore'] = 'index.php?section=commesse&page=dashboard_ore';
            $breadcrumbs['Business Unit'] = null;
            return $breadcrumbs;
        }

        // Gestione Ore: Dettaglio Utente
        if ($page === 'ore_dettaglio_utente') {
            $breadcrumbs[$sectionLabel] = 'index.php?section=commesse&page=elenco_commesse';
            $breadcrumbs['Gestione Ore'] = 'index.php?section=commesse&page=dashboard_ore';
            $breadcrumbs['Dettaglio Utente'] = null;
            return $breadcrumbs;
        }

        // Se siamo nella pagina elenco o gestione commesse
        if ($page === 'elenco_commesse') {
            $breadcrumbs[$sectionLabel] = null;
            if ($page === 'elenco_commesse') {
                $breadcrumbs['Elenco Commesse'] = null;
            } else {
                $breadcrumbs['Gestione Commesse'] = null;
            }
            return $breadcrumbs;
        }

        // Se siamo in una commessa specifica (page=commessa)
        if ($page === 'commessa' && isset($_GET['tabella']) && isset($_GET['titolo'])) {
            $tabella = $_GET['tabella'];
            $titolo = urldecode($_GET['titolo']);
            $view = $_GET['view'] ?? '';

            // Mappa delle view con etichette e gerarchie (dinamica, basata su commessa.php)
            $viewMap = [
                // View principali (livello 1)
                'dati' => ['label' => 'Dati commessa', 'parent' => null],
                'impostazioni' => ['label' => 'Impostazioni', 'parent' => null],
                'crono' => ['label' => 'Cronoprogramma', 'parent' => null],
                'task' => ['label' => 'Task', 'parent' => null],
                'documenti' => ['label' => 'Documenti & Output', 'parent' => null],
                'organigramma' => ['label' => 'Organigramma', 'parent' => null],
                'direzione_lavori' => ['label' => 'Direzione lavori', 'parent' => null],
                'chiusura' => ['label' => 'Chiusura commessa', 'parent' => null],

                // Hub Sicurezza (livello 1)
                'sicurezza' => ['label' => 'Sicurezza', 'parent' => null],

                // Hub Cantiere (livello 1)
                'gestione_cantiere' => ['label' => 'Gestione cantiere', 'parent' => null],

                // Sottosezioni cantiere (livello 2, parent: gestione_cantiere)
                'organigramma_imprese' => ['label' => 'Organigramma Imprese', 'parent' => 'gestione_cantiere'],
                'organigramma_cantiere' => ['label' => 'Organigramma Cantiere', 'parent' => 'gestione_cantiere'],
                'documenti_sicurezza' => ['label' => 'Documenti Sicurezza', 'parent' => 'gestione_cantiere'],
                'controlli_sicurezza' => ['label' => 'Controlli Sicurezza', 'parent' => 'gestione_cantiere'],

                // Moduli sicurezza (livello 2, parent: sicurezza)
                'sic_vvcs' => ['label' => 'Verbale Visita in Cantiere (VVCS)', 'parent' => 'sicurezza'],
                'sic_vcs' => ['label' => 'Verbale Riunione Coordinamento (VCS)', 'parent' => 'sicurezza'],
                'sic_vrtp' => ['label' => 'Verbale Riunione Tecnica Periodica (VRTP)', 'parent' => 'sicurezza'],
                'sic_vpos' => ['label' => 'Verbale Posizione (VPOS)', 'parent' => 'sicurezza'],
                'sic_vfp' => ['label' => 'Verbale Fine Presenza (VFP)', 'parent' => 'sicurezza'],
                'sic_elenco_doc' => ['label' => 'Elenco documenti per impresa', 'parent' => 'sicurezza'],
            ];

            // Costruisci il breadcrumb
            $breadcrumbs[$sectionLabel] = 'index.php?section=commesse&page=elenco_commesse';

            // Link alla commessa base (senza view)
            // Usa sempre il codice commessa ($tabella) invece del nome esteso ($titolo) per il breadcrumb
            $commessaBaseLink = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($tabella) . '&titolo=' . urlencode($titolo);
            $breadcrumbs[$tabella] = $commessaBaseLink;

            // Se c'è una view, aggiungi i livelli
            if ($view && isset($viewMap[$view])) {
                $viewInfo = $viewMap[$view];

                // Se la view ha un parent, aggiungilo
                if (isset($viewInfo['parent']) && $viewInfo['parent'] !== null) {
                    $parentKey = (string) $viewInfo['parent'];
                    if (isset($viewMap[$parentKey])) {
                        $parentInfo = $viewMap[$parentKey];
                        $parentLink = $commessaBaseLink . '&view=' . urlencode($parentKey);
                        $breadcrumbs[$parentInfo['label']] = $parentLink;
                    }
                }

                // Aggiungi la view corrente (senza link)
                $breadcrumbs[$viewInfo['label']] = null;
            }

            return $breadcrumbs;
        }
    }

    return $breadcrumbs;
}

// Funzione per generare il breadcrumb HTML
function renderBreadcrumb()
{
    $breadcrumbs = getDynamicBreadcrumb();

    // Non renderizzare se vuoto
    if (empty($breadcrumbs)) {
        return;
    }

    echo '<nav aria-label="breadcrumb"><div class="breadcrumb" id="breadcrumb"><ul>';
    $total = count($breadcrumbs);
    $index = 0;

    foreach ($breadcrumbs as $title => $link) {
        $index++;
        $isLast = $index === $total;

        echo '<li>';
        if (!$isLast && $link) {
            echo '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a>';
        } elseif ($isLast) {
            echo '<span class="current">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            echo '<span class="no-link">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</li>';

        if (!$isLast) {
            echo '<li class="separator">/</li>';
        }
    }

    echo '</ul></div></nav>';
}

function getProfileImage($nominativo, $by = 'nominativo', $default = 'assets/images/default_profile.png')
{
    static $cache = [];

    if (!$nominativo || !is_string($nominativo))
        return $default;

    $key = strtoupper(trim($nominativo));
    if (isset($cache[$key]))
        return $cache[$key];

    $relative_path = 'assets/images/profile_pictures/';
    $absolute_path = ROOT . '/' . $relative_path;
    $filename_base = ($by === 'filename')
        ? pathinfo(trim($nominativo), PATHINFO_FILENAME)
        : str_replace(' ', '_', trim($nominativo));

    // Se il nominativo contiene spazi, prova anche la versione invertita (cognome_nome)
    if (strpos($nominativo, ' ') !== false && $by === 'nominativo') {
        $parts = explode(' ', trim($nominativo));
        if (count($parts) >= 2) {
            $inverted = $parts[count($parts) - 1] . '_' . $parts[0]; // cognome_nome
            $filename_base_inverted = str_replace(' ', '_', $inverted);
        }
    }

    // 1. Prima prova in /rid/ (webp) - versione invertita se disponibile
    if (isset($filename_base_inverted)) {
        $rid_rel_inverted = $relative_path . 'rid/' . $filename_base_inverted . '.webp';
        $rid_abs_inverted = $absolute_path . 'rid/' . $filename_base_inverted . '.webp';
        if (@is_file($rid_abs_inverted)) {
            return $cache[$key] = $rid_rel_inverted;
        }
    }

    // 2. Poi prova in /rid/ (webp) - versione originale
    $rid_rel = $relative_path . 'rid/' . $filename_base . '.webp';
    $rid_abs = $absolute_path . 'rid/' . $filename_base . '.webp';
    if (@is_file($rid_abs)) {
        return $cache[$key] = $rid_rel;
    }

    // 3. Poi prova in root profile_pictures (webp/jpg/png) - versione invertita se disponibile
    if (isset($filename_base_inverted)) {
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
            $abs_inverted = $absolute_path . $filename_base_inverted . '.' . $ext;
            if (@is_file($abs_inverted)) {
                return $cache[$key] = $relative_path . $filename_base_inverted . '.' . $ext;
            }
        }
    }

    // 4. Poi prova in root profile_pictures (webp/jpg/png) - versione originale
    foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
        $abs = $absolute_path . $filename_base . '.' . $ext;
        if (@is_file($abs)) {
            return $cache[$key] = $relative_path . $filename_base . '.' . $ext;
        }
    }

    // 5. Default: genera avatar dinamico con le iniziali invece dell'immagine standard
    // Verifichiamo prima se il nome non e' noto o vuoto, per evitare stringhe vuote
    $clean_name = trim($nominativo);
    if (empty($clean_name) || strtolower($clean_name) === 'sconosciuto') {
        return $cache[$key] = $default;
    }

    $words = preg_split('/[\s\-]+/', $clean_name, 2, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach ($words as $w) {
        $initials .= mb_substr($w, 0, 1, 'UTF-8');
    }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2, 'UTF-8'), 'UTF-8');
    if (empty($initials))
        $initials = '?';

    $colors = [
        '#f56a00',
        '#7265e6',
        '#ffbf00',
        '#00a2ae',
        '#1890ff',
        '#d41c1c',
        '#13c2c2',
        '#eb2f96',
        '#2f54eb',
        '#a0d911',
        '#52c41a',
        '#faad14',
        '#f5222d',
        '#7cb305',
        '#1677ff',
        '#531dab',
        '#c41d7f',
        '#d4380d',
        '#08979c',
        '#0958d9'
    ];
    $sum = 0;
    for ($i = 0; $i < mb_strlen($initials, 'UTF-8'); $i++) {
        $sum += ord(mb_substr($initials, $i, 1, 'UTF-8')) * ($i + 1);
    }
    $bgColor = $colors[$sum % count($colors)];

    $size = 200;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '">';
    $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="' . $bgColor . '"/>';
    $svg .= '<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-weight="bold" font-size="' . ($size * 0.4) . '">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</text>';
    $svg .= '</svg>';

    return $cache[$key] = 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// Funzione per generare il titolo della pagina
function renderPageTitle($title = null, $color = '#ccc', $escape = true)
{
    if (!$title) {
        $title = ucwords(str_replace('_', ' ', filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'Dashboard'));
    }
    echo '<div class="page-title-container" style="border-bottom: 3px solid ' . htmlspecialchars($color) . '; padding-bottom: 10px; padding-left: 5px; margin-bottom: 15px;">';
    if ($escape) {
        echo '<h1 style="margin: 0; margin-bottom: -5px; font-weight: bold; color: #333;">' . htmlspecialchars($title) . '</h1>';
    } else {
        echo '<h1 style="margin: 0; margin-bottom: -5px; font-weight: bold; color: #333;">' . $title . '</h1>';
    }
    echo '</div>';
}

/**
 * Verifica se l'utente corrente è amministratore
 * Admin = ha role_id 1 in sys_user_roles
 */
function isAdmin(): bool
{
    return !empty($_SESSION['role_ids']) && in_array(1, $_SESSION['role_ids'], true);
}

/**
 * Verifica se l'utente ha un permesso specifico
 * Admin ha sempre tutti i permessi
 */
function userHasPermission(string $permesso): bool
{
    // Validazione permission key
    if (!is_string($permesso) || trim($permesso) === '') {
        if (!defined('APP_ENV') || APP_ENV === 'dev') {
            echo "<div style='background:#ffcccc; border:1px solid #f00; padding:10px; margin:10px;'>";
            echo "<strong>ERRORE SVILUPPO:</strong> Permission key non valida: " . htmlspecialchars(var_export($permesso, true));
            echo "</div>";
        }
        return false; // Permission invalida = deny
    }

    // Normalizza la permission (rimuovi spazi, lowercase)
    $permesso = trim(strtolower($permesso));

    // Admin bypass: se è admin, accesso sempre consentito
    if (isAdmin()) {
        return true;
    }

    // Altrimenti controllo permesso specifico dalla sessione
    return !empty($_SESSION['role_permissions']) && in_array($permesso, $_SESSION['role_permissions'], true);
}

function checkPermissionOrWarn(string $permesso): bool
{
    // Admin bypass immediato: se admin, allow senza controlli permessi
    $isAdmin = in_array(1, $_SESSION['role_ids'] ?? [], true);
    if ($isAdmin) {
        return true;
    }

    // Se non admin, controllo permesso normale
    if (userHasPermission($permesso))
        return true;

    // Debug visibile per diagnosticare problemi autorizzazione
    $debug = [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'is_ajax' => !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest',
        'user_id' => $_SESSION['user_id'] ?? 'MISSING',
        'username' => $_SESSION['username'] ?? 'MISSING',
        'role_ids' => $_SESSION['role_ids'] ?? 'MISSING',
        'is_admin' => false,
        'required_permission' => $permesso,
        'userHasPermission_result' => false,
        'permissions_count' => isset($_SESSION['role_permissions']) ? count($_SESSION['role_permissions']) : 0
    ];

    // Header debug SEMPRE presente nei deny
    header('X-Auth-Debug: ' . base64_encode(json_encode($debug)));

    // UI pulita: messaggio semplice senza overlay/redirect automatici
    echo "<div style='color:red; padding:20px; border:1px solid red; margin:20px; background:#ffeaea;'>";
    echo "<h3>Accesso Negato</h3>";
    echo "<p>Non hai i permessi per accedere a questa pagina.</p>";
    echo "<p>Permesso richiesto: <strong>{$permesso}</strong></p>";
    echo "</div>";

    exit; // Stop esecuzione pulita
}

/**
 * Verifica se la modalità manutenzione è attiva
 * @return bool
 */
function isMaintenanceMode(): bool
{
    global $database;

    try {
        // Verifica se la tabella esiste
        $check = $database->query("SHOW TABLES LIKE 'app_settings'", [], __FILE__);
        if (!$check || $check->rowCount() === 0) {
            return false;
        }

        $result = $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_mode' LIMIT 1",
            [],
            __FILE__
        );

        if ($result) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            return isset($row['setting_value']) && intval($row['setting_value']) === 1;
        }
    } catch (\Throwable $e) {
        error_log("Errore isMaintenanceMode: " . $e->getMessage());
    }

    return false;
}

/**
 * Applica la modalità manutenzione: blocca utenti non admin se attiva
 * @return void
 */
function enforceMaintenanceMode(): void
{
    // Non bloccare richieste CLI
    if (PHP_SAPI === 'cli') {
        return;
    }

    // Se la manutenzione non è attiva, non fare nulla
    if (!isMaintenanceMode()) {
        return;
    }

    // Admin possono sempre accedere
    if (isAdmin()) {
        return;
    }

    // Verifica se è la pagina di gestione manutenzione (admin devono poterla raggiungere)
    $section = $_GET['section'] ?? '';
    $page = $_GET['page'] ?? '';
    if ($section === 'gestione_intranet' && $page === 'maintenance_settings') {
        return;
    }

    // Mostra pagina manutenzione
    global $database;
    $message = '';

    try {
        $result = $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_message' LIMIT 1",
            [],
            __FILE__
        );
        if ($result) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $message = isset($row['setting_value']) ? trim($row['setting_value']) : '';
        }
    } catch (\Throwable $e) {
        error_log("Errore lettura messaggio manutenzione: " . $e->getMessage());
    }

    // Carica pagina manutenzione
    require_once __DIR__ . '/../views/maintenance.php';
    exit;
}

function compressImage(string $source, string $destination, int $quality = 75, bool $convertToWebp = false): string|false
{
    if (!file_exists($source))
        return false;

    $info = getimagesize($source);
    if (!$info || empty($info['mime']))
        return false;

    $mime = $info['mime'];
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($source),
        'image/png' => @imagecreatefrompng($source),
        default => false
    };

    if (!$image)
        return false;

    // Estensione di destinazione
    if ($convertToWebp) {
        $destination = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $destination);
        $res = imagewebp($image, $destination, $quality);
    } else {
        $res = match ($mime) {
            'image/jpeg' => imagejpeg($image, $destination, $quality),
            'image/png' => imagepng($image, $destination, floor(9 * (100 - $quality) / 100)),
            default => false
        };
    }

    imagedestroy($image);
    return $res ? $destination : false;
}

/**
 * Funzione globale riutilizzabile per comprimere e ridimensionare immagini uploadate
 * Supporta resize, conversione WebP, gestione EXIF orientation (se disponibile)
 * 
 * @param string $tmpPath Path temporaneo del file uploadato
 * @param string $destPath Path di destinazione finale
 * @param array $options Opzioni di compressione:
 *   - maxWidth (int, default 900): larghezza massima
 *   - maxHeight (int, default 900): altezza massima
 *   - quality (int 0-100, default 78): qualità per WebP
 *   - outputFormat (string, default 'webp'): formato output ('webp', 'jpeg', 'png')
 *   - stripMetadata (bool, default true): rimuove metadati se possibile
 *   - keepOriginal (bool, default false): mantiene originale se compressione fallisce
 * @return array Risultato: ['ok'=>bool, 'path'=>string, 'mime'=>string, 'width'=>int, 'height'=>int, 'size'=>int, 'skipped'=>bool, 'error'=>string]
 */
function compressUploadedImage(string $tmpPath, string $destPath, array $options = []): array
{
    // Default options
    $maxWidth = isset($options['maxWidth']) ? max(1, intval($options['maxWidth'])) : 900;
    $maxHeight = isset($options['maxHeight']) ? max(1, intval($options['maxHeight'])) : 900;
    $quality = isset($options['quality']) ? max(0, min(100, intval($options['quality']))) : 78;
    $outputFormat = isset($options['outputFormat']) ? strtolower($options['outputFormat']) : 'webp';
    $stripMetadata = isset($options['stripMetadata']) ? (bool) $options['stripMetadata'] : true;
    $keepOriginal = isset($options['keepOriginal']) ? (bool) $options['keepOriginal'] : false;

    // Verifica file esistente
    if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
        return ['ok' => false, 'skipped' => false, 'error' => 'File non accessibile'];
    }

    // Determina MIME reale
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    // Verifica se è immagine supportata
    $supportedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($detectedMime, $supportedMimes)) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Non è un\'immagine supportata'];
    }

    // Leggi dimensioni e info immagine
    $info = @getimagesize($tmpPath);
    if (!$info || empty($info['mime'])) {
        return ['ok' => false, 'skipped' => false, 'error' => 'Impossibile leggere informazioni immagine'];
    }

    $originalWidth = $info[0];
    $originalHeight = $info[1];
    $mime = $info['mime'];

    // Carica immagine con GD
    $image = match ($mime) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($tmpPath),
        'image/png' => @imagecreatefrompng($tmpPath),
        'image/gif' => @imagecreatefromgif($tmpPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
        default => false
    };

    if (!$image) {
        return ['ok' => false, 'skipped' => false, 'error' => 'Impossibile caricare immagine'];
    }

    // Calcola nuove dimensioni mantenendo aspect ratio, no upscaling
    $newWidth = $originalWidth;
    $newHeight = $originalHeight;

    if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int) round($originalWidth * $ratio);
        $newHeight = (int) round($originalHeight * $ratio);
    }

    // Crea immagine ridimensionata
    $resized = @imagecreatetruecolor($newWidth, $newHeight);
    if (!$resized) {
        imagedestroy($image);
        return ['ok' => false, 'skipped' => false, 'error' => 'Impossibile creare immagine ridimensionata'];
    }

    // Mantieni trasparenza per PNG/GIF
    if ($mime === 'image/png' || $mime === 'image/gif') {
        @imagealphablending($resized, false);
        @imagesavealpha($resized, true);
        $transparent = @imagecolorallocatealpha($resized, 255, 255, 255, 127);
        @imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Resize con alta qualità
    @imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

    // Prepara path destinazione con formato corretto
    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }

    $pathInfo = pathinfo($destPath);
    $baseName = $pathInfo['filename'];
    $finalPath = $destDir . '/' . $baseName;

    // Salva nel formato richiesto
    $saved = false;
    switch ($outputFormat) {
        case 'webp':
            if (function_exists('imagewebp')) {
                $finalPath .= '.webp';
                $saved = @imagewebp($resized, $finalPath, $quality);
                $finalMime = 'image/webp';
            } else {
                // Fallback a JPEG se WebP non supportato
                $finalPath .= '.jpg';
                $saved = @imagejpeg($resized, $finalPath, $quality);
                $finalMime = 'image/jpeg';
            }
            break;
        case 'jpeg':
        case 'jpg':
            $finalPath .= '.jpg';
            $saved = @imagejpeg($resized, $finalPath, $quality);
            $finalMime = 'image/jpeg';
            break;
        case 'png':
            $finalPath .= '.png';
            // PNG quality: 0-9 (9 = no compression), convertiamo da 0-100
            $pngQuality = (int) floor(9 * (100 - $quality) / 100);
            $saved = @imagepng($resized, $finalPath, $pngQuality);
            $finalMime = 'image/png';
            break;
        default:
            // Default WebP
            $finalPath .= '.webp';
            $saved = function_exists('imagewebp') ? @imagewebp($resized, $finalPath, $quality) : false;
            $finalMime = 'image/webp';
    }

    // Cleanup
    imagedestroy($image);
    imagedestroy($resized);

    if (!$saved || !file_exists($finalPath)) {
        // Se keepOriginal, salva originale
        if ($keepOriginal) {
            if (@copy($tmpPath, $destPath)) {
                return [
                    'ok' => true,
                    'path' => $destPath,
                    'mime' => $mime,
                    'width' => $originalWidth,
                    'height' => $originalHeight,
                    'size' => filesize($destPath),
                    'warning' => 'Compressione fallita, salvato originale'
                ];
            }
        }
        return ['ok' => false, 'skipped' => false, 'error' => 'Impossibile salvare immagine compressa'];
    }

    $finalSize = filesize($finalPath);

    return [
        'ok' => true,
        'path' => $finalPath,
        'mime' => $finalMime,
        'width' => $newWidth,
        'height' => $newHeight,
        'size' => $finalSize,
        'originalWidth' => $originalWidth,
        'originalHeight' => $originalHeight
    ];
}

function getAllowedImageTypes(): array
{
    return [
        'mimes' => ['image/jpeg', 'image/png'],
        'exts' => ['jpg', 'jpeg', 'png']
    ];
}

function capitalize($string)
{
    $string = strtolower($string);
    return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
}

/**
 * Normalizza $_FILES (singolo o multiplo) in un array di file omogenei.
 * Ritorna solo elementi con campi attesi (name,type,tmp_name,error,size).
 */
function normalizeFilesArray(array $files): array
{
    // caso già “normalizzato”
    $required = ['name', 'type', 'tmp_name', 'error', 'size'];
    $isFlat = count(array_intersect(array_keys($files), $required)) === 5;

    if ($isFlat && !is_array($files['name'])) {
        return [$files];
    }

    // multiplo: name[], type[], ...
    $out = [];
    if ($isFlat && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    // struttura inattesa: prova a raccogliere eventuali sotto-entries
    foreach ($files as $maybe) {
        if (is_array($maybe) && count(array_intersect(array_keys($maybe), $required)) === 5) {
            if (is_array($maybe['name'])) {
                // ricorsione su multipli annidati
                $out = array_merge($out, normalizeFilesArray($maybe));
            } else {
                $out[] = $maybe;
            }
        }
    }
    return $out;
}

/**
 * Gestisce l'upload delle immagini per le task commesse e restituisce array di path relativi salvati.
 * @param array  $fileArr     es. $_FILES['screenshots'] (singolo o multiplo)
 * @param string $uploadDir   path ASSOLUTO di destinazione
 * @param string $relativeDir path RELATIVO da salvare su DB
 * @return array              array di path relativi (in .webp)
 */
function handleTaskImageUpload($fileArr, $uploadDir, $relativeDir): array
{
    $allegati = [];

    if (!is_array($fileArr))
        return $allegati;
    if (!is_dir($uploadDir))
        @mkdir($uploadDir, 0775, true);

    $screens = normalizeFilesArray($fileArr);

    // vincoli
    $allowedMimes = ['image/jpeg', 'image/png'];
    $allowedExts = ['jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    foreach ($screens as $file) {
        // scarta non upload o errori
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            continue;
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
            continue;

        $origName = basename((string) $file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // MIME reale dal tmp
        $mime = @mime_content_type($file['tmp_name']) ?: ($file['type'] ?? '');

        // validazioni base
        if (!in_array($mime, $allowedMimes, true))
            continue;
        if (!in_array($ext, $allowedExts, true))
            continue;
        if (($file['size'] ?? 0) > $maxSize)
            continue;

        // nome univoco (salviamo direttamente in webp)
        $uniqueBase = uniqid('img_', true);
        $destAbs = rtrim($uploadDir, '/') . '/' . $uniqueBase . '.webp';
        $destRel = rtrim($relativeDir, '/') . '/' . $uniqueBase . '.webp';

        // converti/comprimi a webp usando la tua compressImage()
        $ok = compressImage($file['tmp_name'], $destAbs, 75, true);
        if ($ok) {
            $allegati[] = $destRel;
        }
    }

    return $allegati;
}

function estraiDisciplinaDaRuolo($ruolo)
{
    $disciplina = null;
    $subdisciplina = null;
    $badge = null;

    if (!$ruolo)
        return ['disciplina' => null, 'subdisciplina' => null, 'badge' => null];

    // Mappa prefissi→disciplina/badge
    $mappa = [
        'UT-ARC' => ['disciplina' => 'architettura', 'badge' => 'ARC'],
        'UT-STR' => ['disciplina' => 'strutture', 'badge' => 'STR'],
        'UT-MEC' => ['disciplina' => 'meccanico', 'badge' => 'MEC'],
        'UT-ELE' => ['disciplina' => 'elettrico', 'badge' => 'ELE'],
        'UT-BIM' => ['disciplina' => 'bim', 'badge' => 'BIM'],
        'UT-CIV' => ['disciplina' => 'civile', 'badge' => 'CIV'],
        'UT-DT' => ['disciplina' => 'dir. tecnica', 'badge' => 'DT'],
        'UT-R&D' => ['disciplina' => 'r&d', 'badge' => 'R&D'],
        'UT-SIC' => ['disciplina' => 'sicurezza', 'badge' => 'SIC'],
        'SG-AMM' => ['disciplina' => 'amministrazione', 'badge' => 'AMM'],
        'SG-COMM' => ['disciplina' => 'commerciale', 'badge' => 'COMM'],
        'SG-ITN' => ['disciplina' => 'informatico', 'badge' => 'ITN'],
        'SG-MKT' => ['disciplina' => 'marketing', 'badge' => 'MKT'],
        'SQ-RESP' => ['disciplina' => 'qualità', 'badge' => 'QUAL'],
        'user' => ['disciplina' => 'user', 'badge' => 'USR'],
    ];

    // Cicla sui prefissi
    foreach ($mappa as $prefix => $dati) {
        if (strpos($ruolo, $prefix) === 0) {
            $disciplina = $dati['disciplina'];
            $badge = $dati['badge'];
            break;
        }
    }

    // Subdisciplina: SOLO se UT-MEC, UT-ELE, UT-STR, UT-ARC, UT-BIM
    if ($disciplina === 'meccanico' && stripos($ruolo, 'bim specialist') !== false) {
        $subdisciplina = 'bim specialist';
    }
    if ($disciplina === 'strutture' && stripos($ruolo, 'bim specialist') !== false) {
        $subdisciplina = 'bim specialist';
    }
    // Puoi aggiungere altre regole qui per subdiscipline

    return [
        'disciplina' => $disciplina,
        'subdisciplina' => $subdisciplina,
        'badge' => $badge
    ];
}


function getDocumentAreaPages(string $documentArea, ?string $menuTitle = null): array
{
    global $database;
    if (!\Services\DocumentAreaRegistry::isValid($documentArea)) {
        return [];
    }

    $sql = "SELECT titolo, slug, menu_title, colore, descrizione, immagine FROM document_manager_pagine WHERE section = ? ";
    $params = [$documentArea];

    if ($menuTitle !== null && $menuTitle !== '') {
        $menuTitle = trim($menuTitle);
        $sql .= " AND menu_title = ? ";
        $params[] = $menuTitle;
    }

    $sql .= " ORDER BY ordinamento ASC, titolo ASC";
    try {
        $res = $database->query($sql, $params, __FILE__ . ' ⇒ ' . __FUNCTION__);
        return $res->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Ritorna true se l'utente corrente può vedere il modulo dato il suo ID.
 * Regole:
 *  - admin (role_id=1) sempre true
 *  - se NON esistono righe in moduli_visibilita per quel modulo => visibile a tutti
 *  - altrimenti visibile solo se esiste una riga con ruolo_id = role_id utente
 */
/**
 * Verifica se l'utente corrente può accedere a una pagina Page Editor (form).
 * Usa il sistema multi-ruolo con permessi page_editor_form_view:<form_id>.
 * 
 * @param int $formId ID del form dalla tabella forms
 * @return bool true se l'utente può accedere, false altrimenti
 */
function canCurrentUserViewFormById(int $formId): bool
{
    // ADMIN può sempre vedere tutto
    if (isAdmin()) {
        if (defined('APP_ENV') && APP_ENV === 'dev') {
            error_log(sprintf("[MENU DEBUG] canCurrentUserViewFormById(%d): admin bypass", $formId));
        }
        return true;
    }

    // ECCEZIONE: se siamo in impostazioni_moduli => mostra comunque tutto (per gestione admin)
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'gestione';
    if ($page === 'impostazioni_moduli' && $section === 'gestione_intranet') {
        if (defined('APP_ENV') && APP_ENV === 'dev') {
            error_log(sprintf("[MENU DEBUG] canCurrentUserViewFormById(%d): impostazioni_moduli bypass", $formId));
        }
        return true;
    }



    // Verifica permesso specifico page_editor_form_view:<form_id>
    $requiredPermission = "page_editor_form_view:{$formId}";
    $hasPermission = userHasPermission($requiredPermission);



    if (defined('APP_ENV') && APP_ENV === 'dev') {
        error_log(sprintf(
            "[MENU DEBUG] canCurrentUserViewFormById(%d): permission=%s, hasPermission=%s",
            $formId,
            $requiredPermission,
            $hasPermission ? 'yes' : 'no'
        ));
    }

    return $hasPermission;
}

/**
 * Shortcut: controllo visibilità dato il nome del modulo.
 */
function canCurrentUserViewFormByName(string $formName): bool
{
    global $database;

    $row = $database->query(
        "SELECT id FROM forms WHERE name = :n LIMIT 1",
        [':n' => $formName],
        __FILE__ . ' ==> ' . __LINE__
    )->fetch(PDO::FETCH_ASSOC);

    if (!$row)
        return false;
    return canCurrentUserViewFormById(intval($row['id']));
}

/**
 * Guard da chiamare a inizio pagina per bloccare accesso diretto
 * alle pagine di un modulo non visibile.
 *
 * USO:
 *   - dentro le pagine:
 *       gestione_segnalazioni, form, form_viewer, form_view
 *     aggiungi SUBITO dopo i require/inizializzazioni:
 *       enforceFormVisibilityOrRedirect();
 */
function enforceFormVisibilityOrRedirect(): void
{
    $section = $_GET['section'] ?? '';
    $page = $_GET['page'] ?? '';
    $formName = isset($_GET['form_name']) ? urldecode($_GET['form_name']) : null;

    // Applica il controllo solo alle pagine che mostrano un modulo specifico
    $pagesToProtect = ['gestione_segnalazioni', 'form', 'form_viewer', 'form_view'];

    if ($section === 'collaborazione' && in_array($page, $pagesToProtect, true) && $formName) {
        if (!canCurrentUserViewFormByName($formName)) {
            // Messaggio semplice senza overlay/redirect automatici
            echo "<div style='color:red; padding:20px; border:1px solid red; margin:20px; background:#ffeaea;'>";
            echo "<h3>Accesso Negato al Modulo</h3>";
            echo "<p>Non hai i permessi per accedere a questo modulo.</p>";
            echo "<p>Modulo: <strong>{$formName}</strong></p>";
            echo "</div>";

            exit; // Stop esecuzione pulita
        }
    }
}

/**
 * Restituisce le sezioni “realmente” disponibili (filtrate da permessi e contenuto),
 * nel formato: [ ['key' => 'collaborazione', 'label' => 'Collaborazione'], ... ]
 */
function getMenuSectionsForCustom(): array
{
    $full = getStaticMenu(null, true); // full menu senza filtro forzato
    $out = [];
    foreach ($full as $key => $sec) {
        // scarta sezioni senza voci
        if (empty($sec['menus']))
            continue;
        $out[] = [
            'key' => $key,
            'label' => $sec['label'] ?? ucfirst($key)
        ];
    }
    return $out;
}

/**
 * Calcola la visibilità dei blocchi "Protocollo Email" basata sui permessi avanzati.
 * Regola: se nessuno spuntato → entrambi visibili (default permissivo),
 * se solo uno spuntato → visibile solo quello,
 * se entrambi spuntati → visibili entrambi.
 * 
 * @return array ['generale' => bool, 'commesse' => bool]
 */
function getProtocolloEmailVisibility(): array
{
    // Admin vede sempre tutto
    if (isAdmin()) {
        return ['generale' => true, 'commesse' => true];
    }

    $hasGenerale = userHasPermission('protocollo_menu_generale');
    $hasCommesse = userHasPermission('protocollo_menu_commesse');

    // Logica "limita a":
    // - Nessuno spuntato → entrambi visibili (default permissivo)
    // - Solo generale → solo generale
    // - Solo commesse → solo commesse
    // - Entrambi → entrambi

    if (!$hasGenerale && !$hasCommesse) {
        // Nessuno spuntato: default permissivo → entrambi
        return ['generale' => true, 'commesse' => true];
    }

    if ($hasGenerale && !$hasCommesse) {
        // Solo generale spuntato → solo generale
        return ['generale' => true, 'commesse' => false];
    }

    if (!$hasGenerale && $hasCommesse) {
        // Solo commesse spuntato → solo commesse
        return ['generale' => false, 'commesse' => true];
    }

    // Entrambi spuntati → entrambi
    return ['generale' => true, 'commesse' => true];
}

/**
 * Ritorna i possibili "menu padre" (quelli di 1° livello) di una sezione,
 * nel formato: [ 'Segnalazioni', 'Comunicazione', ... ]
 */
function getParentMenusBySection(string $section): array
{
    $menu = getStaticMenu($section, true); // chiediamo il menu “completo” per la sezione
    if (!isset($menu[$section]) || empty($menu[$section]['menus']))
        return [];

    $parents = [];
    foreach ($menu[$section]['menus'] as $m) {
        // considera validi solo quelli che possono contenere submenus
        // (se non esiste 'submenus', li consideriamo cmq validi: la tua UI deciderà se consentire iniezione o meno)
        $parents[] = $m['title'];
    }
    return $parents;
}

/* ============================================
   REMEMBER ME - Sistema di autenticazione persistente sicuro
   Tabella: sys_user_remember_tokens
   Cookie: remember_me (selector:validator)
   ============================================ */

/**
 * Crea un token "Ricordami" per l'utente e imposta il cookie.
 * Schema: selector (16 bytes hex) + validator (32 bytes hex)
 * In DB salva selector + hash(validator), nel cookie "selector:validator"
 *
 * @param int $userId ID dell'utente
 * @return void
 */
function createRememberToken(int $userId): void
{
    global $database;

    // Genera selector (16 bytes = 32 hex) e validator (32 bytes = 64 hex)
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);

    // Scadenza: 30 giorni
    $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));

    // Dati utente per logging
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Inserisci in DB
    $sql = "INSERT INTO sys_user_remember_tokens
            (user_id, selector, token_hash, expires_at, created_at, last_used_at, user_agent, ip)
            VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)";

    $result = $database->query($sql, [
        $userId,
        $selector,
        $tokenHash,
        $expiresAt,
        substr($userAgent, 0, 255),
        substr($ip, 0, 45)
    ], __FILE__ . ' ==> createRememberToken');

    if ($result === false) {
        error_log('createRememberToken: INSERT failed for userId=' . $userId);
        return;
    }

    // Imposta cookie
    $cookieValue = $selector . ':' . $validator;
    $cookieOptions = [
        'expires' => time() + (60 * 60 * 24 * 30), // 30 giorni
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    setcookie('remember_me', $cookieValue, $cookieOptions);
}

/**
 * Cancella il token "Ricordami" dal DB e rimuove il cookie.
 * Se viene passato userId, cancella TUTTI i token di quell'utente.
 *
 * @param int|null $userId Se fornito, cancella tutti i token dell'utente
 * @return void
 */
function clearRememberToken(?int $userId = null): void
{
    global $database;

    // Se c'è il cookie, estrai selector e cancella quel record
    if (isset($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me']);
        if (count($parts) === 2) {
            $selector = $parts[0];
            $database->query(
                "DELETE FROM sys_user_remember_tokens WHERE selector = ?",
                [$selector],
                __FILE__ . ' ==> clearRememberToken.selector'
            );
        }
    }

    // Se userId fornito, cancella TUTTI i token dell'utente
    if ($userId !== null) {
        $database->query(
            "DELETE FROM sys_user_remember_tokens WHERE user_id = ?",
            [$userId],
            __FILE__ . ' ==> clearRememberToken.userId'
        );
    }

    // Rimuovi il cookie
    $cookieOptions = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('remember_me', '', $cookieOptions);
}

/**
 * Tenta l'auto-login dal cookie "Ricordami".
 * Se il cookie è valido e l'utente non è disabilitato, imposta la sessione.
 * Esegue rotazione del validator per sicurezza.
 *
 * @return bool True se auto-login riuscito, false altrimenti
 */
function tryAutoLoginFromRememberCookie(): bool
{
    global $database;

    // Se già loggato, ok
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return true;
    }

    // Se cookie assente, fail
    if (!isset($_COOKIE['remember_me'])) {
        return false;
    }

    // Parse cookie
    $parts = explode(':', $_COOKIE['remember_me']);
    if (count($parts) !== 2) {
        clearRememberToken();
        return false;
    }

    $selector = $parts[0];
    $validator = $parts[1];

    // Validazione formato
    if (strlen($selector) !== 32 || strlen($validator) !== 64) {
        clearRememberToken();
        return false;
    }

    // Cerca token in DB (non scaduto)
    $sql = "SELECT t.*, u.id as uid, u.username, u.ragsoc, u.disabled, u.profile_picture,
                   sur.role_id, sr.name as ruolo
            FROM sys_user_remember_tokens t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN sys_user_roles sur ON sur.user_id = u.id
            LEFT JOIN sys_roles sr ON sr.id = sur.role_id
            WHERE t.selector = ?
            AND t.expires_at > NOW()
            LIMIT 1";

    $result = $database->query($sql, [$selector], __FILE__ . ' ==> tryAutoLoginFromRememberCookie');

    if (!$result) {
        clearRememberToken();
        return false;
    }

    $row = $result->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        clearRememberToken();
        return false;
    }

    // Verifica hash del validator (timing-safe)
    $expectedHash = $row['token_hash'];
    $providedHash = hash('sha256', $validator);

    if (!hash_equals($expectedHash, $providedHash)) {
        // Token non valido - cancella
        $database->query(
            "DELETE FROM sys_user_remember_tokens WHERE selector = ?",
            [$selector],
            __FILE__ . ' ==> tryAutoLoginFromRememberCookie.invalidToken'
        );
        clearRememberToken();
        return false;
    }

    // Verifica utente non disabilitato
    if ($row['disabled'] == '1') {
        clearRememberToken((int) $row['uid']);
        return false;
    }

    // === AUTO-LOGIN RIUSCITO ===

    // Imposta sessione (stesso formato del login normale)
    $_SESSION['username'] = $row['username'];
    $_SESSION['user_id'] = $row['uid'];
    $_SESSION['token'] = bin2hex(random_bytes(32));

    $_SESSION['IPaddress'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (empty($_SESSION['CSRFtoken'])) {
        $_SESSION['CSRFtoken'] = bin2hex(random_bytes(32));
    }

    // Aggiorna auth_token in users (per compatibilità con sistema esistente)
    $database->query(
        "UPDATE users SET auth_token = ? WHERE id = ?",
        [$_SESSION['token'], $row['uid']],
        __FILE__ . ' ==> tryAutoLoginFromRememberCookie.updateAuthToken'
    );

    // Aggiorna last_used_at
    $database->query(
        "UPDATE sys_user_remember_tokens SET last_used_at = NOW() WHERE selector = ?",
        [$selector],
        __FILE__ . ' ==> tryAutoLoginFromRememberCookie.lastUsed'
    );

    // === ROTAZIONE TOKEN ===
    $newValidator = bin2hex(random_bytes(32));
    $newTokenHash = hash('sha256', $newValidator);

    $database->query(
        "UPDATE sys_user_remember_tokens SET token_hash = ? WHERE selector = ?",
        [$newTokenHash, $selector],
        __FILE__ . ' ==> tryAutoLoginFromRememberCookie.rotateToken'
    );

    // Aggiorna cookie con nuovo validator
    $cookieValue = $selector . ':' . $newValidator;
    $cookieOptions = [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('remember_me', $cookieValue, $cookieOptions);

    return true;
}

/**
 * Ottiene i dati dell'utente "candidato" per la schermata "Bentornato".
 * NON esegue auto-login, solo verifica se il cookie è valido.
 * Usato nella pagina di login per mostrare "Bentornato <nome>".
 *
 * @return array|null Dati utente minimi (id, username, ragsoc, profile_picture) o null
 */
function getRememberCandidateUser(): ?array
{
    global $database;

    // Se cookie assente, niente
    if (!isset($_COOKIE['remember_me'])) {
        return null;
    }

    // Parse cookie
    $parts = explode(':', $_COOKIE['remember_me']);
    if (count($parts) !== 2) {
        return null;
    }

    $selector = $parts[0];
    $validator = $parts[1];

    // Validazione formato
    if (strlen($selector) !== 32 || strlen($validator) !== 64) {
        return null;
    }

    // Cerca token in DB (non scaduto)
    $sql = "SELECT t.token_hash, u.id, u.username, u.ragsoc, u.disabled, u.profile_picture
            FROM sys_user_remember_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.selector = ?
            AND t.expires_at > NOW()
            LIMIT 1";

    $result = $database->query($sql, [$selector], __FILE__ . ' ==> getRememberCandidateUser');

    if (!$result) {
        return null;
    }

    $row = $result->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // Verifica hash del validator
    $expectedHash = $row['token_hash'];
    $providedHash = hash('sha256', $validator);

    if (!hash_equals($expectedHash, $providedHash)) {
        return null;
    }

    // Verifica utente non disabilitato
    if ($row['disabled'] == '1') {
        return null;
    }

    // Ritorna dati minimi per UI "Bentornato"
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'ragsoc' => $row['ragsoc'],
        'profile_picture' => $row['profile_picture']
    ];
}

/**
 * Sanificazione output per testo mojibake da charset mismatch;
 * non sostituisce la correzione a monte.
 */
function fixMojibake($value)
{
    // Se è un array, attraversa ricorsivamente
    if (is_array($value)) {
        foreach ($value as &$item) {
            $item = fixMojibake($item);
        }
        unset($item);
        return $value;
    }

    // Se non è stringa, ritorna com'è
    if (!is_string($value)) {
        return $value;
    }

    // Espandi detection per catturare più pattern mojibake comuni
    if (!preg_match('/[ÂÃâ€â€¦™®©]/u', $value)) {
        return $value;
    }

    // Prima applica sostituzioni specifiche per sequenze UTF-8 mal codificate
    $replacements = [
        // Sequenze UTF-8 mal codificate più comuni (devono essere applicate PRIMA della conversione)
        'â€“' => '–',    // en dash
        'â€”' => '—',    // em dash
        'â€˜' => "'",    // left single quotation mark
        'â€™' => "'",    // right single quotation mark
        'â€œ' => '"',    // left double quotation mark
        'â€�' => '"',    // right double quotation mark (â + € + �)
        'â€' => '"',     // right double quotation mark (variante)
        'â€š' => "'",    // single low-9 quotation mark
        'â€ž' => '"',    // double low-9 quotation mark
        'â€' => '"',     // right double quotation mark (parziale)
        'â€¢' => '•',    // bullet
        'â€¦' => '…',    // horizontal ellipsis
        'â„¢' => '™',    // trademark
        'â®' => '®',    // registered
        'â©' => '©',    // copyright
        'â‚¬' => '€',    // euro sign
        'â£' => '£',    // pound sign
        'â¥' => '¥',    // yen sign
        'â§' => '§',    // section sign
        'â¨' => '¨',    // diaeresis
        'âª' => 'ª',    // feminine ordinal indicator
        'â«' => '«',    // left-pointing double angle quotation mark
        'â¬' => '¬',    // not sign
        'â®' => '®',    // registered sign
        'â¯' => '¯',    // macron
        'â°' => '°',    // degree sign
        'â±' => '±',    // plus-minus sign
        'â²' => '²',    // superscript two
        'â³' => '³',    // superscript three
        'â´' => '´',    // acute accent
        'âµ' => 'µ',    // micro sign
        'â¶' => '¶',    // pilcrow sign
        'â·' => '·',    // middle dot
        'â¸' => '¸',    // cedilla
        'â¹' => '¹',    // superscript one
        'âº' => 'º',    // masculine ordinal indicator
        'â»' => '»',    // right-pointing double angle quotation mark
        'â¼' => '¼',    // vulgar fraction one quarter
        'â½' => '½',    // vulgar fraction one half
        'â¾' => '¾',    // vulgar fraction three quarters
        'â¿' => '¿',    // inverted question mark

        // Â con variazioni
        'Â°' => '°',     // degree sign
        'Âº' => 'º',     // masculine ordinal
        'Âª' => 'ª',     // feminine ordinal
        'Â ' => ' ',     // space after Â
        'Â' => '',       // Â isolato (fallback)

        // Ã con variazioni comuni (dopo conversione double-encoding)
        'Ã¡' => 'á',     // a acute
        'Ã©' => 'é',     // e acute
        'Ã­' => 'í',     // i acute
        'Ã³' => 'ó',     // o acute
        'Ãº' => 'ú',     // u acute
        'Ã±' => 'ñ',     // n tilde
        'Ã¼' => 'ü',     // u diaeresis
        'Ã¤' => 'ä',     // a diaeresis
        'Ã¶' => 'ö',     // o diaeresis
        'ÃŸ' => 'ß',     // sharp s
        'Ã§' => 'ç',     // c cedilla
        'Ã¨' => 'è',     // e grave
        'Ã¬' => 'ì',     // i grave
        'Ã²' => 'ò',     // o grave
        'Ã¹' => 'ù',     // u grave
        'Ã' => '',       // Ã isolato (fallback)
    ];

    foreach ($replacements as $from => $to) {
        $value = str_replace($from, $to, $value);
    }

    // POI prova correzione double-encoding SOLO se ancora ci sono problemi
    if (preg_match('/[ÂÃâ€â€¦™®©]/u', $value) && function_exists('mb_convert_encoding')) {
        $originalMojibakeCount = preg_match_all('/[ÂÃâ€â€¦™®©]/u', $value);

        // Tentativo 1: conversione da UTF-8 a ISO-8859-1 e poi riconversione (sostituisce utf8_decode deprecato)
        $decoded = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        $converted1 = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');

        // Tentativo 2: conversione diretta da Windows-1252 a UTF-8
        $converted2 = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        // Scegli il migliore (quello che riduce di più i caratteri mojibake)
        $count1 = preg_match_all('/[ÂÃâ€â€¦™®©]/u', $converted1);
        $count2 = preg_match_all('/[ÂÃâ€â€¦™®©]/u', $converted2);

        if ($count1 < $originalMojibakeCount && $count1 <= $count2) {
            $value = $converted1;
        } elseif ($count2 < $originalMojibakeCount) {
            $value = $converted2;
        }

        // Applica nuovamente le sostituzioni dopo la conversione
        foreach ($replacements as $from => $to) {
            $value = str_replace($from, $to, $value);
        }
    }

    // Trim leggero solo se necessario
    $value = trim($value, " \t\n\r\0\x0B");

    return $value;
}

/**
 * Pulisce i token scaduti dalla tabella sys_user_remember_tokens.
 * Da chiamare periodicamente (es. nel bootstrap o via cron).
 *
 * @return void
 */
function cleanupExpiredRememberTokens(): void
{
    global $database;

    $database->query(
        "DELETE FROM sys_user_remember_tokens WHERE expires_at < NOW()",
        [],
        __FILE__ . ' ==> cleanupExpiredRememberTokens'
    );
}

/**
 * Estrae le iniziali da un nome completo.
 * Es: "Mario Rossi" => "MR", "Anna Maria Bianchi" => "AB"
 *
 * @param string|null $fullName Nome completo
 * @return string Iniziali (max 2 caratteri) o '??' se vuoto
 */
function getInitials(?string $fullName): string
{
    if (empty($fullName) || $fullName === '—' || $fullName === '-') {
        return '??';
    }

    $parts = preg_split('/\s+/', trim($fullName));
    $initials = '';

    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return mb_substr($initials, 0, 2) ?: '??';
}

/**
 * Restituisce la classe CSS suffix per il pill dello stato commessa.
 * Usato per colorare i badge di stato nelle tabelle moderne.
 *
 * @param string|null $stato Valore dello stato
 * @return string Suffix classe CSS (es: 'success', 'warning', 'danger', 'default')
 */
function getStatoPillClass(?string $stato): string
{
    $stato = strtolower(trim($stato ?? ''));

    switch ($stato) {
        case 'aperta':
            return 'success';
        case 'chiusa':
            return 'danger';
        default:
            return 'default';
    }
}
