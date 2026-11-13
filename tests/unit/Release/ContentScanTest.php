<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

use Lib\Install\BannedPackages;
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

    /** @return array<string,array{0:string}> content that names a private key type but holds no complete block */
    public static function bareHeaders(): array
    {
        $begin = TarFixture::keyLine('BEGIN', '');
        return [
            // shared-mime-info magic: the header text cut short, inside binary bytes, no END line
            'file-type magic signature' => ["\0\0\x01" . '-----' . 'BEGIN PGP PRIVATE KEY BLOC' . "\0\x02\0"],
            'a header line with no END line' => [$begin . "\nAAAABBBB\n"],
            'a key split across string literals in program source' => ["k.load(\n\t'" . $begin . "\\n'\n\t'AAAABBBBCCCC\\n'\n\t'"
                . TarFixture::keyLine('END', '') . "\\n')\n"],
            'an END line of another type' => [$begin . "\nAAAA\n" . TarFixture::keyLine('END', 'RSA') . "\n"],
            'header and END with no body' => [$begin . "\n\n" . TarFixture::keyLine('END', '') . "\n"],
        ];
    }

    /** @dataProvider bareHeaders */
    public function testAHeaderWithoutACompleteBlockIsNotAPrivateKey(string $content): void
    {
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['usr/share/mime/magic', $content]]));
    }

    /** @return array<string,array{0:string,1:string}> path, content: complete blocks in many spellings and places */
    public static function completeBlocks(): array
    {
        $pycache = 'usr/lib/x/tests/__pycache__/x.pyc';
        $pkcs8 = TarFixture::pem('');
        return [
            'pkcs8 in a pycache file' => [$pycache, "\xe3\0\0\0\xf1" . $pkcs8 . ")\x06"],
            'openssh in a pycache file' => [$pycache, "\xe3\0" . TarFixture::pem('OPENSSH') . "\0"],
            'pgp in a pycache file' => [$pycache, "\xe3\0" . TarFixture::pem('PGP', "Version: x\n\nAAAA\n=AbCd\n") . "\0"],
            'rsa under a tests directory' => ['usr/share/doc/x/tests/fixture.pem', TarFixture::pem('RSA')],
            'ec under usr' => ['usr/lib/x/k.pem', TarFixture::pem('EC')],
            'crlf line endings' => ['srv/crlf', str_replace("\n", "\r\n", $pkcs8)],
            'json string escapes' => ['srv/c.json', '{"k":"' . str_replace("\n", '\\n', $pkcs8) . '"}'],
            'one line with spaces for newlines' => ['srv/.env', 'KEY=' . str_replace("\n", ' ', $pkcs8)],
            'indented and commented out' => ['etc/x.conf', '# ' . str_replace("\n", "\n# ", rtrim($pkcs8)) . "\n"],
            'a real header after a bare one' => ['srv/two', TarFixture::keyLine('BEGIN', '') . " text\n" . $pkcs8],
        ];
    }

    /** @dataProvider completeBlocks */
    public function testACompleteBlockIsRefusedWhereverItSitsAndHoweverItIsSpelled(string $name, string $content): void
    {
        $this->assertSame(['privateKey ' . $name], $this->codes([['etc/shadow', self::LOCKED_SHADOW], [$name, $content]]));
    }

    public function testAKnownPublicBlockIsReportedAsANoticeAndDoesNotRefuse(): void
    {
        // A fake block stands in for a published fixture: for plain LF text the normalised form is the text itself.
        $fixture = TarFixture::pem('', "AAAAfakePublicFixture\n");
        $public = [hash('sha256', $fixture) => 'test: a fake public fixture'];
        $pyc = 'usr/lib/x/tests/__pycache__/t.pyc';
        $file = TarFixture::write($this->dir . '/f.tar.gz', [['./etc/shadow', self::LOCKED_SHADOW], ['./' . $pyc, "\xe3\0" . $fixture . "\0"],
            ['srv/same.json', '"' . str_replace("\n", '\\r\\n', $fixture) . '"'], ['srv/both', $fixture . TarFixture::pem('')],
            ['srv/other', TarFixture::pem('', "AAAAfakePublicFixturf\n")]]);
        $found = ContentScan::scan($file, $public);
        $this->assertSame([['code' => 'publicFixture', 'path' => $pyc], ['code' => 'publicFixture', 'path' => 'srv/same.json'],
            ['code' => 'privateKey', 'path' => 'srv/both'], ['code' => 'privateKey', 'path' => 'srv/other']], $found);
        [$refusals, $notices] = ContentScan::partition($found);
        $this->assertSame(['srv/both', 'srv/other'], array_column($refusals, 'path'));
        $this->assertSame([$pyc, 'srv/same.json'], array_column($notices, 'path'));
        // The committed list is used when none is passed: the fake fixture is not on it.
        $this->assertSame(['privateKey ' . $pyc], $this->codes([['etc/shadow', self::LOCKED_SHADOW], [$pyc, $fixture]]));
    }

    public function testDotSlashPrefixedPathsKeepTheHostKeyAndShadowRules(): void
    {
        $this->assertSame([], $this->codes([['./etc/shadow', self::LOCKED_SHADOW], ['./etc/ssh/ssh_host_ed25519_key', TarFixture::pem('OPENSSH')],
            ['./etc/ssh/ssh_host_rsa_key', TarFixture::pem('RSA')]]));
        $this->assertSame(['privateKey root/.ssh/id_ed25519', 'rootPassword etc/shadow'], $this->codes([['./etc/shadow', "root:\$6\$x\$y:1:::::\n"],
            ['./root/.ssh/id_ed25519', TarFixture::pem('OPENSSH')], ['./etc/ssh/ssh_host_rsa_key.bak', 'no key here']]));
        $this->assertSame(['privateKey etc/ssh/ssh_host_rsa_key.bak'], $this->codes([['./etc/shadow', self::LOCKED_SHADOW],
            ['./etc/ssh/ssh_host_rsa_key.bak', TarFixture::pem('RSA')]]));
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

    // dpkgStatus builds a dpkg status file: one stanza per [package, "Status" value].
    private static function dpkgStatus(array $packages): string
    {
        $out = '';
        foreach ($packages as [$name, $state]) {
            $out .= "Package: $name\nStatus: $state\nArchitecture: all\n\n";
        }
        return $out;
    }

    public function testAnInstalledBannedPackageIsRefusedByName(): void
    {
        $status = self::dpkgStatus([['bash', 'install ok installed'], ['fail2ban', 'install ok installed'], ['ufw', 'install ok installed']]);
        $this->assertSame(['bannedPackage fail2ban', 'bannedPackage ufw'],
            $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', $status]]));
        [$refusals, $notices] = ContentScan::partition($this->scan([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', $status]]));
        $this->assertSame(['fail2ban', 'ufw'], array_column($refusals, 'path'), 'banned packages refuse, they are not notices');
        $this->assertSame([], $notices);
    }

    public function testRemovedPackagesCleanAndAbsentStatusFilesPass(): void
    {
        $removed = self::dpkgStatus([['fail2ban', 'deinstall ok config-files'], ['ufw', 'purge ok not-installed'], ['bash', 'install ok installed']]);
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', $removed]]));
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', self::dpkgStatus([['bash', 'install ok installed']])]]));
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW]]), 'no dpkg database: nothing is installed by dpkg');
    }

    public function testOnlyTheRootStatusFileCountsAndItIsReadPastTheKeyScanSizeLimit(): void
    {
        $banned = self::dpkgStatus([['crowdsec', 'install ok installed']]);
        // A copy elsewhere in the tree (a backup, a package's test data) is not the database.
        $this->assertSame([], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['usr/share/doc/x/status', $banned], ['var/lib/dpkg/status-old', $banned]]));
        // The real database is bigger than the 256 KiB the key scan reads; the banned stanza sits at its end.
        $big = str_repeat(self::dpkgStatus([['bash', 'install ok installed']]), 15000) . $banned;
        $this->assertGreaterThan(262144, strlen($big));
        $this->assertSame(['bannedPackage crowdsec'], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', $big]]));
    }

    public function testAStatusFileTooLargeToReadIsRefused(): void
    {
        $huge = str_repeat('x', BannedPackages::STATUS_MAX + 1);
        $this->assertSame(['dpkgStatusUnreadable var/lib/dpkg/status'], $this->codes([['etc/shadow', self::LOCKED_SHADOW], ['var/lib/dpkg/status', $huge]]));
    }
}
