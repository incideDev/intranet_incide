<?php

use PhpOffice\PhpWord\TemplateProcessor;

// Percorso assoluto al template DOCX
$template_file = dirname(__DIR__, 2) . '/uploads/Modello_Lettera_Incide.docx';

if (!file_exists($template_file)) {
    die("Template Word non trovato.");
}

$templateProcessor = new TemplateProcessor($template_file);

// Sostituisci i segnaposto coi dati POST
$templateProcessor->setValue('destinatario', $_POST['destinatario'] ?? '');
$templateProcessor->setValue('contatto', $_POST['contatto'] ?? '');
$templateProcessor->setValue('data', $_POST['data'] ?? '');
$templateProcessor->setValue('protocollo', $_POST['protocollo'] ?? '');
$templateProcessor->setValue('descrizione', $_POST['descrizione'] ?? '');

// Salva il file temporaneo
$file_name = 'lettera_modificata_' . time() . '.docx';
$temp_path = sys_get_temp_dir() . '/' . $file_name;
$templateProcessor->saveAs($temp_path);

// Scarica il file all'utente
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($temp_path));

flush();
readfile($temp_path);
unlink($temp_path);
exit;
