<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\CurlTransport;
use Lib\Install\InstallRefusal;
use Lib\Install\NodeCoreClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NetworkFixtures.php';

// NodeCoreClientTest runs the client against a recorded transport fake: no socket, no clock (the
// sleeper is a recorder). It proves the retry classes, the strict response decoding and that the token
// stays out of every log line. CurlTransport's protocol lock is proven without any network too.
final class NodeCoreClientTest extends TestCase
{
    private FakeNodeCore $server;
    /** @var int[] */
    private array $sleeps = [];
    /** @var string[] */
    private array $log = [];

    protected function setUp(): void
    {
        $this->server = new FakeNodeCore('');
        $this->sleeps = [];
        $this->log = [];
    }

    private function client(string $base = 'https://nodecore.example.test/'): NodeCoreClient
    {
        return new NodeCoreClient($base, $this->server, function (int $s): void {
            $this->sleeps[] = $s;
        }, function (string $line): void {
            $this->log[] = $line;
        });
    }

    private function codeOf(callable $fn): string
    {
        try {
            $fn();
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    public function testNotArmedIsADecodedAnswerNotAnError(): void
    {
        $this->server->script('/boot/hello', [FakeNodeCore::err(404, 'not_armed')]);
        $a = $this->client()->hello(['mac' => 'aa:bb:cc:dd:ee:02']);
        $this->assertSame(404, $a['status']);
        $this->assertFalse($a['ok']);
        $this->assertSame('not_armed', $a['code']);
        $this->assertCount(1, $this->server->requests);
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function clientErrors(): array
    {
        return ['409 already booted' => [409, 'already_booted'], '401 bad token' => [401, 'bad_token'], '400 bad request' => [400, 'bad_request'],
            '409 bad sequence' => [409, 'bad_sequence']];
    }

    /** @dataProvider clientErrors */
    public function testA4xxAnswerIsReturnedAndNeverRetried(int $status, string $code): void
    {
        $this->server->script('/install/event', [FakeNodeCore::err($status, $code), FakeNodeCore::ok()]);
        $a = $this->client()->event('job-1', 'tok-0123456789abcdef', 1, 'planned', []);
        $this->assertSame($status, $a['status']);
        $this->assertSame($code, $a['code']);
        $this->assertCount(1, $this->server->requests, 'a 4xx is a decision: exactly one request');
        $this->assertSame([], $this->sleeps);
    }

    public function testA503IsRetriedWithBackoffThenSucceeds(): void
    {
        $this->server->script('/install/event', [['status' => 503, 'body' => '', 'error' => ''], FakeNodeCore::ok()]);
        $a = $this->client()->event('job-1', 'tok-0123456789abcdef', 1, 'planned', []);
        $this->assertTrue($a['ok']);
        $this->assertCount(2, $this->server->requests);
        $this->assertSame([1], $this->sleeps);
    }

    public function testNetworkErrorsAreRetriedAndGiveUpAfterFourAttempts(): void
    {
        $this->server->script('/install/result', [FakeNodeCore::down()]);
        $code = $this->codeOf(fn () => $this->client()->result(['jobId' => 'job-1']));
        $this->assertSame('nodeCoreUnreachable', $code);
        $this->assertCount(4, $this->server->requests);
        $this->assertSame([1, 2, 4], $this->sleeps, 'bounded backoff, no sleep after the last attempt');
    }

    public function testAnOversizedBodyReportedByTheTransportIsTreatedAsNoAnswer(): void
    {
        $this->server->script('/boot/hello', [['status' => 0, 'body' => '', 'error' => 'bodyTooLarge']]);
        $this->assertSame('nodeCoreUnreachable', $this->codeOf(fn () => $this->client()->hello([])));
    }

    public function testAnOversizedBodyThatReachesTheClientIsRefused(): void
    {
        $big = json_encode(['ok' => true, 'pad' => str_repeat('a', NodeCoreClient::MAX_BODY)]);
        $this->server->script('/boot/hello', [['status' => 200, 'body' => $big, 'error' => '']]);
        $this->assertSame('nodeCoreBadResponse', $this->codeOf(fn () => $this->client()->hello([])));
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function badBodies(): array
    {
        return [
            'not json' => [200, '<html>proxy error</html>'],
            'json list' => [200, '[1,2]'],
            'scalar' => [200, '"ok"'],
            'no ok flag' => [200, '{"plan":{}}'],
            'ok is a string' => [200, '{"ok":"true"}'],
            '200 but ok false' => [200, '{"ok":false,"error":{"code":"x"}}'],
            '404 but ok true' => [404, '{"ok":true}'],
            'error without code' => [409, '{"ok":false,"error":{}}'],
            'error code with spaces' => [409, '{"ok":false,"error":{"code":"a b"}}'],
            'too deep' => [200, str_repeat('[', 40) . str_repeat(']', 40)],
        ];
    }

    /** @dataProvider badBodies */
    public function testAnswersOutsideTheContractAreRefused(int $status, string $body): void
    {
        $this->server->script('/boot/hello', [['status' => $status, 'body' => $body, 'error' => '']]);
        $this->assertSame('nodeCoreBadResponse', $this->codeOf(fn () => $this->client()->hello([])));
        $this->assertCount(1, $this->server->requests, 'a malformed answer is not retried');
    }

    public function testARequestOverSixtyFourKiBIsRefusedBeforeItIsSent(): void
    {
        $code = $this->codeOf(fn () => $this->client()->hello(['disks' => str_repeat('a', NodeCoreClient::MAX_BODY)]));
        $this->assertSame('requestInvalid', $code);
        $this->assertSame([], $this->server->requests);
    }

    /** @return array<string,array{0:string}> */
    public static function badBases(): array
    {
        return ['plain http' => ['http://nodecore.example.test/'], 'with query' => ['https://nodecore.example.test/?k=v'], 'with userinfo' => ['https://u:p@nodecore.example.test/'],
            'file' => ['file:///etc/passwd'], 'empty' => ['']];
    }

    /** @dataProvider badBases */
    public function testTheBaseUrlMustBeAPlainHttpsUrl(string $base): void
    {
        $this->assertSame('planInvalid', $this->codeOf(fn () => $this->client($base)));
    }

    public function testTheTokenTravelsInTheBodyOnlyAndNeverReachesTheLog(): void
    {
        $token = 'tok-VERYSECRET-0123456789';
        $this->server->script('/install/event', [['status' => 503, 'body' => '', 'error' => ''], FakeNodeCore::ok()]);
        $this->client('https://nodecore.example.test/api/')->event('job-1', $token, 1, 'planned', ['x' => 1]);

        $request = $this->server->requests[0];
        $this->assertSame($token, $request['body']['token']);
        $this->assertSame('/api/install/event', $request['path']);
        $this->assertStringNotContainsString($token, implode("\n", $this->log));
        $this->assertNotEmpty($this->log);
        foreach ($this->log as $line) {
            $this->assertStringNotContainsString('://', $line, 'no URL in a log line');
        }
    }

    public function testAnEmptyDetailIsSentAsAJsonObject(): void
    {
        $this->client()->event('job-1', 'tok-0123456789abcdef', 1, 'wiped', []);
        $this->assertStringContainsString('"detail":{}', $this->server->requests[0]['raw']);
    }

    public function testCurlTransportRefusesAnyProtocolButHttpsWithoutTouchingTheNetwork(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('php-curl is not installed');
        }
        $http = new CurlTransport(1, 1, 1);
        // The protocol lock is checked before any connection is attempted (CURLE_UNSUPPORTED_PROTOCOL = 1).
        foreach (['http://192.0.2.1:9/x', 'ftp://192.0.2.1:9/x', 'file:///etc/hostname'] as $url) {
            $this->assertSame(['status' => 0, 'body' => '', 'error' => 'curl1'], $http->post($url, '{}'), $url);
        }
    }

    public function testCurlTransportDownloadNeverOverwritesAnExistingFile(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('php-curl is not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'mcxdl');
        file_put_contents($file, 'keep');
        try {
            $r = (new CurlTransport(1, 1, 1))->download('https://192.0.2.1:9/x', $file, 100);
            $this->assertSame('destExists', $r['error']);
            $this->assertSame('keep', file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function testCurlTransportDownloadOverHttpIsRefusedAndLeavesNoFile(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('php-curl is not installed');
        }
        $dir = sys_get_temp_dir() . '/mcx-dl-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $r = (new CurlTransport(1, 1, 1))->download('http://192.0.2.1:9/x', $dir . '/t.tar.gz', 100);
        $this->assertSame('curl1', $r['error']);
        $this->assertFileDoesNotExist($dir . '/t.tar.gz');
        rmdir($dir);
    }
}
