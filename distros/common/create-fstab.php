#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// write_fstab.php - Common /etc/fstab writer for mcxTemplate provisioning.
// Called from distro post-install tasks once partitions are ready to mount.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// The script uses strict types so accidental nulls trigger visible failures.

use Distros\Common\Common;

require __DIR__ . '/lib/Common.php';

Common::ensureRoot();

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

$mountSpecJson = getenv('MCX_MOUNT_SPEC');
$mountEntries = [];
if ($mountSpecJson !== false && trim((string) $mountSpecJson) !== '') {
    $decoded = json_decode($mountSpecJson, true);
    if (is_array($decoded)) {
        $mountEntries = $decoded;
    } else {
        Common::logWarn('Unable to decode MCX_MOUNT_SPEC; falling back to legacy defaults.');
    }
}

if ($mountEntries === []) {
    $rootDevice = envOrDefault('ROOT_DEVICE', '/dev/nvme0n1p2');
    $homeRaw = getenv('HOME_DEVICE');
    $homeDevice = $homeRaw === false ? null : trim($homeRaw);
    $bootDevice = envOrDefault('BOOT_DEVICE', '/dev/md1');
    $swapDevice = envOrDefault('SWAP_DEVICE', '/dev/nvme0n1p1');

    if ($rootDevice !== '') {
        $mountEntries[] = [
            'mount' => '/',
            'original_mount' => '/',
            'device' => $rootDevice,
            'type' => 'ext4',
            'options' => 'errors=remount-ro',
            'dump' => 0,
            'pass' => 1,
            'is_swap' => false,
        ];
    }

    if ($homeDevice !== null && $homeDevice !== '') {
        $mountEntries[] = [
            'mount' => '/home',
            'original_mount' => '/home',
            'device' => $homeDevice,
            'type' => 'ext4',
            'options' => 'defaults',
            'dump' => 0,
            'pass' => 2,
            'is_swap' => false,
        ];
    }

    if ($bootDevice !== '') {
        $mountEntries[] = [
            'mount' => '/boot',
            'original_mount' => '/boot',
            'device' => $bootDevice,
            'type' => 'ext4',
            'options' => 'defaults',
            'dump' => 0,
            'pass' => 2,
            'is_swap' => false,
        ];
    }

    if ($swapDevice !== '') {
        $mountEntries[] = [
            'mount' => 'none',
            'original_mount' => 'swap',
            'device' => $swapDevice,
            'type' => 'swap',
            'options' => 'sw',
            'dump' => 0,
            'pass' => 0,
            'is_swap' => true,
        ];
    }
}

if ($mountEntries === []) {
    Common::logError('Unable to determine mount specification; /etc/fstab not updated.');
    return;
}

$lines = [
    '# /etc/fstab: static file system information.',
    '#',
    "# Use 'blkid' to print the universally unique identifier for a device.",
    '# <file system> <mount point>   <type>  <options>       <dump>  <pass>',
];

foreach ($mountEntries as $entry) {
    if (($entry['is_swap'] ?? false) === true) {
        $type = $entry['type'] ?? 'swap';
        $options = $entry['options'] ?? 'sw';
        $lines[] = sprintf('%s none            %s    %s              %d       %d', $entry['device'], $type, $options, $entry['dump'] ?? 0, $entry['pass'] ?? 0);
        continue;
    }

    $mount = $entry['mount'] ?? '/';
    $type = $entry['type'] ?? 'ext4';
    $options = $entry['options'] ?? ($mount === '/' ? 'errors=remount-ro' : 'defaults');
    $dump = $entry['dump'] ?? 0;
    $pass = $entry['pass'] ?? ($mount === '/' ? 1 : 2);

    $lines[] = sprintf('%s %-15s %s    %-14s %d       %d', $entry['device'], $mount, $type, $options, $dump, $pass);
}

$payload = implode(PHP_EOL, $lines) . PHP_EOL;

if (@file_put_contents('/etc/fstab', $payload) === false) {
    Common::logWarn('Unable to write /etc/fstab; leaving existing file intact.');
    return;
}

Common::logInfo('Updated /etc/fstab with current mount specification.', [
    'entries' => array_map(static function (array $entry): array {
        return [
            'mount' => $entry['original_mount'] ?? $entry['mount'] ?? '',
            'device' => $entry['device'] ?? '',
            'type' => $entry['type'] ?? '',
        ];
    }, $mountEntries),
]);
