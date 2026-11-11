<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Covers failure scenarios for templateAssemble.php by injecting malformed CLI
// arguments and synthetic environments (missing binaries, unreadable paths).
//
// Hermetic by construction: unless a scenario brings its own PATH, a fake ssh that records every call
// shadows the real one, and the test asserts it was never called. A scenario that is meant to be refused
// before any remote work therefore proves it, and no scenario can reach a real host.
final class TemplateAssembleAdversarialTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    /**
     * Data providers run on a bare instance without setUp(), so every scenario gets the running test
     * instance as its first argument instead of capturing $this (PHPUnit 10+ also requires static providers).
     *
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     * @param array<string,string> $env
     */
    public function testAdversarialScenarios(string $description, array $args, string $expected, array $env = [], ?callable $setup = null): void
    {
        // Each scenario should trigger a deterministic failure with an explanatory message.
        if ($setup !== null) {
            $adjusted = $setup($this, $args, $env);
            if (isset($adjusted['args'])) {
                $args = $adjusted['args'];
            } elseif (is_array($adjusted)) {
                $args = $adjusted;
            }
            if (isset($adjusted['env'])) {
                $env = array_merge($env, $adjusted['env']);
            }
        }

        // Scenarios that did not choose a PATH get the recording fake ssh in front of the real PATH.
        $sshCalls = null;
        if (!isset($env['PATH'])) {
            $fakeDir = $this->createFakeBinaryDir();
            $sshCalls = $fakeDir . '/ssh-calls';
            $this->writeExecutable($fakeDir, 'ssh', 'echo "$*" >> ' . escapeshellarg($sshCalls) . "\necho 'fake ssh: refused' >&2\nexit 255");
            $env['PATH'] = $fakeDir . PATH_SEPARATOR . (string) getenv('PATH');
        }

        $result = $this->runTool('tools/templateAssemble.php', $args, $env);
        $combined = trim($result['output']);

        $this->assertNotSame(0, $result['status'], $description . " expected non-zero exit. Output:\n" . $combined);
        $this->assertNotEmpty($combined, $description . ' produced no diagnostic output');
        $this->assertStringContainsString($expected, $combined, $description . ' returned unexpected diagnostic output: ' . $combined);
        if ($sshCalls !== null) {
            $this->assertFileDoesNotExist($sshCalls, $description . ' must be refused before any ssh or rsync work starts');
        }
    }

    // A stream-wrapper --output must be refused without creating a bogus relative directory named after it.
    public function testStreamWrapperOutputIsRefusedWithoutCreatingDirectories(): void
    {
        $fakeDir = $this->createFakeBinaryDir();
        $this->writeExecutable($fakeDir, 'ssh', 'exit 255');
        $stray = getcwd() . DIRECTORY_SEPARATOR . 'php:';
        $this->assertDirectoryDoesNotExist($stray, 'precondition: no stray php: directory before the run');

        $result = $this->runTool(
            'tools/templateAssemble.php',
            ['--source-host', 'bad-node', '--output', 'php://output'],
            ['PATH' => $fakeDir . PATH_SEPARATOR . (string) getenv('PATH')]
        );

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('Unable to create output directory.', $result['output']);
        $this->assertDirectoryDoesNotExist($stray, 'the stream wrapper must not become a directory in the working directory');
    }

    /**
     * @return array<string,array{string,array<int,string>,string,array<string,string>,callable|null}>
     */
    public static function provideAdversarialCases(): array
    {
        return [
            'no arguments' => ['no arguments', [], 'Missing required --source-host value.', [], null],
            'missing source-host value' => ['missing source-host value', ['--source-host'], 'Missing required --source-host value.', [], null],
            'empty source host string' => ['empty source host string', ['--source-host', ''], 'Missing required --source-host value.', [], null],
            'missing template value' => ['missing template value', ['--template'], 'Template path not found.', [], null],
            'template path not found' => ['template path not found', ['--template', '/definitely/not/here'], 'Template path not found.', [], null],
            'ssh option missing value' => ['ssh option missing value', ['--ssh-option'], 'Missing value for --ssh-option.', [], null],
            'ssh option empty string' => ['ssh option empty string', ['--ssh-option', ''], 'Missing value for --ssh-option.', [], null],
            'ssh option whitespace string' => ['ssh option whitespace string', ['--ssh-option', '   '], 'Empty --ssh-option value provided.', [], null],
            'unknown flag' => ['unknown flag', ['--unknown'], "Unknown argument '--unknown'.", [], null],
            'missing template while host provided' => ['missing template while host provided', ['--source-host', 'bad-node', '--template'], 'Template path not found.', [], null],
            'template empty string with host' => ['template empty string with host', ['--source-host', 'bad-node', '--template', ''], 'Template path not found.', [], null],
            // Below a regular file mkdir fails for every user, root included (a fixed /root path does not).
            'output directory not creatable' => ['output directory not creatable', ['--source-host', 'bad-node', '--output', '__OUTPUT__'], 'Unable to create output directory.', [], static function (self $t, array $args, array $env = []): array {
                $blocker = tempnam(sys_get_temp_dir(), 'mcx-blocker-');
                if ($blocker === false) {
                    throw new \RuntimeException('Unable to create temporary file.');
                }
                $t->registerTempPath($blocker);
                $args[3] = $blocker . '/sub/out.tar.gz';
                return ['args' => $args];
            }],
            // An existing directory the user cannot write to must be refused up front, not after the rsync stage.
            'output directory not writable' => ['output directory not writable', ['--source-host', 'bad-node', '--output', '__OUTPUT__'], 'Output directory is not writable.', [], static function (self $t, array $args, array $env = []): array {
                $t->skipWhenRoot();
                $dir = $t->mkTempDir('mcx-readonly-');
                chmod($dir, 0555);
                $args[3] = $dir . '/out.tar.gz';
                return ['args' => $args];
            }],
            'output php stream unsupported' => ['output php stream unsupported', ['--source-host', 'bad-node', '--output', 'php://output'], 'Unable to create output directory.', [], null],
            'rsync missing due to empty PATH' => ['rsync missing due to empty PATH', ['--source-host', 'bad-node'], "Required command 'rsync' not found in PATH.", ['PATH' => '/nonexistent'], null],
            // The real PATH may contain rsync under /bin, so the scenario builds a PATH that cannot.
            'rsync missing with other tools present' => ['rsync missing with other tools present', ['--source-host', 'bad-node'], "Required command 'rsync' not found in PATH.", [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->writeExecutable($dir, 'ssh', 'exit 0');
                $t->writeExecutable($dir, 'tar', 'exit 0');
                $t->writeExecutable($dir, 'pigz', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            // The staging directory is created below the temp dir; a temp dir below a regular file cannot exist.
            'tmpdir unwritable' => ['tmpdir unwritable', ['--source-host', 'bad-node'], 'Unable to create staging directory.', [], static function (self $t, array $args, array $env = []): array {
                $blocker = tempnam(sys_get_temp_dir(), 'mcx-blocker-');
                if ($blocker === false) {
                    throw new \RuntimeException('Unable to create temporary file.');
                }
                $t->registerTempPath($blocker);
                return ['args' => $args, 'env' => ['TMPDIR' => $blocker . '/tmp']];
            }],
            'missing ssh option after valid one' => ['missing ssh option after valid one', ['--source-host', 'bad-node', '--ssh-option', '-o StrictHostKeyChecking=yes', '--ssh-option'], 'Missing value for --ssh-option.', [], null],
            'template missing with embed disable' => ['template missing with embed disable', ['--source-host', 'bad-node', '--no-embed-into-rootfs', '--template'], 'Template path not found.', [], null],
            'existing output file without force' => ['existing output file without force', ['--source-host', 'bad-node', '--output', '__OUTPUT__'], 'Output file already exists:', [], static function (self $t, array $args, array $env = []): array {
                $tmp = tempnam(sys_get_temp_dir(), 'mcx-out-');
                if ($tmp === false) {
                    throw new \RuntimeException('Unable to create temporary file.');
                }
                $t->registerTempPath($tmp);
                $args[3] = $tmp;
                return ['args' => $args];
            }],
            'unknown flag with host present' => ['unknown flag with host present', ['--source-host', 'bad-node', '--mystery'], "Unknown argument '--mystery'.", [], null],
            'template missing with embed force' => ['template missing with embed force', ['--source-host', 'bad-node', '--embed-force', '--template', '/not/exist'], 'Template path not found.', [], null],
            'fake PATH missing tar' => ['fake PATH missing tar', ['--source-host', 'bad-node'], "Required command 'tar' not found in PATH.", [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->writeExecutable($dir, 'rsync', 'exit 0');
                $t->writeExecutable($dir, 'ssh', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            // The tool packs with pigz only (documented dependency), so a PATH without it stops at pigz.
            'fake PATH missing pigz' => ['fake PATH missing pigz', ['--source-host', 'bad-node'], "Required command 'pigz' not found in PATH.", [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->writeExecutable($dir, 'rsync', 'exit 0');
                $t->writeExecutable($dir, 'ssh', 'exit 0');
                $t->writeExecutable($dir, 'tar', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            // A NUL byte cannot be passed to a process at all (argv entries are C strings), so the former
            // "template path with null byte" scenario could not be built and was removed.
            // The remote stage is faked to succeed so the run reaches the local engine copy, which the real
            // rsync then rejects because the engine "directory" is a file.
            'template path points to file' => ['template path points to file', ['--source-host', 'bad-node', '--template', '__FILE__'], 'Rehydration copy failed', [], static function (self $t, array $args, array $env = []): array {
                $file = tempnam(sys_get_temp_dir(), 'mcx-file-');
                if ($file === false) {
                    throw new \RuntimeException('Unable to create temp file.');
                }
                file_put_contents($file, 'engine');
                $t->registerTempPath($file);
                $args[3] = $file;

                $realRsync = trim((string) shell_exec('command -v rsync 2>/dev/null'));
                if ($realRsync === '') {
                    $t->markTestSkipped('rsync not available in PATH');
                }
                $dir = $t->createFakeBinaryDir();
                $t->writeExecutable($dir, 'ssh', 'exit 255');
                // Remote copies (user@host:/ as the source) succeed without a network; local copies are real.
                $t->writeExecutable($dir, 'rsync', "case \"\$*\" in\n  *@*:/*) exit 0 ;;\nesac\nexec " . escapeshellarg($realRsync) . ' "$@"');
                $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
                return ['args' => $args, 'env' => ['PATH' => $path]];
            }],
            // Whitespace in the host is refused up front; the old expectation ("Unknown argument 'host'")
            // assumed the value would be word-split, which a single argv entry never is.
            'source host with whitespace' => ['source host with whitespace', ['--source-host', 'bad host'], 'Invalid --source-host value.', [], null],
            'source host with leading dash' => ['source host with leading dash', ['--source-host', '-oProxyCommand=x'], 'Invalid --source-host value.', [], null],
            'source user with leading dash' => ['source user with leading dash', ['--source-host', 'bad-node', '--source-user', '-oProxyCommand=x'], 'Invalid --source-user value.', [], null],
            'template path control characters' => ['template path control characters', ['--source-host', 'bad-node', '--template', "line1\nline2"], 'Template path not found.', [], null],
        ];
    }
}
