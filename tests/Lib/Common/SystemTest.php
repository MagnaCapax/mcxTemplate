<?php
declare(strict_types=1);

namespace Tests\Lib\Common;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// SystemTest validates the minimal command helpers without depending on system-specific state.
final class SystemTest extends TestCase
{
    // commandExists should confirm standard shells exist and bogus commands do not.
    public function testCommandExistsDetectsCommands(): void
    {
        $this->assertTrue(System::commandExists('sh'));
        $this->assertFalse(System::commandExists('definitely-not-real'));
    }

    // run streams passthru output, so we focus on exit codes to stay environment-agnostic.
    public function testRunHandlesExitCodesWithoutTerminating(): void
    {
        $successStatus = System::run(['true'], false);
        $this->assertSame(0, $successStatus);

        $failureStatus = System::run(['false'], true);
        $this->assertNotSame(0, $failureStatus);
    }

    // capture should return stdout and report the exit code via the reference parameter.
    public function testCaptureReturnsOutputAndStatus(): void
    {
        $output = System::capture([PHP_BINARY, '-r', 'echo "ping";'], $status);
        $this->assertSame('ping', $output);
        $this->assertSame(0, $status);
    }

    // buildCommand must reject empty command vectors so callers supply at least one token.
    public function testRunWithEmptyCommandThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        System::run([]);
    }

    // runIfCommandExists should silently skip commands that are not present while logging the warning.
    public function testRunIfCommandExistsSkipsUnknownCommand(): void
    {
        $result = System::runIfCommandExists('definitely-not-real');
        $this->assertFalse($result);
    }

    // runIfCommandExists should return false when the wrapped command exits with a non-zero status.
    public function testRunIfCommandExistsPropagatesExitCodes(): void
    {
        $result = System::runIfCommandExists('sh', ['-c', 'exit 7']);
        $this->assertFalse($result);
    }

    // runIfCommandExists should return true when the executable exists and exits cleanly.
    public function testRunIfCommandExistsRunsCommandSuccessfully(): void
    {
        $result = System::runIfCommandExists('sh', ['-c', 'exit 0']);
        $this->assertTrue($result);
    }

    // runWithEnv uses passthru, so we assert on the exit status to remain insensitive to stdout.
    public function testRunWithEnvInjectsEnvironmentVariables(): void
    {
        $status = System::runWithEnv('sh', ['PING' => 'PONG'], ['-c', 'test "$PING" = "PONG"']);
        $this->assertSame(0, $status);
    }

    // isBlockDevice should reject files and paths that do not point to block device nodes.
    public function testIsBlockDeviceIdentifiesRegularFilesAsFalse(): void
    {
        $this->assertFalse(System::isBlockDevice(__FILE__));
        $this->assertFalse(System::isBlockDevice('/definitely/not/here'));
    }
}
