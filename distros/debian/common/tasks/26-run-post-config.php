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
Common::logInfo('Task 26: running post-configuration script.');

$uri = trim((string) (getenv('MCX_POST_CONFIG_URI') ?: ''));
if ($uri === '') {
    Common::logInfo('No post-config URI provided; skipping.');
    return;
}

$scriptPath = '/tmp/mcx-post-config-' . uniqid();
$data = @file_get_contents($uri);
if ($data === false) {
    Common::logWarn('Failed to download post-config script from ' . $uri . '.');
    return;
}

if (@file_put_contents($scriptPath, $data) === false) {
    Common::logWarn('Unable to write downloaded post-config script.');
    return;
}

@chmod($scriptPath, 0700);

$descriptorSpec = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
];
$process = @proc_open($scriptPath, $descriptorSpec, $pipes, dirname($scriptPath));
if (!is_resource($process)) {
    @unlink($scriptPath);
    Common::logWarn('Failed to launch post-config script.');
    return;
}
$status = proc_close($process);
@unlink($scriptPath);

if ($status !== 0) {
    Common::logWarn('Post-config script exited with status ' . (string) $status . '.');
    return;
}

Common::logInfo('Task 26 complete: post-config script executed.');
