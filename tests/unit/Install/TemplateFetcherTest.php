<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\RecordingRunner;
use Lib\Install\TemplateFetcher;
use Lib\Install\TmpfsGrower;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NetworkFixtures.php';

// TemplateFetcherTest: the space and memory refusals come before the first byte is requested.
final class TemplateFetcherTest extends TestCase
{
    private const SIZE = 1000;
    private const MARGIN = TemplateFetcher::MARGIN_BYTES;

    private function refusal(TemplateFetcher $f, string $dest = '/run/x/template.tar.gz'): string
    {
        try {
            $f->fetch('https://files.example.test/t.tar.gz', $dest, self::SIZE);
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    public function testTooLittleFilesystemSpaceRefusesBeforeDownloading(): void
    {
        $server = new FakeNodeCore('x');
        $f = new TemplateFetcher($server, static fn (string $d) => self::SIZE + self::MARGIN - 1, static fn (): int => 0);
        $this->assertSame('templateNoSpace', $this->refusal($f));
        $this->assertSame([], $server->downloads);
    }

    public function testUnknownFilesystemSpaceRefuses(): void
    {
        $server = new FakeNodeCore('x');
        $f = new TemplateFetcher($server, static fn (string $d) => false, static fn (): int => 0);
        $this->assertSame('templateNoSpace', $this->refusal($f));
    }

    public function testTooLittleFreeMemoryRefusesBeforeDownloading(): void
    {
        $server = new FakeNodeCore('x');
        $f = new TemplateFetcher($server, static fn (string $d) => PHP_INT_MAX, static fn (): int => self::SIZE + self::MARGIN - 1);
        $this->assertSame('templateNoSpace', $this->refusal($f));
        $this->assertSame([], $server->downloads);
    }

    public function testExactlyEnoughSpaceAndMemoryDownloadsIntoTheGivenPath(): void
    {
        $dir = sys_get_temp_dir() . '/mcx-fetch-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $server = new FakeNodeCore('template-bytes');
        $f = new TemplateFetcher($server, static fn (string $d) => self::SIZE + self::MARGIN, static fn (): int => self::SIZE + self::MARGIN);
        $this->assertSame('', $this->refusal($f, $dir . '/template.tar.gz'));
        $this->assertSame('template-bytes', file_get_contents($dir . '/template.tar.gz'));
        $this->assertSame(self::SIZE, $server->downloads[0]['max']);
        unlink($dir . '/template.tar.gz');
        rmdir($dir);
    }

    // The numbers below were measured on a throwaway VM booted from the rescue netboot files (2026-11-12): the
    // initramfs mounts /run as tmpfs at 10% of RAM. A 15 GiB unit (MemTotal 15347336 kB) has /run of 1571569664
    // bytes with 1571102720 free and MemAvailable 14257584 kB = 14599766016 bytes; a 4 GiB unit (MemTotal
    // 4011696 kB) has /run of 410800128 with 410333184 free and MemAvailable 3011252 kB = 3083522048 bytes.
    // The ubuntu-24.04-base template registered as 1516918290 bytes needs that plus MARGIN_BYTES (268435456).
    private const UBUNTU_SIZE = 1516918290;
    private const UBUNTU_NEED = 1785353746;
    // Used bytes in /run on both units: 1571569664 - 1571102720 = 410800128 - 410333184 = 466944.
    // The new limit is used + need = 466944 + 1785353746.
    private const GROWN_LIMIT = 1785820690;

    /**
     * Build a fetcher on a real temp staging dir whose filesystem is "tmpfs of $total bytes, $free free" until
     * the writer has been asked to remount it, and then offers GROWN_LIMIT minus the used bytes.
     *
     * @return array{0:TemplateFetcher,1:FakeNodeCore,2:RecordingRunner,3:RecordingRunner,4:string}
     */
    private function tmpfsWorld(int $total, int $free, int $mem, string $findmntOut = "tmpfs /run\n", int $mountCode = 0): array
    {
        $dir = sys_get_temp_dir() . '/mcx-grow-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $reader = new RecordingRunner();
        $reader->addResponse(['findmnt'], $findmntOut);
        $writer = new RecordingRunner();
        $writer->addResponse(['mount'], '', $mountCode, $mountCode === 0 ? '' : 'mount: /run: not allowed');
        $server = new FakeNodeCore('template-bytes');
        $freeNow = static fn (string $d) => $writer->calls() === [] || $mountCode !== 0 ? $free : self::GROWN_LIMIT - ($total - $free);
        $f = new TemplateFetcher($server, $freeNow, static fn (): int => $mem, new TmpfsGrower($reader, $writer, static fn (string $d) => $total));
        return [$f, $server, $reader, $writer, $dir];
    }

    private function fetchUbuntu(TemplateFetcher $f, string $dir): string
    {
        try {
            $f->fetch('https://files.example.test/t.tar.gz', $dir . '/template.tar.gz', self::UBUNTU_SIZE);
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    private function removeDir(string $dir): void
    {
        @unlink($dir . '/template.tar.gz');
        @rmdir($dir);
    }

    public function testRunTooSmallOnA15GibUnitButRamEnoughIsGrownAndTheDownloadProceeds(): void
    {
        $this->assertSame(1785353746, self::UBUNTU_SIZE + TemplateFetcher::MARGIN_BYTES, 'the brief\'s need');
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(1571569664, 1571102720, 14599766016);
        $this->assertSame('', $this->fetchUbuntu($f, $dir));
        $this->assertSame([['mount', '-o', 'remount,size=1785820690', '/run']], $writer->argvList());
        $this->assertSame(['findmnt', '-n', '-o', 'FSTYPE,TARGET', '--target', $dir], $reader->argvList()[0]);
        $this->assertCount(1, $server->downloads);
        $this->assertSame(self::UBUNTU_SIZE, $server->downloads[0]['max']);
        $this->removeDir($dir);
    }

    public function testRunTooSmallOnA4GibUnitButRamEnoughIsGrownToTheSameLimit(): void
    {
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(410800128, 410333184, 3083522048);
        $this->assertSame('', $this->fetchUbuntu($f, $dir));
        $this->assertSame([['mount', '-o', 'remount,size=1785820690', '/run']], $writer->argvList());
        $this->assertCount(1, $server->downloads);
        $this->removeDir($dir);
    }

    public function testRamTooSmallStillRefusesAndNeverRemounts(): void
    {
        // One byte under the template plus margin: RAM cannot hold it, so /run must not be grown.
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(1571569664, 1571102720, 1785353745);
        $this->assertSame('templateNoSpace', $this->fetchUbuntu($f, $dir));
        $this->assertSame([], $writer->calls());
        $this->assertSame([], $reader->calls());
        $this->assertSame([], $server->downloads);
        $this->removeDir($dir);
    }

    public function testUnknownRamNeverGrowsTheFilesystem(): void
    {
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(1571569664, 1571102720, 0);
        $this->assertSame('templateNoSpace', $this->fetchUbuntu($f, $dir));
        $this->assertSame([], $writer->calls());
        $this->assertSame([], $server->downloads);
        $this->removeDir($dir);
    }

    public function testAFilesystemThatIsNotTmpfsIsNeverRemounted(): void
    {
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(1571569664, 1571102720, 14599766016, "ext4 /\n");
        $this->assertSame('templateNoSpace', $this->fetchUbuntu($f, $dir));
        $this->assertSame([], $writer->calls());
        $this->assertSame([], $server->downloads);
        $this->removeDir($dir);
    }

    public function testARefusedRemountLeavesTheNamedRefusal(): void
    {
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(1571569664, 1571102720, 14599766016, "tmpfs /run\n", 32);
        $this->assertSame('templateNoSpace', $this->fetchUbuntu($f, $dir));
        $this->assertCount(1, $writer->calls(), 'the remount was tried once and not repeated');
        $this->assertSame([], $server->downloads);
        $this->removeDir($dir);
    }

    public function testATemplateThatAlreadyFitsDownloadsWithoutAnyMountCommand(): void
    {
        [$f, $server, $reader, $writer, $dir] = $this->tmpfsWorld(2 * self::UBUNTU_NEED, self::UBUNTU_NEED, self::UBUNTU_NEED);
        $this->assertSame('', $this->fetchUbuntu($f, $dir));
        $this->assertSame([], $reader->calls());
        $this->assertSame([], $writer->calls());
        $this->assertCount(1, $server->downloads);
        $this->removeDir($dir);
    }

    public function testADownloadErrorIsANamedRefusal(): void
    {
        $server = new FakeNodeCore('x');
        $server->downloadError = 'http404';
        $f = new TemplateFetcher($server, static fn (string $d) => PHP_INT_MAX, static fn (): int => 0);
        $this->assertSame('templateDownloadFailed', $this->refusal($f));
    }

    public function testMemAvailableIsReadFromProcMeminfoInBytes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mcxmem');
        file_put_contents($file, "MemTotal:       16000000 kB\nMemAvailable:    2048 kB\n");
        $this->assertSame(2048 * 1024, TemplateFetcher::readMemAvailable($file));
        file_put_contents($file, "garbage\n");
        $this->assertSame(0, TemplateFetcher::readMemAvailable($file), '0 means unknown');
        unlink($file);
        $this->assertSame(0, TemplateFetcher::readMemAvailable($file));
    }
}
