<?php
declare(strict_types=1);

namespace Lib\Provisioning;

// Shared utility helpers for distro provisioning scripts.
class Common
{
    /**
     * Prefix used for structured log entries when commands start/finish.
     */
    private const COMMAND_START = 'command-start';
    private const COMMAND_FINISH = 'command-finish';

    /**
     * Keep logging consistent across every script for easy parsing.
     */
    public static function logInfo(string $message, array $context = []): void
    {
        self::writeLog(STDOUT, '[INFO] ', $message);
        self::structuredLog('info', $message, $context);
    }

    /**
     * Provide a middle ground logging level for non-fatal warnings.
     */
    public static function logWarn(string $message, array $context = []): void
    {
        self::writeLog(STDERR, '[WARN] ', $message);
        self::structuredLog('warn', $message, $context);
    }

    /**
     * Emit clear error messages that stand out from normal output.
     */
    public static function logError(string $message, array $context = []): void
    {
        self::writeLog(STDERR, '[ERROR] ', $message);
        self::structuredLog('error', $message, $context);
    }

    /**
     * Stop execution when we encounter an unrecoverable problem.
     */
    public static function fail(string $message, array $context = []): void
    {
        self::logError($message, $context);
        exit(1);
    }

    /**
     * Guard that required environment variables are present and non-empty.
     */
    public static function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            self::fail("Environment variable '{$name}' must be set.");
        }
        return trim((string) $value);
    }

    /**
     * Confirm that the script is executing with root privileges.
     */
    public static function ensureRoot(): void
    {
        if (function_exists('posix_geteuid')) {
            if (posix_geteuid() !== 0) {
                self::fail('This action requires root privileges.');
            }
        } elseif (getenv('USER') !== 'root') {
            self::fail('Root privileges are required.');
        }
    }

    /**
     * Provide a simple command existence check using the system shell.
     */
    public static function commandExists(string $command): bool
    {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    /**
     * Execute a command when present while logging a warning if it is missing.
     */
    public static function runIfCommandExists(string $command, array $arguments = []): bool
    {
        if (!self::commandExists($command)) {
            self::logWarn('Skipped ' . $command . ' because it is not installed.');
            return false;
        }

        $escapedArguments = array_map('escapeshellarg', $arguments);
        $commandLine = escapeshellarg($command);
        if ($escapedArguments !== []) {
            $commandLine .= ' ' . implode(' ', $escapedArguments);
        }

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($commandLine, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            self::logWarn('Failed to launch ' . $command . '.');
            return false;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_close($process);
        if ($status !== 0) {
            self::logWarn($command . ' exited with status ' . (string) $status . '.');
            return false;
        }

        return true;
    }

    /**
     * Reuse a single wrapper for command execution across scripts.
     */
    public static function runCommand(array $command, ?int &$status = null, array $context = [], bool $log = false): string
    {
        $start = microtime(true);
        $status = 0;

        $commandString = implode(' ', $command);
        $context += ['command' => $commandString];

        if ($log) {
            self::logInfo('Running command.', $context + ['event' => self::COMMAND_START]);
        } else {
            self::structuredLog('info', 'Running command.', $context + ['event' => self::COMMAND_START]);
        }

        $escaped = array_map('escapeshellarg', $command);
        $commandLine = implode(' ', $escaped) . ' 2>/dev/null';
        $outputLines = [];
        exec($commandLine, $outputLines, $exitCode);
        $status = $exitCode;

        $result = trim(implode("\n", $outputLines));
        $duration = microtime(true) - $start;
        $finishContext = $context + ['event' => self::COMMAND_FINISH, 'exit_code' => $exitCode, 'duration_seconds' => round($duration, 4)];

        if ($exitCode !== 0) {
            self::logWarn('Command exited with non-zero status.', $finishContext);
        } else {
            if ($log) {
                self::logInfo('Command completed successfully.', $finishContext);
            } else {
                self::structuredLog('info', 'Command completed successfully.', $finishContext);
            }
        }

        return $result;
    }

    /**
     * Launch a PHP helper script and optionally treat failures as fatal errors.
     */
    public static function runPhpScript(string $path, array $args = [], bool $failOnError = true): bool
    {
        if (!is_file($path)) {
            $message = 'Helper missing at ' . $path . '.';
            self::logWarn($message, ['helper' => basename($path)]);
            return false;
        }

        $commandParts = [escapeshellarg(PHP_BINARY), escapeshellarg($path)];
        foreach ($args as $argument) {
            $commandParts[] = escapeshellarg((string) $argument);
        }
        $command = implode(' ', $commandParts);

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, dirname($path));
        if (!is_resource($process)) {
            $message = 'Failed to launch helper ' . basename($path) . '.';
            self::logWarn($message, ['helper' => basename($path)]);
            return false;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_close($process);
        if ($status !== 0) {
            $message = 'Helper ' . basename($path) . ' exited with status ' . (string) $status . '.';
            self::logWarn($message, ['helper' => basename($path), 'exit_code' => $status]);
            if ($failOnError) {
                return false;
            }
            return false;
        }

        return true;
    }

    /**
     * Validate hostnames against a conservative RFC 1123 inspired pattern.
     */
    public static function isValidHostname(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $candidate);
    }

    /**
     * Recursively empty directory contents without deleting the root.
     */
    public static function emptyDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $handle = @opendir($directory);
        if ($handle === false) {
            return;
        }

        while (false !== ($entry = readdir($handle))) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::emptyDirectory($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        closedir($handle);
    }

    /**
     * Locate a template file by checking the active distro templates and shared fallbacks.
     */
    public static function findTemplate(string $relativePath, ?string $distroId = null): ?string
    {
        $relative = ltrim($relativePath, '/');
        foreach (self::templateRoots($distroId) as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Render a template file using simple placeholder substitution.
     */
    public static function renderTemplateFromFile(string $templatePath, array $replacements): string
    {
        $content = @file_get_contents($templatePath);
        if ($content === false) {
            self::fail('Unable to read template ' . $templatePath . '.');
        }

        if ($replacements === []) {
            return $content;
        }

        $search = array_keys($replacements);
        $replace = array_values($replacements);
        return str_replace($search, $replace, $content);
    }

    /**
     * Apply a template to a destination file.
     */
    public static function applyTemplateToFile(string $templatePath, string $destination, array $replacements): void
    {
        $rendered = self::renderTemplateFromFile($templatePath, $replacements);
        if (@file_put_contents($destination, $rendered) === false) {
            self::fail('Unable to write ' . $destination . '.');
        }

        $hash = hash('sha256', $rendered);
        self::structuredLog('info', 'Template applied to file.', [
            'event' => 'template-apply',
            'template' => $templatePath,
            'destination' => $destination,
            'sha256' => $hash,
        ]);
    }

    /**
     * Detects a likely primary interface, falling back to a provided default.
     */
    public static function detectPrimaryInterface(string $fallback = 'eth0'): string
    {
        $envInterface = getenv('MCX_PRIMARY_INTERFACE');
        if ($envInterface !== false && trim((string) $envInterface) !== '') {
            return trim((string) $envInterface);
        }

        if (self::commandExists('ip')) {
            $status = 0;
            $routeOutput = self::runCommand(['ip', 'route', 'show', 'default'], $status);
            if ($status === 0 && $routeOutput !== '') {
                if (preg_match('/\bdev\s+(\S+)/', $routeOutput, $match) === 1) {
                    return $match[1];
                }
            }

            $linkStatus = 0;
            $linkOutput = self::runCommand(['ip', '-o', 'link', 'show'], $linkStatus);
            if ($linkStatus === 0 && $linkOutput !== '') {
                foreach (preg_split('/\r?\n/', $linkOutput) ?: [] as $line) {
                    if (preg_match('/^\d+:\s*([^:]+):/', (string) $line, $match) !== 1) {
                        continue;
                    }
                    $candidate = $match[1];
                    if ($candidate === 'lo') {
                        continue;
                    }
                    return $candidate;
                }
            }
        }

        return $fallback;
    }

    /**
     * Produce candidate template roots based on the repository layout.
     */
    private static function templateRoots(?string $distroId = null): array
    {
        $roots = [];
        $repoRoot = getenv('MCX_TEMPLATE_ROOT');
        if ($repoRoot !== false && trim((string) $repoRoot) !== '') {
            $repo = rtrim((string) $repoRoot, DIRECTORY_SEPARATOR);
            $distro = strtolower(trim((string) ($distroId ?? getenv('MCX_DISTRO_ID') ?: '')));
            if ($distro !== '') {
                $roots[] = $repo . DIRECTORY_SEPARATOR . 'distros' . DIRECTORY_SEPARATOR . $distro . DIRECTORY_SEPARATOR . 'templates';
            }
            $roots[] = $repo . DIRECTORY_SEPARATOR . 'distros' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'templates';
        }
        return $roots;
    }

    private static function writeLog($stream, string $prefix, string $message): void
    {
        fwrite($stream, $prefix . $message . PHP_EOL);
    }

    private static function structuredLog(string $level, string $message, array $context = []): void
    {
        $target = getenv('MCX_STRUCTURED_LOG');
        if ($target === false) {
            return;
        }

        $target = trim((string) $target);
        if ($target === '') {
            return;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $record = [
            'timestamp' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== []) {
            $record['context'] = $context;
        }

        @file_put_contents(
            $target,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
