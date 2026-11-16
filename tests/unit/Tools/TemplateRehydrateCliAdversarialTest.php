<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

// Ensures templateRehydrate.php surfaces meaningful errors for invalid inputs.
final class TemplateRehydrateCliAdversarialTest extends TestCase
{
    use ToolTestHelpers;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = $this->mkTempDir('mcx-rehydrate-adv-');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    /**
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     */
    public function testAdversarialScenarios(string $description, array $args, ?callable $setup = null): void
    {
        // Each malformed argument or environment should yield a non-zero status with diagnostics.
        $env = [];
        if ($setup !== null) {
            $adjusted = $setup($args, $env);
            if (isset($adjusted['args'])) {
                $args = $adjusted['args'];
            } elseif (is_array($adjusted)) {
                $args = $adjusted;
            }
            if (isset($adjusted['env'])) {
                $env = array_merge($env, $adjusted['env']);
            }
        }

        $process = $this->runTool('tools/templateRehydrate.php', $args, $env);
        $this->assertNotSame(0, $process['status'], $description . " expected non-zero exit. Output:\n" . $process['output']);
    }

    /**
     * @return array<string,array{string,array<int,string>,callable|null}>
     */
    public function provideAdversarialCases(): array
    {
        return [
            'missing rootfs argument' => ['missing rootfs argument', [], null],
            'unknown argument' => ['unknown argument', ['--rootfs', '/tmp/missing.tar.gz', '--unknown'], null],
            'nonexistent rootfs path' => ['nonexistent rootfs path', ['--rootfs', '/tmp/definitely-not-here.tar.gz'], null],
            'rootfs blank string' => ['rootfs blank string', ['--rootfs', ''], null],
            'rootfs directory path' => ['rootfs directory path', ['--rootfs', '/tmp'], null],
            'rootfs whitespace string' => ['rootfs whitespace string', ['--rootfs', '   '], null],
            'missing template path' => ['missing template path', ['--rootfs', '__ROOTFS__', '--template', '/not/here'], function (array $args, array $env = []): array {
                $root = $this->createMinimalRootfs();
                $args[1] = $root;
                return ['args' => $args];
            }],
            'template empty string' => ['template empty string', ['--rootfs', '__ROOTFS__', '--template', ''], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'output unwritable directory' => ['output unwritable directory', ['--rootfs', '__ROOTFS__', '--output', '/root/out.tar.gz'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'output directory path' => ['output directory path', ['--rootfs', '__ROOTFS__', '--output', $this->tmpDir], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'rootfs unreadable file' => ['rootfs unreadable file', ['--rootfs', '__UNREADABLE__'], function (array $args, array $env = []): array {
                $root = $this->createMinimalRootfs();
                chmod($root, 0000);
                $args[1] = $root;
                return ['args' => $args];
            }],
            'template not directory' => ['template not directory', ['--rootfs', '__ROOTFS__', '--template', '__TEMPLATE_FILE__'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                $file = $this->tmpDir . '/template_file.txt';
                file_put_contents($file, 'engine');
                $args[3] = $file;
                return ['args' => $args];
            }],
            'template directory unreadable' => ['template directory unreadable', ['--rootfs', '__ROOTFS__', '--template', '__TEMPLATE_DIR__'], function (array $args, array $env = []): array {
                $root = $this->createMinimalRootfs();
                $args[1] = $root;
                $dir = $this->mkTempDir('mcx-template-');
                chmod($dir, 0000);
                $args[3] = $dir;
                return ['args' => $args];
            }],
            'output file exists without overwrite' => ['output file exists without overwrite', ['--rootfs', '__ROOTFS__', '--output', '__OUTPUT_FILE__'], function (array $args, array $env = []): array {
                $root = $this->createMinimalRootfs();
                $args[1] = $root;
                $out = tempnam($this->tmpDir, 'out');
                if ($out === false) {
                    throw new \RuntimeException('Unable to allocate temp file');
                }
                $args[3] = $out;
                return ['args' => $args];
            }],
            'unsupported extra argument pair' => ['unsupported extra argument pair', ['--rootfs', '__ROOTFS__', '--format', 'yaml'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'invalid rootfs tarball contents' => ['invalid rootfs tarball contents', ['--rootfs', '__BAD_ROOTFS__'], function (array $args, array $env = []): array {
                $bad = $this->tmpDir . '/bad.tar.gz';
                file_put_contents($bad, 'not a tar');
                $args[1] = $bad;
                return ['args' => $args];
            }],
            'missing pigz dependency scenario' => ['missing pigz dependency scenario', ['--rootfs', '__ROOTFS__'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args, 'env' => ['PATH' => '/nonexistent']];
            }],
            'template copy fails into rootfs' => ['template copy fails into rootfs', ['--rootfs', '__ROOTFS__', '--template', '__TEMPLATE_EMPTY__'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                $dir = $this->mkTempDir('mcx-engine-');
                chmod($dir, 0000);
                $args[3] = $dir;
                return ['args' => $args];
            }],
            'output nested root directory not creatable' => ['output nested root directory not creatable', ['--rootfs', '__ROOTFS__', '--output', '/root/mcx/out.tar.gz'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'template path whitespace with embed force' => ['template path whitespace with embed force', ['--rootfs', '__ROOTFS__', '--template', '   ', '--embed-force'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                return ['args' => $args];
            }],
            'rootfs tarball removed before run' => ['rootfs tarball removed before run', ['--rootfs', '__ROOTFS__'], function (array $args, array $env = []): array {
                $root = $this->createMinimalRootfs();
                unlink($root);
                $args[1] = $root;
                return ['args' => $args];
            }],
            'missing tar binary via PATH override' => ['missing tar binary via PATH override', ['--rootfs', '__ROOTFS__'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                $fake = $this->createFakeBinaryDir();
                $this->registerTempPath($fake);
                $this->writeExecutable($fake, 'pigz', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $fake]];
            }],
            'missing pigz binary via PATH override' => ['missing pigz binary via PATH override', ['--rootfs', '__ROOTFS__'], function (array $args, array $env = []): array {
                $args[1] = $this->createMinimalRootfs();
                $fake = $this->createFakeBinaryDir();
                $this->registerTempPath($fake);
                $this->writeExecutable($fake, 'tar', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $fake]];
            }],
        ];
    }

    private function createMinimalRootfs(): string
    {
        $this->requireBinaries(['tar', 'pigz']);
        $rootDir = $this->mkTempDir('mcx-rootfs-');
        file_put_contents($rootDir . '/etc-hosts', '127.0.0.1 localhost');
        $tar = $this->tmpDir . '/rootfs-' . uniqid() . '.tar.gz';
        $cmd = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .', escapeshellarg($tar), escapeshellarg($rootDir));
        exec($cmd, $out, $status);
        if ($status !== 0) {
            $this->fail('Unable to create rootfs tarball for test.');
        }
        $this->rmTree($rootDir);
        return $tar;
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
