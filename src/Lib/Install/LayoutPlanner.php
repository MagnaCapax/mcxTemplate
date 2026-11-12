<?php
declare(strict_types=1);

namespace Lib\Install;

// LayoutPlanner turns (plan, matched devices) into an ORDERED list of steps. It
// is pure: it runs nothing and reads nothing. Every command is an argv array.
// Filesystem and swap UUIDs are chosen up front (mkfs -U / mkswap -U) so the
// whole command list, including the fstab UUIDs, is known before the first
// write. A dry run prints the same command list an execute run would follow;
// the UUIDs themselves are drawn fresh on each run.
//
// Step shape: id, argv, destructive (bool), roles (disks whose identity must be
// re-read right before the step), env (extra environment), and optionally
// kind ('writeFile', 'password', 'configure', 'verify') for steps the executor
// handles specially.
final class LayoutPlanner
{
    public const TARGET = '/mnt/target';
    private const MIB = 1048576;
    private const BOOT_MIB = 1024;     // biosSingle /boot
    private const SWAP_MIB = 4096;     // swap in both layouts
    private const ROOT_SPLIT_MIB = 102400; // root size when homeSplit is on (100 GiB)
    private const STICK_BOOT_MIB = 2048;   // /boot RAID1 partition on each stick
    private const MD_BOOT = '/dev/md/mcxboot';
    // The chroot child gets exactly this environment plus GRUB_TARGETS, nothing inherited from the
    // rescue shell, so a stray MCX_DRY_RUN or MCX_SKIP_TASKS there cannot make configure skip work.
    // USER=root keeps Common::ensureRoot() working when the template's PHP lacks the posix extension.
    public const CHROOT_ENV = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C',
        'USER' => 'root', 'HOME' => '/root'];

    /**
     * Files the layout cannot boot without, checked in the template listing before any write (ArchiveCheck::checkEntries).
     * biosNvmeUsbBoot keeps /boot on an md RAID1: the installed system needs mdadm to assemble it, and configure task 32
     * writes mdadm.conf only when mdadm exists (it skips with a warning otherwise, which BootVerifier then refuses).
     *
     * @return array<int,array{code:string,paths:array<int,string>}>
     */
    public static function templateNeeds(string $layout): array
    {
        return $layout === 'biosNvmeUsbBoot' ? [['code' => 'templateLacksMdadm', 'paths' => ['usr/sbin/mdadm', 'sbin/mdadm']]] : [];
    }

    /**
     * @param array<string,mixed> $plan  validated plan
     * @param array<string,mixed> $match  DiskMatcher::match result with ok=true
     * @param callable(): string|null $uuid UUID generator (tests inject a counter)
     * @return array<int,array<string,mixed>>
     */
    public static function plan(array $plan, array $match, ?callable $uuid = null): array
    {
        if (($match['ok'] ?? false) !== true) {
            throw new InstallRefusal('planInvalid', 'layout planning needs a successful disk match');
        }
        $uuid = $uuid ?? [self::class, 'uuidV4'];
        $roles = $match['roles'];
        $steps = [];

        foreach ($match['stopArrays'] ?? [] as $array) {
            if (preg_match('/^md[0-9]+$/D', (string) $array) !== 1) {
                throw new InstallRefusal('planInvalid', 'unexpected md array name');
            }
            $steps[] = self::step('stopArray', ['mdadm', '--stop', '/dev/' . $array], true, array_keys($roles));
        }

        if ($plan['layout'] === 'biosSingle') {
            $fs = self::biosSingle($plan, $roles['root'], $uuid, $steps);
        } elseif ($plan['layout'] === 'biosNvmeUsbBoot') {
            $fs = self::biosNvmeUsbBoot($roles, $uuid, $steps);
        } else {
            throw new InstallRefusal('planInvalid', 'unknown layout');
        }

        self::finish($plan, $fs, $steps);
        return $steps;
    }

    // uuidV4 builds a random RFC 4122 version 4 UUID.
    public static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    // partName: nvme0n1 -> nvme0n1p1, sda -> sda1.
    public static function partName(string $disk, int $n): string
    {
        self::assertDevicePath($disk);
        return $disk . (ctype_digit(substr($disk, -1)) ? 'p' : '') . $n;
    }

    /**
     * @param array<string,mixed>       $disk
     * @param array<int,array<string,mixed>> $steps
     * @return array<string,mixed> filesystem map consumed by finish()
     */
    private static function biosSingle(array $plan, array $disk, callable $uuid, array &$steps): array
    {
        $dev = $disk['path'];
        $sizeMib = intdiv($disk['sizeBytes'], self::MIB);
        $home = (bool) $plan['homeSplit'];
        if ($sizeMib < ($home ? 131072 : 20480)) {
            throw new InstallRefusal('layoutSizeRefused', 'root disk is too small for this layout');
        }
        self::wipeDisk($steps, 'root', $disk);

        $bootEnd = 1 + self::BOOT_MIB;
        $swapEnd = $bootEnd + self::SWAP_MIB;
        $parted = ['parted', '-s', '-a', 'optimal', $dev, 'mklabel', 'msdos',
            'mkpart', 'primary', 'ext4', '1MiB', $bootEnd . 'MiB',
            'mkpart', 'primary', 'linux-swap', $bootEnd . 'MiB', $swapEnd . 'MiB'];
        if ($home) {
            $rootEnd = $swapEnd + self::ROOT_SPLIT_MIB;
            array_push($parted, 'mkpart', 'primary', 'ext4', $swapEnd . 'MiB', $rootEnd . 'MiB',
                'mkpart', 'primary', 'ext4', $rootEnd . 'MiB', '100%');
        } else {
            array_push($parted, 'mkpart', 'primary', 'ext4', $swapEnd . 'MiB', '100%');
        }
        array_push($parted, 'set', '1', 'boot', 'on');
        $steps[] = self::step('partition', $parted, true, ['root']);
        self::settle($steps, $dev);

        $uu = ['boot' => $uuid(), 'swap' => $uuid(), 'root' => $uuid(), 'home' => $home ? $uuid() : ''];
        $count = $home ? 4 : 3;
        for ($n = 1; $n <= $count; $n++) {
            $steps[] = self::step('wipePartition' . $n, ['wipefs', '-a', self::partName($dev, $n)], true, ['root']);
        }
        $steps[] = self::step('mkfsBoot', ['mkfs.ext4', '-F', '-L', 'boot', '-U', $uu['boot'], self::partName($dev, 1)], true, ['root']);
        $steps[] = self::step('mkswap', ['mkswap', '-U', $uu['swap'], self::partName($dev, 2)], true, ['root']);
        $steps[] = self::step('mkfsRoot', ['mkfs.ext4', '-F', '-L', 'root', '-U', $uu['root'], self::partName($dev, 3)], true, ['root']);
        if ($home) {
            $steps[] = self::step('mkfsHome', ['mkfs.ext4', '-F', '-m', '0', '-L', 'home', '-U', $uu['home'], self::partName($dev, 4)], true, ['root']);
        }

        $devs = ['root' => self::partName($dev, 3), 'boot' => self::partName($dev, 1), 'home' => $home ? self::partName($dev, 4) : ''];

        return ['uuids' => $uu, 'devs' => $devs, 'mounts' => $home ? ['/', '/boot', '/home'] : ['/', '/boot'],
            'grub' => [self::stablePath($disk)], 'grubRoles' => ['root'], 'stopMd' => false];
    }

    /**
     * @param array<string,array<string,mixed>> $roles
     * @param array<int,array<string,mixed>>    $steps
     * @return array<string,mixed>
     */
    private static function biosNvmeUsbBoot(array $roles, callable $uuid, array &$steps): array
    {
        $root = $roles['root'];
        $sticks = [$roles['boot0'], $roles['boot1']];

        self::wipeDisk($steps, 'root', $root);
        self::wipeDisk($steps, 'boot0', $sticks[0]);
        self::wipeDisk($steps, 'boot1', $sticks[1]);

        // The big disk is GPT: it does not boot, so the BIOS 2 TiB MBR limit does not apply to it.
        $steps[] = self::step('partitionRoot', ['sgdisk', '-n', '1:0:+' . self::SWAP_MIB . 'M', '-t', '1:8200',
            '-n', '2:0:0', '-t', '2:8300', $root['path']], true, ['root']);
        foreach ([0, 1] as $i) {
            $steps[] = self::step('partitionStick' . $i, ['parted', '-s', '-a', 'optimal', $sticks[$i]['path'], 'mklabel', 'msdos',
                'mkpart', 'primary', '1MiB', (1 + self::STICK_BOOT_MIB) . 'MiB', 'set', '1', 'boot', 'on'], true, ['boot' . $i]);
        }
        self::settle($steps, $root['path']);
        self::settle($steps, $sticks[0]['path']);
        self::settle($steps, $sticks[1]['path']);

        $uu = ['boot' => $uuid(), 'swap' => $uuid(), 'root' => $uuid(), 'home' => ''];
        $steps[] = self::step('wipeRootPart1', ['wipefs', '-a', self::partName($root['path'], 1)], true, ['root']);
        $steps[] = self::step('wipeRootPart2', ['wipefs', '-a', self::partName($root['path'], 2)], true, ['root']);
        foreach ([0, 1] as $i) {
            $steps[] = self::step('wipeStickPart' . $i, ['wipefs', '-a', self::partName($sticks[$i]['path'], 1)], true, ['boot' . $i]);
        }
        // Metadata 1.2 matches the existing fleet's boot arrays.
        $steps[] = self::step('mdCreate', ['mdadm', '--create', self::MD_BOOT, '--run', '--level=1', '--raid-devices=2',
            '--metadata=1.2', '--name=mcxboot',
            self::partName($sticks[0]['path'], 1), self::partName($sticks[1]['path'], 1)], true, ['boot0', 'boot1']);
        $steps[] = self::step('mkfsBoot', ['mkfs.ext4', '-F', '-L', 'boot', '-U', $uu['boot'], self::MD_BOOT], true, ['boot0', 'boot1']);
        $steps[] = self::step('mkswap', ['mkswap', '-U', $uu['swap'], self::partName($root['path'], 1)], true, ['root']);
        $steps[] = self::step('mkfsRoot', ['mkfs.ext4', '-F', '-L', 'root', '-U', $uu['root'], self::partName($root['path'], 2)], true, ['root']);

        $devs = ['root' => self::partName($root['path'], 2), 'boot' => self::MD_BOOT, 'home' => ''];

        return ['uuids' => $uu, 'devs' => $devs, 'mounts' => ['/', '/boot'],
            'grub' => [self::stablePath($sticks[0]), self::stablePath($sticks[1])], 'grubRoles' => ['root', 'boot0', 'boot1'],
            'stopMd' => true];
    }

    /**
     * Mount, extract, configure in the chroot, then unmount. Shared by both layouts.
     *
     * @param array<string,mixed>            $plan
     * @param array<string,mixed>            $fs
     * @param array<int,array<string,mixed>> $steps
     */
    private static function finish(array $plan, array $fs, array &$steps): void
    {
        $t = self::TARGET;
        $u = $fs['uuids'];
        $d = $fs['devs'];
        // Mount by the partition just formatted, with the type named. A UUID= mount right after mkfs races udev:
        // mkfs closing the partition makes udev re-probe it, and until that finishes mount saw no filesystem type
        // (seen on a real NVMe: the mount failed 32 ms after mkfs and succeeded 28 ms later).
        $steps[] = self::step('mkdirTarget', ['mkdir', '-p', $t], false);
        $steps[] = self::step('mountRoot', ['mount', '-t', 'ext4', $d['root'], $t], false);
        $steps[] = self::step('mkdirBoot', ['mkdir', '-p', $t . '/boot'], false);
        $steps[] = self::step('mountBoot', ['mount', '-t', 'ext4', $d['boot'], $t . '/boot'], false);
        if ($u['home'] !== '') {
            $steps[] = self::step('mkdirHome', ['mkdir', '-p', $t . '/home'], false);
            $steps[] = self::step('mountHome', ['mount', '-t', 'ext4', $d['home'], $t . '/home'], false);
        }

        // The archive was already verified (size, sha256, layout) before the first write. The strip count comes from
        // that check: "./rootfs/x" members need 2, "rootfs/x" members 1, so rootfs/ lands on the target root.
        $strip = (int) ($plan['template']['stripComponents'] ?? 1);
        if ($strip !== 1 && $strip !== 2) {
            throw new InstallRefusal('templateLayoutRefused', 'unexpected strip count for the template archive');
        }
        $steps[] = self::step('extract', ['tar', '-xpzf', $plan['template']['path'], '--numeric-owner', '--xattrs', '--acls',
            '--strip-components=' . $strip, '-C', $t], false);

        $resolv = '';
        foreach ($plan['host']['resolvers'] as $ip) {
            $resolv .= 'nameserver ' . $ip . "\n";
        }
        $steps[] = ['id' => 'resolvConf', 'argv' => [], 'destructive' => false, 'roles' => [], 'env' => [],
            'kind' => 'writeFile', 'path' => '/etc/resolv.conf', 'content' => $resolv];

        // The executor generates the password, writes it to --secret-out and feeds only the hash to stdin.
        $steps[] = ['id' => 'rootPassword', 'argv' => ['chroot', $t, 'chpasswd', '-e'], 'destructive' => false,
            'roles' => [], 'env' => [], 'kind' => 'password'];

        $binds = [['/dev', '/dev'], ['/dev/pts', '/dev/pts'], ['/proc', '/proc'], ['/sys', '/sys'], ['/run', '/run']];
        foreach ($binds as [$src, $dst]) {
            $steps[] = self::step('bind' . str_replace('/', '', $dst), ['mount', '--bind', $src, $t . $dst], false);
        }

        $mounts = ['--mount=/,UUID=' . $u['root'], '--mount=swap,UUID=' . $u['swap'], '--mount=/boot,UUID=' . $u['boot']];
        if ($u['home'] !== '') {
            $mounts[] = '--mount=/home,UUID=' . $u['home'];
        }
        $configure = array_merge(['chroot', $t, 'php', '/opt/mcxTemplate/installTemplate.php', '--strict',
            '--hostname=' . $plan['host']['fqdn'], '--network-cidr=' . $plan['host']['cidr'], '--gateway=' . $plan['host']['gateway']], $mounts);
        // Stable by-path names, never sda/sdb: kernel names move when USB re-enumerates.
        // Destructive: grub-install inside writes raw sectors to the GRUB targets, so DeviceGuard re-reads
        // those disks (and the root disk the chroot lives on) right before it.
        $configureStep = self::step('configure', $configure, true, $fs['grubRoles'],
            self::CHROOT_ENV + ['GRUB_TARGETS' => implode(' ', $fs['grub'])]);
        $configureStep['kind'] = 'configure';
        $steps[] = $configureStep;

        // Read-only proof that the node can boot, while the target is still mounted (BootVerifier).
        $planned = array_values(array_filter([$u['root'], $u['boot'], $u['swap'], $u['home']], static fn (string $x): bool => $x !== ''));
        $steps[] = ['id' => 'verifyBoot', 'argv' => [], 'destructive' => false, 'roles' => [], 'env' => [], 'kind' => 'verify',
            'uuids' => $planned, 'grubTargets' => $fs['grub'], 'md' => $fs['stopMd']];

        foreach (array_reverse($binds) as [$src, $dst]) {
            $steps[] = self::step('unbind' . str_replace('/', '', $dst), ['umount', $t . $dst], false);
        }
        if ($u['home'] !== '') {
            $steps[] = self::step('umountHome', ['umount', $t . '/home'], false);
        }
        $steps[] = self::step('umountBoot', ['umount', $t . '/boot'], false);
        $steps[] = self::step('umountRoot', ['umount', $t], false);
        if ($fs['stopMd']) {
            $steps[] = self::step('mdStop', ['mdadm', '--stop', self::MD_BOOT], false);
        }
        $steps[] = self::step('sync', ['sync'], false);
    }

    /**
     * Wipe one disk: signatures, partition tables, first and last 16 MiB, re-read.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,mixed>            $disk
     */
    private static function wipeDisk(array &$steps, string $role, array $disk): void
    {
        $dev = $disk['path'];
        self::assertDevicePath($dev);
        $bytes = (int) $disk['sizeBytes'];
        if ($bytes < 64 * self::MIB) {
            throw new InstallRefusal('sizeMismatch', 'disk is too small to wipe safely');
        }
        $steps[] = self::step($role . 'Wipefs', ['wipefs', '-a', $dev], true, [$role]);
        $steps[] = self::step($role . 'Zap', ['sgdisk', '--zap-all', $dev], true, [$role]);
        $steps[] = self::step($role . 'ZeroHead', ['dd', 'if=/dev/zero', 'of=' . $dev, 'bs=1M', 'count=16', 'oflag=direct', 'conv=fsync'], true, [$role]);
        // Seek in bytes: disk sizes are rarely whole MiB, and a MiB seek would leave up to 1 MiB of the tail.
        $steps[] = self::step($role . 'ZeroTail', ['dd', 'if=/dev/zero', 'of=' . $dev, 'bs=1M', 'count=16', 'seek=' . ($bytes - 16 * self::MIB),
            'oflag=direct,seek_bytes', 'conv=fsync'], true, [$role]);
        $steps[] = self::step($role . 'Reread', ['blockdev', '--rereadpt', $dev], true, [$role]);
    }

    /** @param array<int,array<string,mixed>> $steps */
    private static function settle(array &$steps, string $dev): void
    {
        $steps[] = self::step('settle' . basename($dev), ['udevadm', 'settle'], false);
    }

    /**
     * @param array<int,string>    $argv
     * @param array<int,string>    $roles
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    private static function step(string $id, array $argv, bool $destructive, array $roles = [], array $env = []): array
    {
        return ['id' => $id, 'argv' => $argv, 'destructive' => $destructive, 'roles' => $roles, 'env' => $env];
    }

    // stablePath is the by-path name of a disk (udev ID_PATH); refuse when udev did not provide one.
    private static function stablePath(array $disk): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/D', (string) $disk['idPath']) !== 1) {
            throw new InstallRefusal('diskIdentityIncomplete', 'a boot disk has no usable udev path for GRUB_TARGETS');
        }
        return '/dev/disk/by-path/' . $disk['idPath'];
    }

    private static function assertDevicePath(string $path): void
    {
        if (preg_match('#^/dev/[a-z0-9]+$#D', $path) !== 1) {
            throw new InstallRefusal('planInvalid', 'device path is not a plain /dev node');
        }
    }
}
