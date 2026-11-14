<?php
declare(strict_types=1);

namespace Lib\Install;

// HostKeys reads the SSH host public keys of the freshly configured system while the target is still mounted
// (configure task 13 generated them in the chroot). In network mode they travel in the result's facts over the
// token-authenticated channel, so whoever logs in to the installed node first can pin them instead of trusting
// the first key that answers on port 22 (CWE-322). Only *.pub files are opened, never a private key.
// The tree came from an archive, so a symlinked etc, etc/ssh or key file is refused (CWE-59): a link could hand
// over another system's key. Fail-soft: a missing or refused key gives an empty list, and the consumer that wants
// to pin decides (install-node refuses to log in without a reported key); the installed system itself is fine.
final class HostKeys
{
    public const FILES = ['ssh_host_ed25519_key.pub', 'ssh_host_ecdsa_key.pub'];
    private const MAX_BYTES = 4096;
    private const LINE = '/^(ssh-ed25519|ecdsa-sha2-nistp(?:256|384|521)) ([A-Za-z0-9+\/]{40,1024}={0,3})(?: [^\n]*)?\n?$/D';

    /**
     * @return array<int,string> "type base64" per key, ed25519 first, comments dropped; empty when none is usable
     */
    public static function read(string $targetRoot): array
    {
        $root = realpath(rtrim($targetRoot, '/'));
        $dir = $root === false ? false : realpath($root . '/etc/ssh');
        if ($root === false || $dir !== $root . '/etc/ssh') {
            return [];
        }
        $keys = [];
        foreach (self::FILES as $name) {
            $file = $dir . '/' . $name;
            if (is_link($file) || !is_file($file) || (int) filesize($file) > self::MAX_BYTES) {
                continue;
            }
            $text = file_get_contents($file);
            if (is_string($text) && preg_match(self::LINE, $text, $m) === 1) {
                $keys[] = $m[1] . ' ' . $m[2];
            }
        }
        return $keys;
    }
}
