<?php
declare(strict_types=1);

namespace Lib\Install;

// PlanValidator treats the plan file as untrusted input. Every field is checked
// against a strict pattern before any other class uses it (CWE-20, CWE-78, CWE-88).
// Unknown keys are refused. Error messages name the field, never its value, so a
// token or other secret cannot leak through an exception.
final class PlanValidator
{
    public const LAYOUTS = ['biosSingle', 'biosNvmeUsbBoot'];
    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const SERIAL = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const PATHID = '/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/D';
    private const MODEL = '/^[A-Za-z0-9][A-Za-z0-9 ._:+()\/-]{0,63}$/D';
    private const FQDN = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    /**
     * @param mixed $raw decoded JSON
     * @return array<string,mixed> normalised plan
     */
    public static function validate($raw): array
    {
        $plan = self::obj($raw, 'plan');
        self::only($plan, ['jobId', 'nodeId', 'mac', 'expectedIps', 'firmware', 'layout', 'homeSplit', 'disks', 'ignore',
            'template', 'host', 'token', 'apiBase', 'wipeAuthorized'], 'plan');

        $out = [];
        $out['jobId'] = self::match($plan, 'jobId', self::ID);
        $out['nodeId'] = self::match($plan, 'nodeId', self::ID);
        $out['mac'] = self::mac($plan['mac'] ?? null, 'mac');
        $out['expectedIps'] = [];
        foreach (self::lst($plan['expectedIps'] ?? [], 'expectedIps') as $ip) {
            $out['expectedIps'][] = self::ip($ip, 'expectedIps');
        }
        if (($plan['firmware'] ?? null) !== 'bios') {
            throw new InstallRefusal('planInvalid', 'firmware must be bios (UEFI is not supported)');
        }
        $out['firmware'] = 'bios';
        $layout = $plan['layout'] ?? null;
        if (!is_string($layout) || !in_array($layout, self::LAYOUTS, true)) {
            throw new InstallRefusal('planInvalid', 'layout is not a known layout');
        }
        $out['layout'] = $layout;
        $out['homeSplit'] = self::bool($plan['homeSplit'] ?? false, 'homeSplit');
        if ($out['homeSplit'] && $layout !== 'biosSingle') {
            throw new InstallRefusal('planInvalid', 'homeSplit applies to biosSingle only');
        }
        $out['wipeAuthorized'] = self::bool($plan['wipeAuthorized'] ?? false, 'wipeAuthorized');
        $out['disks'] = self::disks($plan['disks'] ?? null, $layout);
        $out['ignore'] = [];
        foreach (self::lst($plan['ignore'] ?? [], 'ignore') as $entry) {
            $e = self::obj($entry, 'ignore entry');
            self::only($e, ['usbVendorId'], 'ignore entry');
            $out['ignore'][] = ['usbVendorId' => self::match($e, 'usbVendorId', '/^[0-9a-f]{4}$/D')];
        }
        $out['template'] = self::template($plan['template'] ?? null);
        $out['host'] = self::host($plan['host'] ?? null);
        // The token is a secret: validated for shape, never echoed.
        $out['token'] = isset($plan['token']) ? self::match($plan, 'token', '/^[A-Za-z0-9._~-]{16,256}$/D') : '';
        $out['apiBase'] = isset($plan['apiBase']) ? self::url($plan['apiBase'], 'apiBase') : '';

        return $out;
    }

    /** @return array<string,mixed> */
    private static function disks($raw, string $layout): array
    {
        $disks = self::obj($raw, 'disks');
        self::only($disks, ['root', 'boot'], 'disks');
        $root = self::obj($disks['root'] ?? null, 'disks.root');
        self::only($root, ['serial', 'wwn', 'sizeBytes', 'transport'], 'disks.root');
        $out = ['root' => [
            'serial' => self::match($root, 'serial', self::SERIAL),
            'wwn' => self::optMatch($root, 'wwn', '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D'),
            'sizeBytes' => self::size($root['sizeBytes'] ?? null, 'disks.root.sizeBytes'),
            'transport' => self::optMatch($root, 'transport', '/^[a-z0-9]{2,16}$/D'),
        ], 'boot' => []];

        $boot = self::lst($disks['boot'] ?? [], 'disks.boot');
        $expected = $layout === 'biosNvmeUsbBoot' ? 2 : 0;
        if (count($boot) !== $expected) {
            throw new InstallRefusal('planInvalid', "layout $layout needs exactly $expected boot disks");
        }
        foreach ($boot as $stick) {
            $s = self::obj($stick, 'disks.boot entry');
            self::only($s, ['serial', 'usbPath', 'sizeBytes', 'transport', 'model'], 'disks.boot entry');
            // Cheap sticks may have an empty serial; then usbPath is the identity (see DiskMatcher).
            $out['boot'][] = [
                'serial' => self::optMatch($s, 'serial', self::SERIAL),
                'usbPath' => self::match($s, 'usbPath', self::PATHID),
                'sizeBytes' => self::size($s['sizeBytes'] ?? null, 'disks.boot.sizeBytes'),
                'transport' => self::optMatch($s, 'transport', '/^[a-z0-9]{2,16}$/D'),
                'model' => self::optMatch($s, 'model', self::MODEL),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function template($raw): array
    {
        $t = self::obj($raw, 'template');
        self::only($t, ['name', 'version', 'sha256', 'sizeBytes', 'url', 'path'], 'template');
        $path = self::match($t, 'path', '/^\/[A-Za-z0-9._\/+-]{1,255}$/D');
        if (strpos($path, '..') !== false || strpos($path, '//') !== false) {
            throw new InstallRefusal('planInvalid', 'template.path must be a clean absolute path');
        }
        return [
            'name' => self::match($t, 'name', self::ID),
            'version' => self::match($t, 'version', self::ID),
            'sha256' => self::match($t, 'sha256', '/^[0-9a-f]{64}$/D'),
            'sizeBytes' => self::size($t['sizeBytes'] ?? null, 'template.sizeBytes'),
            'url' => isset($t['url']) ? self::url($t['url'], 'template.url') : '',
            'path' => $path,
        ];
    }

    /** @return array<string,mixed> */
    private static function host($raw): array
    {
        $h = self::obj($raw, 'host');
        self::only($h, ['fqdn', 'cidr', 'gateway', 'resolvers'], 'host');
        $fqdn = self::match($h, 'fqdn', self::FQDN);
        $cidr = $h['cidr'] ?? null;
        if (!is_string($cidr) || preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/D', $cidr, $m) !== 1
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $m[2] < 8 || (int) $m[2] > 30) {
            throw new InstallRefusal('planInvalid', 'host.cidr must be an IPv4 address with a /8 to /30 prefix');
        }
        $gateway = self::ip($h['gateway'] ?? null, 'host.gateway', true);
        $resolvers = [];
        foreach (self::lst($h['resolvers'] ?? null, 'host.resolvers') as $r) {
            $resolvers[] = self::ip($r, 'host.resolvers');
        }
        if ($resolvers === [] || count($resolvers) > 3) {
            throw new InstallRefusal('planInvalid', 'host.resolvers needs one to three addresses');
        }
        return ['fqdn' => $fqdn, 'cidr' => $cidr, 'gateway' => $gateway, 'resolvers' => $resolvers];
    }

    // --- small typed readers -------------------------------------------------

    /** @return array<string,mixed> */
    private static function obj($v, string $name): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new InstallRefusal('planInvalid', "$name must be an object");
        }
        return $v;
    }

    /** @return array<int,mixed> */
    private static function lst($v, string $name): array
    {
        if (!is_array($v) || ($v !== [] && !array_is_list($v))) {
            throw new InstallRefusal('planInvalid', "$name must be a list");
        }
        return $v;
    }

    private static function only(array $a, array $allowed, string $name): void
    {
        foreach (array_keys($a) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InstallRefusal('planInvalid', "$name has an unknown key");
            }
        }
    }

    private static function match(array $a, string $key, string $pattern): string
    {
        $v = $a[$key] ?? null;
        if (!is_string($v) || preg_match($pattern, $v) !== 1) {
            throw new InstallRefusal('planInvalid', "$key is missing or malformed");
        }
        return $v;
    }

    // optMatch allows an absent or empty value (returned as '') but validates anything else.
    private static function optMatch(array $a, string $key, string $pattern): string
    {
        if (!array_key_exists($key, $a) || $a[$key] === '' || $a[$key] === null) {
            return '';
        }
        return self::match($a, $key, $pattern);
    }

    private static function bool($v, string $name): bool
    {
        if (!is_bool($v)) {
            throw new InstallRefusal('planInvalid', "$name must be a JSON boolean");
        }
        return $v;
    }

    private static function size($v, string $name): int
    {
        if (!is_int($v) || $v <= 0) {
            throw new InstallRefusal('planInvalid', "$name must be a positive integer");
        }
        return $v;
    }

    private static function mac($v, string $name): string
    {
        if (!is_string($v) || preg_match('/^[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}$/D', $v) !== 1) {
            throw new InstallRefusal('planInvalid', "$name is not a MAC address");
        }
        return strtolower($v);
    }

    private static function ip($v, string $name, bool $v4Only = false): string
    {
        $flags = $v4Only ? FILTER_FLAG_IPV4 : 0;
        if (!is_string($v) || filter_var($v, FILTER_VALIDATE_IP, $flags) === false) {
            throw new InstallRefusal('planInvalid', "$name holds an invalid address");
        }
        return $v;
    }

    private static function url($v, string $name): string
    {
        if (!is_string($v) || preg_match('/^https:\/\/[A-Za-z0-9.-]{1,253}(?::\d{1,5})?(?:\/[A-Za-z0-9._~\/%+-]*)?$/D', $v) !== 1) {
            throw new InstallRefusal('planInvalid', "$name must be a plain https URL");
        }
        return $v;
    }
}
