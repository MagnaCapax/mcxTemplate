<?php
declare(strict_types=1);

namespace Lib\Install;

// Installer sequences the install core: read-only checks first (disk match,
// template verification), then the planned steps. Dry-run stops after planning.
// Two runners keep the safety line visible: $reader is only used for reads
// (lsblk, udevadm, findmnt, tar -t, blkid, a one-sector dd read); $writer runs
// the planned commands and is never called in dry-run mode.
final class Installer
{
    // Strict configure prints this exact line after every task succeeded (distros/configure.php).
    // Exit 0 without it means an engine that ignored --strict, or work that was skipped: not a success.
    public const CONFIGURE_SENTINEL = 'MCXTEMPLATE-STRICT-CONFIGURE-OK';
    // The template copy lives here during an execute run: tmpfs on the rescue system, writable by root only.
    public const STAGE_ROOT = '/run';

    private CommandRunner $reader;
    private CommandRunner $writer;
    private SystemStateReader $stateReader;
    /** @var callable|null */
    private $uuid;
    private string $targetRoot;
    private string $stageRoot;

    public function __construct(CommandRunner $reader, CommandRunner $writer, SystemStateReader $stateReader,
        ?callable $uuid = null, string $targetRoot = LayoutPlanner::TARGET, string $stageRoot = self::STAGE_ROOT)
    {
        $this->reader = $reader;
        $this->writer = $writer;
        $this->stateReader = $stateReader;
        $this->uuid = $uuid;
        $this->targetRoot = $targetRoot;
        $this->stageRoot = rtrim($stageRoot, '/');
    }

    /**
     * Read-only phase: match disks, verify the template, build the step list.
     * With $stage (execute runs) the template is first copied into a fresh private
     * directory while hashing, and the layout checks and the extraction use that
     * copy. Without it (dry run) the template is verified in place and nothing is
     * written. Throws InstallRefusal with a named code on any problem.
     *
     * With $fetch (network mode, needs $stage) the template does not exist yet: right after
     * the disk match the fresh staging directory is created and $fetch($stagedPath, $match)
     * must download the template into that path. The size and sha256 check below then runs on
     * the downloaded file, so the bytes that were hashed are the bytes that get extracted.
     *
     * @param array<string,mixed> $plan validated plan
     * @param callable(string,array<string,mixed>):void|null $fetch downloads the template to the given path
     * @return array{match:array<string,mixed>,steps:array<int,array<string,mixed>>,stage:?string}
     */
    public function prepare(array $plan, bool $stage = false, ?callable $fetch = null): array
    {
        if ($fetch !== null && !$stage) {
            throw new InstallRefusal('planInvalid', 'a template download needs a staged (execute) run');
        }
        $inventory = DiskInventory::enumerate($this->reader);
        $state = $this->stateReader->read($this->reader, array_map(static fn (array $d): string => $d['kname'], $inventory));
        $match = DiskMatcher::match($plan, $inventory, $state);
        if ($match['ok'] !== true) {
            throw new InstallRefusal((string) $match['code'], (string) $match['detail']);
        }

        // Verified BEFORE the wipe so a bad artifact never costs a working disk.
        $t = $plan['template'];
        $stageDir = $this->stageRoot . '/mcxTemplate-stage-' . $plan['jobId'];
        $staged = $stageDir . '/' . ArchiveCheck::STAGE_FILE;
        if ($fetch !== null) {
            ArchiveCheck::makeStageDir($stageDir);
            try {
                $fetch($staged, $match);
                ArchiveCheck::verifyFile($staged, $t['sizeBytes'], $t['sha256']);
            } catch (\Throwable $e) {
                ArchiveCheck::unstage($stageDir);
                throw $e;
            }
        } elseif ($stage) {
            ArchiveCheck::stage($t['path'], $stageDir, $t['sizeBytes'], $t['sha256']);
        } else {
            ArchiveCheck::verifyFile($t['path'], $t['sizeBytes'], $t['sha256']);
        }
        try {
            $strip = ArchiveCheck::checkLayout($this->reader, $stage ? $staged : $t['path'], LayoutPlanner::templateNeeds((string) $plan['layout']));
            // The steps always name the staged copy, so a dry run shows the path an execute run extracts.
            $forSteps = $plan;
            $forSteps['template']['path'] = $staged;
            $forSteps['template']['stripComponents'] = $strip;
            $steps = LayoutPlanner::plan($forSteps, $match, $this->uuid);
        } catch (\Throwable $e) {
            if ($stage) {
                ArchiveCheck::unstage($stageDir);
            }
            throw $e;
        }
        return ['match' => $match, 'steps' => $steps, 'stage' => $stage ? $stageDir : null];
    }

    /**
     * Destructive phase. Needs wipeAuthorized in the plan, a staged template and a
     * usable secret file path. The secret path is checked before the first write;
     * the file itself is written right before the password step, once every
     * earlier step succeeded, so an aborted run leaves no password that was never set.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $prepared result of prepare($plan, true)
     * @param callable(string):void $say progress sink; receives step ids only
     */
    public function execute(array $plan, array $prepared, string $secretOut, callable $say): void
    {
        $this->perform($plan, $prepared, $secretOut, $say);
    }

    /**
     * Network-mode twin of execute(): no secret file. The generated root password is returned
     * in memory so the caller can deliver it to nodeCore; it is never written to disk here.
     *
     * @param array<string,mixed>   $plan
     * @param array<string,mixed>   $prepared result of prepare($plan, true, $fetch)
     * @param callable(string):void $say progress sink; receives step ids only
     */
    public function executeInMemory(array $plan, array $prepared, callable $say): string
    {
        return $this->perform($plan, $prepared, null, $say);
    }

    // perform is the shared body: $secretOut null keeps the password in memory and returns it.
    private function perform(array $plan, array $prepared, ?string $secretOut, callable $say): string
    {
        $stageDir = (string) ($prepared['stage'] ?? '');
        try {
            if (($plan['wipeAuthorized'] ?? false) !== true) {
                throw new InstallRefusal('wipeNotAuthorized', 'the plan does not carry wipeAuthorized=true');
            }
            if ($stageDir === '') {
                throw new InstallRefusal('templateNotStaged', 'execute needs a prepare() run that staged the template');
            }
            if ($secretOut !== null) {
                RootPassword::assertUsablePath($secretOut, [LayoutPlanner::TARGET, $this->targetRoot]);
            }
            $password = RootPassword::generate();
            $hash = RootPassword::hash($password);
            $this->runSteps($prepared, $hash, $secretOut, $password, $say);
            return $secretOut === null ? $password : '';
        } finally {
            ArchiveCheck::unstage($stageDir);
        }
    }

    /**
     * @param array<string,mixed>   $prepared
     * @param callable(string):void $say
     */
    private function runSteps(array $prepared, string $hash, ?string $secretOut, string $password, callable $say): void
    {
        $mounted = [];
        $mdCreated = false;
        try {
            foreach ($prepared['steps'] as $step) {
                $say($step['id']);
                if (($step['kind'] ?? '') === 'password' && $secretOut !== null) {
                    RootPassword::writeSecret($secretOut, $password);
                    $password = '';
                }
                $this->runStep($step, $prepared['match']['roles'], $hash);
                if ($step['argv'] !== [] && $step['argv'][0] === 'mount') {
                    $mounted[] = end($step['argv']);
                }
                if ($step['id'] === 'mdCreate') {
                    $mdCreated = true;
                }
            }
        } catch (\Throwable $error) {
            // Any failure, not only a refusal: a TypeError mid-run must not leave mounts and the array behind.
            $this->cleanup($mounted, $mdCreated);
            throw $error;
        }
    }

    /**
     * @param array<string,mixed>               $step
     * @param array<string,array<string,mixed>> $roles
     */
    private function runStep(array $step, array $roles, string $hash): void
    {
        $kind = $step['kind'] ?? '';
        if ($kind === 'writeFile') {
            TargetFile::write($this->targetRoot, $step['path'], $step['content']);
            return;
        }
        if ($kind === 'verify') {
            BootVerifier::verify($this->reader, $this->targetRoot, $step);
            return;
        }
        if ($step['destructive'] === true) {
            foreach ($step['roles'] as $role) {
                DeviceGuard::assertUnchanged($this->reader, $roles[$role]);
            }
        }
        $isPassword = $kind === 'password';
        $result = $this->writer->run($step['argv'], $isPassword ? 'root:' . $hash . "\n" : null, $step['env']);
        if ($result['code'] !== 0) {
            // The password step never echoes output: it could carry the hash.
            $detail = $isPassword ? '' : substr(trim($result['err']), 0, 300);
            throw new InstallRefusal('stepFailed', $step['id'] . ' exited ' . $result['code'] . ($detail !== '' ? ': ' . $detail : ''));
        }
        if ($kind === 'configure' && !self::hasSentinel($result['out'])) {
            throw new InstallRefusal('stepFailed', $step['id'] . ' exited 0 without the strict success line'
                . ' (the engine ignored --strict, or a task was skipped)');
        }
    }

    // hasSentinel: the strict success line must appear as a whole line of stdout.
    private static function hasSentinel(string $out): bool
    {
        foreach (explode("\n", $out) as $line) {
            if (rtrim($line, "\r") === self::CONFIGURE_SENTINEL) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort unwind after a failure: unmount in reverse, stop the boot array.
     *
     * @param array<int,string> $mounted
     */
    private function cleanup(array $mounted, bool $mdCreated): void
    {
        $t = LayoutPlanner::TARGET;
        foreach (array_reverse($mounted) as $mountPoint) {
            // Only the planned mount point or below it; "/mnt/target-other" is not below "/mnt/target".
            if ($mountPoint === $t || strncmp($mountPoint, $t . '/', strlen($t) + 1) === 0) {
                $this->writer->run(['umount', $mountPoint]);
            }
        }
        if ($mdCreated) {
            $this->writer->run(['mdadm', '--stop', '/dev/md/mcxboot']);
        }
    }
}
