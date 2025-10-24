<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Hermetic smoke tests for templateCloneRemote.php using fake ssh wrappers.
final class TemplateCloneRemoteHermeticTest extends TestCase
{
    use ToolTestHelpers;

    private string $fakeBinDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeBinDir = $this->createFakeBinaryDir();
        $this->writeExecutable($this->fakeBinDir, 'ssh', <<<'SH'
mode=${MCX_FAKE_SSH_MODE:-success}
# consume remote script from stdin
cat >/dev/null
if [ "$mode" = "success" ]; then
    if [ -z "$MCX_FAKE_SSH_TAR" ] || [ ! -f "$MCX_FAKE_SSH_TAR" ]; then
        echo "fake ssh: MCX_FAKE_SSH_TAR not set" >&2
        exit 5
    fi
    cat "$MCX_FAKE_SSH_TAR"
    exit 0
fi
echo "fake ssh failure" >&2
exit 3
SH);
    }

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testStreamsArchiveFromFakeSsh(): void
    {
        // Happy path: fake ssh emits a tarball that should be written to disk successfully.
        $tarPath = $this->createCompressedTar(['etc/hosts' => "127.0.0.1 localhost\n"]);
        $outputPath = $this->generateTempFilePath('clone-output');

        $result = $this->runTool(
            'tools/templateCloneRemote.php',
            ['fake@host', $outputPath],
            [
                'PATH' => $this->fakeBinDir . PATH_SEPARATOR . getenv('PATH'),
                'MCX_FAKE_SSH_MODE' => 'success',
                'MCX_FAKE_SSH_TAR' => $tarPath,
            ]
        );

        $this->assertSame(0, $result['status'], 'Expected success when fake ssh returns tar: ' . $result['output']);
        $this->assertStringContainsString('Archive stream completed', $result['output']);
        $this->assertFileExists($outputPath);

        $entries = $this->listTarEntries($outputPath);
        $this->assertContains('etc/hosts', $entries);
    }

    public function testFailsWhenFakeSshReportsError(): void
    {
        // Failure path: fake ssh exits non-zero and the tool should surface the exit code.
        $outputPath = $this->generateTempFilePath('clone-fail');

        $result = $this->runTool(
            'tools/templateCloneRemote.php',
            ['fake@host', $outputPath],
            [
                'PATH' => $this->fakeBinDir . PATH_SEPARATOR . getenv('PATH'),
                'MCX_FAKE_SSH_MODE' => 'fail',
            ]
        );

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('Streaming failed with exit code 3', $result['output']);
        $this->assertFileDoesNotExist($outputPath);
    }

    private function createCompressedTar(array $files): string
    {
        $sourceDir = $this->mkTempDir('fake-rootfs-');
        foreach ($files as $relative => $content) {
            $path = $sourceDir . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->fail('Unable to create directory for fixture: ' . $dir);
            }
            file_put_contents($path, $content);
        }

        $tarPath = $this->generateTempFilePath('fake-remote') . '.tar.gz';
        $cmd = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($sourceDir));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Failed to create synthetic remote archive.');
        }
        return $tarPath;
    }

    private function generateTempFilePath(string $prefix): string
    {
        return $this->registerTempPath(sys_get_temp_dir() . '/' . $prefix . '-' . uniqid() . '.tar.gz');
    }

    private function listTarEntries(string $path): array
    {
        $cmd = sprintf('tar -tzf %s', escapeshellarg($path));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Unable to list contents of ' . $path);
        }
        return array_map(static function (string $entry): string {
            $entry = rtrim($entry, '/');
            $entry = preg_replace('#^\./#', '', $entry) ?: $entry;
            return $entry;
        }, $out);
    }
}
