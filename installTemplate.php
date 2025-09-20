#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// installTemplate.php - Entry point executed by mcxRescue inside the chroot.
// Delegates to the distro configure script while keeping logging consistent.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Use strict types so unexpected values trigger immediate failures.

use Distros\Common\Common;
// Reuse the shared PHP helper for logging and guard utilities.

require __DIR__ . '/distros/common/lib/Common.php';
// Load the helper definitions one time for reuse below.

Common::ensureRoot();
// The chroot scripts always require root to manipulate system files.

$configureScript = __DIR__ . '/distros/configure.php';
// Path to the PHP orchestrator that coordinates distro provisioning tasks.

$forwardArgs = $argv;
array_shift($forwardArgs);
// Pass through CLI arguments supplied by mcxRescue so configure.php sees them.

Common::logInfo('Launching distros/configure.php from installTemplate.php.');
// Emit a breadcrumb so logs show where the provisioning hand-off happens.

$success = Common::runPhpScript($configureScript, $forwardArgs, false);
// Delegate execution to the shared PHP helper while forwarding the arguments.

if (!$success) {
    Common::logWarn('distros/configure.php reported a failure; review logs for details.');
}

Common::logInfo('Distro configuration completed via installTemplate.php.');
// Provide a clear marker that the provisioning stage reached completion.
