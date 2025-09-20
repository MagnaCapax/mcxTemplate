<?php
declare(strict_types=1);

namespace Common\Lib;

use Lib\Provisioning\Common as ProvisioningCommon;
use RuntimeException;

// CommonHelpers exposes a narrow facade over the shared provisioning helpers.
final class CommonHelpers
{
    // logInfo mirrors the simple INFO logger expected by provisioning scripts.
    public static function logInfo(string $message): void
    {
        ProvisioningCommon::logInfo($message);
    }

    // logWarn mirrors the WARN logger.
    public static function logWarn(string $message): void
    {
        ProvisioningCommon::logWarn($message);
    }

    // logError mirrors the ERROR logger.
    public static function logError(string $message): void
    {
        ProvisioningCommon::logError($message);
    }

    // die reports the error then stops execution with the provided code (default 1).
    public static function die(string $message, int $code = 1): void
    {
        ProvisioningCommon::logError($message);
        exit($code);
    }

    // requireCommand ensures a binary exists before the caller relies on it.
    public static function requireCommand(string $command): void
    {
        if (ProvisioningCommon::commandExists($command)) {
            return;
        }

        self::die(sprintf("Required command '%s' is not available.", $command));
    }

    // requireReadableFile verifies that the provided path exists and is readable.
    public static function requireReadableFile(string $path): void
    {
        if (!is_readable($path)) {
            self::die(sprintf("Required file '%s' is missing or unreadable.", $path));
        }
    }

    // requireNonEmpty validates that a value is non-empty.
    public static function requireNonEmpty(string $value, ?string $name = null): void
    {
        if ($value !== '') {
            return;
        }

        $label = $name ?? 'value';
        self::die(sprintf("Required value '%s' is empty.", $label));
    }

    private function __construct()
    {
        throw new RuntimeException('CommonHelpers may not be instantiated.');
    }
}
