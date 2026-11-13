<?php
declare(strict_types=1);

namespace Lib\Install;

// BannedPackages is the one place that says which packages a template must not install, and the one reader of the
// dpkg status database that decides it (docs/adr/0006). The release scan (Lib\Release\ContentScan) and the install-time
// audit (tools/check-template.php) both call it, so the list and the rule cannot drift apart. It lives in
// Lib\Install because that directory ships in the engine tarball a node downloads; Lib\Release does not.
final class BannedPackages
{
    // Packages PM does not run on machines it installs. Debian package names, lower case, exact match.
    public const NAMES = ['fail2ban', 'ufw', 'crowdsec', 'sshguard'];
    // The dpkg status database, relative to the root of the captured filesystem.
    public const STATUS_MEMBER = 'var/lib/dpkg/status';
    // A real status file is a few MiB at most; a larger one is not read, and callers refuse it.
    public const STATUS_MAX = 33554432;
    // The third word of the Status field when no package files remain on disk. Any other state counts as installed:
    // installed, hold, unpacked, half-installed, half-configured, triggers-awaited, triggers-pending.
    private const GONE = ['not-installed', 'config-files'];

    /**
     * Banned packages that are present in a dpkg status file, sorted. A package is present unless its Status field
     * is well formed and says not-installed or config-files; a banned stanza without a usable Status counts as
     * present, because refusing wrongly costs a rebuild and accepting wrongly ships the package.
     *
     * @return string[]
     */
    public static function installed(string $statusFile): array
    {
        $found = [];
        $name = '';
        $gone = false;
        // The trailing empty line flushes the last stanza.
        foreach (array_merge(preg_split('/\r?\n/', $statusFile) ?: [], ['']) as $line) {
            if (trim($line) === '') {
                if ($name !== '' && !$gone) {
                    $found[$name] = true;
                }
                $name = '';
                $gone = false;
                continue;
            }
            // Continuation lines start with a space or tab and are never field names.
            if (preg_match('/^Package:\s*(\S+)\s*$/i', $line, $m) === 1) {
                $lower = strtolower($m[1]);
                $name = in_array($lower, self::NAMES, true) ? $lower : '';
            } elseif (preg_match('/^Status:\s*(\S+)\s+(\S+)\s+(\S+)\s*$/i', $line, $m) === 1) {
                $gone = in_array(strtolower($m[3]), self::GONE, true);
            }
        }
        $names = array_keys($found);
        sort($names);
        return $names;
    }
}
