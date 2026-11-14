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
     * @param string              $role     the plan role of the disk (root, boot0, boot1): it names the device in the
     *                                       refusal, which is reported to nodeCore, so the message never carries a serial
     */
    public static function assertUnchanged(CommandRunner $runner, array $expected, string $role = ''): void
    {
        $what = $role === '' ? 'a planned path' : 'the ' . $role . ' disk';
        $now = DiskInventory::enumerate($runner, (string) $expected['path']);
        if (count($now) !== 1 || $now[0]['kname'] !== $expected['kname']) {
            throw new InstallRefusal('deviceChanged', 'the device behind ' . $what . ' is gone or is not a single disk');
        }
        foreach (self::FIELDS as $field) {
            if ($now[0][$field] !== $expected[$field]) {
                throw new InstallRefusal('deviceChanged', "the $field behind $what changed since the match");
            }
        }
    }
}
