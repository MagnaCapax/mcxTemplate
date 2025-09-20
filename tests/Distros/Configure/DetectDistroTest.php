<?php
declare(strict_types=1);

namespace Tests\Distros\Configure;

use PHPUnit\Framework\TestCase;

// Unit tests exercise distro detection without executing provisioning tasks.
final class DetectDistroTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../fixtures/os-release';
    // Keep a single fixture path helper so individual tests stay concise.

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/distros/common/lib/OsRelease.php';
        // Pull the helper directly so tests avoid running the orchestration script.
    }

    private function fixturePath(string $name): string
    {
        return self::FIXTURE_DIR . '/' . $name;
        // Helper centralises fixture lookup to avoid repeated path math.
    }

    public function testOverridesShortCircuitDetection(): void
    {
        $result = detectDistro('CustomOS', '9.9', $this->fixturePath('nonexistent'));
        // Use a missing file path to confirm the override bypasses filesystem reads.

        $this->assertSame(['CustomOS', '9.9'], $result);
        // Both fields must round-trip untouched when the override is provided.
    }

    public function testDetectionFromCompleteFixture(): void
    {
        $result = detectDistro('', '', $this->fixturePath('complete'));
        // Empty inputs force the function to parse the provided os-release copy.

        $this->assertSame(['Debian', '11.7'], $result);
        // Expect the tuple to mirror the values defined in the sample file.
    }

    public function testDetectionFallsBackToCodename(): void
    {
        $result = detectDistro('', '', $this->fixturePath('missing_version_id'));
        // The fixture omits VERSION_ID so VERSION_CODENAME should win instead.

        $this->assertSame(['Ubuntu', 'focal'], $result);
        // Ensure the fallback produces a value when numeric releases are absent.
    }

    public function testMissingFileLeavesValuesUnchanged(): void
    {
        $result = detectDistro('', '', $this->fixturePath('nonexistent'));
        // When the file is absent, the helper should simply echo the inputs back.

        $this->assertSame(['', ''], $result);
        // Both fields remain empty so callers can decide on next steps.
    }

    public function testNormalisationLowersAndTrimsVersion(): void
    {
        $result = detectDistro('', '', $this->fixturePath('complete'));
        // Reuse the complete fixture so dotted version handling is exercised.

        [$id, $version] = $this->normaliseTuple($result);
        // Apply the same normalisation branch used by the runtime script.

        $this->assertSame(['debian', '11'], [$id, $version]);
        // Lowercase ID and major-only version confirm the branch is respected.
    }

    private function normaliseTuple(array $tuple): array
    {
        [$id, $version] = $tuple;
        // Begin with the tuple returned by detectDistro() itself.

        $id = $id === '' ? '' : strtolower($id);
        // Script lowercases IDs so directory lookups stay consistent.

        if ($version !== '') {
            $majorPieces = explode('.', $version);
            // Mirror the runtime split to isolate the major version token.

            $majorVersion = strtolower(trim($majorPieces[0] ?? ''));
            // Guard against odd data by trimming the first segment carefully.

            $version = $majorVersion !== '' ? $majorVersion : strtolower($version);
            // Prefer the major segment but fall back to a lowercase copy when empty.
        }

        return [$id, $version];
        // Return the normalised tuple so assertions can compare end results.
    }
}
