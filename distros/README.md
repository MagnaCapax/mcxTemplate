# Distribution Hooks

The `distros/` tree stores provisioning hooks that run inside the mcxTemplate
chroot. Each distro keeps its task scripts alongside documentation so behaviour
stays predictable across releases.

## Template Installer Overview

The provisioning entry point is `configure.php`. The script reads the current
system's `/etc/os-release`, determines the distro and major version, and then
executes the matching task directories. Tasks are standard PHP files so every
step can share the helper routines in `distros/common/lib/Common.php` without
bringing in extra interpreters or rewriting control flow.

Execution order mirrors the historical Bash runner:

1. Load shared helpers and guard that the operator is running as root.
2. Detect the distro ID/version (or accept overrides from `MCX_DISTRO_ID` and
   `MCX_DISTRO_VERSION`).
3. Run `common/tasks/` for the distro, followed by any files in
   `common/user.d/` so local sites can layer custom behaviour.
4. When a version directory exists (for example `debian/bookworm/`), run its
   `tasks/` and `user.d/` directories in the same order.
5. Warn instead of aborting when optional helpers are missing so the installer
   stays resilient during partially prepared images.

Each task script executes through the system PHP binary via `proc_open()`.
This keeps logging consistent, avoids shell quoting edge cases, and mirrors how
operators would run the helpers manually for troubleshooting.

## Key Files and Rationale

- `configure.php` – Single entry point that glues detection, logging, and task
  orchestration together. Centralising the logic avoids subtle differences
  between distros and keeps the execution sequence predictable.
- `common/lib/Common.php` – Shared logging, privilege guards, command runners,
  and helper utilities. Using one library keeps behaviour consistent while
  letting tasks stay focused on their immediate job.
- `<distro>/common/tasks/` – Default task set for the distro. Files use numeric
  prefixes (`10-`, `20-`, …) so alphabetical sorting provides a deterministic
  pipeline that mirrors the provisioning checklist.
- `<distro>/templates/` – Distro-scoped templates referenced by the shared helpers
  when rendering configuration files.
- `<distro>/<version>/tasks/` – Optional overrides when a release needs
  different behaviour. The directory structure mirrors the common tasks so
  maintainers only fork when necessary.
- `task_preconditions.php` – Optional map of task filenames to prerequisite checks
  (for example, asserting required binaries exist before a task runs). Each
  entry is keyed by the task filename and may declare `command`, `env`, or
  `env_any` checks; tasks lacking their prerequisites are skipped with an
  informative log message.
- `user.d/` directories – Git-ignored hooks that downstream operators can use to
  append site-specific actions. They run after the built-in tasks, providing a
  stable escape hatch without patching upstream code.
- `../common/*.php` – Shared helpers (for example hostname and network writers)
  that supply repeatable building blocks across distros.

## Adding a New Distro

1. Create `distros/<distro>/common/tasks/` and populate ordered PHP scripts.
2. Add optional `distros/<distro>/<version>/tasks/` when a release diverges from
   the shared defaults.
3. Drop any local overrides into `user.d/` while keeping them out of version
   control.
4. Document the flow in a `README.md` inside the distro directory so future
   contributors understand the expectations.

This layered layout keeps common logic in one place and only forks code when a
release genuinely needs different behaviour.

## Template Assets

Templates live alongside the tasks in `<distro>/templates/` and fall back to `distros/common/templates/`
when a distro-specific file is absent. They are plain text with `{{PLACEHOLDER}}` markers that the
shared helpers replace via `str_replace()`. Each render emits a structured `template-apply` log entry
recording the template path, destination file, and rendered content hash so operators can audit the
changes applied inside the chroot.
