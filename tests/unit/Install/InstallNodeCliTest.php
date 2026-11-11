<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\DiskMatcher;
use Lib\Install\LayoutPlanner;
use Lib\Install\PlanPrinter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallFixtures.php';

// InstallNodeCliTest covers the entry point's refusals and the dry-run report.
// Every case here stops before any write: --execute is only ever combined with
// wipeAuthorized=false, a missing or unusable --secret-out, a non-root run, or a
// plan that cannot validate. The input checks run before the root check, so each
// refusal below is the one specific code its check produces.
final class InstallNodeCliTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcx-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,ini/}*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/ini');
        @rmdir($this->dir);
    }

    /** @return array{0:int,1:string,2:string} */
    private function runCli(array $args, ?array $env = null): array
    {
        $argv = array_merge([PHP_BINARY, realpath(__DIR__ . '/../../../installNode.php')], $args);
        $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    private function planFile(array $raw): string
    {
        $file = $this->dir . '/plan.json';
        file_put_contents($file, json_encode($raw));
        return '--plan=' . $file;
    }

    public function testNoArgumentsIsAUsageError(): void
    {
        [$code, , $err] = $this->runCli([]);
        $this->assertSame(64, $code);
        $this->assertStringContainsString('Usage:', $err);
    }

    public function testUnknownFlagIsAUsageError(): void
    {
        [$code] = $this->runCli([$this->planFile(InstallFixtures::rawPlan()), '--force']);
        $this->assertSame(64, $code);
    }

    public function testInvalidPlanIsRefusedWithANamedCode(): void
    {
        $raw = InstallFixtures::rawPlan();
        $raw['host']['fqdn'] = 'a.example;reboot';
        [$code, , $err] = $this->runCli([$this->planFile($raw)]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('REFUSED planInvalid', $err);
    }

    public function testMissingPlanFileIsRefused(): void
    {
        [$code, , $err] = $this->runCli(['--plan=' . $this->dir . '/none.json']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('REFUSED planInvalid', $err);
    }

    public function testExecuteWithoutWipeAuthorizedIsRefused(): void
    {
        $raw = InstallFixtures::rawPlan(['wipeAuthorized' => false]);
        [$code, $out, $err] = $this->runCli([$this->planFile($raw), '--execute', '--secret-out=' . $this->dir . '/s']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED wipeNotAuthorized:/m', $err);
        $this->assertStringNotContainsString('step:', $out);
        $this->assertFileDoesNotExist($this->dir . '/s');
    }

    public function testExecuteWithoutSecretOutIsRefused(): void
    {
        [$code, $out, $err] = $this->runCli([$this->planFile(InstallFixtures::rawPlan()), '--execute']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED secretOutRequired:/m', $err);
        $this->assertStringNotContainsString('step:', $out);
    }

    public function testExecuteWithSecretOutUnderTheTargetIsRefused(): void
    {
        [$code, $out, $err] = $this->runCli([$this->planFile(InstallFixtures::rawPlan()), '--execute', '--secret-out=/mnt/target/root.secret']);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/^REFUSED secretOutInvalid: --secret-out must not be under the target mount point/m', $err);
        $this->assertStringNotContainsString('step:', $out);
    }

    public function testExecuteWithoutRootIsRefused(): void
    {
        // posix_geteuid is disabled for this child, so the root check fails whoever runs the suite;
        // every input check before it passes (wipeAuthorized=true, a usable --secret-out).
        mkdir($this->dir . '/ini', 0700);
        file_put_contents($this->dir . '/ini/mcx.ini', "disable_functions=posix_geteuid\n");
        $env = array_merge(getenv(), ['PHP_INI_SCAN_DIR' => ':' . $this->dir . '/ini']);
        [$code, $out, $err] = $this->runCli([$this->planFile(InstallFixtures::rawPlan()), '--execute', '--secret-out=' . $this->dir . '/s'], $env);
        $this->assertSame(2, $code, $err);
        $this->assertMatchesRegularExpression('/^REFUSED notRoot:/m', $err);
        $this->assertStringNotContainsString('step:', $out);
        $this->assertFileDoesNotExist($this->dir . '/s');
    }

    public function testDryRunOnTheWrongBoxRefusesAndPrintsNoCommands(): void
    {
        // The plan MAC cannot belong to this machine, so the matcher (or, without lsblk, the inventory) refuses.
        $raw = InstallFixtures::rawPlan(['mac' => '02:00:00:de:ad:01']);
        [$code, $out, $err] = $this->runCli([$this->planFile($raw)]);
        $this->assertSame(2, $code);
        $this->assertMatchesRegularExpression('/REFUSED (nodeIdentityMismatch|inventoryFailed)/', $err);
        $this->assertStringNotContainsString('Commands, in order', $out);
    }

    public function testDryRunReportListsDeviceMapAndOrderedCommandsWithoutSecrets(): void
    {
        $plan = InstallFixtures::plan();
        $match = DiskMatcher::match($plan, InstallFixtures::inventory(), InstallFixtures::state());
        $steps = LayoutPlanner::plan($plan, $match, InstallFixtures::counterUuid());
        $text = PlanPrinter::render($plan, ['match' => $match, 'steps' => $steps], false);

        $this->assertStringContainsString('DRY RUN (nothing is touched)', $text);
        $this->assertStringContainsString('root   /dev/nvme0n1', $text);
        $this->assertStringContainsString('boot0  /dev/sda', $text);
        $this->assertStringContainsString('ignored /dev/sdc (kvmVirtualMedia)', $text);
        $this->assertStringContainsString("[DESTRUCTIVE] 'wipefs' '-a' '/dev/nvme0n1'", $text);
        $this->assertStringContainsString("GRUB_TARGETS='/dev/disk/by-path/", $text);
        $this->assertStringContainsString('root:<SHA-512 hash of a generated password>', $text);
        $this->assertLessThan(strpos($text, "'tar' '-xpzf'"), strpos($text, "'mkfs.ext4'"), 'format comes before extract');
        $this->assertStringNotContainsString('tok-0123456789abcdef', $text, 'the one-time token must not be printed');
        $this->assertStringNotContainsString('api.example.test', $text);
    }
}
