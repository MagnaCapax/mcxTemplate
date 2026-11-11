<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Install\InstallRefusal;
use Lib\Release\BootstrapRenderer;
use PHPUnit\Framework\TestCase;

// BootstrapRendererTest: the three constants end up inside single quotes of a root-run script, so
// anything that could break out of the quotes must be refused, and the real bootstrap stays glue.
final class BootstrapRendererTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const URL = 'https://files.example.test/templates/mcxInstallEngine-0123456789ab.tar.gz';
    private const API = 'https://nodecore.example.test/';

    private function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bootstrap/mcxInstall.sh');
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (InstallRefusal $e) {
            return $e->refusalCode();
        }
        return '';
    }

    public function testTheRealBootstrapIsFilledAndNoPlaceholderIsLeft(): void
    {
        $out = BootstrapRenderer::render($this->template(), self::URL, self::SHA, self::API);
        $this->assertStringContainsString("ENGINE_URL='" . self::URL . "'", $out);
        $this->assertStringContainsString("ENGINE_SHA256='" . self::SHA . "'", $out);
        $this->assertStringContainsString("API_URL='" . self::API . "'", $out);
        $this->assertSame(0, preg_match('/@[A-Z_]+@/', $out));
    }

    public function testTheBootstrapStaysShortGlue(): void
    {
        $text = $this->template();
        $this->assertLessThanOrEqual(20, substr_count($text, "\n"), 'at most 20 lines');
        $this->assertStringStartsWith("#!/bin/sh\n", $text);
        $this->assertStringContainsString('exec php /run/mcxInstall/installNode.php --api="$API_URL" --mac="$mcxMac" --execute --reboot', $text);
        $this->assertStringContainsString('sha256sum -c', $text);
        $this->assertStringContainsString('set -eu', $text);
    }

    public function testTheFilledBootstrapPassesTheShellSyntaxCheck(): void
    {
        $dir = sys_get_temp_dir() . '/mcx-bs-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/mcxInstall.sh';
        file_put_contents($file, BootstrapRenderer::render($this->template(), self::URL, self::SHA, self::API));
        $p = proc_open(['sh', '-n', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = (string) stream_get_contents($pipes[2]);
        $rc = proc_close($p);
        unlink($file);
        rmdir($dir);
        $this->assertSame(0, $rc, $err);
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string}> url, sha, api, expected code */
    public static function badValues(): array
    {
        return [
            'quote in the engine url' => ["https://a.example/x'; rm -rf /; '", self::SHA, self::API, 'planInvalid'],
            'space in the api url' => [self::URL, self::SHA, 'https://a.example/x y', 'planInvalid'],
            'dollar in the api url' => [self::URL, self::SHA, 'https://a.example/$HOME', 'planInvalid'],
            'newline in the engine url' => ["https://a.example/x\nfoo", self::SHA, self::API, 'planInvalid'],
            'http engine url' => ['http://a.example/e.tar.gz', self::SHA, self::API, 'planInvalid'],
            'upper case sha' => [self::URL, strtoupper(self::SHA), self::API, 'bootstrapValueInvalid'],
            'short sha' => [self::URL, 'abcd', self::API, 'bootstrapValueInvalid'],
            'sha with a quote' => [self::URL, str_repeat('a', 63) . "'", self::API, 'bootstrapValueInvalid'],
        ];
    }

    /** @dataProvider badValues */
    public function testValuesThatCouldBreakOutOfTheQuotesAreRefused(string $url, string $sha, string $api, string $expected): void
    {
        $this->assertSame($expected, $this->code(fn () => BootstrapRenderer::render($this->template(), $url, $sha, $api)));
    }

    public function testAPlaceholderMustAppearExactlyOnce(): void
    {
        $this->assertSame('bootstrapTemplateInvalid', $this->code(fn () => BootstrapRenderer::render("A='@ENGINE_URL@'\n", self::URL, self::SHA, self::API)));
        $twice = $this->template() . "echo '@API_URL@'\n";
        $this->assertSame('bootstrapTemplateInvalid', $this->code(fn () => BootstrapRenderer::render($twice, self::URL, self::SHA, self::API)));
    }
}
