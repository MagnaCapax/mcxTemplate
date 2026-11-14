#!/usr/bin/env php
<?php
declare(strict_types=1);

// Minimal local runner for mcxTemplate (CI runs it as `bash tools/run-tests.sh`, which execs this file):
//   1. `php -l` on every PHP file in the repository (vendor/ and node_modules/ at the root are skipped);
//   2. PHPUnit with tests/phpunit.xml.dist when it can be found, otherwise a skip line and exit 0.
// Exit code: 0 when the syntax check passed and PHPUnit passed or was absent; otherwise the exit
// code of the first failing `php -l` or of PHPUnit. Read the output: a missing PHPUnit is not a failure here.

$root = dirname(__DIR__);

/**
 * phpFiles lists every *.php file under $root, relative to it, sorted for a stable order.
 * vendor/ and node_modules/ are skipped at the repository root only, and so is .git.
 *
 * @return list<string>
 */
function phpFiles(string $root): array
{
    $skipTop = ['vendor', 'node_modules', '.git'];
    $found = [];
    $walk = static function (string $dir, string $relative) use (&$walk, &$found, $skipTop): void {
        $names = scandir($dir);
        if ($names === false) {
            return;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $rel = $relative === '' ? $name : $relative . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                // The skip list applies to the top level only, like the old `-path './vendor'` rule.
                if ($relative === '' && in_array($name, $skipTop, true)) {
                    continue;
                }
                $walk($path, $rel);
            } elseif (is_file($path) && str_ends_with($name, '.php')) {
                $found[] = $rel;
            }
        }
    };
    $walk($root, '');
    sort($found);
    return $found;
}

/**
 * phpUnitCommand returns the PHPUnit command to run, in the order the shell runner used:
 * vendor/bin/phpunit, phpunit on PATH, then phpunit-9.phar in the repository root. null when none exists.
 */
function phpUnitCommand(string $root): ?string
{
    $config = escapeshellarg($root . '/tests/phpunit.xml.dist');
    if (is_executable($root . '/vendor/bin/phpunit')) {
        return escapeshellarg($root . '/vendor/bin/phpunit') . ' --configuration ' . $config;
    }
    exec('command -v phpunit >/dev/null 2>&1', $ignored, $onPath);
    if ($onPath === 0) {
        return 'phpunit --configuration ' . $config;
    }
    if (is_file($root . '/phpunit-9.phar')) {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/phpunit-9.phar') . ' --configuration ' . $config;
    }
    return null;
}

echo "[+] PHP syntax check\n";
$files = phpFiles($root);
foreach ($files as $file) {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        // Show why it failed (the shell runner let php's own error text through) and stop with php's exit code.
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit($code);
    }
}
echo '[+] PHP syntax OK (' . count($files) . " files)\n";

echo "[+] PHPUnit (if available)\n";
$command = phpUnitCommand($root);
if ($command === null) {
    echo "[!] PHPUnit not found; skipping unit tests.\n";
} else {
    // passthru keeps PHPUnit's output unbuffered on our stdout; its exit code is ours.
    passthru($command, $code);
    if ($code !== 0) {
        exit($code);
    }
}

echo "[+] Done\n";
exit(0);
