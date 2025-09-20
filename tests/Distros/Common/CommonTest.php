<?php
declare(strict_types=1);

namespace Tests\Distros\Common;

use Distros\Common\Common;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Ensure the shared helper is loaded even when Composer autoloading is absent.
require_once dirname(__DIR__, 3) . '/distros/common/lib/Common.php';

// CommonTest exercises the distro helper API so provisioning scripts stay stable.
final class CommonTest extends TestCase
{
    // Confirm runCommand returns stdout output without trailing whitespace.
    public function testRunCommandReturnsOutput(): void
    {
        $status = null; // Capture the exit code produced by the helper.
        $output = Common::runCommand([PHP_BINARY, '-r', 'echo "probe";'], $status);

        // Validate both the captured output and propagated status code.
        self::assertSame('probe', $output);
        self::assertSame(0, $status);
    }

    // Confirm runCommand propagates exit codes from failing commands.
    public function testRunCommandPropagatesExitStatus(): void
    {
        $status = null; // Track the failing command's exit status.
        $output = Common::runCommand([PHP_BINARY, '-r', 'fwrite(STDERR, "nope"); exit(7);'], $status);

        // Failing commands should surface an empty string with the exit code intact.
        self::assertSame('', $output);
        self::assertSame(7, $status);
    }

    // Validate hostnames that should pass the conservative pattern.
    #[DataProvider('validHostnameProvider')]
    public function testIsValidHostnameAcceptsExpectedValues(string $candidate): void
    {
        self::assertTrue(Common::isValidHostname($candidate)); // Accept good inputs.
    }

    // Validate hostnames that should fail the conservative pattern.
    #[DataProvider('invalidHostnameProvider')]
    public function testIsValidHostnameRejectsUnexpectedValues(string $candidate): void
    {
        self::assertFalse(Common::isValidHostname($candidate)); // Reject bad inputs.
    }

    // Provide representative hostname values that align with RFC 1123 guidance.
    public static function validHostnameProvider(): array
    {
        return [
            'simple host' => ['host'],
            'numeric host' => ['n1'],
            'multi label' => ['alpha.beta.gamma'],
            'with hyphen' => ['rack-22'],
            'long label' => ['a' . str_repeat('b', 61) . 'c'],
        ];
    }

    // Provide values that violate length or character constraints.
    public static function invalidHostnameProvider(): array
    {
        return [
            'empty string' => [''],
            'leading hyphen' => ['-rack'],
            'trailing hyphen' => ['rack-'],
            'too long' => [str_repeat('a', 64)],
            'invalid character' => ['rack_01'],
        ];
    }
}
