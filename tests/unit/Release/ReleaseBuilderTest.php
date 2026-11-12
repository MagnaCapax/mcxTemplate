<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Install\InstallRefusal;
use Lib\Install\ProcessRunner;
use Lib\Install\RecordingRunner;
use Lib\Release\ReleaseBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TarFixture.php';

// ReleaseBuilderTest: the refusals run against a recording runner (no command executes). One end-to-end
// test runs the real tools in a throw-away git repository when git, tar, pigz and rsync exist.
final class ReleaseBuilderTest extends TestCase
{
    private const API = 'https://nodecore.example.test/';
    private const BASE = 'https://files.example.test/templates';
    private const HEAD = '0123456789abcdef0123456789abcdef01234567';
    private const SHADOW = "root:*:19000:0:99999:7:::\n";

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-rel-' . bin2hex(random_bytes(4));
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

    private function cleanGit(): RecordingRunner
    {
        $r = new RecordingRunner();
        $r->addResponse(['git', '-C', '/repo', 'status'], '');
        $r->addResponse(['git', '-C', '/repo', 'rev-parse'], self::HEAD . "\n");
        return $r;
    }

    private function rootfs(array $extra = []): string
    {
        return TarFixture::write($this->dir . '/rootfs.tar.gz', array_merge([['etc/shadow', self::SHADOW], ['etc/os-release', "ID=debian\nVERSION_ID=\"12\"\n"]], $extra));
    }

    private function code(ReleaseBuilder $b, string $rootfs, string $out, array $over = []): string
    {
        $a = $over + ['name' => 'debian-12-base', 'version' => '20261111', 'api' => self::API, 'base' => self::BASE];
        try {
            $b->build($rootfs, $a['name'], $a['version'], $out, $a['api'], $a['base']);
        } catch (InstallRefusal $e) {
            return $e->refusalCode() . ': ' . $e->getMessage();
        }
        return '';
    }

    public function testADirtyEngineRepositoryIsRefusedBeforeAnythingIsBuilt(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['git', '-C', '/repo', 'status'], " M installNode.php\n?? scratch.txt\n");
        $result = $this->code(new ReleaseBuilder($runner, '/repo', true), $this->rootfs(), $this->dir . '/out');
        $this->assertStringStartsWith('dirtyTree', $result);
        $this->assertCount(1, $runner->calls(), 'only git status ran');
        $this->assertDirectoryDoesNotExist($this->dir . '/out');
    }

    public function testNonRootIsRefusedBeforeAnyCommand(): void
    {
        $runner = $this->cleanGit();
        $this->assertStringStartsWith('notRoot', $this->code(new ReleaseBuilder($runner, '/repo', false), $this->rootfs(), $this->dir . '/out'));
        $this->assertSame([], $runner->calls());
    }

    public function testARootfsWithKeysOrAPasswordIsRefusedAndNothingIsWritten(): void
    {
        $runner = $this->cleanGit();
        $rootfs = TarFixture::write($this->dir . '/bad.tar.gz', [['etc/shadow', "root:\$6\$x\$y:1:::::\n"], ['root/.ssh/authorized_keys', 'k'],
            ['root/.ssh/id_ed25519', TarFixture::pem('OPENSSH')]]);
        $result = $this->code(new ReleaseBuilder($runner, '/repo', true), $rootfs, $this->dir . '/out');
        $this->assertStringStartsWith('contentScanRefused', $result);
        $this->assertStringContainsString('authorizedKeys root/.ssh/authorized_keys', $result);
        $this->assertStringContainsString('privateKey root/.ssh/id_ed25519', $result);
        $this->assertStringContainsString('rootPassword etc/shadow', $result);
        $this->assertDirectoryDoesNotExist($this->dir . '/out');
        $programs = array_map(static fn (array $a): string => $a[1] === '-C' ? $a[3] : $a[0], $runner->argvList());
        $this->assertSame(['status', 'rev-parse'], $programs, 'no export, rehydrate or archive step ran');
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function badInputs(): array
    {
        return [
            'upper case name' => [['name' => 'Debian']], 'name with a slash' => [['name' => 'a/b']], 'name starting with a dash' => [['name' => '-x']],
            'free text version' => [['version' => 'latest']], 'version with a path' => [['version' => '20261111/../x']],
            'http api url' => [['api' => 'http://nodecore.example.test/']], 'http artifact base' => [['base' => 'http://files.example.test']],
            'artifact base with a trailing slash' => [['base' => 'https://files.example.test/templates/']],
            'api url with a quote' => [['api' => "https://a.example/'x"]],
        ];
    }

    /**
     * @dataProvider badInputs
     * @param array<string,string> $over
     */
    public function testMalformedInputsAreRefusedBeforeAnyCommand(array $over): void
    {
        $runner = $this->cleanGit();
        $result = $this->code(new ReleaseBuilder($runner, '/repo', true), $this->rootfs(), $this->dir . '/out', $over);
        $this->assertNotSame('', $result);
        $this->assertSame([], $runner->calls());
    }

    public function testARelativeOrMissingInputPathIsRefused(): void
    {
        $b = new ReleaseBuilder($this->cleanGit(), '/repo', true);
        $this->assertStringStartsWith('releaseInputInvalid', $this->code($b, $this->rootfs(), 'out'));
        $this->assertStringStartsWith('releaseInputInvalid', $this->code($b, $this->rootfs(), $this->dir . '/../out'));
        $this->assertStringStartsWith('releaseInputInvalid', $this->code($b, $this->dir . '/missing.tar.gz', $this->dir . '/out'));
    }

    public function testExistingReleaseFilesAreNeverOverwritten(): void
    {
        mkdir($this->dir . '/out');
        file_put_contents($this->dir . '/out/debian-12-base-20261111.tar.gz', 'old release');
        $result = $this->code(new ReleaseBuilder($this->cleanGit(), '/repo', true), $this->rootfs(), $this->dir . '/out');
        $this->assertStringStartsWith('outputExists', $result);
        $this->assertSame('old release', file_get_contents($this->dir . '/out/debian-12-base-20261111.tar.gz'));
    }

    // throwAwayRepo commits a copy of the engine files to a new git repository and returns its path.
    private function throwAwayRepo(ProcessRunner $run): string
    {
        foreach (['git', 'tar', 'pigz', 'rsync', 'cp', 'sh'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) === '') {
                $this->markTestSkipped($bin . ' is not installed');
            }
        }
        $real = realpath(__DIR__ . '/../../..');
        $repo = $this->dir . '/repo';
        mkdir($repo);
        $must = function (array $argv, ?string $cwd = null) use ($run): void {
            $r = $run->run($cwd === null ? $argv : array_merge(['git', '-C', $cwd], $argv));
            $this->assertSame(0, $r['code'], implode(' ', $argv) . ': ' . $r['err']);
        };
        foreach (['installNode.php', 'installTemplate.php', 'bootstrap', 'distros', 'src', 'tools'] as $item) {
            $must(['cp', '-a', $real . '/' . $item, $repo . '/' . $item]);
        }
        $must(['init', '-q'], $repo);
        $must(['add', '-A'], $repo);
        $must(['-c', 'user.name=t', '-c', 'user.email=t@example.test', 'commit', '-q', '-m', 'engine'], $repo);
        return $repo;
    }

    /**
     * A real Debian capture: etc/os-release is a relative symlink to usr/lib/os-release, and an old capture can
     * hold a stale /opt/mcxTemplate. Expected: distro read through the fallback, and the pinned engine replaces the
     * stale copy (check-template passes and the stale file is gone).
     */
    public function testASymlinkedOsReleaseAndAStaleEngineInTheCapture(): void
    {
        $run = new ProcessRunner();
        $repo = $this->throwAwayRepo($run);
        $rootfs = TarFixture::write($this->dir . '/rootfs.tar.gz', [['etc/shadow', self::SHADOW],
            ['usr/lib/os-release', "ID=debian\nVERSION_ID=\"13\"\n"], ['etc/os-release', '../usr/lib/os-release', '2'],
            ['opt/mcxTemplate/Debian/bookworm/stale-engine.txt', "old layout\n"]]);
        $out = $this->dir . '/out';
        $builder = new ReleaseBuilder($run, $repo, false, true, static fn (): int => 1793318400);
        $r = $builder->build($rootfs, 'debian-13-base', '20261111', $out, self::API, self::BASE);

        $manifest = json_decode((string) file_get_contents($out . '/debian-13-base-20261111.tar.gz.manifest.json'), true);
        $this->assertSame(['debian', '13'], [$manifest['distro'], $manifest['distroVersion']]);
        $list = $run->run(['tar', '-tzf', $r['template']])['out'];
        $this->assertStringContainsString('rootfs/opt/mcxTemplate/distros/configure.php', $list);
        $this->assertStringNotContainsString('stale-engine.txt', $list, 'the pinned engine replaces a stale copy');
    }

    public function testEndToEndWithTheRealToolsInAThrowAwayRepository(): void
    {
        $run = new ProcessRunner();
        $repo = $this->throwAwayRepo($run);

        $rootfs = $this->rootfs();
        $out = $this->dir . '/out';
        $builder = new ReleaseBuilder($run, $repo, false, true, static fn (): int => 1793318400);
        $r = $builder->build($rootfs, 'debian-12-base', '20261111', $out, self::API, self::BASE);

        $manifest = json_decode((string) file_get_contents($out . '/debian-12-base-20261111.tar.gz.manifest.json'), true);
        $this->assertSame('debian-12-base', $manifest['name']);
        $this->assertSame('debian', $manifest['distro']);
        $this->assertSame('12', $manifest['distroVersion']);
        $this->assertSame(hash_file('sha256', $r['template']), $manifest['sha256']);
        $this->assertSame(filesize($r['template']), $manifest['sizeBytes']);
        $this->assertSame(self::BASE . '/debian-12-base-20261111.tar.gz', $manifest['url']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{40}$/D', $manifest['engineCommit']));
        $this->assertSame('2026-10-30T00:00:00Z', $manifest['builtAt'], 'the clock is injected');
        $this->assertSame($manifest['sha256'] . "  debian-12-base-20261111.tar.gz\n", file_get_contents($out . '/debian-12-base-20261111.tar.gz.sha256'));

        $list = $run->run(['tar', '-tzf', $r['template']])['out'];
        $this->assertStringContainsString('rootfs/etc/shadow', $list);
        $this->assertStringContainsString('rootfs/opt/mcxTemplate/installTemplate.php', $list);
        $this->assertStringContainsString('rootfs/opt/mcxTemplate/distros/ubuntu/common/tasks/22-render-netplan.php', $list);
        $this->assertStringNotContainsString('.git/', $list, 'the engine is the HEAD export, not the work tree');

        $engine = $run->run(['tar', '-tzf', $r['engine']])['out'];
        $this->assertStringContainsString('installNode.php', $engine);
        $this->assertStringContainsString('src/Lib/Install/NetworkInstall.php', $engine);
        $this->assertStringContainsString('tools/check-template.php', $engine);
        $this->assertStringNotContainsString('tests/', $engine, 'only the installer files');
        $this->assertSame(hash_file('sha256', $r['engine']), $r['engineSha256']);

        $bootstrap = (string) file_get_contents($r['bootstrap']);
        $this->assertStringContainsString("ENGINE_SHA256='" . $r['engineSha256'] . "'", $bootstrap);
        $this->assertSame(hash_file('sha256', $r['bootstrap']), $r['bootstrapSha256']);
        $this->assertSame([], glob($out . '/.work-*') ?: [], 'the work directory is removed');
    }
}
