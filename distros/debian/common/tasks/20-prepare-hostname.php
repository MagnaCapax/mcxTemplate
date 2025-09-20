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
Common::logInfo('Task 20: preparing hostname context.');

function fallbackHostname(): array
{
    $candidate = (string) gethostname();
    if ($candidate === '') {
        Common::logWarn('System hostname unavailable; defaulting to mcx-host.');
        $candidate = 'mcx-host';
    }
    $candidate = trim($candidate);
    $short = trim(explode('.', $candidate)[0] ?? $candidate);
    return [$short, $candidate];
}

$short = trim((string) (getenv('MCX_SHORT_HOSTNAME') ?: ''));
$fqdn = trim((string) (getenv('MCX_FQDN') ?: ''));

if ($short === '' || $fqdn === '') {
    [$autoShort, $autoFqdn] = fallbackHostname();
    if ($short === '') {
        $short = $autoShort;
    }
    if ($fqdn === '') {
        $fqdn = $autoFqdn;
    }
}

if ($fqdn === '') {
    $fqdn = $short;
}

if ($short === '' || !Common::isValidHostname($short)) {
    Common::logWarn('Invalid short hostname detected; using mcx-host.');
    $short = 'mcx-host';
    if ($fqdn === '' || !Common::isValidHostname($fqdn)) {
        $fqdn = 'mcx-host';
    }
}

$hostIp = trim((string) (getenv('MCX_HOST_IP') ?: ''));
if ($hostIp === '') {
    $hostIp = '127.0.1.1';
}

$exports = [
    'MCX_SHORT_HOSTNAME' => $short,
    'MCX_FQDN' => $fqdn,
    'MCX_HOST_IP' => $hostIp,
    'SHORT_HOSTNAME' => $short,
    'FQDN' => $fqdn,
    'HOST_IP' => $hostIp,
];

foreach ($exports as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

Common::logInfo('Task 20 complete: hostname context prepared.');
