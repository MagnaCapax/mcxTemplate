#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 10 - Reset identifiers cloned from the golden Debian template.
// Ensures every provisioned node regenerates identity files on first boot.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Stick with strict typing so unexpected values surface quickly during runs.

use Distros\Common\Common;
// Reuse the shared helper library for logging, guards, and command helpers.

$scriptDir = __DIR__;
// Record the task directory so we can derive the repository root below.

$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
    // Fall back to a relative lookup when the environment variable is absent.
}

require $repoRoot . '/distros/common/lib/Common.php';
// Load shared helper definitions one time for every task invocation.

Common::ensureRoot();
// Refuse to continue when the task does not have the expected privileges.

Common::logInfo('Task 10: resetting Debian identifiers.');
// Provide a clear breadcrumb for mcxRescue logs prior to making changes.

/**
 * Remove stale SSH host keys and regenerate them when possible.
 */
function clearSshHostKeys(): void
{
    Common::logInfo('Removing stale SSH host keys from the cloned filesystem.');
    // Inform operators that we are deleting the previous template keys.

    $keys = glob('/etc/ssh/ssh_host_*') ?: [];
    foreach ($keys as $keyPath) {
        @unlink($keyPath);
        // Ignore unlink failures because the files may already be absent.
    }

    if (!Common::runIfCommandExists('ssh-keygen', ['-A'])) {
        Common::logWarn('ssh-keygen unavailable; host keys regenerate on demand.');
        // Warn but continue so provisioning keeps moving forward.
    }
}

/**
 * Reset /etc/machine-id so each clone receives a unique identifier.
 */
function resetMachineIdentifier(): void
{
    Common::logInfo('Resetting machine-id to avoid duplicate identifiers across nodes.');
    // Explain the intent before touching the sensitive identifier file.

    @unlink('/etc/machine-id');
    // Remove the existing machine-id copied from the template image.

    if (!Common::runIfCommandExists('systemd-machine-id-setup')) {
        Common::logWarn('systemd-machine-id-setup missing, writing placeholder machine-id.');
        // Fall back to an empty file so systemd recreates it on next boot.

        if (@file_put_contents('/etc/machine-id', PHP_EOL) === false) {
            Common::fail('Unable to create /etc/machine-id placeholder.');
            // Halt provisioning if we cannot create the critical identifier file.
        }
    }
}

/**
 * Remove stale resume targets that point to now-missing swap devices.
 */
function cleanResumeConfiguration(): void
{
    $resumeFile = '/etc/initramfs-tools/conf.d/resume';
    // Default Debian path that stores hibernation resume device metadata.

    if (!is_file($resumeFile)) {
        Common::logInfo('Resume configuration absent; nothing to change.');
        // Skip quietly when no resume configuration exists in the first place.
        return;
    }

    $content = @file($resumeFile, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        Common::logWarn('Unable to read resume configuration; leaving file untouched.');
        // Continue execution instead of halting because this file is optional.
        return;
    }

    $filtered = [];
    foreach ($content as $line) {
        if (strpos($line, 'RESUME=') !== 0) {
            $filtered[] = $line;
            // Keep unrelated configuration lines to preserve operator edits.
        }
    }

    $payload = implode(PHP_EOL, $filtered);
    // Reassemble the file content while preserving any custom spacing.

    if (trim($payload) === '') {
        Common::logInfo('Deleting empty resume configuration file.');
        @unlink($resumeFile);
        // Remove the file entirely so initramfs no longer references old swap.
        return;
    }

    $payload .= PHP_EOL;
    if (@file_put_contents($resumeFile, $payload) === false) {
        Common::logWarn('Unable to update resume configuration file; leaving original contents.');
        // Warn the operator while continuing provisioning to remain fail-soft.
    }
}

clearSshHostKeys();
resetMachineIdentifier();
cleanResumeConfiguration();
// Execute the helper routines sequentially to mirror the original Bash order.

Common::logInfo('Task 10 complete: identifiers regenerated.');
// Final breadcrumb so logs show the task completed without fatal errors.
