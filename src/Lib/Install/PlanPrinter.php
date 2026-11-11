<?php
declare(strict_types=1);

namespace Lib\Install;

// PlanPrinter renders the dry-run report: the device map and the exact ordered
// command list. It prints argv with escapeshellarg for DISPLAY only (nothing is
// ever run through a shell). The plan token and the root password never appear:
// the token is not read here and the password step is shown as a placeholder.
final class PlanPrinter
{
    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $prepared result of Installer::prepare()
     */
    public static function render(array $plan, array $prepared, bool $execute): string
    {
        $out = $execute ? "mcxTemplate installNode: EXECUTE\n" : "mcxTemplate installNode: DRY RUN (nothing is touched)\n";
        $out .= sprintf("job %s, node %s, layout %s, wipeAuthorized %s\n\n", $plan['jobId'], $plan['nodeId'], $plan['layout'],
            $plan['wipeAuthorized'] ? 'true' : 'false');

        $out .= "Device map:\n";
        foreach ($prepared['match']['roles'] as $role => $disk) {
            $out .= sprintf("  %-6s %-14s serial=%s size=%d model=%s path=%s\n", $role, $disk['path'], $disk['serial'] === '' ? '(none)' : $disk['serial'],
                $disk['sizeBytes'], $disk['model'] === '' ? '(none)' : $disk['model'], $disk['idPath']);
        }
        foreach ($prepared['match']['ignored'] as $ignored) {
            $out .= sprintf("  ignored /dev/%s (%s)\n", $ignored['kname'], $ignored['reason']);
        }
        foreach ($prepared['match']['stopArrays'] as $array) {
            $out .= "  md array /dev/$array lies fully inside the targets and is stopped first\n";
        }

        $out .= "\nCommands, in order:\n";
        foreach ($prepared['steps'] as $n => $step) {
            $kind = $step['kind'] ?? '';
            if ($kind === 'writeFile') {
                $line = sprintf('write %s%s (%d bytes)', LayoutPlanner::TARGET, $step['path'], strlen($step['content']));
            } elseif ($kind === 'verify') {
                $line = 'verify (read-only): kernel + initrd, grub.cfg, fstab UUIDs against blkid, GRUB boot code on '
                    . implode(' ', $step['grubTargets']) . ($step['md'] ? ', mdadm.conf lists mcxboot' : '');
            } else {
                $line = implode(' ', array_map('escapeshellarg', $step['argv']));
                if ($kind === 'password') {
                    $line .= '   (stdin: root:<SHA-512 hash of a generated password>)';
                }
                if ($kind === 'configure') {
                    $line .= '   (must print ' . Installer::CONFIGURE_SENTINEL . ')';
                }
                foreach ($step['env'] as $key => $value) {
                    $line = $key . '=' . escapeshellarg($value) . ' ' . $line;
                }
            }
            $out .= sprintf("%3d. %s%s  # %s\n", $n + 1, $step['destructive'] ? '[DESTRUCTIVE] ' : '', $line, $step['id']);
        }
        return $out;
    }
}
