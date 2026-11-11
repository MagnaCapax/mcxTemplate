<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\RootPassword;
use PHPUnit\Framework\TestCase;

// RootPasswordTest: CSPRNG-shaped, strong, hashed with SHA-512, secret file exclusive and 0600.
final class RootPasswordTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-rootpw-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testGeneratedPasswordIsTwentyAlphanumericCharsWithTwoClasses(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $pw = RootPassword::generate();
            $this->assertSame(1, preg_match('/^[A-Za-z0-9]{20}$/D', $pw));
            $this->assertTrue(RootPassword::isStrong($pw));
        }
    }

    public function testSingleClassSamplesAreResampled(): void
    {
        // First 20 draws are all index 0 ("A"): one class. The next 20 vary and must be used.
        $calls = 0;
        $rand = static function (int $min, int $max) use (&$calls): int {
            $calls++;
            return $calls <= 20 ? 0 : ($calls % 62);
        };
        $pw = RootPassword::generate($rand);
        $this->assertNotSame(str_repeat('A', 20), $pw);
        $this->assertGreaterThan(20, $calls);
    }

    public function testWeakAndDefaultPasswordsAreNotStrong(): void
    {
        foreach (['short1A', 'alllowercaseletters', 'ALLUPPERCASELETTERS', '1234567890123', 'Password12345', 'rescue-Admin99'] as $weak) {
            $this->assertFalse(RootPassword::isStrong($weak), $weak);
        }
    }

    public function testGenerationFailsClosedWhenRandomnessIsConstant(): void
    {
        $this->expectException(InstallRefusal::class);
        RootPassword::generate(static fn (int $min, int $max): int => 0);
    }

    public function testHashIsSha512CryptAndVerifies(): void
    {
        $hash = RootPassword::hash('Abcdef0123456789xyzQ');
        $this->assertStringStartsWith('$6$', $hash);
        $this->assertTrue(hash_equals($hash, crypt('Abcdef0123456789xyzQ', $hash)));
        $this->assertFalse(hash_equals($hash, crypt('wrong', $hash)));
        $this->assertStringNotContainsString('Abcdef0123456789xyzQ', $hash);
    }

    public function testSecretFileIsExclusiveAndPrivate(): void
    {
        $path = $this->dir . '/root.secret';
        RootPassword::writeSecret($path, 'Abcdef0123456789xyzQ');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame("Abcdef0123456789xyzQ\n", file_get_contents($path));
        try {
            RootPassword::writeSecret($path, 'another');
            $this->fail('an existing secret file was overwritten');
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
            $this->assertSame("Abcdef0123456789xyzQ\n", file_get_contents($path));
        }
    }

    public function testShortWriteRemovesTheFileAndRefuses(): void
    {
        $path = $this->dir . '/root.secret';
        $short = static fn ($handle, string $data) => fwrite($handle, substr($data, 0, 5));
        try {
            RootPassword::writeSecret($path, 'Abcdef0123456789xyzQ', $short);
            $this->fail('a short write was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
        }
        $this->assertFileDoesNotExist($path, 'a truncated password file must not stay behind');
    }

    public function testFailedWriteRemovesTheFileAndRefuses(): void
    {
        $path = $this->dir . '/root.secret';
        try {
            RootPassword::writeSecret($path, 'Abcdef0123456789xyzQ', static fn ($handle, string $data) => false);
            $this->fail('a failed write was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
        }
        $this->assertFileDoesNotExist($path);
    }

    public function testUsablePathAcceptsANewFileInAnExistingDirectory(): void
    {
        RootPassword::assertUsablePath($this->dir . '/root.secret', ['/mnt/target']);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider provideUnusablePaths */
    public function testUnusableSecretPathsAreRefused(string $suffix, string $needle): void
    {
        mkdir($this->dir . '/target', 0700);
        file_put_contents($this->dir . '/exists', 'old');
        symlink($this->dir . '/target', $this->dir . '/link');
        try {
            RootPassword::assertUsablePath($suffix === 'relative' ? 'root.secret' : $this->dir . $suffix, ['/mnt/target', $this->dir . '/target']);
            $this->fail("accepted $suffix");
        } catch (InstallRefusal $e) {
            $this->assertSame('secretOutInvalid', $e->refusalCode());
            $this->assertStringContainsString($needle, $e->getMessage());
        } finally {
            unlink($this->dir . '/link');
            @rmdir($this->dir . '/target');
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function provideUnusablePaths(): array
    {
        return [
            'relative' => ['relative', 'clean absolute path'],
            'dot-dot' => ['/x/../root.secret', 'clean absolute path'],
            'under the target' => ['/target/root.secret', 'under the target mount point'],
            'under the target via a symlinked directory' => ['/link/root.secret', 'under the target mount point'],
            'directory missing' => ['/none/root.secret', 'does not exist'],
            'file exists' => ['/exists', 'already exists'],
        ];
    }

    public function testSecretUnderTheRealTargetMountPointIsRefused(): void
    {
        $this->expectException(InstallRefusal::class);
        $this->expectExceptionMessage('under the target mount point');
        RootPassword::assertUsablePath('/mnt/target/root.secret');
    }

    public function testRelativeSecretPathIsRefused(): void
    {
        $this->expectException(InstallRefusal::class);
        RootPassword::writeSecret('secret.txt', 'x');
    }
}
