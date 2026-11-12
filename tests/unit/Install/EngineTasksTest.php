<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

// EngineTasksTest runs the install-time tasks (resolvers, netplan, engine removal) as real child PHP
// processes against a throw-away tree. MCX_TARGET_ROOT points them at that tree, so nothing outside the
// temp directory can be read or written; posix_geteuid is disabled in the child so a non-root user passes
// Common::ensureRoot() (the same trick StrictModeTest uses).
final class EngineTasksTest extends TestCase
{
    private const TASKS = [
        'resolvers' => 'distros/debian/common/tasks/23-render-resolvers.php',
        'cleanup' => 'distros/debian/common/tasks/90-remove-engine.php',
        'netplan' => 'distros/ubuntu/common/tasks/22-render-netplan.php',
        'boot' => 'distros/debian/common/tasks/40-update-boot.php',
        'mdadm' => 'distros/debian/common/tasks/32-capture-mdadm.php',
    ];
    private string $base = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mcx-task-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/target';
        mkdir($this->base . '/ini', 0700, true);
        file_put_contents($this->base . '/ini/mcx.ini', "disable_functions=posix_geteuid\n");
        mkdir($this->root . '/etc/netplan', 0755, true);
        $this->installEngine($this->root . '/opt/mcxTemplate');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    // installEngine copies the helper library and the three tasks into an engine directory.
    private function installEngine(string $engine): void
    {
        $repo = realpath(__DIR__ . '/../../..');
        foreach (['distros/common/lib', 'src/Lib/Provisioning', 'distros/debian/common/tasks', 'distros/ubuntu/common/tasks'] as $dir) {
            mkdir($engine . '/' . $dir, 0755, true);
        }
        foreach (['distros/common/lib/Common.php', 'src/Lib/Provisioning/Common.php'] as $file) {
            copy($repo . '/' . $file, $engine . '/' . $file);
        }
        foreach (self::TASKS as $file) {
            copy($repo . '/' . $file, $engine . '/' . $file);
        }
    }

    /**
     * @param array<string,string> $env
     * @return array{0:int,1:string}
     */
    private function runTask(string $task, array $env, ?string $engine = null): array
    {
        $engine = $engine ?? $this->root . '/opt/mcxTemplate';
        $env += ['USER' => 'root', 'HOME' => $this->base, 'PATH' => (string) getenv('PATH'), 'PHP_INI_SCAN_DIR' => ':' . $this->base . '/ini',
            'MCX_TEMPLATE_ROOT' => $engine, 'MCX_TARGET_ROOT' => $this->root, 'MCX_LOG_DIR' => $this->base . '/logs'];
        $p = proc_open([PHP_BINARY, $engine . '/' . self::TASKS[$task]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->base, $env);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }

    private function resolv(): string
    {
        return $this->root . '/etc/resolv.conf';
    }

    public function testResolversAreWrittenAsNameserverLines(): void
    {
        [$rc, $out] = $this->runTask('resolvers', ['MCX_RESOLVERS' => '198.51.100.2, 2001:db8::53']);
        $this->assertSame(0, $rc, $out);
        $this->assertSame("nameserver 198.51.100.2\nnameserver 2001:db8::53\n", file_get_contents($this->resolv()));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->resolv())), -4));
    }

    public function testASymlinkedResolvConfIsReplacedNotWrittenThrough(): void
    {
        mkdir($this->base . '/outside');
        file_put_contents($this->base . '/outside/stub', "nameserver 10.0.0.53\n");
        symlink($this->base . '/outside/stub', $this->resolv());
        [$rc, $out] = $this->runTask('resolvers', ['MCX_RESOLVERS' => '198.51.100.2']);
        $this->assertSame(0, $rc, $out);
        $this->assertFalse(is_link($this->resolv()));
        $this->assertSame("nameserver 198.51.100.2\n", file_get_contents($this->resolv()));
        $this->assertSame("nameserver 10.0.0.53\n", file_get_contents($this->base . '/outside/stub'), 'the link target was not touched');
    }

    public function testNoResolversSkipsUnlessStrict(): void
    {
        file_put_contents($this->resolv(), "nameserver 192.0.2.1\n");
        [$rc, $out] = $this->runTask('resolvers', []);
        $this->assertSame(0, $rc, $out);
        $this->assertSame("nameserver 192.0.2.1\n", file_get_contents($this->resolv()), 'left as it was');

        [$rc, $out] = $this->runTask('resolvers', ['MCX_STRICT' => '1']);
        $this->assertSame(1, $rc, 'strict mode fails on a missing list');
        $this->assertStringContainsString('strict mode', $out);
        $this->assertSame("nameserver 192.0.2.1\n", file_get_contents($this->resolv()));
    }

    /** @return array<string,array{0:string}> */
    public static function badResolvers(): array
    {
        return ['name instead of address' => ['dns.example.test'], 'one bad entry' => ['198.51.100.2,nope'], 'four entries' => ['192.0.2.1,192.0.2.2,192.0.2.3,192.0.2.4'],
            'injected line' => ["198.51.100.2\nnameserver 203.0.113.66"], 'option smuggling' => ['198.51.100.2 options attempts:1']];
    }

    /** @dataProvider badResolvers */
    public function testAnInvalidListAlwaysFailsAndLeavesTheFileAlone(string $list): void
    {
        file_put_contents($this->resolv(), "nameserver 192.0.2.1\n");
        [$rc] = $this->runTask('resolvers', ['MCX_RESOLVERS' => $list]);
        $this->assertSame(1, $rc);
        $this->assertSame("nameserver 192.0.2.1\n", file_get_contents($this->resolv()));
    }

    public function testCleanupRemovesTheEngineAndNothingElse(): void
    {
        $engine = $this->root . '/opt/mcxTemplate';
        mkdir($this->root . '/home/user', 0755, true);
        mkdir($this->root . '/opt/other');
        mkdir($this->base . '/outside');
        file_put_contents($this->root . '/home/user/data', 'customer');
        file_put_contents($this->root . '/opt/other/file', 'keep');
        file_put_contents($this->base . '/outside/keep', 'outside');
        symlink($this->base . '/outside', $engine . '/escape');   // a link out of the engine: unlinked, never entered
        symlink($this->base . '/outside/keep', $engine . '/escapefile');

        [$rc, $out] = $this->runTask('cleanup', ['MCX_REMOVE_ENGINE' => '1']);
        $this->assertSame(0, $rc, $out);
        $this->assertDirectoryDoesNotExist($engine);
        $this->assertSame('customer', file_get_contents($this->root . '/home/user/data'));
        $this->assertSame('keep', file_get_contents($this->root . '/opt/other/file'));
        $this->assertSame('outside', file_get_contents($this->base . '/outside/keep'), 'a symlink out of the engine was not followed');
    }

    public function testCleanupDoesNothingWithoutTheOptIn(): void
    {
        [$rc, $out] = $this->runTask('cleanup', []);
        $this->assertSame(0, $rc, $out);
        $this->assertFileExists($this->root . '/opt/mcxTemplate/distros/common/lib/Common.php');
        $this->assertStringContainsString('MCX_REMOVE_ENGINE is not set', $out);
    }

    public function testCleanupOnlyRemovesTheEngineItIsRunningFrom(): void
    {
        // The task runs from another engine copy; the target's /opt/mcxTemplate is not the running one.
        $running = $this->base . '/elsewhere/mcxTemplate';
        $this->installEngine($running);
        [$rc, $out] = $this->runTask('cleanup', ['MCX_REMOVE_ENGINE' => '1'], $running);
        $this->assertSame(0, $rc, $out);
        $this->assertFileExists($this->root . '/opt/mcxTemplate/distros/common/lib/Common.php', 'the target engine stays');
        $this->assertFileExists($running . '/distros/common/lib/Common.php', 'the running engine stays');
        $this->assertStringContainsString('nothing removed', $out);
    }

    public function testCleanupRefusesASymlinkedEngineDirectory(): void
    {
        $real = $this->base . '/real/mcxTemplate';
        $this->installEngine($real);
        $this->removeTree($this->root . '/opt/mcxTemplate');
        symlink($real, $this->root . '/opt/mcxTemplate');
        [$rc, $out] = $this->runTask('cleanup', ['MCX_REMOVE_ENGINE' => '1'], $real);
        $this->assertSame(0, $rc, $out);
        $this->assertFileExists($real . '/distros/common/lib/Common.php', 'a symlinked /opt/mcxTemplate is not followed');
        $this->assertTrue(is_link($this->root . '/opt/mcxTemplate'));
    }

    private function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function testNetplanFileHasTheAddressRouteAndResolversAndIsPrivate(): void
    {
        [$rc, $out] = $this->runTask('netplan', ['MCX_NETWORK_CIDR' => '192.0.2.10/24', 'MCX_GATEWAY' => '192.0.2.1', 'MCX_PRIMARY_INTERFACE' => 'eno1',
            'MCX_RESOLVERS' => '198.51.100.2,198.51.100.3']);
        $this->assertSame(0, $rc, $out);
        $file = $this->root . '/etc/netplan/01-mcx.yaml';
        $this->assertSame("network:\n  version: 2\n  ethernets:\n    eno1:\n      addresses: [192.0.2.10/24]\n      routes:\n        - to: default\n"
            . "          via: 192.0.2.1\n      nameservers:\n        addresses: [198.51.100.2, 198.51.100.3]\n", file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function testNetplanWithoutResolversOmitsTheNameserverBlock(): void
    {
        [$rc, $out] = $this->runTask('netplan', ['MCX_NETWORK_CIDR' => '192.0.2.10/24', 'MCX_GATEWAY' => '192.0.2.1', 'MCX_PRIMARY_INTERFACE' => 'eno1']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringNotContainsString('nameservers', (string) file_get_contents($this->root . '/etc/netplan/01-mcx.yaml'));
    }

    public function testNetplanSkipsWithoutAddressUnlessStrictAndRefusesBadInput(): void
    {
        [$rc] = $this->runTask('netplan', ['MCX_PRIMARY_INTERFACE' => 'eno1']);
        $this->assertSame(0, $rc);
        $this->assertFileDoesNotExist($this->root . '/etc/netplan/01-mcx.yaml');
        [$rc] = $this->runTask('netplan', ['MCX_PRIMARY_INTERFACE' => 'eno1', 'MCX_STRICT' => '1']);
        $this->assertSame(1, $rc);
        foreach ([['192.0.2.10/99x', '192.0.2.1', 'eno1'], ['192.0.2.10/33', '192.0.2.1', 'eno1'], ['192.0.2.10/24', '192.0.2.1; reboot', 'eno1'], ['192.0.2.10/24', '192.0.2.1', "eno1\n    evil: 1"]] as [$cidr, $gw, $if]) {
            [$rc] = $this->runTask('netplan', ['MCX_NETWORK_CIDR' => $cidr, 'MCX_GATEWAY' => $gw, 'MCX_PRIMARY_INTERFACE' => $if]);
            $this->assertSame(1, $rc, $cidr . ' ' . $gw);
            $this->assertFileDoesNotExist($this->root . '/etc/netplan/01-mcx.yaml');
        }
    }

    public function testUbuntuReusesTheDebianTasksBySymlinkAndReplacesOnlyTheNetworkTask(): void
    {
        $repo = realpath(__DIR__ . '/../../..');
        $debian = array_map('basename', glob($repo . '/distros/debian/common/tasks/*.php') ?: []);
        $ubuntu = array_map('basename', glob($repo . '/distros/ubuntu/common/tasks/*.php') ?: []);
        $expected = array_merge(array_diff($debian, ['22-render-network-config.php']), ['22-render-netplan.php']);
        sort($expected);
        sort($ubuntu);
        $this->assertSame($expected, $ubuntu);
        foreach ($ubuntu as $name) {
            $path = $repo . '/distros/ubuntu/common/tasks/' . $name;
            if ($name === '22-render-netplan.php') {
                $this->assertFalse(is_link($path));
                continue;
            }
            $this->assertTrue(is_link($path), $name . ' is a symlink');
            $this->assertSame(realpath($repo . '/distros/debian/common/tasks/' . $name), realpath($path), $name);
        }
        $this->assertSame(realpath($repo . '/distros/debian/templates'), realpath($repo . '/distros/ubuntu/templates'));
        $this->assertSame('90-remove-engine.php', max($debian), 'the engine removal is the last task by number');
    }

    public function testConfigureDryRunForUbuntuListsTheNetplanTaskAndGatesTheEngineRemoval(): void
    {
        $repo = realpath(__DIR__ . '/../../..');
        $run = function (array $env) use ($repo): array {
            $argv = [PHP_BINARY, '-d', 'disable_functions=posix_geteuid', $repo . '/distros/configure.php', '--dry-run', '--distro=ubuntu', '--hostname=t.example.test',
                '--mount=/,/dev/sda1', '--log-dir=' . $this->base . '/logs'];
            $p = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->base, $env + ['USER' => 'root', 'PATH' => (string) getenv('PATH'), 'HOME' => $this->base]);
            $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($p), $out];
        };
        [$rc, $out] = $run([]);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('would execute 22-render-netplan.php', $out);
        $this->assertStringContainsString('would execute 23-render-resolvers.php', $out);
        $this->assertStringNotContainsString('22-render-network-config', $out);
        $this->assertStringNotContainsString('would execute 90-remove-engine.php', $out, 'without MCX_REMOVE_ENGINE the precondition skips it');
        [$rc, $out] = $run(['MCX_REMOVE_ENGINE' => '1']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('would execute 90-remove-engine.php', $out);
    }
    // bootStubs puts update-initramfs, update-grub and grub-install stubs on a private PATH (each exits with
    // the given status), so task 40 never reaches a real boot tool. Returns the env the task runs with.
    /**
     * @param array<string,int> $status
     * @return array<string,string>
     */
    private function bootStubs(array $status): array
    {
        $bin = $this->base . '/stubbin';
        mkdir($bin, 0700, true);
        foreach (['update-initramfs', 'update-grub', 'grub-install'] as $cmd) {
            file_put_contents($bin . '/' . $cmd, "#!/bin/sh\necho stub $cmd >&2\nexit " . ($status[$cmd] ?? 0) . "\n");
            chmod($bin . '/' . $cmd, 0700);
        }
        touch($this->base . '/bootdisk');
        return ['PATH' => $bin . ':/bin:/usr/bin', 'GRUB_TARGETS' => $this->base . '/bootdisk'];
    }

    public function testBootTaskFailsInStrictModeWhenGrubInstallFails(): void
    {
        [$rc, $out] = $this->runTask('boot', $this->bootStubs(['grub-install' => 1]) + ['MCX_STRICT' => '1']);
        $this->assertNotSame(0, $rc, $out);
        $this->assertStringContainsString('grub-install ' . $this->base . '/bootdisk failed and strict mode is on', $out);
        $this->assertStringNotContainsString('Task 40 complete', $out);
    }

    public function testBootTaskFailsInStrictModeWhenUpdateGrubFails(): void
    {
        [$rc, $out] = $this->runTask('boot', $this->bootStubs(['update-grub' => 1]) + ['MCX_STRICT' => '1']);
        $this->assertNotSame(0, $rc, $out);
        $this->assertStringContainsString('update-grub failed and strict mode is on', $out);
    }

    public function testBootTaskKeepsWarningOnlyBehaviourWithoutStrict(): void
    {
        [$rc, $out] = $this->runTask('boot', $this->bootStubs(['update-initramfs' => 1, 'update-grub' => 1, 'grub-install' => 1]));
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('Task 40 complete', $out);
    }

    // mdadmRun puts an mdadm stub that prints one scan line on a private PATH, writes $existing as the target's
    // mdadm.conf (null = no file) and runs task 32. Returns [exit code, output, resulting mdadm.conf].
    /**
     * @return array{0:int,1:string,2:string}
     */
    private function mdadmRun(?string $existing): array
    {
        $bin = $this->base . '/mdbin';
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/mdadm', "#!/bin/sh\necho 'ARRAY /dev/md/mcxboot metadata=0.90 UUID=01234567:89abcdef:01234567:89abcdef'\n");
        chmod($bin . '/mdadm', 0700);
        mkdir($this->root . '/etc/mdadm', 0755, true);
        if ($existing !== null) {
            file_put_contents($this->root . '/etc/mdadm/mdadm.conf', $existing);
        }
        [$rc, $out] = $this->runTask('mdadm', ['PATH' => $bin . ':/bin:/usr/bin']);
        return [$rc, $out, (string) @file_get_contents($this->root . '/etc/mdadm/mdadm.conf')];
    }

    public function testMdadmCaptureAddsMailaddrSoMdmonitorCanStart(): void
    {
        [$rc, $out, $conf] = $this->mdadmRun(null);
        $this->assertSame(0, $rc, $out);
        $this->assertSame("MAILADDR root\nARRAY /dev/md/mcxboot metadata=0.90 UUID=01234567:89abcdef:01234567:89abcdef\n", $conf);
    }

    public function testMdadmCaptureKeepsExistingSettingsAndReplacesOnlyArrayLines(): void
    {
        [$rc, $out, $conf] = $this->mdadmRun("HOMEHOST <system>\nMAILADDR ops@example.org\nARRAY /dev/md/old metadata=1.2 UUID=ffffffff:ffffffff:ffffffff:ffffffff\n");
        $this->assertSame(0, $rc, $out);
        $this->assertSame("HOMEHOST <system>\nMAILADDR ops@example.org\nARRAY /dev/md/mcxboot metadata=0.90 UUID=01234567:89abcdef:01234567:89abcdef\n", $conf);
    }

    public function testMdadmCaptureDropsTheContinuationLinesOfAnOldArray(): void
    {
        [$rc, $out, $conf] = $this->mdadmRun("ARRAY /dev/md/old metadata=1.2\n   UUID=ffffffff:ffffffff:ffffffff:ffffffff\nMAILADDR root\n");
        $this->assertSame(0, $rc, $out);
        $this->assertSame("MAILADDR root\nARRAY /dev/md/mcxboot metadata=0.90 UUID=01234567:89abcdef:01234567:89abcdef\n", $conf);
    }

    public function testMdadmCaptureKeepsAProgramAlertWithoutAddingMailaddr(): void
    {
        [$rc, $out, $conf] = $this->mdadmRun("PROGRAM /usr/local/sbin/md-alert\n");
        $this->assertSame(0, $rc, $out);
        $this->assertStringNotContainsString('MAILADDR', $conf);
        $this->assertStringStartsWith("PROGRAM /usr/local/sbin/md-alert\nARRAY /dev/md/mcxboot", $conf);
    }

    public function testBootTaskSucceedsInStrictModeWhenEveryCommandSucceeds(): void
    {
        [$rc, $out] = $this->runTask('boot', $this->bootStubs([]) + ['MCX_STRICT' => '1']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('stub grub-install', $out);
        $this->assertStringContainsString('Task 40 complete', $out);
    }
}
