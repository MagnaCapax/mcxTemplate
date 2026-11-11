<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\DeviceGuard;
use Lib\Install\DiskInventory;
use Lib\Install\InstallRefusal;
use Lib\Install\RecordingRunner;
use PHPUnit\Framework\TestCase;

// DeviceGuardTest: a change in ANY identity field of the device behind a planned
// path aborts. One case per field, so removing one comparison fails its case.
final class DeviceGuardTest extends TestCase
{
    private const ID_PATH = 'pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0';

    /** @return array<string,mixed> one lsblk row as DiskInventory reads it */
    private static function row(array $over = []): array
    {
        return array_merge(['name' => 'sda', 'kname' => 'sda', 'type' => 'disk', 'size' => 31914983424, 'serial' => 'STICK-1',
            'wwn' => '0x5000c500a1b2c3d4', 'model' => 'Flash_Disk', 'tran' => 'usb', 'rm' => true, 'hotplug' => true, 'pttype' => 'dos'], $over);
    }

    // runner answers lsblk with $rows and udevadm with $idPath.
    private static function runner(array $rows, string $idPath = self::ID_PATH): RecordingRunner
    {
        $r = new RecordingRunner();
        $r->addResponse(['lsblk'], (string) json_encode(['blockdevices' => $rows]));
        $r->addResponse(['udevadm'], 'ID_PATH=' . $idPath . "\n");
        return $r;
    }

    /** @return array<string,mixed> the matched record the guard compares against */
    private static function expected(): array
    {
        return DiskInventory::enumerate(self::runner([self::row()]), '/dev/sda')[0];
    }

    private function assertChanged(string $needle, RecordingRunner $live): void
    {
        try {
            DeviceGuard::assertUnchanged($live, self::expected());
            $this->fail('a changed device was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('deviceChanged', $e->refusalCode());
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testUnchangedDevicePassesAndReadsOnlyThatPath(): void
    {
        $live = self::runner([self::row()]);
        DeviceGuard::assertUnchanged($live, self::expected());
        $this->assertSame(['lsblk', '-J', '-b', '-d', '-o', 'NAME,KNAME,TYPE,SIZE,SERIAL,WWN,MODEL,TRAN,RM,HOTPLUG,PTTYPE', '/dev/sda'], $live->argvList()[0]);
    }

    /** @dataProvider provideFieldChanges */
    public function testEachIdentityFieldIsCompared(string $field, array $over, string $idPath): void
    {
        $this->assertChanged("the $field behind", self::runner([self::row($over)], $idPath));
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:string}> */
    public static function provideFieldChanges(): array
    {
        return [
            'serial' => ['serial', ['serial' => 'STICK-2'], self::ID_PATH],
            'wwn' => ['wwn', ['wwn' => '0x5000c500ffffffff'], self::ID_PATH],
            'model' => ['model', ['model' => 'Other_Disk'], self::ID_PATH],
            'size' => ['sizeBytes', ['size' => 31914983424 + 512], self::ID_PATH],
            'usb path' => ['idPath', [], 'pci-0000:00:14.0-usb-0:4:1.0-scsi-0:0:0:0'],
            'transport' => ['tran', ['tran' => 'sata'], self::ID_PATH],
        ];
    }

    public function testVanishedDeviceIsRefused(): void
    {
        $this->assertChanged('gone or is not a single disk', self::runner([]));
    }

    public function testTwoDevicesBehindOnePathAreRefused(): void
    {
        $this->assertChanged('gone or is not a single disk', self::runner([self::row(), self::row(['name' => 'sdb', 'kname' => 'sdb'])]));
    }

    public function testOtherKernelNameIsRefused(): void
    {
        $this->assertChanged('gone or is not a single disk', self::runner([self::row(['name' => 'sdb', 'kname' => 'sdb'])]));
    }
}
