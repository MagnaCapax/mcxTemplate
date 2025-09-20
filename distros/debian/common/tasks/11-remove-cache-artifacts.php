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
Common::logInfo('Task 11: removing cache artefacts.');

$patterns = [
    '/var/cache/apt/archives/*.deb',
    '/var/cache/apt/archives/*.deb.*',
    '/var/cache/apt/*.bin',
    '/var/cache/man/cat*/*',
];

foreach ($patterns as $pattern) {
    $matches = glob($pattern, GLOB_NOSORT | GLOB_BRACE) ?: [];
    foreach ($matches as $path) {
        if (is_dir($path) && !is_link($path)) {
            Common::emptyDirectory($path);
            @rmdir($path);
            continue;
        }
        @unlink($path);
    }
}

Common::logInfo('Task 11 complete: cache artefacts removed.');
