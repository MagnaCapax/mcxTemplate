#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// Task 20 - Configure hostname and basic network identity for Debian clones.
// Reads kernel parameters, renders hostname files, and writes interfaces config.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict typing keeps helper calls predictable during provisioning runs.

use Distros\Common\Common;
// Shared logging and guard helpers keep behaviour consistent across scripts.

$scriptDir = __DIR__;
// Capture the current directory to assist with relative path calculations.

$repoRoot = getenv('MCX_TEMPLATE_ROOT');
if ($repoRoot === false || trim((string) $repoRoot) === '') {
    $repoRoot = dirname($scriptDir, 4);
    // Fall back to computing the repository root directly from the filesystem.
}

require $repoRoot . '/distros/common/lib/Common.php';
// Load the helper definitions before executing any substantive logic.

Common::ensureRoot();
// Reject execution unless we have the privileges required for file updates.

$hostnameDomain = trim((string) (getenv('HOSTNAME_DOMAIN') ?: 'pulsedmedia.com'));
// Preserve the historical default domain while allowing overrides when needed.

Common::logInfo('Task 20: configuring Debian hostname and network files.');
// Log the start of the task so operators can follow progress in mcxRescue logs.

/**
 * Try to extract the hostname from the kernel command line before falling back.
 */
function deriveHostname(): string
{
    $cmdline = @file_get_contents('/proc/cmdline') ?: '';
    // Read the kernel parameters that mcxRescue typically passes during boot.

    if (preg_match('/hostname=([^\s]+)/', $cmdline, $matches) === 1) {
        $candidate = $matches[1];
    } else {
        $candidate = (string) gethostname();
        Common::logWarn('hostname= kernel parameter missing, falling back to ' . $candidate . '.');
        // Warn operators that we relied on system hostname as a fallback path.
    }

    $short = explode('.', $candidate)[0];
    $short = trim($short);
    if ($short === '') {
        Common::fail('Unable to determine a valid short hostname.');
        // Abort because the remaining helper scripts require a non-empty value.
    }

    return $short;
}

/**
 * Gather the primary CIDR and gateway from the iproute2 tooling when available.
 */
function gatherNetworkInformation(): array
{
    if (!Common::commandExists('ip')) {
        Common::logWarn('ip command not found; using built-in network defaults.');
        return ['', '', ''];
        // Continue gracefully to preserve the fail-soft behaviour from Bash.
    }

    $status = 0;
    $addressOutput = Common::runCommand(['ip', '-o', '-f', 'inet', 'addr', 'show', 'scope', 'global'], $status);
    $cidr = '';
    if ($status === 0 && $addressOutput !== '') {
        $lines = preg_split('/\r?\n/', $addressOutput) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/inet ([0-9.]+\/[0-9]+)/', (string) $line, $match) === 1) {
                $cidr = $match[1];
                break;
                // Stop at the first global address to mirror the legacy approach.
            }
        }
    }

    if ($cidr === '') {
        Common::logWarn('No global IPv4 address detected; using defaults for network templates.');
        // Notify operators that the helper will fall back to example addressing.
    }

    $ipAddress = $cidr !== '' ? explode('/', $cidr)[0] : '';
    // Extract the host portion so we can update /etc/hosts consistently.

    $routeStatus = 0;
    $routeOutput = Common::runCommand(['ip', 'route'], $routeStatus);
    $gateway = '';
    if ($routeStatus === 0 && $routeOutput !== '') {
        $routeLines = preg_split('/\r?\n/', $routeOutput) ?: [];
        foreach ($routeLines as $line) {
            if (preg_match('/^default\s+via\s+([0-9.]+)/', (string) $line, $match) === 1) {
                $gateway = $match[1];
                break;
                // Capture the first default route which matches the Bash script.
            }
        }
    }

    if ($gateway === '') {
        Common::logWarn('Gateway not found in routing table; interfaces will omit gateway.');
        // Provide visibility that the generated interfaces file will skip gateway.
    }

    return [$cidr, $ipAddress, $gateway];
}

/**
 * Render hostname related files using the shared helper.
 */
function writeHostnameFiles(string $repoRoot, string $shortHostname, string $fqdn, string $ipAddress): void
{
    $hostnameHelper = $repoRoot . '/distros/common/create-hostname.php';
    // Shared helper path that renders /etc/hostname and /etc/hosts.

    $hostIp = $ipAddress !== '' ? $ipAddress : '127.0.1.1';
    // Reuse the longstanding default when we fail to detect an address.

    putenv('SHORT_HOSTNAME=' . $shortHostname);
    $_ENV['SHORT_HOSTNAME'] = $shortHostname;
    putenv('FQDN=' . $fqdn);
    $_ENV['FQDN'] = $fqdn;
    putenv('HOST_IP=' . $hostIp);
    $_ENV['HOST_IP'] = $hostIp;
    // Export variables so the helper can build the final file contents.

    Common::logInfo('Writing hostname files for ' . $fqdn . '.');
    Common::runPhpScript($hostnameHelper);
    // Execute the helper via the shared wrapper so failures are handled uniformly.
}

/**
 * Render /etc/network/interfaces through the shared PHP helper.
 */
function writeNetworkConfig(string $repoRoot, string $cidr, string $gateway): void
{
    $networkHelper = $repoRoot . '/distros/common/create-network-config.php';
    // Shared helper path for interface rendering.

    $cidrValue = $cidr !== '' ? $cidr : '192.0.2.10/24';
    // Maintain the example CIDR when detection fails to provide real data.

    putenv('NETWORK_CIDR=' . $cidrValue);
    $_ENV['NETWORK_CIDR'] = $cidrValue;
    putenv('GATEWAY=' . $gateway);
    $_ENV['GATEWAY'] = $gateway;
    // Export values so the helper can build its configuration template.

    Common::logInfo('Writing network interfaces with primary address ' . $cidrValue . '.');
    Common::runPhpScript($networkHelper);
    // Execute the helper to render /etc/network/interfaces.
}

$shortHostname = deriveHostname();
[$cidr, $ipAddress, $gateway] = gatherNetworkInformation();
// Collect inputs up-front so helper calls remain linear and easy to follow.

$fqdn = $shortHostname . '.' . $hostnameDomain;
// Build the fully qualified name once to share across helper invocations.

writeHostnameFiles($repoRoot, $shortHostname, $fqdn, $ipAddress);
writeNetworkConfig($repoRoot, $cidr, $gateway);
// Delegate file rendering to the shared helpers with validated inputs.

Common::logInfo('Task 20 complete: identity files rendered.');
// Emit a completion message so provisioning logs clearly show success.
