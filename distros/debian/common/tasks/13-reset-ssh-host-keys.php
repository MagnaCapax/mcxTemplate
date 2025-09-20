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
Common::logInfo('Task 13: resetting SSH host keys.');

$keys = glob('/etc/ssh/ssh_host_*') ?: [];
foreach ($keys as $keyPath) {
    @unlink($keyPath);
}

if (!Common::runIfCommandExists('ssh-keygen', ['-A'])) {
    Common::logWarn('ssh-keygen unavailable; host keys regenerate on demand.');
}

Common::logInfo('Task 13 complete: SSH host keys cleared.');
