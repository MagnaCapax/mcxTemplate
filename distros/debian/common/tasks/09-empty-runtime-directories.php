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
Common::logInfo('Task 09: emptying runtime directories.');

$directories = [
    '/tmp',
    '/var/tmp',
    '/var/log/journal',
    '/var/lib/dhcp',
    '/var/lib/NetworkManager',
    '/var/cache/systemd',
];

foreach ($directories as $directory) {
    Common::emptyDirectory($directory);
}

Common::logInfo('Task 09 complete: runtime directories emptied.');
