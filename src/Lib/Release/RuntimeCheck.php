<?php
declare(strict_types=1);

namespace Lib\Release;

// RuntimeCheck answers one release question: will the install engine find the programs it runs inside
// the freshly extracted template? The configure step is `chroot <target> php installTemplate.php`, and
// the hardware check after the install needs smartctl. A template without them wipes a real node and
// then fails, so the release refuses it instead (refusal code templateMissingRuntime).
//
// ContentScan streams the archive once and feeds this object two facts per entry: every symlink and
// hard link (path => target) and every regular file with an execute bit. missing() then follows each
// required path the way the kernel would INSIDE the rootfs: absolute link targets start at the rootfs
// root, never at the build host, directory components that are links are followed too (merged /usr),
// and the number of links followed is bounded so a loop ends in a refusal instead of a hang.
final class RuntimeCheck
{
    // Programs the engine runs in the target (rootfs-relative path). Debian and Ubuntu ship php as
    // usr/bin/php -> /etc/alternatives/php -> /usr/bin/php8.x, so the path is followed, not just looked up.
    public const REQUIRED = ['usr/bin/php', 'usr/sbin/smartctl'];
    // Links followed per required path; Linux itself stops at 40 (ELOOP).
    public const MAX_LINKS = 40;

    /** @var array<string,string> link path => target as written in the archive */
    private array $links = [];
    /** @var array<string,true> regular files with at least one execute bit */
    private array $executables = [];

    // noteLink records a symbolic link; $target keeps its own spelling (relative or absolute).
    public function noteLink(string $path, string $target): void
    {
        $this->links[$path] = $target;
    }

    // noteExecutable records a regular file whose mode has an execute bit.
    public function noteExecutable(string $path): void
    {
        $this->executables[$path] = true;
    }

    /**
     * @return string[] the REQUIRED paths that are not an executable file after following links
     */
    public function missing(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $path) {
            $real = $this->resolve($path);
            if ($real === null || !isset($this->executables[$real])) {
                $missing[] = $path;
            }
        }
        return $missing;
    }

    // resolve walks $path component by component, expanding every link it meets; null when the chain
    // is longer than MAX_LINKS. A ".." at the root stays at the root, as in a chroot.
    private function resolve(string $path): ?string
    {
        $pending = explode('/', $path);
        $done = [];
        $followed = 0;
        while ($pending !== []) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($done);
                continue;
            }
            $here = implode('/', array_merge($done, [$part]));
            if (!isset($this->links[$here])) {
                $done[] = $part;
                continue;
            }
            if (++$followed > self::MAX_LINKS) {
                return null;
            }
            $target = $this->links[$here];
            if (substr($target, 0, 1) === '/') {
                $done = []; // absolute: the rootfs root, not the build host's
            }
            $pending = array_merge(explode('/', $target), $pending);
        }
        return implode('/', $done);
    }
}
