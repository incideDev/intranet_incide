<?php
namespace Services;

class SidebarService
{

    public static function getSidebarMenu($section = 'archivio')
    {

        $sectionWhitelist = ['archivio', 'qualita', 'collaborazione', 'area-tecnica', 'gestione', 'profilo', 'notifiche', 'login', 'logout', 'gestione_intranet', 'commesse', 'hr', 'commerciale'];

        $section = is_string($section) ? trim($section) : 'archivio';

        if (!in_array($section, $sectionWhitelist, true)) {
            $section = 'archivio';
        }

        $menuData = getStaticMenu($section);
        $sectionMenu = $menuData[$section]['menus'] ?? [];

        return ['success' => true, 'menus' => $sectionMenu];
    }

    // Nuovo: elenco sezioni disponibili (per la prima select)
    public static function getSectionsList()
    {
        // fullMenu=true => ritorna la mappa completa di tutte le sezioni
        $all = getStaticMenu(null, true);
        // Normalizzo in {key, label}
        $out = [];
        foreach ($all as $k => $v) {
            $out[] = [
                'key' => $k,
                'label' => $v['label'] ?? ucfirst($k)
            ];
        }
        return ['success' => true, 'sections' => $out];
    }

    // Nuovo: elenco dei parent menu per una sezione (per la seconda select)
    public static function getParentMenusForSection($section = 'archivio')
    {
        $all = getStaticMenu($section, true);
        if (!isset($all[$section])) {
            return ['success' => false, 'parents' => [], 'message' => 'Sezione non trovata'];
        }
        $parents = [];
        foreach ($all[$section]['menus'] as $m) {
            // Un parent ?? una voce che ha submenus OPPURE pu?? accogliere submenus
            if (isset($m['submenus']) && is_array($m['submenus'])) {
                $parents[] = [
                    'title' => $m['title'],
                    'subCount' => count($m['submenus']),
                ];
            } else {
                // opzionale: se vuoi permettere di creare submenus anche su voci singole
                // $parents[] = ['title' => $m['title'], 'subCount' => 0];
            }
        }
        return ['success' => true, 'parents' => $parents];
    }

    // (facoltativo) Struttura completa della sezione per UI pi?? ricca
    public static function getSidebarStructure($section = 'archivio')
    {
        $all = getStaticMenu($section, true);
        return [
            'success' => true,
            'structure' => $all[$section] ?? ['menus' => []]
        ];
    }

}

