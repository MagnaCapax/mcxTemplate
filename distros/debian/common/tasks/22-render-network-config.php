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
Common::logInfo('Task 22: rendering network configuration.');

function gatherNetworkInformation(): array
{
    if (!Common::commandExists('ip')) {
        Common::logWarn('ip command not found; using network defaults.');
        return ['', ''];
    }

    $status = 0;
    $addressOutput = Common::runCommand(['ip', '-o', '-f', 'inet', 'addr', 'show', 'scope', 'global'], $status);
    $cidr = '';
    if ($status === 0 && $addressOutput !== '') {
        foreach (preg_split('/\r?\n/', $addressOutput) ?: [] as $line) {
            if (preg_match('/inet ([0-9.]+\/[0-9]+)/', (string) $line, $match) === 1) {
                $cidr = $match[1];
                break;
            }
        }
    }

    $routeStatus = 0;
    $routeOutput = Common::runCommand(['ip', 'route'], $routeStatus);
    $gateway = '';
    if ($routeStatus === 0 && $routeOutput !== '') {
        foreach (preg_split('/\r?\n/', $routeOutput) ?: [] as $line) {
            if (preg_match('/^default\s+via\s+([0-9.]+)/', (string) $line, $match) === 1) {
                $gateway = $match[1];
                break;
            }
        }
    }

    if ($cidr === '') {
        Common::logWarn('No global IPv4 address detected; using defaults in template.');
    }

    return [$cidr, $gateway];
}

[$cidr, $gateway] = gatherNetworkInformation();
$cidrValue = $cidr !== '' ? $cidr : '192.0.2.10/24';

putenv('NETWORK_CIDR=' . $cidrValue);
putenv('GATEWAY=' . $gateway);
$_ENV['NETWORK_CIDR'] = $cidrValue;
$_ENV['GATEWAY'] = $gateway;

$success = Common::runPhpScript($repoRoot . '/distros/common/create-network-config.php', [], false);
if (!$success) {
    Common::logWarn('Network helper failed; retaining existing interfaces file.');
    return;
}

Common::logInfo('Task 22 complete: network configuration rendered.');
