<?php
declare(strict_types=1);

namespace Tests\Lib\Common;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// SystemTest validates the minimal command helpers without depending on system-specific state.
final class SystemTest extends TestCase
{
    // commandExists should accept multiple known utilities that are always provisioned.
    /**
     * @dataProvider provideExistingCommands
     */
    public function testCommandExistsDetectsCommands(string $command): void
    {
        $this->assertTrue(System::commandExists($command));
    }

    // provideExistingCommands lists binaries that the suite relies on for other checks.
    public function provideExistingCommands(): array
    {
        return [
            'sh shell' => ['sh'],
            'env utility' => ['env'],
            'php interpreter' => ['php'],
        ];
    }

    // commandExists should reject blank and malicious command candidates.
    /**
     * @dataProvider provideMissingCommands
     */
    public function testCommandExistsRejectsInvalidCommandNames(string $command): void
    {
        $this->assertFalse(System::commandExists($command));
    }

    // provideMissingCommands enumerates the surprising inputs we guard against.
    public function provideMissingCommands(): array
    {
        return [
            'obvious gibberish' => ['definitely-not-real'],
            'empty string' => [''],
            'spaces only' => ['   '],
            'missing absolute path' => ['/bin/definitely-not-real'],
            'injection looking' => ['$(totally-not-happening)'],
        ];
    }

    // run should report success for standard command vectors that exit zero.
    /**
     * @dataProvider provideSuccessfulRunCommands
     */
    public function testRunHandlesSuccessfulExitCodes(array $command): void
    {
        $status = System::run($command);
        $this->assertSame(0, $status);
    }

    // provideSuccessfulRunCommands keeps the exit-zero cases varied for coverage.
    public function provideSuccessfulRunCommands(): array
    {
        return [
            'true binary' => [['true']],
            'shell exit 0' => [['sh', '-c', 'exit 0']],
            'php inline exit' => [[PHP_BINARY, '-r', 'exit(0);']],
        ];
    }

    // run should return the non-zero status for commands that fail in different ways.
    /**
     * @dataProvider provideFailingRunCommands
     */
    public function testRunSurfacesNonZeroExitCodes(array $command, int $expectedStatus): void
    {
        $status = System::run($command);
        $this->assertSame($expectedStatus, $status);
    }

    // provideFailingRunCommands illustrates common failure patterns to protect against.
    public function provideFailingRunCommands(): array
    {
        return [
            'false binary' => [['false'], 1],
            'shell custom exit' => [['sh', '-c', 'exit 23'], 23],
            'missing executable' => [['definitely-not-real-command'], 127],
        ];
    }

    // buildCommand must reject empty command vectors so callers supply at least one token.
    public function testRunWithEmptyCommandThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        System::run([]);
    }

    // run should also accept disallowing failure while still returning zero on success.
    public function testRunHonoursAllowFailureFlagOnSuccess(): void
    {
        $status = System::run(['sh', '-c', 'exit 0'], false);
        $this->assertSame(0, $status);
    }

    // run should terminate the process when allowFailure is false and the command fails.
    public function testRunStopsExecutionWhenFailureDisallowed(): void
    {
        $command = ['sh', '-c', 'exit 5'];
        [$output, $status] = $this->invokeSystemInIsolatedProcess(
            sprintf('\\Lib\\Common\\System::run(%s, false);', var_export($command, true))
        );

        $this->assertSame(5, $status);
        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString('Command failed', $output);
    }

    // capture should trim stdout and keep the zero exit code visible to the caller.
    /**
     * @dataProvider provideCaptureSuccessCases
     */
    public function testCaptureReturnsOutputAndStatus(array $command, string $expectedOutput): void
    {
        $output = System::capture($command, $status);
        $this->assertSame($expectedOutput, $output);
        $this->assertSame(0, $status);
    }

    // provideCaptureSuccessCases includes tricky whitespace and newline handling scenarios.
    public function provideCaptureSuccessCases(): array
    {
        return [
            'php echo' => [[PHP_BINARY, '-r', 'echo "ping";'], 'ping'],
            'shell multiline' => [['sh', '-c', 'printf "first\\nsecond"'], "first\nsecond"],
            'shell trimmed spaces' => [['sh', '-c', 'printf "  spaced  "'], 'spaced'],
        ];
    }

    // capture must preserve stdout content while surfacing non-zero exit statuses for callers.
    /**
     * @dataProvider provideCaptureFailureCases
     */
    public function testCaptureReportsOutputForFailingCommands(array $command, string $expectedOutput, int $expectedStatus): void
    {
        $output = System::capture($command, $status);
        $this->assertSame($expectedOutput, $output);
        $this->assertSame($expectedStatus, $status);
    }

    // provideCaptureFailureCases lists how stdout behaves when commands fail in messy ways.
    public function provideCaptureFailureCases(): array
    {
        return [
            'stdout before failure' => [['sh', '-c', 'echo fail; exit 3'], 'fail', 3],
            'stderr only output' => [['sh', '-c', '>&2 echo error; exit 9'], '', 9],
            'missing binary' => [['definitely-not-real-command'], '', 127],
        ];
    }

    // capture should collapse whitespace-only output to an empty string so callers see no noise.
    public function testCaptureReturnsEmptyStringWhenCommandOutputsWhitespaceOnly(): void
    {
        $output = System::capture(['sh', '-c', "printf '   \n   '"], $status);
        $this->assertSame('', $output);
        $this->assertSame(0, $status);
    }

    // runIfCommandExists should return true when the executable exists and exits cleanly.
    public function testRunIfCommandExistsRunsCommandSuccessfully(): void
    {
        $result = System::runIfCommandExists('sh', ['-c', 'exit 0']);
        $this->assertTrue($result);
    }

    // runIfCommandExists should return false when the executable is missing or fails.
    /**
     * @dataProvider provideRunIfCommandExistsFailures
     */
    public function testRunIfCommandExistsHandlesFailures(string $command, array $args): void
    {
        $result = System::runIfCommandExists($command, $args);
        $this->assertFalse($result);
    }

    // provideRunIfCommandExistsFailures combines missing binaries and non-zero exits.
    public function provideRunIfCommandExistsFailures(): array
    {
        return [
            'missing command' => ['definitely-not-real', []],
            'failing command' => ['sh', ['-c', 'exit 7']],
        ];
    }

    // runWithEnv uses passthru, so we assert on the exit status to remain insensitive to stdout.
    public function testRunWithEnvInjectsEnvironmentVariables(): void
    {
        $status = System::runWithEnv('sh', ['PING' => 'PONG', 'ALPHA' => 'BETA'], ['-c', 'test "$PING" = "PONG" -a "$ALPHA" = "BETA"']);
        $this->assertSame(0, $status);
    }

    // runWithEnv should handle callers that do not need any extra environment overrides.
    public function testRunWithEnvHandlesEmptyEnvironment(): void
    {
        $status = System::runWithEnv('sh', [], ['-c', 'exit 0']);
        $this->assertSame(0, $status);
    }

    // runWithEnv should allow callers to observe non-zero exits when requested.
    public function testRunWithEnvReturnsStatusWhenFailureAllowed(): void
    {
        $status = System::runWithEnv('sh', ['PING' => 'PONG'], ['-c', 'exit 9'], true);
        $this->assertSame(9, $status);
    }

    // runWithEnv should mirror run() by exiting when allowFailure is false and the command fails.
    public function testRunWithEnvStopsOnFailureWhenDisallowed(): void
    {
        $environment = ['PING' => 'PONG'];
        $args = ['-c', 'exit 4'];
        [$output, $status] = $this->invokeSystemInIsolatedProcess(
            sprintf('\\Lib\\Common\\System::runWithEnv(%s, %s, %s);', var_export('sh', true), var_export($environment, true), var_export($args, true))
        );

        $this->assertSame(4, $status);
        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString('Command failed', $output);
    }

    // isBlockDevice should reject files, directories, and missing paths as non-block nodes.
    public function testIsBlockDeviceIdentifiesRegularFilesAsFalse(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mcx');
        $this->assertIsString($tempFile);

        $this->assertFalse(System::isBlockDevice($tempFile));
        $this->assertFalse(System::isBlockDevice(__FILE__));
        $this->assertFalse(System::isBlockDevice(dirname(__FILE__)));
        $this->assertFalse(System::isBlockDevice('/dev/null'));
        $this->assertFalse(System::isBlockDevice('/definitely/not/here'));

        if (is_string($tempFile)) {
            unlink($tempFile);
        }
    }

    // invokeSystemInIsolatedProcess executes System helpers in a fresh PHP process for exit testing.
    private function invokeSystemInIsolatedProcess(string $invocation): array
    {
        $script = sprintf(
            'require_once %s; %s',
            var_export($this->getBootstrapPath(), true),
            $invocation
        );

        $output = System::capture([PHP_BINARY, '-d', 'display_errors=0', '-r', $script], $status);
        return [$output, $status];
    }

    // getBootstrapPath resolves the autoloader so isolated processes find System and Logging classes.
    private function getBootstrapPath(): string
    {
        $bootstrapPath = realpath(__DIR__ . '/../../bootstrap.php');
        if ($bootstrapPath === false) {
            $this->fail('Unable to resolve bootstrap path for isolated System calls.');
        }

        return $bootstrapPath;
    }
}
