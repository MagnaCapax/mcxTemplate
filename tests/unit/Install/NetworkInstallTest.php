<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\NetworkInstall;
use Lib\Install\NodeCoreClient;
use Lib\Install\TemplateFetcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NetworkFixtures.php';

// NetworkInstallTest drives the whole network mode against a scripted nodeCore (FakeNodeCore) and the
// same fake machine InstallerTest uses. Nothing real runs: the writer only records, and no socket exists.
final class NetworkInstallTest extends TestCase
{
    private NetworkWorld $w;
    /** @var int fake clock, advanced by the fake sleep */
    private int $clock = 0;

    protected function setUp(): void
    {
        $this->w = new NetworkWorld();
        $this->clock = 0;
    }

    protected function tearDown(): void
    {
        $this->w->cleanup();
    }

    private function network(array $options = [], ?callable $freeSpace = null): NetworkInstall
    {
        $w = $this->w;
        $client = new NodeCoreClient(NetworkWorld::API, $w->server, static function (int $s): void {
        }, static function (string $line) use ($w): void {
            $w->log[] = $line;
        });
        $fetcher = new TemplateFetcher($w->server, $freeSpace ?? static fn (string $d) => 10 * 1073741824, static fn (): int => 10 * 1073741824);
        $options += ['isRoot' => true, 'maxAckRounds' => 3, 'dmi' => static fn (): array => ['uuid' => 'u-1', 'serial' => 's-1'],
            'sleep' => function (int $s): void {
                $this->clock += $s;
            }, 'now' => fn (): int => $this->clock];
        return new NetworkInstall($client, $fetcher, $w->installer(), $w->reader, $w->writer, $w->state(), static function (string $line) use ($w): void {
            $w->console[] = $line;
        }, $options);
    }

    private function rebootCalls(): int
    {
        return count(array_filter($this->w->writer->argvList(), static fn (array $a): bool => $a === ['reboot']));
    }

    /** @return string[] the argv[0] of every command the writer was asked to run */
    private function writerPrograms(): array
    {
        return array_map(static fn (array $a): string => $a[0], $this->w->writer->argvList());
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    public function testNotArmedExitsZeroHavingTouchedNothing(): void
    {
        $this->w->server->script('/boot/hello', [FakeNodeCore::err(404, 'not_armed')]);
        $code = $this->network()->run(InstallFixtures::MAC, true, true);

        $this->assertSame(0, $code);
        $this->assertSame(['/boot/hello'], array_column($this->w->server->requests, 'path'), 'only hello was sent');
        $this->assertSame([], $this->w->writer->calls(), 'no command was run');
        $this->assertSame([], $this->w->server->downloads, 'nothing was downloaded');
        $this->assertDirectoryDoesNotExist($this->w->stageDir());
        $this->assertStringContainsString('not armed', implode("\n", $this->w->console));
    }

    public function testAlreadyBooted409IsRefused(): void
    {
        $this->w->server->script('/boot/hello', [FakeNodeCore::err(409, 'already_booted')]);
        $this->assertSame('alreadyBooted', $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true)));
        $this->assertSame([], $this->w->writer->calls());
    }

    public function testA404WithAnotherCodeIsNotTreatedAsNotArmed(): void
    {
        $this->w->server->script('/boot/hello', [FakeNodeCore::err(404, 'no_route')]);
        $this->assertSame('helloRefused', $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true)));
    }

    public function testNotRootIsRefusedBeforeHello(): void
    {
        $this->assertSame('notRoot', $this->refusal(fn () => $this->network(['isRoot' => false])->run(InstallFixtures::MAC, true, true)));
        $this->assertSame([], $this->w->server->requests, 'hello would burn the armed job');
    }

    public function testHelloCarriesTheMachineDescription(): void
    {
        $this->network()->run(InstallFixtures::MAC, false, false);
        $hello = $this->w->server->to('/boot/hello')[0]['body'];
        $this->assertSame(InstallFixtures::MAC, $hello['mac']);
        $this->assertContains(InstallFixtures::MAC, $hello['macs']);
        $this->assertSame(['uuid' => 'u-1', 'serial' => 's-1'], $hello['dmi']);
        $this->assertSame('nvme0n1', $hello['disks'][0]['name']);
        $this->assertSame('NVME-ROOT-1', $hello['disks'][0]['serial']);
        $this->assertArrayNotHasKey('token', $hello);
    }

    public function testDryRunSaysHelloAndNothingElse(): void
    {
        $code = $this->network()->run(InstallFixtures::MAC, false, false);
        $this->assertSame(0, $code);
        $this->assertSame(['/boot/hello'], array_column($this->w->server->requests, 'path'));
        $this->assertSame([], $this->w->writer->calls());
        $this->assertSame([], $this->w->server->downloads);
    }

    public function testFullRunPostsOrderedEventsThenTheResultThenReboots(): void
    {
        $w = $this->w;
        $rebootsAtResult = -1;
        $w->server->script('/install/result', [function (array $body) use (&$rebootsAtResult): array {
            $rebootsAtResult = $this->rebootCalls();
            return FakeNodeCore::ok(['ack' => true]);
        }]);
        $code = $this->network()->run(InstallFixtures::MAC, true, true);

        $this->assertSame(0, $code);
        $this->assertSame(['planned', 'downloaded', 'wiped', 'partitioned', 'extracted', 'configured', 'verified', 'delivering'], $w->server->eventNames());
        $this->assertSame(range(1, 8), array_map(static fn (array $r): int => $r['body']['seq'], $w->server->to('/install/event')), 'seq strictly increasing from 1');
        $this->assertSame(0, $rebootsAtResult, 'the result was posted before any reboot');
        $this->assertSame(1, $this->rebootCalls());
        $this->assertSame(['reboot'], $w->writer->argvList()[count($w->writer->argvList()) - 1], 'the reboot is the last command');
        $this->assertDirectoryDoesNotExist($w->stageDir(), 'the downloaded template is removed after the run');

        $planned = $w->server->to('/install/event')[0]['body']['detail'];
        $this->assertSame(['serial' => 'NVME-ROOT-1', 'kname' => 'nvme0n1'], $planned['devices']['root']);
        $result = $w->server->to('/install/result')[0]['body'];
        $this->assertTrue($result['ok']);
        $this->assertSame('installed', $result['code']);
        $this->assertSame('Debian GNU/Linux 12 (bookworm)', $result['facts']['osRelease']);
        $this->assertSame('00000000-0000-4000-8000-000000000003', $result['facts']['rootUuid']);
    }

    public function testTheServerSuppliedTemplatePathIsIgnoredAndTheDownloadStaysInTheStageDirectory(): void
    {
        $this->network()->run(InstallFixtures::MAC, true, false);
        $this->assertCount(1, $this->w->server->downloads);
        $d = $this->w->server->downloads[0];
        $this->assertSame(NetworkWorld::URL, $d['url']);
        $this->assertSame($this->w->stageDir() . '/template.tar.gz', $d['dest']);
        $this->assertSame(strlen(NetworkWorld::TEMPLATE), $d['max'], 'the download is capped at the planned size');
    }

    public function testNoRebootWithoutTheFlag(): void
    {
        $this->network()->run(InstallFixtures::MAC, true, false);
        $this->assertSame(0, $this->rebootCalls());
        $this->assertStringContainsString('not rebooting', implode("\n", $this->w->console));
    }

    public function testNoRebootWhenNodeCoreNeverAnswers(): void
    {
        $this->w->server->script('/install/result', [FakeNodeCore::down()]);
        $code = $this->refusal(fn () => $this->network(['maxAckRounds' => 2])->run(InstallFixtures::MAC, true, true));
        $this->assertSame('resultNotAcknowledged', $code);
        $this->assertSame(0, $this->rebootCalls(), 'no ack, no reboot');
    }

    public function testNoRebootWhenTheResultIsAnsweredWithoutAnAck(): void
    {
        $this->w->server->script('/install/result', [FakeNodeCore::ok()]);
        $code = $this->refusal(fn () => $this->network(['maxAckRounds' => 2])->run(InstallFixtures::MAC, true, true));
        $this->assertSame('resultNotAcknowledged', $code);
        $this->assertSame(0, $this->rebootCalls());
    }

    public function testRejectedResultStopsWithoutRetryingAndWithoutReboot(): void
    {
        $this->w->server->script('/install/result', [FakeNodeCore::err(401, 'bad_token')]);
        $code = $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true));
        $this->assertSame('resultRejected', $code);
        $this->assertSame(0, $this->rebootCalls());
        // One real result attempt (the 401), then the failure report's own ok=false result.
        $bodies = array_column($this->w->server->to('/install/result'), 'body');
        $this->assertTrue($bodies[0]['ok']);
        $this->assertFalse($bodies[1]['ok']);
    }

    public function testAckThatArrivesLateStillReboots(): void
    {
        $this->w->server->script('/install/result', [FakeNodeCore::down(), FakeNodeCore::down(), FakeNodeCore::down(), FakeNodeCore::down(),
            FakeNodeCore::ok(['ack' => true])]);
        // The first round burns the client's four attempts; the second round gets the ack.
        $code = $this->network(['maxAckRounds' => 10])->run(InstallFixtures::MAC, true, true);
        $this->assertSame(0, $code);
        $this->assertSame(1, $this->rebootCalls());
    }

    public function testAfterTenMinutesWithoutAckItSaysSoOnceAndKeepsRetrying(): void
    {
        $this->w->server->script('/install/result', [FakeNodeCore::down()]);
        $code = $this->refusal(fn () => $this->network(['maxAckRounds' => 60])->run(InstallFixtures::MAC, true, true));
        $this->assertSame('resultNotAcknowledged', $code);
        $this->assertSame(1, substr_count(implode("\n", $this->w->console), 'no acknowledgement after 10 minutes'));
        $this->assertSame(0, $this->rebootCalls());
        $this->assertGreaterThanOrEqual(600, $this->clock);
    }

    public function testTemplateHashMismatchFailsBeforeAnyWipeAndReportsPhaseAndCode(): void
    {
        // Right size, wrong bytes: only the sha256 check can catch this.
        $this->w->server->templateBytes = str_repeat('x', strlen(NetworkWorld::TEMPLATE));
        $code = $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true));

        $this->assertSame('templateHashMismatch', $code);
        $this->assertSame([], $this->w->writer->calls(), 'nothing was wiped or written');
        $this->assertDirectoryDoesNotExist($this->w->stageDir());
        $events = $this->w->server->to('/install/event');
        $failed = end($events)['body'];
        $this->assertSame('failed', $failed['event']);
        $this->assertSame(['phase' => 'checkTemplate', 'code' => 'templateHashMismatch'], $failed['detail']);
        $this->assertSame(['planned', 'downloaded', 'failed'], $this->w->server->eventNames());
        $results = $this->w->server->to('/install/result');
        $this->assertFalse($results[0]['body']['ok']);
        $this->assertArrayNotHasKey('secret', $results[0]['body']);
    }

    public function testStepFailureReportsTheRunningPhase(): void
    {
        $this->w->writer->addResponse(['mkfs.ext4'], '', 1, "mke2fs: Device size reported to be zero.\n\tbad\x01");
        $code = $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true));
        $this->assertSame('stepFailed', $code);
        $events = $this->w->server->to('/install/event');
        $failed = end($events)['body'];
        // The operator reading jobs/status must see which step failed and why, cleaned to printable ASCII.
        $this->assertSame(['phase' => 'partition', 'code' => 'stepFailed',
            'error' => 'stepFailed: mkfsBoot exited 1: mke2fs: Device size reported to be zero. bad?'], $failed['detail']);
        $this->assertContains('wiped', $this->w->server->eventNames());
        $this->assertNotContains('extracted', $this->w->server->eventNames());
        $this->assertSame(0, $this->rebootCalls());
    }

    public function testNoSpaceRefusesBeforeDownloadingAndSaysSo(): void
    {
        $code = $this->refusal(fn () => $this->network([], static fn (string $d) => 1000)->run(InstallFixtures::MAC, true, true));
        $this->assertSame('templateNoSpace', $code);
        $this->assertSame([], $this->w->server->downloads);
        $this->assertSame([], $this->w->writer->calls());
    }

    public function testFailingToAnnouncePlannedAbortsBeforeTheDownload(): void
    {
        $this->w->server->script('/install/event', [FakeNodeCore::err(401, 'bad_token')]);
        $code = $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true));
        $this->assertSame('eventRejected', $code);
        $this->assertSame([], $this->w->server->downloads);
        $this->assertSame([], $this->w->writer->calls());
    }

    public function testALaterEventFailureOnlyWarnsAndTheInstallContinues(): void
    {
        $this->w->server->script('/install/event', [FakeNodeCore::ok(), FakeNodeCore::ok(), FakeNodeCore::err(409, 'bad_sequence'), FakeNodeCore::ok()]);
        $code = $this->network()->run(InstallFixtures::MAC, true, true);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('warning: eventRejected', implode("\n", $this->w->console));
        $this->assertSame(1, $this->rebootCalls());
    }

    public function testRejectedEventDoesNotConsumeASequenceNumber(): void
    {
        $this->w->server->script('/install/event', [FakeNodeCore::ok(), FakeNodeCore::ok(), FakeNodeCore::err(409, 'bad_sequence'), FakeNodeCore::ok()]);
        $this->network()->run(InstallFixtures::MAC, true, false);
        $seqs = array_map(static fn (array $r): int => $r['body']['seq'], $this->w->server->to('/install/event'));
        $this->assertSame([1, 2, 3, 3, 4, 5, 6, 7], $seqs, 'the rejected seq 3 is reused, so nodeCore sees no gap');
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function badPlans(): array
    {
        return [
            'mac of another machine' => [['mac' => '02:00:00:00:00:99'], 'planInvalid'],
            'no token' => [['token' => null], 'planInvalid'],
            'unknown key' => [['evil' => 'x'], 'planInvalid'],
            'http template url' => [['template' => ['name' => 'a', 'version' => 'b', 'sha256' => 'ab', 'sizeBytes' => 1, 'url' => 'http://x.example/t']], 'planInvalid'],
        ];
    }

    /**
     * @dataProvider badPlans
     * @param array<string,mixed> $over
     */
    public function testAnInvalidPlanIsRefusedBeforeAnythingHappens(array $over, string $expected): void
    {
        $raw = $this->w->rawPlan();
        foreach ($over as $key => $value) {
            if ($value === null) {
                unset($raw[$key]);
            } else {
                $raw[$key] = $value;
            }
        }
        $this->w->server->script('/boot/hello', [FakeNodeCore::ok(['plan' => $raw])]);
        $this->assertSame($expected, $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true)));
        $this->assertSame([], $this->w->writer->calls());
        $this->assertSame([], $this->w->server->downloads);
        $this->assertSame(['/boot/hello'], array_column($this->w->server->requests, 'path'));
    }

    public function testAPlanWithoutATemplateUrlIsRefused(): void
    {
        $raw = $this->w->rawPlan();
        unset($raw['template']['url']);
        $this->w->server->script('/boot/hello', [FakeNodeCore::ok(['plan' => $raw])]);
        $this->assertSame('planInvalid', $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true)));
        $this->assertSame([], $this->w->server->downloads);
    }

    public function testWipeNotAuthorizedInThePlanIsRefusedAndReported(): void
    {
        $this->w->server->script('/boot/hello', [FakeNodeCore::ok(['plan' => $this->w->rawPlan(['wipeAuthorized' => false])])]);
        $this->assertSame('wipeNotAuthorized', $this->refusal(fn () => $this->network()->run(InstallFixtures::MAC, true, true)));
        $this->assertSame([], $this->w->server->downloads);
        $this->assertSame([], $this->w->writer->calls());
    }

    public function testConfigureStepGetsTheNetworkEnvOnTopOfTheMinimalEnvironment(): void
    {
        $this->network()->run(InstallFixtures::MAC, true, false);
        $configure = null;
        foreach ($this->w->writer->calls() as $call) {
            if ($call['argv'][0] === 'chroot' && ($call['argv'][2] ?? '') === 'php') {
                $configure = $call;
            }
        }
        $this->assertNotNull($configure);
        $this->assertSame('198.51.100.2,198.51.100.3', $configure['env']['MCX_RESOLVERS']);
        $this->assertSame('1', $configure['env']['MCX_STRICT']);
        $this->assertSame('1', $configure['env']['MCX_REMOVE_ENGINE']);
        $this->assertSame('/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', $configure['env']['PATH'], 'the minimal environment is still there');
    }

    public function testNeitherTokenNorPasswordLeaksIntoConsoleLogEventsOrCommands(): void
    {
        $w = $this->w;
        $this->network()->run(InstallFixtures::MAC, true, true);

        $password = $w->server->to('/install/result')[0]['body']['secret']['rootPassword'];
        $this->assertSame(1, preg_match('/^[A-Za-z0-9]{20}$/D', $password));
        $token = $w->rawPlan()['token'];

        $visible = implode("\n", $w->console) . "\n" . implode("\n", $w->log);
        foreach ([$password, $token] as $secret) {
            $this->assertStringNotContainsString($secret, $visible, 'console or client log shows a secret');
            foreach ($w->writer->calls() as $call) {
                $this->assertStringNotContainsString($secret, implode("\0", $call['argv']) . implode("\0", $call['env']));
            }
        }
        foreach ($w->server->to('/install/event') as $event) {
            $this->assertStringNotContainsString($password, $event['raw'], 'a password in an event body');
            $this->assertArrayNotHasKey('secret', $event['body']);
        }
        $this->assertStringNotContainsString($token, $w->server->to('/boot/hello')[0]['raw'], 'hello is sent before a token exists');
        $this->assertStringContainsString($token, $w->server->to('/install/result')[0]['raw'], 'sanity: the token does travel in the body');
        $this->assertNotEmpty($w->log, 'the client logged its attempts');
        foreach ($w->log as $line) {
            $this->assertMatchesRegularExpression('#^nodeCore /(boot|install)/[a-z]+ attempt \\d+ status \\d+$#D', $line, 'a log line holds endpoint, attempt and status only');
        }
    }

    public function testWithInstallEnvTouchesOnlyTheConfigureStep(): void
    {
        $steps = [['id' => 'a', 'env' => ['K' => 'v']], ['id' => 'configure', 'kind' => 'configure', 'env' => ['PATH' => '/bin']]];
        $out = NetworkInstall::withInstallEnv($steps, ['host' => ['resolvers' => ['192.0.2.1']]]);
        $this->assertSame(['K' => 'v'], $out[0]['env']);
        $this->assertSame(['PATH' => '/bin', 'MCX_RESOLVERS' => '192.0.2.1', 'MCX_STRICT' => '1', 'MCX_REMOVE_ENGINE' => '1'], $out[1]['env']);
    }
}
