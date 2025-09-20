<?php
declare(strict_types=1);
// -----------------------------------------------------------------------------
// OsRelease.php - Helpers for interrogating os-release metadata files.
// Keeps detectDistro() reusable for the runtime script and unit tests alike.
// -----------------------------------------------------------------------------

if (!function_exists('detectDistro')) {
    /**
     * Detect distro information from /etc/os-release when overrides are missing.
     */
    function detectDistro(string $currentId, string $currentVersion, string $osReleasePath = '/etc/os-release'): array
    {
        $distroId = $currentId;
        $distroVersion = $currentVersion;
        // Start with the supplied identifiers and fill in any missing pieces.

        // Optional path keeps unit tests hermetic while defaulting to /etc/os-release.

        if ($distroId !== '' && $distroVersion !== '') {
            return [$distroId, $distroVersion];
            // Short-circuit when both values were explicitly provided by caller.
        }

        if (!is_file($osReleasePath)) {
            return [$distroId, $distroVersion];
            // Leave detection untouched if the metadata file is absent entirely.
        }

        $data = @parse_ini_file($osReleasePath);
        if ($data === false) {
            return [$distroId, $distroVersion];
            // Fail softly so the operator can fall back to manual overrides later.
        }

        if ($distroId === '' && isset($data['ID'])) {
            $distroId = trim((string) $data['ID']);
            // Mirror the behaviour of the historical Bash helper for ID values.
        }

        if ($distroVersion === '' && isset($data['VERSION_ID'])) {
            $distroVersion = trim((string) $data['VERSION_ID']);
            // Prefer VERSION_ID so we align with numeric release identifiers.
        }

        if ($distroVersion === '' && isset($data['VERSION_CODENAME'])) {
            $distroVersion = trim((string) $data['VERSION_CODENAME']);
            // Fall back to VERSION_CODENAME when numeric identifiers are missing.
        }

        return [$distroId, $distroVersion];
    }
}
