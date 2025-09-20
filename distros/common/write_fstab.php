#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// write_fstab.php - Common /etc/fstab writer for mcxTemplate provisioning.
// Called from distro post-install tasks once partitions are ready to mount.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// The script uses strict types so accidental nulls trigger visible failures.

$defaults = [
    'ROOT_DEVICE' => '/dev/nvme0n1p2',
    'HOME_DEVICE' => '/dev/nvme0n1p3',
    'BOOT_DEVICE' => '/dev/md1',
    'SWAP_DEVICE' => '/dev/nvme0n1p1',
];
// Defaults mirror the historical clone workflow and can be overridden via env.

$getValue = static function (string $key) use ($defaults): string {
    $raw = getenv($key);
    if ($raw === false) {
        return $defaults[$key];
    }
    $trimmed = trim($raw);
    return $trimmed === '' ? $defaults[$key] : $trimmed;
};
// Fetch configuration values while falling back to predictable defaults.

$lines = [
    '# /etc/fstab: static file system information.',
    '#',
    "# Use 'blkid' to print the universally unique identifier for a device.",
    '# <file system> <mount point>   <type>  <options>       <dump>  <pass>',
    sprintf('%s /               ext4    errors=remount-ro 0       1', $getValue('ROOT_DEVICE')),
    sprintf('%s /home           ext4    defaults        0       2', $getValue('HOME_DEVICE')),
    sprintf('%s /boot           ext4    defaults        0       2', $getValue('BOOT_DEVICE')),
    sprintf('%s none            swap    sw              0       0', $getValue('SWAP_DEVICE')),
];
// Compose the canonical fstab entries in the same order as the legacy scripts.

$payload = implode(PHP_EOL, $lines) . PHP_EOL;
// Ensure the rendered file always ends with a trailing newline for POSIX tools.

if (file_put_contents('/etc/fstab', $payload) === false) {
    fwrite(STDERR, "ERROR: Unable to write /etc/fstab\n");
    exit(1);
}
// Abort loudly when the file cannot be written so the operator notices quickly.
