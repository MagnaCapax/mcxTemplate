#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// configure.php - PHP orchestrator that runs distro provisioning tasks in order.
// Detects the active distro, exports shared context, and executes each task.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict types keep argument handling predictable across the entry point.

use Distros\Common\Common;
// Reuse the shared logging and guard helpers for consistent behaviour.

$scriptDir = __DIR__;
// Cache the distros directory path for repeated use below.

$repoRoot = dirname($scriptDir);
// Derive the repository root by walking one level up from the distros folder.

require $scriptDir . '/common/lib/Common.php';
// Load the helper definitions exactly once before any logic executes.

Common::ensureRoot();
// Abort immediately if the orchestrator does not run with root privileges.

putenv('MCX_TEMPLATE_ROOT=' . $repoRoot);
$_ENV['MCX_TEMPLATE_ROOT'] = $repoRoot;
// Share the repository root with every downstream task via environment vars.

/**
 * Render the usage banner for interactive operators.
 */
function showUsage(): void
{
    echo "Usage: configure.php [OPTIONS] [DISTRO [VERSION]]" . PHP_EOL;
    echo PHP_EOL;
    echo "Base options:" . PHP_EOL;
    echo "  --distro=ID                Override distro detection." . PHP_EOL;
    echo "  --version=MAJOR            Override version detection." . PHP_EOL;
    echo PHP_EOL;
    echo "Networking & identity:" . PHP_EOL;
    echo "  --hostname=FQDN            Set the fully-qualified hostname." . PHP_EOL;
    echo "  --host-ip=IP               Explicit host IP for /etc/hosts." . PHP_EOL;
    echo "  --network-cidr=CIDR        Primary interface CIDR block." . PHP_EOL;
    echo "  --gateway=IP               Default gateway address." . PHP_EOL;
    echo PHP_EOL;
    echo "Storage:" . PHP_EOL;
    echo "  --root-device=PATH         Device to mount at /." . PHP_EOL;
    echo "  --home-device=PATH|omit    Device for /home or omit to skip." . PHP_EOL;
    echo PHP_EOL;
    echo "Post provisioning:" . PHP_EOL;
    echo "  --ssh-keys-uri=URI         Fetch authorized_keys from URI." . PHP_EOL;
    echo "  --post-config=URI          Download and execute post script." . PHP_EOL;
    echo PHP_EOL;
    echo "Without options the script inspects /etc/os-release to detect" . PHP_EOL;
    echo "the active distro and version. Environment variables" . PHP_EOL;
    echo "MCX_DISTRO_ID and MCX_DISTRO_VERSION provide the same overrides." . PHP_EOL;
}

/**
 * Split argv into recognised long options and positional arguments.
 */
function parseArguments(array $arguments): array
{
    $options = [];
    $positionals = [];
    $known = [
        'distro',
        'version',
        'hostname',
        'host-ip',
        'network-cidr',
        'gateway',
        'post-config',
        'ssh-keys-uri',
        'root-device',
        'home-device',
    ];

    while ($arguments !== []) {
        $current = array_shift($arguments);

        if ($current === '--help' || $current === '-h') {
            showUsage();
            exit(0);
        }

        if ($current === '--') {
            $positionals = array_merge($positionals, $arguments);
            break;
        }

        if (strncmp($current, '--', 2) !== 0) {
            $positionals[] = $current;
            continue;
        }

        $eqPos = strpos($current, '=');
        if ($eqPos !== false) {
            $name = substr($current, 2, $eqPos - 2);
            $value = substr($current, $eqPos + 1);
        } else {
            $name = substr($current, 2);
            if ($arguments !== [] && strncmp((string) $arguments[0], '--', 2) !== 0) {
                $value = array_shift($arguments);
            } else {
                $value = '';
            }
        }

        if (!in_array($name, $known, true)) {
            Common::logWarn("Ignoring unknown option --{$name}.");
            continue;
        }

        $options[$name] = $value;
    }

    return [$options, $positionals];
}

function setEnvironmentValue(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$args = $argv;
array_shift($args);
// Remove the script name from the argument list for easier handling.

[$cliOptions, $args] = parseArguments($args);
// Separate CLI options from positional distro arguments.

$distroId = trim((string) ($cliOptions['distro'] ?? (getenv('MCX_DISTRO_ID') ?: ($args[0] ?? ''))));
$distroVersion = trim((string) ($cliOptions['version'] ?? (getenv('MCX_DISTRO_VERSION') ?: ($args[1] ?? ''))));
// Allow explicit overrides via command-line options, environment variables, or positionals.

if ($distroId !== '') {
    setEnvironmentValue('MCX_DISTRO_ID', $distroId);
}

if ($distroVersion !== '') {
    setEnvironmentValue('MCX_DISTRO_VERSION', $distroVersion);
}

$hostnameOption = trim((string) ($cliOptions['hostname'] ?? ''));
if ($hostnameOption !== '') {
    setEnvironmentValue('MCX_FQDN', $hostnameOption);
    $short = explode('.', $hostnameOption)[0] ?? $hostnameOption;
    $short = trim((string) $short);
    if ($short === '') {
        Common::logWarn('Ignoring empty hostname override.');
    } else {
        setEnvironmentValue('MCX_SHORT_HOSTNAME', $short);
    }

    $dotPos = strpos($hostnameOption, '.');
    if ($dotPos !== false) {
        $domain = substr($hostnameOption, $dotPos + 1);
        if ($domain !== false && trim($domain) !== '') {
            setEnvironmentValue('MCX_HOSTNAME_DOMAIN', trim($domain));
        }
    }
}

$optionEnvMap = [
    'host-ip' => 'MCX_HOST_IP',
    'network-cidr' => 'MCX_NETWORK_CIDR',
    'gateway' => 'MCX_GATEWAY',
    'post-config' => 'MCX_POST_CONFIG_URI',
    'ssh-keys-uri' => 'MCX_SSH_KEYS_URI',
    'root-device' => 'ROOT_DEVICE',
    'home-device' => 'HOME_DEVICE',
];

foreach ($optionEnvMap as $option => $envName) {
    if (array_key_exists($option, $cliOptions)) {
        $value = trim((string) $cliOptions[$option]);
        if ($option === 'home-device' && strcasecmp($value, 'omit') === 0) {
            putenv($envName);
            unset($_ENV[$envName]);
            continue;
        }

        if ($value === '') {
            Common::logWarn('Ignoring empty value for --' . $option . '.');
            continue;
        }

        setEnvironmentValue($envName, $value);
    }
}

/**
 * Detect distro information from /etc/os-release when overrides are missing.
 */
function detectDistro(string $currentId, string $currentVersion): array
{
    $distroId = $currentId;
    $distroVersion = $currentVersion;
    // Start with the supplied identifiers and fill in any missing pieces.

    if ($distroId !== '' && $distroVersion !== '') {
        return [$distroId, $distroVersion];
        // Short-circuit when both values were explicitly provided by caller.
    }

    $osRelease = '/etc/os-release';
    if (!is_file($osRelease)) {
        return [$distroId, $distroVersion];
        // Leave detection untouched if the metadata file is absent entirely.
    }

    $data = @parse_ini_file($osRelease);
    if ($data === false) {
        return [$distroId, $distroVersion];
        // Fail softly so the operator can fall back to manual overrides later.
    }

    if ($distroId === '' && isset($data['ID'])) {
        $distroId = trim((string) $data['ID']);
        // Mirror the behaviour of the historical Bash helper for ID values.
    }

    if ($distroVersion === '' && isset($data['VERSION_ID'])) {
        $distroVersion = trim((string) $data['VERSION_ID']);
        // Prefer VERSION_ID so we align with numeric release identifiers.
    }

    if ($distroVersion === '' && isset($data['VERSION_CODENAME'])) {
        $distroVersion = trim((string) $data['VERSION_CODENAME']);
        // Fall back to VERSION_CODENAME when numeric identifiers are missing.
    }

    return [$distroId, $distroVersion];
}

[$distroId, $distroVersion] = detectDistro($distroId, $distroVersion);
// Populate missing fields by inspecting the local os-release metadata.

if ($distroId === '') {
    Common::fail('Unable to determine distro identifier.');
    // Hard stop because we cannot locate any task directories without the ID.
}

$distroId = strtolower($distroId);
// Normalise to lowercase so directory lookups remain consistent.

if ($distroVersion !== '') {
    $majorPieces = explode('.', $distroVersion);
    $majorVersion = strtolower(trim($majorPieces[0] ?? ''));
    $distroVersion = $majorVersion !== '' ? $majorVersion : strtolower($distroVersion);
    // Keep only the major portion when dealing with dotted release numbers.
}

Common::logInfo(
    "Configuring distro '{$distroId}' version '" . ($distroVersion === '' ? 'common' : $distroVersion) . "'."
);
// Emit a friendly status message before we begin running any tasks.

$distroDir = $scriptDir . '/' . $distroId;
if (!is_dir($distroDir)) {
    Common::fail("No configuration available for distro '{$distroId}'.");
    // Abort early when the repository lacks the requested distro directory.
}

/**
 * Execute an individual task script using the PHP binary.
 */
function runTaskScript(string $path): void
{
    $scriptName = basename($path);
    // Capture the name in advance so logging remains tidy below.

    if (!is_file($path)) {
        Common::logWarn("Skipping {$scriptName}; file is missing.");
        return;
        // Avoid explosions when a task vanishes mid-run for any reason.
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($extension !== 'php') {
        Common::logWarn("Skipping {$scriptName}; unsupported task type.");
        return;
        // Enforce PHP-based tasks while leaving a breadcrumb for operators.
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    // Build the command line explicitly so spacing inside paths is handled.

    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];
    // Pass the current standard streams through for consistent logging.

    $process = proc_open($command, $descriptorSpec, $pipes, dirname($path));
    if (!is_resource($process)) {
        Common::logWarn("Failed to launch task {$scriptName}.");
        return;
        // Keep provisioning moving so a single failure does not halt progress.
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    // Close pipe handles immediately to avoid leaking descriptors.

    $status = proc_close($process);
    if ($status !== 0) {
        Common::logWarn("Task {$scriptName} exited with status {$status}.");
        // Warn operators while carrying on to the remaining tasks.
    }
}

/**
 * Run all task scripts contained within the requested directory.
 */
function runTaskDir(string $taskDir, string $label): void
{
    if (!is_dir($taskDir)) {
        Common::logInfo("No {$label} directory at {$taskDir}; skipping.");
        return;
        // Provide a small breadcrumb when directories are intentionally absent.
    }

    $tasks = [];
    $iterator = new DirectoryIterator($taskDir);
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $tasks[] = $fileInfo->getPathname();
        }
    }
    // Collect plain files so we can sort and execute them deterministically.

    sort($tasks, SORT_STRING);
    if ($tasks === []) {
        Common::logInfo("No {$label} tasks found in {$taskDir}.");
        return;
        // Keep the operator informed when directories intentionally stay empty.
    }

    foreach ($tasks as $task) {
        runTaskScript($task);
        // Execute each task sequentially to mirror the former Bash runner.
    }
}

/**
 * Execute repository tasks and optional user overrides for a directory tree.
 */
function runTaskGroup(string $baseDir, string $label): void
{
    runTaskDir($baseDir . '/tasks', $label);
    runTaskDir($baseDir . '/user.d', $label . ' user overrides');
    // Allow operators to drop local overrides alongside the standard tasks.
}

runTaskGroup($distroDir . '/common', $distroId . ' common');
// Start with shared distro tasks before exploring version-specific overrides.

if ($distroVersion !== '') {
    $versionDir = $distroDir . '/' . $distroVersion;
    if (is_dir($versionDir)) {
        runTaskGroup($versionDir, $distroId . ' ' . $distroVersion);
        // Execute version specific tasks when the directory actually exists.
    } else {
        Common::logInfo("No version-specific directory for {$distroId} {$distroVersion}; skipping.");
        // Let the operator know that the version folder is intentionally absent.
    }
} else {
    Common::logInfo('Version identifier missing; only common tasks executed.');
    // Provide context for the log stream when only common tasks are available.
}

Common::logInfo('Distro configuration completed successfully.');
// Final confirmation so mcxRescue logs show the provisioning stage finished.
