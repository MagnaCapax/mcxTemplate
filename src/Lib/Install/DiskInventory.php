<?php
declare(strict_types=1);

namespace Lib\Install;

// DiskInventory reads the live disk list through the injected CommandRunner
// (lsblk JSON, then udevadm per disk). It only reads. Tests feed fixtures.
final class DiskInventory
{
    private const COLUMNS = 'NAME,KNAME,TYPE,SIZE,SERIAL,WWN,MODEL,TRAN,RM,HOTPLUG,PTTYPE';

    /**
     * Enumerate whole disks. With $onlyPath only that device is described
     * (used to re-read identity right before a destructive call).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function enumerate(CommandRunner $runner, ?string $onlyPath = null): array
    {
        $argv = ['lsblk', '-J', '-b', '-d', '-o', self::COLUMNS];
        if ($onlyPath !== null) {
            $argv[] = $onlyPath;
        }
        $result = $runner->run($argv);
        if ($result['code'] !== 0) {
            throw new InstallRefusal('inventoryFailed', 'lsblk exited non-zero');
        }
        return self::parse($result['out'], $runner);
    }

    /**
     * Parse lsblk JSON and enrich every disk with udev properties.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parse(string $json, CommandRunner $runner): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['blockdevices']) || !is_array($data['blockdevices'])) {
            throw new InstallRefusal('inventoryFailed', 'lsblk output is not the expected JSON');
        }

        $disks = [];
        foreach ($data['blockdevices'] as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'disk') {
                continue; // loop, rom, md and friends are never install targets
            }
            $kname = (string) ($row['kname'] ?? $row['name'] ?? '');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', $kname) !== 1) {
                throw new InstallRefusal('inventoryFailed', 'a disk has an unusable kernel name');
            }
            $udev = self::udev($runner, '/dev/' . $kname);
            $disks[] = [
                'kname' => $kname,
                'path' => '/dev/' . $kname,
                'sizeBytes' => self::intval($row['size'] ?? 0),
                'serial' => trim((string) ($row['serial'] ?? '')),
                'wwn' => trim((string) ($row['wwn'] ?? '')),
                'model' => trim((string) ($row['model'] ?? '')),
                'tran' => strtolower(trim((string) ($row['tran'] ?? ''))),
                'removable' => self::flag($row['rm'] ?? false),
                'hotplug' => self::flag($row['hotplug'] ?? false),
                'pttype' => trim((string) ($row['pttype'] ?? '')),
                'idPath' => $udev['ID_PATH'] ?? '',
                'usbVendorId' => strtolower($udev['ID_VENDOR_ID'] ?? ''),
                'idSerialShort' => $udev['ID_SERIAL_SHORT'] ?? '',
            ];
        }
        return $disks;
    }

    /** @return array<string,string> */
    private static function udev(CommandRunner $runner, string $path): array
    {
        $result = $runner->run(['udevadm', 'info', '--query=property', '--name=' . $path]);
        $props = [];
        if ($result['code'] !== 0) {
            return $props; // empty properties; the matcher refuses when it needs them
        }
        foreach (explode("\n", $result['out']) as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $props[substr($line, 0, $pos)] = trim(substr($line, $pos + 1));
            }
        }
        return $props;
    }

    // intval accepts both numeric strings and ints (lsblk -b differs by version).
    private static function intval($v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    // flag normalises true/false, 0/1 and "0"/"1" to bool.
    private static function flag($v): bool
    {
        return $v === true || $v === 1 || $v === '1';
    }
}
