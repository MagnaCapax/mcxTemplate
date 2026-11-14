<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Install\ProcessRunner;
use Lib\Release\ContentScan;
use Lib\Release\RuntimeCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TarFixture.php';

// RuntimeCheckTest: which rootfs archives have an executable php (usr/bin/php) and smartctl (usr/sbin/smartctl)
// once symbolic links are followed INSIDE the archive. Archives are built byte by byte, so every expected
// answer below comes from the rule, not from what the code prints.
final class RuntimeCheckTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-rt-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @param array<int,array{0:string,1:string,2?:string,3?:int}> $entries
     * @return string[] the required paths the archive lacks
     */
    private function missing(array $entries): array
    {
        $runtime = new RuntimeCheck();
        ContentScan::scan(TarFixture::write($this->dir . '/r.tar.gz', $entries), null, $runtime);
        return $runtime->missing();
    }

    private const SMARTCTL = ['usr/sbin/smartctl', "x\n", '0', 0755];

    public function testPhpThroughTheAlternativesChainAndSmartctlPass(): void
    {
        $this->assertSame([], $this->missing(TarFixture::runtime()));
    }

    public function testPhpMissingIsNamed(): void
    {
        $this->assertSame(['usr/bin/php'], $this->missing([self::SMARTCTL]));
    }

    public function testADanglingPhpSymlinkIsMissing(): void
    {
        // usr/bin/php -> /etc/alternatives/php, and nothing at the end of the chain
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', '/etc/alternatives/php', '2'], self::SMARTCTL]));
        // the chain ends at a name the archive does not hold
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', '/etc/alternatives/php', '2'], ['etc/alternatives/php', '/usr/bin/php8.2', '2'], self::SMARTCTL]));
    }

    public function testSmartctlMissingIsNamed(): void
    {
        $this->assertSame(['usr/sbin/smartctl'], $this->missing(array_slice(TarFixture::runtime(), 0, 3)));
    }

    public function testBothMissingAreBothNamed(): void
    {
        $this->assertSame(['usr/bin/php', 'usr/sbin/smartctl'], $this->missing([['etc/os-release', "ID=debian\n"]]));
    }

    public function testSymlinkLoopsAreRefusedNotFollowedForever(): void
    {
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', 'php', '2'], self::SMARTCTL]), 'a link to itself');
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', '/etc/alternatives/php', '2'], ['etc/alternatives/php', '/usr/bin/php', '2'], self::SMARTCTL]), 'two links in a ring');
        // a chain one link longer than the bound is refused even though it ends at a real file
        $entries = [['usr/bin/target', "x\n", '0', 0755], self::SMARTCTL, ['usr/bin/php', 'l1', '2']];
        for ($i = 1; $i <= RuntimeCheck::MAX_LINKS; $i++) {
            $entries[] = ['usr/bin/l' . $i, $i === RuntimeCheck::MAX_LINKS ? 'target' : 'l' . ($i + 1), '2'];
        }
        $this->assertSame(['usr/bin/php'], $this->missing($entries), 'MAX_LINKS + 1 links');
    }

    public function testPhpThatIsNotExecutableIsMissing(): void
    {
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', "x\n", '0', 0644], self::SMARTCTL]));
    }

    public function testAbsoluteLinkTargetsStartAtTheRootfsNotTheBuildHost(): void
    {
        // The build host may have a real /usr/bin/php8.2; the archive does not, so this is missing.
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', '/usr/bin/php8.2', '2'], self::SMARTCTL]));
    }

    public function testRelativeLinksAndDotDotAreFollowed(): void
    {
        $this->assertSame([], $this->missing([['usr/lib/php/8.2/php', "x\n", '0', 0755], ['usr/bin/php', '../lib/php/8.2/../8.2/php', '2'], self::SMARTCTL]));
        // ".." above the root stays at the root, as in a chroot
        $this->assertSame([], $this->missing([['usr/bin/php8.2', "x\n", '0', 0755], ['usr/bin/php', '../../../../usr/bin/php8.2', '2'], self::SMARTCTL]));
    }

    public function testADirectoryLinkIsFollowedLikeMergedUsr(): void
    {
        // usr/sbin is a link to bin, and smartctl lives in usr/bin
        $this->assertSame([], $this->missing([['usr/bin/php', "x\n", '0', 0755], ['usr/bin/smartctl', "x\n", '0', 0755], ['usr/sbin', 'bin', '2']]));
    }

    public function testAHardLinkToAnExecutableCounts(): void
    {
        $this->assertSame([], $this->missing([['usr/bin/php8.2', "x\n", '0', 0755], ['usr/bin/php', 'usr/bin/php8.2', '1', 0755], self::SMARTCTL]));
        $this->assertSame(['usr/bin/php'], $this->missing([['usr/bin/php', 'usr/bin/php8.2', '1', 0755], self::SMARTCTL]), 'a hard link to a member the archive lacks');
    }

    public function testALinkTargetLongerThan100BytesIsRead(): void
    {
        $long = '/usr/bin/' . str_repeat('./', 60) . 'php8.2';
        $this->assertGreaterThan(100, strlen($long));
        $this->assertSame([], $this->missing([['usr/bin/php8.2', "x\n", '0', 0755], ['usr/bin/php', $long, '2'], self::SMARTCTL]));
    }

    // A real GNU tar archive (mode and link fields as tar writes them) gives the same answers.
    public function testARealTarArchive(): void
    {
        $run = new ProcessRunner();
        if ($run->run(['tar', '--version'])['code'] !== 0) {
            $this->markTestSkipped('tar is not installed');
        }
        $root = $this->dir . '/root';
        foreach (['', '/usr', '/usr/bin', '/usr/sbin', '/etc', '/etc/alternatives'] as $d) {
            mkdir($root . $d);
        }
        file_put_contents($root . '/usr/bin/php8.2', "x\n");
        chmod($root . '/usr/bin/php8.2', 0755);
        file_put_contents($root . '/usr/sbin/smartctl', "x\n");
        chmod($root . '/usr/sbin/smartctl', 0755);
        symlink('/usr/bin/php8.2', $root . '/etc/alternatives/php');
        symlink('/etc/alternatives/php', $root . '/usr/bin/php');
        $tar = $this->dir . '/real.tar.gz';
        $this->assertSame(0, $run->run(['tar', '-czf', $tar, '-C', $root, '.'])['code']);

        $runtime = new RuntimeCheck();
        $codes = array_column(ContentScan::scan($tar, null, $runtime), 'code');
        $this->assertNotContains('archiveMalformed', $codes, 'the scan reads a real tar to its end');
        $this->assertSame([], $runtime->missing());
    }
}
