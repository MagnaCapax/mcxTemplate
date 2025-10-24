<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

final class TemplateRehydrateCliAdversarialTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = $this->mkTempDir('mcx-cli-adv-');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function testUnknownArgumentCausesFailure(): void
    {
        $script = $this->repoPath('tools/templateRehydrate.php');
        $cmd = [PHP_BINARY, $script, '--rootfs', '/tmp/nowhere.tar.gz', '--unknown-flag'];
        $output = System::capture($cmd, $status);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('Unknown argument', $output);
    }

    public function testMissingTemplatePathFails(): void
    {
        $this->requireBinaries(['tar', 'pigz']);

        // Create a minimal rootfs tarball so we fail specifically on template path validation
        $rootfsDir = $this->mkTempDir('rootfs-');
        $this->mkdirs($rootfsDir . '/etc');
        $this->touch($rootfsDir . '/etc/hostname', 'ex');
        $rootfsTar = $this->tmpDir . '/rootfs.tar.gz';
        $this->createTarGz($rootfsDir, $rootfsTar);

        $script = $this->repoPath('tools/templateRehydrate.php');
        $cmd = [PHP_BINARY, $script, '--rootfs', $rootfsTar, '--output', $this->tmpDir . '/out.tar.gz', '--template', '/path/does/not/exist'];
        $output = System::capture($cmd, $status);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('Template path not found', $output);
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

    private function createTarGz(string $sourceDir, string $output): void
    {
        $cmd = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .', escapeshellarg($output), escapeshellarg($sourceDir));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Failed to create tar.gz for test');
        }
    }
}

