<?php
declare(strict_types=1);

// Suite entry point for runners that expect tests/run.php. Runs the same checks as CI
// (tools/run-tests.sh: syntax check, then PHPUnit 9.6 with tests/phpunit.xml.dist), but
// exits nonzero when PHPUnit is missing instead of skipping the unit tests.

$root = dirname(__DIR__);
$candidates = [
    [$root . '/vendor/bin/phpunit'],
    ['phpunit'],
    [PHP_BINARY, $root . '/phpunit-9.phar'],
    [PHP_BINARY, '/usr/local/lib/phpunit-9.phar'],
];
$runner = null;
foreach ($candidates as $c) {
    $bin = $c[count($c) - 1];
    if ($bin === 'phpunit') {
        exec('command -v phpunit 2>/dev/null', $out, $rc);
        if ($rc === 0) { $runner = $c; break; }
    } elseif (is_file($bin)) {
        $runner = $c;
        break;
    }
}
if ($runner === null) {
    fwrite(STDERR, "PHPUnit not found (vendor/bin/phpunit, phpunit on PATH, phpunit-9.phar); the unit suite did not run\n");
    exit(2);
}

$lint = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/.git/') !== false) {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "syntax error: $p\n"); $lint++; }
}
echo "[+] PHP syntax check: " . ($lint === 0 ? 'OK' : "$lint file(s) failed") . "\n";

$cmd = array_merge($runner, ['--configuration', $root . '/tests/phpunit.xml.dist']);
passthru(implode(' ', array_map('escapeshellarg', $cmd)), $rc);
exit($lint > 0 ? 1 : $rc);
