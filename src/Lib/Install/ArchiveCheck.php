<?php
declare(strict_types=1);

namespace Lib\Install;

// ArchiveCheck verifies the template BEFORE any wipe, so a bad artifact never
// costs a working disk: size and sha256 against the plan, then the archive
// layout (everything under rootfs/, no path traversal, os-release and the
// embedded engine present), then the existing tools/check-template.php audit,
// which also refuses a template with a banned package installed (BannedPackages).
//
// stage() closes the check-then-use gap (CWE-367): an execute run copies the
// template once into a fresh mode-0700 directory, hashing while it copies, and
// every later check and the extraction read that copy, so the bytes that were
// hashed are the bytes that are extracted.
final class ArchiveCheck
{
    private const REQUIRED = ['rootfs/etc/os-release', 'rootfs/opt/mcxTemplate/installTemplate.php'];
    public const STAGE_FILE = 'template.tar.gz';
    private const CHUNK = 1048576;

    // verifyFile compares size and sha256 with the values the plan carries (dry run: read in place).
    public static function verifyFile(string $path, int $size, string $sha256): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InstallRefusal('templateMissing', 'the template file is not readable');
        }
        if (filesize($path) !== $size) {
            throw new InstallRefusal('templateSizeMismatch', 'template size differs from the plan');
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($sha256, $actual)) {
            throw new InstallRefusal('templateHashMismatch', 'template sha256 differs from the plan');
        }
    }

    /**
     * Create the fresh, private (mode 0700) staging directory $dir; refused when it already exists.
     * Shared by stage() and by the network install, which downloads straight into the directory.
     */
    public static function makeStageDir(string $dir): void
    {
        $old = umask(0077);
        $made = @mkdir($dir, 0700);
        umask($old);
        // mkdir(2) never follows a symlink at the last component, so "made" means a new, private directory.
        if (!$made || is_link($dir) || !is_dir($dir)) {
            throw new InstallRefusal('templateStageRefused', 'the staging directory already exists or could not be created');
        }
    }

    /**
     * Copy $src into the fresh directory $dir (created here, mode 0700; refused when it
     * already exists), hashing the bytes as they are written. Returns the copy's path.
     * On any mismatch or I/O error the copy and the directory are removed again.
     */
    public static function stage(string $src, string $dir, int $size, string $sha256): string
    {
        if (!is_file($src) || !is_readable($src)) {
            throw new InstallRefusal('templateMissing', 'the template file is not readable');
        }
        self::makeStageDir($dir);
        $dest = $dir . '/' . self::STAGE_FILE;
        try {
            self::copyHashed($src, $dest, $size, $sha256);
        } catch (\Throwable $e) {
            self::unstage($dir);
            throw $e;
        }
        return $dest;
    }

    // copyHashed streams $src to a new $dest while hashing; refuses on size or hash mismatch.
    private static function copyHashed(string $src, string $dest, int $size, string $sha256): void
    {
        $in = @fopen($src, 'rb');
        $out = @fopen($dest, 'xb');
        if ($in === false || $out === false) {
            throw new InstallRefusal('templateStageRefused', 'the template could not be copied to the staging directory');
        }
        $ctx = hash_init('sha256');
        $copied = 0;
        $ok = true;
        while ($ok && !feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            $copied += strlen($chunk);
            if ($copied > $size) {
                break; // larger than the plan: stop copying, refused below
            }
            hash_update($ctx, $chunk);
            $ok = $chunk === '' || fwrite($out, $chunk) === strlen($chunk);
        }
        fclose($in);
        $ok = fclose($out) && $ok;
        if (!$ok) {
            throw new InstallRefusal('templateStageRefused', 'the staged copy could not be written completely');
        }
        if ($copied !== $size) {
            throw new InstallRefusal('templateSizeMismatch', 'template size differs from the plan');
        }
        if (!hash_equals($sha256, hash_final($ctx))) {
            throw new InstallRefusal('templateHashMismatch', 'template sha256 differs from the plan');
        }
    }

    // unstage removes the staged copy and its directory; it touches only those two exact names.
    public static function unstage(string $dir): void
    {
        if ($dir === '' || is_link($dir) || !is_dir($dir)) {
            return;
        }
        $file = $dir . '/' . self::STAGE_FILE;
        if (is_file($file) && !is_link($file)) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * Refuse an archive that lacks the rootfs/ prefix or tries to escape it, and return how many leading path
     * components extraction must strip so rootfs/ lands on the target root: 2 when the members are spelled
     * "./rootfs/..." (GNU tar counts the "." as a component), 1 when spelled "rootfs/...". Both spellings in one
     * archive are refused, because one strip count cannot serve both.
     *
     * $needs are files a layout cannot boot without (LayoutPlanner::templateNeeds): each names a refusal code and
     * rootfs-relative alternatives, one of which must be a member. Checked here, before any write, so a template that
     * cannot serve the layout costs no disk (2026-11-12: a debian-12 template without mdadm was wiped onto a
     * biosNvmeUsbBoot unit and only the post-install verify refused it).
     *
     * @param array<int,string> $entries tar member names
     * @param array<int,array{code:string,paths:array<int,string>}> $needs
     */
    public static function checkEntries(array $entries, array $needs = []): int
    {
        $names = [];
        $dotted = 0;
        $bare = 0;
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }
            if (strncmp($entry, './', 2) === 0) {
                $dotted++;
            } else {
                $bare++;
            }
            $name = preg_replace('#^\./+#', '', rtrim($entry, '/')) ?? $entry;
            if ($name === '' || $name === '.') {
                continue;
            }
            $names[$name] = true;
            // An absolute member ("/rootfs/...") fails this prefix test too, so it needs no separate branch.
            if ($name !== 'rootfs' && strncmp($name, 'rootfs/', 7) !== 0) {
                throw new InstallRefusal('templateLayoutRefused', 'archive has members outside rootfs/');
            }
            if (in_array('..', explode('/', $name), true)) {
                throw new InstallRefusal('templateLayoutRefused', 'archive has a parent-relative member');
            }
        }
        foreach (self::REQUIRED as $required) {
            if (!isset($names[$required])) {
                throw new InstallRefusal('templateLayoutRefused', "archive lacks $required");
            }
        }
        foreach ($needs as $need) {
            $found = false;
            foreach ($need['paths'] as $path) {
                $found = $found || isset($names['rootfs/' . $path]);
            }
            if (!$found) {
                throw new InstallRefusal($need['code'], 'the layout needs ' . implode(' or ', $need['paths']) . ' in the template');
            }
        }
        if ($dotted > 0 && $bare > 0) {
            throw new InstallRefusal('templateLayoutRefused', 'archive mixes ./rootfs/ and rootfs/ member names');
        }
        return $dotted > 0 ? 2 : 1;
    }

    /**
     * Read one member of a tar.gz to memory through the runner. Archives made by the rehydrate tool name
     * their members with a leading "./", others without, so both spellings are tried. Null when absent.
     */
    public static function readMember(CommandRunner $runner, string $archive, string $member): ?string
    {
        foreach (['./' . $member, $member] as $name) {
            $r = $runner->run(['tar', '-xzOf', $archive, $name]);
            if ($r['code'] === 0 && $r['out'] !== '') {
                return $r['out'];
            }
        }
        return null;
    }

    // checkLayout lists the archive through the runner, applies checkEntries and runs the existing auditor.
    // Returns the strip count checkEntries derived from the member spelling. $needs: see checkEntries.
    /** @param array<int,array{code:string,paths:array<int,string>}> $needs */
    public static function checkLayout(CommandRunner $runner, string $path, array $needs = []): int
    {
        $list = $runner->run(['tar', '-tzf', $path]);
        if ($list['code'] !== 0) {
            throw new InstallRefusal('templateLayoutRefused', 'tar could not list the archive');
        }
        $strip = self::checkEntries(explode("\n", trim($list['out'])), $needs);

        $tool = dirname(__DIR__, 3) . '/tools/check-template.php';
        $audit = $runner->run([PHP_BINARY, $tool, '--path=' . $path, '--tar']);
        // Exit 3 is a banned package (docs/adr/0006); the auditor's last line names the packages.
        if ($audit['code'] === 3) {
            $named = preg_match('/^bannedPackage: ([A-Za-z0-9.+, -]{1,200})$/m', $audit['out'], $m) === 1 ? $m[1] : 'see the audit output';
            throw new InstallRefusal('bannedPackage', 'the template installs a package PM does not ship: ' . $named);
        }
        if ($audit['code'] !== 0) {
            throw new InstallRefusal('templateAuditFailed', 'tools/check-template.php reported missing parts');
        }
        return $strip;
    }
}
