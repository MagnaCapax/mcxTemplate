<?php
declare(strict_types=1);

namespace Lib\Install;

// NodeCoreClient speaks the install wire contract (hello, event, result) to nodeCore
// over an HttpTransport. Rules it enforces for every call:
//  - the base URL is a plain https URL given on the command line, never taken from the plan;
//  - request bodies are JSON of at most 64 KiB, response bodies are decoded strictly
//    (bounded depth, must be a JSON object) and an unexpected shape is a refusal;
//  - 5xx answers and network errors are retried a bounded number of times with backoff;
//    a 4xx answer is a decision, never retried, and is returned to the caller;
//  - the token and the root password travel only inside request bodies. The log sink
//    receives endpoint names, attempt numbers and status codes, nothing else (CWE-532).
final class NodeCoreClient
{
    public const MAX_BODY = 65536;
    public const MAX_ATTEMPTS = 4;
    private const BACKOFF = [1, 2, 4];

    private string $base;
    private HttpTransport $http;
    /** @var callable(int):void */
    private $sleep;
    /** @var callable(string):void|null */
    private $log;

    /**
     * @param callable(int):void|null    $sleep seconds sleeper (tests inject a recorder)
     * @param callable(string):void|null $log   receives one safe line per attempt
     */
    public function __construct(string $apiBase, HttpTransport $http, ?callable $sleep = null, ?callable $log = null)
    {
        $this->base = rtrim(PlanValidator::httpsUrl($apiBase, '--api'), '/');
        $this->http = $http;
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->log = $log;
    }

    /**
     * @param array<string,mixed> $payload mac, macs, dmi, disks
     * @return array{status:int,ok:bool,code:string,data:array<string,mixed>}
     */
    public function hello(array $payload): array
    {
        return $this->post('/boot/hello', $payload);
    }

    /**
     * @param array<string,mixed> $detail small JSON object; never a password, token or key
     * @return array{status:int,ok:bool,code:string,data:array<string,mixed>}
     */
    public function event(string $jobId, string $token, int $seq, string $event, array $detail): array
    {
        return $this->post('/install/event', ['jobId' => $jobId, 'token' => $token, 'seq' => $seq, 'event' => $event,
            'detail' => (object) $detail]);
    }

    /**
     * @param array<string,mixed> $body jobId, token, ok, code, secret, facts
     * @return array{status:int,ok:bool,code:string,data:array<string,mixed>}
     */
    public function result(array $body): array
    {
        return $this->post('/install/result', $body);
    }

    /**
     * One call with bounded retries. Returns the decoded answer for any 2xx or 4xx status;
     * throws InstallRefusal when nodeCore stays unreachable or answers outside the contract.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int,ok:bool,code:string,data:array<string,mixed>}
     */
    private function post(string $endpoint, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_BODY) {
            throw new InstallRefusal('requestInvalid', 'request body is not encodable JSON of at most 64 KiB');
        }
        $url = $this->base . $endpoint;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $answer = $this->http->post($url, $json);
            $this->note($endpoint, $attempt, $answer['status'], $answer['error']);
            if ($answer['status'] > 0 && $answer['status'] < 500) {
                return $this->decode($answer['status'], $answer['body']);
            }
            // 5xx and "no answer" (network error, timeout, oversized body) are the retryable class.
            if ($attempt < self::MAX_ATTEMPTS) {
                ($this->sleep)(self::BACKOFF[$attempt - 1]);
            }
        }
        throw new InstallRefusal('nodeCoreUnreachable', $endpoint . ' failed after ' . self::MAX_ATTEMPTS . ' attempts');
    }

    /**
     * Strict decode of a 2xx or 4xx answer.
     *
     * @return array{status:int,ok:bool,code:string,data:array<string,mixed>}
     */
    private function decode(int $status, string $body): array
    {
        if (strlen($body) > self::MAX_BODY) {
            throw new InstallRefusal('nodeCoreBadResponse', 'response is larger than 64 KiB');
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InstallRefusal('nodeCoreBadResponse', 'response is not valid JSON');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || !is_bool($data['ok'] ?? null)) {
            throw new InstallRefusal('nodeCoreBadResponse', 'response is not a contract object');
        }
        $code = '';
        if ($data['ok'] === false) {
            $err = $data['error'] ?? null;
            $code = is_array($err) && is_string($err['code'] ?? null) ? $err['code'] : '';
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $code) !== 1) {
                throw new InstallRefusal('nodeCoreBadResponse', 'error answer has no usable code');
            }
        }
        // A 2xx answer must say ok=true, a 4xx answer must say ok=false: anything else is outside the contract.
        if (($status < 300) !== $data['ok']) {
            throw new InstallRefusal('nodeCoreBadResponse', 'status and ok flag disagree');
        }
        return ['status' => $status, 'ok' => $data['ok'], 'code' => $code, 'data' => $data];
    }

    // note logs one attempt: endpoint path, attempt number, status or error code. No URL, query, body or token.
    private function note(string $endpoint, int $attempt, int $status, string $error): void
    {
        if ($this->log !== null) {
            ($this->log)(sprintf('nodeCore %s attempt %d status %d%s', $endpoint, $attempt, $status, $error !== '' ? ' error ' . $error : ''));
        }
    }
}
