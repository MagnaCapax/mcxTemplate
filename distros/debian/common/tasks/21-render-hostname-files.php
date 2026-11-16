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
Common::logInfo('Task 21: writing hostname files.');

$success = Common::runPhpScript($repoRoot . '/distros/common/create-hostname.php', [], false);
if (!$success) {
    Common::logWarn('Hostname helper failed; leaving existing hostname files.');
    return;
}

Common::logInfo('Task 21 complete: hostname files written.');
