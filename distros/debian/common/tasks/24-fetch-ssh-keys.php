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
Common::logInfo('Task 24: fetching SSH authorized keys.');

$uriList = trim((string) (getenv('MCX_SSH_KEYS_URI') ?: ''));
if ($uriList === '') {
    Common::logInfo('No SSH keys URI provided; skipping.');
    return;
}

$uris = array_filter(array_map('trim', preg_split('/[,\s]+/', $uriList) ?: []));
if ($uris === []) {
    Common::logInfo('SSH keys URI list empty after parsing; skipping.');
    return;
}

$sshDir = '/root/.ssh';
if (!is_dir($sshDir) && !@mkdir($sshDir, 0700, true)) {
    Common::logWarn('Unable to create /root/.ssh; skipping key installation.');
    return;
}
@chmod($sshDir, 0700);

$authorizedKeys = $sshDir . '/authorized_keys';
$buffer = '';
foreach ($uris as $uri) {
    $data = @file_get_contents($uri);
    if ($data === false) {
        Common::logWarn('Failed to download SSH keys from ' . $uri . '.');
        continue;
    }
    $buffer .= rtrim($data) . PHP_EOL;
}

if ($buffer === '') {
    Common::logWarn('No SSH keys retrieved; authorized_keys not updated.');
    return;
}

$prefix = '';
if (is_file($authorizedKeys) && filesize($authorizedKeys) > 0) {
    $prefix = PHP_EOL;
}

$result = @file_put_contents($authorizedKeys, $prefix . $buffer, FILE_APPEND | LOCK_EX);
if ($result === false) {
    Common::logWarn('Unable to append to authorized_keys.');
    return;
}

@chmod($authorizedKeys, 0600);
Common::logInfo('Task 24 complete: SSH keys installed.');
