<?php
declare(strict_types=1);

namespace Lib\Install;

// SystemStateReader collects what DiskMatcher needs to judge "in use": mounts,
// swaps, md/dm members, partitions, the rescue medium and local NIC MACs.
// Read-only. /proc and /sys roots are parameters so tests can use a fixture tree.
//
// State shape (all kernel names, no /dev prefix):
//   mounted    list of kernel names that are mounted
//   swapped    list of kernel names used as swap
//   md         map mdName => list of member kernel names
//   dm         map dmName => list of member kernel names
//   holders    map name => list of devices stacked on it (/sys/block/<dev>[/<part>]/holders:
//              md, dm, bcache and anything nested); devices with no holders are left out
//   partitions map diskKname => list of its partition kernel names
//   rescueDisk kernel name of the disk holding the rescue medium, or ''
//   nicMacs    list of lowercase MACs of physical NICs
final class SystemStateReader
{
    private string $proc;
    private string $sys;

    public function __construct(string $procRoot = '/proc', string $sysRoot = '/sys')
    {
        $this->proc = rtrim($procRoot, '/');
        $this->sys = rtrim($sysRoot, '/');
    }

    /**
     * @param array<int,string> $diskKnames kernel names of the enumerated disks
     * @return array<string,mixed>
     */
    public function read(CommandRunner $runner, array $diskKnames): array
    {
        $state = [
            'mounted' => $this->mounted(),
            'swapped' => $this->swapped(),
            'md' => $this->slaves('md*'),
            'dm' => $this->slaves('dm-*'),
            'holders' => $this->holders(),
            'partitions' => [],
            'rescueDisk' => '',
            'nicMacs' => $this->nicMacs(),
        ];
        foreach ($diskKnames as $disk) {
            $state['partitions'][$disk] = $this->partitionsOf($disk);
        }
        $state['rescueDisk'] = $this->rescueDisk($runner, $state['partitions']);
        return $state;
    }

    /** @return array<int,string> */
    private function mounted(): array
    {
        $names = [];
        foreach ($this->lines($this->proc . '/self/mountinfo') as $line) {
            // "... - fstype source options": the source follows the separator.
            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $fields = explode(' ', $parts[1]);
            $kname = $this->kname($fields[1] ?? '');
            if ($kname !== '') {
                $names[$kname] = true;
            }
        }
        return array_keys($names);
    }

    /** @return array<int,string> */
    private function swapped(): array
    {
        $names = [];
        foreach (array_slice($this->lines($this->proc . '/swaps'), 1) as $line) {
            $kname = $this->kname(preg_split('/\s+/', $line)[0] ?? '');
            if ($kname !== '') {
                $names[$kname] = true;
            }
        }
        return array_keys($names);
    }

    /** @return array<string,array<int,string>> */
    private function slaves(string $glob): array
    {
        $map = [];
        foreach (glob($this->sys . '/block/' . $glob, GLOB_ONLYDIR) ?: [] as $dir) {
            $members = [];
            foreach (glob($dir . '/slaves/*') ?: [] as $slave) {
                $members[] = basename($slave);
            }
            $map[basename($dir)] = $members;
        }
        return $map;
    }

    /** @return array<string,array<int,string>> */
    private function holders(): array
    {
        $map = [];
        // Whole devices and their partitions; a non-empty holders directory means something is stacked on it.
        $dirs = array_merge(glob($this->sys . '/block/*/holders', GLOB_ONLYDIR) ?: [], glob($this->sys . '/block/*/*/holders', GLOB_ONLYDIR) ?: []);
        foreach ($dirs as $dir) {
            $names = array_map('basename', glob($dir . '/*') ?: []);
            if ($names !== []) {
                sort($names);
                $map[basename(dirname($dir))] = $names;
            }
        }
        ksort($map);
        return $map;
    }

    /** @return array<int,string> */
    private function partitionsOf(string $disk): array
    {
        $parts = [];
        foreach (glob($this->sys . '/block/' . $disk . '/*/partition') ?: [] as $file) {
            $parts[] = basename(dirname($file));
        }
        sort($parts);
        return $parts;
    }

    /** @return array<int,string> */
    private function nicMacs(): array
    {
        $macs = [];
        foreach (glob($this->sys . '/class/net/*') ?: [] as $nic) {
            // Only NICs backed by a device: dummy, bridge and veth interfaces are not hardware evidence.
            if (!file_exists($nic . '/device')) {
                continue;
            }
            $mac = strtolower(trim((string) @file_get_contents($nic . '/address')));
            if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $mac) === 1 && $mac !== '00:00:00:00:00:00') {
                $macs[$mac] = true;
            }
        }
        return array_keys($macs);
    }

    /**
     * @param array<string,array<int,string>> $partitions
     */
    private function rescueDisk(CommandRunner $runner, array $partitions): string
    {
        $result = $runner->run(['findmnt', '-n', '-o', 'SOURCE', '--target', '/run/live/medium']);
        $source = trim($result['out']);
        if ($result['code'] !== 0 || $source === '') {
            return '';
        }
        $kname = $this->kname($source);
        foreach ($partitions as $disk => $parts) {
            if ($kname === $disk || in_array($kname, $parts, true)) {
                return $disk;
            }
        }
        return $kname; // unknown device: still reported so a name match can refuse
    }

    // kname maps /dev/... (also symlinks such as by-id or mapper) to its kernel name; '' if not a device path.
    private function kname(string $source): string
    {
        if (strncmp($source, '/dev/', 5) !== 0) {
            return '';
        }
        $real = @realpath($source);
        return basename($real !== false ? $real : $source);
    }

    /** @return array<int,string> */
    private function lines(string $file): array
    {
        $text = @file_get_contents($file);
        return $text === false ? [] : array_values(array_filter(explode("\n", $text), static fn (string $l): bool => $l !== ''));
    }
}
