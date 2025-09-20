<?php
declare(strict_types=1);

namespace Lib\Provisioning;

// Configurator groups the helper methods that power distros/configure.php so tests can exercise them directly.
class Configurator
{
    /**
     * Parse argv into recognised long options and positional arguments.
     *
     * @return array{options: array<string,string>, positionals: array<int,string>, multi: array<string,array<int,string>>, help: bool}
     */
    public static function parseArguments(array $arguments): array
    {
        $options = [];
        $positionals = [];
        $multi = [
            'mount' => [],
        ];
        $help = false;
        $known = [
            'distro',
            'version',
            'hostname',
            'host-ip',
            'network-cidr',
            'gateway',
            'post-config',
            'post-config-sha256',
            'ssh-keys-uri',
            'ssh-keys-sha256',
            'root-device',
            'home-device',
            'log-dir',
            'skip-tasks',
            'hosts-template',
            'mount',
            'dry-run',
            'primary-interface',
        ];

        while ($arguments !== []) {
            $current = array_shift($arguments);

            if ($current === '--help' || $current === '-h') {
                $help = true;
                continue;
            }

            if ($current === '--') {
                $positionals = array_merge($positionals, $arguments);
                break;
            }

            if (strncmp($current, '--', 2) !== 0) {
                $positionals[] = $current;
                continue;
            }

            $eqPos = strpos($current, '=');
            if ($eqPos !== false) {
                $name = substr($current, 2, $eqPos - 2);
                $value = substr($current, $eqPos + 1);
            } else {
                $name = substr($current, 2);
                if ($arguments !== [] && strncmp((string) $arguments[0], '--', 2) !== 0) {
                    $value = array_shift($arguments);
                } else {
                    $value = '';
                }
            }

            if (!in_array($name, $known, true)) {
                Common::logWarn("Ignoring unknown option --{$name}.", ['option' => $name]);
                continue;
            }

            if ($name === 'mount') {
                $multi['mount'][] = $value;
                continue;
            }

            if ($name === 'dry-run' && $value === '') {
                $options[$name] = '1';
                continue;
            }

            $options[$name] = $value;
        }

        return [
            'options' => $options,
            'positionals' => $positionals,
            'multi' => $multi,
            'help' => $help,
        ];
    }

    /**
     * Parse a --mount definition of the form mountpoint,device[,type[,options]].
     */
    public static function parseMountOption(string $spec): ?array
    {
        $parts = array_map('trim', explode(',', $spec));
        if (count($parts) < 2) {
            Common::logWarn('Ignoring invalid --mount definition; requires at least mountpoint and device.', ['value' => $spec]);
            return null;
        }

        [$mountPoint, $device] = $parts;
        $type = $parts[2] ?? '';
        $options = $parts[3] ?? '';

        if ($mountPoint === '' || $device === '') {
            Common::logWarn('Ignoring invalid --mount definition; empty mountpoint or device.', ['value' => $spec]);
            return null;
        }

        return [
            'mount' => $mountPoint,
            'device' => $device,
            'type' => $type,
            'options' => $options,
        ];
    }

    /**
     * Normalize mount entries with defaults and validation.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeMountEntries(array $entries): array
    {
        $normalized = [];
        $seenMounts = [];

        foreach ($entries as $entry) {
            $mountPoint = $entry['mount'];
            $originalMount = $entry['original_mount'] ?? $mountPoint;
            $device = $entry['device'];
            $type = $entry['type'] ?? '';
            $options = $entry['options'] ?? '';

            $isSwap = strtolower($originalMount) === 'swap';
            if ($isSwap) {
                $mount = 'none';
                $type = $type !== '' ? $type : 'swap';
                $options = $options !== '' ? $options : 'sw';
                $pass = 0;
            } else {
                if ($mountPoint === '' || $mountPoint[0] !== '/') {
                    Common::logWarn('Ignoring mount definition without absolute mountpoint.', ['mount' => $mountPoint, 'device' => $device]);
                    continue;
                }
                $mount = $mountPoint;
                $type = $type !== '' ? $type : 'ext4';
                if ($options === '') {
                    $options = $mount === '/' ? 'errors=remount-ro' : 'defaults';
                }
                $pass = $mount === '/' ? 1 : 2;

                if (isset($seenMounts[$mount])) {
                    Common::logWarn(
                        'Duplicate mount definition detected; using the first entry.',
                        [
                            'mount' => $mount,
                            'existing_device' => $seenMounts[$mount],
                            'duplicate_device' => $device,
                        ]
                    );
                    continue;
                }
                $seenMounts[$mount] = $device;
            }

            $normalized[] = [
                'mount' => $mount,
                'original_mount' => $originalMount,
                'device' => $device,
                'type' => $type,
                'options' => $options,
                'dump' => $entry['dump'] ?? 0,
                'pass' => $entry['pass'] ?? $pass,
                'is_swap' => $isSwap,
            ];
        }

        return $normalized;
    }

    /**
     * Legacy mount specification based on root/home/boot/swap environment values.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function buildLegacyMountSpec(): array
    {
        $defaults = [
            'ROOT_DEVICE' => '/dev/nvme0n1p2',
            'HOME_DEVICE' => '/dev/nvme0n1p3',
            'BOOT_DEVICE' => '/dev/md1',
            'SWAP_DEVICE' => '/dev/nvme0n1p1',
        ];

        $spec = [];

        $root = trim((string) (getenv('ROOT_DEVICE') ?: $defaults['ROOT_DEVICE']));
        if ($root !== '') {
            $spec[] = ['mount' => '/', 'original_mount' => '/', 'device' => $root, 'type' => 'ext4', 'options' => 'errors=remount-ro', 'pass' => 1];
        }

        $home = getenv('HOME_DEVICE');
        if ($home !== false) {
            $homeValue = trim((string) $home);
            if ($homeValue !== '' && strcasecmp($homeValue, 'omit') !== 0) {
                $spec[] = ['mount' => '/home', 'original_mount' => '/home', 'device' => $homeValue, 'type' => 'ext4', 'options' => 'defaults', 'pass' => 2];
            }
        }

        $boot = trim((string) (getenv('BOOT_DEVICE') ?: $defaults['BOOT_DEVICE']));
        if ($boot !== '') {
            $spec[] = ['mount' => '/boot', 'original_mount' => '/boot', 'device' => $boot, 'type' => 'ext4', 'options' => 'defaults', 'pass' => 2];
        }

        $swap = trim((string) (getenv('SWAP_DEVICE') ?: $defaults['SWAP_DEVICE']));
        if ($swap !== '') {
            $spec[] = ['mount' => 'swap', 'original_mount' => 'swap', 'device' => $swap, 'type' => 'swap', 'options' => 'sw', 'pass' => 0];
        }

        return $spec;
    }

    /**
     * Maintain legacy environment variables for compatibility.
     */
    public static function setLegacyEnvFromMounts(array $normalizedMounts): void
    {
        $seen = [
            'ROOT_DEVICE' => false,
            'HOME_DEVICE' => false,
            'BOOT_DEVICE' => false,
            'SWAP_DEVICE' => false,
        ];

        foreach ($normalizedMounts as $entry) {
            if ($entry['is_swap']) {
                self::setEnvironmentValue('SWAP_DEVICE', $entry['device']);
                $seen['SWAP_DEVICE'] = true;
                continue;
            }

            if ($entry['mount'] === '/') {
                self::setEnvironmentValue('ROOT_DEVICE', $entry['device']);
                $seen['ROOT_DEVICE'] = true;
            } elseif ($entry['mount'] === '/home') {
                self::setEnvironmentValue('HOME_DEVICE', $entry['device']);
                $seen['HOME_DEVICE'] = true;
            } elseif ($entry['mount'] === '/boot') {
                self::setEnvironmentValue('BOOT_DEVICE', $entry['device']);
                $seen['BOOT_DEVICE'] = true;
            }
        }

        foreach ($seen as $env => $matched) {
            if ($matched) {
                continue;
            }
            putenv($env);
            unset($_ENV[$env]);
        }
    }

    /**
     * Persist the mount specification into MCX_MOUNT_SPEC.
     */
    public static function storeMountSpecification(array $normalizedMounts): string
    {
        $encoded = json_encode($normalizedMounts);
        if ($encoded === false) {
            Common::fail('Unable to encode mount specification.');
        }

        self::setEnvironmentValue('MCX_MOUNT_SPEC', $encoded);

        return $encoded;
    }

    /**
     * Build the set of tasks to skip alongside a display list for logging.
     *
     * @return array{set: array<string,bool>, display: array<int,string>}
     */
    public static function buildSkipTaskSet(string $raw): array
    {
        $skipTaskSet = [];
        $skipTaskDisplay = [];
        if ($raw === '') {
            return ['set' => $skipTaskSet, 'display' => $skipTaskDisplay];
        }

        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        foreach ($parts as $part) {
            $normalized = strtolower(trim((string) $part));
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $skipTaskDisplay, true)) {
                $skipTaskDisplay[] = $normalized;
            }

            $candidates = [$normalized];
            $base = basename($normalized);
            if ($base !== '' && $base !== $normalized) {
                $candidates[] = $base;
            }

            foreach ($candidates as $candidate) {
                $skipTaskSet[$candidate] = true;
                if (substr($candidate, -4) !== '.php') {
                    $skipTaskSet[$candidate . '.php'] = true;
                }
            }
        }

        return ['set' => $skipTaskSet, 'display' => $skipTaskDisplay];
    }

    /**
     * Detect distro information from /etc/os-release when overrides are missing.
     *
     * @return array{0:string,1:string}
     */
    public static function detectDistro(string $currentId, string $currentVersion): array
    {
        $distroId = $currentId;
        $distroVersion = $currentVersion;

        if ($distroId !== '' && $distroVersion !== '') {
            return [$distroId, $distroVersion];
        }

        $osRelease = '/etc/os-release';
        if (!is_file($osRelease)) {
            return [$distroId, $distroVersion];
        }

        $data = @parse_ini_file($osRelease);
        if ($data === false) {
            return [$distroId, $distroVersion];
        }

        if ($distroId === '' && isset($data['ID'])) {
            $distroId = trim((string) $data['ID']);
        }

        if ($distroVersion === '' && isset($data['VERSION_ID'])) {
            $distroVersion = trim((string) $data['VERSION_ID']);
        }

        if ($distroVersion === '' && isset($data['VERSION_CODENAME'])) {
            $distroVersion = trim((string) $data['VERSION_CODENAME']);
        }

        return [$distroId, $distroVersion];
    }

    /**
     * Helper used internally to maintain environment variables in a consistent manner.
     */
    public static function setEnvironmentValue(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}
