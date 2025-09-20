# Debian Tasks

Debian provisioning executes the PHP task scripts stored in `common/tasks/`. Files run in
lexicographic order so the numeric prefixes define the pipeline. All helpers share the
`Distros\Common\Common` library which now proxies the reusable `src/Lib/Provisioning` classes,
keeping logging, structured events, and command execution behaviour identical everywhere.

## Task Breakdown

- `05-clear-log-files.php` – Removes Debian log archives so new clones boot without inherited
  log history.
- `07-truncate-login-ledgers.php` – Truncates `wtmp`, `btmp`, and `lastlog` to clear login state.
- `09-empty-runtime-directories.php` – Empties transient directories such as `/tmp` and
  `/var/log/journal`.
- `11-remove-cache-artifacts.php` – Prunes apt and manpage caches left from the template build.
- `12-clear-cloud-state.php` – Clears `cloud-init` artefacts to avoid reusing instance metadata.
- `13-reset-ssh-host-keys.php` – Deletes host keys and regenerates them on first boot when
  `ssh-keygen` is present.
- `15-reset-machine-ids.php` – Removes `/etc/machine-id` and associated D-Bus identifiers.
- `17-reset-systemd-entropy.php` – Drops cached systemd random seeds and credentials.
- `19-clear-udev-and-resume.php` – Clears persistent udev rules, `machine-info`, and stale resume
  configuration.
- `20-prepare-hostname.php` – Normalises hostname context (`MCX_SHORT_HOSTNAME`, `MCX_FQDN`, etc.).
- `21-render-hostname-files.php` – Applies the hostname and hosts templates for the distro.
- `22-render-network-config.php` – Detects CIDR/gateway information, selects the primary
  interface, and renders `/etc/network/interfaces` from the distro template.
- `24-fetch-ssh-keys.php` – Fetches remote SSH authorized keys when URIs are supplied.
- `26-run-post-config.php` – Downloads and executes an optional site-specific post-config script.
- `30-render-fstab.php` – Rebuilds `/etc/fstab` using the resolved mount specification.
- `32-capture-mdadm.php` – Writes `mdadm.conf` when mdadm metadata is available.
- `40-update-boot.php` – Refreshes initramfs images and reinstalls GRUB on the configured targets.

## Templates and Overrides

Configuration files rendered by these tasks source templates from
`distros/debian/templates/`. Each template uses simple placeholder substitution via
`str_replace()` so regeneration is idempotent:

- `hostname.tpl` – Writes `/etc/hostname`.
- `hosts.tpl` – Writes `/etc/hosts` (custom paths can override via `MCX_HOSTS_TEMPLATE`).
- `network/interfaces.tpl` – Writes `/etc/network/interfaces`, populated with the detected or
  overridden primary interface.
- `fstab.tpl` – Writes `/etc/fstab` based on the resolved mount specification.

Place additional templates under this directory when new config writers are added. Scripts will
fall back to `distros/common/templates/` if a distro-specific asset is unavailable.

Site operators may drop PHP overrides into `common/user.d/`; these run after the built-in tasks.
`MCX_SKIP_TASKS` (or the `--skip-tasks` option) accepts comma- or whitespace-delimited names that
match against task filenames with or without the `.php` suffix, letting automation disable steps
without patching the repository.

Task preconditions are defined in `distros/task_preconditions.php`; for Debian the `32-capture-mdadm.php`
task now declares a dependency on the `mdadm` binary, while `24-fetch-ssh-keys.php` executes only when
`MCX_SSH_KEYS_URI` is present.

## Logging & Observability

Every task streams console output and writes structured JSON events to the directory configured
via `MCX_LOG_DIR` (default `/var/log/mcxTemplate`). Structured logs contain per-task start/finish
records, exit codes, and durations; `tools/analyze-structured-log.php` can summarise the JSON
stream for post-run analysis.

## Version Overrides

Create `distros/debian/<version>/tasks/` when specific releases require additional steps. The
orchestrator runs the common tasks first, then executes the version-scoped directory (and any
`user.d` overrides) if it exists. Debian currently relies solely on the shared `common` tasks.
