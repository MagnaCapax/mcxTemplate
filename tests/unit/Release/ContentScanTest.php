<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Release\ContentScan;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TarFixture.php';

// ContentScanTest builds tiny archives byte by byte and proves what the scan refuses and what it allows.
final class ContentScanTest extends TestCase
{
    private const LOCKED_SHADOW = "root:*:19000:0:99999:7:::\ndaemon:*:19000:0:99999:7:::\n";

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-scan-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @param array<int,array{0:string,1:string,2?:string}> $entries */
    private function scan(array $entries): array
    {
        return ContentScan::scan(TarFixture::write($this->dir . '/r.tar.gz', $entries));
    }

    /** @return string[] "code path" per finding, sorted */
    private function codes(array $entries): array
    {
        $out = array_map(static fn (array $f): string => trim($f['code'] . ' ' . $f['path']), $this->scan($entries));
        sort($out);
        return $out;
    }

    public function testACleanRootfsPassesAndSshHostKeysAreAllowed(): void
    {
        $this->assertSame([], $this->codes([
            ['etc/', '', '5'], ['etc/shadow', self::LOCKED_SHADOW], ['etc/os-release', "ID=debian\n"],
            ['etc/ssh/ssh_host_ed25519_key', TarFixture::pem('OPENSSH')], ['etc/ssh/ssh_host_rsa_key', TarFixture::pem('OPENSSH')], ['etc/ssh/ssh_host_ed25519_key.pub', "ssh-ed25519 AAAA\n"],
            ['root/.ssh/known_hosts', "host ssh-ed25519 AAAA\n"], ['usr/share/doc/x', str_repeat('a', 5000)],
        ]));
    }

    /** @return array<string,array{0:string,1:string,2:string}> name, content, expected code */
    public static function privateKeys(): array
    {
        return [
            'user ssh key' => ['root/.ssh/id_ed25519', TarFixture::pem('OPENSSH'), 'privateKey'],
            'rsa pem in a home' => ['home/u/key.pem', TarFixture::pem('RSA'), 'privateKey'],
            'ec key' => ['srv/k', TarFixture::pem('EC'), 'privateKey'],
            'encrypted pkcs8' => ['srv/k8', TarFixture::pem('ENCRYPTED'), 'privateKey'],
            'pgp block' => ['root/.gnupg/sec.asc', TarFixture::pem('PGP'), 'privateKey'],
            'host key name with a suffix is not a host key' => ['etc/ssh/ssh_host_rsa_key.bak', TarFixture::pem('OPENSSH'), 'privateKey'],
            'host key name in another directory' => ['root/etc/ssh/ssh_host_rsa_key', TarFixture::pem('OPENSSH'), 'privateKey'],
            'key after a long preamble' => ['etc/ssl/private/x.key', str_repeat("# c\n", 200) . TarFixture::pem('OPENSSH'), 'privateKey'],
        ];
    }

    /** @dataProvider privateKeys */
    public function testPrivateKeysOutsideTheHostKeyListAreRefused(string $name, string $content, string $code): void
    {
        $found = $this->codes([['etc/shadow', self::LOCKED_SHADOW], [$name, $content]]);
        $this->assertSame([$code . ' ' . $name], $found);
    }

    public function testPublicKeysAndCertificatesAreNotPrivateKeys(): void
    {
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['etc/ssl/certs/a.pem', '-----' . "BEGIN CERTIFICATE-----\nx\n"],
            ['root/.ssh/id_rsa.pub', '-----' . "BEGIN PUBLIC KEY-----\nx\n"]]));
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function authorizedKeys(): array
    {
        return ['empty file' => ['root/.ssh/authorized_keys', '', '0'], 'with a key' => ['home/a/.ssh/authorized_keys', "ssh-ed25519 AAAA a\n", '0'],
            'second spelling' => ['home/a/.ssh/authorized_keys2', 'x', '0'], 'a symlink of that name' => ['root/.ssh/authorized_keys', '/elsewhere', '2'],
            'dot slash prefix' => ['./root/.ssh/authorized_keys', 'x', '0']];
    }

    /** @dataProvider authorizedKeys */
    public function testAnyAuthorizedKeysFileIsRefusedWhateverItHolds(string $name, string $content, string $type): void
    {
        $found = $this->codes([['etc/shadow', self::LOCKED_SHADOW], [$name, $content, $type]]);
        $this->assertSame(['authorizedKeys ' . ltrim($name, './')], $found);
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function shadows(): array
    {
        return [
            'star' => ["root:*:1:::::\n", true], 'bang' => ["root:!:1:::::\n", true], 'bang plus hash (locked account)' => ["root:!\$6\$abc:1:::::\n", true],
            'double bang' => ["root:!!:1:::::\n", true],
            'sha512 hash' => ["root:\$6\$salt\$hash:1:::::\n", false], 'yescrypt hash' => ["root:\$y\$j9T\$salt\$hash:1:::::\n", false],
            'empty password' => ["root::1:::::\n", false], 'no root line' => ["daemon:*:1:::::\n", false],
            'root is not first' => ["daemon:*:1:::::\nroot:\$6\$x:1:::::\n", false], 'empty file' => ['', false],
            'only a field name' => ["root\n", false],
        ];
    }

    /** @dataProvider shadows */
    public function testRootMustBeLockedInEtcShadow(string $shadow, bool $locked): void
    {
        $this->assertSame($locked, ContentScan::rootLocked($shadow));
        $found = $this->codes([['./etc/shadow', $shadow]]);
        $this->assertSame($locked ? [] : ['rootPassword etc/shadow'], $found);
    }

    public function testAMissingShadowFileIsAFinding(): void
    {
        $this->assertSame(['shadowMissing etc/shadow'], $this->codes([['etc/os-release', "ID=debian\n"]]));
    }

    public function testEveryFindingIsReportedNotJustTheFirst(): void
    {
        $this->assertSame(['authorizedKeys root/.ssh/authorized_keys', 'privateKey root/.ssh/id_rsa', 'rootPassword etc/shadow'], $this->codes([
            ['etc/shadow', "root:\$6\$x\$y:1:::::\n"], ['root/.ssh/id_rsa', TarFixture::pem('OPENSSH')], ['root/.ssh/authorized_keys', 'k'],
        ]));
    }

    public function testLongPathsAreReadThroughGnuLongNameEntries(): void
    {
        $long = 'home/' . str_repeat('d/', 70) . 'authorized_keys';
        $this->assertGreaterThan(100, strlen($long));
        $this->assertSame(['authorizedKeys ' . $long], $this->codes([['etc/shadow', self::LOCKED_SHADOW], [$long, 'k']]));
    }

    public function testPaxPathsAreReadToo(): void
    {
        $bytes = TarFixture::entry('etc/shadow', self::LOCKED_SHADOW) . TarFixture::paxEntry('root/.ssh/authorized_keys', 'k') . str_repeat("\0", 1024);
        file_put_contents($this->dir . '/p.tar.gz', gzencode($bytes));
        $found = ContentScan::scan($this->dir . '/p.tar.gz');
        $this->assertSame([['code' => 'authorizedKeys', 'path' => 'root/.ssh/authorized_keys']], $found);
    }

    public function testATruncatedArchiveIsMalformedNotClean(): void
    {
        $bytes = TarFixture::bytes([['etc/shadow', self::LOCKED_SHADOW], ['usr/big', str_repeat('a', 4000)]]);
        file_put_contents($this->dir . '/t.tar.gz', gzencode(substr($bytes, 0, 1500)));
        $this->assertSame([['code' => 'archiveMalformed', 'path' => '']], ContentScan::scan($this->dir . '/t.tar.gz'));
    }

    public function testACorruptHeaderIsMalformed(): void
    {
        $bytes = TarFixture::bytes([['etc/shadow', self::LOCKED_SHADOW]]);
        $bytes[10] = chr(ord($bytes[10]) ^ 0xFF);   // breaks the header checksum
        file_put_contents($this->dir . '/c.tar.gz', gzencode($bytes));
        $this->assertSame([['code' => 'archiveMalformed', 'path' => '']], ContentScan::scan($this->dir . '/c.tar.gz'));
    }

    public function testAMissingFileIsMalformed(): void
    {
        $this->assertSame([['code' => 'archiveMalformed', 'path' => '']], ContentScan::scan($this->dir . '/none.tar.gz'));
    }

    public function testFindingsNeverCarryFileContent(): void
    {
        $found = $this->scan([['etc/shadow', self::LOCKED_SHADOW], ['root/id', TarFixture::pem('OPENSSH')]]);
        $this->assertStringNotContainsString('AAAA', json_encode($found));
    }
}
