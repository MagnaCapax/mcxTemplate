#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 30 - Configure Debian storage metadata such as fstab and mdadm settings.
// Keeps partition mappings and RAID definitions in sync with the template layout.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict typing keeps device mapping lookups predictable.

use Distros\Common\Common;
// Shared helper library keeps logging and command execution consistent.

$scriptDir = __DIR__;
// Record the current directory so fallback path detection works reliably.

$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
    // Derive the repository root manually when environment metadata is missing.
}

require $repoRoot . '/distros/common/lib/Common.php';
// Load shared helpers before executing any of the task steps below.

Common::ensureRoot();
// Bail out early unless we have permission to manipulate system files.

$defaults = [
    'ROOT_DEVICE' => '/dev/nvme0n1p2',
    'HOME_DEVICE' => '/dev/nvme0n1p3',
    'BOOT_DEVICE' => '/dev/md1',
    'SWAP_DEVICE' => '/dev/nvme0n1p1',
];
// Mirror the long-standing NVMe plus mdraid layout used by mcxRescue.

$deviceMap = [];
foreach ($defaults as $key => $value) {
    $raw = getenv($key);
    $trimmed = $raw === false ? '' : trim((string) $raw);
    $deviceMap[$key] = $trimmed !== '' ? $trimmed : $value;
    // Allow overrides while keeping a predictable fallback for each device.
}

Common::logInfo('Task 30: configuring Debian storage metadata.');
// Announce the start of storage configuration to the provisioning logs.

/**
 * Render /etc/fstab using the shared PHP helper and the collected device map.
 */
function writeFstab(string $repoRoot, array $deviceMap): void
{
    $fstabHelper = $repoRoot . '/distros/common/create-fstab.php';
    // Location of the reusable fstab renderer shared across distros.

    foreach ($deviceMap as $name => $value) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        // Export each device mapping so the helper can render the correct paths.
    }

    Common::logInfo('Writing /etc/fstab with Debian device mappings.');
    Common::runPhpScript($fstabHelper);
    // Delegate to the helper while letting the Common wrapper handle failures.
}

/**
 * Capture mdadm metadata when the utility is installed on the target system.
 */
function configureMdadm(): void
{
    if (!Common::commandExists('mdadm')) {
        Common::logWarn('mdadm not installed; skipping mdadm.conf generation.');
        // Mirror the fail-soft behaviour from the historical Bash scripts.
        return;
    }

    Common::logInfo('Recording mdadm array metadata into /etc/mdadm/mdadm.conf.');
    $output = shell_exec('mdadm --detail --scan 2>/dev/null');
    // Capture the mdadm array definitions without letting stderr clutter logs.

    if (!is_string($output) || trim($output) === '') {
        Common::logWarn('mdadm returned no metadata; existing configuration retained.');
        // Leave the existing configuration untouched when mdadm provides nothing.
        return;
    }

    $payload = rtrim($output) . PHP_EOL;
    if (@file_put_contents('/etc/mdadm/mdadm.conf', $payload) === false) {
        Common::logWarn('Unable to write /etc/mdadm/mdadm.conf.');
        // Warn the operator yet continue so other tasks can complete successfully.
    }
}

writeFstab($repoRoot, $deviceMap);
configureMdadm();
// Execute helper routines sequentially to match the legacy provisioning order.

Common::logInfo('Task 30 complete: storage configuration applied.');
// Provide a closing breadcrumb so the logs capture successful completion.
