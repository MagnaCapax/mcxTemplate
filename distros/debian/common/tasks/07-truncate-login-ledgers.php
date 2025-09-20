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
Common::logInfo('Task 07: truncating Debian login ledgers.');

$files = [
    '/var/log/wtmp',
    '/var/log/btmp',
    '/var/log/lastlog',
];

foreach ($files as $path) {
    if (!file_exists($path)) {
        continue;
    }

    $handle = @fopen($path, 'w');
    if ($handle === false) {
        Common::logWarn('Unable to truncate ' . $path . '.');
        continue;
    }

    fclose($handle);
}

Common::logInfo('Task 07 complete: login ledgers truncated.');
