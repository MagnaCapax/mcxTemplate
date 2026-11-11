# Ubuntu Tasks

Ubuntu reuses the Debian task set without copying it. `configure.php` picks the task
directory from the `ID` in `/etc/os-release`, so `distros/ubuntu/common/tasks/` must exist;
every file in it is a relative symlink to the same-named Debian task
(`../../../debian/common/tasks/NN-name.php`), and `templates` is a symlink to
`../debian/templates`. A Debian fix therefore reaches Ubuntu automatically.

The one difference is networking. Ubuntu 24.04 uses netplan, not ifupdown, so Debian task 22
is not linked; `22-render-netplan.php` takes its place and writes `/etc/netplan/01-mcx.yaml`
(static address, default route, resolvers; written, not applied).

Not verified on a real Ubuntu 24.04 install yet: the netplan file is covered by a unit test of
its output only. A cloud-init netplan file shipped in the template (`50-cloud-init.yaml`) is
left untouched and merges with ours; build templates without it.

Add a new shared Debian task as a new symlink here; add an Ubuntu-only task as a normal file.
Do not add a task behind `90-remove-engine.php` (it removes the engine).
