<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\RecordingRunner;
use Lib\Install\TmpfsGrower;
use PHPUnit\Framework\TestCase;

// TmpfsGrowerTest: the remount is issued only for a tmpfs whose size is known, with the size spelled in bytes.
final class TmpfsGrowerTest extends TestCase
{
    private function runners(string $findmntOut, int $findmntCode = 0): array
    {
        $reader = new RecordingRunner();
        $reader->addResponse(['findmnt'], $findmntOut, $findmntCode);
        return [$reader, new RecordingRunner()];
    }

    public function testNewLimitIsUsedBytesPlusNeedAndTheMountPointIsPassedWhole(): void
    {
        [$reader, $writer] = $this->runners("tmpfs /run/a b\n");
        $grower = new TmpfsGrower($reader, $writer, static fn (string $d) => 1000.0);
        // 1000 total, 400 free: 600 used; need 500 -> limit 1100.
        $this->assertTrue($grower->growFor('/run/a b/stage', 500, 400.0));
        $this->assertSame([['mount', '-o', 'remount,size=1100', '/run/a b']], $writer->argvList());
    }

    public function testUnknownTotalSizeOrFailedFindmntDoNotRemount(): void
    {
        [$reader, $writer] = $this->runners("tmpfs /run\n");
        $this->assertFalse((new TmpfsGrower($reader, $writer, static fn (string $d) => false))->growFor('/run/x', 500, 400));
        [$reader, $writer] = $this->runners('', 1);
        $this->assertFalse((new TmpfsGrower($reader, $writer, static fn (string $d) => 1000))->growFor('/run/x', 500, 400));
        $this->assertSame([], $writer->calls());
    }

    public function testRemountFailureIsReportedAsFalse(): void
    {
        [$reader, $writer] = $this->runners("tmpfs /run\n");
        $writer->addResponse(['mount'], '', 32, 'busy');
        $this->assertFalse((new TmpfsGrower($reader, $writer, static fn (string $d) => 1000))->growFor('/run/x', 500, 400));
    }
}
