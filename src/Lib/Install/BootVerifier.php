<?php
declare(strict_types=1);

namespace Lib\Install;

// BootVerifier is the read-only check after the chroot configuration and before
// the install is reported as done. Several boot tasks log a failure and still
// exit 0 (grub-install, update-initramfs, update-grub, the fstab helper, mdadm
// capture), so an exit code alone cannot prove the node will boot (CWE-636).
// Every check reads files under the target root or runs a read-only command
// (blkid, dd of=-less read of one sector, mdadm --detail) through the READER runner.
// Any miss is a verifyFailed refusal that names the check.
// Tasks 21 and 22 (hostname, network) also log a warning and exit 0 when their helper fails in a non-strict run, so the
// hostname and the static address are read back from the installed files too (ADR-0011).
final class BootVerifier
{
    public const SECTOR = 512;

    /**
     * @param array<string,mixed> $step verify step: uuids (planned fstab UUIDs), grubTargets, md, hostname and cidr (as requested)
     */
    public static function verify(CommandRunner $reader, string $targetRoot, array $step): void
    {
        $root = rtrim($targetRoot, '/');
        self::kernel($root);
        self::grubConfig($root);
        self::fstab($reader, $root, $step['uuids']);
        self::hostname($root, $step['hostname'] ?? null);
        self::network($root, $step['cidr'] ?? null);
        foreach ($step['grubTargets'] as $device) {
            self::bootCode($reader, (string) $device);
        }
        if ($step['md'] === true) {
            self::mdadmConf($root);
            self::bootArray($reader);
        }
    }

    private static function fail(string $check, string $detail): never
    {
        throw new InstallRefusal('verifyFailed', $check . ': ' . $detail);
    }

    // kernel: at least one /boot/vmlinuz-<version> with a matching /boot/initrd.img-<version>.
    private static function kernel(string $root): void
    {
        $kernels = glob($root . '/boot/vmlinuz-*') ?: [];
        if ($kernels === []) {
            self::fail('kernel', 'no /boot/vmlinuz-* in the target');
        }
        foreach ($kernels as $kernel) {
            $initrd = $root . '/boot/initrd.img-' . substr(basename($kernel), strlen('vmlinuz-'));
            if (is_file($kernel) && filesize($kernel) > 0 && is_file($initrd) && filesize($initrd) > 0) {
                return;
            }
        }
        self::fail('initrd', 'no kernel in /boot has a non-empty matching initrd.img');
    }

    // grubConfig: update-grub's result; its failure is swallowed by task 40.
    private static function grubConfig(string $root): void
    {
        $cfg = $root . '/boot/grub/grub.cfg';
        if (!is_file($cfg) || filesize($cfg) === 0) {
            self::fail('grubConfig', '/boot/grub/grub.cfg is missing or empty');
        }
    }

    /**
     * fstab: every planned UUID is in the target fstab, and every UUID the fstab
     * names exists on this machine now (blkid without its cache).
     *
     * @param array<int,string> $planned
     */
    private static function fstab(CommandRunner $reader, string $root, array $planned): void
    {
        $text = @file_get_contents($root . '/etc/fstab');
        if (!is_string($text)) {
            self::fail('fstab', '/etc/fstab is missing in the target');
        }
        $named = [];
        foreach (explode("\n", (string) $text) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (($fields[0] ?? '') !== '' && $fields[0][0] !== '#' && strncmp($fields[0], 'UUID=', 5) === 0) {
                $named[] = strtolower(substr($fields[0], 5));
            }
        }
        foreach ($planned as $uuid) {
            if (!in_array(strtolower($uuid), $named, true)) {
                self::fail('fstab', 'a planned filesystem UUID is not in the target fstab');
            }
        }
        $blkid = $reader->run(['blkid', '-c', '/dev/null', '-o', 'value', '-s', 'UUID']);
        $present = array_map('strtolower', array_filter(array_map('trim', explode("\n", $blkid['out']))));
        if ($blkid['code'] !== 0 || $present === []) {
            self::fail('fstab', 'blkid listed no filesystem UUIDs');
        }
        foreach ($named as $uuid) {
            if (!in_array($uuid, $present, true)) {
                self::fail('fstab', 'an fstab UUID does not exist on any device');
            }
        }
    }

    // hostname: /etc/hostname holds the requested hostname (hostname.tpl writes it alone on the line).
    // A task whose helper failed leaves the template's own name behind. Compared whole and without regard to case.
    private static function hostname(string $root, ?string $requested): void
    {
        if ($requested === null || $requested === '') {
            self::fail('hostname', 'the verify step carries no requested hostname');
        }
        $text = @file_get_contents($root . '/etc/hostname');
        if (!is_string($text)) {
            self::fail('hostname', '/etc/hostname is missing in the target');
        }
        if (strtolower(trim($text)) !== strtolower($requested)) {
            self::fail('hostname', '/etc/hostname does not hold the requested hostname');
        }
    }

    /**
     * network: the requested static address (CIDR) is configured in the installed system, as Debian's
     * /etc/network/interfaces ("address <cidr>" on a line of its own) or as an address in a netplan file
     * (Ubuntu). The match is on the whole token, so 192.0.2.100/24 does not stand in for 192.0.2.10/24, and a
     * comment line is never the configuration. A template that kept its own DHCP file has neither.
     */
    private static function network(string $root, ?string $cidr): void
    {
        if ($cidr === null || $cidr === '') {
            self::fail('network', 'the verify step carries no requested address');
        }
        $quoted = preg_quote($cidr, '#');
        $interfaces = @file_get_contents($root . '/etc/network/interfaces');
        if (is_string($interfaces) && preg_match('#^[ \t]*address[ \t]+' . $quoted . '[ \t]*$#m', $interfaces) === 1) {
            return;
        }
        foreach (glob($root . '/etc/netplan/*.yaml') ?: [] as $file) {
            $yaml = @file_get_contents($file);
            if (is_string($yaml) && preg_match('#^[ \t]*[^\#\n]*addresses:[ \t]*\[[^\]\#\n]*(?<![0-9./])' . $quoted . '(?![0-9])#m', $yaml) === 1) {
                return;
            }
        }
        self::fail('network', 'neither /etc/network/interfaces nor /etc/netplan holds the requested static address');
    }
    // bootCode: the first sector of a GRUB target carries the 0x55AA signature and GRUB's boot.img ("GRUB " text).
    private static function bootCode(CommandRunner $reader, string $device): void
    {
        if (preg_match('#^/dev/disk/by-path/[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$#D', $device) !== 1) {
            self::fail('bootCode', 'a GRUB target is not a by-path device');
        }
        $read = $reader->run(['dd', 'if=' . $device, 'bs=' . self::SECTOR, 'count=1', 'status=none']);
        $sector = $read['out'];
        if ($read['code'] !== 0 || strlen($sector) !== self::SECTOR) {
            self::fail('bootCode', 'the first sector of a GRUB target could not be read');
        }
        if (substr($sector, 510, 2) !== "\x55\xAA" || strpos(substr($sector, 0, 446), 'GRUB ') === false) {
            self::fail('bootCode', 'a GRUB target has no GRUB boot code in its first sector');
        }
    }

    /**
     * bootArray: the /boot mirror carries metadata 0.90 (ADR-0005). The rescue kernel cannot show what the target's
     * own, older kernel will accept: a 1.x superblock made by a kernel newer than 6.18 assembles here and is refused
     * there, so the format is checked instead of an assemble test.
     */
    private static function bootArray(CommandRunner $reader): void
    {
        $detail = $reader->run(['mdadm', '--detail', '--export', '/dev/md/mcxboot']);
        if ($detail['code'] !== 0 || preg_match('/^MD_METADATA=0\.90$/m', $detail['out']) !== 1) {
            self::fail('bootArray', 'the /boot mirror does not carry metadata 0.90');
        }
    }

    // mdadmConf: the /boot mirror must be named in the target's mdadm.conf, or the initramfs may not assemble it.
    private static function mdadmConf(string $root): void
    {
        $text = @file_get_contents($root . '/etc/mdadm/mdadm.conf');
        if (!is_string($text) || preg_match('/^ARRAY\s.*mcxboot/m', $text) !== 1) {
            self::fail('mdadmConf', '/etc/mdadm/mdadm.conf does not list the mcxboot array');
        }
    }
}
