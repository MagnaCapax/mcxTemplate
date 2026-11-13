<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Release\TarFixture;

require_once __DIR__ . '/../Release/TarFixture.php';

// CheckTemplateBannedPackageTest: tools/check-template.php refuses a template whose rootfs has a banned package
// installed (exit 3, docs/adr/0006), for a tarball (both member spellings) and for an extracted directory.
final class CheckTemplateBannedPackageTest extends TestCase
{
    private const ENGINE = ['rootfs/opt/mcxTemplate/distros/configure.php', 'rootfs/opt/mcxTemplate/distros/common/templates/x',
        'rootfs/opt/mcxTemplate/distros/debian/templates/x', 'rootfs/opt/mcxTemplate/distros/debian/common/tasks/x'];
    private const STATUS_MEMBER = 'rootfs/var/lib/dpkg/status';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-chk-' . bin2hex(random_bytes(4));
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

    /** @return array{exit:int,output:string} */
    private function audit(string $path, string $flag): array
    {
        $cmd = sprintf('%s %s --path=%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../../tools/check-template.php'), escapeshellarg($path), $flag);
        exec($cmd, $lines, $exit);
        return ['exit' => $exit, 'output' => preg_replace('/\033\[[0-9;]*m/', '', implode("\n", $lines)) ?? ''];
    }

    /** Engine members under $prefix, plus the dpkg status file with $status when it is not null. */
    private function tarball(string $prefix, ?string $status): string
    {
        $entries = array_map(static fn (string $n): array => [$prefix . $n, ''], self::ENGINE);
        if ($status !== null) {
            $entries[] = [$prefix . self::STATUS_MEMBER, $status];
        }
        return TarFixture::write($this->dir . '/t.tar.gz', $entries);
    }

    /** @return array<string,array{0:string}> */
    public static function spellings(): array
    {
        return ['bare member names' => [''], 'dot-slash member names' => ['./']];
    }

    /** @dataProvider spellings */
    public function testATarballWithFail2banInstalledIsRefused(string $prefix): void
    {
        $r = $this->audit($this->tarball($prefix, "Package: bash\nStatus: install ok installed\n\nPackage: fail2ban\nStatus: install ok installed\n\n"), '--tar');
        $this->assertSame(3, $r['exit']);
        $this->assertMatchesRegularExpression('/BANNED\s+banned package fail2ban/', $r['output']);
        $this->assertStringContainsString("\nbannedPackage: fail2ban", "\n" . $r['output']);
    }

    /** @dataProvider spellings */
    public function testRemovedConfigFilesAndCleanTarballsPass(string $prefix): void
    {
        $removed = $this->audit($this->tarball($prefix, "Package: fail2ban\nStatus: deinstall ok config-files\n\nPackage: ufw\nStatus: purge ok not-installed\n\n"), '--tar');
        $this->assertSame(0, $removed['exit'], $removed['output']);
        $clean = $this->audit($this->tarball($prefix, "Package: bash\nStatus: install ok installed\n\n"), '--tar');
        $this->assertSame(0, $clean['exit'], $clean['output']);
        $none = $this->audit($this->tarball($prefix, null), '--tar');
        $this->assertSame(0, $none['exit'], 'a template without a dpkg database has no dpkg package to refuse');
    }

    public function testADirectoryWithAnyBannedPackageIsRefused(): void
    {
        foreach (self::ENGINE as $member) {
            mkdir(dirname($this->dir . '/' . $member), 0755, true);
            file_put_contents($this->dir . '/' . $member, '');
        }
        $this->assertSame(0, $this->audit($this->dir, '--dir')['exit']);
        mkdir($this->dir . '/rootfs/var/lib/dpkg', 0755, true);
        file_put_contents($this->dir . '/' . self::STATUS_MEMBER, "Package: sshguard\nStatus: install ok installed\n\nPackage: crowdsec\nStatus: install ok installed\n\n");
        $r = $this->audit($this->dir, '--dir');
        $this->assertSame(3, $r['exit']);
        $this->assertStringContainsString('bannedPackage: crowdsec, sshguard', $r['output']);
    }
}
