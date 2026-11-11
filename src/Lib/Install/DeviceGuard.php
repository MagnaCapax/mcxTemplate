<?php
declare(strict_types=1);

namespace Lib\Install;

// DeviceGuard closes the time-of-check/time-of-use gap (CWE-367). Kernel names
// can change when USB re-enumerates, so before EVERY destructive call the
// identity of the resolved device is read again and compared with the snapshot
// DiskMatcher accepted. Any difference aborts before the command runs.
final class DeviceGuard
{
    private const FIELDS = ['serial', 'wwn', 'model', 'sizeBytes', 'idPath', 'tran'];

    /**
     * @param array<string,mixed> $expected the matched disk record
     */
    public static function assertUnchanged(CommandRunner $runner, array $expected): void
    {
        $now = DiskInventory::enumerate($runner, (string) $expected['path']);
        if (count($now) !== 1 || $now[0]['kname'] !== $expected['kname']) {
            throw new InstallRefusal('deviceChanged', 'the device behind a planned path is gone or is not a single disk');
        }
        foreach (self::FIELDS as $field) {
            if ($now[0][$field] !== $expected[$field]) {
                throw new InstallRefusal('deviceChanged', "the $field behind a planned path changed since the match");
            }
        }
    }
}
