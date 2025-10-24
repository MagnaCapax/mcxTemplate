<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

final class CheckTemplateEmbeddedLayoutTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = $this->mkTempDir('mcx-embedded-');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function testAuditorAcceptsEmbeddedEngineLayout(): void
    {
        // Build a minimal embedded layout under rootfs/opt/mcxTemplate
        $base = $this->tmpDir . '/rootfs/opt/mcxTemplate';
        $this->mkdirs($base . '/distros/debian/common/tasks');
        $this->mkdirs($base . '/distros/debian/templates');
        $this->mkdirs($base . '/distros/common/templates');
        $this->touch($base . '/distros/configure.php', "<?php // stub\n");
        $this->touch($base . '/distros/debian/common/tasks/00-stub.php', "<?php // stub\n");
        $this->touch($base . '/distros/debian/templates/hostname.tpl', "{{FQDN}}\n");
        $this->touch($base . '/distros/common/templates/fstab.tpl', "{{FSTAB_ENTRIES}}\n");

        $script = $this->repoPath('tools/check-template.php');
        $output = System::capture([PHP_BINARY, $script, '--path=' . $this->tmpDir, '--dir'], $status);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Template audit results:', $output);
    }

    public function testAuditorFailsWhenCommonTemplatesMissing(): void
    {
        $base = $this->tmpDir . '/rootfs/opt/mcxTemplate';
        $this->mkdirs($base . '/distros/debian/common/tasks');
        $this->mkdirs($base . '/distros/debian/templates');
        // Intentionally omit distros/common/templates to trigger failure
        $this->touch($base . '/distros/configure.php', "<?php // stub\n");
        $this->touch($base . '/distros/debian/common/tasks/00-stub.php', "<?php // stub\n");
        $this->touch($base . '/distros/debian/templates/hostname.tpl', "{{FQDN}}\n");

        $script = $this->repoPath('tools/check-template.php');
        $output = System::capture([PHP_BINARY, $script, '--path=' . $this->tmpDir, '--dir'], $status);
        $this->assertSame(2, $status, $output);
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
}

