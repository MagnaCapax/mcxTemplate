<?php
declare(strict_types=1);

namespace Lib\Release;

use Lib\Install\BannedPackages;
use Lib\Install\CommandRunner;
use Lib\Install\InstallRefusal;

// DebianRootfsBuilder builds a minimal Debian (or Debian-derivative, e.g. Ubuntu) root filesystem archive from
// the distribution's archive with mmdebstrap, instead of capturing a golden machine. The result is the --rootfs
// input of tools/templateRelease.php: a gzip-compressed tar with no rootfs/ prefix ("./etc/...", as a capture).
//
// Inputs are a suite name and a reviewed package list (distros/<distro>/<version>/minimal.packages):
// one package per line, each with a "# needed by <code>" comment naming what requires it. The build is
// variant minbase plus that list; Recommends are not installed (mmdebstrap's default, so no apt option
// has to be left behind in the image). Three files are written into the image by mmdebstrap's
// "upload" hook, so nothing of the build host leaks into a published template (mmdebstrap otherwise
// copies the host's /etc/hostname and /etc/resolv.conf) and root can log in with the password the
// install sets (Debian's sshd default is prohibit-password). See OVERLAY.
//
// Refusals happen before any write: a malformed argument or list, a banned package in the list
// (BannedPackages), an existing --out, a non-root run (mmdebstrap needs root to give files their real
// owners) and a missing mmdebstrap. After the build the image's dpkg status is read and a banned
// package in it refuses before the archive is published. Every command is an argv array (no shell).
final class DebianRootfsBuilder
{
    // Image path => content. hostname and resolv.conf are rewritten at install time (tasks 21 and 23).
    public const OVERLAY = [
        '/etc/hostname' => "localhost\n",
        '/etc/resolv.conf' => '',
        '/etc/ssh/sshd_config.d/50-mcxtemplate-root-login.conf' => "# Written by mcxTemplate templateBuildDebian: root logs in with the password the install sets.\nPermitRootLogin yes\n",
        // Kernel NIC names (eth0, ...), as on captured templates: task 22 then names the NIC from probe order
        // (Common::installedInterfaceName) instead of trusting that this udev names it as the rescue's udev does.
        '/etc/default/grub.d/50-mcxtemplate-net.cfg' => "# Written by mcxTemplate templateBuildDebian: kernel NIC names (eth0, ...).\nGRUB_CMDLINE_LINUX=\"\$GRUB_CMDLINE_LINUX net.ifnames=0\"\n",
    ];
    private const SUITE = '/^[a-z][a-z0-9-]{1,31}$/D';
    private const PACKAGE = '/^[a-z0-9][a-z0-9+.-]{1,99}$/D';
    private const LINE = '/^(\S+)\s+#\s*needed by\s+(\S.*)$/D';
    private const MIRROR = '#^https?://[A-Za-z0-9][A-Za-z0-9._~:/%+-]{0,250}$#D';
    // Optional apt sources, for archives mmdebstrap does not know (Ubuntu): comma lists of names, and a keyring file path.
    private const COMPONENTS = '/^[a-z][a-z0-9-]{0,31}(,[a-z][a-z0-9-]{0,31}){0,7}$/D';
    private const POCKETS = '/^[a-z][a-z0-9]{0,31}(,[a-z][a-z0-9]{0,31}){0,7}$/D';
    private const KEYRING = '#^/[A-Za-z0-9._+/-]{1,200}\.gpg$#D';

    private CommandRunner $runner;
    private bool $isRoot;
    private string $mmdebstrap;
    private string $lastError = '';

    /**
     * @param string $mmdebstrap absolute path of the mmdebstrap binary (the CLI resolves it from PATH; tests inject one)
     */
    public function __construct(CommandRunner $runner, bool $isRoot, string $mmdebstrap)
    {
        $this->runner = $runner;
        $this->isRoot = $isRoot;
        $this->mmdebstrap = $mmdebstrap;
    }

    /**
     * Parse --suite=, --packages=, --out= (required) and --mirror=, --components=, --pockets=, --keyring= (optional).
     * Throws "usage" on anything else.
     *
     * @param array<int,string> $args arguments without the program name
     * @return array{suite:string,packages:string,out:string,mirror:string,components:string,pockets:string,keyring:string}
     */
    public static function parseArguments(array $args): array
    {
        $want = ['suite' => '', 'packages' => '', 'out' => '', 'mirror' => '', 'components' => '', 'pockets' => '', 'keyring' => ''];
        foreach ($args as $arg) {
            $eq = strpos($arg, '=');
            $key = $eq === false ? '' : substr($arg, 2, $eq - 2);
            if (strncmp($arg, '--', 2) !== 0 || !array_key_exists($key, $want) || substr($arg, $eq + 1) === '') {
                throw new InstallRefusal('usage', 'unknown or empty argument');
            }
            $want[$key] = substr($arg, $eq + 1);
        }
        foreach (['suite', 'packages', 'out'] as $required) {
            if ($want[$required] === '') {
                throw new InstallRefusal('usage', '--' . $required . ' is required');
            }
        }
        return $want;
    }

    /**
     * Read a reviewed package list. Blank lines and lines starting with "#" are skipped; every other line
     * is "<package>  # needed by <reason>". A line without the reason, a malformed name or a duplicate refuses.
     *
     * @return array<string,string> package => reason, in file order
     */
    public static function readPackageList(string $text): array
    {
        $packages = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $n => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match(self::LINE, $line, $m) !== 1 || preg_match(self::PACKAGE, $m[1]) !== 1) {
                throw new InstallRefusal('packageListInvalid', 'line ' . ($n + 1) . ' is not "<package>  # needed by <reason>"');
            }
            if (isset($packages[$m[1]])) {
                throw new InstallRefusal('packageListInvalid', 'line ' . ($n + 1) . ' repeats a package');
            }
            $packages[$m[1]] = $m[2];
        }
        if ($packages === []) {
            throw new InstallRefusal('packageListInvalid', 'the list names no package');
        }
        return $packages;
    }

    // lastError: the tail of mmdebstrap's stderr after a failed build (operator diagnostics, printed by the CLI).
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @param array{components?:string,pockets?:string,keyring?:string} $sources optional apt sources (see sourcesArguments)
     * @return array{suite:string,packages:int,bytes:int,sha256:string,out:string}
     */
    public function build(string $suite, string $packagesFile, string $out, string $mirror = '', array $sources = []): array
    {
        $packages = $this->checkInputs($suite, $packagesFile, $out, $mirror, $sources);
        $work = dirname($out) . '/.build-' . bin2hex(random_bytes(6));
        $old = umask(0077);
        $created = @mkdir($work, 0700);
        umask($old);
        if (!$created || is_link($work)) {
            throw new InstallRefusal('workDirFailed', 'the private build directory could not be created');
        }
        $tar = $work . '/rootfs.tar';
        $made = [$tar];
        $status = '';
        try {
            $argv = [$this->mmdebstrap, '--variant=minbase', '--format=tar', '--include=' . implode(',', array_keys($packages))];
            // upload needs the target directory; the directories come from the OVERLAY constant only, never from input.
            $dirs = array_unique(array_map('dirname', array_keys(self::OVERLAY)));
            $argv[] = '--customize-hook=mkdir -p ' . implode(' ', array_map(static fn (string $d): string => '"$1' . $d . '"', $dirs));
            foreach (self::OVERLAY as $inside => $content) {
                $local = $work . '/overlay-' . count($made);
                $made[] = $local;
                if (file_put_contents($local, $content) !== strlen($content)) {
                    throw new InstallRefusal('workDirFailed', 'an overlay file could not be written');
                }
                $argv[] = '--customize-hook=upload ' . $local . ' ' . $inside;
            }
            array_push($argv, $suite, $tar);
            array_push($argv, ...self::sourcesArguments($suite, $mirror, $sources));
            // TMPDIR keeps mmdebstrap's chroot on the output filesystem, not on a small /tmp tmpfs.
            $run = $this->runner->run($argv, null, ['TMPDIR' => $work] + self::environment());
            if ($run['code'] !== 0 || !is_file($tar)) {
                $this->lastError = implode("\n", array_slice(explode("\n", trim($run['err'])), -20));
                throw new InstallRefusal('mmdebstrapFailed', 'mmdebstrap exited ' . $run['code']);
            }
            $status = $this->readStatus($tar);
            $banned = BannedPackages::installed($status);
            if ($banned !== []) {
                throw new InstallRefusal('bannedPackageInstalled', implode(',', $banned) . ' is installed in the built image');
            }
            $this->publish($tar, $out);
        } finally {
            foreach ($made as $file) {
                if (is_file($file) && !is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($work); // stays (as .build-<hex> next to --out) only if mmdebstrap left its own files behind
        }
        return ['suite' => $suite, 'packages' => self::installedCount($status), 'bytes' => (int) filesize($out),
            'sha256' => (string) hash_file('sha256', $out), 'out' => $out];
    }

    /**
     * Where the image's apt sources come from. Without --components, --pockets and --keyring mmdebstrap decides
     * (Debian: the suite, its -updates and -security, main). With any of them each source is written out as one
     * line, which needs --mirror: mmdebstrap does not know Ubuntu's pockets, so without this a noble image would
     * carry only the release pocket and never see a security update. --keyring becomes the line's signed-by and
     * must exist on the build host (the distribution's keyring package) and, at the same path, in the image.
     *
     * @param array{components?:string,pockets?:string,keyring?:string} $sources
     * @return array<int,string> the mmdebstrap MIRROR arguments after TARGET
     */
    public static function sourcesArguments(string $suite, string $mirror, array $sources): array
    {
        $components = $sources['components'] ?? '';
        $pockets = $sources['pockets'] ?? '';
        $keyring = $sources['keyring'] ?? '';
        if ($components === '' && $pockets === '' && $keyring === '') {
            return $mirror === '' ? [] : [$mirror];
        }
        $suites = [$suite];
        foreach ($pockets === '' ? [] : explode(',', $pockets) as $pocket) {
            $suites[] = $suite . '-' . $pocket;
        }
        $options = $keyring === '' ? '' : ' [signed-by=' . $keyring . ']';
        $lines = [];
        foreach ($suites as $name) {
            $lines[] = 'deb' . $options . ' ' . $mirror . ' ' . $name . ' ' . str_replace(',', ' ', $components === '' ? 'main' : $components);
        }
        return $lines;
    }

    /**
     * @param array{components?:string,pockets?:string,keyring?:string} $sources
     * @return array<string,string> package => reason
     */
    private function checkInputs(string $suite, string $packagesFile, string $out, string $mirror, array $sources = []): array
    {
        if (preg_match(self::SUITE, $suite) !== 1) {
            throw new InstallRefusal('buildInputInvalid', '--suite is malformed');
        }
        if ($mirror !== '' && preg_match(self::MIRROR, $mirror) !== 1) {
            throw new InstallRefusal('buildInputInvalid', '--mirror must be a plain http(s) URL');
        }
        $this->checkSources($mirror, $sources);
        if ($out === '' || $out[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)|//|\s#', $out) === 1 || substr($out, -7) !== '.tar.gz') {
            throw new InstallRefusal('buildInputInvalid', '--out must be a clean absolute path ending in .tar.gz');
        }
        if (!is_dir(dirname($out))) {
            throw new InstallRefusal('buildInputInvalid', 'the directory of --out does not exist');
        }
        if (!is_file($packagesFile) || !is_readable($packagesFile)) {
            throw new InstallRefusal('buildInputInvalid', '--packages is not a readable file');
        }
        $packages = self::readPackageList((string) file_get_contents($packagesFile));
        $banned = array_values(array_intersect(array_keys($packages), BannedPackages::NAMES));
        if ($banned !== []) {
            throw new InstallRefusal('bannedPackageListed', implode(',', $banned) . ' must not be in a template');
        }
        if (file_exists($out) || is_link($out)) {
            throw new InstallRefusal('outputExists', '--out already exists; it is never replaced');
        }
        if (!$this->isRoot) {
            throw new InstallRefusal('notRoot', 'run as root: mmdebstrap needs root to give the files their real owners');
        }
        if ($this->mmdebstrap === '' || $this->mmdebstrap[0] !== '/' || !is_file($this->mmdebstrap) || !is_executable($this->mmdebstrap)) {
            throw new InstallRefusal('mmdebstrapMissing', 'mmdebstrap is not installed (apt install mmdebstrap)');
        }
        return $packages;
    }

    // checkSources refuses a malformed --components, --pockets or --keyring, any of them without --mirror, and a keyring file that is not on this host.
    /** @param array{components?:string,pockets?:string,keyring?:string} $sources */
    private function checkSources(string $mirror, array $sources): void
    {
        $patterns = ['components' => self::COMPONENTS, 'pockets' => self::POCKETS, 'keyring' => self::KEYRING];
        $given = false;
        foreach ($patterns as $key => $pattern) {
            $value = $sources[$key] ?? '';
            if ($value === '') {
                continue;
            }
            $given = true;
            if (preg_match($pattern, $value) !== 1 || ($key === 'keyring' && preg_match('#(^|/)\.\.?(/|$)|//#', $value) === 1)) {
                throw new InstallRefusal('buildInputInvalid', '--' . $key . ' is malformed');
            }
        }
        if ($given && $mirror === '') {
            throw new InstallRefusal('buildInputInvalid', '--components, --pockets and --keyring need --mirror');
        }
        $keyring = $sources['keyring'] ?? '';
        if ($keyring !== '' && !is_file($keyring)) {
            throw new InstallRefusal('buildInputInvalid', '--keyring is not a file on this host (install the distribution keyring package)');
        }
    }

    // installedCount: packages whose dpkg Status is "install ok installed" (reported, not checked).
    private static function installedCount(string $status): int
    {
        return (int) preg_match_all('/^Status:\s*install ok installed\s*$/m', str_replace("\r\n", "\n", $status));
    }

    // readStatus reads var/lib/dpkg/status from the uncompressed image tar (members are spelled "./x").
    private function readStatus(string $tar): string
    {
        foreach (['./' . BannedPackages::STATUS_MEMBER, BannedPackages::STATUS_MEMBER] as $member) {
            $r = $this->runner->run(['tar', '-xOf', $tar, $member]);
            if ($r['code'] === 0 && trim($r['out']) !== '') {
                return $r['out'];
            }
        }
        throw new InstallRefusal('dpkgStatusMissing', 'the built image has no readable ' . BannedPackages::STATUS_MEMBER);
    }

    // publish gzips the tar to a private temporary name next to --out, then links it to --out, which
    // fails rather than replace a file that appeared in the meantime.
    private function publish(string $tar, string $out): void
    {
        $part = $out . '.part-' . bin2hex(random_bytes(4));
        $in = @fopen($tar, 'rb');
        $gz = @fopen($part, 'xb');
        if ($in === false || $gz === false) {
            throw new InstallRefusal('outputFailed', 'the archive could not be opened for writing');
        }
        // window 31 = deflate with a gzip header and trailer (zlib.deflate filter).
        $filter = stream_filter_append($gz, 'zlib.deflate', STREAM_FILTER_WRITE, ['level' => 6, 'window' => 31]);
        $copied = stream_copy_to_stream($in, $gz);
        fclose($in);
        $closed = fclose($gz); // flushes the deflate trailer
        $ok = $filter !== false && $closed && $copied === filesize($tar);
        if (!$ok || !@link($part, $out)) {
            @unlink($part);
            throw new InstallRefusal('outputFailed', 'the archive could not be written to --out');
        }
        @unlink($part);
    }

    /** @return array<string,string> the caller's environment (a non-empty runner env replaces it entirely) */
    private static function environment(): array
    {
        $env = getenv();
        return is_array($env) ? array_filter($env, 'is_string') : [];
    }
}
