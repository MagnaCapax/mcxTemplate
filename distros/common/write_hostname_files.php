#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// write_hostname_files.php - Maintain hostname and hosts files for templates.
// Converts the Bash templating logic into PHP for easier maintenance.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Stick with strict types to catch parameter mistakes early.

use Distros\Common\Common;
// Import shared helpers so logging and failures match the other scripts.

require __DIR__ . '/lib/Common.php';
// Load the helper definitions exactly once from the shared library.

Common::ensureRoot();
// Refuse to continue when the caller lacks the necessary privileges.

$shortHostname = trim((string) (getenv('SHORT_HOSTNAME') ?: ''));
// Capture the short hostname that callers detected from /proc/cmdline.
if ($shortHostname === '') {
    Common::fail("Environment variable 'SHORT_HOSTNAME' must be provided.");
    // Fail early because the rest of the script depends on this value.
}

$fqdn = trim((string) (getenv('FQDN') ?: ''));
// Pull the fully-qualified name so /etc/hostname mirrors the legacy flow.
if ($fqdn === '') {
    Common::fail("Environment variable 'FQDN' must be provided.");
    // Keep the behaviour strict to avoid writing broken hostnames.
}

$hostIp = trim((string) (getenv('HOST_IP') ?: ''));
// Allow overriding the IPv4 address while keeping historical defaults.
if ($hostIp === '') {
    $hostIp = '127.0.1.1';
    // Fallback matches the previous Bash implementation when IP was empty.
}

if (@file_put_contents('/etc/hostname', $fqdn . PHP_EOL) === false) {
    Common::fail('Unable to write /etc/hostname.');
    // Stop immediately when we cannot update the hostname file on disk.
}

Common::logInfo('Updated /etc/hostname with ' . $fqdn . '.');
// Emit a short note so operators know the hostname changed.

$hostsLines = [
    '127.0.0.1       localhost',
    $hostIp . '    ' . $shortHostname . ' ' . $fqdn,
    '',
    '# The following lines are desirable for IPv6 capable hosts',
    '::1     localhost ip6-localhost ip6-loopback',
    'ff02::1 ip6-allnodes',
    'ff02::2 ip6-allrouters',
];
// Mirror the canonical /etc/hosts layout maintained for Debian images.

$hostsContent = implode(PHP_EOL, $hostsLines) . PHP_EOL;
// Build the final file content, preserving the trailing newline for POSIX.

if (@file_put_contents('/etc/hosts', $hostsContent) === false) {
    Common::fail('Unable to write /etc/hosts.');
    // Halt provisioning so the system does not boot with a broken hosts file.
}

Common::logInfo('Updated /etc/hosts with IPv4 and IPv6 defaults.');
// Record success to match the verbosity of the original Bash helper.
