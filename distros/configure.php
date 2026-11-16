#!/usr/bin/env php
<?php
// -----------------------------------------------------------------------------
// configure.php - PHP orchestrator that runs distro provisioning tasks in order.
// Detects the active distro, exports shared context, and executes each task.
// -----------------------------------------------------------------------------

declare(strict_types=1);
// Strict types keep argument handling predictable across the entry point.

use Distros\Common\Common;
use Lib\Provisioning\Configurator;
// Reuse the shared logging and guard helpers for consistent behaviour.

$scriptDir = __DIR__;
// Cache the distros directory path for repeated use below.

$repoRoot = dirname($scriptDir);
// Derive the repository root by walking one level up from the distros folder.

require $scriptDir . '/common/lib/Common.php';
require $repoRoot . '/src/Lib/Provisioning/Configurator.php';
// Load the helper definitions exactly once before any logic executes.

putenv('MCX_TEMPLATE_ROOT=' . $repoRoot);
$_ENV['MCX_TEMPLATE_ROOT'] = $repoRoot;
// Share the repository root with every downstream task via environment vars.

$preconditionsFile = $repoRoot . '/distros/task_preconditions.php';
$taskPreconditions = [];
if (is_file($preconditionsFile)) {
    $loaded = require $preconditionsFile;
    if (is_array($loaded)) {
        foreach ($loaded as $task => $checks) {
            $taskPreconditions[strtolower((string) $task)] = $checks;
        }
    }
}
$GLOBALS['MCX_TASK_PRECONDITIONS'] = $taskPreconditions;

/**
 * Render the usage banner for interactive operators.
 */
function showUsage(): void
{
    echo "Usage: configure.php [OPTIONS] [DISTRO [VERSION]]" . PHP_EOL;
    echo PHP_EOL;
    echo "Host configuration:" . PHP_EOL;
    echo "  --hostname=FQDN            Fully-qualified hostname to apply." . PHP_EOL;
    echo "  --host-ip=IP               Explicit host IP for /etc/hosts." . PHP_EOL;
    echo "  --network-cidr=CIDR        Primary interface CIDR block." . PHP_EOL;
    echo "  --gateway=IP               Default gateway address." . PHP_EOL;
    echo "  --hosts-template=PATH      Custom template for /etc/hosts placeholders." . PHP_EOL;
    echo "  --primary-interface=NAME   Override detected primary interface for templates." . PHP_EOL;
    echo PHP_EOL;
    echo "Storage:" . PHP_EOL;
    echo "  --mount=MOUNT,DEV[,TYPE[,OPTS]]  Generic mount specification." . PHP_EOL;
    echo "  --root-device=PATH         Device to mount at / (legacy, kept for fallback)." . PHP_EOL;
    echo "  --home-device=PATH|omit    Device for /home or omit to skip (legacy)." . PHP_EOL;
    echo PHP_EOL;
    echo "Post provisioning:" . PHP_EOL;
    echo "  --ssh-keys-uri=URI         Fetch authorized_keys from URI." . PHP_EOL;
    echo "  --ssh-keys-sha256=HASH     Expected SHA-256 (or URI=HASH pairs) for key payloads." . PHP_EOL;
    echo "  --post-config=URI          Download and execute post script." . PHP_EOL;
    echo "  --post-config-sha256=HASH  Expected SHA-256 for downloaded post script." . PHP_EOL;
    echo PHP_EOL;
    echo "Operational:" . PHP_EOL;
    echo "  --log-dir=PATH             Directory for per-task log files." . PHP_EOL;
    echo "  --skip-tasks=LIST          Comma/space list of task names to skip." . PHP_EOL;
    echo "  --dry-run                  Print planned tasks without executing them." . PHP_EOL;
    echo "  --distro=ID                Override distro detection." . PHP_EOL;
    echo "  --version=MAJOR            Override version detection." . PHP_EOL;
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
    $parsed = Configurator::parseArguments($arguments);

    return [
        $parsed['options'],
        $parsed['positionals'],
        $parsed['multi'],
        $parsed['help'],
    ];
}

function setEnvironmentValue(string $key, string $value): void
{
    Configurator::setEnvironmentValue($key, $value);
}

/**
 * Determine whether a task's preconditions are satisfied.
 */
function taskPreconditionsMet(string $scriptName, array $contextBase = []): bool
{
    global $MCX_TASK_PRECONDITIONS;

    $key = strtolower($scriptName);
    if (!isset($MCX_TASK_PRECONDITIONS[$key])) {
        return true;
    }

    $checks = $MCX_TASK_PRECONDITIONS[$key];
    if (!is_array($checks)) {
        return true;
    }

    foreach ($checks as $check) {
        if (!is_array($check) || !isset($check['type'])) {
            continue;
        }

        $type = strtolower((string) $check['type']);
        if ($type === 'command') {
            $command = (string) ($check['value'] ?? '');
            if ($command === '') {
                continue;
            }
            if (!Common::commandExists($command)) {
                Common::logInfo(
                    'Skipping task; required command missing.',
                    $contextBase + ['event' => 'precondition-skipped', 'reason' => 'command', 'command' => $command]
                );
                return false;
            }
        } elseif ($type === 'env') {
            $envName = (string) ($check['value'] ?? '');
            if ($envName === '') {
                continue;
            }
            $value = getenv($envName);
            if ($value === false || trim((string) $value) === '') {
                Common::logInfo(
                    'Skipping task; required environment variable missing.',
                    $contextBase + ['event' => 'precondition-skipped', 'reason' => 'env', 'env' => $envName]
                );
                return false;
            }
        } elseif ($type === 'env_any') {
            $names = $check['names'] ?? [];
            if (!is_array($names)) {
                continue;
            }
            $found = false;
            foreach ($names as $name) {
                $value = getenv((string) $name);
                if ($value !== false && trim((string) $value) !== '') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                Common::logInfo(
                    'Skipping task; none of the required environment variables are set.',
                    $contextBase + ['event' => 'precondition-skipped', 'reason' => 'env_any', 'env' => $names]
                );
                return false;
            }
        }
    }

    return true;
}

/**
 * Parse a --mount definition of the form mountpoint,device[,type[,options]].
 */
function parseMountOption(string $spec): ?array
{
    return Configurator::parseMountOption($spec);
}

/**
 * Normalize mount entries with defaults and validation.
 */
function normalizeMountEntries(array $entries): array
{
    return Configurator::normalizeMountEntries($entries);
}

/**
 * Legacy mount specification based on root/home/boot/swap environment values.
 */
function buildLegacyMountSpec(): array
{
    return Configurator::buildLegacyMountSpec();
}

/**
 * Maintain legacy environment variables for compatibility.
 */
function setLegacyEnvFromMounts(array $normalizedMounts): void
{
    Configurator::setLegacyEnvFromMounts($normalizedMounts);
}

$args = $argv;
array_shift($args);
// Remove the script name from the argument list for easier handling.

[$cliOptions, $args, $multiOptions, $helpRequested] = parseArguments($args);
if ($helpRequested) {
    showUsage();
    exit(0);
}
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

Common::ensureRoot();
// Enforce root privileges once we know we actually need to run tasks.

$dryRun = array_key_exists('dry-run', $cliOptions);
if (!$dryRun) {
    $envDryRun = getenv('MCX_DRY_RUN');
    if ($envDryRun !== false && trim((string) $envDryRun) !== '' && trim((string) $envDryRun) !== '0') {
        $dryRun = true;
    }
}

if ($dryRun) {
    setEnvironmentValue('MCX_DRY_RUN', '1');
    $GLOBALS['MCX_DRY_RUN'] = true;
    Common::logInfo('Dry-run mode enabled; tasks will not modify the filesystem.', ['event' => 'dry-run']);
} else {
    $GLOBALS['MCX_DRY_RUN'] = false;
    putenv('MCX_DRY_RUN');
    unset($_ENV['MCX_DRY_RUN']);
}

$logDir = trim((string) (getenv('MCX_LOG_DIR') ?: '/var/log/mcxTemplate'));
if ($logDir === '') {
    $logDir = '/var/log/mcxTemplate';
}
$logDirCreated = false;
if (!is_dir($logDir)) {
    if (@mkdir($logDir, 0755, true) || is_dir($logDir)) {
        $logDirCreated = true;
    } else {
        Common::logWarn(
            "Unable to create log directory '{$logDir}'; task logs limited to console.",
            ['log_dir' => $logDir]
        );
        $logDir = '';
    }
} else {
    $logDirCreated = true;
}

if ($logDirCreated && $logDir !== '') {
    $resolvedLogDir = realpath($logDir);
    if (is_string($resolvedLogDir)) {
        $logDir = $resolvedLogDir;
        setEnvironmentValue('MCX_LOG_DIR', $logDir);
    }
    $GLOBALS['MCX_LOG_DIR_PATH'] = $logDir;
} else {
    $GLOBALS['MCX_LOG_DIR_PATH'] = null;
    putenv('MCX_LOG_DIR');
    unset($_ENV['MCX_LOG_DIR']);
}

$structuredPath = getenv('MCX_STRUCTURED_LOG');
if (($structuredPath === false || trim((string) $structuredPath) === '') && $logDir !== '') {
    $defaultStructured = rtrim($logDir, '/') . '/structured.log';
    setEnvironmentValue('MCX_STRUCTURED_LOG', $defaultStructured);
}

$skipCli = trim((string) ($cliOptions['skip-tasks'] ?? ''));
if ($skipCli !== '') {
    setEnvironmentValue('MCX_SKIP_TASKS', $skipCli);
}

$skipSource = trim((string) (getenv('MCX_SKIP_TASKS') ?: ''));
$skipData = Configurator::buildSkipTaskSet($skipSource);
$skipTaskSet = $skipData['set'];
$skipTaskDisplay = $skipData['display'];

if ($skipTaskDisplay !== []) {
    Common::logInfo(
        'Tasks disabled via MCX_SKIP_TASKS: ' . implode(', ', $skipTaskDisplay),
        ['skip_tasks' => $skipTaskDisplay]
    );
}
$GLOBALS['MCX_SKIP_TASKS_SET'] = $skipTaskSet;

$mountSpecs = [];
foreach (($multiOptions['mount'] ?? []) as $mountDefinition) {
    $parsed = parseMountOption($mountDefinition);
    if ($parsed !== null) {
        $mountSpecs[] = $parsed;
    }
}

if ($mountSpecs === []) {
    $mountJson = trim((string) (getenv('MCX_MOUNT_SPEC') ?: ''));
    if ($mountJson !== '') {
        $decoded = json_decode($mountJson, true);
        if (is_array($decoded)) {
            $mountSpecs = $decoded;
        } else {
            Common::logWarn('Unable to decode MCX_MOUNT_SPEC; falling back to legacy defaults.');
        }
    }
}

if ($mountSpecs === []) {
    $mountSpecs = buildLegacyMountSpec();
}

$normalizedMounts = normalizeMountEntries($mountSpecs);

$hasRoot = false;
foreach ($normalizedMounts as $entry) {
    if (!$entry['is_swap'] && $entry['mount'] === '/') {
        $hasRoot = true;
        break;
    }
}

if (!$hasRoot) {
    Common::fail('At least one mount definition for / is required.');
}

Configurator::storeMountSpecification($normalizedMounts);
setLegacyEnvFromMounts($normalizedMounts);

Common::logInfo(
    'Mount specification resolved.',
    ['mounts' => array_map(static function (array $entry): array {
        return [
            'mount' => $entry['original_mount'] ?? $entry['mount'] ?? '',
            'device' => $entry['device'] ?? '',
            'type' => $entry['type'] ?? '',
        ];
    }, $normalizedMounts)]
);

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
    'post-config-sha256' => 'MCX_POST_CONFIG_SHA256',
    'ssh-keys-uri' => 'MCX_SSH_KEYS_URI',
    'ssh-keys-sha256' => 'MCX_SSH_KEYS_SHA256',
    'root-device' => 'ROOT_DEVICE',
    'home-device' => 'HOME_DEVICE',
    'log-dir' => 'MCX_LOG_DIR',
    'hosts-template' => 'MCX_HOSTS_TEMPLATE',
    'primary-interface' => 'MCX_PRIMARY_INTERFACE',
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
            Common::logWarn('Ignoring empty value for --' . $option . '.', ['option' => $option]);
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
    return Configurator::detectDistro($currentId, $currentVersion);
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
    "Configuring distro '{$distroId}' version '" . ($distroVersion === '' ? 'common' : $distroVersion) . "'.",
    ['distro' => $distroId, 'version' => $distroVersion === '' ? 'common' : $distroVersion]
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
    global $MCX_SKIP_TASKS_SET, $MCX_LOG_DIR_PATH, $MCX_DRY_RUN;

    $scriptName = basename($path);
    $scriptKey = strtolower($scriptName);

    if (isset($MCX_SKIP_TASKS_SET[$scriptKey])) {
        Common::logInfo(
            "Skipping {$scriptName}; disabled via MCX_SKIP_TASKS.",
            ['task' => $scriptName, 'event' => 'skipped', 'path' => $path]
        );
        return;
    }

    if (!is_file($path)) {
        Common::logWarn(
            "Skipping {$scriptName}; file is missing.",
            ['task' => $scriptName, 'event' => 'missing', 'path' => $path]
        );
        return;
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($extension !== 'php') {
        Common::logWarn(
            "Skipping {$scriptName}; unsupported task type.",
            ['task' => $scriptName, 'event' => 'unsupported', 'path' => $path]
        );
        return;
    }

    $contextBase = ['task' => $scriptName, 'path' => $path];

    if (!taskPreconditionsMet($scriptName, $contextBase)) {
        return;
    }

    if ($MCX_DRY_RUN) {
        Common::logInfo('DRY RUN: would execute ' . $scriptName . '.', $contextBase + ['event' => 'dry-run']);
        return;
    }

    $logDir = is_string($MCX_LOG_DIR_PATH ?? null) ? $MCX_LOG_DIR_PATH : null;
    $logFile = null;
    $logHandle = null;
    if ($logDir !== null && $logDir !== '') {
        $unique = preg_replace('/[^A-Za-z0-9]/', '', uniqid('', true));
        $unique = $unique !== '' ? substr($unique, -6) : (string) time();
        $logFile = rtrim($logDir, '/') . '/' . pathinfo($scriptName, PATHINFO_FILENAME) . '-' . date('Ymd_His') . '-' . $unique . '.log';
        $logHandle = @fopen($logFile, 'a');
        if (!is_resource($logHandle)) {
            Common::logWarn(
                'Unable to open task log file ' . $logFile . '.',
                ['task' => $scriptName, 'event' => 'log_open_failed', 'path' => $path, 'log_file' => $logFile]
            );
            $logFile = null;
            $logHandle = null;
        }
    }

    if ($logFile !== null) {
        $contextBase['log_file'] = $logFile;
    }

    $start = microtime(true);
    Common::logInfo('Starting task ' . $scriptName . '.', $contextBase + ['event' => 'start']);

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, dirname($path));
    if (!is_resource($process)) {
        Common::logWarn(
            "Failed to launch task {$scriptName}.",
            $contextBase + ['event' => 'launch_failed']
        );
        if (is_resource($logHandle)) {
            fclose($logHandle);
        }
        return;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    while (true) {
        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }

        if ($read === []) {
            break;
        }

        $write = $except = [];
        $selected = @stream_select($read, $write, $except, 0, 200000);
        if ($selected === false) {
            break;
        }

        if ($selected === 0) {
            continue;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            if ($stream === $pipes[1]) {
                fwrite(STDOUT, $chunk);
            } else {
                fwrite(STDERR, $chunk);
            }

            if (is_resource($logHandle)) {
                fwrite($logHandle, $chunk);
            }
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);

    if (is_resource($logHandle)) {
        fclose($logHandle);
    }

    $duration = microtime(true) - $start;
    $finishContext = $contextBase + [
        'event' => 'finish',
        'exit_code' => $status,
        'duration_seconds' => round($duration, 4),
    ];

    if ($status !== 0) {
        Common::logWarn(
            "Task {$scriptName} exited with status {$status}.",
            $finishContext
        );
    } else {
        Common::logInfo(
            sprintf('Task %s completed in %.3fs.', $scriptName, $duration),
            $finishContext
        );
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
