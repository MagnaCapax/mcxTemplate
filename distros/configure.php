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
    echo "Host configuration:" . PHP_EOL;
    echo "  --hostname=FQDN            Fully-qualified hostname to apply." . PHP_EOL;
    echo "  --host-ip=IP               Explicit host IP for /etc/hosts." . PHP_EOL;
    echo "  --network-cidr=CIDR        Primary interface CIDR block." . PHP_EOL;
    echo "  --gateway=IP               Default gateway address." . PHP_EOL;
    echo "  --hosts-template=PATH      Custom template for /etc/hosts placeholders." . PHP_EOL;
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
    $options = [];
    $positionals = [];
    $multi = [
        'mount' => [],
    ];
    $known = [
        'distro',
        'version',
        'hostname',
        'host-ip',
        'network-cidr',
        'gateway',
        'post-config',
        'post-config-sha256',
        'ssh-keys-uri',
        'ssh-keys-sha256',
        'root-device',
        'home-device',
        'log-dir',
        'skip-tasks',
        'hosts-template',
        'mount',
        'dry-run',
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
            Common::logWarn("Ignoring unknown option --{$name}.", ['option' => $name]);
            continue;
        }

        if ($name === 'mount') {
            $multi['mount'][] = $value;
            continue;
        }

        if ($name === 'dry-run' && $value === '') {
            $options[$name] = '1';
            continue;
        }

        $options[$name] = $value;
    }

    return [$options, $positionals, $multi];
}

function setEnvironmentValue(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

/**
 * Parse a --mount definition of the form mountpoint,device[,type[,options]].
 */
function parseMountOption(string $spec): ?array
{
    $parts = array_map('trim', explode(',', $spec));
    if (count($parts) < 2) {
        Common::logWarn('Ignoring invalid --mount definition; requires at least mountpoint and device.', ['value' => $spec]);
        return null;
    }

    [$mountPoint, $device] = $parts;
    $type = $parts[2] ?? '';
    $options = $parts[3] ?? '';

    if ($mountPoint === '' || $device === '') {
        Common::logWarn('Ignoring invalid --mount definition; empty mountpoint or device.', ['value' => $spec]);
        return null;
    }

    return [
        'mount' => $mountPoint,
        'device' => $device,
        'type' => $type,
        'options' => $options,
    ];
}

/**
 * Normalize mount entries with defaults and validation.
 */
function normalizeMountEntries(array $entries): array
{
    $normalized = [];

    foreach ($entries as $entry) {
        $mountPoint = $entry['mount'];
        $originalMount = $entry['original_mount'] ?? $mountPoint;
        $device = $entry['device'];
        $type = $entry['type'] ?? '';
        $options = $entry['options'] ?? '';

        $isSwap = strtolower($originalMount) === 'swap';
        if ($isSwap) {
            $mount = 'none';
            $type = $type !== '' ? $type : 'swap';
            $options = $options !== '' ? $options : 'sw';
            $pass = 0;
        } else {
            if ($mountPoint === '' || $mountPoint[0] !== '/') {
                Common::logWarn('Ignoring mount definition without absolute mountpoint.', ['mount' => $mountPoint, 'device' => $device]);
                continue;
            }
            $mount = $mountPoint;
            $type = $type !== '' ? $type : 'ext4';
            if ($options === '') {
                $options = $mount === '/' ? 'errors=remount-ro' : 'defaults';
            }
            $pass = $mount === '/' ? 1 : 2;
        }

        $normalized[] = [
            'mount' => $mount,
            'original_mount' => $originalMount,
            'device' => $device,
            'type' => $type,
            'options' => $options,
            'dump' => $entry['dump'] ?? 0,
            'pass' => $entry['pass'] ?? $pass,
            'is_swap' => $isSwap,
        ];
    }

    return $normalized;
}

/**
 * Legacy mount specification based on root/home/boot/swap environment values.
 */
function buildLegacyMountSpec(): array
{
    $defaults = [
        'ROOT_DEVICE' => '/dev/nvme0n1p2',
        'HOME_DEVICE' => '/dev/nvme0n1p3',
        'BOOT_DEVICE' => '/dev/md1',
        'SWAP_DEVICE' => '/dev/nvme0n1p1',
    ];

    $spec = [];

    $root = trim((string) (getenv('ROOT_DEVICE') ?: $defaults['ROOT_DEVICE']));
    if ($root !== '') {
        $spec[] = ['mount' => '/', 'original_mount' => '/', 'device' => $root, 'type' => 'ext4', 'options' => 'errors=remount-ro', 'pass' => 1];
    }

    $home = getenv('HOME_DEVICE');
    if ($home !== false) {
        $homeValue = trim((string) $home);
        if ($homeValue !== '' && strcasecmp($homeValue, 'omit') !== 0) {
            $spec[] = ['mount' => '/home', 'original_mount' => '/home', 'device' => $homeValue, 'type' => 'ext4', 'options' => 'defaults', 'pass' => 2];
        }
    }

    $boot = trim((string) (getenv('BOOT_DEVICE') ?: $defaults['BOOT_DEVICE']));
    if ($boot !== '') {
        $spec[] = ['mount' => '/boot', 'original_mount' => '/boot', 'device' => $boot, 'type' => 'ext4', 'options' => 'defaults', 'pass' => 2];
    }

    $swap = trim((string) (getenv('SWAP_DEVICE') ?: $defaults['SWAP_DEVICE']));
    if ($swap !== '') {
        $spec[] = ['mount' => 'swap', 'original_mount' => 'swap', 'device' => $swap, 'type' => 'swap', 'options' => 'sw', 'pass' => 0];
    }

    return $spec;
}

/**
 * Maintain legacy environment variables for compatibility.
 */
function setLegacyEnvFromMounts(array $normalizedMounts): void
{
    $seen = [
        'ROOT_DEVICE' => false,
        'HOME_DEVICE' => false,
        'BOOT_DEVICE' => false,
        'SWAP_DEVICE' => false,
    ];

    foreach ($normalizedMounts as $entry) {
        if ($entry['is_swap']) {
            setEnvironmentValue('SWAP_DEVICE', $entry['device']);
            $seen['SWAP_DEVICE'] = true;
            continue;
        }

        if ($entry['mount'] === '/') {
            setEnvironmentValue('ROOT_DEVICE', $entry['device']);
            $seen['ROOT_DEVICE'] = true;
        } elseif ($entry['mount'] === '/home') {
            setEnvironmentValue('HOME_DEVICE', $entry['device']);
            $seen['HOME_DEVICE'] = true;
        } elseif ($entry['mount'] === '/boot') {
            setEnvironmentValue('BOOT_DEVICE', $entry['device']);
            $seen['BOOT_DEVICE'] = true;
        }
    }

    foreach ($seen as $env => $matched) {
        if ($matched) {
            continue;
        }
        putenv($env);
        unset($_ENV[$env]);
    }
}

$args = $argv;
array_shift($args);
// Remove the script name from the argument list for easier handling.

[$cliOptions, $args, $multiOptions] = parseArguments($args);
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
$skipTaskSet = [];
$skipTaskDisplay = [];
if ($skipSource !== '') {
    $parts = preg_split('/[,\s]+/', $skipSource) ?: [];
    foreach ($parts as $part) {
        $normalized = strtolower(trim((string) $part));
        if ($normalized === '') {
            continue;
        }
        if (!in_array($normalized, $skipTaskDisplay, true)) {
            $skipTaskDisplay[] = $normalized;
        }

        $candidates = [$normalized];
        $base = basename($normalized);
        if ($base !== '' && $base !== $normalized) {
            $candidates[] = $base;
        }

        foreach ($candidates as $candidate) {
            $skipTaskSet[$candidate] = true;
            if (substr($candidate, -4) !== '.php') {
                $skipTaskSet[$candidate . '.php'] = true;
            }
        }
    }
    if ($skipTaskDisplay !== []) {
        Common::logInfo(
            'Tasks disabled via MCX_SKIP_TASKS: ' . implode(', ', $skipTaskDisplay),
            ['skip_tasks' => $skipTaskDisplay]
        );
    }
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

$encodedMounts = json_encode($normalizedMounts);
if ($encodedMounts === false) {
    Common::fail('Unable to encode mount specification.');
}

setEnvironmentValue('MCX_MOUNT_SPEC', $encodedMounts);
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
