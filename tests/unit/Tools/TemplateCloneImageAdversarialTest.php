<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Exercises templateCloneImage.php argument validation and dependency checks.
final class TemplateCloneImageAdversarialTest extends TestCase
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
     */
    public function testAdversarialScenarios(string $description, string $mode, array $args, string $expected, ?callable $setup = null): void
    {
        // Each malformed input should cause parseArguments or CLI entrypoint to emit the expected error.
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

        if ($mode === 'cli') {
            $result = $this->runTool('tools/templateCloneImage.php', $args, $env);
            $combined = trim($result['output']);
            $this->assertNotSame(0, $result['status'], $description . " expected non-zero exit. Output:\n" . $combined);
            $this->assertNotEmpty($combined, $description . ' produced no diagnostic output');
            $this->assertStringContainsString($expected, $combined, $description . ' returned unexpected diagnostic output: ' . $combined);
            return;
        }

        $script = $this->repoPath('tools/templateCloneImage.php');
        $code = sprintf('require %s; parseArguments(%s);', var_export($script, true), var_export($args, true));
        $result = $this->runPhpCode($code, $env);
        $combined = trim($result['output']);
        $this->assertNotSame(0, $result['status'], $description . " expected non-zero exit. Output:\n" . $combined);
        $this->assertNotEmpty($combined, $description . ' produced no diagnostic output');
        $this->assertStringContainsString($expected, $combined, $description . ' returned unexpected diagnostic output: ' . $combined);
    }

    /**
     * @return array<string,array{string,string,array<int,string>,string}>
     */
    public function provideAdversarialCases(): array
    {
        $missingImage = 'Missing required --image value.';
        $imageNotFound = 'Image file not found:';
        $unknownArgument = 'Unknown argument';

        return [
            'cli without arguments' => ['cli without arguments', 'cli', [], 'Root privileges are required.'],
            'parse missing image flag' => ['parse missing image flag', 'parse', [], $missingImage],
            'parse image flag without value' => ['parse image flag without value', 'parse', ['--image'], $missingImage],
            'parse empty image string' => ['parse empty image string', 'parse', ['--image', ''], $missingImage],
            'parse whitespace image string' => ['parse whitespace image string', 'parse', ['--image', '   '], $imageNotFound],
            'parse newline image string' => ['parse newline image string', 'parse', ['--image', "line1\n"], $imageNotFound],
            'parse tab image string' => ['parse tab image string', 'parse', ['--image', "bad\tpath"], $imageNotFound],
            'parse nonexistent absolute image' => ['parse nonexistent absolute image', 'parse', ['--image', '/definitely/not/here.img'], $imageNotFound],
            'parse relative nonexistent image' => ['parse relative nonexistent image', 'parse', ['--image', 'nonexistent.img'], $imageNotFound],
            'parse directory as image' => ['parse directory as image', 'parse', ['--image', '/tmp'], $imageNotFound],
            'parse missing image but partition provided' => ['parse missing image but partition provided', 'parse', ['--partition', '1'], $missingImage],
            'parse missing image with output only' => ['parse missing image with output only', 'parse', ['--output', 'snapshot.tar.gz'], $missingImage],
            'parse partition flag missing value' => ['parse partition flag missing value', 'parse', ['--partition'], $missingImage],
            'parse output flag missing value' => ['parse output flag missing value', 'parse', ['--output'], $missingImage],
            'parse unknown flag basic' => ['parse unknown flag basic', 'parse', ['--image', '/tmp/bad.img', '--mystery'], $unknownArgument],
            'parse unknown flag uppercase' => ['parse unknown flag uppercase', 'parse', ['--image', '/tmp/bad.img', '--Mystery'], $unknownArgument],
            'parse unknown flag with value' => ['parse unknown flag with value', 'parse', ['--image', '/tmp/bad.img', '--foo', 'bar'], $unknownArgument],
            'parse uppercase image flag' => ['parse uppercase image flag', 'parse', ['--Image', 'file'], $unknownArgument],
            'parse double dash unknown' => ['parse double dash unknown', 'parse', ['--image', '/tmp/bad.img', '--unknown-flag'], $unknownArgument],
            'parse image path pointing to dev null' => ['parse image path pointing to dev null', 'parse', ['--image', '/dev/null'], $imageNotFound],
            'parse image path with relative traversal' => ['parse image path with relative traversal', 'parse', ['--image', '../not-here.img'], $imageNotFound],
            'cli missing qemu-img' => ['cli missing qemu-img', 'cli', ['--image', '/tmp/missing.img'], "Required command 'qemu-img' not found in PATH.", function (array $args, array $env): array {
                $dir = $this->createFakeBinaryDir();
                $this->registerTempPath($dir);
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'cli missing losetup' => ['cli missing losetup', 'cli', ['--image', '/tmp/missing.img'], "Required command 'losetup' not found in PATH.", function (array $args, array $env): array {
                $dir = $this->createFakeBinaryDir();
                $this->registerTempPath($dir);
                $this->writeExecutable($dir, 'qemu-img', 'exit 0');
                $this->writeExecutable($dir, 'tar', 'exit 0');
                $this->writeExecutable($dir, 'mount', 'exit 0');
                $this->writeExecutable($dir, 'lsblk', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
        ];
    }
}
