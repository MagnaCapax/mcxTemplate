#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// write_fstab.php - Common /etc/fstab writer for mcxTemplate provisioning.
// Called from distro post-install tasks once partitions are ready to mount.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// The script uses strict types so accidental nulls trigger visible failures.

function envOrDefault(string $key, string $default): string
{
    $raw = getenv($key);
    if ($raw === false) {
        return $default;
    }
    $trimmed = trim($raw);
    return $trimmed === '' ? $default : $trimmed;
}
// Fetch configuration values while falling back to predictable defaults.

$rootDevice = envOrDefault('ROOT_DEVICE', '/dev/nvme0n1p2');

$homeRaw = getenv('HOME_DEVICE');
$homeDevice = $homeRaw === false ? null : trim($homeRaw);
if ($homeDevice === '') {
    $homeDevice = null;
}

$bootDevice = envOrDefault('BOOT_DEVICE', '/dev/md1');
$swapDevice = envOrDefault('SWAP_DEVICE', '/dev/nvme0n1p1');

$lines = [
    '# /etc/fstab: static file system information.',
    '#',
    "# Use 'blkid' to print the universally unique identifier for a device.",
    '# <file system> <mount point>   <type>  <options>       <dump>  <pass>',
    sprintf('%s /               ext4    errors=remount-ro 0       1', $rootDevice),
];

if ($homeDevice !== null) {
    $lines[] = sprintf('%s /home           ext4    defaults        0       2', $homeDevice);
}

$lines[] = sprintf('%s /boot           ext4    defaults        0       2', $bootDevice);
$lines[] = sprintf('%s none            swap    sw              0       0', $swapDevice);
// Compose the canonical fstab entries in the same order as the legacy scripts.

$payload = implode(PHP_EOL, $lines) . PHP_EOL;
// Ensure the rendered file always ends with a trailing newline for POSIX tools.

if (file_put_contents('/etc/fstab', $payload) === false) {
    fwrite(STDERR, "WARN: Unable to write /etc/fstab; leaving existing file intact\n");
    return;
}
// Warn and continue so downstream tasks can proceed even if fstab write fails.
