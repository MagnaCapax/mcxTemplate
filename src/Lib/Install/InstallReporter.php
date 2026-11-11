<?php
declare(strict_types=1);

namespace Lib\Install;

// InstallReporter is the install's voice towards nodeCore for ONE job: it numbers the
// events (seq starts at 1 and only advances when nodeCore accepted one, so there are no
// gaps), turns the executor's step ids into the contract's phase events, reports a
// failure as {phase, code}, and delivers the result until nodeCore acknowledges it.
//
// What may be sent: names, counts, device serials and kernel names. The token goes into
// request bodies only (by NodeCoreClient); the root password goes into the result body
// only and is never kept here, printed or put in an event.
final class InstallReporter
{
    public const ACK_WAIT_SECONDS = 600;
    // Step-id prefix => [phase, event posted once that phase is complete].
    private const WIPE = '/^(stopArray|(root|boot[01])(Wipefs|Zap|ZeroHead|ZeroTail|Reread))/D';
    private const PARTITION = '/^(partition|settle|wipe|mdCreate|mkfs|mkswap|mkdir|mount)/D';
    private const EXTRACT = '/^(extract|resolvConf|rootPassword|bind)/D';
    private const EVENT_OF = ['wipe' => 'wiped', 'partition' => 'partitioned', 'extract' => 'extracted',
        'configure' => 'configured', 'verify' => 'verified', 'teardown' => ''];

    private NodeCoreClient $client;
    private string $jobId;
    private string $token;
    private int $seq = 0;
    private string $phase = 'start';
    /** @var callable(string):void */
    private $out;
    /** @var callable(int):void */
    private $sleep;
    /** @var callable():int */
    private $now;
    private ?int $maxAckRounds;

    /**
     * @param callable(string):void   $out    console line sink (never receives secrets)
     * @param callable(int):void|null $sleep  seconds sleeper
     * @param callable():int|null     $now    unix clock
     * @param int|null                $maxAckRounds null in production (retry until acknowledged); tests bound it
     */
    public function __construct(NodeCoreClient $client, string $jobId, string $token, callable $out, ?callable $sleep = null,
        ?callable $now = null, ?int $maxAckRounds = null)
    {
        $this->client = $client;
        $this->jobId = $jobId;
        $this->token = $token;
        $this->out = $out;
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->now = $now ?? static fn (): int => time();
        $this->maxAckRounds = $maxAckRounds;
    }

    // phase is the name of the work in progress (used as the "phase" of a failure report).
    public function phase(): string
    {
        return $this->phase;
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }

    /**
     * Post one event. $required events (before the first write to a disk) abort the install when
     * nodeCore cannot be told; later ones only warn, because stopping half way through a wipe is worse.
     *
     * @param array<string,mixed> $detail
     */
    public function event(string $name, array $detail, bool $required): void
    {
        try {
            $answer = $this->client->event($this->jobId, $this->token, $this->seq + 1, $name, $detail);
            if ($answer['ok']) {
                $this->seq++;
                return;
            }
            $problem = new InstallRefusal('eventRejected', $name . ' answered ' . $answer['status'] . ' ' . $answer['code']);
        } catch (InstallRefusal $e) {
            $problem = $e;
        }
        if ($required) {
            throw $problem;
        }
        ($this->out)('warning: ' . $problem->getMessage());
    }

    /**
     * The executor calls this with each step id before running it. A change of phase means the
     * previous phase is complete, so its event is posted then.
     */
    public function stepStarted(string $id): void
    {
        $phase = self::phaseOf($id, $this->phase);
        if ($phase !== $this->phase) {
            $this->completePhase();
            $this->phase = $phase;
        }
        ($this->out)('step: ' . $id);
    }

    // finishSteps posts the event of the last phase when the executor ended without a teardown step.
    public function finishSteps(): void
    {
        $this->completePhase();
        $this->phase = 'teardown';
    }

    // completePhase posts the event of the phase that was running, once.
    private function completePhase(): void
    {
        $event = self::EVENT_OF[$this->phase] ?? '';
        if ($event !== '') {
            $this->event($event, [], false);
        }
    }

    // phaseOf maps a LayoutPlanner step id to the phase it belongs to; an unknown id stays in the current phase.
    public static function phaseOf(string $id, string $current): string
    {
        if (preg_match(self::WIPE, $id) === 1) {
            return 'wipe';
        }
        if (preg_match(self::PARTITION, $id) === 1) {
            return 'partition';
        }
        if (preg_match(self::EXTRACT, $id) === 1) {
            return 'extract';
        }
        if ($id === 'configure') {
            return 'configure';
        }
        if ($id === 'verifyBoot') {
            return 'verify';
        }
        if (preg_match('/^(unbind|umount|mdStop|sync)/D', $id) === 1) {
            return 'teardown';
        }
        return $current;
    }

    /**
     * Report a failure: the failed event {phase, code}, then the closing result with ok=false.
     * Best effort: the install is already failing and its own error must reach the console.
     */
    public function failed(string $code): void
    {
        $this->event('failed', ['phase' => $this->phase, 'code' => $code], false);
        try {
            $this->client->result(['jobId' => $this->jobId, 'token' => $this->token, 'ok' => false, 'code' => $code]);
        } catch (InstallRefusal $e) {
            ($this->out)('warning: ' . $e->getMessage());
        }
    }

    /**
     * Deliver the result with the root password and wait for nodeCore's acknowledgement.
     * Returns only after a 200 with ack=true. A 4xx answer (token spent, job not in an install
     * state) stops with a refusal. Without an answer it keeps retrying; after ten minutes it says
     * so once and slows down, but it never returns without the ack, so the caller never reboots without it.
     *
     * @param array<string,mixed> $facts
     */
    public function deliver(string $rootPassword, array $facts): void
    {
        $this->event('delivering', [], false);
        $this->phase = 'delivery';
        $deadline = ($this->now)() + self::ACK_WAIT_SECONDS;
        $warned = false;
        for ($round = 1;; $round++) {
            try {
                $answer = $this->client->result(['jobId' => $this->jobId, 'token' => $this->token, 'ok' => true, 'code' => 'installed',
                    'secret' => ['rootPassword' => $rootPassword], 'facts' => (object) $facts]);
                if ($answer['ok'] && ($answer['data']['ack'] ?? false) === true) {
                    return;
                }
                if (!$answer['ok']) {
                    throw new InstallRefusal('resultRejected', 'nodeCore answered ' . $answer['status'] . ' ' . $answer['code']);
                }
            } catch (InstallRefusal $e) {
                // Only "nodeCore did not answer" is retried; a contract answer that refuses is final.
                if ($e->refusalCode() !== 'nodeCoreUnreachable') {
                    throw $e;
                }
            }
            if ($this->maxAckRounds !== null && $round >= $this->maxAckRounds) {
                throw new InstallRefusal('resultNotAcknowledged', 'no acknowledgement after ' . $round . ' rounds');
            }
            $late = ($this->now)() >= $deadline;
            if ($late && !$warned) {
                ($this->out)('no acknowledgement after 10 minutes; staying in rescue and still retrying');
                $warned = true;
            }
            ($this->sleep)($late ? 60 : 15);
        }
    }
}
