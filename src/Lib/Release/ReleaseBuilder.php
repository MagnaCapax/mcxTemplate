<?php
declare(strict_types=1);

namespace Lib\Release;

use Lib\Install\ArchiveCheck;
use Lib\Install\CommandRunner;
use Lib\Install\InstallRefusal;
use Lib\Install\PlanValidator;
use Lib\Provisioning\Common;

// ReleaseBuilder turns a captured rootfs archive into published artifacts:
//   <name>-<version>.tar.gz (+ .sha256, .manifest.json)   the template, engine embedded at git HEAD
//   mcxInstallEngine-<commit>.tar.gz                      the installer files the bootstrap downloads
//   mcxInstall.sh                                         the bootstrap with its three constants filled
// Every external step goes through the CommandRunner (argv arrays, no shell) and reuses the existing
// tools: tools/templateRehydrate.php and tools/check-template.php run unchanged. It refuses a dirty
// engine repository, a rootfs that fails ContentScan, and (unless told otherwise) a non-root run:
// the rehydrate step extracts the rootfs with tar, and only root keeps the file owners.
// ContentScan notices (a known-public package key fixture) do not refuse; they come back as scanNotices.
final class ReleaseBuilder
{
    // Installer files the node needs: the entry point, its library and the layout auditor ArchiveCheck calls.
    public const ENGINE_PATHS = ['installNode.php', 'src/Lib/Install', 'tools/check-template.php'];
    private const NAME = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';
    private const VERSION = '/^\d{8}(?:\.\d{1,3})?$/D';

    private CommandRunner $runner;
    private string $repo;
    private bool $isRoot;
    private bool $allowNonRoot;
    /** @var callable():int */
    private $now;

    /**
     * @param string $repo engine repository (git work tree); tools/ and bootstrap/ are read from it
     */
    public function __construct(CommandRunner $runner, string $repo, bool $isRoot, bool $allowNonRoot = false, ?callable $now = null)
    {
        $this->runner = $runner;
        $this->repo = rtrim($repo, '/');
        $this->isRoot = $isRoot;
        $this->allowNonRoot = $allowNonRoot;
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @return array{template:string,sha256:string,manifest:array<string,mixed>,engine:string,engineSha256:string,bootstrap:string,bootstrapSha256:string,scanNotices:string[]}
     */
    public function build(string $rootfs, string $name, string $version, string $out, string $apiUrl, string $artifactBase): array
    {
        $this->checkInputs($rootfs, $name, $version, $out, $apiUrl, $artifactBase);
        $commit = $this->cleanHead();
        [$refusals, $notices] = ContentScan::partition(ContentScan::scan($rootfs));
        $describe = static fn (array $f): string => $f['code'] . ($f['path'] !== '' ? ' ' . $f['path'] : '');
        if ($refusals !== []) {
            throw new InstallRefusal('contentScanRefused', implode(', ', array_map($describe, $refusals)));
        }
        $scanNotices = array_map($describe, $notices);

        if (!@mkdir($out, 0755, true) && !is_dir($out)) {
            throw new InstallRefusal('outputDirFailed', 'the output directory could not be created');
        }
        $file = $name . '-' . $version . '.tar.gz';
        $engineFile = 'mcxInstallEngine-' . substr($commit, 0, 12) . '.tar.gz';
        foreach ([$file, $file . '.sha256', $file . '.manifest.json', $engineFile, 'mcxInstall.sh'] as $f) {
            if (file_exists($out . '/' . $f) || is_link($out . '/' . $f)) {
                throw new InstallRefusal('outputExists', $f . ' already exists in the output directory');
            }
        }
        $work = $out . '/.work-' . bin2hex(random_bytes(4));
        $template = $out . '/' . $file;
        $engineDir = $work . '/engine';
        if (!@mkdir($work, 0700) || !@mkdir($engineDir, 0700)) {
            throw new InstallRefusal('workDirFailed', 'the work directory could not be created');
        }
        try {
            $this->exportHead($commit, $work, $engineDir);
            // A release carries exactly the engine of the commit it pins; an engine copy already in the capture is stale.
            $this->mustRun([PHP_BINARY, $this->repo . '/tools/templateRehydrate.php', '--rootfs', $rootfs, '--output', $template,
                '--template', $engineDir, '--embed-force'], 'rehydrateFailed');
            $this->mustRun([PHP_BINARY, $this->repo . '/tools/check-template.php', '--path=' . $template, '--tar'], 'checkTemplateFailed');
            $manifest = $this->manifest($template, $name, $version, $commit, $artifactBase . '/' . $file);
            $sha = $manifest['sha256'];
            file_put_contents($out . '/' . $file . '.sha256', $sha . '  ' . $file . "\n");
            file_put_contents($out . '/' . $file . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $this->mustRun(array_merge(['git', '-C', $this->repo, 'archive', '--format=tar.gz', '-o', $out . '/' . $engineFile, $commit, '--'],
                self::ENGINE_PATHS), 'engineArchiveFailed');
            $engineSha = (string) hash_file('sha256', $out . '/' . $engineFile);
            $bootstrap = BootstrapRenderer::render((string) file_get_contents($this->repo . '/bootstrap/mcxInstall.sh'),
                $artifactBase . '/' . $engineFile, $engineSha, $apiUrl);
            file_put_contents($out . '/mcxInstall.sh', $bootstrap);
            chmod($out . '/mcxInstall.sh', 0755);
            $this->mustRun(['sh', '-n', $out . '/mcxInstall.sh'], 'bootstrapSyntax');
        } catch (\Throwable $error) {
            // A failed release leaves no half-made artifact that would block the next attempt.
            foreach ([$file, $file . '.sha256', $file . '.manifest.json', $engineFile, 'mcxInstall.sh'] as $made) {
                @unlink($out . '/' . $made);
            }
            throw $error;
        } finally {
            // The work directory holds only the exported engine; emptyDirectory never follows symlinks.
            Common::emptyDirectory($work);
            @rmdir($work);
        }
        return ['template' => $template, 'sha256' => $sha, 'manifest' => $manifest, 'engine' => $out . '/' . $engineFile,
            'engineSha256' => $engineSha, 'bootstrap' => $out . '/mcxInstall.sh', 'bootstrapSha256' => (string) hash_file('sha256', $out . '/mcxInstall.sh'),
            'scanNotices' => $scanNotices];
    }

    private function checkInputs(string $rootfs, string $name, string $version, string $out, string $apiUrl, string $artifactBase): void
    {
        if (preg_match(self::NAME, $name) !== 1 || preg_match(self::VERSION, $version) !== 1) {
            throw new InstallRefusal('releaseInputInvalid', '--name or --version is malformed');
        }
        // Throws planInvalid for anything but a plain https URL; a trailing slash on the base would double up.
        PlanValidator::httpsUrl($apiUrl, '--api-url');
        PlanValidator::httpsUrl($artifactBase, '--artifact-base');
        if (substr($artifactBase, -1) === '/') {
            throw new InstallRefusal('releaseInputInvalid', '--artifact-base must not end with a slash');
        }
        if (!is_file($rootfs) || !is_readable($rootfs)) {
            throw new InstallRefusal('releaseInputInvalid', '--rootfs is not a readable file');
        }
        if ($out === '' || $out[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)|//#', $out) === 1) {
            throw new InstallRefusal('releaseInputInvalid', '--out must be a clean absolute path');
        }
        if (!$this->isRoot && !$this->allowNonRoot) {
            throw new InstallRefusal('notRoot', 'run as root: tar keeps file owners only for root (or pass --unsafe-allow-non-root for a test build)');
        }
    }

    // cleanHead returns the full HEAD commit and refuses when the work tree has any change (untracked files included).
    private function cleanHead(): string
    {
        $status = $this->runner->run(['git', '-C', $this->repo, 'status', '--porcelain', '--untracked-files=all']);
        if ($status['code'] !== 0) {
            throw new InstallRefusal('gitFailed', 'git status failed in the engine repository');
        }
        if (trim($status['out']) !== '') {
            throw new InstallRefusal('dirtyTree', 'the engine repository has uncommitted or untracked changes; commit them first');
        }
        $head = $this->runner->run(['git', '-C', $this->repo, 'rev-parse', 'HEAD']);
        $commit = trim($head['out']);
        if ($head['code'] !== 0 || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw new InstallRefusal('gitFailed', 'git rev-parse HEAD did not return a commit');
        }
        return $commit;
    }

    // exportHead writes the files of $commit (not the work tree, not .git) into $engineDir.
    private function exportHead(string $commit, string $work, string $engineDir): void
    {
        $tarFile = $work . '/engine.tar';
        $this->mustRun(['git', '-C', $this->repo, 'archive', '--format=tar', '-o', $tarFile, $commit], 'engineExportFailed');
        $this->mustRun(['tar', '-xf', $tarFile, '-C', $engineDir], 'engineExportFailed');
    }

    /**
     * @return array<string,mixed>
     */
    private function manifest(string $template, string $name, string $version, string $commit, string $url): array
    {
        // os-release(5): /etc/os-release, else /usr/lib/os-release. On Debian and Ubuntu the first is a relative symlink
        // to the second, and tar -xO of a symlink member prints nothing, so the target is read as the fallback.
        $osRelease = ArchiveCheck::readMember($this->runner, $template, 'rootfs/etc/os-release')
            ?? ArchiveCheck::readMember($this->runner, $template, 'rootfs/usr/lib/os-release') ?? '';
        $id = $versionId = '';
        foreach (explode("\n", $osRelease) as $line) {
            if (preg_match('/^(ID|VERSION_ID)="?([A-Za-z0-9._-]{1,32})"?$/', trim($line), $m) === 1) {
                if ($m[1] === 'ID') {
                    $id = $m[2];
                } else {
                    $versionId = $m[2];
                }
            }
        }
        if ($id === '' || $versionId === '') {
            throw new InstallRefusal('osReleaseMissing', 'rootfs/etc/os-release has no ID and VERSION_ID');
        }
        return ['name' => $name, 'version' => $version, 'distro' => $id, 'distroVersion' => $versionId,
            'sizeBytes' => (int) filesize($template), 'sha256' => (string) hash_file('sha256', $template), 'url' => $url,
            'engineCommit' => $commit, 'builtAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->now)())];
    }

    private function mustRun(array $argv, string $code): void
    {
        $r = $this->runner->run($argv);
        if ($r['code'] !== 0) {
            // Command name and exit code only: tool output could carry paths from the rootfs.
            throw new InstallRefusal($code, basename((string) $argv[0]) . ' exited ' . $r['code']);
        }
    }
}
