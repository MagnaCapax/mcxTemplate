<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

final class RehydrationLibraryTest extends TestCase
{
    private string $tmpDir;
    private string $engineDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = $this->mkTempDir('mcx-rehydrate-lib-');
        $this->engineDir = $this->mkTempDir('mcx-engine-');

        // Minimal engine contents to verify copies
        $this->touch($this->engineDir . '/installTemplate.php', "<?php echo 'engine';\n");
        $this->mkdirs($this->engineDir . '/distros/common/templates');
        $this->touch($this->engineDir . '/distros/configure.php', "<?php echo 'config';\n");
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        $this->rmTree($this->engineDir);
        parent::tearDown();
    }

    public function testEmbedIntoRootfsCopiesEngine(): void
    {
        $staging = $this->mkTempDir('staging-');
        $this->mkdirs($staging . '/rootfs');

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_staging($this->engineDir, $staging, true, false);

        $this->assertFileExists($staging . '/rootfs/opt/mcxTemplate/installTemplate.php');
        $this->rmTree($staging);
    }

    public function testEmbedSkipsWhenExistingAndNoForce(): void
    {
        $staging = $this->mkTempDir('staging-');
        $dest = $staging . '/rootfs/opt/mcxTemplate';
        $this->mkdirs($dest);
        $this->touch($dest . '/existing.flag', 'keep');

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_staging($this->engineDir, $staging, true, false);

        // Existing file should remain and engine root should not have been copied over it (no installTemplate.php)
        $this->assertFileExists($dest . '/existing.flag');
        $this->assertFileDoesNotExist($dest . '/installTemplate.php');
        $this->rmTree($staging);
    }

    public function testEmbedForceOverwritesExisting(): void
    {
        $staging = $this->mkTempDir('staging-');
        $dest = $staging . '/rootfs/opt/mcxTemplate';
        $this->mkdirs($dest);
        $this->touch($dest . '/existing.flag', 'old');

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_staging($this->engineDir, $staging, true, true);

        // installTemplate.php from engine should exist; old flag should be gone after overwrite
        $this->assertFileExists($dest . '/installTemplate.php');
        $this->assertFileDoesNotExist($dest . '/existing.flag');
        $this->rmTree($staging);
    }

    public function testNoEmbedPlacesEngineUnderTemplateDir(): void
    {
        $staging = $this->mkTempDir('staging-');
        $this->mkdirs($staging . '/rootfs');

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_staging($this->engineDir, $staging, false, false);

        $this->assertFileExists($staging . '/template/installTemplate.php');
        $this->rmTree($staging);
    }

    public function testRehydrateArchiveEmbedsIntoRootfs(): void
    {
        $this->requireBinaries(['tar', 'pigz', 'rsync']);
        [$rootfsTar, $outTar] = [$this->tmpDir . '/rootfs.tar.gz', $this->tmpDir . '/out.tar.gz'];

        $rootfsDir = $this->mkTempDir('rootfs-');
        $this->mkdirs($rootfsDir . '/etc');
        $this->touch($rootfsDir . '/etc/hostname', 'example');
        $this->createTarGz($rootfsDir, $rootfsTar);

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_archive($rootfsTar, $this->engineDir, $outTar, true, false);

        $entries = $this->listTar($outTar);
        $this->assertContains('rootfs/etc/hostname', $entries);
        $this->assertContains('rootfs/opt/mcxTemplate/installTemplate.php', $entries);
    }

    public function testRehydrateArchivePlacesEngineUnderTemplateWhenRequested(): void
    {
        $this->requireBinaries(['tar', 'pigz', 'rsync']);
        [$rootfsTar, $outTar] = [$this->tmpDir . '/rootfs.tar.gz', $this->tmpDir . '/out2.tar.gz'];

        $rootfsDir = $this->mkTempDir('rootfs-');
        $this->mkdirs($rootfsDir . '/etc');
        $this->touch($rootfsDir . '/etc/hosts', '127.0.0.1 localhost');
        $this->createTarGz($rootfsDir, $rootfsTar);

        require_once $this->repoPath('tools/lib/rehydration.php');
        \rehydrate_archive($rootfsTar, $this->engineDir, $outTar, false, false);

        $entries = $this->listTar($outTar);
        $this->assertContains('rootfs/etc/hosts', $entries);
        $this->assertContains('template/installTemplate.php', $entries);
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

    private function listTar(string $path): array
    {
        $cmd = sprintf('tar -tzf %s', escapeshellarg($path));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Failed to list tar contents for test');
        }
        // Normalise by removing trailing slashes for directories
        return array_map(static function (string $e): string {
            $e = rtrim($e, '/');
            $e = preg_replace('#^\./#', '', $e) ?: $e;
            return $e;
        }, $out);
    }
}
