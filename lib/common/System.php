<?php
declare(strict_types=1);

namespace Lib\Common;

use RuntimeException;

// System centralises small system utility helpers that multiple provisioning scripts share.
final class System
{
    // commandExists checks whether a binary is available in PATH.
    public static function commandExists(string $command): bool
    {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    // requireRoot stops execution unless we run with root privileges.
    public static function requireRoot(): void
    {
        if (function_exists('posix_geteuid')) {
            if (posix_geteuid() === 0) {
                return;
            }
        } elseif (getenv('USER') === 'root') {
            return;
        }

        Logging::error('Root privileges are required for provisioning steps.');
        exit(1);
    }

    // run executes a command and optionally terminates when it fails.
    public static function run(array $command, bool $allowFailure = true): int
    {
        $commandLine = self::buildCommand($command);
        passthru($commandLine, $status);
        if (!$allowFailure && $status !== 0) {
            Logging::error(sprintf('Command failed: %s (exit %d)', $commandLine, $status));
            exit($status);
        }
        return $status;
    }

    // capture executes a command and returns its trimmed stdout output.
    public static function capture(array $command, ?int &$status = null): string
    {
        $commandLine = self::buildCommand($command);
        $output = [];
        exec($commandLine, $output, $exitCode);
        $status = $exitCode;
        return trim(implode("\n", $output));
    }

    // runIfCommandExists executes when the command is present, logging a warning otherwise.
    public static function runIfCommandExists(string $command, array $args = []): bool
    {
        if (!self::commandExists($command)) {
            Logging::warn(sprintf('Skipped %s because it is not installed.', $command));
            return false;
        }

        $status = self::run(array_merge([$command], $args));
        if ($status !== 0) {
            Logging::warn(sprintf('%s exited with status %d.', $command, $status));
            return false;
        }
        return true;
    }

    // runWithEnv executes a binary while overriding selected environment variables.
    public static function runWithEnv(string $executable, array $environment, array $args = [], bool $allowFailure = false): int
    {
        $command = array_merge(['env'], self::formatEnvironment($environment), [$executable], $args);
        return self::run($command, $allowFailure);
    }

    // isBlockDevice checks whether the provided path is a block device node.
    public static function isBlockDevice(string $path): bool
    {
        $stat = @stat($path);
        if ($stat === false) {
            return false;
        }
        return ($stat['mode'] & 0170000) === 0060000;
    }

    // buildCommand converts an argument vector into a shell-safe command string.
    private static function buildCommand(array $command): string
    {
        if ($command === []) {
            throw new RuntimeException('Command must contain at least one argument.');
        }
        return implode(' ', array_map('escapeshellarg', $command));
    }

    // formatEnvironment prepares env assignments for the env(1) helper.
    private static function formatEnvironment(array $environment): array
    {
        $formatted = [];
        foreach ($environment as $key => $value) {
            $formatted[] = sprintf('%s=%s', $key, $value);
        }
        return $formatted;
    }
}
