<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\InstallRefusal;
use Lib\Install\PlanValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// PlanValidatorTest: the plan file is untrusted input; every field has a strict pattern.
final class PlanValidatorTest extends TestCase
{
    // assertRejects expects planInvalid for a mutated raw plan.
    private function assertRejects(callable $mutate): void
    {
        $raw = InstallFixtures::rawPlan();
        $mutate($raw);
        try {
            PlanValidator::validate($raw);
            $this->fail('the plan was accepted');
        } catch (InstallRefusal $e) {
            $this->assertSame('planInvalid', $e->refusalCode());
        }
    }

    public function testValidPlanIsNormalised(): void
    {
        $plan = InstallFixtures::plan();
        $this->assertSame('aa:bb:cc:dd:ee:02', $plan['mac']);
        $this->assertTrue($plan['wipeAuthorized']);
        $this->assertCount(2, $plan['disks']['boot']);
    }

    /** @dataProvider provideBadFields */
    public function testMalformedFieldsAreRefused(callable $mutate): void
    {
        $this->assertRejects($mutate);
    }

    public static function provideBadFields(): array
    {
        return [
            'hostname with shell metachar' => [static function (array &$p): void { $p['host']['fqdn'] = 'a.example;reboot'; }],
            'hostname with space' => [static function (array &$p): void { $p['host']['fqdn'] = 'a b.example'; }],
            'hostname option injection' => [static function (array &$p): void { $p['host']['fqdn'] = '-rf.example'; }],
            'cidr without prefix' => [static function (array &$p): void { $p['host']['cidr'] = '192.0.2.10'; }],
            'cidr prefix 31' => [static function (array &$p): void { $p['host']['cidr'] = '192.0.2.10/31'; }],
            'cidr bad octet' => [static function (array &$p): void { $p['host']['cidr'] = '192.0.2.300/24'; }],
            'gateway name' => [static function (array &$p): void { $p['host']['gateway'] = 'gw.example'; }],
            'resolver list empty' => [static function (array &$p): void { $p['host']['resolvers'] = []; }],
            'resolver not an ip' => [static function (array &$p): void { $p['host']['resolvers'] = ['192.0.2.53; rm']; }],
            'mac malformed' => [static function (array &$p): void { $p['mac'] = 'aa:bb:cc'; }],
            'jobId with slash' => [static function (array &$p): void { $p['jobId'] = '../x'; }],
            'firmware uefi' => [static function (array &$p): void { $p['firmware'] = 'uefi'; }],
            'unknown layout' => [static function (array &$p): void { $p['layout'] = 'raid0'; }],
            'unknown top level key' => [static function (array &$p): void { $p['extra'] = 1; }],
            'wipeAuthorized as string' => [static function (array &$p): void { $p['wipeAuthorized'] = 'true'; }],
            'wipeAuthorized as int' => [static function (array &$p): void { $p['wipeAuthorized'] = 1; }],
            'root serial with space' => [static function (array &$p): void { $p['disks']['root']['serial'] = 'AB CD'; }],
            'root serial empty' => [static function (array &$p): void { $p['disks']['root']['serial'] = ''; }],
            'root size string' => [static function (array &$p): void { $p['disks']['root']['sizeBytes'] = '4000'; }],
            'one boot stick only' => [static function (array &$p): void { array_pop($p['disks']['boot']); }],
            'stick usbPath missing' => [static function (array &$p): void { $p['disks']['boot'][0]['usbPath'] = ''; }],
            'ignore vendor not hex' => [static function (array &$p): void { $p['ignore'] = [['usbVendorId' => 'zz99']]; }],
            'template sha256 short' => [static function (array &$p): void { $p['template']['sha256'] = 'abcd'; }],
            'template path traversal' => [static function (array &$p): void { $p['template']['path'] = '/run/../etc/passwd'; }],
            'template path relative' => [static function (array &$p): void { $p['template']['path'] = 'run/x.tar.gz'; }],
            'template url http' => [static function (array &$p): void { $p['template']['url'] = 'http://x.example/t.tar.gz'; }],
            'token too short' => [static function (array &$p): void { $p['token'] = 'abc'; }],
            'apiBase with quote' => [static function (array &$p): void { $p['apiBase'] = 'https://x.example/"'; }],
        ];
    }

    public function testBiosSingleRejectsBootSticks(): void
    {
        $this->assertRejects(static function (array &$p): void { $p['layout'] = 'biosSingle'; });
    }

    public function testHomeSplitOnlyWithBiosSingle(): void
    {
        $this->assertRejects(static function (array &$p): void { $p['homeSplit'] = true; });
    }

    public function testErrorMessagesNeverContainTheToken(): void
    {
        $raw = InstallFixtures::rawPlan();
        $raw['token'] = 'SECRET-TOKEN-VALUE-0123456789;';
        try {
            PlanValidator::validate($raw);
            $this->fail('accepted');
        } catch (InstallRefusal $e) {
            $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', $e->getMessage());
        }
    }

    public function testNonObjectPlanIsRefused(): void
    {
        $this->expectException(InstallRefusal::class);
        PlanValidator::validate('not a plan');
    }
}
