#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 40 - Refresh initramfs and bootloader metadata after configuration.
// Keeps the boot chain aligned with files rendered by previous provisioning tasks.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict typing stops accidental nulls from creeping into command arguments.

use Distros\Common\Common;
// Shared helpers provide consistent logging and command execution semantics.

$scriptDir = __DIR__;
// Track the task directory so we can recover the repository root if needed.

$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
    // Fall back to a relative lookup when the environment variable is absent.
}

require $repoRoot . '/distros/common/lib/Common.php';
// Load helper definitions before touching any boot-related configuration.

Common::ensureRoot();
// Guard against accidental execution without the necessary privileges.

$targetsRaw = trim((string) (getenv('GRUB_TARGETS') ?: '/dev/sda /dev/sdb'));
$grubTargets = array_values(array_filter(preg_split('/\s+/', $targetsRaw) ?: []));
// Parse the boot device list while maintaining the historical dual-disk default.

Common::logInfo('Task 40: updating Debian boot components.');
// Announce the beginning of boot maintenance in the provisioning logs.

/**
 * Refresh initramfs images and install GRUB on the configured boot targets.
 */
function updateBootComponents(array $targets): void
{
    Common::logInfo('Updating initramfs and regenerating GRUB configuration.');
    // Begin by refreshing initramfs and the top-level GRUB configuration files.

    Common::runIfCommandExists('update-initramfs', ['-u']);
    Common::runIfCommandExists('update-grub');
    // Execute both commands when available while logging missing utilities.

    foreach ($targets as $device) {
        if (!file_exists($device)) {
            Common::logWarn('Block device ' . $device . ' missing; skipping GRUB installation.');
            // Skip gracefully when the expected boot device is not present.
            continue;
        }

        Common::logInfo('Installing GRUB to ' . $device . '.');
        Common::runIfCommandExists('grub-install', [$device]);
        // Install GRUB to each device while tolerating absent grub-install binaries.
    }
}

updateBootComponents($grubTargets);
// Execute the boot refresh routine once with the parsed target list.

Common::logInfo('Task 40 complete: boot chain refreshed.');
// Provide a closing breadcrumb to confirm the boot maintenance finished.
