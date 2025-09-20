<?php
declare(strict_types=1);

namespace Tests\Distros\Common;

use Distros\Common\Common;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Bootstrap already wires the Common helper for the suite, so no extra require.

// Verify the shared Common helpers behave predictably.
final class CommonTest extends TestCase
{
    // Provide the echo command fixture with a helpful label.
    public function testRunCommandReturnsOutput(): void
    {
        $status = null; // Capture the exit code returned by the helper.
        $output = Common::runCommand([PHP_BINARY, '-r', 'echo "probe";'], $status);

        // Ensure the output and exit status mirror the executed command.
        self::assertSame('probe', $output);
        self::assertSame(0, $status);
    }

    // Confirm failing commands bubble their exit statuses back to callers.
    public function testRunCommandPropagatesExitStatus(): void
    {
        $status = null; // Track the exit status for the failing probe.
        $output = Common::runCommand([PHP_BINARY, '-r', 'fwrite(STDERR, "nope"); exit(7);'], $status);

        // The helper trims stdout and leaves stderr untouched for failures.
        self::assertSame('', $output);
        self::assertSame(7, $status);
    }

    // Supply passing hostname candidates for the validation helper.
    #[DataProvider('validHostnameProvider')]
    public function testIsValidHostnameAcceptsExpectedValues(string $candidate): void
    {
        // Acceptable hostnames should evaluate to true.
        self::assertTrue(Common::isValidHostname($candidate));
    }

    // Supply failing hostname candidates to guarantee rejections.
    #[DataProvider('invalidHostnameProvider')]
    public function testIsValidHostnameRejectsUnexpectedValues(string $candidate): void
    {
        // Invalid hostnames should evaluate to false.
        self::assertFalse(Common::isValidHostname($candidate));
    }

    // Provide representative hostnames that align with RFC 1123 expectations.
    public static function validHostnameProvider(): array
    {
        return [
            'simple host' => ['host'],
            'numeric host' => ['n1'],
            'multi label' => ['alpha.beta.gamma'],
            'with hyphen' => ['rack-22'],
            'trailing label max length' => ['a' . str_repeat('b', 61) . 'c'],
        ];
    }

    // Provide hostnames that violate the conservative validation pattern.
    public static function invalidHostnameProvider(): array
    {
        return [
            'empty string' => [''],
            'leading hyphen' => ['-rack'],
            'trailing hyphen' => ['rack-'],
            'exceeds label length' => [str_repeat('a', 64)],
            'invalid character' => ['rack_01'],
        ];
    }
}
