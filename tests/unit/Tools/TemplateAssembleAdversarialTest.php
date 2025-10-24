<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Covers failure scenarios for templateAssemble.php by injecting malformed CLI
// arguments and synthetic environments (missing binaries, unreadable paths).
final class TemplateAssembleAdversarialTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    /**
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     * @param array<string,string> $env
     */
    public function testAdversarialScenarios(string $description, array $args, string $expected, array $env = [], ?callable $setup = null): void
    {
        // Each scenario should trigger a deterministic failure with an explanatory message.
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

        $result = $this->runTool('tools/templateAssemble.php', $args, $env);
        $combined = trim($result['output']);

        $this->assertNotSame(0, $result['status'], $description . " expected non-zero exit. Output:\n" . $combined);
        $this->assertNotEmpty($combined, $description . ' produced no diagnostic output');
        $this->assertStringContainsString($expected, $combined, $description . ' returned unexpected diagnostic output: ' . $combined);
    }

    /**
     * @return array<string,array{string,array<int,string>,string,array<string,string>,callable|null}>
     */
    public function provideAdversarialCases(): array
    {
        return [
            'no arguments' => ['no arguments', [], 'Missing required --source-host value.', [], null],
            'missing source-host value' => ['missing source-host value', ['--source-host'], 'Missing required --source-host value.', [], null],
            'empty source host string' => ['empty source host string', ['--source-host', ''], 'Missing required --source-host value.', [], null],
            'missing template value' => ['missing template value', ['--template'], 'Template path not found.', [], null],
            'template path not found' => ['template path not found', ['--template', '/definitely/not/here'], 'Template path not found.', [], null],
            'ssh option missing value' => ['ssh option missing value', ['--ssh-option'], 'Missing value for --ssh-option.', [], null],
            'ssh option empty string' => ['ssh option empty string', ['--ssh-option', ''], 'Missing value for --ssh-option.', [], null],
            'ssh option whitespace string' => ['ssh option whitespace string', ['--ssh-option', '   '], 'Empty --ssh-option value provided.', [], null],
            'unknown flag' => ['unknown flag', ['--unknown'], "Unknown argument '--unknown'.", [], null],
            'missing template while host provided' => ['missing template while host provided', ['--source-host', 'bad-node', '--template'], 'Template path not found.', [], null],
            'template empty string with host' => ['template empty string with host', ['--source-host', 'bad-node', '--template', ''], 'Template path not found.', [], null],
            'output directory not creatable root' => ['output directory not creatable root', ['--source-host', 'bad-node', '--output', '/root/out.tar.gz'], 'Unable to create output directory.', [], null],
            'output php stream unsupported' => ['output php stream unsupported', ['--source-host', 'bad-node', '--output', 'php://output'], 'Unable to create output directory.', [], null],
            'rsync missing due to empty PATH' => ['rsync missing due to empty PATH', ['--source-host', 'bad-node'], "Required command 'rsync' not found in PATH.", ['PATH' => '/nonexistent'], null],
            'rsync missing with PATH /bin' => ['rsync missing with PATH /bin', ['--source-host', 'bad-node'], "Required command 'rsync' not found in PATH.", ['PATH' => '/bin'], null],
            'tmpdir unwritable' => ['tmpdir unwritable', ['--source-host', 'bad-node'], 'Unable to create staging directory.', ['TMPDIR' => '/root'], null],
            'missing ssh option after valid one' => ['missing ssh option after valid one', ['--source-host', 'bad-node', '--ssh-option', '-o StrictHostKeyChecking=yes', '--ssh-option'], 'Missing value for --ssh-option.', [], null],
            'template missing with embed disable' => ['template missing with embed disable', ['--source-host', 'bad-node', '--no-embed-into-rootfs', '--template'], 'Template path not found.', [], null],
            'existing output file without force' => ['existing output file without force', ['--source-host', 'bad-node', '--output', '__OUTPUT__'], 'Output file already exists:', [], function (array $args, array $env = []): array {
                $tmp = tempnam(sys_get_temp_dir(), 'mcx-out-');
                if ($tmp === false) {
                    throw new \RuntimeException('Unable to create temporary file.');
                }
                $this->registerTempPath($tmp);
                $args[3] = $tmp;
                return ['args' => $args];
            }],
            'unknown flag with host present' => ['unknown flag with host present', ['--source-host', 'bad-node', '--mystery'], "Unknown argument '--mystery'.", [], null],
            'template missing with embed force' => ['template missing with embed force', ['--source-host', 'bad-node', '--embed-force', '--template', '/not/exist'], 'Template path not found.', [], null],
            'fake PATH missing tar' => ['fake PATH missing tar', ['--source-host', 'bad-node'], "Required command 'tar' not found in PATH.", [], function (array $args, array $env = []): array {
                $dir = $this->createFakeBinaryDir();
                $this->registerTempPath($dir);
                $this->writeExecutable($dir, 'rsync', 'exit 0');
                $this->writeExecutable($dir, 'ssh', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'fake PATH missing pigz and gzip' => ['fake PATH missing pigz and gzip', ['--source-host', 'bad-node'], "Required command 'gzip' not found in PATH.", [], function (array $args, array $env = []): array {
                $dir = $this->createFakeBinaryDir();
                $this->registerTempPath($dir);
                $this->writeExecutable($dir, 'rsync', 'exit 0');
                $this->writeExecutable($dir, 'ssh', 'exit 0');
                $this->writeExecutable($dir, 'tar', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'template path with null byte' => ['template path with null byte', ['--source-host', 'bad-node', '--template', "invalid\0path"], 'Template path not found.', [], null],
            'template path points to file' => ['template path points to file', ['--source-host', 'bad-node', '--template', '__FILE__'], 'Rehydration copy failed', [], function (array $args, array $env = []): array {
                $file = tempnam(sys_get_temp_dir(), 'mcx-file-');
                if ($file === false) {
                    throw new \RuntimeException('Unable to create temp file.');
                }
                file_put_contents($file, 'engine');
                $this->registerTempPath($file);
                $args[3] = $file;
                return ['args' => $args];
            }],
            'source host with whitespace positional' => ['source host with whitespace positional', ['--source-host', 'bad host'], "Unknown argument 'host'.", [], null],
            'template path control characters' => ['template path control characters', ['--source-host', 'bad-node', '--template', "line1\nline2"], 'Template path not found.', [], null],
        ];
    }
}
