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
Common::logInfo('Task 19: clearing udev rules, machine info, and resume config.');

$ruleSeeds = [
    '/etc/udev/rules.d/70-persistent-net.rules',
    '/etc/udev/rules.d/75-persistent-net-generator.rules',
    '/etc/udev/rules.d/80-net-setup-link.rules',
];

$ruleMatches = glob('/etc/udev/rules.d/*persistent*net*.rules') ?: [];
$rules = array_unique(array_merge($ruleSeeds, $ruleMatches));

foreach ($rules as $rule) {
    if (file_exists($rule)) {
        @unlink($rule);
    }
}

$machineInfo = '/etc/machine-info';
if (file_exists($machineInfo)) {
    @unlink($machineInfo);
}

$resumeFile = '/etc/initramfs-tools/conf.d/resume';
if (is_file($resumeFile)) {
    $content = @file($resumeFile, FILE_IGNORE_NEW_LINES);
    if ($content !== false) {
        $filtered = array_filter($content, static fn(string $line): bool => strpos($line, 'RESUME=') !== 0);
        $payload = implode(PHP_EOL, $filtered);
        if (trim($payload) === '') {
            @unlink($resumeFile);
        } else {
            @file_put_contents($resumeFile, $payload . PHP_EOL);
        }
    }
}

Common::logInfo('Task 19 complete: udev and resume state cleared.');
