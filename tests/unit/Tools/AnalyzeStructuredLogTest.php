<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Smoke tests for analyze-structured-log.php happy-path behaviour.
final class AnalyzeStructuredLogTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testJsonSummaryWithValidLog(): void
    {
        // Valid JSON log should produce a summary with success/failure counts.
        $logPath = $this->createLog([
            ['event' => 'start'],
            ['event' => 'finish', 'context' => ['task' => '10-test.php', 'exit_code' => 0, 'duration_seconds' => 1.5]],
            ['event' => 'finish', 'context' => ['task' => '10-test.php', 'exit_code' => 1, 'duration_seconds' => 2.0]],
        ]);

        $result = $this->runTool('tools/analyze-structured-log.php', ['--input=' . $logPath]);

        $this->assertSame(0, $result['status'], 'Analyzer should succeed: ' . $result['output']);
        $decoded = json_decode($result['stdout'], true, 10, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $decoded['total_events']);
        $this->assertSame(2, $decoded['tasks']['10-test.php']['count']);
        $this->assertSame(1, $decoded['tasks']['10-test.php']['success_count']);
        $this->assertSame(1, $decoded['tasks']['10-test.php']['failure_count']);
    }

    public function testTableSummaryWritesToFile(): void
    {
        // Table format should write human-readable output to the requested file.
        $logPath = $this->createLog([
            ['event' => 'start'],
            ['event' => 'finish', 'context' => ['task' => '20-run.php', 'exit_code' => 0, 'duration_seconds' => 0.75]],
        ]);

        $outputPath = $this->registerTempPath(sys_get_temp_dir() . '/summary-' . uniqid() . '.txt');
        $result = $this->runTool('tools/analyze-structured-log.php', ['--input=' . $logPath, '--format=table', '--output=' . $outputPath]);

        $this->assertSame(0, $result['status'], 'Analyzer table mode should succeed: ' . $result['output']);
        $this->assertFileExists($outputPath);
        $contents = file_get_contents($outputPath);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('20-run.php', $contents);
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function createLog(array $events): string
    {
        $path = $this->registerTempPath(sys_get_temp_dir() . '/structured-' . uniqid() . '.log');
        $lines = array_map(static fn(array $event): string => json_encode($event, JSON_UNESCAPED_SLASHES) ?: '{}', $events);
        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
        return $path;
    }
}
