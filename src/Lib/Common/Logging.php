<?php
declare(strict_types=1);

namespace Lib\Common;

// Logging provides lightweight timestamped logging helpers for provisioning tasks.
final class Logging
{
    // formatTime emits a UTC timestamp so log parsing stays consistent across hosts.
    private static function formatTime(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    // logWithLevel standardises the log line format for every severity.
    private static function logWithLevel(string $level, string $message): void
    {
        $line = sprintf('%s [%s] %s', self::formatTime(), $level, $message);
        fwrite(STDOUT, $line . PHP_EOL);
    }

    // info reports normal progress updates.
    public static function info(string $message): void
    {
        self::logWithLevel('INFO', $message);
    }

    // warn highlights recoverable problems that deserve attention.
    public static function warn(string $message): void
    {
        self::logWithLevel('WARN', $message);
    }

    // error records unrecoverable issues before the caller decides how to exit.
    public static function error(string $message): void
    {
        self::logWithLevel('ERROR', $message);
    }
}
