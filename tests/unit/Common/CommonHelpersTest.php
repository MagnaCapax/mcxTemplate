<?php
declare(strict_types=1);

namespace Tests\Unit\Common;

use PHPUnit\Framework\TestCase;

// CommonHelpersTest validates the legacy CommonHelpers facade stays predictable for callers.
final class CommonHelpersTest extends TestCase
{
    // Each logging wrapper should forward to Logging and keep the expected format intact.
    /**
     * @dataProvider provideLoggingWrappers
     */
    public function testLoggingHelpersProxyToLogging(string $method, string $level, string $message): void
    {
        // Build the invocation so the isolated interpreter only runs the requested helper.
        $invocation = sprintf(
            '\\Common\\Lib\\CommonHelpers::%s(%s);',
            $method,
            var_export($message, true)
        );

        // Execute the helper via a fresh PHP process to collect stdout without polluting PHPUnit.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Confirm we saw a clean exit and a correctly formatted log line with the original message.
        $this->assertSame(0, $status);
        $this->assertMatchesRegularExpression($this->buildLogPattern($level, $message), $output);
    }

    // provideLoggingWrappers supplies a wide mix of log levels and message shapes to broaden coverage.
    public function provideLoggingWrappers(): array
    {
        return [
            'info simple' => ['logInfo', 'INFO', 'system online'],
            'info punctuation' => ['logInfo', 'INFO', 'phase-1 ready'],
            'info unicode' => ['logInfo', 'INFO', 'café ready'],
            'info numbers' => ['logInfo', 'INFO', 'slot #12 engaged'],
            'warn simple' => ['logWarn', 'WARN', 'retry window open'],
            'warn path' => ['logWarn', 'WARN', 'disk [/dev/sda] near full'],
            'warn uppercase' => ['logWarn', 'WARN', 'TEMPERATURE HIGH'],
            'warn quoted' => ['logWarn', 'WARN', 'user "deploy" delayed'],
            'error simple' => ['logError', 'ERROR', 'fatal mismatch observed'],
            'error path' => ['logError', 'ERROR', 'failed to open /etc/passwd'],
            'error percent' => ['logError', 'ERROR', '99% done but failing'],
            'error colon' => ['logError', 'ERROR', 'service: offline'],
        ];
    }

    // die() should log the message then exit with the requested status code for every scenario.
    /**
     * @dataProvider provideExitCodes
     */
    public function testDieExitsWithProvidedStatus(int $code, string $messageFragment): void
    {
        // Compose the die() call so the test can assert the resulting status and stderr-friendly text.
        $invocation = sprintf(
            '\\Common\\Lib\\CommonHelpers::die(%s, %d);',
            var_export($messageFragment, true),
            $code
        );

        // Launch the helper out-of-process to avoid halting the main PHPUnit execution flow.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Expect the exit code to match while the log output records the provided message fragment.
        $this->assertSame($code, $status);
        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString($messageFragment, $output);
    }

    // provideExitCodes exercises several realistic exit statuses to keep the behaviour predictable.
    public function provideExitCodes(): array
    {
        return [
            'default one' => [1, 'primary failure'],
            'config missing' => [2, 'config missing'],
            'retry exhausted' => [7, 'retry limit exceeded'],
            'unexpected branch' => [42, 'unexpected branch reached'],
            'fatal signal mimic' => [111, 'fatal signal mimic'],
        ];
    }

    // requireCommand should silently continue for every commonly provisioned binary we rely on.
    /**
     * @dataProvider provideAvailableCommands
     */
    public function testRequireCommandSucceedsForAvailableCommands(string $command): void
    {
        // Prepare the invocation so we can observe that execution flows past the helper call.
        $invocation = sprintf(
            '\\Common\\Lib\\CommonHelpers::requireCommand(%s); echo "ok";',
            var_export($command, true)
        );

        // Execute in isolation to mimic real usage while keeping PHPUnit alive during failure modes.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Confirm the exit stayed zero and the sentinel output shows control returned to the caller.
        $this->assertSame(0, $status);
        $this->assertSame('ok', $output);
    }

    // provideAvailableCommands lists binaries bundled in every test container for stable assertions.
    public function provideAvailableCommands(): array
    {
        return [
            'shell' => ['sh'],
            'env' => ['env'],
            'php' => ['php'],
            'ls' => ['ls'],
            'cat' => ['cat'],
        ];
    }

    // requireCommand must stop execution when the target binary cannot be discovered.
    /**
     * @dataProvider provideMissingCommands
     */
    public function testRequireCommandFailsForMissingCommands(string $command): void
    {
        // Invoke the helper with an obviously invalid command to trigger the fail-fast path.
        $invocation = sprintf(
            '\\Common\\Lib\\CommonHelpers::requireCommand(%s);',
            var_export($command, true)
        );

        // Run the helper in an isolated PHP instance to capture the exit code and log output.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Assert we exited with the default fatal code while the error text references the command.
        $this->assertSame(1, $status);
        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString($command, $output);
    }

    // provideMissingCommands covers whitespace, shell tricks, and nonsense binaries to harden validation.
    public function provideMissingCommands(): array
    {
        return [
            'gibberish one' => ['mcx-missing-one'],
            'gibberish two' => ['mcx-missing-two'],
            'gibberish three' => ['mcx-missing-three'],
            'gibberish four' => ['mcx-missing-four'],
            'gibberish five' => ['mcx-missing-five'],
            'empty string' => [''],
            'spaces only' => ['   '],
            'fake absolute' => ['/tmp/mcx/definitely-not-real'],
            'subshell attempt' => ['$(mcx-never-runs)'],
            'wild command' => ['DROP TABLE'],
        ];
    }

    // requireReadableFile should allow execution to continue when the path resolves to a readable file.
    /**
     * @dataProvider provideReadableFilePaths
     */
    public function testRequireReadableFileAcceptsPaths(string $path): void
    {
        // Wrap the helper call so we can detect that control reaches the sentinel echo afterwards.
        $invocation = sprintf(
            '\\Common\\Lib\\CommonHelpers::requireReadableFile(%s); echo "ok";',
            var_export($path, true)
        );

        // Execute the helper in isolation because the failure branch terminates the interpreter.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Ensure the exit remained successful and that the sentinel confirms continued execution.
        $this->assertSame(0, $status);
        $this->assertSame('ok', $output);
    }

    // provideReadableFilePaths references canonical repository files that should remain readable.
    public function provideReadableFilePaths(): array
    {
        return [
            'common helpers' => [$this->resolvePath(__DIR__ . '/../../../src/Common/Lib/Common.php')],
            'logging helper' => [$this->resolvePath(__DIR__ . '/../../../src/Lib/Common/Logging.php')],
            'system helper' => [$this->resolvePath(__DIR__ . '/../../../src/Lib/Common/System.php')],
        ];
    }

    // requireReadableFile must reject unreadable, missing, or malformed paths every time they appear.
    /**
     * @dataProvider provideUnreadableFileScenarios
     */
    public function testRequireReadableFileRejectsInvalidPaths(callable $scenario): void
    {
        // Prepare the scenario, capturing the generated path and optional cleanup hook.
        [$path, $cleanup] = $scenario();

        try {
            // Execute the helper so we can assert it exits and logs the problematic path reference.
            $invocation = sprintf(
                '\\Common\\Lib\\CommonHelpers::requireReadableFile(%s);',
                var_export($path, true)
            );

            [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

            // Validate that we exited with the fatal code and surfaced the expected error messaging.
            $this->assertSame(1, $status);
            $this->assertStringContainsString('[ERROR]', $output);
            $this->assertStringContainsString('Required file', $output);
        } finally {
            // Always run the cleanup to avoid leaking broken symlinks or permission-altered directories.
            if ($cleanup !== null) {
                $cleanup();
            }
        }
    }

    // provideUnreadableFileScenarios creates multiple failure modes so validation stays defensive.
    public function provideUnreadableFileScenarios(): array
    {
        return [
            'missing absolute' => [static fn (): array => ['/definitely/not/here', null]],
            'missing relative' => [static fn (): array => [__DIR__ . '/missing-file.txt', null]],
            'empty string' => [static fn (): array => ['', null]],
            'spaces only' => [static fn (): array => ['   ', null]],
            'broken symlink' => [function (): array {
                $path = sys_get_temp_dir() . '/mcx_broken_symlink';
                @unlink($path);
                symlink('/definitely/not/here', $path);
                $cleanup = static function () use ($path): void {
                    @unlink($path);
                };
                return [$path, $cleanup];
            }],
            'unicode missing path' => [static fn (): array => [sys_get_temp_dir() . '/不存在-file', null]],
        ];
    }

    // requireNonEmpty should allow non-empty strings regardless of whether callers supply a label.
    /**
     * @dataProvider provideNonEmptyValues
     */
    public function testRequireNonEmptyAcceptsValues(string $value, ?string $name, bool $includeName): void
    {
        // Build the helper invocation while optionally including the custom label parameter.
        $call = $includeName
            ? sprintf('\\Common\\Lib\\CommonHelpers::requireNonEmpty(%s, %s);', var_export($value, true), var_export($name, true))
            : sprintf('\\Common\\Lib\\CommonHelpers::requireNonEmpty(%s);', var_export($value, true));

        // Append a sentinel echo so we can confirm execution returns to the caller after validation.
        $invocation = $call . ' echo "ok";';

        // Run the helper via a separate PHP instance so exits do not terminate the PHPUnit harness.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($invocation);

        // Successful paths should keep the exit code at zero and surface the sentinel response.
        $this->assertSame(0, $status);
        $this->assertSame('ok', $output);
    }

    // provideNonEmptyValues spans plain, spaced, and unicode strings with and without explicit labels.
    public function provideNonEmptyValues(): array
    {
        return [
            'simple default label' => ['ready', null, false],
            'spaced default label' => ['  trimmed  ', null, false],
            'unicode default label' => ['naïve', null, false],
            'simple named label' => ['ready', 'hostname', true],
            'unicode named label' => ['пример', 'описание', true],
        ];
    }

    // requireNonEmpty should exit when presented with empty values, using the right label each time.
    /**
     * @dataProvider provideEmptyValues
     */
    public function testRequireNonEmptyFailsForEmptyValues(string $value, ?string $name, bool $includeName, string $expectedLabel): void
    {
        // Build the helper call, omitting the label when the scenario expects the default placeholder.
        $call = $includeName
            ? sprintf('\\Common\\Lib\\CommonHelpers::requireNonEmpty(%s, %s);', var_export($value, true), var_export($name, true))
            : sprintf('\\Common\\Lib\\CommonHelpers::requireNonEmpty(%s);', var_export($value, true));

        // Execute the helper so we can assert on both the exit code and the emitted error messaging.
        [$output, $status] = $this->invokeCommonHelpersInIsolatedProcess($call);

        // Every failure should map to exit code one and reference the label that was evaluated.
        $this->assertSame(1, $status);
        $this->assertStringContainsString('[ERROR]', $output);
        $this->assertStringContainsString(sprintf("Required value '%s' is empty.", $expectedLabel), $output);
    }

    // provideEmptyValues focuses on blank strings, whitespace, and explicit labels to guard regressions.
    public function provideEmptyValues(): array
    {
        return [
            'empty default' => ['', null, false, 'value'],
            'empty named' => ['', 'hostname', true, 'hostname'],
            'empty explicit label' => ['', '', true, ''],
            'empty multi word label' => ['', 'pipeline stage', true, 'pipeline stage'],
        ];
    }

    // buildLogPattern mirrors LoggingTest so log format expectations stay aligned between suites.
    private function buildLogPattern(string $level, string $message): string
    {
        $escapedLevel = preg_quote($level, '/');
        $escapedMessage = preg_quote($message, '/');
        return sprintf('/^\\[%s\\] %s$/', $escapedLevel, $escapedMessage);
    }

    // invokeCommonHelpersInIsolatedProcess centralises the boilerplate for spawning isolated PHP runs.
    private function invokeCommonHelpersInIsolatedProcess(string $invocation): array
    {
        $bootstrap = var_export($this->getBootstrapPath(), true);
        $helpers = var_export($this->getCommonHelpersPath(), true);
        $script = sprintf('require_once %s; require_once %s; %s', $bootstrap, $helpers, $invocation);

        $command = sprintf('%s -d display_errors=0 -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script));
        $outputLines = [];
        exec($command, $outputLines, $status);

        return [trim(implode("\n", $outputLines)), $status];
    }

    // getBootstrapPath resolves the shared bootstrap loader for isolated interpreter execution.
    private function getBootstrapPath(): string
    {
        $path = realpath(__DIR__ . '/../../bootstrap.php');
        if ($path === false) {
            $this->fail('Unable to resolve bootstrap path for CommonHelpers tests.');
        }

        return $path;
    }

    // getCommonHelpersPath finds the legacy CommonHelpers definition for inclusion in isolated runs.
    private function getCommonHelpersPath(): string
    {
        $path = realpath(__DIR__ . '/../../../src/Common/Lib/Common.php');
        if ($path === false) {
            $this->fail('Unable to resolve CommonHelpers path for inclusion.');
        }

        return $path;
    }

    // resolvePath wraps realpath() so data providers can reuse the consistent failure handling logic.
    private function resolvePath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $this->fail(sprintf('Unable to resolve path: %s', $path));
        }

        return $resolved;
    }
}
