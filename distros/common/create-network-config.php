#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// write_interfaces.php - Render /etc/network/interfaces for mcxTemplate.
// Moves the templating out of Bash so we keep the complex bits in PHP.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict types help spot subtle bugs before we touch system files.

use Distros\Common\Common;
// Reuse the shared helper utilities for consistency across scripts.

require __DIR__ . '/lib/Common.php';
// Include the library once so we share behaviour with other helpers.

Common::ensureRoot();
// Guard privileged operations early to fail fast on misuse.

$cidr = trim((string) (getenv('NETWORK_CIDR') ?: ''));
// Fetch the provided CIDR value from the environment.
if ($cidr === '') {
    $cidr = '192.0.2.10/24';
    // Maintain the legacy default when nothing is supplied.
}

$gateway = trim((string) (getenv('GATEWAY') ?: ''));
// Accept an optional gateway while keeping empty strings clean.

$lines = [
    '# This file describes the network interfaces available on your system',
    '# and how to activate them. For more information, see interfaces(5).',
    '',
    'source /etc/network/interfaces.d/*',
    '',
    '# The loopback network interface',
    'auto lo',
    'iface lo inet loopback',
    '',
    '# The primary network interface',
    'auto eth0',
    'iface eth0 inet static',
    '    address ' . $cidr,
];
// Compose the canonical Debian interface configuration for eth0.

if ($gateway !== '') {
    $lines[] = '    gateway ' . $gateway;
    // Append the gateway line only when we actually detected one.
}

$content = implode(PHP_EOL, $lines) . PHP_EOL;
// Join everything together and keep the trailing newline intact.

if (@file_put_contents('/etc/network/interfaces', $content) === false) {
    Common::fail('Unable to write /etc/network/interfaces.');
    // Abort loudly so provisioning stops on partial network configs.
}

Common::logInfo('Updated /etc/network/interfaces via PHP helper.');
// Provide a friendly confirmation for the provisioning logs.
