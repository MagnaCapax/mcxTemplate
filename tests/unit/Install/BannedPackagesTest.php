<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\BannedPackages;
use PHPUnit\Framework\TestCase;

// BannedPackagesTest: what counts as installed in a dpkg status file (docs/adr/0006).
final class BannedPackagesTest extends TestCase
{
    // stanza builds one dpkg status stanza; $status is the whole Status field value.
    private static function stanza(string $package, string $status, string $extra = ''): string
    {
        return "Package: $package\nStatus: $status\nPriority: optional\nArchitecture: all\nVersion: 1.0-1\n$extra\n";
    }

    /** @return array<string,array{0:string,1:string[]}> */
    public static function cases(): array
    {
        $clean = self::stanza('bash', 'install ok installed') . self::stanza('coreutils', 'install ok installed');
        return [
            'clean base system' => [$clean, []],
            'fail2ban installed' => [$clean . self::stanza('fail2ban', 'install ok installed'), ['fail2ban']],
            'removed, config files left' => [$clean . self::stanza('fail2ban', 'deinstall ok config-files'), []],
            'purged' => [$clean . self::stanza('ufw', 'purge ok not-installed'), []],
            'every banned name' => [self::stanza('sshguard', 'install ok installed') . self::stanza('crowdsec', 'install ok installed')
                . self::stanza('ufw', 'install ok installed') . self::stanza('fail2ban', 'install ok installed'), ['crowdsec', 'fail2ban', 'sshguard', 'ufw']],
            'held back is still installed' => [self::stanza('ufw', 'hold ok installed'), ['ufw']],
            'half configured has its files' => [self::stanza('ufw', 'install ok half-configured'), ['ufw']],
            'unpacked has its files' => [self::stanza('crowdsec', 'install ok unpacked'), ['crowdsec']],
            'trigger pending has its files' => [self::stanza('sshguard', 'install ok triggers-pending'), ['sshguard']],
            'no Status field fails closed' => ["Package: fail2ban\nArchitecture: all\n\n", ['fail2ban']],
            'unreadable Status fails closed' => ["Package: fail2ban\nStatus: broken\n\n", ['fail2ban']],
            'Status before Package' => ["Status: deinstall ok config-files\nPackage: fail2ban\n\n", []],
            'CRLF line ends' => [str_replace("\n", "\r\n", self::stanza('fail2ban', 'install ok installed')), ['fail2ban']],
            'last stanza without a blank line' => ["Package: ufw\nStatus: install ok installed", ['ufw']],
            'upper case package name' => [self::stanza('UFW', 'install ok installed'), ['ufw']],
            'similar names are not banned' => [self::stanza('python3-fail2ban', 'install ok installed') . self::stanza('ufw-extras', 'install ok installed')
                . self::stanza('gufw', 'install ok installed'), []],
            'a banned name in Depends or Description is not a package' => [self::stanza('webmin', 'install ok installed',
                "Depends: fail2ban, ufw\nDescription: works with fail2ban\n Package: ufw\n"), []],
            'the same package twice is reported once' => [self::stanza('ufw', 'install ok installed') . self::stanza('ufw', 'install ok installed'), ['ufw']],
            'empty file' => ['', []],
        ];
    }

    /**
     * @dataProvider cases
     * @param string[] $expected
     */
    public function testInstalledReadsTheStatusDatabase(string $status, array $expected): void
    {
        $this->assertSame($expected, BannedPackages::installed($status));
    }

    public function testTheListIsTheFourNamedPackages(): void
    {
        $this->assertSame(['fail2ban', 'ufw', 'crowdsec', 'sshguard'], BannedPackages::NAMES);
    }
}
