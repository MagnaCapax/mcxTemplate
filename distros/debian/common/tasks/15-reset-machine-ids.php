#!/usr/bin/env php
<?php
declare(strict_types=1);

use Distros\Common\Common;

$scriptDir = __DIR__;
$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
}

require $repoRoot . '/distros/common/lib/Common.php';

Common::ensureRoot();
Common::logInfo('Task 15: resetting machine identifiers.');

$machineId = '/etc/machine-id';
$dbusMachineId = '/var/lib/dbus/machine-id';

@unlink($machineId);
@unlink($dbusMachineId);

if (!Common::runIfCommandExists('systemd-machine-id-setup')) {
    Common::logWarn('systemd-machine-id-setup missing; writing placeholder machine-id.');

    if (@file_put_contents($machineId, PHP_EOL) === false) {
        Common::fail('Unable to create /etc/machine-id placeholder.');
    }

    if (@file_put_contents($dbusMachineId, PHP_EOL) === false) {
        Common::logWarn('Unable to create /var/lib/dbus/machine-id placeholder.');
    }
}

Common::logInfo('Task 15 complete: machine identifiers cleared.');
