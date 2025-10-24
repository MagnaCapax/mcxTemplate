<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Hermetic success/failure coverage for templateAssemble.php via fake rsync/ssh.
final class TemplateAssembleHermeticTest extends TestCase
{
    use ToolTestHelpers;

    private string $fakeBinDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeBinDir = $this->createFakeBinaryDir();

        // Fake ssh (rsync -e requires it) just exits successfully.
        $this->writeExecutable($this->fakeBinDir, 'ssh', "exit 0\n");

        // Fake rsync copies from MCX_FAKE_RSYNC_SOURCE into the destination.
        $this->writeExecutable($this->fakeBinDir, 'rsync', <<<'SH'
mode=${MCX_FAKE_RSYNC_MODE:-success}
if [ "$mode" = "fail" ]; then
    echo "fake rsync failure" >&2
    exit 90
fi
src="$MCX_FAKE_RSYNC_SOURCE"
if [ -z "$src" ] || [ ! -d "$src" ]; then
    echo "fake rsync source missing" >&2
    exit 91
fi
# Destination is the last argument (rsync target)
eval "dest=\${$#}"
dest=${dest%/}
mkdir -p "$dest"
cp -a "$src"/. "$dest"/
exit 0
SH);
    }

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testAssembleWithFakeRsyncAndSsh(): void
    {
        // Successful assemble should create an archive embedding both rootfs and mcxTemplate engine.
        $sourceDir = $this->mkTempDir('mcx-assemble-source-');
        file_put_contents($sourceDir . '/etc-hosts', "127.0.0.1 localhost\n");

        $engineDir = $this->mkTempDir('mcx-engine-');
        file_put_contents($engineDir . '/installTemplate.php', "<?php echo 'engine';\n");
        mkdir($engineDir . '/distros', 0755, true);
        file_put_contents($engineDir . '/distros/configure.php', "<?php echo 'config';\n");

        $outputPath = $this->generateTempFilePath('assemble-success');

        $result = $this->runTool(
            'tools/templateAssemble.php',
            ['--source-host', 'fake-host', '--template', $engineDir, '--output', $outputPath],
            [
                'PATH' => $this->fakeBinDir . PATH_SEPARATOR . getenv('PATH'),
                'MCX_FAKE_RSYNC_SOURCE' => $sourceDir,
            ]
        );

        $this->assertSame(0, $result['status'], 'Assemble harness failed: ' . $result['output']);
        $this->assertStringContainsString('Archive created at', $result['output']);
        $this->assertFileExists($outputPath);

        $entries = $this->listTarEntries($outputPath);
        $this->assertContains('rootfs/etc-hosts', $entries);
        $this->assertContains('rootfs/opt/mcxTemplate/installTemplate.php', $entries, 'Engine not embedded into rootfs');
    }

    public function testAssembleFailsWhenFakeRsyncErrors(): void
    {
        // When the synthetic rsync exits non-zero the archive must not be produced.
        $engineDir = $this->mkTempDir('mcx-engine-');
        file_put_contents($engineDir . '/installTemplate.php', 'engine');
        mkdir($engineDir . '/distros', 0755, true);
        file_put_contents($engineDir . '/distros/configure.php', 'config');

        $outputPath = $this->generateTempFilePath('assemble-fail');

        $result = $this->runTool(
            'tools/templateAssemble.php',
            ['--source-host', 'fake-host', '--template', $engineDir, '--output', $outputPath],
            [
                'PATH' => $this->fakeBinDir . PATH_SEPARATOR . getenv('PATH'),
                'MCX_FAKE_RSYNC_MODE' => 'fail',
                'MCX_FAKE_RSYNC_SOURCE' => $this->mkTempDir('unused-source-'),
            ]
        );

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('fake rsync failure', $result['output']);
        $this->assertFileDoesNotExist($outputPath);
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
            $this->fail('Unable to list archive contents for ' . $path);
        }
        return array_map(static function (string $entry): string {
            $entry = rtrim($entry, '/');
            $entry = preg_replace('#^\./#', '', $entry) ?: $entry;
            return $entry;
        }, $out);
    }
}
