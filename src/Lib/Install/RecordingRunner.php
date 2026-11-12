<?php
declare(strict_types=1);

namespace Lib\Install;

// RecordingRunner is the fake used by tests and by the dry-run path: it records
// every command and runs nothing. Scripted responses are matched by argv prefix.
// Stdin is recorded only as a byte count in calls(); the real bytes are kept
// apart (stdinOf) so a failing test never prints a secret.
final class RecordingRunner implements CommandRunner
{
    /** @var array<int,array{argv:array<int,string>,stdinBytes:int,env:array<string,string>}> */
    private array $calls = [];
    /** @var array<int,string|null> */
    private array $stdins = [];
    /** @var array<int,array{prefix:array<int,string>,result:array{code:int,out:string,err:string}}> */
    private array $responses;

    /**
     * @param array<int,array{prefix:array<int,string>,result:array{code:int,out:string,err:string}}> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function run(array $argv, ?string $stdin = null, array $env = []): array
    {
        $this->calls[] = ['argv' => $argv, 'stdinBytes' => $stdin === null ? 0 : strlen($stdin), 'env' => $env];
        $this->stdins[] = $stdin;

        foreach ($this->responses as $response) {
            $prefix = $response['prefix'];
            if (array_slice($argv, 0, count($prefix)) === $prefix) {
                return $response['result'];
            }
        }
        return ['code' => 0, 'out' => '', 'err' => ''];
    }

    /** @return array<int,array{argv:array<int,string>,stdinBytes:int,env:array<string,string>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return array<int,array<int,string>> just the argv lists, in order */
    public function argvList(): array
    {
        return array_map(static fn (array $call): array => $call['argv'], $this->calls);
    }

    // stdinOf returns the stdin given to call $index (tests only).
    public function stdinOf(int $index): ?string
    {
        return $this->stdins[$index] ?? null;
    }

    // addResponse appends a scripted answer (stdout, exit code, stderr) for commands starting with $prefix.
    public function addResponse(array $prefix, string $out, int $code = 0, string $err = ''): void
    {
        $this->responses[] = ['prefix' => $prefix, 'result' => ['code' => $code, 'out' => $out, 'err' => $err]];
    }
}
