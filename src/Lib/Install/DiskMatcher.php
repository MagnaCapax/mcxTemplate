<?php
declare(strict_types=1);

namespace Lib\Install;

// DiskMatcher::match is a PURE function: plan + live inventory + system state in,
// either a role => disk map or a named refusal code out. It never runs a command
// and never guesses. Any ambiguity is a refusal before the first write (CWE-636).
//
// Refusal codes: planInvalid, nodeIdentityMismatch, diskMissing, diskAmbiguous,
// sizeMismatch, layoutSizeRefused, unexpectedDisk, diskInUse. The in-use gate also
// refuses any target disk or partition that something is stacked on (sysfs holders:
// md, dm, bcache, nested), except an otherwise unused md array fully inside the targets.
final class DiskMatcher
{
    public const KVM_VENDOR_ID = '3346';        // KVM virtual-media device (USB vendor 3346): never a target
    public const MBR_LIMIT_BYTES = 2199023255552; // 2 TiB: some BIOSes cannot MBR-boot a larger disk
    public const STICK_MIN_BYTES = 4294967296;    // 4 GiB
    public const STICK_MAX_BYTES = 68719476736;   // 64 GiB

    /**
     * @param array<string,mixed>              $plan      validated plan (PlanValidator)
     * @param array<int,array<string,mixed>>   $inventory DiskInventory::enumerate result
     * @param array<string,mixed>              $state     SystemStateReader::read result
     * @return array<string,mixed> ['ok'=>true,'roles'=>..,'ignored'=>..,'targetKnames'=>..,'stopArrays'=>..]
     *                             or ['ok'=>false,'code'=>..,'detail'=>..]
     */
    public static function match(array $plan, array $inventory, array $state): array
    {
        // Rule 6: node identity. The plan MAC must belong to a local NIC (any port: dual-port
        // PXE NICs alternate between boots). Disk corroboration is the root serial match below.
        if (!in_array((string) ($plan['mac'] ?? ''), $state['nicMacs'] ?? [], true)) {
            return self::refuse('nodeIdentityMismatch', 'plan MAC is not a local NIC');
        }

        // Rule 4 (part 1): classify ignorable devices. They are removed from every later
        // step, so a KVM virtual-media device can never become a target.
        $ignoreVendors = [self::KVM_VENDOR_ID];
        foreach ($plan['ignore'] ?? [] as $entry) {
            $ignoreVendors[] = (string) $entry['usbVendorId'];
        }
        $ignored = [];
        $candidates = [];
        foreach ($inventory as $disk) {
            if ($disk['tran'] === 'usb' && $disk['usbVendorId'] !== '' && in_array($disk['usbVendorId'], $ignoreVendors, true)) {
                $ignored[] = ['kname' => $disk['kname'], 'reason' => $disk['usbVendorId'] === self::KVM_VENDOR_ID ? 'kvmVirtualMedia' : 'planIgnore'];
                continue;
            }
            $candidates[] = $disk;
        }

        $roles = [];
        $root = self::matchRoot($plan['disks']['root'], $candidates);
        if (isset($root['code'])) {
            return $root;
        }
        $roles['root'] = $root['disk'];

        // Rules 1 and 2 for the boot sticks.
        $planBoot = $plan['disks']['boot'];
        foreach ($planBoot as $i => $stick) {
            $found = self::matchStick($stick, $planBoot, $candidates);
            if (isset($found['code'])) {
                return $found;
            }
            foreach ($roles as $role => $taken) {
                if ($taken['kname'] === $found['disk']['kname']) {
                    return self::refuse('diskAmbiguous', "boot disk $i resolves to the same device as $role");
                }
            }
            $roles['boot' . $i] = $found['disk'];
        }

        // Rule 3: size gates and layout consistency.
        $sizes = self::checkSizes($plan, $roles);
        if ($sizes !== null) {
            return $sizes;
        }

        // Rule 4 (part 2): every non-ignored disk must have a role.
        $matched = array_map(static fn (array $d): string => $d['kname'], $roles);
        foreach ($candidates as $disk) {
            if (!in_array($disk['kname'], $matched, true)) {
                return self::refuse('unexpectedDisk', 'an enumerated disk has no role in the plan');
            }
        }

        // Rule 5: in-use gate.
        $use = self::checkInUse($roles, $state);
        if (isset($use['code'])) {
            return $use;
        }

        return ['ok' => true, 'roles' => $roles, 'ignored' => $ignored,
            'targetKnames' => $use['targetKnames'], 'stopArrays' => $use['stopArrays']];
    }

    /** @return array<string,mixed> */
    private static function refuse(string $code, string $detail): array
    {
        return ['ok' => false, 'code' => $code, 'detail' => $detail];
    }

    /**
     * @param array<string,mixed>            $want
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private static function matchRoot(array $want, array $candidates): array
    {
        if ($want['serial'] === '') {
            return self::refuse('planInvalid', 'root serial is empty');
        }
        $hits = array_values(array_filter($candidates, static fn (array $d): bool => $d['serial'] === $want['serial']));
        if (count($hits) === 0) {
            return self::refuse('diskMissing', 'no disk carries the root serial');
        }
        if (count($hits) > 1) {
            return self::refuse('diskAmbiguous', 'the root serial appears on more than one disk');
        }
        $disk = $hits[0];
        if ($want['transport'] !== '' && $disk['tran'] !== $want['transport']) {
            return self::refuse('diskMissing', 'the disk with the root serial has another transport');
        }
        if ($want['wwn'] !== '' && $disk['wwn'] !== ''
            && self::wwn($want['wwn']) !== self::wwn($disk['wwn'])) {
            return self::refuse('nodeIdentityMismatch', 'root serial matches but the WWN differs');
        }
        return ['disk' => $disk];
    }

    /**
     * Cheap sticks may have empty or duplicate serials: fall back to
     * (usbPath, sizeBytes, model); if that is ambiguous too, refuse.
     *
     * @param array<string,mixed>            $want
     * @param array<int,array<string,mixed>> $allWanted
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private static function matchStick(array $want, array $allWanted, array $candidates): array
    {
        $serial = $want['serial'];
        $planDuplicate = false;
        if ($serial !== '') {
            $same = array_filter($allWanted, static fn (array $s): bool => $s['serial'] === $serial);
            $planDuplicate = count($same) > 1;
        }
        $bySerial = $serial === '' ? [] : array_values(array_filter($candidates, static fn (array $d): bool => $d['serial'] === $serial));

        if ($serial !== '' && !$planDuplicate && count($bySerial) === 1) {
            $hit = $bySerial[0];
        } elseif ($serial !== '' && count($bySerial) === 0) {
            return self::refuse('diskMissing', 'a boot stick serial is not present');
        } else {
            // Empty or non-unique serial: identity falls back to the recorded USB path, size and model.
            $hits = array_values(array_filter($candidates, static function (array $d) use ($want, $serial): bool {
                return $d['idPath'] !== '' && $d['idPath'] === $want['usbPath']
                    && $d['sizeBytes'] === $want['sizeBytes']
                    && ($want['model'] === '' || $d['model'] === $want['model'])
                    && ($serial === '' || $d['serial'] === $serial);
            }));
            if (count($hits) === 0) {
                return self::refuse('diskMissing', 'a boot stick was not found by USB path, size and model');
            }
            if (count($hits) > 1) {
                return self::refuse('diskAmbiguous', 'a boot stick is ambiguous even by USB path, size and model');
            }
            $hit = $hits[0];
        }
        if ($hit['tran'] !== 'usb') {
            return self::refuse('diskMissing', 'a boot stick match is not on the USB transport');
        }
        return ['disk' => $hit];
    }

    /**
     * @param array<string,mixed>            $plan
     * @param array<string,array<string,mixed>> $roles
     */
    private static function checkSizes(array $plan, array $roles): ?array
    {
        $rootWant = $plan['disks']['root']['sizeBytes'];
        if (!self::within($roles['root']['sizeBytes'], $rootWant)) {
            return self::refuse('sizeMismatch', 'root disk size is not within 1% of the plan');
        }
        foreach ($plan['disks']['boot'] as $i => $stick) {
            $size = $roles['boot' . $i]['sizeBytes'];
            if ($size < self::STICK_MIN_BYTES || $size > self::STICK_MAX_BYTES || !self::within($size, $stick['sizeBytes'])) {
                return self::refuse('sizeMismatch', "boot stick $i is outside 4-64 GiB or not within 1% of the plan");
            }
        }
        if ($plan['layout'] === 'biosSingle' && $roles['root']['sizeBytes'] > self::MBR_LIMIT_BYTES) {
            return self::refuse('layoutSizeRefused', 'biosSingle cannot MBR-boot a root disk over 2 TiB');
        }
        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $roles
     * @param array<string,mixed>               $state
     * @return array<string,mixed>
     */
    private static function checkInUse(array $roles, array $state): array
    {
        $target = [];
        $diskNames = [];
        foreach ($roles as $disk) {
            $diskNames[] = $disk['kname'];
            $target[] = $disk['kname'];
            foreach ($state['partitions'][$disk['kname']] ?? [] as $part) {
                $target[] = $part;
            }
        }
        $inTarget = static fn (string $name): bool => in_array($name, $target, true);

        foreach ($state['mounted'] ?? [] as $name) {
            if ($inTarget($name)) {
                return self::refuse('diskInUse', 'a target disk or partition is mounted');
            }
        }
        foreach ($state['swapped'] ?? [] as $name) {
            if ($inTarget($name)) {
                return self::refuse('diskInUse', 'a target disk or partition is in use as swap');
            }
        }
        if (($state['rescueDisk'] ?? '') !== '' && $inTarget((string) $state['rescueDisk'])) {
            return self::refuse('diskInUse', 'a target holds the rescue boot medium');
        }
        foreach ($state['dm'] ?? [] as $members) {
            foreach ($members as $member) {
                if ($inTarget($member)) {
                    return self::refuse('diskInUse', 'a target is a member of an LVM or device-mapper device');
                }
            }
        }

        $stop = [];
        $holders = $state['holders'] ?? [];
        foreach ($state['md'] ?? [] as $array => $members) {
            $inside = array_filter($members, $inTarget);
            if ($inside === []) {
                continue;
            }
            if (count($inside) !== count($members)) {
                return self::refuse('diskInUse', 'an md array has a member outside the target set');
            }
            // The array and its partitions (md127p1) must be free: not mounted, not swap, nothing stacked on them.
            $onArray = static fn (string $name): bool => $name === (string) $array
                || preg_match('/^' . preg_quote((string) $array, '/') . 'p[0-9]+$/D', $name) === 1;
            foreach (array_merge($state['mounted'] ?? [], $state['swapped'] ?? []) as $name) {
                if ($onArray((string) $name)) {
                    return self::refuse('diskInUse', 'an md array on the targets is mounted or in use as swap');
                }
            }
            foreach ($holders as $name => $list) {
                if ($list !== [] && $onArray((string) $name)) {
                    return self::refuse('diskInUse', 'an md array on the targets has another device stacked on it');
                }
            }
            $stop[] = (string) $array; // fully inside the target set: stopped before the wipe
        }

        // Anything stacked on a target disk or partition (md, dm, bcache, nested) refuses, except an md
        // array that lies fully inside the targets, is otherwise unused, and is stopped first.
        foreach ($target as $name) {
            foreach ($holders[$name] ?? [] as $holder) {
                if (!in_array($holder, $stop, true)) {
                    return self::refuse('diskInUse', 'a target disk or partition is held by another device');
                }
            }
        }

        return ['targetKnames' => array_values(array_unique($target)), 'stopArrays' => $stop];
    }

    // within reports whether $actual is inside 1% of $planned.
    private static function within(int $actual, int $planned): bool
    {
        return abs($actual - $planned) * 100 <= $planned;
    }

    // wwn strips a leading 0x and case so lsblk and udev spellings compare equal.
    private static function wwn(string $wwn): string
    {
        return strtolower(preg_replace('/^0x/i', '', $wwn) ?? $wwn);
    }
}
