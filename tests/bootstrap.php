<?php
declare(strict_types=1);

// Locate the project root once so we avoid repeated dirname calls later.
$projectRoot = dirname(__DIR__);

// Load Composer's autoloader when dependencies have been installed locally.
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

// Provide a shared autoloader for namespaces used throughout the suite.
spl_autoload_register(
    static function (string $class) use ($projectRoot): void {
        // Map each namespace prefix to its base directory relative to the repo.
        $prefixes = [
            'Lib\\Common\\' => $projectRoot . '/src/Lib/Common/',
            'Common\\Lib\\' => $projectRoot . '/src/Common/Lib/',
            'Distros\\Common\\' => $projectRoot . '/distros/common/lib/',
        ];

        // Walk each mapping until we find a matching namespace prefix.
        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            // Convert the remainder of the namespace into a filesystem path.
            $relativeClass = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            // Include the file when it exists and quietly return otherwise.
            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
);
