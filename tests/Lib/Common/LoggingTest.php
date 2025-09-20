<?php
declare(strict_types=1);

namespace Tests\Lib\Common;

use Lib\Common\System;
use PHPUnit\Framework\TestCase;

// LoggingTest verifies the timestamped logging helpers remain stable even with unusual messages.
final class LoggingTest extends TestCase
{
    // Each logger should emit a UTC timestamp, the level tag, and the original message payload.
    /**
     * @dataProvider provideLevelsAndMessages
     */
    public function testLoggersEmitTimestampedLines(string $method, string $level, string $message): void
    {
        $output = $this->executeLoggingCall($method, $message);
        $this->assertLogLineMatches($output, $level, $message);
    }

    // provideLevelsAndMessages covers typical phrases plus tricky payloads like multiline strings.
    public function provideLevelsAndMessages(): array
    {
        return [
            'info basic' => ['info', 'INFO', 'booting sequence'],
            'warn spaced' => ['warn', 'WARN', 'late stage retry window'],
            'error special chars' => ['error', 'ERROR', 'path [/tmp/volume] reported "busy"'],
            'info multiline' => ['info', 'INFO', "line-one\nline-two"],
        ];
    }

    // Calling an undefined logger should bubble the PHP fatal error back to the caller.
    public function testUnknownLoggingMethodSurfacesPhpError(): void
    {
        $script = sprintf(
            'require_once %s; \\Lib\\Common\\Logging::%s(%s);',
            var_export($this->getLoggingPath(), true),
            'missing',
            var_export('boom', true)
        );

        $output = System::capture([PHP_BINARY, '-d', 'display_errors=1', '-r', $script], $status);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('Call to undefined method', $output);
    }

    // executeLoggingCall runs the requested logger in a fresh CLI PHP to capture stdout reliably.
    private function executeLoggingCall(string $method, string $message): string
    {
        $script = sprintf(
            'require_once %s; \\Lib\\Common\\Logging::%s(%s);',
            var_export($this->getLoggingPath(), true),
            $method,
            var_export($message, true)
        );

        $output = System::capture([PHP_BINARY, '-r', $script], $status);
        $this->assertSame(0, $status);

        return $output;
    }

    // getLoggingPath centralises the realpath lookup so failure messaging stays consistent.
    private function getLoggingPath(): string
    {
        $loggingPath = realpath(__DIR__ . '/../../../src/Lib/Common/Logging.php');
        if ($loggingPath === false) {
            $this->fail('Unable to resolve Logging helper path.');
        }

        return $loggingPath;
    }

    // assertLogLineMatches standardises the timestamp + level + message verification across cases.
    private function assertLogLineMatches(string $output, string $level, string $message): void
    {
        $pattern = sprintf(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z \\[%s\\] %s$/',
            preg_quote($level, '/'),
            preg_quote($message, '/')
        );
        $this->assertMatchesRegularExpression($pattern, $output);
    }
}
