<?php
if (!defined('HostDbDataConnector')) { header("HTTP/1.0 403 Forbidden"); exit; }
?>
<div class="main-container commessa-documenti">
<?php renderPageTitle("Elenco Documenti Commessa", "#cccccc"); ?>

    <div class="commessa-docs-actions" style="margin-bottom:16px;">
        <button class="button">Carica Documento</button>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th>Nome Documento</th>
                <th>Tipo</th>
                <th>Caricato da</th>
                <th>Data caricamento</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="azioni-colonna">
                    <button class="button button-sm">Scarica</button>
                    <button class="button button-sm button-danger">Elimina</button>
                </td>
                <td>Relazione tecnica.pdf</td>
                <td>PDF</td>
                <td>Mario Rossi</td>
                <td>2024-06-01</td>
            </tr>
            <tr>
                <td class="azioni-colonna">
                    <button class="button button-sm">Scarica</button>
                    <button class="button button-sm button-danger">Elimina</button>
                </td>
                <td>Capitolato.docx</td>
                <td>Word</td>
                <td>Laura Bianchi</td>
                <td>2024-06-03</td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:16px;color:#aaa;">
        (In futuro: upload multipli, anteprime, filtri, drag&drop…)
    </div>
</div>
