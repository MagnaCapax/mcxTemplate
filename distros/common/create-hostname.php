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

$hostnameTemplate = Common::findTemplate('hostname.tpl');
if ($hostnameTemplate === null) {
    Common::fail('Hostname template is missing for the active distro.');
}

Common::applyTemplateToFile($hostnameTemplate, '/etc/hostname', [
    '{{FQDN}}' => $fqdn,
]);

Common::logInfo('Updated /etc/hostname.', ['fqdn' => $fqdn, 'template' => $hostnameTemplate]);
// Emit a short note so operators know the hostname changed.

$hostsTemplateOverride = trim((string) (getenv('MCX_HOSTS_TEMPLATE') ?: ''));
$templatePath = null;
$templateSource = 'distro';

if ($hostsTemplateOverride !== '') {
    if (!is_file($hostsTemplateOverride) || !is_readable($hostsTemplateOverride)) {
        Common::logWarn(
            'Unable to read hosts template override; falling back to distro template.',
            ['template' => $hostsTemplateOverride]
        );
    } else {
        $templatePath = $hostsTemplateOverride;
        $templateSource = 'custom';
    }
}

if ($templatePath === null) {
    $templatePath = Common::findTemplate('hosts.tpl');
    if ($templatePath === null) {
        Common::fail('Hosts template is missing for the active distro.');
    }
}

Common::applyTemplateToFile($templatePath, '/etc/hosts', [
    '{{SHORT_HOSTNAME}}' => $shortHostname,
    '{{FQDN}}' => $fqdn,
    '{{HOST_IP}}' => $hostIp,
]);

Common::logInfo(
    $templateSource === 'custom' ? 'Updated /etc/hosts using custom template.' : 'Updated /etc/hosts using distro template.',
    ['template' => $templatePath, 'source' => $templateSource]
);
// Record success to match the verbosity of the original Bash helper.
