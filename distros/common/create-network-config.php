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

$interface = trim((string) (getenv('NETWORK_INTERFACE') ?: ''));
if ($interface === '') {
    $interface = Common::detectPrimaryInterface();
}

$templatePath = Common::findTemplate('network/interfaces.tpl');
if ($templatePath === null) {
    Common::fail('Network interface template is missing for the active distro.');
}

$replacements = [
    '{{INTERFACE}}' => $interface,
    '{{CIDR}}' => $cidr,
    '{{GATEWAY_LINE}}' => $gateway !== '' ? '    gateway ' . $gateway : '',
];

Common::applyTemplateToFile($templatePath, '/etc/network/interfaces', $replacements);

Common::logInfo('Updated /etc/network/interfaces via template.', [
    'cidr' => $cidr,
    'gateway' => $gateway,
    'interface' => $interface,
    'template' => $templatePath,
]);
// Provide a friendly confirmation for the provisioning logs.
