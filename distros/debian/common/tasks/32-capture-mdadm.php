#!/usr/bin/env php
<?php
declare(strict_types=1);

use Distros\Common\Common;

$scriptDir = __DIR__;
$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
}

require $repoRoot . '/distros/common/lib/Common.php';

Common::ensureRoot();
Common::logInfo('Task 32: capturing mdadm metadata.');

if (!Common::commandExists('mdadm')) {
    Common::logWarn('mdadm not installed; skipping mdadm.conf generation.');
    exit(0);
}

$output = shell_exec('mdadm --detail --scan 2>/dev/null');
if (!is_string($output) || trim($output) === '') {
    Common::logWarn('mdadm returned no metadata; existing configuration retained.');
    exit(0);
}

// The scan replaces only the ARRAY lines. Other lines already in the file (MAILADDR, PROGRAM, DEVICE, HOMEHOST)
// stay. With neither MAILADDR nor PROGRAM, mdmonitor refuses to start ("No mail address or alert command"), so
// the Debian package default MAILADDR root is added. MCX_TARGET_ROOT is a test seam; chroot runs have none.
$root = rtrim((string) (getenv('MCX_TARGET_ROOT') ?: ''), '/');
$file = $root . '/etc/mdadm/mdadm.conf';
// A line starting with whitespace continues the line above it, so an old ARRAY line goes with its continuations.
$kept = [];
$inArray = false;
foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $line) {
    if (preg_match('/^ARRAY\s/', $line) === 1 || ($inArray && preg_match('/^\s/', $line) === 1)) {
        $inArray = true;
        continue;
    }
    $inArray = false;
    if ($line !== '') {
        $kept[] = $line;
    }
}
if (preg_grep('/^\s*(MAILADDR|PROGRAM)\s/', $kept) === []) {
    $kept[] = 'MAILADDR root';
}
$payload = implode(PHP_EOL, $kept) . PHP_EOL . rtrim($output) . PHP_EOL;
if (@file_put_contents($file, $payload) === false) {
    Common::logWarn('Unable to write /etc/mdadm/mdadm.conf.');
    exit(0);
}

Common::logInfo('Task 32 complete: mdadm metadata recorded.');
