<?php
declare(strict_types=1);

// Discover the repository root so we can reference project files easily.
$projectRoot = dirname(__DIR__);

// Load Composer's autoloader when dependencies have been installed locally.
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

// Provide a lightweight autoloader that covers the shared PHP namespaces.
spl_autoload_register(
    static function (string $class) use ($projectRoot): void {
        // Map namespace prefixes to their associated directories inside the repo.
        $prefixes = [
            'Lib\\Common\\' => $projectRoot . '/src/Lib/Common/',
            'Common\\Lib\\' => $projectRoot . '/src/Common/Lib/',
            'Distros\\Common\\' => $projectRoot . '/distros/common/lib/',
        ];

        // Walk each mapping until we find a matching namespace prefix.
        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue; // Skip non-matching prefixes quickly.
            }

            // Translate the remaining namespace into a filesystem relative path.
            $relativeClass = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            // Include the file when present; missing files fall through silently.
            if (is_file($path)) {
                require_once $path;
            }

            return; // Stop after handling the first matching prefix.
        }
    }
);
