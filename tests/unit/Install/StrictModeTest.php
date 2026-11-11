<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\Installer;
use PHPUnit\Framework\TestCase;

// StrictModeTest runs distros/configure.php and installTemplate.php from a temp tree
// that holds copies of the code under test plus two harmless fake tasks (one exits 1,
// one exits 0). No real distro task runs, and nothing outside the temp dir is touched.
final class StrictModeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $repo = realpath(__DIR__ . '/../../..');
        $this->root = sys_get_temp_dir() . '/mcx-strict-' . bin2hex(random_bytes(4));
        foreach (['distros/common/lib', 'src/Lib/Provisioning', 'distros/fake/common/tasks', 'ini', 'logs'] as $dir) {
            mkdir($this->root . '/' . $dir, 0755, true);
        }
        foreach (['distros/configure.php', 'installTemplate.php', 'distros/common/lib/Common.php',
            'src/Lib/Provisioning/Common.php', 'src/Lib/Provisioning/Configurator.php'] as $file) {
            copy($repo . '/' . $file, $this->root . '/' . $file);
        }
        file_put_contents($this->root . '/distros/fake/common/tasks/10-fail.php', "<?php\nexit(1);\n");
        file_put_contents($this->root . '/distros/fake/common/tasks/20-ok.php', "<?php\nexit(0);\n");
        // Lets a non-root user pass Common::ensureRoot() in this child and in the PHP it spawns.
        file_put_contents($this->root . '/ini/mcx.ini', "disable_functions=posix_geteuid\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @return array{0:int,1:string,2:string} */
    private function runCli(string $script, array $args): array
    {
        $argv = array_merge([PHP_BINARY, $this->root . '/' . $script], $args);
        $env = array_merge(getenv(), ['USER' => 'root', 'MCX_LOG_DIR' => $this->root . '/logs', 'PHP_INI_SCAN_DIR' => ':' . $this->root . '/ini']);
        $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    private function args(array $extra = []): array
    {
        return array_merge(['--distro=fake', '--mount=/,/dev/null', '--log-dir=' . $this->root . '/logs'], $extra);
    }

    // sentinelLines counts the stdout lines that are exactly the strict success line.
    private static function sentinelLines(string $out): int
    {
        return count(array_filter(explode("\n", $out), static fn (string $l): bool => $l === Installer::CONFIGURE_SENTINEL));
    }

    public function testWithoutStrictAFailedTaskIsStillOnlyAWarning(): void
    {
        [$code, $out, $err] = $this->runCli('distros/configure.php', $this->args());
        $this->assertSame(0, $code, $out . $err);
        $this->assertStringContainsString('completed successfully', $out);
        $this->assertStringContainsString('10-fail.php exited with status 1', $err);
        $this->assertSame(0, self::sentinelLines($out), 'without --strict the output is unchanged');
    }

    public function testWithoutStrictAllTasksGoodPrintsNoSuccessLine(): void
    {
        unlink($this->root . '/distros/fake/common/tasks/10-fail.php');
        [$code, $out] = $this->runCli('distros/configure.php', $this->args());
        $this->assertSame(0, $code);
        $this->assertSame(0, self::sentinelLines($out));
    }

    public function testStrictSuccessEndsWithTheSuccessLine(): void
    {
        unlink($this->root . '/distros/fake/common/tasks/10-fail.php');
        [$code, $out, $err] = $this->runCli('distros/configure.php', $this->args(['--strict']));
        $this->assertSame(0, $code, $out . $err);
        $this->assertSame(1, self::sentinelLines($out));
        $this->assertSame(Installer::CONFIGURE_SENTINEL, trim((string) strrchr("\n" . rtrim($out), "\n")), 'it is the final line');
    }

    public function testStrictDryRunOrSkippedTasksPrintNoSuccessLine(): void
    {
        unlink($this->root . '/distros/fake/common/tasks/10-fail.php');
        [, $dry] = $this->runCli('distros/configure.php', $this->args(['--strict', '--dry-run']));
        $this->assertSame(0, self::sentinelLines($dry), 'a dry run proves nothing ran');
        [, $skip] = $this->runCli('distros/configure.php', $this->args(['--strict', '--skip-tasks=20-ok']));
        $this->assertSame(0, self::sentinelLines($skip), 'a skipped task is not "all tasks succeeded"');
    }

    public function testInstallTemplateStrictSuccessPassesTheSuccessLineThrough(): void
    {
        unlink($this->root . '/distros/fake/common/tasks/10-fail.php');
        [$code, $out, $err] = $this->runCli('installTemplate.php', $this->args(['--strict']));
        $this->assertSame(0, $code, $out . $err);
        $this->assertSame(1, self::sentinelLines($out));
    }

    public function testStrictFailedTaskExitsNonZeroWithSummary(): void
    {
        [$code, $out, $err] = $this->runCli('distros/configure.php', $this->args(['--strict']));
        $this->assertSame(1, $code, $out . $err);
        $this->assertStringContainsString('FAILED in strict mode', $err);
        $this->assertStringContainsString('10-fail.php (exit 1)', $err);
        $this->assertStringNotContainsString('completed successfully', $out);
        $this->assertStringNotContainsString('Ignoring unknown option --strict', $err);
        $this->assertSame(0, self::sentinelLines($out), 'a failed run never prints the success line');
    }

    public function testStrictWithAllTasksGoodStillSucceeds(): void
    {
        unlink($this->root . '/distros/fake/common/tasks/10-fail.php');
        [$code, $out, $err] = $this->runCli('distros/configure.php', $this->args(['--strict']));
        $this->assertSame(0, $code, $out . $err);
        $this->assertStringContainsString('completed successfully', $out);
    }

    public function testInstallTemplateForwardsStrictAndExitsNonZero(): void
    {
        [$code, $out, $err] = $this->runCli('installTemplate.php', $this->args(['--strict']));
        $this->assertSame(1, $code, $out . $err);
        $this->assertStringNotContainsString('completed via installTemplate.php', $out);
    }

    public function testInstallTemplateWithoutStrictKeepsExitZero(): void
    {
        [$code, $out, $err] = $this->runCli('installTemplate.php', $this->args());
        $this->assertSame(0, $code, $out . $err);
        $this->assertStringContainsString('completed via installTemplate.php', $out);
    }
}
