# mcxTemplate

mcxTemplate delivers a simple, repeatable templating engine for installing bare
metal systems from live images. The repository keeps the configuration that runs
inside the target chroot after mcxRescue prepares disks and copies a template
filesystem into place.

## Purpose & Scope

- Linux-only templating engine that configures a prepared root filesystem from inside a chroot using PHP tasks and simple text templates.
- Primary target: bare‑metal servers booted into a rescue/live environment; also suitable for VM images/guests (for example, KVM) that follow the same mount‑and‑chroot model.
- Disk preparation (identify target disks, partition, format, extract, root password) lives in `installNode.php` and `src/Lib/Install/` ([ADR-0002](docs/adr/0002-disk-preparation-and-install-live-in-mcxtemplate.md), which supersedes the earlier note assigning it to mcxRescue). It is a dry run unless `--execute` and `"wipeAuthorized": true` are both given. The chroot tasks still assume filesystems are mounted under the target root, which `installNode.php` does for you or you do by hand as in the Quick Start.
- Why it exists: deliver reproducible installs and rebuilds at scale, eliminate config drift, keep distro specifics versioned/auditable, and make provisioning fast, observable, and idempotent.

## Quick Start (Debian)

This is a minimal, human-friendly first-run guide using the included Debian tasks and templates.

- Build or obtain a template tarball
  - From a live host: `php tools/templateAssemble.php --source-host <host> --source-user root --output /srv/templates/debian-base.tar.gz`
  - From a disk image: `sudo php tools/templateCloneImage.php --image /path/debian.img --output /srv/templates/debian-base.tar.gz`
  - Validate contents: `php tools/check-template.php /srv/templates/debian-base.tar.gz`
- Prepare target in rescue environment
  - Partition, format, and mount the target root: `installNode.php` does this (see "Unattended install"); by hand, the steps below still apply.
  - Extract the archive into the target root.
    - If built with `tools/templateAssemble.php` defaults, the engine is already embedded under `rootfs/opt/mcxTemplate`, so extraction is sufficient.
    - If you used `--no-embed-into-rootfs` or a raw stream via `templateCloneRemote.php`, copy the engine into `/opt/mcxTemplate` manually (e.g., from the `template/` directory in the archive).
- Run the installer inside the chroot (dry run first)
  - Dry run: `php /opt/mcxTemplate/installTemplate.php --hostname=example1.dc.local --network-cidr=192.0.2.10/24 --gateway=192.0.2.1 --mount=/,/dev/nvme0n1p2 --mount=swap,/dev/nvme0n1p3 --log-dir=/var/log/mcxTemplate-test --skip-tasks=26-run-post-config.php --dry-run`
  - Apply: `php /opt/mcxTemplate/installTemplate.php --hostname=example1.dc.local --network-cidr=192.0.2.10/24 --gateway=192.0.2.1 --mount=/,/dev/nvme0n1p2 --mount=swap,/dev/nvme0n1p3`
- Review logs and structured output
  - Per-task logs: `/var/log/mcxTemplate` (or the path passed via `--log-dir`).
  - Structured JSON: `${MCX_STRUCTURED_LOG}`; reference with `--log-dir` if unset.

Notes
- Debian sample tasks are already included under `distros/debian/common/tasks/` and cover log cleanup, ID resets, hostname/network rendering, fstab generation, mdadm capture, and boot updates.
- You can skip tasks non-destructively via `--skip-tasks` or `MCX_SKIP_TASKS` (names match task filenames, with or without `.php`).


## Provisioning Architecture

mcxTemplate assumes the provisioning host follows the mcxRescue workflow:

1. **Boot rescue environment** – Nodes boot into mcxRescue (or another live
   image) that provides the tools (`lsblk`, `parted`, `sgdisk`, `mdadm`, `tar`,
   PHP) and a copy of this repository.
2. **Partition and populate** – `installNode.php` matches the target disks by
   serial against a JSON plan, refuses anything ambiguous, then wipes, partitions
   and formats them, mounts the target paths and extracts the template tarball
   onto the new root filesystem (see "Unattended install" below).
3. **Chroot hand-off** – `installNode.php` enters the chroot and runs
   `/opt/mcxTemplate/installTemplate.php --strict`. This entry point invokes the
   distro configurator shipped in this repository.
4. **Distro configuration** – `distros/configure.php` detects the active distro,
   runs shared tasks from `distros/<distro>/common`, then executes any
   version-specific overrides. Hooks live entirely inside the target chroot, so
   every change directly affects the freshly cloned system.
5. **Verify, logging and reboot** – `installNode.php` checks read-only that the
   new system can boot (kernel and initrd, `grub.cfg`, fstab UUIDs, GRUB boot
   code, `mdadm.conf`), then unmounts the target filesystems and stops the boot
   array. Rebooting into the installed OS is still done by the caller (mcxRescue);
   result delivery is not built yet.

This flow keeps the template build consistent and avoids running destructive
operations inside the final system.

## Unattended install (installNode.php)

```
php installNode.php --plan=/run/plan.json                  # dry run: device map + ordered command list, exit 0, nothing touched
php installNode.php --plan=/run/plan.json --execute --secret-out=/run/root.secret
```

- The plan is untrusted JSON; every field is validated (see ADR-0002 for the fields). The template is a local file named by `template.path`, and its size and sha256 must equal the plan's. An execute run copies it once, hashing as it copies, into a new private directory `/run/mcxTemplate-stage-<jobId>` and extracts that copy (the copy is removed when the run ends; it needs the template's size again in RAM).
- `--execute` also needs `"wipeAuthorized": true` in the plan and root. `--secret-out` receives the generated root password (new file, mode 0600, not under `/mnt/target`), written right before the password is set, never earlier; the password is never printed or logged.
- The chroot configuration runs with `--strict` and a minimal fixed environment, and must end with the line `MCXTEMPLATE-STRICT-CONFIGURE-OK`; exit 0 without it counts as a failure. A read-only boot check follows before the run reports success.
- Layouts: `biosSingle` (MBR, `/boot` + swap + root, optional `/home` split) and `biosNvmeUsbBoot` (two USB sticks as md RAID1 `/boot`, large disk GPT with swap and root). UEFI is not supported.
- Exit codes: 0 ok, 2 refusal (`REFUSED <code>: ...` on stderr; codes such as `diskMissing`, `diskAmbiguous`, `unexpectedDisk`, `sizeMismatch`, `layoutSizeRefused`, `diskInUse`, `nodeIdentityMismatch`, `deviceChanged`, `wipeNotAuthorized`, `secretOutRequired`, `secretOutInvalid`, `notRoot`, `templateHashMismatch`, `templateStageRefused`, `stepFailed`, `verifyFailed`), 3 internal error, 64 usage.
- Status: the logic is covered by unit tests with a fake command runner. It has not been run against a real disk, and the network client, template download, result delivery and reboot are not built yet.

## Repository Layout

- `installNode.php`, `src/Lib/Install/` – the unattended install core described above.

- `distros/configure.php` – Main orchestrator invoked by `installTemplate.php`.
- `distros/<distro>/common/tasks/` – Ordered PHP tasks executed for the
  distro regardless of version. Tasks run alphabetically to keep the flow
  predictable.
- `distros/<distro>/<version>/tasks/` – Optional overrides for specific releases
  (for example `distros/debian/12/tasks`). Missing directories simply skip the
  override stage.
- `distros/<distro>/*/user.d/` – Optional user-maintained hooks ignored by Git.
  Place site-specific `.php` scripts here when extra tweaks are
  required.
- `distros/common/` – Shared PHP helpers for rendering config files across all
  distros.
- `distros/task_preconditions.php` – Optional registry for task-level
  preconditions (for example, requiring binaries before execution).
- `lib/common/` – Shared PHP helpers (`Logging.php`, `System.php`).
- `tools/` – Packaging helpers for building template tarballs.

### Template Rendering

Provisioning tasks render configuration files from simple templates to keep the system state
predictable. Templates live under `distros/<distro>/templates/` with fallbacks in
`distros/common/templates/`. Each file uses placeholder tokens such as `{{FQDN}}`,
`{{INTERFACE}}`, or `{{FSTAB_ENTRIES}}`. The helpers substitute these tokens with a straight
`str_replace()` call, so template changes remain easy to audit.

Every call to `Common::applyTemplateToFile()` emits a structured `template-apply` log event
containing the template path, destination file, and SHA-256 hash of the rendered payload. This
makes it straightforward to review which files changed during a provisioning run.

## configure.php Options

`distros/configure.php` (and the `installTemplate.php` wrapper) accept additional
flags so mcxRescue can inject host metadata when it hands execution to the
template. Common options include:

**Host configuration**

- `--hostname=<fqdn>` – Fully-qualified hostname to apply inside the chroot.
- `--host-ip=<address>` – Primary host IP for `/etc/hosts` when the network is
  not discoverable automatically.
- `--network-cidr=<cidr>` / `--gateway=<address>` – Override network detection
  when the rescue environment cannot infer routes.
- `--hosts-template=<path>` – Custom `/etc/hosts` template containing
  `{{SHORT_HOSTNAME}}`, `{{FQDN}}`, and `{{HOST_IP}}` placeholders.
- `--primary-interface=<name>` – Override the detected primary network interface used when
  rendering distro templates.

**Storage**

- `--mount=<mount,device[,type[,opts]]>` – Generic mount specification. Repeat
  for each filesystem (for example, `--mount=/,/dev/nvme0n1p2` and
  `--mount=/home,/dev/nvme0n1p3`).
- `--root-device=<path>` / `--home-device=<path|omit>` – Legacy compatibility
  flags retained for simple environments; values are translated into the mount
  list automatically.

At least one `--mount` definition for `/` must be supplied (or implied via the
legacy flags); mcxTemplate validates this before writing `/etc/fstab`.

**Post provisioning**

- `--ssh-keys-uri=<uri>` – Download and append SSH public keys to
  `/root/.ssh/authorized_keys`.
- `--ssh-keys-sha256=<hash>` – Optional SHA-256 verification for key payloads.
  Supply `URI=HASH` pairs to verify individual downloads.
- `--post-config=<uri>` – Fetch and execute a post-configuration script after
  the built-in tasks complete.
- `--post-config-sha256=<hash>` – Expected SHA-256 for the downloaded
  post-configuration script.

**Operational controls**

- `--log-dir=<path>` – Directory used for per-task log files (defaults to
  `/var/log/mcxTemplate`).
- `--skip-tasks=<list>` – Comma- or whitespace-separated task names to skip for
  a particular run.
- `--dry-run` – Print the task plan (respecting `--skip-tasks`) without executing
  any scripts.
- `--strict` – Opt-in. A failed task makes the run exit 1 and print a failure
  summary instead of "completed successfully". When every task ran and
  succeeded (no `--dry-run`, no skipped tasks) the last line is
  `MCXTEMPLATE-STRICT-CONFIGURE-OK`, which `installNode.php` requires. Without
  `--strict` a failed task stays a warning, the exit code is 0 and the output is
  unchanged, as before.

The script only requires root once it begins executing tasks; informational
actions like `--help` can be run without elevated privileges.

### Environment Variables

- `MCX_LOG_DIR` – Directory for per-task log files (defaults to
  `/var/log/mcxTemplate`). mcxTemplate attempts to create it automatically.
- `MCX_STRUCTURED_LOG` – Optional JSON lines log written alongside console
  output. Defaults to `<MCX_LOG_DIR>/structured.log` when unset.
- `MCX_SKIP_TASKS` – Comma- or whitespace-separated list of task names to skip
  (case-insensitive; the `.php` suffix is optional).
- `MCX_HOSTS_TEMPLATE` – Absolute path to a template for `/etc/hosts` containing
  `{{SHORT_HOSTNAME}}`, `{{FQDN}}`, and `{{HOST_IP}}` placeholders.
- `MCX_PRIMARY_INTERFACE` – Interface name to prefer when rendering network configuration templates.
- `MCX_DRY_RUN` – When set to a non-empty value, lists tasks without executing
  them (equivalent to passing `--dry-run`).
- `MCX_SSH_KEYS_SHA256` – SHA-256 hash (or `URI=HASH` pairs) for verifying
  downloaded SSH public keys.
- `MCX_POST_CONFIG_SHA256` – Expected SHA-256 hash for the downloaded
  post-configuration script.

Example usage from mcxRescue:

```
php /opt/mcxTemplate/installTemplate.php \
  --hostname=example1.dc.local \
  --network-cidr=192.0.2.10/24 \
  --gateway=192.0.2.1 \
  --root-device=/dev/nvme0n1p2 \
  --home-device=omit \
  --ssh-keys-uri="https://provisioning.example.com/keys?id=123" \
  --post-config="https://provisioning.example.com/scripts/post.sh"
```

Single-line invocation for automation harnesses:

```
php /opt/mcxTemplate/installTemplate.php --hostname=example1.dc.local --network-cidr=192.0.2.10/24 --gateway=192.0.2.1 --mount=/,/dev/nvme0n1p2 --mount=swap,/dev/nvme0n1p3 --ssh-keys-uri="https://provisioning.example.com/keys?id=123"
```

## Troubleshooting

- **Task warning messages** – When you see `Task <name> exited with status …`,
  review the per-task log in `${MCX_LOG_DIR}` (default `/var/log/mcxTemplate`).
  Structured JSON events (including start/finish timestamps and durations) are
  mirrored to `${MCX_STRUCTURED_LOG}` for easy machine parsing.
- **Permissions on log directories** – If mcxTemplate cannot create the default
  log directory, set `MCX_LOG_DIR` to a writable location (for example,
  `/tmp/mcxTemplate-logs`) before invoking `installTemplate.php`.
- **Missing provisioning tools** – When utilities such as `ip`, `mdadm`, or
  `ssh-keygen` are absent, the scripts log a warning and continue with safe
  defaults. Override values with CLI flags (`--network-cidr`, `--gateway`,
  etc.) when auto-detection fails.
- **Task preconditions** – If a task logs `precondition-skipped`, the entry in
  `distros/task_preconditions.php` blocked execution (for example, the `mdadm`
  binary was unavailable). Install the dependency or adjust the precondition map
  before re-running the template.
- **Skipping problematic steps** – Use `MCX_SKIP_TASKS` to disable individual
  tasks without editing the repository. Supply the filename (with or without the
  `.php` suffix), separated by commas or whitespace.
- **Custom `/etc/hosts` template** – Set `MCX_HOSTS_TEMPLATE=/path/to/hosts.tpl`
  (or pass `--hosts-template=` once that CLI option lands) to point at a file
  containing `{{SHORT_HOSTNAME}}`, `{{FQDN}}`, and `{{HOST_IP}}` placeholders.
  If the file is unreadable the script logs a warning and falls back to the
  default Debian layout.

## Versioning & Change Management

- Tag releases with a date-based identifier (for example, `2025.09.20`) so
  rescue environments can pin a known-good template version.
- Maintain a short CHANGELOG entry for each tag summarising behavioural changes
  and new environment variables to keep downstream automation teams informed.
- Track pending and released changes in [`CHANGELOG.md`](CHANGELOG.md). Populate
  the "Unreleased" section before publishing a new tag.
- Update mcxRescue alongside mcxTemplate when introducing new flags or
  environment variables, ensuring both layers stay in lockstep.

## Testing & Operations Guide

1. **Dry run syntax checks** – From the repository root run
   `find distros -name '*.php' -print0 | xargs -0 -n1 php -l` before packaging a
   release. CI should mirror this check.
2. **Chroot smoke test** – In a rescue environment:
   - Mount the target filesystem and chroot into it.
   - Copy the repository to `/opt/mcxTemplate`.
   - Run `php installTemplate.php --hostname=test.example --network-cidr=10.0.0.10/24 --gateway=10.0.0.1 --mount=/,/dev/sda1 --mount=swap,/dev/sda2 --log-dir=/var/log/mcxTemplate-test --skip-tasks=26-run-post-config.php --dry-run` to preview the task plan, then rerun without `--dry-run` to apply changes.
   - Inspect `/var/log/mcxTemplate-test` and `${MCX_STRUCTURED_LOG}` to confirm
     task logs and JSON events were emitted.
3. **Custom hosts template test** – Supply
   `MCX_HOSTS_TEMPLATE=/root/hosts.tpl` containing placeholders, run
   `php distros/common/create-hostname.php` manually, and verify the rendered
   `/etc/hosts` matches expectations and the structured log records the template
   path.
4. **Skip list validation** – Set `MCX_SKIP_TASKS="05-clear-log-files task-does-not-exist"`
   and execute `php installTemplate.php`. Confirm skipped tasks are logged as
   such and that missing entries do not abort execution.
5. **Post-config & SSH key fetch** – For online testing, point `--ssh-keys-uri`
   and `--post-config` at known endpoints; ensure warnings appear when the
   resources are unreachable.
6. **Structured log parsing** – Use `tools/analyze-structured-log.php --input=$MCX_STRUCTURED_LOG`
   to collate per-task summaries, or feed the JSON lines into `jq`/log pipelines
   for near real-time monitoring.

After installing PHPUnit 9+, execute `./vendor/bin/phpunit --configuration tests/phpunit.xml.dist`
to run the unit tests. The suite currently exercises the Configurator helpers, provisioning template
utilities, tooling assembly scripts, and the shared Common abstractions to keep regressions obvious.

For the full template capture workflow, see `docs/template-authoring.md`, which outlines how to stage a
reference system, optionally prune unique identifiers, and produce the tarball with the packaging helpers.

Clone or sync this repository directly to the target system at
`/opt/mcxTemplate`. The automation expects its working directory to match this
path so that relative references resolve without extra configuration. If the
repository must live elsewhere, create a symlink back to `/opt/mcxTemplate` so
scripts keep functioning.
