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
Common::logInfo('Task 30: rendering /etc/fstab.');

$rootDevice = trim((string) (getenv('ROOT_DEVICE') ?: ''));
if ($rootDevice === '') {
    $rootDevice = '/dev/nvme0n1p2';
}
putenv('ROOT_DEVICE=' . $rootDevice);
$_ENV['ROOT_DEVICE'] = $rootDevice;

$homeRaw = getenv('HOME_DEVICE');
$homeDevice = $homeRaw === false ? null : trim((string) $homeRaw);
if ($homeDevice !== null && strcasecmp($homeDevice, 'omit') === 0) {
    $homeDevice = null;
}
if ($homeDevice !== null && $homeDevice !== '') {
    putenv('HOME_DEVICE=' . $homeDevice);
    $_ENV['HOME_DEVICE'] = $homeDevice;
} else {
    putenv('HOME_DEVICE');
    unset($_ENV['HOME_DEVICE']);
}

foreach ([
    'BOOT_DEVICE' => '/dev/md1',
    'SWAP_DEVICE' => '/dev/nvme0n1p1',
] as $key => $default) {
    $raw = getenv($key);
    $value = $raw === false || trim((string) $raw) === '' ? $default : trim((string) $raw);
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$success = Common::runPhpScript($repoRoot . '/distros/common/create-fstab.php', [], false);
if (!$success) {
    Common::logWarn('fstab helper failed; existing /etc/fstab preserved.');
    return;
}

Common::logInfo('Task 30 complete: fstab rendered.');
