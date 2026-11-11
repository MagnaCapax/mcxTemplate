#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 23 - Write /etc/resolv.conf from the resolver list the installer passes in
// MCX_RESOLVERS (comma separated, one to three addresses).
// Absent list: skipped with a warning, unless MCX_STRICT=1 (the network install sets it),
// where a missing list fails the task. An invalid list always fails: a half-valid
// resolver file would leave the node without working DNS.
// MCX_TARGET_ROOT is a test seam (a directory standing in for /); chroot runs have none.
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
Common::logInfo('Task 23: rendering resolver configuration.');

$raw = trim((string) (getenv('MCX_RESOLVERS') ?: ''));
if ($raw === '') {
    if (trim((string) (getenv('MCX_STRICT') ?: '')) === '1') {
        Common::fail('Task 23: no resolvers supplied (MCX_RESOLVERS) and strict mode is on.');
    }
    Common::logWarn('Task 23: no resolvers supplied; leaving /etc/resolv.conf as it is.');
    return;
}

$resolvers = array_map('trim', explode(',', $raw));
if (count($resolvers) > 3) {
    Common::fail('Task 23: more than three resolvers supplied.');
}
foreach ($resolvers as $address) {
    if (filter_var($address, FILTER_VALIDATE_IP) === false) {
        Common::fail('Task 23: MCX_RESOLVERS holds an entry that is not an IP address.');
    }
}

$root = rtrim((string) (getenv('MCX_TARGET_ROOT') ?: ''), '/');
$file = $root . '/etc/resolv.conf';
$content = '';
foreach ($resolvers as $address) {
    $content .= 'nameserver ' . $address . "\n";
}

// A symlink (systemd-resolved stub) is replaced by a plain file; the write never follows it.
if (is_link($file) || is_file($file)) {
    @unlink($file);
}
if (@file_put_contents($file, $content, LOCK_EX) !== strlen($content)) {
    Common::fail('Task 23: /etc/resolv.conf could not be written.');
}
@chmod($file, 0644);

Common::logInfo('Task 23 complete: resolvers written.', ['count' => count($resolvers)]);
