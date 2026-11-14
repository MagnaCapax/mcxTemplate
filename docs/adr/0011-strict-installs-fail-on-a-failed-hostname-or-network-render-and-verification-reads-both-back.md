# ADR-0011: Strict installs fail on a failed hostname or network render, and verification reads both back

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-14
- Status: Proposed

## Context
[ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md) accepted that a task which logs a failure and exits 0 is invisible to `--strict`, and that the boot verification covers only what decides whether the node boots (kernel, initrd, `grub.cfg`, fstab, boot code, `mdadm.conf`). Its Consequences name "a hostname or network file that was not written" as not checked.

Tasks 21 (hostname files) and 22 (network config) were exactly such tasks: when their helper failed they logged a warning and returned, exit 0, also under `MCX_STRICT`. Two real installs show what that costs:
- an installed system kept the template's hostname `debian`;
- another came up on DHCP instead of its static address.

Both reported success. A node that boots under the wrong name, or on an address the operator did not plan, is a failed install for the operator even though it boots.

## Options Considered
- Keep the accepted limit and fix each cause as found: rejected. The next helper failure would be silent again.
- Fail the tasks only: catches a failed helper, not a helper that exits 0 having written something else, and does nothing for a non-strict run.
- Fail the tasks under strict mode and also read the outcome back after configure: chosen. The first makes the failure visible at its source; the second checks the result the operator cares about instead of trusting an exit code.

## Decision
- Under `MCX_STRICT=1` (the network install sets it), tasks 21 and 22 fail with a message when their helper fails (`Common::fail`, so configure's `--strict` reports the failed task and the install ends in `stepFailed`). Without `MCX_STRICT` they stay fail-soft: a warning, exit 0, as before. The Ubuntu task 21 is the same file by symlink. The Ubuntu netplan task already failed under strict mode.
- `BootVerifier` (step `verifyBoot`) also checks the visible outcome in the installed tree, as `verifyFailed` naming the check:
  - `hostname`: `/etc/hostname` holds the requested hostname (whole value, case-insensitive).
  - `network`: the requested static address (the plan's `host.cidr`) is configured: an `address <cidr>` line in `/etc/network/interfaces` (Debian), or the address inside an `addresses: [...]` list of an `/etc/netplan/*.yaml` file (Ubuntu). The address is matched as a whole token, comment lines do not count, and `192.0.2.100/24` is not `192.0.2.10/24`. The gateway and resolvers are not read back.
- `LayoutPlanner` puts `hostname` (the plan's `host.fqdn`) and `cidr` into the `verifyBoot` step. A verify step without them is refused, never skipped, so a planner that forgets them fails loudly.
- The read-back runs in every install, local `--plan` mode included, because it reads files that every successful run of the two tasks has written.

## Consequences
- An install that leaves the template's hostname or network behind is refused before it is reported done, with the check named. In the network mode the node is already wiped at that point; the refusal is the report, and a rerun reinstalls.
- A template whose network is configured in a form the check does not read (for example a netplan file with a block-style `addresses:` list, or another network manager) is refused until the check learns it. The engine's own netplan task writes the inline form the check reads.
- The read-back sees the files as configure left them; anything that rewrites them after the install, on first boot for example, is outside this check.
- The last sentence of ADR-0002's Consequences on hostname and network files no longer holds; it is amended by this ADR.

## References
- [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md), [ADR-0008](0008-network-install-reports-the-installed-ssh-host-keys.md)
- `distros/debian/common/tasks/21-render-hostname-files.php`, `22-render-network-config.php`, `distros/ubuntu/common/tasks/22-render-netplan.php`, `src/Lib/Install/BootVerifier.php`, `src/Lib/Install/LayoutPlanner.php`
- `tests/unit/Install/BootVerifierTest.php`, `tests/unit/Install/EngineTasksTest.php`, `tests/unit/Install/NetworkInstallTest.php`
