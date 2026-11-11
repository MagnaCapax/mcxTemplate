<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\ProcessRunner;
use PHPUnit\Framework\TestCase;

// ProcessRunnerTest runs only the PHP binary itself with harmless one-liners: it proves
// argv is not shell-interpreted, stdin and env pass through, and both streams are read.
final class ProcessRunnerTest extends TestCase
{
    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $evil = 'a; echo INJECTED $(id) `id` | cat';
        $r = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', '--', $evil]);
        $this->assertSame(0, $r['code']);
        $this->assertSame($evil, $r['out']);
    }

    public function testStdinAndEnvironmentReachTheChild(): void
    {
        $r = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo strtoupper(stream_get_contents(STDIN)), getenv("MCX_TEST_VAR");'], 'secret-in', ['MCX_TEST_VAR' => 'envval']);
        $this->assertSame('SECRET-INenvval', $r['out']);
    }

    public function testAGivenEnvironmentReplacesTheInheritedOne(): void
    {
        // A stray MCX_DRY_RUN in the parent must not reach a child that was given its own environment.
        putenv('MCX_DRY_RUN=1');
        try {
            $r = (new ProcessRunner())->run([PHP_BINARY, '-r', 'var_export([getenv("MCX_DRY_RUN"), getenv("LANG")]);'], null, ['LANG' => 'C']);
        } finally {
            putenv('MCX_DRY_RUN');
        }
        $this->assertSame("array (\n  0 => false,\n  1 => 'C',\n)", $r['out']);
    }

    public function testExitCodeAndBothStreamsAreCaptured(): void
    {
        $r = (new ProcessRunner())->run([PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("o", 200000)); fwrite(STDERR, str_repeat("e", 200000)); exit(3);']);
        $this->assertSame(3, $r['code']);
        $this->assertSame(200000, strlen($r['out']));
        $this->assertSame(200000, strlen($r['err']));
    }

    public function testMissingBinaryReportsAFailureCode(): void
    {
        $r = (new ProcessRunner())->run(['/nonexistent/mcx-no-such-binary']);
        $this->assertNotSame(0, $r['code']);
    }

    public function testEmptyCommandAndNulBytesAreRefused(): void
    {
        foreach ([[], [''], [PHP_BINARY, "a\0b"]] as $bad) {
            try {
                (new ProcessRunner())->run($bad);
                $this->fail('accepted a bad command');
            } catch (InstallRefusal $e) {
                $this->assertSame('commandInvalid', $e->refusalCode());
            }
        }
    }
}
