<?php
declare(strict_types=1);

namespace Lib\Install;

// CommandRunner is the only way the install core touches the system. Commands are
// argv arrays (never shell strings), so plan values cannot be interpreted by a
// shell (CWE-78). Tests use RecordingRunner; production uses ProcessRunner.
interface CommandRunner
{
    /**
     * Run one command.
     *
     * @param array<int,string>    $argv  program followed by its arguments
     * @param string|null          $stdin data fed to stdin (used for secrets so they stay out of argv)
     * @param array<string,string> $env   when non-empty, the complete environment of this command (nothing is inherited);
     *                                    empty means the command inherits the caller's environment
     * @return array{code:int,out:string,err:string}
     */
    public function run(array $argv, ?string $stdin = null, array $env = []): array;
}
