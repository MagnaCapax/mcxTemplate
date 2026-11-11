<?php
declare(strict_types=1);

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// Lightweight autoloader for the Lib namespace (src/Lib) and the shared test helpers (Tests\Unit -> tests/unit).
// PHPUnit only loads files named *Test.php, so a helper trait such as Tests\Unit\Tools\ToolTestHelpers is found
// only through this map; without it a test using the trait fails with "Trait not found".
spl_autoload_register(static function (string $class): void {
    $map = [
        'Lib\\' => __DIR__ . '/../src/Lib/',
        'Tests\\Unit\\' => __DIR__ . '/unit/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }
});
