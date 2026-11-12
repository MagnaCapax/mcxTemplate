<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\BootVerifier;
use Lib\Install\InstallRefusal;
use Lib\Install\RecordingRunner;
use PHPUnit\Framework\TestCase;

// BootVerifierTest: each read-only boot check refuses on its own miss. The target
// is a temp directory; blkid and the one-sector read come from a recording runner.
final class BootVerifierTest extends TestCase
{
    private const ROOT_UUID = '00000000-0000-4000-8000-000000000003';
    private const BOOT_UUID = '00000000-0000-4000-8000-000000000001';
    private const STICK0 = '/dev/disk/by-path/pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0';
    private const STICK1 = '/dev/disk/by-path/pci-0000:00:14.0-usb-0:4:1.0-scsi-0:0:0:0';
    private string $root = '';
    private string $mdDetail = "MD_LEVEL=raid1\nMD_DEVICES=2\nMD_METADATA=0.90\nMD_UUID=01020304:05060708:090a0b0c:0d0e0f10\n";

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mcx-verify-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/boot/grub', 0755, true);
        mkdir($this->root . '/etc/mdadm', 0755, true);
        file_put_contents($this->root . '/boot/vmlinuz-6.1.0-test', 'kernel');
        file_put_contents($this->root . '/boot/initrd.img-6.1.0-test', 'initrd');
        file_put_contents($this->root . '/boot/grub/grub.cfg', "menuentry x {}\n");
        file_put_contents($this->root . '/etc/fstab', "# a comment UUID=ffffffff-0000-4000-8000-000000000009\n"
            . 'UUID=' . self::ROOT_UUID . " / ext4 defaults 0 1\nUUID=" . self::BOOT_UUID . " /boot ext4 defaults 0 2\nproc /proc proc defaults 0 0\n");
        file_put_contents($this->root . '/etc/mdadm/mdadm.conf', "ARRAY /dev/md/mcxboot metadata=0.90 UUID=1:2:3:4\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private static function grubSector(): string
    {
        return str_pad(str_repeat("\0", 384) . "GRUB \0Geom\0Hard Disk\0Read\0 Error", 510, "\0") . "\x55\xAA";
    }

    // reader: blkid lists both UUIDs; each GRUB target answers with $sectors[$device] or a GRUB sector.
    private function reader(array $sectors = [], string $blkid = self::ROOT_UUID . "\n" . self::BOOT_UUID . "\n"): RecordingRunner
    {
        $r = new RecordingRunner();
        $r->addResponse(['blkid'], $blkid);
        $r->addResponse(['mdadm', '--detail'], $this->mdDetail);
        foreach ($sectors as $device => $bytes) {
            $r->addResponse(['dd', 'if=' . $device], $bytes);
        }
        $r->addResponse(['dd'], self::grubSector());
        return $r;
    }

    private function step(bool $md = true): array
    {
        return ['id' => 'verifyBoot', 'kind' => 'verify', 'uuids' => [self::ROOT_UUID, self::BOOT_UUID],
            'grubTargets' => [self::STICK0, self::STICK1], 'md' => $md];
    }

    // assertMiss expects verifyFailed naming $check.
    private function assertMiss(string $check, RecordingRunner $reader, ?array $step = null): void
    {
        try {
            BootVerifier::verify($reader, $this->root, $step ?? $this->step());
            $this->fail("the $check miss was accepted");
        } catch (InstallRefusal $e) {
            $this->assertSame('verifyFailed', $e->refusalCode());
            $this->assertStringStartsWith("verifyFailed: $check:", $e->getMessage());
        }
    }

    public function testABootableTargetPassesAndOnlyReads(): void
    {
        $reader = $this->reader();
        BootVerifier::verify($reader, $this->root, $this->step());
        $this->assertSame([['blkid', '-c', '/dev/null', '-o', 'value', '-s', 'UUID'],
            ['dd', 'if=' . self::STICK0, 'bs=512', 'count=1', 'status=none'],
            ['dd', 'if=' . self::STICK1, 'bs=512', 'count=1', 'status=none'],
            ['mdadm', '--detail', '--export', '/dev/md/mcxboot']], $reader->argvList());
    }

    // A 1.2 mirror assembles under the rescue kernel and is refused by an older target kernel (ADR-0005).
    public function testBootMirrorWithMetadata12IsRefused(): void
    {
        $this->mdDetail = "MD_LEVEL=raid1\nMD_DEVICES=2\nMD_METADATA=1.2\nMD_NAME=rescue:mcxboot\n";
        $this->assertMiss('bootArray', $this->reader());
    }

    public function testBootMirrorThatMdadmCannotDescribeIsRefused(): void
    {
        $reader = new RecordingRunner([['prefix' => ['mdadm'], 'result' => ['code' => 1, 'out' => 'MD_METADATA=0.90', 'err' => '']]]);
        $reader->addResponse(['blkid'], self::ROOT_UUID . "\n" . self::BOOT_UUID . "\n");
        $reader->addResponse(['dd'], self::grubSector());
        $this->assertMiss('bootArray', $reader);
    }

    public function testNoMirrorMeansNoMdadmRead(): void
    {
        $reader = $this->reader();
        BootVerifier::verify($reader, $this->root, $this->step(false));
        $this->assertSame([], array_filter($reader->argvList(), static fn (array $a): bool => $a[0] === 'mdadm'));
    }

    public function testMissingKernelIsRefused(): void
    {
        unlink($this->root . '/boot/vmlinuz-6.1.0-test');
        $this->assertMiss('kernel', $this->reader());
    }

    public function testKernelWithoutMatchingInitrdIsRefused(): void
    {
        rename($this->root . '/boot/initrd.img-6.1.0-test', $this->root . '/boot/initrd.img-6.1.0-other');
        $this->assertMiss('initrd', $this->reader());
    }

    public function testEmptyInitrdIsRefused(): void
    {
        file_put_contents($this->root . '/boot/initrd.img-6.1.0-test', '');
        $this->assertMiss('initrd', $this->reader());
    }

    public function testMissingGrubConfigIsRefused(): void
    {
        unlink($this->root . '/boot/grub/grub.cfg');
        $this->assertMiss('grubConfig', $this->reader());
    }

    public function testFstabWithoutAPlannedUuidIsRefused(): void
    {
        // The fstab task failed and the template's old fstab was kept.
        file_put_contents($this->root . '/etc/fstab', "UUID=11111111-2222-4333-8444-555555555555 / ext4 defaults 0 1\n");
        $this->assertMiss('fstab', $this->reader());
    }

    public function testFstabUuidThatNoDeviceCarriesIsRefused(): void
    {
        file_put_contents($this->root . '/etc/fstab', file_get_contents($this->root . '/etc/fstab')
            . "UUID=99999999-0000-4000-8000-000000000099 /data ext4 defaults 0 2\n");
        $this->assertMiss('fstab', $this->reader());
    }

    public function testMissingFstabIsRefused(): void
    {
        unlink($this->root . '/etc/fstab');
        $this->assertMiss('fstab', $this->reader());
    }

    public function testFailedBlkidIsRefused(): void
    {
        $reader = new RecordingRunner([['prefix' => ['blkid'], 'result' => ['code' => 2, 'out' => '', 'err' => '']]]);
        $this->assertMiss('fstab', $reader);
    }

    public function testGrubTargetWithoutBootCodeIsRefused(): void
    {
        // The second stick kept a blank first sector: grub-install failed on it and the task carried on.
        $this->assertMiss('bootCode', $this->reader([self::STICK1 => str_repeat("\0", 512)]));
    }

    public function testGrubTargetWithAForeignBootSectorIsRefused(): void
    {
        // A valid 0x55AA signature from an old partition table or another boot loader is not GRUB.
        $this->assertMiss('bootCode', $this->reader([self::STICK0 => str_repeat("\0", 510) . "\x55\xAA"]));
    }

    public function testGrubTargetWithoutBootSignatureIsRefused(): void
    {
        $this->assertMiss('bootCode', $this->reader([self::STICK0 => substr(self::grubSector(), 0, 510) . "\0\0"]));
    }

    public function testUnreadableGrubTargetIsRefused(): void
    {
        $this->assertMiss('bootCode', $this->reader([self::STICK0 => 'short']));
    }

    public function testGrubTargetOutsideByPathIsRefused(): void
    {
        $step = $this->step();
        $step['grubTargets'] = ['/dev/sda'];
        $this->assertMiss('bootCode', $this->reader(), $step);
    }

    public function testMissingMdadmConfIsRefusedWhenTheLayoutUsesMd(): void
    {
        unlink($this->root . '/etc/mdadm/mdadm.conf');
        $this->assertMiss('mdadmConf', $this->reader());
    }

    public function testMdadmConfWithoutTheBootArrayIsRefused(): void
    {
        file_put_contents($this->root . '/etc/mdadm/mdadm.conf', "# no arrays\n");
        $this->assertMiss('mdadmConf', $this->reader());
    }

    public function testMdadmConfIsNotNeededWithoutMd(): void
    {
        unlink($this->root . '/etc/mdadm/mdadm.conf');
        BootVerifier::verify($this->reader(), $this->root, $this->step(false));
        $this->addToAssertionCount(1);
    }
}
