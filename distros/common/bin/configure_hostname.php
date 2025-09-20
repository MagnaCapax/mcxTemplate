#!/usr/bin/env php
<?php
declare(strict_types=1);

use Distros\Common\Common;

require __DIR__ . '/../lib/Common.php';

// Configure the system hostname using templated environment variables.
Common::ensureRoot();
$desiredHostname = Common::requireEnv('MCX_HOSTNAME');
$prettyHostname = trim((string) (getenv('MCX_PRETTY_HOSTNAME') ?: ''));

// Validate the provided hostname so that bad values do not reach the OS.
if (!Common::isValidHostname($desiredHostname)) {
    Common::fail("'{$desiredHostname}' is not a valid hostname.");
}

// Keep the flow easy to read by splitting retrieval into a helper.
$currentHostname = getCurrentHostname();
if ($currentHostname === $desiredHostname) {
    Common::logInfo("Hostname already set to '{$desiredHostname}'.");
    if ($prettyHostname !== '') {
        Common::logInfo('Ensuring pretty hostname is applied for completeness.');
        applyHostname($desiredHostname, $prettyHostname);
    }
    exit(0);
}

// Apply the desired hostname while reporting what changed.
Common::logInfo("Updating hostname from '" . ($currentHostname !== '' ? $currentHostname : 'unknown') . "' to '{$desiredHostname}'.");
applyHostname($desiredHostname, $prettyHostname);
Common::logInfo('Hostname successfully updated.');

// Read the current hostname using the best available tool.
function getCurrentHostname(): string
{
    if (Common::commandExists('hostnamectl')) {
        $status = 0;
        $staticName = Common::runCommand(['hostnamectl', '--static'], $status);
        if ($status === 0 && $staticName !== '') {
            return $staticName;
        }
    }

    // Fallback to the traditional hostname command when necessary.
    $status = 0;
    $legacyName = Common::runCommand(['hostname'], $status);
    if ($status === 0) {
        return $legacyName;
    }

    // Return an empty string so the caller can handle unknown cases.
    return '';
}

// Apply static and pretty hostnames with graceful fallbacks.
function applyHostname(string $staticName, string $prettyName): void
{
    if (Common::commandExists('hostnamectl')) {
        $status = 0;
        Common::runCommand(['hostnamectl', 'set-hostname', $staticName], $status);
        if ($status !== 0) {
            Common::fail('Failed to set static hostname via hostnamectl.');
        }

        // Apply the pretty hostname when one is provided.
        if ($prettyName !== '') {
            Common::runCommand(['hostnamectl', 'set-hostname', $prettyName, '--pretty'], $status);
            if ($status !== 0) {
                Common::fail('Failed to set pretty hostname via hostnamectl.');
            }
        }
        return;
    }

    // Legacy systems only have the hostname command available.
    if (!Common::commandExists('hostname')) {
        Common::fail("Required command 'hostname' not found in PATH.");
    }

    $status = 0;
    Common::runCommand(['hostname', $staticName], $status);
    if ($status !== 0) {
        Common::fail('Failed to set hostname via the legacy hostname command.');
    }

    // Update /etc/hostname when we have write access so reboots persist.
    if (is_writable('/etc/hostname') || (!file_exists('/etc/hostname') && is_writable('/etc'))) {
        if (@file_put_contents('/etc/hostname', $staticName . PHP_EOL) === false) {
            Common::logInfo('Unable to update /etc/hostname; continuing.');
        }
    } else {
        Common::logInfo('/etc/hostname not writable; skipping file update.');
    }

    // Pretty hostnames are unsupported without hostnamectl but we inform the user.
    if ($prettyName !== '') {
        Common::logInfo('Pretty hostname requested but hostnamectl is unavailable.');
    }
}
