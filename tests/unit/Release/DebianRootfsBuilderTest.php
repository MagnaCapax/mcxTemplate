<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Install\CommandRunner;
use Lib\Install\InstallRefusal;
use Lib\Install\RecordingRunner;
use Lib\Release\DebianRootfsBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TarFixture.php';

// FakeBuildRunner stands in for mmdebstrap and tar: "mmdebstrap" writes a small tar to its TARGET argument,
// "tar -xOf" prints the dpkg status the test chose. Nothing runs, no network, no root.
final class FakeBuildRunner implements CommandRunner
{
    /** @var array<int,array{argv:array<int,string>,env:array<string,string>}> */
    public array $calls = [];
    public string $tarBytes;
    public string $status;
    public int $mmdebstrapCode = 0;

    public function __construct(string $tarBytes, string $status)
    {
        $this->tarBytes = $tarBytes;
        $this->status = $status;
    }

    public function run(array $argv, ?string $stdin = null, array $env = []): array
    {
        $this->calls[] = ['argv' => $argv, 'env' => $env];
        if ($argv[0] === 'tar') {
            return ['code' => 0, 'out' => $this->status, 'err' => ''];
        }
        if ($this->mmdebstrapCode !== 0) {
            return ['code' => $this->mmdebstrapCode, 'out' => '', 'err' => "E: download failed\n"];
        }
        // mmdebstrap [OPTION...] SUITE TARGET [MIRROR]: the target is the first argument ending in .tar.
        foreach ($argv as $arg) {
            if (substr($arg, -4) === '.tar') {
                file_put_contents($arg, $this->tarBytes);
            }
        }
        return ['code' => 0, 'out' => '', 'err' => ''];
    }
}

final class DebianRootfsBuilderTest extends TestCase
{
    private const LIST = __DIR__ . '/../../../distros/debian/12/minimal.packages';
    private const BANNED_STATUS = __DIR__ . '/../../fixtures/release/dpkg-status-banned';
    private const CLEAN_STATUS = "Package: base-files\nStatus: install ok installed\n\nPackage: openssh-server\nStatus: install ok installed\n";

    private string $dir = '';
    private string $mmdebstrap = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-deb-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        // An executable stand-in, so the "is mmdebstrap installed" check passes; it is never run (the runner is fake).
        $this->mmdebstrap = $this->dir . '/mmdebstrap';
        file_put_contents($this->mmdebstrap, "#!/bin/sh\nexit 99\n");
        chmod($this->mmdebstrap, 0755);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function listFile(string $text): string
    {
        file_put_contents($this->dir . '/list.packages', $text);
        return $this->dir . '/list.packages';
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    public function testArgumentsAreParsedAndAnythingElseIsUsage(): void
    {
        $this->assertSame(['suite' => 'bookworm', 'packages' => '/p', 'out' => '/o.tar.gz', 'mirror' => ''],
            DebianRootfsBuilder::parseArguments(['--suite=bookworm', '--packages=/p', '--out=/o.tar.gz']));
        $this->assertSame('http://deb.example.test/debian', DebianRootfsBuilder::parseArguments(['--suite=bookworm', '--packages=/p',
            '--out=/o.tar.gz', '--mirror=http://deb.example.test/debian'])['mirror']);
        foreach ([['--suite=bookworm', '--packages=/p'], ['--suite=bookworm', '--packages=/p', '--out=/o.tar.gz', '--force'],
            ['--suite=', '--packages=/p', '--out=/o.tar.gz'], ['bookworm', '--packages=/p', '--out=/o.tar.gz']] as $args) {
            $this->assertSame('usage', $this->refusal(static fn () => DebianRootfsBuilder::parseArguments($args)), implode(' ', $args));
        }
    }

    public function testEveryLineOfTheShippedListNamesWhatNeedsIt(): void
    {
        $lines = array_filter(array_map('trim', file(self::LIST) ?: []), static fn (string $l): bool => $l !== '' && $l[0] !== '#');
        $this->assertNotSame([], $lines);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^\S+\s+#\s*needed by\s+\S/', $line);
        }
        $packages = DebianRootfsBuilder::readPackageList((string) file_get_contents(self::LIST));
        $this->assertCount(count($lines), $packages);
        foreach (['linux-image-amd64', 'grub-pc', 'mdadm', 'openssh-server', 'php-cli', 'ifupdown', 'iproute2'] as $needed) {
            $this->assertArrayHasKey($needed, $packages);
        }
    }

    public function testAListLineWithoutAReasonOrRepeatedIsRefused(): void
    {
        $this->assertSame('packageListInvalid', $this->refusal(static fn () => DebianRootfsBuilder::readPackageList("mdadm  # needed by task 32\nvim\n")));
        $this->assertSame('packageListInvalid', $this->refusal(static fn () => DebianRootfsBuilder::readPackageList("mdadm # needed by a\nmdadm # needed by b\n")));
        $this->assertSame('packageListInvalid', $this->refusal(static fn () => DebianRootfsBuilder::readPackageList("--root=/ # needed by nothing\n")));
        $this->assertSame('packageListInvalid', $this->refusal(static fn () => DebianRootfsBuilder::readPackageList("# only a comment\n")));
    }

    public function testABannedPackageInTheListIsRefusedBeforeAnyCommand(): void
    {
        $runner = new RecordingRunner();
        $list = $this->listFile("mdadm  # needed by task 32\nfail2ban  # needed by nobody\n");
        $b = new DebianRootfsBuilder($runner, true, $this->mmdebstrap);
        $this->assertSame('bannedPackageListed', $this->refusal(fn () => $b->build('bookworm', $list, $this->dir . '/out.tar.gz')));
        $this->assertSame([], $runner->calls());
    }

    public function testAnExistingOutputIsNeverReplaced(): void
    {
        file_put_contents($this->dir . '/out.tar.gz', 'old');
        $runner = new RecordingRunner();
        $b = new DebianRootfsBuilder($runner, true, $this->mmdebstrap);
        $this->assertSame('outputExists', $this->refusal(fn () => $b->build('bookworm', self::LIST, $this->dir . '/out.tar.gz')));
        $this->assertSame('old', file_get_contents($this->dir . '/out.tar.gz'));
        $this->assertSame([], $runner->calls());
    }

    public function testNonRootAndAMissingMmdebstrapAreRefusedBeforeAnyCommand(): void
    {
        $runner = new RecordingRunner();
        $out = $this->dir . '/out.tar.gz';
        $this->assertSame('notRoot', $this->refusal(fn () => (new DebianRootfsBuilder($runner, false, $this->mmdebstrap))->build('bookworm', self::LIST, $out)));
        $this->assertSame('mmdebstrapMissing', $this->refusal(fn () => (new DebianRootfsBuilder($runner, true, $this->dir . '/none/mmdebstrap'))->build('bookworm', self::LIST, $out)));
        $this->assertSame('mmdebstrapMissing', $this->refusal(fn () => (new DebianRootfsBuilder($runner, true, ''))->build('bookworm', self::LIST, $out)));
        $this->assertSame([], $runner->calls());
        $this->assertFileDoesNotExist($out);
    }

    public function testMalformedInputsAreRefused(): void
    {
        $b = new DebianRootfsBuilder(new RecordingRunner(), true, $this->mmdebstrap);
        $out = $this->dir . '/out.tar.gz';
        foreach ([['Bookworm', self::LIST, $out, ''], ['bookworm', self::LIST, 'out.tar.gz', ''], ['bookworm', self::LIST, $this->dir . '/out.tar', ''],
            ['bookworm', self::LIST, $this->dir . '/../out.tar.gz', ''], ['bookworm', self::LIST, $this->dir . '/missing/out.tar.gz', ''],
            ['bookworm', $this->dir . '/missing.packages', $out, ''], ['bookworm', self::LIST, $out, 'ftp://mirror.example.test/'],
            ['bookworm', self::LIST, $out, 'http://mirror.example.test/ debian']] as [$suite, $list, $o, $mirror]) {
            $this->assertSame('buildInputInvalid', $this->refusal(fn () => $b->build($suite, $list, $o, $mirror)), $o . ' ' . $mirror);
        }
    }

    public function testABuildRunsMmdebstrapMinbaseAndPublishesTheGzippedTar(): void
    {
        $tar = TarFixture::bytes([['./etc/os-release', "ID=debian\nVERSION_ID=\"12\"\n"]]);
        $runner = new FakeBuildRunner($tar, self::CLEAN_STATUS);
        $out = $this->dir . '/debian-12-minimal-rootfs.tar.gz';
        $r = (new DebianRootfsBuilder($runner, true, $this->mmdebstrap))->build('bookworm', self::LIST, $out, 'http://deb.example.test/debian');

        $this->assertSame($tar, gzdecode((string) file_get_contents($out)), 'the output is the mmdebstrap tar, gzipped');
        $this->assertSame(['suite' => 'bookworm', 'packages' => 2, 'bytes' => filesize($out), 'sha256' => hash_file('sha256', $out), 'out' => $out], $r);
        $this->assertSame([basename($out), 'mmdebstrap'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])),
            'only the output is left next to it: no build directory, no part file');

        $argv = $runner->calls[0]['argv'];
        $this->assertSame($this->mmdebstrap, $argv[0]);
        $this->assertContains('--variant=minbase', $argv);
        $this->assertContains('--format=tar', $argv);
        $include = (string) current(array_filter($argv, static fn (string $a): bool => strncmp($a, '--include=', 10) === 0));
        $this->assertSame(array_keys(DebianRootfsBuilder::readPackageList((string) file_get_contents(self::LIST))), explode(',', substr($include, 10)));
        $this->assertSame([], array_filter($argv, static fn (string $a): bool => stripos($a, 'Recommends') !== false), 'Recommends stay at mmdebstrap\'s default (off)');
        $mkdir = array_values(array_filter($argv, static fn (string $a): bool => strncmp($a, '--customize-hook=mkdir -p ', 26) === 0));
        $this->assertCount(1, $mkdir, 'one hook creates the overlay directories before the uploads');
        $mkdirAt = array_search($mkdir[0], $argv, true);
        foreach (array_keys(DebianRootfsBuilder::OVERLAY) as $inside) {
            $upload = array_filter($argv, static fn (string $a): bool => preg_match('#^--customize-hook=upload /\S+ ' . preg_quote($inside, '#') . '$#', $a) === 1);
            $this->assertCount(1, $upload);
            $this->assertGreaterThan($mkdirAt, array_key_first($upload));
            $this->assertStringContainsString('"$1' . dirname($inside) . '"', $mkdir[0]);
        }
        $this->assertStringContainsString('net.ifnames=0', DebianRootfsBuilder::OVERLAY['/etc/default/grub.d/50-mcxtemplate-net.cfg']);
        $this->assertSame(['bookworm', 'http://deb.example.test/debian'], [$argv[count($argv) - 3], $argv[count($argv) - 1]]);
        $this->assertStringStartsWith($this->dir . '/.build-', $runner->calls[0]['env']['TMPDIR']);
        $this->assertSame(['tar', '-xOf'], array_slice($runner->calls[1]['argv'], 0, 2));
    }

    public function testABannedPackageInTheBuiltImageRefusesAndLeavesNothing(): void
    {
        $runner = new FakeBuildRunner(TarFixture::bytes([['./etc/os-release', "ID=debian\n"]]), (string) file_get_contents(self::BANNED_STATUS));
        $out = $this->dir . '/out.tar.gz';
        $code = $this->refusal(fn () => (new DebianRootfsBuilder($runner, true, $this->mmdebstrap))->build('bookworm', self::LIST, $out));
        $this->assertSame('bannedPackageInstalled', $code);
        $this->assertSame(['mmdebstrap'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])), 'no output, no build directory, no part file');
    }

    public function testAFailedMmdebstrapRefusesWithItsLastErrorAndLeavesNothing(): void
    {
        $runner = new FakeBuildRunner('', self::CLEAN_STATUS);
        $runner->mmdebstrapCode = 1;
        $b = new DebianRootfsBuilder($runner, true, $this->mmdebstrap);
        $this->assertSame('mmdebstrapFailed', $this->refusal(fn () => $b->build('bookworm', self::LIST, $this->dir . '/out.tar.gz')));
        $this->assertSame('E: download failed', $b->lastError());
        $this->assertSame(['mmdebstrap'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
    }
}
