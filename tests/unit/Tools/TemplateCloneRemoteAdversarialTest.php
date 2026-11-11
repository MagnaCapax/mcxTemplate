<?php
declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

// Verifies templateCloneRemote.php rejects malformed inputs and missing toolchains.
final class TemplateCloneRemoteAdversarialTest extends TestCase
{
    use ToolTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanupToolPaths();
        parent::tearDown();
    }

    /**
     * An endpoint that ssh would read as an option is refused before ssh starts.
     */
    public function testEndpointThatLooksLikeAnSshOptionIsRefusedBeforeSsh(): void
    {
        $fakeDir = $this->createFakeBinaryDir();
        $marker = $fakeDir . '/ssh-called';
        $this->writeExecutable($fakeDir, 'ssh', "touch " . escapeshellarg($marker) . "\nexit 255");
        $env = ['PATH' => $fakeDir . PATH_SEPARATOR . (string) getenv('PATH')];
        $result = $this->runTool('tools/templateCloneRemote.php', ['-oProxyCommand=true', '-'], $env);
        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('Invalid <user@host> value.', $result['output']);
        $this->assertFileDoesNotExist($marker);
    }

    /**
     * Data providers run on a bare instance without setUp(), so every scenario gets the running test
     * instance as its first argument instead of capturing $this (PHPUnit 10+ also requires static providers).
     *
     * @dataProvider provideAdversarialCases
     * @param array<int,string> $args
     * @param array<string,string> $env
     */
    public function testAdversarialScenarios(string $description, array $args, array $env = [], ?callable $setup = null): void
    {
        // Ensure invalid arguments or environments surface readable failure diagnostics.
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
        // Hermetic: a scenario that did not choose its own PATH gets a fake ssh that always fails in front of
        // the real one, so a scenario that gets as far as connecting can never reach a real host or resolver.
        if (!isset($env['PATH'])) {
            $fakeDir = $this->createFakeBinaryDir();
            $this->writeExecutable($fakeDir, 'ssh', "cat >/dev/null\necho 'fake ssh: refused' >&2\nexit 255");
            $env['PATH'] = $fakeDir . PATH_SEPARATOR . (string) getenv('PATH');
        }
        $result = $this->runTool('tools/templateCloneRemote.php', $args, $env);
        $combined = trim($result['output']);
        $this->assertNotSame(0, $result['status'], $description . " expected non-zero exit. Output:\n" . $combined);
        $this->assertNotEmpty($combined, $description . ' produced no diagnostic output');
    }

    /**
     * @return array<string,array{string,array<int,string>,array<string,string>,callable|null}>
     */
    public static function provideAdversarialCases(): array
    {
        return [
            'no arguments' => ['no arguments', [] , [] , null],
            'only remote positional' => ['only remote positional', ['user@host'], [], null],
            'ssh option missing value' => ['ssh option missing value', ['--ssh-option'], [], null],
            'ssh option empty string' => ['ssh option empty string', ['--ssh-option', ''], [], null],
            'ssh option whitespace string' => ['ssh option whitespace string', ['--ssh-option', '   '], [], null],
            'unknown flag' => ['unknown flag', ['--unknown'], [], null],
            'template missing value' => ['template missing value', ['user@host', '-', '--template'], [], null],
            'template path not found rehydrate' => ['template path not found rehydrate', ['user@host', '-', '--rehydrate', '--template', '/not/here'], [], null],
            'template empty string rehydrate' => ['template empty string rehydrate', ['user@host', '-', '--rehydrate', '--template', ''], [], null],
            'embed force with missing template' => ['embed force with missing template', ['user@host', '-', '--rehydrate', '--embed-force', '--template', '/not/here'], [], null],
            'unknown flag with rehydrate' => ['unknown flag with rehydrate', ['user@host', '-', '--rehydrate', '--mystery'], [], null],
            'no embed template missing' => ['no embed template missing', ['user@host', '-', '--rehydrate', '--no-embed-into-rootfs', '--template'], [], null],
            'empty PATH removes ssh' => ['empty PATH removes ssh', ['user@host', '-'], ['PATH' => '/nonexistent'], null],
            // /bin may hold ssh on a real system, so the scenario builds a PATH that has the other tools but no ssh.
            'PATH without ssh but with other tools' => ['PATH without ssh but with other tools', ['user@host', '-'], [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->writeExecutable($dir, 'tar', 'exit 0');
                $t->writeExecutable($dir, 'pigz', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'rehydrate with PATH empty' => ['rehydrate with PATH empty', ['user@host', '-', '--rehydrate'], ['PATH' => '/nonexistent'], null],
            'existing output file without force' => ['existing output file without force', ['user@host', '__TEMP_FILE__'], [], static function (self $t, array $args, array $env = []): array {
                $tmp = tempnam(sys_get_temp_dir(), 'mcx-clone-');
                if ($tmp === false) {
                    throw new \RuntimeException('Unable to create temporary file.');
                }
                $t->registerTempPath($tmp);
                $args[1] = $tmp;
                return ['args' => $args];
            }],
            'php stream output with missing template' => ['php stream output with missing template', ['user@host', 'php://output', '--rehydrate', '--template', '/not/here'], [], null],
            'invalid remote host triggers ssh failure' => ['invalid remote host triggers ssh failure', ['not-a-real-host', '-'], [], null],
            'invalid remote host with stdout' => ['invalid remote host with stdout', ['not-a-real-host', 'php://stdout'], [], null],
            'rehydrate with template path empty string and env wiped' => ['rehydrate template empty with env wiped', ['user@host', '-', '--rehydrate', '--template', ''], ['PATH' => ''], null],
            'template realpath missing but embed force set' => ['template realpath missing embed force', ['user@host', '-', '--embed-force', '--template', '/not/here'], [], null],
            'rehydrate with template path whitespace' => ['rehydrate template whitespace', ['user@host', '-', '--rehydrate', '--template', '   '], [], null],
            'fake PATH missing ssh' => ['fake PATH missing ssh', ['user@host', '-'], [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->registerTempPath($dir);
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'fake ssh missing tar payload env' => ['fake ssh missing tar payload env', ['user@host', '-'], [], static function (self $t, array $args, array $env = []): array {
                $dir = $t->createFakeBinaryDir();
                $t->registerTempPath($dir);
                $t->writeExecutable($dir, 'ssh', "cat >/dev/null; echo 'fake ssh: MCX_FAKE_SSH_TAR not set' >&2; exit 5\n");
                $t->writeExecutable($dir, 'rsync', 'exit 0');
                $t->writeExecutable($dir, 'tar', 'exit 0');
                $t->writeExecutable($dir, 'pigz', 'exit 0');
                return ['args' => $args, 'env' => ['PATH' => $dir]];
            }],
            'template path points to file in rehydrate' => ['template path points to file in rehydrate', ['user@host', '-', '--rehydrate', '--template', '__FILE__'], [], static function (self $t, array $args, array $env = []): array {
                $file = tempnam(sys_get_temp_dir(), 'mcx-file-');
                if ($file === false) {
                    throw new \RuntimeException('Unable to create temp file.');
                }
                file_put_contents($file, 'engine');
                $t->registerTempPath($file);
                $args[4] = $file;
                return ['args' => $args];
            }],
            'template path control characters rehydrate' => ['template path control characters rehydrate', ['user@host', '-', '--rehydrate', '--template', "line\nline"], [], null],
        ];
    }
}
