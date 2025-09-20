<?php
declare(strict_types=1);

namespace Distros\Common;

// Shared utility helpers for distro provisioning scripts.
final class Common
{
    // Keep logging consistent across every script for easy parsing.
    public static function logInfo(string $message, array $context = []): void
    {
        self::writeLog(STDOUT, '[INFO] ', $message);
        self::structuredLog('info', $message, $context);
    }

    // Provide a middle ground logging level for non-fatal warnings.
    public static function logWarn(string $message, array $context = []): void
    {
        self::writeLog(STDERR, '[WARN] ', $message);
        self::structuredLog('warn', $message, $context);
    }

    // Emit clear error messages that stand out from normal output.
    public static function logError(string $message, array $context = []): void
    {
        self::writeLog(STDERR, '[ERROR] ', $message);
        self::structuredLog('error', $message, $context);
    }

    // Stop execution when we encounter an unrecoverable problem.
    public static function fail(string $message): void
    {
        self::logError($message);
        exit(1);
    }

    // Guard that required environment variables are present and non-empty.
    public static function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            self::fail("Environment variable '{$name}' must be set.");
        }
        return trim((string) $value);
    }

    // Confirm that the script is executing with root privileges.
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

    // Provide a simple command existence check using the system shell.
    public static function commandExists(string $command): bool
    {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    // Execute a command when present while logging a warning if it is missing.
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
        // Assemble the command line explicitly to avoid shell injection issues.

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        // Pass through standard streams so callers inherit command output.

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
        // Close pipes early to avoid descriptor leaks across repeated calls.

        $status = proc_close($process);
        if ($status !== 0) {
            self::logWarn($command . ' exited with status ' . (string) $status . '.');
            return false;
        }

        return true;
    }

    // Reuse a single wrapper for command execution across scripts.
    public static function runCommand(array $command, ?int &$status = null, array $context = [], bool $log = false): string
    {
        $start = microtime(true);
        $status = 0;

        $commandString = implode(' ', $command);
        $context += ['command' => $commandString];

        if ($log) {
            self::logInfo('Running command.', $context + ['event' => 'command-start']);
        } else {
            self::structuredLog('info', 'Running command.', $context + ['event' => 'command-start']);
        }

        $escaped = array_map('escapeshellarg', $command);
        $commandLine = implode(' ', $escaped) . ' 2>/dev/null';
        $outputLines = [];
        exec($commandLine, $outputLines, $exitCode);
        $status = $exitCode;

        $result = trim(implode("\n", $outputLines));
        $duration = microtime(true) - $start;
        $finishContext = $context + ['event' => 'command-finish', 'exit_code' => $exitCode, 'duration_seconds' => round($duration, 4)];

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

    // Launch a PHP helper script and optionally treat failures as fatal errors.
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
        // Use the current PHP binary so nested calls mirror the parent process.

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        // Keep all standard I/O wired through for seamless logging behaviour.

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
        // Ensure descriptors close even when helpers run for extended periods.

        $status = proc_close($process);
        if ($status !== 0) {
            $message = 'Helper ' . basename($path) . ' exited with status ' . (string) $status . '.';
            if ($failOnError) {
                self::logWarn($message, ['helper' => basename($path), 'exit_code' => $status]);
                return false;
            }
            self::logWarn($message, ['helper' => basename($path), 'exit_code' => $status]);
            return false;
        }

        return true;
    }

    // Validate hostnames against a conservative RFC 1123 inspired pattern.
    public static function isValidHostname(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $candidate);
    }

    // Recursively empty directory contents without deleting the root.
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
