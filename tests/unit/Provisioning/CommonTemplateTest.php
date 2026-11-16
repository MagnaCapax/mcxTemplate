<?php
declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lib\Provisioning\Common;
use PHPUnit\Framework\TestCase;

final class CommonTemplateTest extends TestCase
{
    private string $originalTemplateRoot;
    private ?string $originalStructuredLog;
    private string $originalDistroId;
    private string $originalPath;
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTemplateRoot = getenv('MCX_TEMPLATE_ROOT') ?: '';
        $this->originalStructuredLog = getenv('MCX_STRUCTURED_LOG') ?: null;
        $this->originalDistroId = getenv('MCX_DISTRO_ID') ?: '';
        $this->originalPath = getenv('PATH') ?: '';
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('MCX_TEMPLATE_ROOT', $this->originalTemplateRoot);
        if ($this->originalStructuredLog === null) {
            putenv('MCX_STRUCTURED_LOG');
            unset($_ENV['MCX_STRUCTURED_LOG']);
        } else {
            $this->restoreEnv('MCX_STRUCTURED_LOG', $this->originalStructuredLog);
        }
        $this->restoreEnv('MCX_DISTRO_ID', $this->originalDistroId);
        $this->restoreEnv('PATH', $this->originalPath);
        $this->cleanupTempDirs();
        parent::tearDown();
    }

    public function testApplyTemplateToFileWritesContentAndStructuredLog(): void
    {
        $tempDir = $this->createTempDir();
        $template = $tempDir . '/template.tpl';
        file_put_contents($template, 'Hello {{NAME}}');

        $destination = $tempDir . '/output.txt';
        $logPath = $tempDir . '/structured.log';
        Common::applyTemplateToFile($template, $destination, ['{{NAME}}' => 'World']);

        $this->assertSame("Hello World", trim((string) file_get_contents($destination)));

        $this->assertFileDoesNotExist($logPath);

        putenv('MCX_STRUCTURED_LOG=' . $logPath);
        $_ENV['MCX_STRUCTURED_LOG'] = $logPath;

        Common::applyTemplateToFile($template, $destination, ['{{NAME}}' => 'Tester']);

        $this->assertSame("Hello Tester", trim((string) file_get_contents($destination)));
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);
        $last = json_decode((string) end($lines), true);
        $this->assertIsArray($last);
        $this->assertSame('template-apply', $last['context']['event'] ?? null);
        $this->assertSame($template, $last['context']['template'] ?? null);
        $this->assertSame($destination, $last['context']['destination'] ?? null);
        $this->assertArrayHasKey('sha256', $last['context'] ?? []);
    }

    public function testFindTemplatePrefersDistroSpecificPath(): void
    {
        $root = $this->createTempDir();
        $distro = 'testos';
        $commonDir = $root . '/distros/common/templates';
        $distroDir = $root . '/distros/' . $distro . '/templates';
        $this->ensureDirectory($commonDir);
        $this->ensureDirectory($distroDir);

        file_put_contents($commonDir . '/config.tpl', 'common');
        file_put_contents($distroDir . '/config.tpl', 'distro');

        putenv('MCX_TEMPLATE_ROOT=' . $root);
        $_ENV['MCX_TEMPLATE_ROOT'] = $root;
        putenv('MCX_DISTRO_ID=' . $distro);
        $_ENV['MCX_DISTRO_ID'] = $distro;

        $path = Common::findTemplate('config.tpl');
        $this->assertSame(realpath($distroDir . '/config.tpl'), $path);

        unlink($distroDir . '/config.tpl');
        $fallback = Common::findTemplate('config.tpl');
        $this->assertSame(realpath($commonDir . '/config.tpl'), $fallback);
    }

    public function testDetectPrimaryInterfaceRespectsEnvironmentOverride(): void
    {
        putenv('MCX_PRIMARY_INTERFACE=enp0s31f6');
        $_ENV['MCX_PRIMARY_INTERFACE'] = 'enp0s31f6';
        $this->assertSame('enp0s31f6', Common::detectPrimaryInterface('eth9'));
    }

    public function testDetectPrimaryInterfaceFallsBackWhenCommandsUnavailable(): void
    {
        putenv('MCX_PRIMARY_INTERFACE');
        unset($_ENV['MCX_PRIMARY_INTERFACE']);
        // Clear PATH so command -v cannot locate utilities.
        putenv('PATH=');
        $_ENV['PATH'] = '';
        $this->assertSame('eth0', Common::detectPrimaryInterface('eth0'));
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mcxTemplate-test-' . uniqid('', true);
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

    private function restoreEnv(string $name, string $value): void
    {
        if ($value === '') {
            putenv($name);
            unset($_ENV[$name]);
        } else {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    private function cleanupTempDirs(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
