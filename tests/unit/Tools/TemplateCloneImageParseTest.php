<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Basic parseArguments coverage for templateCloneImage.php without requiring root.
final class TemplateCloneImageParseTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testParseArgumentsWithExplicitOutput(): void
    {
        // When args specify image/output/partition, parseArguments should return the exact values.
        $image = $this->createTempImage();
        $output = $this->registerTempPath(sys_get_temp_dir() . '/image-out-' . uniqid() . '.tar.gz');

        $script = $this->repoPath('tools/templateCloneImage.php');
        $code = sprintf(
            'require %s; $config = parseArguments([0, "--image", %s, "--output", %s, "--partition", "1"]); echo json_encode($config);',
            var_export($script, true),
            var_export($image, true),
            var_export($output, true)
        );

        $result = $this->runPhpCode($code);
        $this->assertSame(0, $result['status'], 'parseArguments should succeed: ' . $result['output']);
        $decoded = json_decode($result['stdout'], true, 10, JSON_THROW_ON_ERROR);
        $this->assertSame($image, $decoded['image']);
        $this->assertSame($output, $decoded['output']);
        $this->assertSame('1', $decoded['partition']);
    }

    public function testParseArgumentsAppliesDefaultOutput(): void
    {
        // Without --output, parseArguments should synthesise a default path based on the image name.
        $image = $this->createTempImage();

        $script = $this->repoPath('tools/templateCloneImage.php');
        $code = sprintf(
            'require %s; $config = parseArguments([0, "--image", %s]); echo $config["image"] . "\n" . $config["output"];',
            var_export($script, true),
            var_export($image, true)
        );

        $result = $this->runPhpCode($code);
        $this->assertSame(0, $result['status'], 'parseArguments default output should succeed: ' . $result['output']);
        $lines = explode("\n", trim($result['stdout']));
        $this->assertCount(2, $lines);
        $this->assertSame($image, $lines[0]);
        $this->assertStringContainsString('image-' . basename($image), $lines[1]);
    }

    private function createTempImage(): string
    {
        $path = $this->registerTempPath(sys_get_temp_dir() . '/clone-image-' . uniqid() . '.img');
        file_put_contents($path, str_repeat("0", 1024));
        return $path;
    }
}
