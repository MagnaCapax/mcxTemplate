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

$payload = rtrim($output) . PHP_EOL;
if (@file_put_contents('/etc/mdadm/mdadm.conf', $payload) === false) {
    Common::logWarn('Unable to write /etc/mdadm/mdadm.conf.');
    exit(0);
}

Common::logInfo('Task 32 complete: mdadm metadata recorded.');
