<?php
declare(strict_types=1);

namespace Lib\Install;

// ProcessRunner runs commands with proc_open and an argv array, so no shell is
// involved. Output is read with stream_select so a chatty stderr cannot block
// stdout (no pipe deadlock).
final class ProcessRunner implements CommandRunner
{
    public function run(array $argv, ?string $stdin = null, array $env = []): array
    {
        if ($argv === [] || !is_string($argv[0]) || $argv[0] === '') {
            throw new InstallRefusal('commandInvalid', 'empty command');
        }
        foreach ($argv as $arg) {
            // NUL bytes would truncate an argument silently.
            if (!is_string($arg) || strpos($arg, "\0") !== false) {
                throw new InstallRefusal('commandInvalid', 'argument is not a clean string');
            }
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // A non-empty $env is the child's WHOLE environment: nothing from the rescue shell is inherited
        // (a stray MCX_DRY_RUN there must not reach the chroot). An empty $env inherits, as before.
        $environment = $env === [] ? null : $env;
        $process = @proc_open($argv, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'command could not be started'];
        }

        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                $key = $stream === $pipes[1] ? 1 : 2;
                if ($chunk !== false && $chunk !== '') {
                    if ($key === 1) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
                if (feof($stream)) {
                    unset($open[$key]);
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
