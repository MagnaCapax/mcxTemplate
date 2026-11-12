<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\DiskMatcher;
use Lib\Install\InstallRefusal;
use Lib\Install\LayoutPlanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// LayoutPlannerTest checks the ordered argv lists. Nothing here runs a command.
final class LayoutPlannerTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function usbSteps(?array $inventory = null, array $stateOver = []): array
    {
        $plan = InstallFixtures::plan();
        $match = DiskMatcher::match($plan, $inventory ?? InstallFixtures::inventory(), InstallFixtures::state($stateOver));
        $this->assertTrue($match['ok'], (string) ($match['detail'] ?? ''));
        return LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
    }

    /** @return array<int,array<string,mixed>> */
    private function singleSteps(bool $homeSplit = false): array
    {
        $raw = InstallFixtures::rawPlan(['layout' => 'biosSingle', 'homeSplit' => $homeSplit]);
        $raw['disks']['boot'] = [];
        $raw['disks']['root']['sizeBytes'] = 1920383410176;
        $inv = [InstallFixtures::disk('nvme0n1', ['serial' => 'NVME-ROOT-1', 'sizeBytes' => 1920383410176, 'idPath' => 'pci-0000:01:00.0-nvme-1'])];
        $plan = InstallFixtures::plan($raw);
        $match = DiskMatcher::match($plan, $inv, InstallFixtures::state(['partitions' => ['nvme0n1' => []]]));
        $this->assertTrue($match['ok'], (string) ($match['detail'] ?? ''));
        return LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
    }

    /** @return array<int,string> */
    private function ids(array $steps): array
    {
        return array_map(static fn (array $s): string => $s['id'], $steps);
    }

    private function step(array $steps, string $id): array
    {
        foreach ($steps as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        $this->fail("no step $id");
    }

    public function testPartNamesFollowNvmeAndSataConventions(): void
    {
        $this->assertSame('/dev/nvme0n1p2', LayoutPlanner::partName('/dev/nvme0n1', 2));
        $this->assertSame('/dev/sda1', LayoutPlanner::partName('/dev/sda', 1));
    }

    public function testBiosSingleOrderWipeThenPartitionThenFormatThenMountThenExtractThenConfigure(): void
    {
        $ids = $this->ids($this->singleSteps());
        $order = ['rootWipefs', 'rootZap', 'rootZeroHead', 'rootZeroTail', 'rootReread', 'partition', 'mkfsBoot', 'mkswap', 'mkfsRoot',
            'mountRoot', 'mountBoot', 'extract', 'resolvConf', 'rootPassword', 'configure', 'verifyBoot', 'umountBoot', 'umountRoot', 'sync'];
        $last = -1;
        foreach ($order as $id) {
            $pos = array_search($id, $ids, true);
            $this->assertNotFalse($pos, "missing $id");
            $this->assertGreaterThan($last, $pos, "$id is out of order");
            $last = $pos;
        }
    }

    public function testBiosSingleCommandsAreExact(): void
    {
        $steps = $this->singleSteps();
        $this->assertSame(['parted', '-s', '-a', 'optimal', '/dev/nvme0n1', 'mklabel', 'msdos', 'mkpart', 'primary', 'ext4', '1MiB', '1025MiB',
            'mkpart', 'primary', 'linux-swap', '1025MiB', '5121MiB', 'mkpart', 'primary', 'ext4', '5121MiB', '100%', 'set', '1', 'boot', 'on'],
            $this->step($steps, 'partition')['argv']);
        $this->assertSame(['mkfs.ext4', '-F', '-L', 'boot', '-U', '00000000-0000-4000-8000-000000000001', '/dev/nvme0n1p1'], $this->step($steps, 'mkfsBoot')['argv']);
        $this->assertSame(['mkswap', '-U', '00000000-0000-4000-8000-000000000002', '/dev/nvme0n1p2'], $this->step($steps, 'mkswap')['argv']);
        $this->assertSame(['tar', '-xpzf', '/run/mcxTemplate/debian-12-base.tar.gz', '--numeric-owner', '--xattrs', '--acls', '--strip-components=1', '-C', '/mnt/target'],
            $this->step($steps, 'extract')['argv']);
        $configure = $this->step($steps, 'configure');
        $this->assertSame(['chroot', '/mnt/target', 'php', '/opt/mcxTemplate/installTemplate.php', '--strict', '--hostname=node1.example.test',
            '--network-cidr=192.0.2.10/24', '--gateway=192.0.2.1', '--mount=/,UUID=00000000-0000-4000-8000-000000000003',
            '--mount=swap,UUID=00000000-0000-4000-8000-000000000002', '--mount=/boot,UUID=00000000-0000-4000-8000-000000000001'], $configure['argv']);
        $this->assertSame(['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C', 'USER' => 'root', 'HOME' => '/root',
            'GRUB_TARGETS' => '/dev/disk/by-path/pci-0000:01:00.0-nvme-1'], $configure['env'], 'exactly this environment, nothing inherited');
    }

    // A UUID= mount right after mkfs raced udev's re-probe on a real NVMe (mount saw no type and fell back to
    // squashfs). Every mount must name the device just formatted and the filesystem type.
    public function testMountsUseTheFormattedDeviceAndNameTheType(): void
    {
        $single = $this->singleSteps();
        $this->assertSame(['mount', '-t', 'ext4', '/dev/nvme0n1p3', '/mnt/target'], $this->step($single, 'mountRoot')['argv']);
        $this->assertSame(['mount', '-t', 'ext4', '/dev/nvme0n1p1', '/mnt/target/boot'], $this->step($single, 'mountBoot')['argv']);
        $split = $this->singleSteps(true);
        $this->assertSame(['mount', '-t', 'ext4', '/dev/nvme0n1p4', '/mnt/target/home'], $this->step($split, 'mountHome')['argv']);
        $usb = $this->usbSteps();
        $this->assertSame(['mount', '-t', 'ext4', '/dev/nvme0n1p2', '/mnt/target'], $this->step($usb, 'mountRoot')['argv']);
        $this->assertSame(['mount', '-t', 'ext4', '/dev/md/mcxboot', '/mnt/target/boot'], $this->step($usb, 'mountBoot')['argv']);
        foreach ([$single, $split, $usb] as $steps) {
            foreach ($steps as $s) {
                if (strncmp($s['id'], 'mount', 5) === 0) {
                    $this->assertSame('-t', $s['argv'][1], $s['id'] . ' does not name the filesystem type');
                    $this->assertStringStartsWith('/dev/', $s['argv'][3], $s['id'] . ' does not mount by device path');
                }
            }
        }
    }

    public function testExtractStripsTheComponentCountTheArchiveCheckFound(): void
    {
        $raw = InstallFixtures::rawPlan(['layout' => 'biosSingle']);
        $raw['disks']['boot'] = [];
        $raw['disks']['root']['sizeBytes'] = 1920383410176;
        $inv = [InstallFixtures::disk('nvme0n1', ['serial' => 'NVME-ROOT-1', 'sizeBytes' => 1920383410176, 'idPath' => 'pci-0000:01:00.0-nvme-1'])];
        $plan = InstallFixtures::plan($raw);
        $match = DiskMatcher::match($plan, $inv, InstallFixtures::state(['partitions' => ['nvme0n1' => []]]));
        $plan['template']['stripComponents'] = 2;
        $steps = LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
        $this->assertContains('--strip-components=2', $this->step($steps, 'extract')['argv']);
        $plan['template']['stripComponents'] = 3;
        try {
            LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
            $this->fail('a strip count other than 1 or 2 must be refused');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateLayoutRefused', $e->refusalCode());
        }
    }

    public function testHomeSplitAddsAFourthPartitionWithPmFilesystemOptions(): void
    {
        $steps = $this->singleSteps(true);
        $home = $this->step($steps, 'mkfsHome')['argv'];
        $this->assertSame(['mkfs.ext4', '-F', '-m', '0', '-L', 'home'], array_slice($home, 0, 6));
        $this->assertSame('/dev/nvme0n1p4', end($home));
        $this->assertContains('--mount=/home,UUID=' . $home[7], $this->step($steps, 'configure')['argv']);
    }

    public function testUsbLayoutBuildsMetadata090MirrorOnBothSticksAndInstallsGrubToBoth(): void
    {
        $steps = $this->usbSteps();
        $create = $this->step($steps, 'mdCreate');
        // 0.90, never 1.x: a v1.x superblock made by a rescue kernel newer than 6.18 is refused by older target kernels.
        $this->assertSame(['mdadm', '--create', '/dev/md/mcxboot', '--run', '--level=1', '--raid-devices=2', '--metadata=0.90',
            '/dev/sda1', '/dev/sdb1'], $create['argv']);
        $this->assertSame(['boot0', 'boot1'], $create['roles']);
        $this->assertSame(['mkfs.ext4', '-F', '-L', 'boot', '-U', '00000000-0000-4000-8000-000000000001', '/dev/md/mcxboot'], $this->step($steps, 'mkfsBoot')['argv']);
        $this->assertSame(['sgdisk', '-n', '1:0:+4096M', '-t', '1:8200', '-n', '2:0:0', '-t', '2:8300', '/dev/nvme0n1'], $this->step($steps, 'partitionRoot')['argv']);
        $env = $this->step($steps, 'configure')['env'];
        $this->assertSame('/dev/disk/by-path/pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0 /dev/disk/by-path/pci-0000:00:14.0-usb-0:4:1.0-scsi-0:0:0:0', $env['GRUB_TARGETS']);
        $this->assertContains('mdStop', $this->ids($steps));
    }

    public function testStoppedArraysComeFirst(): void
    {
        $steps = $this->usbSteps(null, ['md' => ['md127' => ['sda1', 'sdb1']]]);
        $this->assertSame(['mdadm', '--stop', '/dev/md127'], $steps[0]['argv']);
        $this->assertSame('stopArray', $steps[0]['id']);
    }

    public function testEveryDestructiveStepNamesTheDisksToReverify(): void
    {
        foreach ([$this->usbSteps(), $this->singleSteps(), $this->singleSteps(true)] as $steps) {
            foreach ($steps as $s) {
                if ($s['destructive']) {
                    $this->assertNotSame([], $s['roles'], $s['id'] . ' is destructive but has no identity re-check');
                }
            }
        }
    }

    public function testDestructiveCommandsUseExactDevicePathsNeverGlobs(): void
    {
        foreach ([$this->usbSteps(), $this->singleSteps()] as $steps) {
            foreach ($steps as $s) {
                foreach ($s['argv'] as $arg) {
                    $this->assertIsString($arg);
                    if ($s['destructive']) {
                        $this->assertSame(0, preg_match('/[*?\[\]$`;|&<>\s]/', $arg), $s['id'] . ' has a shell-active argument');
                    }
                }
            }
        }
    }

    public function testTailZeroingTargetsTheLastSixteenMebibytesInBytes(): void
    {
        // 1920383410176 is not a whole number of MiB: a MiB seek would leave part of the tail unzeroed.
        $argv = $this->step($this->singleSteps(), 'rootZeroTail')['argv'];
        $this->assertSame(1920383410176 - 16 * 1048576, 1920366632960);
        $this->assertSame(['bs=1M', 'count=16', 'seek=1920366632960', 'oflag=direct,seek_bytes', 'conv=fsync'], array_slice($argv, 3));
        $this->assertSame('of=/dev/nvme0n1', $argv[2]);
    }

    public function testConfigureIsDestructiveAndReverifiesEveryGrubDisk(): void
    {
        // grub-install inside configure writes raw sectors, so DeviceGuard must re-read those disks first.
        $single = $this->step($this->singleSteps(), 'configure');
        $this->assertTrue($single['destructive']);
        $this->assertSame(['root'], $single['roles']);
        $usb = $this->step($this->usbSteps(), 'configure');
        $this->assertTrue($usb['destructive']);
        $this->assertSame(['root', 'boot0', 'boot1'], $usb['roles']);
        $this->assertSame('configure', $usb['kind']);
    }

    public function testBootVerificationFollowsConfigureWhileTheTargetIsMounted(): void
    {
        $steps = $this->usbSteps();
        $ids = $this->ids($steps);
        $this->assertSame(array_search('configure', $ids, true) + 1, array_search('verifyBoot', $ids, true));
        $verify = $this->step($steps, 'verifyBoot');
        $this->assertSame('verify', $verify['kind']);
        $this->assertFalse($verify['destructive']);
        $this->assertSame([], $verify['argv']);
        $this->assertSame(['00000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'], $verify['uuids']);
        $this->assertSame(['/dev/disk/by-path/pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0', '/dev/disk/by-path/pci-0000:00:14.0-usb-0:4:1.0-scsi-0:0:0:0'], $verify['grubTargets']);
        $this->assertTrue($verify['md']);
        $this->assertFalse($this->step($this->singleSteps(), 'verifyBoot')['md']);
        $this->assertContains('00000000-0000-4000-8000-000000000004', $this->step($this->singleSteps(true), 'verifyBoot')['uuids'], 'the /home UUID is verified too');
    }

    // planRefusal plans a mutated match and returns the refusal message.
    private function planRefusal(callable $mutate, string $layout = 'biosNvmeUsbBoot'): string
    {
        if ($layout === 'biosNvmeUsbBoot') {
            $plan = InstallFixtures::plan();
            $match = DiskMatcher::match($plan, InstallFixtures::inventory(), InstallFixtures::state());
        } else {
            $raw = InstallFixtures::rawPlan(['layout' => 'biosSingle']);
            $raw['disks']['boot'] = [];
            $raw['disks']['root']['sizeBytes'] = 1920383410176;
            $plan = InstallFixtures::plan($raw);
            $inv = [InstallFixtures::disk('nvme0n1', ['serial' => 'NVME-ROOT-1', 'sizeBytes' => 1920383410176, 'idPath' => 'pci-0000:01:00.0-nvme-1'])];
            $match = DiskMatcher::match($plan, $inv, InstallFixtures::state(['partitions' => ['nvme0n1' => []]]));
        }
        $this->assertTrue($match['ok'], (string) ($match['detail'] ?? ''));
        $mutate($match);
        try {
            LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
        } catch (InstallRefusal $e) {
            return $e->getMessage();
        }
        $this->fail('the planner accepted the mutated match');
    }

    public function testDevicePathThatIsNotAPlainDevNodeIsRefused(): void
    {
        $msg = $this->planRefusal(static function (array &$m): void { $m['roles']['root']['path'] = '/dev/disk/by-id/nvme-x'; });
        $this->assertSame('planInvalid: device path is not a plain /dev node', $msg);
    }

    public function testDiskTooSmallToWipeIsRefused(): void
    {
        $msg = $this->planRefusal(static function (array &$m): void { $m['roles']['root']['sizeBytes'] = 64 * 1048576 - 512; });
        $this->assertSame('sizeMismatch: disk is too small to wipe safely', $msg);
    }

    public function testBiosSingleRootTooSmallForTheLayoutIsRefused(): void
    {
        $msg = $this->planRefusal(static function (array &$m): void { $m['roles']['root']['sizeBytes'] = 20479 * 1048576; }, 'biosSingle');
        $this->assertSame('layoutSizeRefused: root disk is too small for this layout', $msg);
    }

    public function testMdArrayNameOutsideThePatternIsRefused(): void
    {
        $msg = $this->planRefusal(static function (array &$m): void { $m['stopArrays'] = ['md127/../sda']; });
        $this->assertSame('planInvalid: unexpected md array name', $msg);
    }

    public function testPasswordStepCarriesNoSecretInArgvOrEnv(): void
    {
        $step = $this->step($this->usbSteps(), 'rootPassword');
        $this->assertSame(['chroot', '/mnt/target', 'chpasswd', '-e'], $step['argv']);
        $this->assertSame([], $step['env']);
        $this->assertSame('password', $step['kind']);
    }

    public function testResolversComeFromThePlan(): void
    {
        $step = $this->step($this->usbSteps(), 'resolvConf');
        $this->assertSame("nameserver 198.51.100.2\nnameserver 198.51.100.3\n", $step['content']);
    }

    public function testBootDiskWithoutUdevPathIsRefusedForGrubTargets(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[1]['idPath'] = '';
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][0]['serial'] = 'STICKSER';
        $inv[1]['serial'] = 'STICKSER';
        $plan = InstallFixtures::plan($raw);
        $match = DiskMatcher::match($plan, $inv, InstallFixtures::state());
        $this->assertTrue($match['ok'], (string) ($match['detail'] ?? ''));
        $this->expectException(InstallRefusal::class);
        $this->expectExceptionMessage('diskIdentityIncomplete');
        LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
    }

    public function testPlanningNeedsASuccessfulMatch(): void
    {
        $this->expectException(InstallRefusal::class);
        LayoutPlanner::plan(InstallFixtures::plan(), ['ok' => false, 'code' => 'diskMissing', 'detail' => ''], InstallFixtures::counterUuid());
    }

    public function testRealUuidGeneratorProducesVersion4Uuids(): void
    {
        $this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', LayoutPlanner::uuidV4()));
    }
}
