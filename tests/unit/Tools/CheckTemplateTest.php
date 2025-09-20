<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

final class CheckTemplateTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function testDetectsMissingAssetsInDirectory(): void
    {
        $templateDir = $this->createTempDir();
        $this->ensureDirectory($templateDir . '/distros/common/templates');
        file_put_contents($templateDir . '/distros/common/templates/.keep', '');
        $this->ensureDirectory($templateDir . '/distros/testos/common/tasks');
        file_put_contents($templateDir . '/distros/testos/common/tasks/.keep', '');
        // Intentionally omit distros/configure.php and templates to trigger warnings.

        $result = $this->runCheckTemplate($templateDir);
        $this->assertSame(2, $result['exit']);
        $output = $result['output'];
        $clean = $this->stripAnsi($output);
        $this->assertMatchesRegularExpression('/MISSING\s+distros\/configure\.php/', $clean);
        $this->assertMatchesRegularExpression('/MISSING\s+distros\/testos\/templates/', $clean);
    }

    public function testPassesWhenAllAssetsPresent(): void
    {
        $templateDir = $this->createTempDir();
        $this->ensureDirectory($templateDir . '/distros/common/templates');
        file_put_contents($templateDir . '/distros/common/templates/.keep', '');
        $this->ensureDirectory($templateDir . '/distros/testos/common/tasks');
        file_put_contents($templateDir . '/distros/testos/common/tasks/.keep', '');
        $this->ensureDirectory($templateDir . '/distros/testos/templates');
        file_put_contents($templateDir . '/distros/testos/templates/.keep', '');
        file_put_contents($templateDir . '/distros/configure.php', "<?php\n");

        $result = $this->runCheckTemplate($templateDir);
        $this->assertSame(0, $result['exit']);
        $this->assertMatchesRegularExpression('/OK\s+distros\/configure\.php/', $this->stripAnsi($result['output']));
    }

    public function testTarballDetection(): void
    {
        $dir = $this->createTempDir();
        $this->ensureDirectory($dir . '/distros/common/templates');
        file_put_contents($dir . '/distros/common/templates/.keep', '');
        $this->ensureDirectory($dir . '/distros/testos/common/tasks');
        file_put_contents($dir . '/distros/testos/common/tasks/.keep', '');
        $this->ensureDirectory($dir . '/distros/testos/templates');
        file_put_contents($dir . '/distros/testos/templates/.keep', '');
        file_put_contents($dir . '/distros/configure.php', "<?php\n");

        $tar = $this->createTempDir() . '/template.tar.gz';
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($tar), escapeshellarg($dir)), $out, $status);
        $this->assertSame(0, $status, 'Failed to create tarball for testing');

        $result = $this->runCheckTemplate($tar);
        $this->assertSame(0, $result['exit']);
        $this->assertMatchesRegularExpression('/OK\s+distros\/configure\.php/', $this->stripAnsi($result['output']));
    }

    private function runCheckTemplate(string $path): array
    {
        $script = escapeshellarg(__DIR__ . '/../../../tools/check-template.php');
        $cmd = sprintf('%s %s --path=%s 2>&1', escapeshellarg(PHP_BINARY), $script, escapeshellarg($path));
        $outputLines = [];
        exec($cmd, $outputLines, $exitCode);
        return ['output' => implode("\n", $outputLines), 'exit' => $exitCode];
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mcx-template-check-' . uniqid('', true);
        $this->ensureDirectory($dir);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail('Unable to create directory: ' . $path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private function stripAnsi(string $value): string
    {
        return (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $value);
    }
}
