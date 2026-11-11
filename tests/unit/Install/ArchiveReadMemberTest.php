<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\ArchiveCheck;
use Lib\Install\RecordingRunner;
use PHPUnit\Framework\TestCase;

// ArchiveReadMemberTest: archives from the rehydrate tool name members "./rootfs/...", others "rootfs/...".
final class ArchiveReadMemberTest extends TestCase
{
    public function testTheDotSlashSpellingIsTriedFirst(): void
    {
        $r = new RecordingRunner();
        $r->addResponse(['tar', '-xzOf', 'a.tar.gz', './rootfs/etc/os-release'], "ID=debian\n");
        $this->assertSame("ID=debian\n", ArchiveCheck::readMember($r, 'a.tar.gz', 'rootfs/etc/os-release'));
        $this->assertCount(1, $r->calls());
    }

    public function testThePlainSpellingIsTheFallback(): void
    {
        $r = new RecordingRunner();
        $r->addResponse(['tar', '-xzOf', 'a.tar.gz', './rootfs/etc/os-release'], '', 2);
        $r->addResponse(['tar', '-xzOf', 'a.tar.gz', 'rootfs/etc/os-release'], "ID=ubuntu\n");
        $this->assertSame("ID=ubuntu\n", ArchiveCheck::readMember($r, 'a.tar.gz', 'rootfs/etc/os-release'));
        $this->assertCount(2, $r->calls());
    }

    public function testAnAbsentMemberIsNull(): void
    {
        $r = new RecordingRunner();
        $r->addResponse(['tar'], '', 2);
        $this->assertNull(ArchiveCheck::readMember($r, 'a.tar.gz', 'rootfs/etc/os-release'));
    }
}
