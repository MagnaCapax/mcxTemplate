<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\TemplateFetcher;
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
