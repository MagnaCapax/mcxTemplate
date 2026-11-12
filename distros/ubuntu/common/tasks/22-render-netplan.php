#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 22 (Ubuntu) - Write /etc/netplan/01-mcx.yaml: a static address, the default
// route and the resolvers, for the interface that carries the default route.
// Replaces the Debian task 22 (ifupdown's /etc/network/interfaces, which Ubuntu
// 24.04 does not read). Inputs come from the environment configure.php exports:
// MCX_NETWORK_CIDR, MCX_GATEWAY, MCX_RESOLVERS (optional), MCX_PRIMARY_INTERFACE (optional).
// Missing address or gateway: skipped with a warning, unless MCX_STRICT=1 (then it fails).
// Invalid input always fails. MCX_TARGET_ROOT is a test seam (a directory standing in for /).
// The file is written and NOT applied: netplan reads it at the next boot.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use Distros\Common\Common;

$scriptDir = __DIR__;
$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
}

require $repoRoot . '/distros/common/lib/Common.php';

Common::ensureRoot();
Common::logInfo('Task 22: rendering netplan configuration.');

$cidr = trim((string) (getenv('MCX_NETWORK_CIDR') ?: ''));
$gateway = trim((string) (getenv('MCX_GATEWAY') ?: ''));
if ($cidr === '' || $gateway === '') {
    if (trim((string) (getenv('MCX_STRICT') ?: '')) === '1') {
        Common::fail('Task 22: address or gateway missing and strict mode is on.');
    }
    Common::logWarn('Task 22: address or gateway missing; no netplan file written.');
    return;
}

$interface = Common::installedPrimaryInterface();
$resolvers = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('MCX_RESOLVERS') ?: '')))));
$valid = preg_match('#^\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}$#D', $cidr) === 1
    && filter_var(explode('/', $cidr)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    && (int) explode('/', $cidr)[1] <= 32
    && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/D', $interface) === 1
    && count($resolvers) <= 3;
foreach ($resolvers as $address) {
    $valid = $valid && filter_var($address, FILTER_VALIDATE_IP) !== false;
}
if (!$valid) {
    Common::fail('Task 22: address, gateway, interface or resolvers are not valid.');
}

$yaml = "network:\n  version: 2\n  ethernets:\n    " . $interface . ":\n      addresses: [" . $cidr . "]\n"
    . "      routes:\n        - to: default\n          via: " . $gateway . "\n";
if ($resolvers !== []) {
    $yaml .= "      nameservers:\n        addresses: [" . implode(', ', $resolvers) . "]\n";
}

$root = rtrim((string) (getenv('MCX_TARGET_ROOT') ?: ''), '/');
$dir = $root . '/etc/netplan';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    Common::fail('Task 22: /etc/netplan could not be created.');
}
$file = $dir . '/01-mcx.yaml';
if (is_link($file) || is_file($file)) {
    @unlink($file);
}
// netplan warns about world-readable files: 0600, set before the content is written.
$old = umask(0077);
$written = @file_put_contents($file, $yaml, LOCK_EX);
umask($old);
if ($written !== strlen($yaml)) {
    Common::fail('Task 22: the netplan file could not be written.');
}

Common::logInfo('Task 22 complete: netplan configuration rendered.', ['interface' => $interface, 'cidr' => $cidr]);
