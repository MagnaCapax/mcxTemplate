<?php
declare(strict_types=1);

namespace Lib\Install;

// RootPassword makes the one-off root password on the target: CSPRNG only, never
// a dictionary or default value. The plaintext is written to a 0600 file created
// with O_EXCL (--secret-out) and is never printed, logged or put in argv. Only
// the SHA-512 crypt hash goes to the chroot, through stdin of chpasswd -e.
final class RootPassword
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const SALT_ALPHABET = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const LENGTH = 20;
    private const BANNED = ['password', 'passw0rd', 'admin', 'root', 'changeme', 'qwerty', '123456', 'letmein', 'rescue'];

    /**
     * Generate 20 characters over [A-Za-z0-9], resampling until at least two
     * character classes (upper, lower, digit) are present.
     *
     * @param callable(int,int):int|null $randomInt random_int stand-in for tests
     */
    public static function generate(?callable $randomInt = null): string
    {
        $randomInt = $randomInt ?? 'random_int';
        $max = strlen(self::ALPHABET) - 1;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $candidate .= self::ALPHABET[$randomInt(0, $max)];
            }
            if (self::isStrong($candidate)) {
                return $candidate;
            }
        }
        throw new InstallRefusal('passwordGenerationFailed', 'no strong password after 100 samples');
    }

    // isStrong: at least 12 chars, at least 2 character classes, no obvious word.
    public static function isStrong(string $password): bool
    {
        if (strlen($password) < 12) {
            return false;
        }
        $classes = (preg_match('/[a-z]/', $password) === 1 ? 1 : 0)
            + (preg_match('/[A-Z]/', $password) === 1 ? 1 : 0)
            + (preg_match('/[0-9]/', $password) === 1 ? 1 : 0)
            + (preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0);
        if ($classes < 2) {
            return false;
        }
        $lower = strtolower($password);
        foreach (self::BANNED as $word) {
            if (strpos($lower, $word) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * SHA-512 crypt hash with a random 16 character salt.
     *
     * @param callable(int,int):int|null $randomInt
     */
    public static function hash(string $password, ?callable $randomInt = null): string
    {
        $randomInt = $randomInt ?? 'random_int';
        $salt = '';
        for ($i = 0; $i < 16; $i++) {
            $salt .= self::SALT_ALPHABET[$randomInt(0, strlen(self::SALT_ALPHABET) - 1)];
        }
        $hash = crypt($password, '$6$rounds=100000$' . $salt . '$');
        // crypt() returns "*0" or a short DES string when SHA-512 is unsupported: refuse, never fall back.
        if (!is_string($hash) || strncmp($hash, '$6$', 3) !== 0 || strlen($hash) < 60) {
            throw new InstallRefusal('passwordHashFailed', 'SHA-512 crypt is unavailable');
        }
        return $hash;
    }

    /**
     * Read-only check of a --secret-out path, done before the first write of a run:
     * absolute and clean, not under a target mount point (a file there is hidden once
     * the target is mounted over it), its directory exists, and the file does not.
     *
     * @param array<int,string> $forbiddenRoots mount points the secret must not live under
     */
    public static function assertUsablePath(string $path, array $forbiddenRoots = [LayoutPlanner::TARGET]): void
    {
        if ($path === '' || $path[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)|//#', $path) === 1) {
            throw new InstallRefusal('secretOutInvalid', '--secret-out must be a clean absolute path');
        }
        // As written first (the mount point may not exist yet), then with the directory resolved (symlinks).
        self::assertOutside($path, $forbiddenRoots);
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            throw new InstallRefusal('secretOutInvalid', 'the --secret-out directory does not exist');
        }
        self::assertOutside($parent . '/' . basename($path), $forbiddenRoots);
        if (file_exists($path) || is_link($path)) {
            throw new InstallRefusal('secretOutInvalid', 'the --secret-out file already exists');
        }
    }

    /** @param array<int,string> $roots refuse $path when it is one of these roots or below one (as given or resolved) */
    private static function assertOutside(string $path, array $roots): void
    {
        foreach ($roots as $root) {
            $real = realpath($root);
            foreach (array_filter([rtrim($root, '/'), $real === false ? '' : $real]) as $r) {
                if ($path === $r || strncmp($path, $r . '/', strlen($r) + 1) === 0) {
                    throw new InstallRefusal('secretOutInvalid', '--secret-out must not be under the target mount point');
                }
            }
        }
    }

    /**
     * Write the plaintext to $path (mode 0600, exclusive create) and nowhere else.
     * Refuses when the file exists, so a secret is never mixed into an old file.
     * Every write, the fsync and the close are checked; on any failure the file is removed.
     *
     * @param callable(resource,string):(int|false)|null $write fwrite stand-in for tests
     */
    public static function writeSecret(string $path, string $password, ?callable $write = null): void
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InstallRefusal('secretOutInvalid', '--secret-out must be an absolute path');
        }
        $write = $write ?? 'fwrite';
        $old = umask(0177);
        $handle = @fopen($path, 'x');
        umask($old);
        if ($handle === false) {
            throw new InstallRefusal('secretOutInvalid', 'the secret file could not be created exclusively');
        }
        @chmod($path, 0600);
        $data = $password . "\n";
        $written = $write($handle, $data);
        $ok = $written === strlen($data) && fflush($handle) && fsync($handle);
        $ok = fclose($handle) && $ok;
        if (!$ok) {
            @unlink($path);
            throw new InstallRefusal('secretOutInvalid', 'the secret file could not be written');
        }
    }
}
