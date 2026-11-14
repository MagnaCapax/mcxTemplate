<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\HostKeys;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// HostKeysTest lays out a target root on disk and checks which SSH host public keys are reported:
// valid ed25519/ecdsa lines only, comments dropped, and nothing reached through a symlink (CWE-59).
final class HostKeysTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-hostkeys-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/target/etc/ssh', 0755, true);
        mkdir($this->dir . '/elsewhere', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function key(string $name, string $text): void
    {
        file_put_contents($this->dir . '/target/etc/ssh/' . $name, $text);
    }

    public function testReadsEd25519ThenEcdsaAndDropsComments(): void
    {
        $this->key('ssh_host_ecdsa_key.pub', InstallFixtures::hostKey('ecdsa') . " root@buildhost\n");
        $this->key('ssh_host_ed25519_key.pub', InstallFixtures::hostKey() . " root@buildhost\n");
        $this->assertSame([InstallFixtures::hostKey(), InstallFixtures::hostKey('ecdsa')], HostKeys::read($this->dir . '/target'));
    }

    public function testAKeyWithoutCommentOrNewlineIsRead(): void
    {
        $this->key('ssh_host_ed25519_key.pub', InstallFixtures::hostKey());
        $this->assertSame([InstallFixtures::hostKey()], HostKeys::read($this->dir . '/target/'));
    }

    public function testNoEtcSshGivesAnEmptyList(): void
    {
        rmdir($this->dir . '/target/etc/ssh');
        $this->assertSame([], HostKeys::read($this->dir . '/target'));
        $this->assertSame([], HostKeys::read($this->dir . '/missing-root'));
    }

    // Only the two public key types are reported: an RSA line or a bare base64 blob (not a key line) gives nothing.
    public function testRsaLinesAndNonKeyTextAreNotReported(): void
    {
        $this->key('ssh_host_ed25519_key.pub', "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZWQyNTUxOQ==\n");
        $this->key('ssh_host_ecdsa_key.pub', InstallFixtures::hostKey('rsa') . " root@x\n");
        $this->assertSame([], HostKeys::read($this->dir . '/target'));
    }

    public function testTwoKeysInOneFileOrAShellMetacharacterAreRefused(): void
    {
        $this->key('ssh_host_ed25519_key.pub', InstallFixtures::hostKey() . "\n" . InstallFixtures::hostKey() . "\n");
        $this->key('ssh_host_ecdsa_key.pub', "ecdsa-sha2-nistp256 AAAA'\$(reboot)'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n");
        $this->assertSame([], HostKeys::read($this->dir . '/target'));
    }

    public function testASymlinkedKeyFileIsSkipped(): void
    {
        file_put_contents($this->dir . '/elsewhere/rescue.pub', InstallFixtures::hostKey() . "\n");
        symlink($this->dir . '/elsewhere/rescue.pub', $this->dir . '/target/etc/ssh/ssh_host_ed25519_key.pub');
        $this->key('ssh_host_ecdsa_key.pub', InstallFixtures::hostKey('ecdsa') . "\n");
        $this->assertSame([InstallFixtures::hostKey('ecdsa')], HostKeys::read($this->dir . '/target'));
    }

    public function testASymlinkedEtcSshDirectoryGivesAnEmptyList(): void
    {
        rmdir($this->dir . '/target/etc/ssh');
        file_put_contents($this->dir . '/elsewhere/ssh_host_ed25519_key.pub', InstallFixtures::hostKey() . "\n");
        symlink($this->dir . '/elsewhere', $this->dir . '/target/etc/ssh');
        $this->assertSame([], HostKeys::read($this->dir . '/target'));
    }

    public function testAnOversizedFileIsSkipped(): void
    {
        $this->key('ssh_host_ed25519_key.pub', InstallFixtures::hostKey() . ' ' . str_repeat('c', 5000) . "\n");
        $this->assertSame([], HostKeys::read($this->dir . '/target'));
    }
}
