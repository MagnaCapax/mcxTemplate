<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\Installer;
use Lib\Install\InstallRefusal;
use Lib\Install\LayoutPlanner;
use Lib\Install\RecordingRunner;
use Lib\Install\SystemStateReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// InstallerTest drives the whole install core with fake runners: the reader answers
// lsblk/udevadm/blkid/dd from fixtures, the writer only records. No command is ever
// executed. The "target" is a temp directory laid out like an installed system.
final class InstallerTest extends TestCase
{
    private const TEMPLATE = 'pretend-archive';
    private string $dir = '';
    private bool $swapped = false;
    private string $listing = "rootfs/\nrootfs/etc/os-release\nrootfs/opt/mcxTemplate/installTemplate.php\nrootfs/usr/sbin/mdadm\n";
    private string $sector = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-installer-' . bin2hex(random_bytes(4));
        foreach (['/sys/class/net/eth0/device', '/proc/self', '/target/etc/mdadm', '/target/etc/ssh', '/target/boot/grub'] as $sub) {
            mkdir($this->dir . $sub, 0755, true);
        }
        file_put_contents($this->dir . '/sys/class/net/eth0/address', InstallFixtures::MAC . "\n");
        file_put_contents($this->dir . '/proc/self/mountinfo', '');
        file_put_contents($this->dir . '/proc/swaps', "Filename Type Size Used Priority\n");
        file_put_contents($this->dir . '/template.tar.gz', self::TEMPLATE);
        // What a successful configure leaves behind (USB layout, counter UUIDs: boot 1, swap 2, root 3).
        file_put_contents($this->dir . '/target/boot/vmlinuz-6.1.0-test', 'kernel');
        file_put_contents($this->dir . '/target/boot/initrd.img-6.1.0-test', 'initrd');
        file_put_contents($this->dir . '/target/boot/grub/grub.cfg', "menuentry x {}\n");
        file_put_contents($this->dir . '/target/etc/ssh/ssh_host_ed25519_key.pub', InstallFixtures::hostKey() . " root@rescue\n");
        file_put_contents($this->dir . '/target/etc/fstab', "UUID=" . self::uuid(3) . " / ext4 defaults 0 1\n"
            . "UUID=" . self::uuid(1) . " /boot ext4 defaults 0 2\nUUID=" . self::uuid(2) . " none swap sw 0 0\n");
        file_put_contents($this->dir . '/target/etc/mdadm/mdadm.conf', "ARRAY /dev/md/mcxboot metadata=1.2 name=rescue:mcxboot UUID=1:2:3:4\n");
        $this->sector = str_pad(str_repeat("\0", 384) . "GRUB \0Geom\0Hard Disk\0Read\0 Error", 510, "\0") . "\x55\xAA";
        $this->swapped = false;
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private static function uuid(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function plan(array $over = []): array
    {
        $raw = InstallFixtures::rawPlan($over);
        $raw['template']['path'] = $this->dir . '/template.tar.gz';
        $raw['template']['sizeBytes'] = strlen(self::TEMPLATE);
        $raw['template']['sha256'] = hash('sha256', self::TEMPLATE);
        return InstallFixtures::plan($raw);
    }

    private function stageDir(): string
    {
        return $this->dir . '/mcxTemplate-stage-job-1';
    }

    // reader answers lsblk, udevadm, findmnt, the tar listing, blkid and the boot-sector read from the fixtures.
    private function reader(): CallbackRunner
    {
        $inventory = InstallFixtures::inventory();
        return new CallbackRunner(function (array $argv) use ($inventory): array {
            $ok = static fn (string $out): array => ['code' => 0, 'out' => $out, 'err' => ''];
            if ($argv[0] === 'lsblk') {
                $rows = [];
                foreach ($inventory as $d) {
                    if (isset($argv[6]) && $argv[6] !== $d['path']) {
                        continue;
                    }
                    if ($this->swapped && $d['kname'] === 'nvme0n1') {
                        $d['serial'] = 'SOMEONE-ELSES-DISK';
                    }
                    $rows[] = ['name' => $d['kname'], 'kname' => $d['kname'], 'type' => 'disk', 'size' => $d['sizeBytes'], 'serial' => $d['serial'],
                        'wwn' => $d['wwn'], 'model' => $d['model'], 'tran' => $d['tran'], 'rm' => $d['removable'], 'hotplug' => $d['hotplug'], 'pttype' => null];
                }
                return $ok(json_encode(['blockdevices' => $rows]));
            }
            if ($argv[0] === 'udevadm') {
                foreach ($inventory as $d) {
                    if ('--name=' . $d['path'] === $argv[3]) {
                        return $ok('ID_PATH=' . $d['idPath'] . "\nID_VENDOR_ID=" . $d['usbVendorId'] . "\n");
                    }
                }
            }
            if ($argv[0] === 'tar') {
                return $ok($this->listing);
            }
            if ($argv[0] === 'findmnt') {
                return ['code' => 1, 'out' => '', 'err' => ''];
            }
            if ($argv[0] === 'blkid') {
                return $ok(self::uuid(1) . "\n" . self::uuid(2) . "\n" . self::uuid(3) . "\nmd-member-uuid\n");
            }
            if ($argv[0] === 'dd') {
                return $ok($this->sector);
            }
            if ($argv[0] === 'mdadm') {
                return $ok("MD_LEVEL=raid1\nMD_METADATA=0.90\n");
            }
            return $ok('');
        });
    }

    // writer records every command; the strict configure answers with the success line.
    private function writer(): RecordingRunner
    {
        $writer = new RecordingRunner();
        $writer->addResponse(['chroot', '/mnt/target', 'php'], "[INFO] tasks ran\n" . Installer::CONFIGURE_SENTINEL . "\n[INFO] done\n");
        return $writer;
    }

    private function installer(CallbackRunner $reader, $writer): Installer
    {
        return new Installer($reader, $writer, new SystemStateReader($this->dir . '/proc', $this->dir . '/sys'),
            InstallFixtures::counterUuid(), $this->dir . '/target', $this->dir);
    }

    // run executes a prepared (staged) plan and returns the refusal code, or '' on success.
    private function runInstall(Installer $installer, array $plan, ?callable $say = null): string
    {
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', $say ?? static function (string $id): void {});
        } catch (InstallRefusal $e) {
            return $e->refusalCode() . ': ' . $e->getMessage();
        }
        return '';
    }

    public function testPrepareReadsOnlyAndNeverTouchesTheWriter(): void
    {
        $writer = new RecordingRunner();
        $prepared = $this->installer($this->reader(), $writer)->prepare($this->plan());
        $this->assertSame([], $writer->calls());
        $this->assertSame('nvme0n1', $prepared['match']['roles']['root']['kname']);
        $this->assertNotSame([], $prepared['steps']);
        $this->assertNull($prepared['stage']);
        $this->assertDirectoryDoesNotExist($this->stageDir(), 'a dry-run prepare writes nothing, not even the staged copy');
    }

    // A template spelled "./rootfs/..." (as the rehydrate tool writes it) must extract with --strip-components=2;
    // one spelled "rootfs/..." with 1. Either way the tree lands on the target root.
    public function testExtractStripMatchesTheArchiveSpelling(): void
    {
        $extract = function (): array {
            foreach ($this->installer($this->reader(), new RecordingRunner())->prepare($this->plan())['steps'] as $step) {
                if ($step['id'] === 'extract') {
                    return $step['argv'];
                }
            }
            $this->fail('no extract step');
        };
        $this->assertContains('--strip-components=1', $extract());
        $this->listing = "./\n./rootfs/\n./rootfs/etc/os-release\n./rootfs/opt/mcxTemplate/installTemplate.php\n./rootfs/usr/sbin/mdadm\n";
        $this->assertContains('--strip-components=2', $extract());
    }

    public function testReaderIssuesNoDestructiveCommand(): void
    {
        $reader = $this->reader();
        $this->installer($reader, new RecordingRunner())->prepare($this->plan());
        $allowed = ['lsblk', 'udevadm', 'findmnt', 'tar', PHP_BINARY];
        foreach ($reader->argvs as $argv) {
            $this->assertContains($argv[0], $allowed);
            if ($argv[0] === 'tar') {
                $this->assertSame('-tzf', $argv[1], 'the reader may only list an archive');
            }
        }
    }

    // biosNvmeUsbBoot keeps /boot on md RAID1, so a template without mdadm cannot boot it. The refusal comes from the
    // listing in prepare, before the writer runs anything (2026-11-12: such a template was wiped onto a unit first).
    public function testMdLayoutTemplateWithoutMdadmIsRefusedBeforeAnyWrite(): void
    {
        $this->listing = "rootfs/\nrootfs/etc/os-release\nrootfs/opt/mcxTemplate/installTemplate.php\n";
        $writer = new RecordingRunner();
        try {
            $this->installer($this->reader(), $writer)->prepare($this->plan());
            $this->fail('a template without mdadm was accepted for the md layout');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateLacksMdadm', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
    }

    public function testTamperedTemplateIsRefusedBeforeAnyStep(): void
    {
        file_put_contents($this->dir . '/template.tar.gz', 'pretend-archivX');
        $writer = new RecordingRunner();
        try {
            $this->installer($this->reader(), $writer)->prepare($this->plan());
            $this->fail('a tampered template was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateHashMismatch', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
    }

    public function testTamperedTemplateIsRefusedWhileStagingAndLeavesNoCopy(): void
    {
        file_put_contents($this->dir . '/template.tar.gz', 'pretend-archivX');
        try {
            $this->installer($this->reader(), new RecordingRunner())->prepare($this->plan(), true);
            $this->fail('a tampered template was staged');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateHashMismatch', $e->refusalCode());
        }
        $this->assertDirectoryDoesNotExist($this->stageDir());
    }

    public function testExistingStageDirectoryIsRefused(): void
    {
        mkdir($this->stageDir(), 0700);
        try {
            $this->installer($this->reader(), new RecordingRunner())->prepare($this->plan(), true);
            $this->fail('an existing staging directory was reused');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateStageRefused', $e->refusalCode());
        }
    }

    public function testExecuteNeedsWipeAuthorizedInThePlan(): void
    {
        $plan = $this->plan(['wipeAuthorized' => false]);
        $writer = new RecordingRunner();
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('an unauthorized plan was executed');
        } catch (InstallRefusal $e) {
            $this->assertSame('wipeNotAuthorized', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
        $this->assertFileDoesNotExist($this->dir . '/secret');
        $this->assertDirectoryDoesNotExist($this->stageDir(), 'the staged copy is removed when execute refuses');
    }

    public function testExecuteNeedsAStagedTemplate(): void
    {
        $plan = $this->plan();
        $writer = new RecordingRunner();
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan); // dry-run prepare: nothing staged
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('executed without a staged template');
        } catch (InstallRefusal $e) {
            $this->assertSame('templateNotStaged', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
    }

    public function testExecuteRunsPlannedStepsInOrderWithoutSecretsInArgv(): void
    {
        $plan = $this->plan();
        $writer = $this->writer();
        $reader = $this->reader();
        $installer = $this->installer($reader, $writer);
        $prepared = $installer->prepare($plan, true);
        $readsBefore = count($reader->argvs);
        $seen = [];
        $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id) use (&$seen): void { $seen[] = $id; });

        $expected = [];
        foreach ($prepared['steps'] as $step) {
            if (!in_array($step['kind'] ?? '', ['writeFile', 'verify', 'hostKeys'], true)) {
                $expected[] = $step['argv'];
            }
        }
        $this->assertSame($expected, $writer->argvList());
        $this->assertSame(array_column($prepared['steps'], 'id'), $seen);
        $this->assertSame([InstallFixtures::hostKey()], $installer->hostKeys(), 'the host key is read from the target, not run as a command');
        $this->assertSame("nameserver 198.51.100.2\nnameserver 198.51.100.3\n", file_get_contents($this->dir . '/target/etc/resolv.conf'));
        $this->assertDirectoryDoesNotExist($this->stageDir(), 'the staged copy is removed after the run');

        // During execute the reader only re-reads identity and runs the read-only boot checks.
        foreach (array_slice($reader->argvs, $readsBefore) as $argv) {
            $this->assertContains($argv[0], ['lsblk', 'udevadm', 'blkid', 'dd', 'mdadm']);
            if ($argv[0] === 'mdadm') {
                $this->assertSame(['mdadm', '--detail', '--export', '/dev/md/mcxboot'], $argv, 'the reader only describes the mirror');
            }
            if ($argv[0] === 'dd') {
                $this->assertSame([], preg_grep('/^of=/', $argv), 'the boot-code check only reads');
            }
        }

        $password = trim((string) file_get_contents($this->dir . '/secret'));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9]{20}$/D', $password));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/secret')), -4));
        foreach ($writer->calls() as $i => $call) {
            $this->assertStringNotContainsString($password, implode("\0", $call['argv']), 'plaintext password in argv');
            $this->assertStringNotContainsString($password, implode("\0", $call['env']), 'plaintext password in env');
            if ($call['argv'] === ['chroot', '/mnt/target', 'chpasswd', '-e']) {
                $stdin = (string) $writer->stdinOf($i);
                $this->assertStringStartsWith('root:$6$', $stdin);
                $this->assertStringNotContainsString($password, $stdin);
            } else {
                $this->assertSame(0, $call['stdinBytes'], 'only the password step gets stdin');
            }
            if ($call['argv'][0] === 'chroot' && $call['argv'][2] === 'php') {
                $this->assertSame(LayoutPlanner::CHROOT_ENV + ['GRUB_TARGETS' => '/dev/disk/by-path/pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0'
                    . ' /dev/disk/by-path/pci-0000:00:14.0-usb-0:4:1.0-scsi-0:0:0:0'], $call['env'], 'configure gets exactly the minimal environment');
            }
        }
    }

    public function testRenamedDeviceAbortsBeforeTheFirstDestructiveCommand(): void
    {
        $plan = $this->plan();
        $writer = new RecordingRunner();
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan, true);
        $this->swapped = true; // the kernel path now answers with another disk's serial
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('a device that changed identity was wiped');
        } catch (InstallRefusal $e) {
            $this->assertSame('deviceChanged', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls(), 'no command may run once identity changed');
    }

    public function testDeviceChangeRightBeforeConfigureUnwindsMountsAndTheArray(): void
    {
        // Identity changes after the mounts and the extract: configure (grub-install writes raw disks) must not run.
        $writer = $this->writer();
        $code = $this->runInstall($this->installer($this->reader(), $writer), $this->plan(), function (string $id): void {
            if ($id === 'configure') {
                $this->swapped = true;
            }
        });
        $this->assertStringStartsWith('deviceChanged', $code);
        $t = '/mnt/target';
        $this->assertSame([['umount', "$t/run"], ['umount', "$t/sys"], ['umount', "$t/proc"], ['umount', "$t/dev/pts"], ['umount', "$t/dev"],
            ['umount', "$t/boot"], ['umount', $t], ['mdadm', '--stop', '/dev/md/mcxboot']], array_slice($writer->argvList(), -8));
        foreach ($writer->argvList() as $argv) {
            $this->assertNotSame(['chroot', $t, 'php'], array_slice($argv, 0, 3), 'configure ran on a changed device');
        }
    }

    public function testConfigureExitZeroWithoutTheStrictSuccessLineIsAFailure(): void
    {
        // An engine that ignores --strict exits 0 and prints no success line.
        $writer = new RecordingRunner();
        $reader = $this->reader();
        $code = $this->runInstall($this->installer($reader, $writer), $this->plan());
        $this->assertStringStartsWith('stepFailed', $code);
        $this->assertStringContainsString('configure', $code);
        $this->assertSame(['mdadm', '--stop', '/dev/md/mcxboot'], array_slice($writer->argvList(), -1)[0], 'the failed run was unwound');
        $this->assertSame([], array_filter($reader->argvs, static fn (array $a): bool => $a[0] === 'blkid'), 'nothing after configure ran');
    }

    public function testBootVerificationFailureFailsTheRunAndUnwinds(): void
    {
        unlink($this->dir . '/target/boot/initrd.img-6.1.0-test');
        $writer = $this->writer();
        $code = $this->runInstall($this->installer($this->reader(), $writer), $this->plan());
        $this->assertStringStartsWith('verifyFailed', $code);
        $this->assertStringContainsString('initrd', $code);
        $this->assertSame(['mdadm', '--stop', '/dev/md/mcxboot'], array_slice($writer->argvList(), -1)[0]);
        $this->assertContains(['umount', '/mnt/target'], $writer->argvList());
    }

    public function testFailedStepStopsTheRunAndUnwindsMounts(): void
    {
        $plan = $this->plan();
        $writer = new RecordingRunner();
        $writer->addResponse(['tar'], '', 2);
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('a failed extract did not stop the run');
        } catch (InstallRefusal $e) {
            $this->assertSame('stepFailed', $e->refusalCode());
            $this->assertStringContainsString('extract', $e->getMessage());
        }
        $argvs = $writer->argvList();
        $tail = array_slice($argvs, -3);
        $this->assertSame([['umount', '/mnt/target/boot'], ['umount', '/mnt/target'], ['mdadm', '--stop', '/dev/md/mcxboot']], $tail);
        foreach ($argvs as $argv) {
            $this->assertNotSame('chroot', $argv[0], 'nothing after the failed step may run');
        }
        $this->assertFileDoesNotExist($this->dir . '/secret', 'no password file for a password that was never set');
    }

    public function testAnyThrowableMidRunStillUnwinds(): void
    {
        $writer = new CallbackRunner(static function (array $argv): array {
            if ($argv[0] === 'tar') {
                throw new \RuntimeException('runner broke');
            }
            return ['code' => 0, 'out' => '', 'err' => ''];
        });
        $installer = $this->installer($this->reader(), $writer);
        $plan = $this->plan();
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('the error was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(InstallRefusal::class, $e, 'the original error is rethrown');
        }
        $this->assertSame([['umount', '/mnt/target/boot'], ['umount', '/mnt/target'], ['mdadm', '--stop', '/dev/md/mcxboot']], array_slice($writer->argvs, -3));
        $this->assertDirectoryDoesNotExist($this->stageDir());
    }

    public function testCleanupOnlyUnmountsTheTargetMountPointAndBelow(): void
    {
        $writer = new RecordingRunner();
        $writer->addResponse(['false'], '', 1);
        $installer = $this->installer($this->reader(), $writer);
        $plan = $this->plan();
        $prepared = $installer->prepare($plan, true);
        $step = static fn (string $id, array $argv): array => ['id' => $id, 'argv' => $argv, 'destructive' => false, 'roles' => [], 'env' => []];
        $prepared['steps'] = [$step('a', ['mount', 'UUID=1', '/srv/elsewhere']), $step('b', ['mount', 'UUID=2', '/mnt/target-other']),
            $step('c', ['mount', 'UUID=3', '/mnt/target']), $step('d', ['mount', 'UUID=4', '/mnt/target/boot']), $step('boom', ['false'])];
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('the failing step was ignored');
        } catch (InstallRefusal $e) {
            $this->assertSame('stepFailed', $e->refusalCode());
        }
        $this->assertSame([['umount', '/mnt/target/boot'], ['umount', '/mnt/target']], array_slice($writer->argvList(), 5));
    }

    public function testExistingSecretFileStopsTheRunBeforeAnyWrite(): void
    {
        file_put_contents($this->dir . '/secret', 'old');
        $plan = $this->plan();
        $writer = new RecordingRunner();
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});
            $this->fail('executed without a usable secret file');
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
        $this->assertSame('old', file_get_contents($this->dir . '/secret'));
    }

    public function testSecretOutUnderTheTargetMountPointIsRefusedBeforeAnyWrite(): void
    {
        $plan = $this->plan();
        $writer = new RecordingRunner();
        $installer = $this->installer($this->reader(), $writer);
        $prepared = $installer->prepare($plan, true);
        try {
            $installer->execute($plan, $prepared, $this->dir . '/target/root.secret', static function (string $id): void {});
            $this->fail('a secret file under the target was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
        }
        $this->assertSame([], $writer->calls());
        $this->assertFileDoesNotExist($this->dir . '/target/root.secret');
    }

    public function testSecretIsWrittenOnlyRightBeforeThePasswordStep(): void
    {
        $secret = $this->dir . '/secret';
        $existed = [];
        $code = $this->runInstall($this->installer($this->reader(), $this->writer()), $this->plan(), static function (string $id) use ($secret, &$existed): void {
            $existed[$id] = is_file($secret);
        });
        $this->assertSame('', $code);
        $ids = array_keys($existed);
        $at = array_search('rootPassword', $ids, true);
        $this->assertNotFalse($at);
        foreach (array_slice($ids, 0, $at + 1) as $id) {
            $this->assertFalse($existed[$id], "the secret existed before step $id ran");
        }
        $this->assertTrue($existed[$ids[$at + 1]], 'the secret is written with the password step');
    }

    public function testChangedTemplateAfterPrepareDoesNotChangeWhatIsExtracted(): void
    {
        $extracted = null;
        $stageMode = '';
        $writer = new CallbackRunner(function (array $argv) use (&$extracted, &$stageMode): array {
            if ($argv[0] === 'tar') {
                $extracted = file_get_contents($argv[2]);
                $stageMode = substr(sprintf('%o', fileperms(dirname($argv[2]))), -4);
            }
            $out = $argv[0] === 'chroot' && $argv[2] === 'php' ? Installer::CONFIGURE_SENTINEL . "\n" : '';
            return ['code' => 0, 'out' => $out, 'err' => ''];
        });
        $installer = $this->installer($this->reader(), $writer);
        $plan = $this->plan();
        $prepared = $installer->prepare($plan, true);
        file_put_contents($this->dir . '/template.tar.gz', 'swapped-archive'); // same size, other bytes
        $installer->execute($plan, $prepared, $this->dir . '/secret', static function (string $id): void {});

        $this->assertSame(self::TEMPLATE, $extracted, 'the hashed copy is what gets extracted');
        $this->assertSame('0700', $stageMode);
        $tar = array_values(array_filter($writer->argvs, static fn (array $a): bool => $a[0] === 'tar'))[0];
        $this->assertSame($this->stageDir() . '/template.tar.gz', $tar[2]);
    }
}
