<?php
namespace Services;

class MenuCustomService
{
    public static function listAll(): array
    {
        global $database;
        $stmt = $database->query(
            "SELECT * FROM menu_custom ORDER BY section, parent_title, ordinamento, id",
            [],
            __FILE__
        );
        return ['success' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    public static function getSectionsAndParents(): array
    {
        return ['success' => true, 'data' => getAllSectionsAndParents()];
    }

    public static function upsert(array $in): array
    {
        global $database;

        $id = isset($in['id']) ? (int) $in['id'] : null;
        $section = trim((string) ($in['section'] ?? $in['menu_section'] ?? ''));
        $parent_title = trim((string) ($in['parent_title'] ?? ''));
        $title = trim((string) ($in['title'] ?? ''));
        $link = trim((string) ($in['link'] ?? ''));
        $attivo = isset($in['attivo']) ? (int) $in['attivo'] : 1;
        $ordinamento = isset($in['ordinamento']) ? (int) $in['ordinamento'] : 100;

        if ($section === '' || $parent_title === '' || $title === '' || $link === '') {
            return ['success' => false, 'message' => 'Compila tutti i campi obbligatori.'];
        }

        // whitelist sezione (allineata con SidebarService)
        $sectionWhitelist = [
            'archivio',
            'collaborazione',
            'area-tecnica',
            'gestione',
            'profilo',
            'notifiche',
            'gestione_intranet',
            'changelog',
            'commesse',
            'hr',
            'commerciale'
        ];
        if (!in_array($section, $sectionWhitelist, true)) {
            return ['success' => false, 'message' => 'Sezione non valida.'];
        }

        try {
            if ($id) {
                // update diretto se ho l???ID
                $database->query(
                    "UPDATE menu_custom
                     SET section=:s, parent_title=:p, title=:t, link=:l, attivo=:a, ordinamento=:o, updated_at=NOW()
                     WHERE id=:id",
                    [
                        ':s' => $section,
                        ':p' => $parent_title,
                        ':t' => $title,
                        ':l' => $link,
                        ':a' => $attivo,
                        ':o' => $ordinamento,
                        ':id' => $id
                    ],
                    __FILE__
                );
            } else {
                // ???? FIX ESTREMO: Cerca la riga esistente in modo pi?? robusto per evitare duplicati nello spostamento
                $existingId = null;

                // 1. Prova match esatto del link (pi?? veloce)
                $res = $database->query("SELECT id FROM menu_custom WHERE link=:l LIMIT 1", [':l' => $link], __FILE__);
                if ($res && ($row = $res->fetch(\PDO::FETCH_ASSOC))) {
                    $existingId = $row['id'];
                }

                // 2. Se non trovato e il link parla di un form, prova match per form_name nel parametro URL
                if (!$existingId && strpos($link, 'form_name=') !== false) {
                    $parsed = parse_url($link);
                    if (!empty($parsed['query'])) {
                        parse_str($parsed['query'], $qs);
                        $fname = $qs['form_name'] ?? '';
                        if ($fname) {
                            // Cerca qualsiasi link che contenga lo stesso form_name (gestisce varianti come index.php? o ?)
                            $res = $database->query(
                                "SELECT id FROM menu_custom WHERE link LIKE :f LIMIT 1",
                                [':f' => "%form_name=$fname%"],
                                __FILE__
                            );
                            if ($res && ($row = $res->fetch(\PDO::FETCH_ASSOC))) {
                                $existingId = $row['id'];
                            }
                        }
                    }
                }

                if ($existingId) {
                    // Trovata! Aggiorniamo la posizione (section, parent), il titolo e il link (per normalizzarlo)
                    $database->query(
                        "UPDATE menu_custom
                         SET section=:s, parent_title=:p, title=:t, link=:l, attivo=:a, ordinamento=:o, updated_at=NOW()
                         WHERE id=:id",
                        [
                            ':s' => $section,
                            ':p' => $parent_title,
                            ':t' => $title,
                            ':l' => $link,
                            ':a' => $attivo,
                            ':o' => $ordinamento,
                            ':id' => $existingId
                        ],
                        __FILE__
                    );
                } else {
                    // Non esiste alcuna voce per questo link ??? inserisco
                    $database->query(
                        "INSERT INTO menu_custom
                         (section, parent_title, title, link, attivo, ordinamento, created_at, updated_at)
                         VALUES (:s,:p,:t,:l,:a,:o,NOW(),NOW())",
                        [
                            ':s' => $section,
                            ':p' => $parent_title,
                            ':t' => $title,
                            ':l' => $link,
                            ':a' => $attivo,
                            ':o' => $ordinamento
                        ],
                        __FILE__
                    );
                }
            }
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore DB: ' . $e->getMessage()];
        }
    }

    public static function delete($id)
    {
        global $database;

        $row = $database->query(
            "SELECT id, link FROM menu_custom WHERE id=:id LIMIT 1",
            [':id' => (int) $id],
            __FILE__ . ' ??? menu_delete.load'
        )->fetch(\PDO::FETCH_ASSOC);

        if ($row && !empty($row['link'])) {
            $parts = parse_url($row['link']);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['page']) && $q['page'] === 'form' && !empty($q['form_name'])) {
                    try {
                        \Services\PageEditorService::deleteForm(['form_name' => (string) $q['form_name']]);
                    } catch (\Throwable $e) { /* ignora */
                    }
                }
            }
        }

        $ok = $database->query(
            "DELETE FROM menu_custom WHERE id=:id",
            [':id' => (int) $id],
            __FILE__ . ' ??? menu_delete.exec'
        );

        return ['success' => (bool) $ok];
    }

    public static function reorder(array $rows): array
    {
        global $database;
        if (!is_array($rows))
            return ['success' => false, 'message' => 'Payload non valido'];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $o = (int) ($r['ordinamento'] ?? 100);
            if ($id > 0) {
                $database->query(
                    "UPDATE menu_custom SET ordinamento=:o WHERE id=:id",
                    [':o' => $o, ':id' => $id],
                    __FILE__
                );
            }
        }
        return ['success' => true];
    }

    public static function getMenuPlacementForForm(string $form_name): array
    {
        global $database;
        $form_name = trim($form_name);
        if ($form_name === '') {
            return ['success' => false, 'message' => 'form_name mancante'];
        }

        try {
            $stmt = $database->query(
                "SELECT section, parent_title
                 FROM menu_custom
                 WHERE title = :t
                 ORDER BY attivo DESC, ordinamento ASC, id DESC
                 LIMIT 1",
                [':t' => $form_name],
                __FILE__
            );
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            if ($row) {
                return ['success' => true, 'placement' => $row];
            } else {
                return ['success' => false, 'message' => 'nessuna voce di menu trovata'];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'errore lettura menu: ' . $e->getMessage()];
        }
    }
}

