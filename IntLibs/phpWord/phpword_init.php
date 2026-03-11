<?php 
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/IntLibs')) . '/page-errors/404.php');
    die();
}

// Includi manualmente le classi necessarie (ordine importante: dipendenze prima)
// Interfacce e classi base prima delle implementazioni
if (!interface_exists('PhpOffice\PhpWord\Escaper\EscaperInterface', false)) {
    require_once __DIR__ . '/Escaper/EscaperInterface.php';
}
if (!class_exists('PhpOffice\PhpWord\Exception\Exception', false)) {
    require_once __DIR__ . '/Exception/Exception.php';
    require_once __DIR__ . '/Exception/CopyFileException.php';
    require_once __DIR__ . '/Exception/CreateTemporaryFileException.php';
}
if (!class_exists('PhpOffice\PhpWord\Shared\Text', false)) {
    require_once __DIR__ . '/Shared/Text.php';
    require_once __DIR__ . '/Shared/ZipArchive.php';
    require_once __DIR__ . '/Shared/XMLWriter.php';
}
if (!class_exists('PhpOffice\PhpWord\Escaper\AbstractEscaper', false)) {
    require_once __DIR__ . '/Escaper/AbstractEscaper.php';
    require_once __DIR__ . '/Escaper/RegExp.php';
    require_once __DIR__ . '/Escaper/Xml.php';
}
if (!class_exists('PhpOffice\PhpWord\PhpWord', false)) {
    require_once __DIR__ . '/PhpWord.php';
    require_once __DIR__ . '/Settings.php';
}
if (!class_exists('PhpOffice\PhpWord\TemplateProcessor', false)) {
    require_once __DIR__ . '/TemplateProcessor.php';
}

// Autoload per eventuali classi future
spl_autoload_register(function ($class) {
    if (strpos($class, 'PhpOffice\\PhpWord\\') === 0) {
        $path = str_replace('\\', '/', $class);
        $file = __DIR__ . '/' . $path . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
