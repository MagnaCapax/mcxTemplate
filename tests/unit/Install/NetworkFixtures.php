<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use Lib\Install\HttpTransport;
use Lib\Install\Installer;
use Lib\Install\RecordingRunner;
use Lib\Install\SystemStateReader;

require_once __DIR__ . '/InstallFixtures.php';

// FakeNodeCore is a scripted nodeCore behind the HttpTransport interface: no socket is ever opened.
// Every request is recorded (decoded body plus the raw JSON) so tests can assert on order, seq numbers
// and on what a body may and may not contain. Responses per path are consumed in order; the last one repeats.
final class FakeNodeCore implements HttpTransport
{
    /** @var array<int,array{path:string,body:array<string,mixed>,raw:string}> */
    public array $requests = [];
    /** @var array<int,array{url:string,dest:string,max:int}> */
    public array $downloads = [];
    /** @var array<string,array<int,mixed>> path => list of responses (arrays or closures) */
    private array $script = [];
    public string $templateBytes;
    public string $downloadError = '';

    public function __construct(string $templateBytes)
    {
        $this->templateBytes = $templateBytes;
    }

    /** @param array<int,mixed> $responses */
    public function script(string $path, array $responses): void
    {
        $this->script[$path] = $responses;
    }

    public function post(string $url, string $json): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['path' => $path, 'body' => json_decode($json, true) ?? [], 'raw' => $json];
        $list = &$this->script[$path];
        if ($list === null || $list === []) {
            return self::ok();
        }
        $next = count($list) > 1 ? array_shift($list) : $list[0];
        return $next instanceof \Closure ? $next(end($this->requests)['body']) : $next;
    }

    public function download(string $url, string $dest, int $maxBytes): array
    {
        $this->downloads[] = ['url' => $url, 'dest' => $dest, 'max' => $maxBytes];
        if ($this->downloadError !== '') {
            return ['status' => 0, 'bytes' => 0, 'error' => $this->downloadError];
        }
        file_put_contents($dest, $this->templateBytes);
        return ['status' => 200, 'bytes' => strlen($this->templateBytes), 'error' => ''];
    }

    /** @return array{status:int,body:string,error:string} */
    public static function ok(array $extra = []): array
    {
        return ['status' => 200, 'body' => json_encode(['ok' => true] + $extra), 'error' => ''];
    }

    /** @return array{status:int,body:string,error:string} */
    public static function err(int $status, string $code): array
    {
        return ['status' => $status, 'body' => json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => 'x']]), 'error' => ''];
    }

    /** @return array{status:int,body:string,error:string} */
    public static function down(): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'curl7'];
    }

    /** @return array<int,array{path:string,body:array<string,mixed>,raw:string}> */
    public function to(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['path'] === $path));
    }

    /** @return array<int,string> the event names posted, in order */
    public function eventNames(): array
    {
        return array_map(static fn (array $r): string => (string) $r['body']['event'], $this->to('/install/event'));
    }
}

// NetworkWorld builds the temp "machine" a network install runs against: fake /proc and /sys, a fake
// target tree holding what a successful configure leaves behind, readers that answer from the
// InstallFixtures disks, and a recording writer. Nothing real is ever run.
final class NetworkWorld
{
    public const TEMPLATE = 'pretend-template-archive';
    public const URL = 'https://files.example.test/templates/debian-12-base-20261111.tar.gz';
    public const API = 'https://nodecore.example.test/';

    public string $dir;
    public RecordingRunner $writer;
    public CallbackRunner $reader;
    public FakeNodeCore $server;
    /** @var array<int,string> */
    public array $console = [];
    /** @var array<int,string> lines the NodeCoreClient wrote to its log sink */
    public array $log = [];

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/mcx-net-' . bin2hex(random_bytes(4));
        foreach (['/sys/class/net/eth0/device', '/proc/self', '/target/etc/mdadm', '/target/boot/grub'] as $sub) {
            mkdir($this->dir . $sub, 0755, true);
        }
        file_put_contents($this->dir . '/sys/class/net/eth0/address', InstallFixtures::MAC . "\n");
        file_put_contents($this->dir . '/proc/self/mountinfo', '');
        file_put_contents($this->dir . '/proc/swaps', "Filename Type Size Used Priority\n");
        file_put_contents($this->dir . '/target/boot/vmlinuz-6.1.0-test', 'kernel');
        file_put_contents($this->dir . '/target/boot/initrd.img-6.1.0-test', 'initrd');
        file_put_contents($this->dir . '/target/boot/grub/grub.cfg', "menuentry x {}\n");
        $uuid = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
        file_put_contents($this->dir . '/target/etc/fstab', 'UUID=' . $uuid(3) . " / ext4 defaults 0 1\nUUID=" . $uuid(1)
            . " /boot ext4 defaults 0 2\nUUID=" . $uuid(2) . " none swap sw 0 0\n");
        file_put_contents($this->dir . '/target/etc/mdadm/mdadm.conf', "ARRAY /dev/md/mcxboot metadata=1.2 name=rescue:mcxboot UUID=1:2:3:4\n");

        $this->server = new FakeNodeCore(self::TEMPLATE);
        $this->server->script('/boot/hello', [FakeNodeCore::ok(['plan' => $this->rawPlan()])]);
        $this->server->script('/install/result', [FakeNodeCore::ok(['ack' => true])]);
        $this->writer = new RecordingRunner();
        $this->writer->addResponse(['chroot', '/mnt/target', 'php'], "tasks ran\n" . Installer::CONFIGURE_SENTINEL . "\n");
        $this->reader = $this->makeReader();
    }

    public function cleanup(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array<string,mixed> the plan nodeCore sends (template.path deliberately hostile: it must be ignored) */
    public function rawPlan(array $over = []): array
    {
        $raw = InstallFixtures::rawPlan($over);
        $raw['template'] = ['name' => 'debian-12-base', 'version' => '20261111', 'sha256' => hash('sha256', self::TEMPLATE),
            'sizeBytes' => strlen(self::TEMPLATE), 'url' => self::URL, 'path' => '/etc/passwd'];
        $raw['token'] = 'tok-SECRETTOKEN-0123456789';
        unset($raw['apiBase']);
        return $raw;
    }

    public function installer(): Installer
    {
        return new Installer($this->reader, $this->writer, $this->state(), InstallFixtures::counterUuid(), $this->dir . '/target', $this->dir);
    }

    public function state(): SystemStateReader
    {
        return new SystemStateReader($this->dir . '/proc', $this->dir . '/sys');
    }

    public function stageDir(): string
    {
        return $this->dir . '/mcxTemplate-stage-job-1';
    }

    private function makeReader(): CallbackRunner
    {
        $inventory = InstallFixtures::inventory();
        $uuid = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
        $sector = str_pad(str_repeat("\0", 384) . "GRUB \0Geom\0Hard Disk\0Read\0 Error", 510, "\0") . "\x55\xAA";
        return new CallbackRunner(function (array $argv) use ($inventory, $uuid, $sector): array {
            $ok = static fn (string $out): array => ['code' => 0, 'out' => $out, 'err' => ''];
            if ($argv[0] === 'lsblk') {
                $rows = [];
                foreach ($inventory as $d) {
                    if (isset($argv[6]) && $argv[6] !== $d['path']) {
                        continue;
                    }
                    $rows[] = ['name' => $d['kname'], 'kname' => $d['kname'], 'type' => 'disk', 'size' => $d['sizeBytes'], 'serial' => $d['serial'],
                        'wwn' => $d['wwn'], 'model' => $d['model'], 'tran' => $d['tran'], 'rm' => $d['removable'], 'hotplug' => $d['hotplug'], 'pttype' => null];
                }
                return $ok((string) json_encode(['blockdevices' => $rows]));
            }
            if ($argv[0] === 'udevadm') {
                foreach ($inventory as $d) {
                    if ('--name=' . $d['path'] === $argv[3]) {
                        return $ok('ID_PATH=' . $d['idPath'] . "\nID_VENDOR_ID=" . $d['usbVendorId'] . "\n");
                    }
                }
            }
            if ($argv[0] === 'tar' && in_array('-xzOf', $argv, true)) {
                return $ok("NAME=x\nPRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\n");
            }
            if ($argv[0] === 'tar') {
                return $ok("rootfs/\nrootfs/etc/os-release\nrootfs/opt/mcxTemplate/installTemplate.php\n");
            }
            if ($argv[0] === 'findmnt') {
                return ['code' => 1, 'out' => '', 'err' => ''];
            }
            if ($argv[0] === 'blkid') {
                return $ok($uuid(1) . "\n" . $uuid(2) . "\n" . $uuid(3) . "\nmd-member-uuid\n");
            }
            if ($argv[0] === 'dd') {
                return $ok($sector);
            }
            return $ok('');
        });
    }
}
