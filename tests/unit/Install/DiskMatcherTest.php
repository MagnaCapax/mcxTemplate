<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\DiskMatcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// DiskMatcherTest covers rules 1-6 of the target-disk selection design. Every
// refusal is asserted by its named code; each test fails if its rule is removed.
final class DiskMatcherTest extends TestCase
{
    // refusal asserts a failed match with the given code.
    private function assertRefused(string $code, array $result): void
    {
        $this->assertFalse($result['ok'], 'expected a refusal but the match succeeded');
        $this->assertSame($code, $result['code'], (string) ($result['detail'] ?? ''));
    }

    public function testHappyPathResolvesRolesAndIgnoresKvm(): void
    {
        $r = DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), InstallFixtures::state());
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
        $this->assertSame('nvme0n1', $r['roles']['root']['kname']);
        $this->assertSame('sda', $r['roles']['boot0']['kname']);
        $this->assertSame('sdb', $r['roles']['boot1']['kname']);
        $this->assertSame([['kname' => 'sdc', 'reason' => 'kvmVirtualMedia']], $r['ignored']);
    }

    // --- rule 1: identity by serial, exactly once ------------------------------------

    public function testDuplicateRootSerialIsAmbiguous(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[] = InstallFixtures::disk('nvme1n1', ['serial' => 'NVME-ROOT-1', 'idPath' => 'pci-0000:02:00.0-nvme-1']);
        $this->assertRefused('diskAmbiguous', DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state()));
    }

    public function testMissingRootSerialIsMissing(): void
    {
        $this->assertRefused('diskMissing', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::without('nvme0n1'), InstallFixtures::state()));
    }

    public function testKernelNameIsNotIdentity(): void
    {
        // The root disk comes back under another kernel name: still matched by serial.
        $inv = InstallFixtures::inventory();
        $inv[0]['kname'] = 'nvme1n1';
        $inv[0]['path'] = '/dev/nvme1n1';
        $r = DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state(['partitions' => ['nvme1n1' => [], 'sda' => [], 'sdb' => [], 'sdc' => []]]));
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
        $this->assertSame('nvme1n1', $r['roles']['root']['kname']);
    }

    // --- rule 2: sticks with empty or duplicate serials -----------------------------

    public function testEmptySerialSticksAreResolvedByUsbPath(): void
    {
        // Swapping the order of the two sticks in the inventory must not swap the roles.
        $inv = InstallFixtures::inventory();
        [$inv[1], $inv[2]] = [$inv[2], $inv[1]];
        $r = DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state());
        $this->assertTrue($r['ok']);
        $this->assertSame('sda', $r['roles']['boot0']['kname']);
        $this->assertSame('sdb', $r['roles']['boot1']['kname']);
    }

    public function testMissingStickIsRefused(): void
    {
        $this->assertRefused('diskMissing', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::without('sdb'), InstallFixtures::state()));
    }

    public function testSticksIdenticalInEverythingAreAmbiguous(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[2]['idPath'] = $inv[1]['idPath']; // same port, same size, same model, no serial
        $plan = InstallFixtures::plan();
        $this->assertRefused('diskAmbiguous', DiskMatcher::match($plan, $inv, InstallFixtures::state()));
    }

    public function testDuplicateStickSerialsFallBackToUsbPath(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[1]['serial'] = 'DUPSERIAL';
        $inv[2]['serial'] = 'DUPSERIAL';
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][0]['serial'] = 'DUPSERIAL';
        $raw['disks']['boot'][1]['serial'] = 'DUPSERIAL';
        $r = DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state());
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
        $this->assertSame('sda', $r['roles']['boot0']['kname']);
    }

    public function testStickSerialMissingFromPlanMatchIsNotGuessedByPath(): void
    {
        // A plan serial that no disk carries must not silently fall back to the USB path.
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][0]['serial'] = 'NOSUCHSERIAL';
        $this->assertRefused('diskMissing', DiskMatcher::match(InstallFixtures::plan($raw), InstallFixtures::inventory(), InstallFixtures::state()));
    }

    public function testStickMustBeOnUsb(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[1]['tran'] = 'sata';
        $this->assertRefused('diskMissing', DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state()));
    }

    // --- rule 3: size gates ------------------------------------------------------------

    public function testRootSizeOutsideOnePercentIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[0]['sizeBytes'] = (int) (4000787030016 * 0.97);
        $this->assertRefused('sizeMismatch', DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state()));
    }

    public function testTinyStickIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[1]['sizeBytes'] = 3 * 1073741824;
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][0]['sizeBytes'] = 3 * 1073741824;
        $this->assertRefused('sizeMismatch', DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state()));
    }

    public function testHugeStickIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[2]['sizeBytes'] = 128 * 1073741824;
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][1]['sizeBytes'] = 128 * 1073741824;
        $this->assertRefused('sizeMismatch', DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state()));
    }

    public function testBiosSingleRefusesFourTerabyteRoot(): void
    {
        $raw = InstallFixtures::rawPlan(['layout' => 'biosSingle']);
        $raw['disks']['boot'] = [];
        $inv = InstallFixtures::without('sda');
        $inv = array_values(array_filter($inv, static fn (array $d): bool => $d['kname'] !== 'sdb'));
        $this->assertRefused('layoutSizeRefused', DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state()));
    }

    public function testBiosSingleAcceptsTwoTerabyteDisk(): void
    {
        $raw = InstallFixtures::rawPlan(['layout' => 'biosSingle']);
        $raw['disks']['boot'] = [];
        $raw['disks']['root']['sizeBytes'] = 2000398934016;
        $inv = [InstallFixtures::disk('sda', ['serial' => 'NVME-ROOT-1', 'sizeBytes' => 2000398934016, 'tran' => 'nvme'])];
        $r = DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state(['partitions' => ['sda' => []]]));
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
    }

    // --- rule 4: unexpected disks and the KVM device -----------------------------------

    public function testKvmVirtualMediaIsNeverATarget(): void
    {
        // A plan that names the KVM device as a stick finds nothing: the device is classified away.
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['boot'][0] = ['serial' => '', 'usbPath' => 'pci-0000:00:14.0-usb-0:1:1.0-scsi-0:0:0:0', 'sizeBytes' => 21900000000, 'transport' => 'usb', 'model' => 'KvmMedia'];
        $this->assertRefused('diskMissing', DiskMatcher::match(InstallFixtures::plan($raw), InstallFixtures::inventory(), InstallFixtures::state()));
    }

    public function testUnexpectedExtraDiskIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[] = InstallFixtures::disk('sdd', ['serial' => 'STRANGER', 'tran' => 'sata', 'sizeBytes' => 1000204886016]);
        $this->assertRefused('unexpectedDisk', DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state()));
    }

    public function testPlanIgnoreListSkipsADevice(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[] = InstallFixtures::disk('sdd', ['serial' => '', 'tran' => 'usb', 'usbVendorId' => 'abcd', 'sizeBytes' => 1000000000]);
        $raw = InstallFixtures::rawPlan(['ignore' => [['usbVendorId' => 'abcd']]]);
        $r = DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state());
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
    }

    // --- rule 5: in-use gate -------------------------------------------------------------

    public function testMountedPartitionOfTargetIsRefused(): void
    {
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), InstallFixtures::state(['mounted' => ['nvme0n1p2']])));
    }

    public function testSwapOnTargetIsRefused(): void
    {
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), InstallFixtures::state(['swapped' => ['nvme0n1p1']])));
    }

    public function testMdArrayWithMemberOutsideTargetsIsRefused(): void
    {
        $state = InstallFixtures::state(['md' => ['md0' => ['sda1', 'sdz1']]]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testMdArrayFullyInsideTargetsIsStoppedFirst(): void
    {
        // As sysfs reports it: the array is also the holder of both member partitions.
        $state = InstallFixtures::state(['md' => ['md127' => ['sda1', 'sdb1']], 'holders' => ['sda1' => ['md127'], 'sdb1' => ['md127']]]);
        $r = DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state);
        $this->assertTrue($r['ok'], (string) ($r['detail'] ?? ''));
        $this->assertSame(['md127'], $r['stopArrays']);
    }

    public function testBcacheHolderOnATargetPartitionIsRefused(): void
    {
        $state = InstallFixtures::state(['holders' => ['nvme0n1p2' => ['bcache0']]]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testHolderOnAWholeTargetDiskIsRefused(): void
    {
        $state = InstallFixtures::state(['holders' => ['sdb' => ['dm-3']]]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testDeviceStackedOnAnArrayInsideTheTargetsIsRefused(): void
    {
        // LVM (dm) on top of an md array whose members are all targets: stopping the array would fail or break it.
        $state = InstallFixtures::state(['md' => ['md127' => ['sda1', 'sdb1']], 'holders' => ['sda1' => ['md127'], 'sdb1' => ['md127'], 'md127' => ['dm-0']]]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testMountedPartitionOfAnArrayInsideTheTargetsIsRefused(): void
    {
        $state = InstallFixtures::state(['md' => ['md127' => ['sda1', 'sdb1']], 'mounted' => ['md127p1']]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testRootSerialOnAnotherTransportIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[0]['tran'] = 'sata';
        $r = DiskMatcher::match(InstallFixtures::plan(), $inv, InstallFixtures::state());
        $this->assertRefused('diskMissing', $r);
        $this->assertStringContainsString('transport', $r['detail']);
    }

    public function testMountedMdArrayInsideTargetsIsRefused(): void
    {
        $state = InstallFixtures::state(['md' => ['md127' => ['sda1', 'sdb1']], 'mounted' => ['md127']]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testLvmMemberOnTargetIsRefused(): void
    {
        $state = InstallFixtures::state(['dm' => ['dm-0' => ['nvme0n1p2']]]);
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testRescueBootMediumOnTargetIsRefused(): void
    {
        $this->assertRefused('diskInUse', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), InstallFixtures::state(['rescueDisk' => 'sda'])));
    }

    // --- rule 6: node identity -------------------------------------------------------------

    public function testPlanMacNotOnThisBoxIsRefused(): void
    {
        $state = InstallFixtures::state(['nicMacs' => ['11:22:33:44:55:66']]);
        $this->assertRefused('nodeIdentityMismatch', DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state));
    }

    public function testEitherPortOfADualPortNicIsAccepted(): void
    {
        $state = InstallFixtures::state(['nicMacs' => [InstallFixtures::MAC]]);
        $this->assertTrue(DiskMatcher::match(InstallFixtures::plan(), InstallFixtures::inventory(), $state)['ok']);
    }

    public function testRootWwnMismatchIsRefused(): void
    {
        $inv = InstallFixtures::inventory();
        $inv[0]['wwn'] = 'eui.0025385b71b0c0de';
        $raw = InstallFixtures::rawPlan();
        $raw['disks']['root']['wwn'] = 'eui.ffffffffffffffff';
        $this->assertRefused('nodeIdentityMismatch', DiskMatcher::match(InstallFixtures::plan($raw), $inv, InstallFixtures::state()));
    }
}
