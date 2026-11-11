<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

// InstallNodeNetworkCliTest covers the network flags of the entry point. Every case ends before any
// connection: a usage error, a refused input, or the not-root refusal (posix_geteuid is disabled in the
// child, so it is not root even when the suite is, and hello is never sent).
final class InstallNodeNetworkCliTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-netcli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/ini', 0700, true);
        file_put_contents($this->dir . '/ini/mcx.ini', "disable_functions=posix_geteuid\n");
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/ini/mcx.ini');
        rmdir($this->dir . '/ini');
        rmdir($this->dir);
    }

    /** @return array{0:int,1:string,2:string} */
    private function cli(array $args): array
    {
        $argv = array_merge([PHP_BINARY, realpath(__DIR__ . '/../../../installNode.php')], $args);
        $env = array_merge(getenv(), ['PHP_INI_SCAN_DIR' => ':' . $this->dir . '/ini']);
        $p = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** @return array<string,array{0:string[]}> */
    public static function usageErrors(): array
    {
        $api = '--api=https://192.0.2.1:9/';
        $mac = '--mac=aa:bb:cc:dd:ee:02';
        return [
            'api without mac' => [[$api]],
            'mac without api' => [[$mac]],
            'plan and api together' => [['--plan=/x.json', $api, $mac]],
            'secret-out in network mode' => [[$api, $mac, '--secret-out=/tmp/s']],
            'reboot without execute' => [[$api, $mac, '--reboot']],
            'reboot in plan mode' => [['--plan=/x.json', '--reboot']],
            'mac in plan mode' => [['--plan=/x.json', $mac]],
        ];
    }

    /**
     * @dataProvider usageErrors
     * @param string[] $args
     */
    public function testWrongFlagCombinationsAreUsageErrors(array $args): void
    {
        [$code, , $err] = $this->cli($args);
        $this->assertSame(64, $code);
        $this->assertStringContainsString('Usage:', $err);
        $this->assertStringContainsString('--api=https://host/ --mac=', $err);
    }

    public function testAMalformedMacIsRefusedBeforeAnythingElse(): void
    {
        [$code, , $err] = $this->cli(['--api=https://192.0.2.1:9/', '--mac=not-a-mac', '--execute']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED (planInvalid|curlMissing)/m', $err);
    }

    public function testAnHttpApiUrlIsRefusedBeforeAnyConnection(): void
    {
        [$code, , $err] = $this->cli(['--api=http://192.0.2.1:9/', '--mac=aa:bb:cc:dd:ee:02', '--execute']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED (planInvalid|curlMissing)/m', $err);
    }

    public function testExecuteWithoutRootIsRefusedBeforeHello(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('php-curl is not installed; the CLI refuses with curlMissing first');
        }
        [$code, $out, $err] = $this->cli(['--api=https://192.0.2.1:9/', '--mac=aa:bb:cc:dd:ee:02', '--execute', '--reboot']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED notRoot:/m', $err);
        $this->assertStringNotContainsString('nodeCore', $err, 'no request was attempted');
        $this->assertSame('', $out);
    }

    public function testHelpMentionsBothModes(): void
    {
        [$code, $out] = $this->cli(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--plan=FILE', $out);
        $this->assertStringContainsString('--api=https://host/ --mac=', $out);
    }
}
