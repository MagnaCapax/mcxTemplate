<?php
declare(strict_types=1);

namespace Lib\Install;

// NetworkInstall is the unattended install driven by nodeCore (installNode.php --api --mac):
//   hello -> plan (UNTRUSTED, through PlanValidator) -> disk match -> template download ->
//   the same prepare/execute path as the local --plan mode -> events -> result -> reboot.
// It touches nothing before nodeCore answers hello with a plan, so an unrequested PXE boot
// (404 not_armed) exits 0 having changed nothing. The reboot happens only with $reboot and
// only after nodeCore acknowledged the result. See docs/adr/0002-*.
final class NetworkInstall
{
    // The plan's template.path is never taken from nodeCore: the archive lands in the private staging directory.
    public const PLACEHOLDER_PATH = '/run/mcxTemplate-network/template.tar.gz';

    private NodeCoreClient $client;
    private TemplateFetcher $fetcher;
    private Installer $installer;
    private CommandRunner $reader;
    private CommandRunner $writer;
    private SystemStateReader $state;
    /** @var callable(string):void */
    private $out;
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param callable(string):void $out console line sink
     * @param array{isRoot?:bool,sleep?:callable,now?:callable,maxAckRounds?:?int,dmi?:callable} $options test seams;
     *        production passes isRoot only
     */
    public function __construct(NodeCoreClient $client, TemplateFetcher $fetcher, Installer $installer, CommandRunner $reader,
        CommandRunner $writer, SystemStateReader $state, callable $out, array $options = [])
    {
        $this->client = $client;
        $this->fetcher = $fetcher;
        $this->installer = $installer;
        $this->reader = $reader;
        $this->writer = $writer;
        $this->state = $state;
        $this->out = $out;
        $this->options = $options;
    }

    /**
     * @return int process exit code: 0 done or not armed; refusals are thrown as InstallRefusal
     */
    public function run(string $mac, bool $execute, bool $reboot): int
    {
        $mac = PlanValidator::macAddress($mac, '--mac');
        if ($execute && ($this->options['isRoot'] ?? false) !== true) {
            throw new InstallRefusal('notRoot', '--execute needs root');
        }

        $hello = $this->client->hello($this->helloPayload($mac));
        if ($hello['status'] === 404 && $hello['code'] === 'not_armed') {
            ($this->out)('not armed: nodeCore has no armed install job for this machine; nothing was touched');
            return 0;
        }
        if (!$hello['ok']) {
            throw new InstallRefusal($hello['code'] === 'already_booted' ? 'alreadyBooted' : 'helloRefused',
                'nodeCore answered ' . $hello['status'] . ' ' . $hello['code']);
        }
        $plan = $this->validatePlan($hello['data']['plan'] ?? null, $mac);
        ($this->out)(sprintf('plan received: job %s, template %s %s', $plan['jobId'], $plan['template']['name'], $plan['template']['version']));
        if (!$execute) {
            ($this->out)('Dry run: nothing was downloaded, matched or changed. (The hello above was real.)');
            return 0;
        }

        $reporter = new InstallReporter($this->client, $plan['jobId'], $plan['token'], $this->out, $this->options['sleep'] ?? null,
            $this->options['now'] ?? null, $this->options['maxAckRounds'] ?? null);
        try {
            [$password, $facts] = $this->install($plan, $reporter);
            $reporter->deliver($password, $facts);
            $password = '';
        } catch (\Throwable $error) {
            $code = $error instanceof InstallRefusal ? $error->refusalCode() : 'internalError';
            // Only a failed step sends its message (step id, exit code, stderr): other refusals can name disk serials,
            // which nodeCore keeps out of stored events, and an internal error stays the class name on the console.
            $reporter->failed($code, $code === 'stepFailed' ? $error->getMessage() : '');
            throw $error;
        }
        if ($reboot) {
            ($this->out)('result acknowledged; rebooting');
            $this->writer->run(['reboot']);
        } else {
            ($this->out)('result acknowledged; not rebooting (no --reboot)');
        }
        return 0;
    }

    /**
     * install runs prepare (match, download, verify) and execute, reporting phases.
     *
     * @return array{0:string,1:array<string,mixed>} the generated root password (memory only) and the result facts
     */
    private function install(array $plan, InstallReporter $reporter): array
    {
        if ($plan['wipeAuthorized'] !== true) {
            throw new InstallRefusal('wipeNotAuthorized', 'the plan does not carry wipeAuthorized=true');
        }
        $reporter->setPhase('prepare');
        $prepared = $this->installer->prepare($plan, true, function (string $staged, array $match) use ($plan, $reporter): void {
            $t = $plan['template'];
            $reporter->event('planned', ['layout' => $plan['layout'], 'template' => ['name' => $t['name'], 'version' => $t['version']],
                'devices' => self::deviceMap($match)], true);
            $reporter->setPhase('download');
            $this->fetcher->fetch($t['url'], $staged, $t['sizeBytes']);
            $reporter->event('downloaded', ['name' => $t['name'], 'version' => $t['version'], 'sizeBytes' => $t['sizeBytes']], true);
            // The size and sha256 check of the received file follows in prepare().
            $reporter->setPhase('checkTemplate');
        });
        try {
            $prepared['steps'] = self::withInstallEnv($prepared['steps'], $plan);
            $facts = $this->facts($plan, $prepared, $this->osRelease($prepared['stage']));
            ($this->out)(PlanPrinter::render($plan, $prepared, true));
        } catch (\Throwable $e) {
            // prepare() left a staged template behind; execute() would have removed it.
            ArchiveCheck::unstage((string) $prepared['stage']);
            throw $e;
        }

        $password = $this->installer->executeInMemory($plan, $prepared, static function (string $id) use ($reporter): void {
            $reporter->stepStarted($id);
        });
        $reporter->finishSteps();
        return [$password, $facts];
    }

    /**
     * The plan is untrusted: every field goes through PlanValidator. Beyond the schema this checks what
     * only the network mode needs: a token to report with, a template URL, and the MAC we said hello with.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function validatePlan($raw, string $mac): array
    {
        if (!is_array($raw)) {
            throw new InstallRefusal('nodeCoreBadResponse', 'hello answered without a plan');
        }
        if (isset($raw['template']) && is_array($raw['template'])) {
            $raw['template']['path'] = self::PLACEHOLDER_PATH;
        }
        $plan = PlanValidator::validate($raw);
        if ($plan['token'] === '') {
            throw new InstallRefusal('planInvalid', 'the plan carries no token');
        }
        if ($plan['template']['url'] === '') {
            throw new InstallRefusal('planInvalid', 'the plan carries no template url');
        }
        if ($plan['mac'] !== $mac) {
            throw new InstallRefusal('planInvalid', 'the plan is for a different MAC address');
        }
        return $plan;
    }

    /**
     * Hello body: what this machine is, so nodeCore can match it to the armed job.
     *
     * @return array<string,mixed>
     */
    private function helloPayload(string $mac): array
    {
        $inventory = DiskInventory::enumerate($this->reader);
        $state = $this->state->read($this->reader, array_map(static fn (array $d): string => $d['kname'], $inventory));
        $disks = [];
        foreach ($inventory as $d) {
            $disks[] = ['name' => $d['kname'], 'serial' => $d['serial'], 'wwn' => $d['wwn'], 'sizeBytes' => $d['sizeBytes'],
                'transport' => $d['tran'], 'model' => $d['model'], 'idPath' => $d['idPath'], 'removable' => $d['removable']];
        }
        $dmi = isset($this->options['dmi']) ? ($this->options['dmi'])() : self::readDmi();
        return ['mac' => $mac, 'macs' => array_values($state['nicMacs']), 'dmi' => (object) $dmi, 'disks' => $disks];
    }

    // readDmi reads the board's UUID and serial; they are root-only files, so an unprivileged run sends empty strings.
    private static function readDmi(): array
    {
        $read = static function (string $file): string {
            $v = @file_get_contents($file);
            return is_string($v) ? substr(trim($v), 0, 128) : '';
        };
        return ['uuid' => $read('/sys/class/dmi/id/product_uuid'), 'serial' => $read('/sys/class/dmi/id/product_serial')];
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,array{serial:string,kname:string}>
     */
    private static function deviceMap(array $match): array
    {
        $map = [];
        foreach ($match['roles'] as $role => $disk) {
            $map[$role] = ['serial' => $disk['serial'], 'kname' => $disk['kname']];
        }
        return $map;
    }

    /**
     * Network installs hand the chroot configure step three switches the local mode does not set:
     * the resolvers (task 23), the strict flag the tasks cannot see otherwise, and the removal of the
     * engine from the installed system (last task). The planner and the local mode stay untouched.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,mixed>            $plan
     * @return array<int,array<string,mixed>>
     */
    public static function withInstallEnv(array $steps, array $plan): array
    {
        foreach ($steps as $i => $step) {
            if (($step['kind'] ?? '') === 'configure') {
                $steps[$i]['env'] += ['MCX_RESOLVERS' => implode(',', $plan['host']['resolvers']), 'MCX_STRICT' => '1', 'MCX_REMOVE_ENGINE' => '1'];
            }
        }
        return $steps;
    }

    // osRelease reads PRETTY_NAME from the staged archive (read-only); '' when it cannot be read. os-release(5):
    // /etc/os-release, else /usr/lib/os-release. On Debian and Ubuntu the first is a relative symlink, and tar -xO of a
    // symlink member prints nothing, so the target is read as the fallback (as ReleaseBuilder::manifest does).
    private function osRelease(?string $stageDir): string
    {
        if ($stageDir === null) {
            return '';
        }
        $archive = $stageDir . '/' . ArchiveCheck::STAGE_FILE;
        $text = ArchiveCheck::readMember($this->reader, $archive, 'rootfs/etc/os-release')
            ?? ArchiveCheck::readMember($this->reader, $archive, 'rootfs/usr/lib/os-release');
        if ($text !== null && preg_match('/^PRETTY_NAME="?([^"\n]{1,120})"?$/m', $text, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * Facts for the result: what was installed where. No secrets.
     *
     * @param array<string,mixed> $prepared
     * @return array<string,mixed>
     */
    private function facts(array $plan, array $prepared, string $osRelease): array
    {
        $rootUuid = '';
        foreach ($prepared['steps'] as $step) {
            if ($step['id'] === 'mkfsRoot') {
                $at = array_search('-U', $step['argv'], true);
                $rootUuid = $at === false ? '' : (string) $step['argv'][$at + 1];
            }
        }
        return ['osRelease' => $osRelease, 'rootUuid' => $rootUuid, 'diskMap' => (object) self::deviceMap($prepared['match']),
            'template' => ['name' => $plan['template']['name'], 'version' => $plan['template']['version']]];
    }
}
