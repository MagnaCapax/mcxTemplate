<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\ArchiveCheck;
use Lib\Install\InstallRefusal;
use Lib\Install\LayoutPlanner;
use Lib\Install\RecordingRunner;
use PHPUnit\Framework\TestCase;

// ArchiveCheckTest: template integrity and layout are proven before any wipe.
final class ArchiveCheckTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-archive-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/t.tar.gz', 'template-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/t.tar.gz');
        @unlink($this->dir . '/stage/template.tar.gz');
        @rmdir($this->dir . '/stage');
        @rmdir($this->dir);
    }

    private function assertCode(string $code, callable $run): void
    {
        try {
            $run();
            $this->fail("expected $code");
        } catch (InstallRefusal $e) {
            $this->assertSame($code, $e->refusalCode());
        }
    }

    private const GOOD = ['rootfs/', 'rootfs/etc/', 'rootfs/etc/os-release', 'rootfs/opt/mcxTemplate/', 'rootfs/opt/mcxTemplate/installTemplate.php'];

    public function testMatchingSizeAndHashPass(): void
    {
        ArchiveCheck::verifyFile($this->dir . '/t.tar.gz', 14, hash('sha256', 'template-bytes'));
        $this->addToAssertionCount(1);
    }

    public function testHashMismatchIsRefused(): void
    {
        $this->assertCode('templateHashMismatch', fn () => ArchiveCheck::verifyFile($this->dir . '/t.tar.gz', 14, str_repeat('0', 64)));
    }

    public function testSizeMismatchIsRefused(): void
    {
        $this->assertCode('templateSizeMismatch', fn () => ArchiveCheck::verifyFile($this->dir . '/t.tar.gz', 15, hash('sha256', 'template-bytes')));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->assertCode('templateMissing', fn () => ArchiveCheck::verifyFile($this->dir . '/nope.tar.gz', 1, str_repeat('0', 64)));
    }

    public function testArchiveWithRootfsPrefixPassesAndStripsOneComponent(): void
    {
        $this->assertSame(1, ArchiveCheck::checkEntries(self::GOOD));
    }

    // GNU tar counts the leading "." as a component: "./rootfs/etc/x" needs --strip-components=2 to land on etc/x.
    // With 1 the whole tree landed under the target's rootfs/ and the first write into /etc was refused.
    public function testArchiveWithDotSlashPrefixPassesAndStripsTwoComponents(): void
    {
        $this->assertSame(2, ArchiveCheck::checkEntries(array_merge(['./'], array_map(static fn (string $e): string => './' . $e, self::GOOD))));
    }

    public function testArchiveMixingDotSlashAndBareNamesIsRefused(): void
    {
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries(array_merge(self::GOOD, ['./rootfs/etc/hosts'])));
    }

    public function testArchiveWithoutRootfsPrefixIsRefused(): void
    {
        $flat = ['etc/os-release', 'opt/mcxTemplate/installTemplate.php', 'usr/bin/php'];
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries($flat));
    }

    public function testMemberOutsideRootfsIsRefused(): void
    {
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries(array_merge(self::GOOD, ['etc/shadow'])));
    }

    public function testParentTraversalIsRefused(): void
    {
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries(array_merge(self::GOOD, ['rootfs/../../etc/cron.d/x'])));
    }

    public function testAbsoluteMemberIsRefusedByThePrefixRule(): void
    {
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries(array_merge(self::GOOD, ['/rootfs/etc/cron.d/x'])));
    }

    public function testStageCopiesIntoAFreshPrivateDirectoryAndHashesTheCopy(): void
    {
        $stage = $this->dir . '/stage';
        $copy = ArchiveCheck::stage($this->dir . '/t.tar.gz', $stage, 14, hash('sha256', 'template-bytes'));
        $this->assertSame($stage . '/template.tar.gz', $copy);
        $this->assertSame('template-bytes', file_get_contents($copy));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($stage)), -4));
        ArchiveCheck::unstage($stage);
        $this->assertDirectoryDoesNotExist($stage);
        $this->assertFileExists($this->dir . '/t.tar.gz', 'unstage never touches the original');
    }

    public function testStageRefusesAHashMismatchAndLeavesNothing(): void
    {
        $stage = $this->dir . '/stage';
        $this->assertCode('templateHashMismatch', fn () => ArchiveCheck::stage($this->dir . '/t.tar.gz', $stage, 14, str_repeat('0', 64)));
        $this->assertDirectoryDoesNotExist($stage);
    }

    public function testStageRefusesASizeMismatchAndLeavesNothing(): void
    {
        $stage = $this->dir . '/stage';
        $this->assertCode('templateSizeMismatch', fn () => ArchiveCheck::stage($this->dir . '/t.tar.gz', $stage, 13, hash('sha256', 'template-bytes')));
        $this->assertCode('templateSizeMismatch', fn () => ArchiveCheck::stage($this->dir . '/t.tar.gz', $stage, 15, hash('sha256', 'template-bytes')));
        $this->assertDirectoryDoesNotExist($stage);
    }

    public function testStageRefusesAnExistingDirectoryOrSymlink(): void
    {
        mkdir($this->dir . '/stage', 0700);
        $this->assertCode('templateStageRefused', fn () => ArchiveCheck::stage($this->dir . '/t.tar.gz', $this->dir . '/stage', 14, hash('sha256', 'template-bytes')));
        rmdir($this->dir . '/stage');
        symlink($this->dir, $this->dir . '/stage');
        $this->assertCode('templateStageRefused', fn () => ArchiveCheck::stage($this->dir . '/t.tar.gz', $this->dir . '/stage', 14, hash('sha256', 'template-bytes')));
        unlink($this->dir . '/stage');
    }

    public function testMissingEngineIsRefused(): void
    {
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkEntries(['rootfs/', 'rootfs/etc/os-release']));
    }

    public function testMdLayoutNeedsMdadmInTheTemplate(): void
    {
        $md = LayoutPlanner::templateNeeds('biosNvmeUsbBoot');
        $this->assertCode('templateLacksMdadm', fn () => ArchiveCheck::checkEntries(self::GOOD, $md));
        $this->assertSame(1, ArchiveCheck::checkEntries(array_merge(self::GOOD, ['rootfs/usr/sbin/mdadm']), $md));
        $this->assertSame(1, ArchiveCheck::checkEntries(array_merge(self::GOOD, ['rootfs/sbin/mdadm']), $md), 'unmerged /sbin');
        $dotted = array_map(static fn (string $e): string => './' . $e, array_merge(self::GOOD, ['rootfs/usr/sbin/mdadm']));
        $this->assertSame(2, ArchiveCheck::checkEntries($dotted, $md));
        $this->assertCode('templateLacksMdadm', fn () => ArchiveCheck::checkEntries(array_merge(self::GOOD, ['rootfs/usr/share/doc/mdadm', 'rootfs/usr/sbin/mdadm.d/x']), $md));
    }

    public function testSingleDiskLayoutNeedsNothingExtra(): void
    {
        $this->assertSame([], LayoutPlanner::templateNeeds('biosSingle'));
        $this->assertSame(1, ArchiveCheck::checkEntries(self::GOOD, LayoutPlanner::templateNeeds('biosSingle')));
    }

    public function testLayoutCheckPassesTheNeedsToTheListing(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['tar', '-tzf'], implode("\n", self::GOOD) . "\n");
        $this->assertCode('templateLacksMdadm', fn () => ArchiveCheck::checkLayout($runner, '/run/t.tar.gz', LayoutPlanner::templateNeeds('biosNvmeUsbBoot')));
        $this->assertCount(1, $runner->argvList(), 'refused at the listing, before the auditor runs');
    }

    public function testLayoutCheckListsThenAuditsThroughTheRunner(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['tar', '-tzf'], implode("\n", self::GOOD) . "\n");
        ArchiveCheck::checkLayout($runner, '/run/t.tar.gz');
        $calls = $runner->argvList();
        $this->assertSame(['tar', '-tzf', '/run/t.tar.gz'], $calls[0]);
        $this->assertSame(PHP_BINARY, $calls[1][0]);
        $this->assertStringEndsWith('/tools/check-template.php', $calls[1][1]);
        $this->assertSame(['--path=/run/t.tar.gz', '--tar'], array_slice($calls[1], 2));
    }

    public function testFailingAuditIsRefused(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['tar', '-tzf'], implode("\n", self::GOOD) . "\n");
        $runner->addResponse([PHP_BINARY], '', 2);
        $this->assertCode('templateAuditFailed', fn () => ArchiveCheck::checkLayout($runner, '/run/t.tar.gz'));
    }

    public function testABannedPackageInTheAuditIsItsOwnNamedRefusal(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['tar', '-tzf'], implode("\n", self::GOOD) . "\n");
        $runner->addResponse([PHP_BINARY], "Template audit results:\n  BANNED   banned package fail2ban\nbannedPackage: fail2ban, ufw\n", 3);
        try {
            ArchiveCheck::checkLayout($runner, '/run/t.tar.gz');
            $this->fail('a banned package must refuse');
        } catch (InstallRefusal $e) {
            $this->assertSame('bannedPackage', $e->refusalCode());
            $this->assertStringContainsString('fail2ban, ufw', $e->getMessage());
        }
    }

    public function testUnreadableArchiveListingIsRefused(): void
    {
        $runner = new RecordingRunner();
        $runner->addResponse(['tar', '-tzf'], '', 2);
        $this->assertCode('templateLayoutRefused', fn () => ArchiveCheck::checkLayout($runner, '/run/t.tar.gz'));
    }
}
