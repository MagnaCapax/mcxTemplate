# ADR-0002: Disk preparation and install live in mcxTemplate

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-11
- Status: Proposed

## Context

[ADR-0001](0001-architecture-layout-and-languages.md) and the README assign partitioning, formatting and wiping to the rescue layer ("a rescue or live system, normally mcxRescue, partitions and formats the disks, mounts the target, extracts a template archive"). That layer does not contain this code: mcxRescue builds the rescue image and its own rules keep application logic out of it. The result is that nothing in the chain identifies the target disks, partitions them, extracts the archive, sets a root password or enters the chroot. mcxTemplate configures a chroot and stops there.

An unattended install needs exactly the missing middle, and it is the part that can destroy the wrong disk. It therefore needs the strictest tests and the smallest review surface, which means it belongs next to the tasks it drives, in PHP, shipped as a versioned tarball rather than baked into the rescue image.

## Options Considered
- Keep disk preparation in mcxRescue: rejected. The image builder has no PHP test suite and its scope rule excludes application logic; every change would need an image rebuild.
- A new repository for the installer: rejected for now. It would need the same tarball layout, the same `rootfs/` knowledge and the same task runner; a second repository only adds a second place for them to drift.
- Put it in mcxTemplate as `installNode.php` and `src/Lib/Install/`: chosen.

## Decision

This ADR supersedes the sentence in ADR-0001 ("Destructive disk work (partition, format, wipe) is out of scope; it belongs to the rescue layer") and the matching README text. mcxRescue keeps booting the node and fetching mcxTemplate; mcxTemplate now owns disk identification, partitioning, filesystem creation, archive extraction, the root password and the hand-off to the chroot tasks.

### Entry point and modes
- `php installNode.php --plan=FILE` is a dry run: it reads (lsblk, udev, `/proc`, `/sys`, the archive), prints the device map and the ordered command list an execute run follows, and exits 0. It writes nothing. The filesystem UUIDs are drawn fresh on every run, so a dry run shows the commands, not the exact UUIDs of a later run.
- Destructive execution needs BOTH `--execute` and `"wipeAuthorized": true` in the plan, plus `--secret-out=/absolute/path` for the generated root password, and root. The input checks (wipeAuthorized, `--secret-out` present and usable) run before the root check, so each refusal has its own code and an unprivileged test proves each one.
- Exit codes: 0 success, 2 refusal with a named code (`REFUSED <code>: <detail>` on stderr), 3 internal error (class name only), 64 usage.

### The plan
A JSON file treated as untrusted input. `PlanValidator` checks every field against a strict pattern, refuses unknown keys and never puts a field value in an error message. Fields: `jobId`, `nodeId`, `mac`, `expectedIps`, `firmware` (only `bios`), `layout`, `homeSplit`, `disks.root` (`serial`, `wwn`, `sizeBytes`, `transport`), `disks.boot[]` (`serial`, `usbPath`, `sizeBytes`, `transport`, `model`), `ignore[]` (`usbVendorId`), `template` (`name`, `version`, `sha256`, `sizeBytes`, `path`, optional `url`), `host` (`fqdn`, `cidr`, `gateway`, `resolvers`), `token`, `apiBase`, `wipeAuthorized`. In this slice the template is a local file (`template.path`); fetching it is a later slice.

### Target-disk selection (cannot wipe the wrong disk)
`DiskMatcher::match(plan, inventory, state)` is a pure function that returns a role-to-disk map or a refusal code. Nothing is guessed; any ambiguity refuses before the first write.
1. Identity is the serial, never the kernel name. An expected disk must be found exactly once (`diskMissing`, `diskAmbiguous`).
2. A boot stick with an empty or duplicate serial is identified by the recorded USB path, size and model; if that is ambiguous too, refuse.
3. Size gates: root within 1% of the plan; sticks 4 to 64 GiB and within 1% of the plan (`sizeMismatch`); `biosSingle` refuses a root disk over 2 TiB (`layoutSizeRefused`).
4. Any enumerated disk without a role that is not ignored refuses (`unexpectedDisk`). A USB device with vendor id 3346 (KVM virtual media) is classified away first and can never be a target.
5. In-use gate (`diskInUse`): a target or partition that is mounted, used as swap, an LVM or device-mapper member, the rescue boot medium, or an md member whose array has a member outside the targets. Any target disk or partition with a non-empty `/sys/block/.../holders` refuses too (md, dm, bcache and anything nested). The one exception is an md array that lies fully inside the targets, is not mounted or swap (neither the array nor its partitions such as `md127p1`) and has nothing stacked on it: it is stopped first.
6. Node identity (`nodeIdentityMismatch`): the plan MAC must be the MAC of a local physical NIC (any port), and the root serial must match.
7. Time of check to time of use: every destructive step names the disks whose identity must be re-read, and `DeviceGuard` re-reads serial, WWN, model, size, USB path and transport right before the command and aborts (`deviceChanged`) on any difference. Destructive commands take exact device paths. The chroot configure step counts as destructive (its `grub-install` writes raw sectors to the GRUB targets), so the root disk and every GRUB disk are re-read right before it.

### Layouts
- `biosSingle`: MBR, `/boot` 1 GiB, swap 4 GiB, root over the rest; optional `homeSplit` makes root 100 GiB and adds a `/home` partition (`mkfs.ext4 -m 0 -L home`). The sizes of `/boot` and swap are this ADR's choice, not measured from an existing profile.
- `biosNvmeUsbBoot`: two USB sticks hold `/boot` as an md RAID1 (metadata 1.2, a 2 GiB partition on each, GRUB to both); the large disk is GPT with swap and root. GPT on the large disk is a choice made here because it does not boot, so the 2 TiB MBR limit does not apply to it.
- UEFI is out of scope.

Filesystem and swap UUIDs are chosen before the first write (`mkfs -U`, `mkswap -U`), so the whole command list, including the fstab UUIDs, is known and printable in a dry run. `GRUB_TARGETS` is set to `/dev/disk/by-path/<udev ID_PATH>` of the boot disks, never `sda`/`sdb`. Wiping a disk zeroes its first 16 MiB and its last 16 MiB; the tail write seeks in bytes (`oflag=seek_bytes`, size minus 16 MiB), because disk sizes are rarely whole MiB.

### Template, password and the chroot
- An execute run copies the template once into a fresh directory `/run/mcxTemplate-stage-<jobId>` (created with mode 0700; an existing one refuses with `templateStageRefused`), hashing the bytes as it writes them, and refuses on a size or sha256 difference. The layout check (everything under `rootfs/`, no `..` members, `rootfs/etc/os-release` and the embedded engine present, then `tools/check-template.php`) and the extraction read that copy, so the bytes that were hashed are the bytes that are extracted, whatever happens to `template.path` afterwards. `/run` is chosen because it is tmpfs on the rescue system, writable only by root and gone after a reboot; the copy is removed when the run ends. The cost is a second copy of the template in RAM for the length of the run. A dry run verifies the template in place and copies nothing. Extraction uses `tar -xpzf --numeric-owner --xattrs --acls --strip-components=1`.
- The root password is generated on the node (`random_int`, 20 characters over `[A-Za-z0-9]`, at least two classes) and set in the chroot as a SHA-512 crypt hash through the stdin of `chpasswd -e`. The `--secret-out` path is checked before the first write (absolute, no `.`/`..`, its directory exists, the file does not, and it is not under `/mnt/target`, where a file would be hidden by the mount). The file itself (mode 0600, created exclusively) is written only when every earlier step has succeeded, right before the password step, with every write, the fsync and the close checked, so an aborted run leaves no password that was never set. The plaintext is never in argv, environment, logs or output.
- `/etc/resolv.conf` is written from the plan's resolvers; the write refuses to follow a symlink out of the target root.
- The chroot step runs `installTemplate.php --strict` with `--mount=...,UUID=...` entries and an explicit environment (`PATH`, `LANG=C`, `USER=root`, `HOME=/root`, `GRUB_TARGETS`); nothing is inherited from the rescue shell, so a stray `MCX_DRY_RUN` or `MCX_SKIP_TASKS` there cannot make configure skip its work. `--strict` is new and opt-in on `installTemplate.php` and `distros/configure.php`: a failed task makes the run exit non-zero and print a failure summary instead of "completed successfully". When every task ran and succeeded (no dry run, no skipped tasks), strict configure prints one fixed final line, `MCXTEMPLATE-STRICT-CONFIGURE-OK`. `installNode.php` needs positive proof: exit 0 without that line (for example an older engine in the template that ignores `--strict`) is `stepFailed`. Without `--strict` nothing changes.
- Boot verification (`verifyFailed`, naming the check): after configure and while the target is still mounted, a read-only step checks that `/boot` holds a kernel with a matching non-empty initrd and a `grub.cfg`, that every planned UUID is in the target fstab and every fstab UUID exists in `blkid` output, that the first sector of each GRUB target carries the `0x55AA` signature and GRUB's boot code, and, for the md layout, that `mdadm.conf` lists the `mcxboot` array. Several boot tasks log a failure and exit 0, so their exit code proves nothing; this step catches the result instead.
- Any failure, including an unexpected error, unmounts what was mounted (only `/mnt/target` and below) and stops the boot array before the error is passed on.

### Tests
Unit tests use a recording fake command runner only. Rules 1 to 7 above each have fixtures that fail when the rule is removed; so do the strict success line, the destructive marking of configure, the staged copy and every boot check.

## Consequences
- The install core exists and is reviewable in one place, with a dry-run default and named refusals.
- It has not run against a disk: every test uses a fake command runner. The first real run must be on a disposable machine.
- Not in this slice: the network client and job events, the download of the template, delivery of the root password (until then it exists only in the `--secret-out` file, normally in rescue RAM), the reboot, removal of the engine from the new root, a task that writes the resolvers inside the chroot, Ubuntu tasks, template release tooling and UEFI.
- Soft failures inside a task (a task that logs and exits 0) are still invisible to `--strict`; the existing task files were not changed. The boot verification catches the ones that matter for booting (kernel, initrd, `grub.cfg`, fstab, boot code, `mdadm.conf`); others, such as a hostname or network file that was not written, are not checked.
- The plan and the engine are not authenticated in this slice: `wipeAuthorized` and `template.sha256` are only what a local, root-supplied plan says about itself. That must change before the plan arrives over the network.
- Tasks still carry device defaults and a TEST-NET network fallback when called without the flags; `installNode.php` always passes explicit values.

## References
- [ADR-0001](0001-architecture-layout-and-languages.md), [README.md](../../README.md), [AGENTS.md](../../AGENTS.md)
