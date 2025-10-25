<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Positive-path smoke tests for templateRehydrate.php using synthetic rootfs and engine data.
final class TemplateRehydrateCliSuccessTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testRehydrateWritesArchiveToFile(): void
    {
        // Rehydrate should embed the engine into rootfs/opt/mcxTemplate and produce a tarball when output is a file.
        $rootfsTar = $this->createRootfsTar(['etc/hostname' => "example-node\n"]);
        $engineDir = $this->createEngineDir();
        $outputPath = $this->registerTempPath(sys_get_temp_dir() . '/rehydrated-' . uniqid() . '.tar.gz');

        $result = $this->runTool(
            'tools/templateRehydrate.php',
            ['--rootfs', $rootfsTar, '--template', $engineDir, '--output', $outputPath]
        );

        $this->assertSame(0, $result['status'], 'Rehydrate CLI should succeed: ' . $result['output']);
        $this->assertStringContainsString($outputPath, $result['stdout']);
        $this->assertFileExists($outputPath);

        $entries = $this->listTarEntries($outputPath);
        $this->assertContains('rootfs/etc/hostname', $entries);
        $this->assertContains('rootfs/opt/mcxTemplate/installTemplate.php', $entries);
    }

    public function testRehydrateStreamsToStdout(): void
    {
        // When output is '-', the rehydrated archive should be streamed to STDOUT successfully.
        $rootfsTar = $this->createRootfsTar(['etc/hosts' => "127.0.0.1 localhost\n"]);
        $engineDir = $this->createEngineDir();

        $result = $this->runTool(
            'tools/templateRehydrate.php',
            ['--rootfs', $rootfsTar, '--template', $engineDir, '--output', '-']
        );

        $this->assertSame(0, $result['status'], 'Streaming mode should succeed: ' . $result['output']);
        $this->assertStringContainsString('Rehydrated archive stream completed', $result['stderr']);
        $this->assertStringContainsString('rootfs/', $result['stdout']);
    }

    /**
     * @param array<string,string> $files
     */
    private function createRootfsTar(array $files): string
    {
        $rootDir = $this->mkTempDir('mcx-rootfs-');
        foreach ($files as $relative => $contents) {
            $path = $rootDir . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->fail('Unable to create fixture directory: ' . $dir);
            }
            file_put_contents($path, $contents);
        }

        $tarPath = $this->registerTempPath(sys_get_temp_dir() . '/rootfs-' . uniqid() . '.tar.gz');
        $cmd = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($rootDir));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Failed to create synthetic rootfs archive.');
        }
        return $tarPath;
    }

    private function createEngineDir(): string
    {
        $engineDir = $this->mkTempDir('mcx-engine-');
        mkdir($engineDir . '/distros', 0755, true);
        file_put_contents($engineDir . '/installTemplate.php', "<?php echo 'engine';\n");
        file_put_contents($engineDir . '/distros/configure.php', "<?php echo 'config';\n");
        return $engineDir;
    }

    /**
     * @return array<int,string>
     */
    private function listTarEntries(string $path): array
    {
        $cmd = sprintf('tar -tzf %s', escapeshellarg($path));
        exec($cmd, $lines, $status);
        if ($status !== 0) {
            $this->fail('Unable to list archive entries for ' . $path);
        }
        return array_map(static fn(string $entry): string => rtrim($entry, '/'), $lines);
    }
}

