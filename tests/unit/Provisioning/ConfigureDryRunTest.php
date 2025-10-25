<?php
declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Tools\ToolTestHelpers;

// Dry-run integration smoke test for distros/configure.php without requiring root.
final class ConfigureDryRunTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testConfigureDryRunCompletesSuccessfully(): void
    {
        // Dry-run with disable_functions=posix_geteuid and USER=root should succeed and list tasks.
        $logDir = $this->mkTempDir('mcx-config-logs-');
        $this->registerTempPath($logDir);

        $command = [
            PHP_BINARY,
            '-d', 'disable_functions=posix_geteuid',
            $this->repoPath('distros/configure.php'),
            '--dry-run',
            '--distro=debian',
            '--hostname=test.example',
            '--mount=/,/dev/sda1',
            '--log-dir', $logDir,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, [
            'USER' => 'root',
            'MCX_LOG_DIR' => $logDir,
        ]);

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process, 'Failed to launch configure.php dry-run');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $status = proc_close($process);

        $combined = trim($stdout . $stderr);
        $this->assertSame(0, $status, 'configure.php dry-run failed: ' . $combined);
        $this->assertStringContainsString('Dry-run mode enabled', $combined);
        $this->assertStringContainsString('Configuring distro', $combined);
    }
}

