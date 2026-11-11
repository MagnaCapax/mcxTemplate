<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\RecordingRunner;
use Lib\Install\SystemStateReader;
use PHPUnit\Framework\TestCase;

// SystemStateReaderTest builds a small fake /proc and /sys tree in a temp dir.
final class SystemStateReaderTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mcx-state-' . bin2hex(random_bytes(4));
        $p = $this->root . '/proc';
        $s = $this->root . '/sys';
        foreach ([$p . '/self', $s . '/block/md127/slaves', $s . '/block/dm-0/slaves', $s . '/block/nvme0n1/nvme0n1p1', $s . '/block/nvme0n1/nvme0n1p2',
            $s . '/class/net/eth0/device', $s . '/class/net/br0', $s . '/class/net/lo',
            $s . '/block/nvme0n1/holders', $s . '/block/nvme0n1/nvme0n1p1/holders', $s . '/block/nvme0n1/nvme0n1p2/holders', $s . '/block/md127/holders'] as $dir) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($p . '/self/mountinfo', "36 35 259:2 / /mnt/target rw,relatime shared:1 - ext4 /dev/nvme0n1p2 rw\n"
            . "40 35 0:25 / /run rw - tmpfs tmpfs rw\n");
        file_put_contents($p . '/swaps', "Filename\t\t\t\tType\t\tSize\tUsed\tPriority\n/dev/sda2 partition 4194300 0 -2\n");
        touch($s . '/block/md127/slaves/sda1');
        touch($s . '/block/md127/slaves/sdb1');
        touch($s . '/block/dm-0/slaves/nvme0n1p2');
        touch($s . '/block/nvme0n1/nvme0n1p2/holders/dm-0');
        touch($s . '/block/nvme0n1/nvme0n1p2/holders/bcache0');
        touch($s . '/block/md127/holders/dm-1');
        touch($s . '/block/nvme0n1/nvme0n1p1/partition');
        touch($s . '/block/nvme0n1/nvme0n1p2/partition');
        file_put_contents($s . '/class/net/eth0/address', "AA:BB:CC:DD:EE:01\n");
        file_put_contents($s . '/class/net/br0/address', "de:ad:be:ef:00:01\n");
        file_put_contents($s . '/class/net/lo/address', "00:00:00:00:00:00\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    public function testReadsMountsSwapMembersPartitionsAndPhysicalNicMacsOnly(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['findmnt'], "/dev/sdc1\n");
        $state = (new SystemStateReader($this->root . '/proc', $this->root . '/sys'))->read($runner, ['nvme0n1', 'sdc']);

        $this->assertSame(['nvme0n1p2'], $state['mounted'], 'tmpfs is not a device mount');
        $this->assertSame(['sda2'], $state['swapped']);
        $this->assertSame(['md127' => ['sda1', 'sdb1']], $state['md']);
        $this->assertSame(['dm-0' => ['nvme0n1p2']], $state['dm']);
        $this->assertSame(['md127' => ['dm-1'], 'nvme0n1p2' => ['bcache0', 'dm-0']], $state['holders'], 'disks and partitions; empty holders are left out');
        $this->assertSame(['nvme0n1p1', 'nvme0n1p2'], $state['partitions']['nvme0n1']);
        $this->assertSame([], $state['partitions']['sdc']);
        $this->assertSame(['aa:bb:cc:dd:ee:01'], $state['nicMacs'], 'bridge and loopback MACs are not hardware evidence');
        $this->assertSame('sdc1', $state['rescueDisk'], 'an unknown rescue partition is still reported by name');
    }

    public function testRescueMediumPartitionMapsToItsDisk(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['findmnt'], "/dev/nvme0n1p1\n");
        $state = (new SystemStateReader($this->root . '/proc', $this->root . '/sys'))->read($runner, ['nvme0n1']);
        $this->assertSame('nvme0n1', $state['rescueDisk']);
    }

    public function testNoRescueMediumMeansEmpty(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['findmnt'], '', 1);
        $state = (new SystemStateReader($this->root . '/proc', $this->root . '/sys'))->read($runner, ['nvme0n1']);
        $this->assertSame('', $state['rescueDisk']);
    }
}
