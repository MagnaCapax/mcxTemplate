<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\TargetFile;
use PHPUnit\Framework\TestCase;

// TargetFileTest: a write into the new root must never follow a symlink out of it.
final class TargetFileTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-target-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/root/etc', 0755, true);
        mkdir($this->dir . '/outside', 0755);
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir . '/root/etc/resolv.conf/sub');
        @rmdir($this->dir . '/root/etc/resolv.conf');
        foreach (['/root/etc/resolv.conf', '/outside/victim', '/root/etc2', '/root/escape'] as $p) {
            if (is_link($this->dir . $p) || is_file($this->dir . $p)) {
                @unlink($this->dir . $p);
            }
        }
        @rmdir($this->dir . '/root/etc');
        @rmdir($this->dir . '/root');
        @rmdir($this->dir . '/outside');
        @rmdir($this->dir);
    }

    public function testWritesAFreshFile(): void
    {
        TargetFile::write($this->dir . '/root', '/etc/resolv.conf', "nameserver 198.51.100.2\n");
        $this->assertSame("nameserver 198.51.100.2\n", file_get_contents($this->dir . '/root/etc/resolv.conf'));
    }

    public function testSymlinkedDestinationIsReplacedNotFollowed(): void
    {
        file_put_contents($this->dir . '/outside/victim', 'keep');
        symlink($this->dir . '/outside/victim', $this->dir . '/root/etc/resolv.conf');
        TargetFile::write($this->dir . '/root', '/etc/resolv.conf', "nameserver 198.51.100.2\n");
        $this->assertSame('keep', file_get_contents($this->dir . '/outside/victim'));
        $this->assertFalse(is_link($this->dir . '/root/etc/resolv.conf'));
        $this->assertSame("nameserver 198.51.100.2\n", file_get_contents($this->dir . '/root/etc/resolv.conf'));
    }

    public function testSymlinkedParentDirectoryIsRefused(): void
    {
        symlink($this->dir . '/outside', $this->dir . '/root/escape');
        try {
            TargetFile::write($this->dir . '/root', '/escape/victim', 'x');
            $this->fail('a write escaped the target root');
        } catch (InstallRefusal $e) {
            $this->assertSame('targetWriteRefused', $e->refusalCode());
        }
        $this->assertFileDoesNotExist($this->dir . '/outside/victim');
    }

    public function testDirectoryAtTheTargetPathIsRefusedAndKept(): void
    {
        mkdir($this->dir . '/root/etc/resolv.conf/sub', 0755, true);
        try {
            TargetFile::write($this->dir . '/root', '/etc/resolv.conf', 'x');
            $this->fail('a directory at the target path was replaced');
        } catch (InstallRefusal $e) {
            $this->assertSame('targetWriteRefused', $e->refusalCode());
            $this->assertStringContainsString('is a directory', $e->getMessage());
        }
        $this->assertDirectoryExists($this->dir . '/root/etc/resolv.conf/sub');
    }

    public function testRelativeAndTraversalPathsAreRefused(): void
    {
        foreach (['etc/resolv.conf', '/etc/../../x', ''] as $bad) {
            try {
                TargetFile::write($this->dir . '/root', $bad, 'x');
                $this->fail("accepted $bad");
            } catch (InstallRefusal $e) {
                $this->assertSame('targetWriteRefused', $e->refusalCode());
            }
        }
    }
}
