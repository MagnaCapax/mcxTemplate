<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\DiskInventory;
use Lib\Install\InstallRefusal;
use Lib\Install\RecordingRunner;
use PHPUnit\Framework\TestCase;

// DiskInventoryTest feeds a recorded lsblk JSON and udevadm output; no real disk is read.
final class DiskInventoryTest extends TestCase
{
    private function runner(): RecordingRunner
    {
        $json = (string) file_get_contents(__DIR__ . '/../../fixtures/install/lsblk-nvme-two-sticks-kvm.json');
        $r = new RecordingRunner();
        $r->addResponse(['lsblk'], $json);
        $r->addResponse(['udevadm', 'info', '--query=property', '--name=/dev/nvme0n1'], "ID_PATH=pci-0000:01:00.0-nvme-1\nID_SERIAL_SHORT=NVME-ROOT-1\n");
        $r->addResponse(['udevadm', 'info', '--query=property', '--name=/dev/sda'], "ID_PATH=pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0\nID_VENDOR_ID=058F\n");
        $r->addResponse(['udevadm', 'info', '--query=property', '--name=/dev/sdc'], "ID_PATH=pci-0000:00:14.0-usb-0:1:1.0-scsi-0:0:0:0\nID_VENDOR_ID=3346\n");
        return $r;
    }

    public function testOnlyWholeDisksAreListedWithUdevProperties(): void
    {
        $disks = DiskInventory::enumerate($this->runner());
        $this->assertSame(['nvme0n1', 'sda', 'sdb', 'sdc'], array_column($disks, 'kname'));
        $this->assertSame(4000787030016, $disks[0]['sizeBytes']);
        $this->assertSame(31914983424, $disks[1]['sizeBytes'], 'string sizes from older lsblk are accepted');
        $this->assertSame('', $disks[1]['serial'], 'null serial becomes the empty string');
        $this->assertSame('pci-0000:00:14.0-usb-0:3:1.0-scsi-0:0:0:0', $disks[1]['idPath']);
        $this->assertSame('058f', $disks[1]['usbVendorId'], 'vendor ids are lowercased');
        $this->assertSame('3346', $disks[3]['usbVendorId']);
        $this->assertTrue($disks[1]['removable']);
        $this->assertSame('', $disks[2]['idPath'], 'missing udev data stays empty so the matcher can refuse');
    }

    public function testEnumerationUsesTheDocumentedLsblkColumns(): void
    {
        $runner = $this->runner();
        DiskInventory::enumerate($runner);
        $this->assertSame(['lsblk', '-J', '-b', '-d', '-o', 'NAME,KNAME,TYPE,SIZE,SERIAL,WWN,MODEL,TRAN,RM,HOTPLUG,PTTYPE'], $runner->argvList()[0]);
    }

    public function testSingleDeviceReadPassesThePath(): void
    {
        $runner = $this->runner();
        DiskInventory::enumerate($runner, '/dev/sda');
        $first = $runner->argvList()[0];
        $this->assertSame('/dev/sda', end($first));
    }

    public function testLsblkFailureIsARefusalNotAnEmptyList(): void
    {
        $runner = new RecordingRunner([['prefix' => ['lsblk'], 'result' => ['code' => 1, 'out' => '', 'err' => 'boom']]]);
        try {
            DiskInventory::enumerate($runner);
            $this->fail('an empty inventory was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('inventoryFailed', $e->refusalCode());
        }
    }

    public function testGarbageOutputIsRefused(): void
    {
        $this->expectException(InstallRefusal::class);
        DiskInventory::parse('not json', new RecordingRunner());
    }

    public function testUnsafeKernelNameIsRefused(): void
    {
        $this->expectException(InstallRefusal::class);
        DiskInventory::parse('{"blockdevices":[{"name":"x","kname":"../sda","type":"disk","size":1}]}', new RecordingRunner());
    }
}
