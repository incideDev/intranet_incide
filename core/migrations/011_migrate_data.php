<?php
/**
 * Migration 011 - Data migration: populate categories + segments columns
 * Run AFTER executing 011_elenco_doc_dynamic_categories.sql
 *
 * Usage: php core/migrations/011_migrate_data.php
 */

// Standalone DB connection (no bootstrap needed)
$envPath = __DIR__ . '/../../config/.env';
if (!file_exists($envPath)) {
    exit("Missing config/.env\n");
}
$envLines = file($envPath, FILE_IGNORE_NEW_LINES);
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) continue;
    $parts = explode('=', $line, 2);
    $key = trim($parts[0]);
    $value = isset($parts[1]) ? trim(trim($parts[1]), '"\'') : '';
    if ($key !== '') putenv($key . '=' . $value);
}

$dsn = 'mysql:host=' . getenv('DB_SERVER') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== Migration 011: Dynamic Categories Data Migration ===\n\n";

// ─────────────────────────────────────────────────────────────
// 1. Migrate templates: fasi/zone/discipline/tipi_documento → categories
// ─────────────────────────────────────────────────────────────
echo "Step 1: Migrating templates...\n";

$stmt = $pdo->query("SELECT id, fasi, zone, discipline, tipi_documento, categories FROM elenco_doc_commessa");
$templates = $stmt->fetchAll();

$migratedTpl = 0;
foreach ($templates as $tpl) {
    // Skip if already migrated
    if (!empty($tpl['categories'])) {
        echo "  Template #{$tpl['id']}: already migrated, skipping\n";
        continue;
    }

    $fasi = json_decode($tpl['fasi'] ?? '[]', true) ?: [];
    $zone = json_decode($tpl['zone'] ?? '[]', true) ?: [];
    $discipline = json_decode($tpl['discipline'] ?? '[]', true) ?: [];
    $tipiDoc = json_decode($tpl['tipi_documento'] ?? '[]', true) ?: [];

    $categories = [];

    if (!empty($fasi)) {
        $categories[] = [
            'key' => 'fase',
            'label' => 'Fase',
            'items' => array_map(function ($f) {
                return is_string($f) ? ['cod' => $f, 'desc' => $f] : ['cod' => $f['cod'] ?? $f, 'desc' => $f['desc'] ?? $f];
            }, $fasi)
        ];
    }

    if (!empty($zone)) {
        $categories[] = [
            'key' => 'zona',
            'label' => 'Zona',
            'items' => array_map(function ($z) {
                return is_string($z) ? ['cod' => $z, 'desc' => $z] : ['cod' => $z['cod'] ?? $z, 'desc' => $z['desc'] ?? $z];
            }, $zone)
        ];
    }

    if (!empty($discipline)) {
        $categories[] = [
            'key' => 'disc',
            'label' => 'Disciplina',
            'items' => array_map(function ($d) {
                return is_string($d) ? ['cod' => $d, 'desc' => $d] : ['cod' => $d['cod'] ?? $d, 'desc' => $d['desc'] ?? $d];
            }, $discipline)
        ];
    }

    if (!empty($tipiDoc)) {
        $categories[] = [
            'key' => 'tipo',
            'label' => 'Tipo',
            'items' => array_map(function ($t) {
                return ['cod' => $t['cod'] ?? '', 'desc' => $t['desc'] ?? ''];
            }, $tipiDoc)
        ];
    }

    $categoriesJson = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $upd = $pdo->prepare("UPDATE elenco_doc_commessa SET categories = ? WHERE id = ?");
    $upd->execute([$categoriesJson, $tpl['id']]);

    $migratedTpl++;
    echo "  Template #{$tpl['id']}: migrated (" . count($categories) . " categories)\n";
}

echo "  Done: {$migratedTpl} templates migrated.\n\n";

// ─────────────────────────────────────────────────────────────
// 2. Migrate documents: seg_fase/zona/disc/tipo → segments
// ─────────────────────────────────────────────────────────────
echo "Step 2: Migrating documents...\n";

$stmt = $pdo->query("SELECT id, seg_fase, seg_zona, seg_disc, seg_tipo, segments FROM elenco_doc_documents WHERE segments IS NULL");
$docs = $stmt->fetchAll();

$migratedDocs = 0;
$upd = $pdo->prepare("UPDATE elenco_doc_documents SET segments = ? WHERE id = ?");
foreach ($docs as $doc) {
    $segments = [
        'fase' => $doc['seg_fase'] ?? '',
        'zona' => $doc['seg_zona'] ?? '',
        'disc' => $doc['seg_disc'] ?? '',
        'tipo' => $doc['seg_tipo'] ?? '',
    ];

    $segmentsJson = json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $upd->execute([$segmentsJson, $doc['id']]);
    $migratedDocs++;
}

echo "  Done: {$migratedDocs} documents migrated.\n\n";

echo "=== Migration complete! ===\n";
