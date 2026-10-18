# ADR-0001: Architecture, layout and languages

## Metadata
- Author: Aleksi Ursin / MCX
- Date: 2026-10-18
- Status: Accepted

## Context

mcxTemplate configures a freshly installed Linux root filesystem from inside a chroot. A rescue or live system (normally [mcxRescue](https://github.com/MagnaCapax/mcxRescue)) partitions and formats the disks, mounts the target, extracts a template archive onto it, enters the chroot and runs `/opt/mcxTemplate/installTemplate.php`. mcxTemplate then resets the identity a clone must not inherit (logs, machine ids, SSH host keys, cloud and udev state), writes hostname, hosts, network and fstab from templates, captures mdadm state, fetches optional SSH keys and a post-config script, and refreshes the initramfs and boot loader. Destructive disk work (partition, format, wipe) is out of scope; it belongs to the rescue layer. The repository also ships the tools that capture a template from a live host or a disk image and bundle the engine into it.

This repository had no decision records. This ADR writes down the architecture as it is, so later ADRs have something to supersede, and records how the organisation-wide [engineering principles](../../development/engineering-principles.md) apply here.

## Options Considered
- Leave the architecture undocumented outside the READMEs: rejected. The READMEs describe usage; they do not say which choices are deliberate, so the next contributor cannot tell a decision from an accident.
- Record it in one ADR, with later changes as new ADRs that supersede parts of it: chosen.

## Decision

### Layout and what each part owns

| Path | Owns |
|---|---|
| `installTemplate.php` | Entry point run inside the chroot. Requires root, then hands the arguments to `distros/configure.php`. |
| `distros/configure.php` | The orchestrator. Parses flags, detects the distribution and major version from `/etc/os-release` (or `MCX_DISTRO_ID` / `MCX_DISTRO_VERSION`), then runs `<distro>/common/tasks/`, `<distro>/common/user.d/`, and the version's `tasks/` and `user.d/` when they exist. Each task runs as its own PHP process through `proc_open()`. Writes per-task logs and structured JSON events; supports `--dry-run` and `--skip-tasks`. |
| `distros/task_preconditions.php` | Map of task file name to prerequisites (`command`, `env`, `env_any`). A task whose prerequisites are missing is skipped with a log line. |
| `distros/<distro>/common/tasks/` | The ordered task pipeline. Debian has 17 tasks, `05-clear-log-files.php` to `40-update-boot.php`. |
| `distros/<distro>/templates/`, `distros/common/templates/` | Plain-text templates with `{{PLACEHOLDER}}` markers; the distribution's copy first, the common copy as fallback. |
| `distros/<distro>/**/user.d/` | Site-specific hooks, ignored by Git, run after the built-in tasks. The supported way to add local behaviour without patching the repository. |
| `distros/common/` | Shared writers (hostname, fstab, network config) and `lib/Common.php`, which exposes the provisioning helpers under the historical `Distros\Common` namespace. |
| `src/Lib/Provisioning/` | `Common.php` (logging, privilege guard, template lookup and rendering, command running) and `Configurator.php` (argument parsing, mount normalisation, skip sets). Kept as classes so the tests can call them directly. |
| `src/Lib/Common/` | `System.php` (command helpers) and `Logging.php`. |
| `src/Common/Lib/Common.php` | `CommonHelpers`, a narrow facade over the provisioning helpers. When this ADR was written only its own test referenced it. |
| `tools/` | Template capture and packaging CLIs: `templateCloneRemote.php`, `templateCloneImage.php`, `templateAssemble.php`, `templateRehydrate.php`, the auditor `check-template.php`, `analyze-structured-log.php`, the shared `tools/lib/packageCommon.php` and `tools/lib/rehydration.php`, and the test runner `run-tests.sh`. |
| `tests/` | PHPUnit 9 suite under `tests/unit/{Common,Provisioning,Tools}`, configured by `tests/phpunit.xml.dist`. |
| `docs/` | `tests.md` (testing methodology) and these decision records. |

The engine is deployed by copying the repository to `/opt/mcxTemplate` in the target root. The capture tools embed it at `rootfs/opt/mcxTemplate` by default, or stage it under `template/` with `--no-embed-into-rootfs`.

### Languages

All logic is PHP, following principle 1 (PHP for logic, shell only as thin glue, no Python). The repository's own docs give the reasons this project chose it: tasks are PHP files "so every step can share the helper routines ... without bringing in extra interpreters or rewriting control flow", and running each one through the PHP binary with `proc_open()` "keeps logging consistent, avoids shell quoting edge cases" ([distros/README.md](../../distros/README.md)). The task runner replaced an earlier Bash runner; its order is kept ("Execution order mirrors the historical Bash runner").

That choice has a requirement: the target root filesystem must have a PHP CLI, because `installTemplate.php` and every task run with the chroot's own `php`. The capture tools run on a build host that also needs PHP.

PHP version: CI runs PHP 8.1, 8.2 and 8.3 with the `posix` and `pcntl` extensions. Files use `declare(strict_types=1)`.

Shell: `tools/run-tests.sh` is the only shell file. It is 32 lines and branches (file discovery with or without `rg`, three ways to find PHPUnit), so under principle 1 it is past the glue limit; it becomes PHP the next time it needs a change. No Python, no Composer runtime dependencies.

### Build, run and test
- Build: none. The README asks for releases as date-based tags (for example `2025.09.20`) with a `CHANGELOG.md` entry; no tag existed when this ADR was written.
- Run: inside the chroot, `php /opt/mcxTemplate/installTemplate.php --hostname=... --mount=/,<device> ...`. `--dry-run` (or `MCX_DRY_RUN`) prints the task plan without running it. Logs go to `--log-dir` / `MCX_LOG_DIR` (default `/var/log/mcxTemplate`), structured events to `MCX_STRUCTURED_LOG`.
- Test: `bash tools/run-tests.sh`, the same command CI runs. It checks every PHP file with `php -l`, then runs PHPUnit if it can find it. When PHPUnit is missing it prints a skip line and still exits 0, so read the output. Tests are hermetic by default: temporary directories, no network, no device changes, no root ([docs/tests.md](../tests.md)).

### Conventions in use
- Task files are named `NN-what-it-does.php`. The number fixes the order, because the orchestrator sorts by name. The names are also an interface: `--skip-tasks`, `MCX_SKIP_TASKS` and the keys of `task_preconditions.php` match them.
- Templates use plain `str_replace()` on `{{PLACEHOLDER}}` tokens. Every render logs a `template-apply` event with the template path, the destination and the SHA-256 of the result.
- Fail soft: a missing optional tool or helper logs a warning and the run continues; a failing task is reported, not fatal to the whole run.
- Configuration comes as `--flags` or `MCX_*` environment variables; the README lists both.
- Functions and methods are camelCase (`parseArguments`, `commandExists`), classes UpperCamelCase. File names are mixed: camelCase (`templateAssemble.php`), kebab-case (`check-template.php`, `create-fstab.php`) and one snake_case (`configure_hostname.php`).
- At least one comment line per ten lines of code (AGENTS.md).

### How the principles apply
1. **PHP, shell as glue:** as above. A new tool or task is PHP.
2. **Files around 150 lines:** 9 of the 38 tracked runtime PHP files were over 150 lines when this ADR was written; the largest is `distros/configure.php` at 673. Split them when changing them, not in a sweep.
3. **camelCase, broad to specific:** new variables, functions, classes and non-task code files are camelCase. Task files keep the `NN-name.php` pattern, because the prefix is the ordering mechanism. Existing files are not renamed for the convention's sake: task names are matched by `--skip-tasks`, `MCX_SKIP_TASKS` and `task_preconditions.php`, and tool names appear in the documented commands that users copy into their own automation (principle 7).
4. **KISS, DRY, YAGNI:** one orchestrator for every distribution; a release forks its own `tasks/` only when it really behaves differently.
5. **Delete first:** before adding to a facade or helper layer, check who calls it.
6. **Pit of success:** `--dry-run` exists for every run; site changes go in `user.d/`, not in patched upstream files; downloaded keys and post-config scripts can be pinned with SHA-256.
7. **Never break old users:** legacy flags (`--root-device`, `--home-device`) keep working and are translated into the mount list; flags, environment variables and task names stay stable; new flags are coordinated with mcxRescue.
8. **Tests that can fail:** every change runs `bash tools/run-tests.sh` with PHPUnit present; a new behaviour gets a test that fails when the behaviour is broken.
9. **Decisions are binding:** significant changes ship with an ADR in this directory.
10. **Fail soft, explain why:** as in the conventions above.

## Consequences
- A contributor can see the intended layout and language rule without reading the code first.
- Known gaps, recorded here rather than fixed in this ADR: `tools/run-tests.sh` is over the shell limit; nine runtime files are over the size target; `AGENTS.md` still carries a "default to Bash" language line that predates principle 1 and needs a decision of its own.
- Any change to the layout, the entry point, the task interface or the language rule needs a new ADR that supersedes the relevant part of this one.

## References
- [README.md](../../README.md), [distros/README.md](../../distros/README.md), [distros/debian/README.md](../../distros/debian/README.md), [tools/README.md](../../tools/README.md), [docs/tests.md](../tests.md)
- [Engineering principles](../../development/engineering-principles.md)
