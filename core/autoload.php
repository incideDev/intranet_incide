<?php
spl_autoload_register(function ($class) {
    if (strpos($class, 'Services\\') === 0) {
        // Gestisce Services\AIextraction\ClassName -> services/AIextraction/ClassName.php
        if (strpos($class, 'Services\\AIextraction\\') === 0) {
            $relativeClass = str_replace('Services\\AIextraction\\', '', $class);
            $file = ROOT . '/services/AIextraction/' . $relativeClass . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        
        // Cerca direttamente in services/ — converte backslash in / per sub-namespace (es. Nextcloud\NextcloudService)
        $relativeClass = str_replace('Services\\', '', $class);
        $relativeClass = str_replace('\\', '/', $relativeClass);
        $file = ROOT . '/services/' . $relativeClass . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
        
        // Fallback: cerca ricorsivamente nelle sottocartelle di services/
        // Es: Services\StorageManager -> services/AIextraction/StorageManager.php
        $servicesDir = ROOT . '/services';
        if (is_dir($servicesDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($servicesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getFilename() === $relativeClass . '.php') {
                    require_once $fileInfo->getPathname();
                    return;
                }
            }
        }
        
        error_log("Autoload Services: classe $class non trovata (cercato: $file e nelle sottocartelle di services/)");
    }
});
