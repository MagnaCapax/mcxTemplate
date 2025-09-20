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
Common::logInfo('Task 12: clearing cloud-init state.');

$paths = [
    '/var/lib/cloud/instance',
    '/var/lib/cloud/instances',
    '/var/lib/cloud/data',
    '/var/lib/cloud/seed',
];

foreach ($paths as $path) {
    if (is_dir($path) && !is_link($path)) {
        Common::emptyDirectory($path);
        @rmdir($path);
        continue;
    }

    @unlink($path);
}

Common::logInfo('Task 12 complete: cloud-init state cleared.');
