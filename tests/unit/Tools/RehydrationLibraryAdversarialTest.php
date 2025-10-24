<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

final class RehydrationLibraryAdversarialTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = $this->mkTempDir('mcx-rehydrate-adv-');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function testEmbedFailsWhenEnginePathMissing(): void
    {
        $this->requireBinaries(['rsync']);
        $staging = $this->mkTempDir('staging-');
        $this->mkdirs($staging . '/rootfs');

        $script = sprintf(
            'require_once %s; require_once %s; rehydrate_staging(%s, %s, true, false);',
            var_export($this->repoPath('tools/lib/packageCommon.php'), true),
            var_export($this->repoPath('tools/lib/rehydration.php'), true),
            var_export('/no/such/engine', true),
            var_export($staging, true)
        );

        $output = System::capture([PHP_BINARY, '-r', $script], $status);
        $this->assertNotSame(0, $status);
    }

    public function testRehydrateArchiveFailsOnCorruptedTar(): void
    {
        $this->requireBinaries(['tar', 'pigz', 'rsync']);
        $badTar = $this->tmpDir . '/bad.tar.gz';
        file_put_contents($badTar, "not a tarball");

        $engineDir = $this->mkTempDir('engine-');
        $this->touch($engineDir . '/installTemplate.php', 'engine');

        $script = sprintf(
            'require_once %s; require_once %s; rehydrate_archive(%s, %s, %s, true, false);',
            var_export($this->repoPath('tools/lib/packageCommon.php'), true),
            var_export($this->repoPath('tools/lib/rehydration.php'), true),
            var_export($badTar, true),
            var_export($engineDir, true),
            var_export('php://stdout', true)
        );

        $output = System::capture([PHP_BINARY, '-r', $script], $status);
        $this->assertNotSame(0, $status);
    }

    public function testEmbedCreatesMissingParentDirectories(): void
    {
        $this->requireBinaries(['rsync']);
        $staging = $this->mkTempDir('staging-');
        // Intentionally skip creating rootfs directory; embed should create it via recursive mkdir.
        $engineDir = $this->mkTempDir('engine-');
        $this->touch($engineDir . '/installTemplate.php', 'engine');

        $script = sprintf(
            'require_once %s; require_once %s; rehydrate_staging(%s, %s, true, false);',
            var_export($this->repoPath('tools/lib/packageCommon.php'), true),
            var_export($this->repoPath('tools/lib/rehydration.php'), true),
            var_export($engineDir, true),
            var_export($staging, true)
        );
        $output = System::capture([PHP_BINARY, '-r', $script], $status);
        $this->assertSame(0, $status, $output);
        $this->assertFileExists($staging . '/rootfs/opt/mcxTemplate/installTemplate.php');
    }

    // Utilities
    private function mkTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = tempnam($base, $prefix);
        if ($path === false) {
            $this->fail('Unable to create temporary directory name.');
        }
        @unlink($path);
        if (!mkdir($path, 0755, true)) {
            $this->fail('Unable to create temporary directory: ' . $path);
        }
        return $path;
    }

    private function mkdirs(string $path): void
    {
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail('Unable to create directory: ' . $path);
        }
    }

    private function touch(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->fail('Unable to create directory for file: ' . $dir);
        }
        if (@file_put_contents($path, $content) === false) {
            $this->fail('Unable to write temporary file: ' . $path);
        }
    }

    private function repoPath(string $relative): string
    {
        $path = realpath(__DIR__ . '/../../../' . $relative);
        if ($path === false) {
            $this->fail('Unable to resolve path: ' . $relative);
        }
        return $path;
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
    }

    private function requireBinaries(array $bins): void
    {
        foreach ($bins as $bin) {
            if (!System::commandExists($bin)) {
                $this->markTestSkipped($bin . ' not available in PATH');
            }
        }
    }
}
