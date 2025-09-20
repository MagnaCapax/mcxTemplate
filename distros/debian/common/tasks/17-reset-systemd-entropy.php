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
Common::logInfo('Task 17: resetting systemd entropy files.');

$paths = [
    '/var/lib/systemd/random-seed',
    '/var/lib/systemd/credential.secret',
];

foreach ($paths as $path) {
    if (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

Common::logInfo('Task 17 complete: entropy artefacts removed.');
