<?php
declare(strict_types=1);

// Register an autoloader so PHPUnit can resolve shared library classes.
spl_autoload_register(static function (string $class): void {
    // We only serve classes from the Lib\Common namespace here.
    $prefix = 'Lib\\Common\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    // Build the file path relative to the repository structure.
    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/../lib/common/' . str_replace('\\', '/', $relativeClass) . '.php';

    // Include the file when it exists to keep the autoloader quiet otherwise.
    if (is_file($path)) {
        require_once $path;
    }
});
