# Ubuntu Tasks

Ubuntu reuses the Debian task set without copying it. `configure.php` picks the task
directory from the `ID` in `/etc/os-release`, so `distros/ubuntu/common/tasks/` must exist;
every file in it is a relative symlink to the same-named Debian task
(`../../../debian/common/tasks/NN-name.php`), and `templates` is a symlink to
`../debian/templates`. A Debian fix therefore reaches Ubuntu automatically.

The one difference is networking. Ubuntu 24.04 uses netplan, not ifupdown, so Debian task 22
is not linked; `22-render-netplan.php` takes its place and writes `/etc/netplan/01-mcx.yaml`
(static address, default route, resolvers; written, not applied).

Verified on a virtual machine (2026-11-14) with a template built from `24.04/minimal.packages`
(`tools/templateBuildDebian.php`, [ADR-0010](../../docs/adr/0010-templatebuilddebian-builds-ubuntu-from-its-archive-with-written-out-apt-sources.md)):
the install ran to the end and the boot check passed, the machine booted twice with
`systemctl is-system-running` reporting `running` and no failed unit, netplan brought `eth0` up
with the planned address and default route (systemd-networkd), php, smartctl, cron, dbus, time sync and
sshd were active or present, and an ACPI power-off stopped it cleanly. The netplan file is also
covered by a unit test of its output. A cloud-init netplan file shipped in the template
(`50-cloud-init.yaml`) is left untouched and merges with ours; build templates without it.

Add a new shared Debian task as a new symlink here; add an Ubuntu-only task as a normal file.
Do not add a task behind `90-remove-engine.php` (it removes the engine).
