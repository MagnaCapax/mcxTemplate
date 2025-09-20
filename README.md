# mcxTemplate

mcxTemplate delivers a simple, repeatable templating engine for installing bare
metal systems from live images. The repository keeps the configuration that runs
inside the target chroot after mcxRescue prepares disks and copies a template
filesystem into place.

## Provisioning Architecture

mcxTemplate assumes the provisioning host follows the mcxRescue workflow:

1. **Boot rescue environment** – Nodes boot into mcxRescue (or another live
   image) that ships the disk preparation logic.
2. **Partition and populate** – The rescue scripts wipe target disks, create
   partitions, format filesystems, mount the target paths, and extract the
   mcxTemplate tarball onto the new root filesystem.
3. **Chroot hand-off** – mcxRescue enters the chroot and runs
   `/opt/mcxTemplate/installTemplate.php`. This entry point invokes the distro
   configurator shipped in this repository.
4. **Distro configuration** – `distros/configure.php` detects the active distro,
   runs shared tasks from `distros/<distro>/common`, then executes any
   version-specific overrides. Hooks live entirely inside the target chroot, so
   every change directly affects the freshly cloned system.
5. **Logging and reboot** – mcxRescue captures stdout/stderr from the scripts,
   unmounts the target filesystems, and reboots the machine into the installed
   OS.

This flow keeps the template build consistent and avoids running destructive
operations inside the final system.

## Repository Layout

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
