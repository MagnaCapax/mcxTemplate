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
Common::logInfo('Task 05: clearing Debian log files.');

$patterns = [
    '/var/log/*.log',
    '/var/log/*.old',
    '/var/log/*.gz',
    '/var/log/*/*.log',
    '/var/log/*/*.old',
    '/var/log/*/*.gz',
];

function removeByPattern(string $pattern): void
{
    $matches = glob($pattern, GLOB_NOSORT | GLOB_BRACE) ?: [];
    foreach ($matches as $path) {
        if (is_dir($path) && !is_link($path)) {
            emptyDirectory($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function emptyDirectory(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }

    $handle = @opendir($directory);
    if ($handle === false) {
        return;
    }

    while (false !== ($entry = readdir($handle))) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            emptyDirectory($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    closedir($handle);
}

foreach ($patterns as $pattern) {
    removeByPattern($pattern);
}

Common::logInfo('Task 05 complete: log files removed.');
