<?php
// FILE: services/ElencoDocumentiPdfService.php
// Genera il PDF della lettera di trasmissione per un submittal.
// Usa DomPDF (installato via Composer in vendor/).

namespace Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ElencoDocumentiPdfService
{
    /**
     * Genera il PDF della lettera di trasmissione e lo invia come download.
     * Chiamato direttamente da service_router — esce con output binario.
     *
     * @param array $input  Deve contenere: submittalId, idProject
     */
    /**
     * Genera il PDF della lettera di trasmissione e lo invia come download.
     * Chiamato direttamente da service_router — esce con output binario.
     */
    public static function streamPdf(array $input): void
    {
        if (!userHasPermission('view_commesse')) {
            http_response_code(403);
            echo 'Permesso negato';
            exit;
        }

        $submittalId = filter_var($input['submittalId'] ?? 0, FILTER_VALIDATE_INT);
        $idProject   = filter_var($input['idProject']   ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$submittalId || !$idProject) {
            http_response_code(400);
            echo 'Parametri mancanti';
            exit;
        }

        $result = self::renderPdf($submittalId, $idProject);
        if (!$result) {
            http_response_code(404);
            echo 'Submittal non trovato';
            exit;
        }

        // Pulisce qualsiasi output precedente
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo $result['bytes'];
        exit;
    }

    /**
     * Genera il PDF e restituisce i bytes (per allegato email).
     * Restituisce null se i parametri non sono validi o il submittal non esiste.
     */
    public static function generatePdfBytes(array $input): ?string
    {
        $submittalId = filter_var($input['submittalId'] ?? 0, FILTER_VALIDATE_INT);
        $idProject   = filter_var($input['idProject']   ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$submittalId || !$idProject) {
            return null;
        }

        $result = self::renderPdf($submittalId, $idProject);
        return $result ? $result['bytes'] : null;
    }

    /**
     * Logica condivisa: carica dati e genera PDF.
     * Restituisce ['bytes' => string, 'filename' => string] o null.
     */
    private static function renderPdf(int $submittalId, string $idProject): ?array
    {
        global $database;

        // ── Carica submittal ──────────────────────────────────────
        $sub = $database->query(
            "SELECT * FROM elenco_doc_submittals WHERE id = ? AND id_project = ? LIMIT 1",
            [$submittalId, $idProject],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$sub) {
            return null;
        }

        // ── Carica documenti del submittal ────────────────────────
        $docs = $database->query(
            "SELECT d.*, s.nome AS section_name
             FROM elenco_doc_documents d
             LEFT JOIN elenco_doc_sections s ON s.id = d.id_section
             WHERE d.id_submittal = ?
             ORDER BY s.ordine, d.seg_numero",
            [$submittalId],
            __FILE__
        )->fetchAll(\PDO::FETCH_ASSOC);

        // ── Carica nome commessa (elenco_commesse) ────────────────
        $commessa = $database->query(
            "SELECT codice, oggetto, cliente FROM elenco_commesse WHERE codice = ? LIMIT 1",
            [$idProject],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        // ── Carica nome destinatario da personale ─────────────────
        $destNome = $sub['destinatario'] ?? '';
        if (is_numeric($destNome)) {
            $row = $database->query(
                "SELECT Nominativo FROM personale WHERE user_id = ? LIMIT 1",
                [(int)$destNome],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);
            if ($row) $destNome = $row['Nominativo'];
        }

        // ── Carica template categories per codice documento ────────
        $tplResult = ElencoDocumentiService::getTemplate($idProject);
        $tplCategories = ($tplResult['success'] && !empty($tplResult['data']['categories']))
            ? $tplResult['data']['categories'] : [];

        // ── Costruisce HTML lettera ───────────────────────────────
        $html = self::buildHtml($sub, $docs, $commessa, $destNome, $tplCategories);

        // ── Genera PDF con DomPDF ─────────────────────────────────
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Trasmissione_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $sub['codice']) . '.pdf';

        return ['bytes' => $dompdf->output(), 'filename' => $filename];
    }

    // ─────────────────────────────────────────────────────────────
    // HTML della lettera
    // ─────────────────────────────────────────────────────────────

    private static function buildHtml(array $sub, array $docs, ?array $commessa, string $destNome, array $categories = []): string
    {
        $codice    = htmlspecialchars($sub['codice']      ?? '', ENT_QUOTES, 'UTF-8');
        $oggetto   = htmlspecialchars($sub['oggetto']     ?? '', ENT_QUOTES, 'UTF-8');
        $scopo     = htmlspecialchars($sub['scopo']       ?? '—', ENT_QUOTES, 'UTF-8');
        $modalita  = htmlspecialchars($sub['modalita']    ?? '—', ENT_QUOTES, 'UTF-8');
        $note      = htmlspecialchars($sub['note']        ?? '', ENT_QUOTES, 'UTF-8');
        $data      = $sub['data_consegna'] ? date('d/m/Y', strtotime($sub['data_consegna'])) : date('d/m/Y');
        $dest      = htmlspecialchars($destNome,           ENT_QUOTES, 'UTF-8');
        $projCode  = htmlspecialchars($commessa['codice']  ?? '', ENT_QUOTES, 'UTF-8');
        $projNome  = htmlspecialchars($commessa['oggetto'] ?? '', ENT_QUOTES, 'UTF-8');
        $cliente   = htmlspecialchars($commessa['cliente'] ?? '', ENT_QUOTES, 'UTF-8');

        $docsCount = count($docs);
        $rows = '';
        foreach ($docs as $i => $d) {
            $num  = $i + 1;
            $code = htmlspecialchars(self::codeStr($d, $categories), ENT_QUOTES, 'UTF-8');
            $titolo = htmlspecialchars($d['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
            $rev    = htmlspecialchars($d['revisione'] ?? '', ENT_QUOTES, 'UTF-8');
            $rows .= "
                <tr>
                    <td class=\"num\">{$num}</td>
                    <td class=\"code\">{$code}</td>
                    <td>{$titolo}</td>
                    <td class=\"rev\">{$rev}</td>
                </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 10pt;
        color: #111;
        padding: 20mm 18mm;
        line-height: 1.4;
    }
    .header-logo {
        font-size: 18pt;
        font-weight: bold;
        color: #4f46e5;
        letter-spacing: 1px;
        margin-bottom: 6px;
    }
    .header-divider {
        border: none;
        border-top: 2px solid #4f46e5;
        margin: 10px 0 20px;
    }
    .doc-title {
        text-align: center;
        font-size: 14pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 4px;
    }
    .doc-code {
        text-align: center;
        font-size: 9pt;
        color: #6b7280;
        margin-bottom: 24px;
    }
    .meta-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    .meta-table td {
        padding: 5px 8px;
        vertical-align: top;
    }
    .meta-table td:first-child {
        font-weight: bold;
        width: 130px;
        color: #374151;
    }
    .meta-table tr:nth-child(odd) td {
        background: #f9fafb;
    }
    .section-title {
        font-size: 9pt;
        font-weight: bold;
        text-transform: uppercase;
        color: #4f46e5;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 4px;
        margin: 20px 0 10px;
        letter-spacing: 0.5px;
    }
    .docs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9pt;
    }
    .docs-table th {
        background: #4f46e5;
        color: #fff;
        padding: 6px 8px;
        text-align: left;
        font-weight: 600;
    }
    .docs-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
    }
    .docs-table tr:nth-child(even) td {
        background: #f9fafb;
    }
    .docs-table td.num  { width: 30px;  text-align: center; color: #6b7280; }
    .docs-table td.code { width: 160px; font-family: Courier New, monospace; font-size: 8pt; }
    .docs-table td.rev  { width: 40px;  text-align: center; font-weight: bold; }
    .note-box {
        margin-top: 20px;
        padding: 10px 12px;
        background: #f9fafb;
        border-left: 3px solid #4f46e5;
        font-size: 9pt;
    }
    .footer {
        margin-top: 40px;
        border-top: 1px solid #e5e7eb;
        padding-top: 12px;
        font-size: 8pt;
        color: #9ca3af;
        text-align: center;
    }
</style>
</head>
<body>

    <div class="header-logo">INCIDE</div>
    <hr class="header-divider">

    <div class="doc-title">Lettera di Trasmissione</div>
    <div class="doc-code">{$codice}</div>

    <div class="section-title">Dati trasmissione</div>
    <table class="meta-table">
        <tr><td>Commessa</td><td>{$projCode} — {$projNome}</td></tr>
        <tr><td>Cliente</td><td>{$cliente}</td></tr>
        <tr><td>Data</td><td>{$data}</td></tr>
        <tr><td>Destinatario</td><td>{$dest}</td></tr>
        <tr><td>Oggetto</td><td>{$oggetto}</td></tr>
        <tr><td>Scopo</td><td>{$scopo}</td></tr>
        <tr><td>Modalità</td><td>{$modalita}</td></tr>
    </table>

    <div class="section-title">Elenco documenti trasmessi ({$docsCount})</div>
    <table class="docs-table">
        <thead>
            <tr>
                <th style="width:30px">#</th>
                <th style="width:160px">Codice documento</th>
                <th>Descrizione</th>
                <th style="width:40px">Rev</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>

    {$note ? "<div class=\"note-box\"><strong>Note:</strong><br>{$note}</div>" : ''}

    <div class="footer">
        Documento generato automaticamente — {$projCode} — {$codice}
    </div>

</body>
</html>
HTML;
    }

    /**
     * Ricostruisce il codice documento dal record DB.
     * Supports dynamic segments from template categories.
     */
    private static function codeStr(array $d, array $categories = []): string
    {
        // If we have dynamic segments and categories, use them
        $segments = !empty($d['segments'])
            ? (is_string($d['segments']) ? (json_decode($d['segments'], true) ?: []) : $d['segments'])
            : [];

        $parts = [];
        foreach ($categories as $cat) {
            $val = $segments[$cat['key']] ?? '';
            if ($val !== '') $parts[] = $val;
        }
        if ($d['seg_numero']) $parts[] = str_pad((string)(int)$d['seg_numero'], 4, '0', STR_PAD_LEFT);
        if (!empty($d['revisione'])) $parts[] = $d['revisione'];
        return implode('-', $parts);
    }
}
