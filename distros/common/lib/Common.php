<?php
declare(strict_types=1);

namespace Distros\Common;

// Shared utility helpers for distro provisioning scripts.
final class Common
{
    // Keep logging consistent across every script for easy parsing.
    public static function logInfo(string $message): void
    {
        fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
    }

    // Provide a middle ground logging level for non-fatal warnings.
    public static function logWarn(string $message): void
    {
        fwrite(STDERR, '[WARN] ' . $message . PHP_EOL);
    }

    // Emit clear error messages that stand out from normal output.
    public static function logError(string $message): void
    {
        fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
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
    public static function runCommand(array $command, ?int &$status = null): string
    {
        $escaped = array_map('escapeshellarg', $command);
        $commandLine = implode(' ', $escaped) . ' 2>/dev/null';
        $outputLines = [];
        exec($commandLine, $outputLines, $exitCode);
        $status = $exitCode;
        return trim(implode("\n", $outputLines));
    }

    // Launch a PHP helper script and optionally treat failures as fatal errors.
    public static function runPhpScript(string $path, bool $failOnError = true): bool
    {
        if (!is_file($path)) {
            $message = 'Helper missing at ' . $path . '.';
            if ($failOnError) {
                self::fail($message);
            } else {
                self::logWarn($message);
                return false;
            }
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
        // Use the current PHP binary so nested calls mirror the parent process.

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        // Keep all standard I/O wired through for seamless logging behaviour.

        $process = proc_open($command, $descriptorSpec, $pipes, dirname($path));
        if (!is_resource($process)) {
            if ($failOnError) {
                self::fail('Failed to launch helper ' . basename($path) . '.');
            } else {
                self::logWarn('Failed to launch helper ' . basename($path) . '.');
                return false;
            }
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
                self::fail($message);
            } else {
                self::logWarn($message);
                return false;
            }
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
}
