<?php
declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lib\Provisioning\Configurator;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Tools\ToolTestHelpers;

// configure.php runs each task as its own process, so a task sees only what configure.php exported. Helpers find
// their templates through MCX_DISTRO_ID; when the distro came from /etc/os-release instead of --distro, nothing
// exported it and tasks 21 and 22 found no hostname or network template.
final class ConfigureDistroExportTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    public function testTasksSeeTheDistroDetectedFromOsRelease(): void
    {
        [$distroId, $distroVersion] = Configurator::detectDistro('', '');
        $distroId = strtolower($distroId);
        if ($distroId === '') {
            $this->markTestSkipped('this machine has no /etc/os-release ID to detect');
        }
        $major = strtolower(trim(explode('.', $distroVersion)[0]));

        // A copy of the orchestrator and its libraries with one recording task and none of the real ones,
        // so running it as "root" cannot change the machine the tests run on.
        $tree = $this->mkTempDir('mcx-configure-export-');
        $this->copyTree($this->repoPath('distros/common'), $tree . '/distros/common');
        $this->copyTree($this->repoPath('src'), $tree . '/src');
        copy($this->repoPath('distros/configure.php'), $tree . '/distros/configure.php');
        copy($this->repoPath('distros/task_preconditions.php'), $tree . '/distros/task_preconditions.php');
        $record = $tree . '/record.txt';
        $taskDir = $tree . '/distros/' . $distroId . '/common/tasks';
        mkdir($taskDir, 0755, true);
        file_put_contents(
            $taskDir . '/50-record.php',
            "<?php\nfile_put_contents(" . var_export($record, true)
            . ", (string) getenv('MCX_DISTRO_ID') . '|' . (string) getenv('MCX_DISTRO_VERSION'));\n"
        );

        $env = getenv();
        unset($env['MCX_DISTRO_ID'], $env['MCX_DISTRO_VERSION'], $env['MCX_DRY_RUN'], $env['MCX_SKIP_TASKS']);
        $env['USER'] = 'root';
        $env['MCX_LOG_DIR'] = $tree . '/logs';
        $env['MCX_STRUCTURED_LOG'] = $tree . '/logs/structured.log';

        $command = [PHP_BINARY, '-d', 'disable_functions=posix_geteuid', $tree . '/distros/configure.php', '--strict'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tree, $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (stream_get_contents($pipes[1]) ?: '') . (stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, 'configure.php failed: ' . $output);
        $this->assertFileExists($record, 'the recording task did not run: ' . $output);
        $this->assertSame($distroId . '|' . $major, file_get_contents($record));
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $source = $from . '/' . $name;
            if (is_dir($source) && !is_link($source)) {
                $this->copyTree($source, $to . '/' . $name);
            } else {
                copy($source, $to . '/' . $name);
            }
        }
    }
}
