#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 90 - Remove the provisioning engine (/opt/mcxTemplate) from the installed
// system once every other task has run, so a customer machine does not carry the
// installer. Opt-in: it runs only when MCX_REMOVE_ENGINE=1 (the network install sets
// it; task_preconditions.php skips it otherwise). It is the last task by number.
// NOTE: tasks that run after it (a distro version directory, user.d hooks) could no
// longer load the engine; add no task behind it.
// Guards: it removes only the engine it is running from, and only when that engine
// sits at <root>/opt/mcxTemplate (root = MCX_TARGET_ROOT, a test seam; empty inside the
// chroot). A symlink there, or any other layout, is left alone.
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
Common::logInfo('Task 90: removing the provisioning engine.');

if (trim((string) (getenv('MCX_REMOVE_ENGINE') ?: '')) !== '1') {
    Common::logInfo('Task 90: MCX_REMOVE_ENGINE is not set; the engine stays.');
    return;
}

$root = rtrim((string) (getenv('MCX_TARGET_ROOT') ?: ''), '/');
$engine = $root . '/opt/mcxTemplate';
$engineReal = realpath($engine);
$runningReal = realpath((string) $repoRoot);
if (is_link($engine) || $engineReal === false || $runningReal === false || $engineReal !== $runningReal) {
    Common::logWarn('Task 90: the running engine is not <root>/opt/mcxTemplate; nothing removed.');
    return;
}
// The root itself must be a real directory above the engine, never the engine's own parent chain by accident.
if ($root !== '' && (realpath($root) === false || $engineReal !== realpath($root) . '/opt/mcxTemplate')) {
    Common::logWarn('Task 90: the engine path does not resolve under the target root; nothing removed.');
    return;
}

// emptyDirectory never enters a symlinked directory (links are unlinked); then the now-empty engine directory goes.
Common::emptyDirectory($engineReal);
if (!@rmdir($engineReal) || file_exists($engineReal)) {
    Common::fail('Task 90: the engine could not be removed completely.');
}
Common::logInfo('Task 90 complete: engine removed.');
