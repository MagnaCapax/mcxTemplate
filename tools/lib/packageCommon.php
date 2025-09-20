<?php
declare(strict_types=1);

// packageCommon.php centralises helpers shared across packaging utilities.
// The helpers keep with the KISS rule so every script uses the same flow.

// PACKAGE_EXCLUDES mirrors the runtime paths we never archive.
const PACKAGE_EXCLUDES = [
    '/dev/*',
    '/proc/*',
    '/sys/*',
    '/tmp/*',
    '/run/*',
    '/mnt/*',
    '/media/*',
    '/lost+found',
];

// fail prints an error to STDERR and exits with a non-zero status code.
function fail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . "\n");
    exit($exitCode);
}

// commandExists checks whether a binary is reachable in the PATH.
function commandExists(string $binary): bool
{
    $escaped = escapeshellarg($binary);
    $result = shell_exec("command -v {$escaped} 2>/dev/null");
    return is_string($result) && trim($result) !== '';
}

// ensureBinary enforces that a required binary is present before running.
function ensureBinary(string $binary): void
{
    if (!commandExists($binary)) {
        fail("Required command '{$binary}' not found in PATH.");
    }
}

// ensureDirectory guarantees that a directory exists before use.
function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        fail("Unable to create directory: {$path}");
    }
}

// sanitiseIdentifier normalises filenames used for generated archives.
function sanitiseIdentifier(string $value): string
{
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '-', $value);
    return $clean === null ? 'target' : trim($clean, '-');
}

// defaultOutputPath builds a timestamped archive path for a workload.
function defaultOutputPath(string $label, string $identifier): string
{
    $safe = sanitiseIdentifier($identifier);
    if ($safe === '') {
        $safe = 'target';
    }

    $timestamp = date('Ymd-His');
    return getcwd() . DIRECTORY_SEPARATOR . "{$label}-{$safe}-{$timestamp}.tar.gz";
}

// tarExcludeArguments returns the shared --exclude arguments for tar.
function tarExcludeArguments(): array
{
    $args = [];
    foreach (PACKAGE_EXCLUDES as $pattern) {
        $args[] = '--exclude=' . escapeshellarg($pattern);
    }
    return $args;
}

// tarBaseCommandPrefix supplies the reusable tar options and excludes.
function tarBaseCommandPrefix(): string
{
    $parts = array_merge(
        ['tar', '--numeric-owner', '--xattrs', '--acls', '--one-file-system'],
        tarExcludeArguments()
    );
    return implode(' ', $parts);
}

// tokenizeArgumentString splits a shell-like string into individual tokens.
function tokenizeArgumentString(string $value): array
{
    $tokens = [];

    if ($value === '') {
        return $tokens;
    }

    $pattern = "/\"([^\"]*)\"|'([^']*)'|([^\\s\"']+)/";
    if (preg_match_all($pattern, $value, $matches)) {
        foreach ($matches[0] as $index => $_) {
            $double = $matches[1][$index] ?? '';
            if ($double !== '') {
                $tokens[] = stripcslashes($double);
                continue;
            }

            $single = $matches[2][$index] ?? '';
            if ($single !== '') {
                $tokens[] = $single;
                continue;
            }

            $plain = $matches[3][$index] ?? '';
            if ($plain !== '') {
                $tokens[] = $plain;
            }
        }
    }

    if (count($tokens) === 0 && trim($value) !== '') {
        $tokens[] = trim($value);
    }

    $normalised = [];
    $carry = null;

    foreach ($tokens as $token) {
        if ($carry !== null) {
            $normalised[] = $carry . $token;
            $carry = null;
            continue;
        }

        if ($token !== '=' && substr($token, -1) === '=') {
            // Hold tokens like "ProxyCommand=" until the value arrives.
            $carry = $token;
            continue;
        }

        $normalised[] = $token;
    }

    if ($carry !== null) {
        $normalised[] = $carry;
    }

    return $normalised;
}
