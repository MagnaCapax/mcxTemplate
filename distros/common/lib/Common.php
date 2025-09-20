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

    // Validate hostnames against a conservative RFC 1123 inspired pattern.
    public static function isValidHostname(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $candidate);
    }
}
