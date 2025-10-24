# Testing Methodology

This repo provisions Linux systems in a chroot, so tests must be safe, repeatable,
and non‑destructive by default. We follow nodeCore’s testing philosophy (hermetic
unit tests first, then focused integration), and PMSS’s separation of dev‑time
tests vs. production probes.

## Goals

- Hermetic by default: no network, no device mutations, no root required.
- Deterministic: fixed inputs and temp directories; no reliance on host state.
- Observable: assert on return codes, file outputs, and structured logs.
- Idempotent: repeat runs do not require cleanup beyond temp directory removal.

## Layers

1) Unit (pure PHP)
- Target: `src/Lib/Provisioning/*`, `src/Lib/Common/*`, `tools/check-template.php` helpers.
- Approach: use temp files/dirs; do not write to absolute system paths.
- Examples: argument parsing, mount spec normalisation, template lookup, structured logging, skip‑task set, auditor helpers.

2) Tool smoke (CLI, non‑destructive)
- Target: `tools/*` CLIs with `--help`, directory‑only modes, synthetic fixtures.
- Approach: spawn subprocesses (`proc_open`), capture exit codes. Create and remove temp trees per test. Avoid network.
- Examples: run `tools/check-template.php` on synthetic directories/tarballs; verify exit codes and required labels.

3) Staging‑root integration (optional in CI)
- Target: distros entrypoint and tasks in dry‑run.
- Approach: create a temp “staging root”, set env (`MCX_TEMPLATE_ROOT`, `MCX_DRY_RUN=1`, `MCX_LOG_DIR` to a temp dir), call `php distros/configure.php --dry-run ...`, and assert structured log lines and task listings. This exercises orchestration without touching `/etc/*`.
- Note: tasks that write absolute paths are skipped in dry‑run; the goal here is to validate detection, ordering, skip‑sets, and logging, not file mutations.

4) Lab E2E (manual/ops)
- Run inside a throwaway chroot or VM with root. Partition/mount, extract a template, then run `installTemplate.php` for real. Capture logs/structured events. These are documented runbooks, not CI jobs.

## Framework

- PHPUnit 9.x for unit/smoke. Config: `tests/phpunit.xml.dist`, bootstrap: `tests/bootstrap.php`.
- Static checks: `php -l`. For Bash snippets: `bash -n`, `shellcheck`, and `shfmt -w` (when applicable).
- Runner script (optional): `tools/run-tests.sh` to standardise local execution.

## CI (GitHub Actions)

- CI is wired under `.github/workflows/ci.yml` and runs on Ubuntu across PHP 8.1/8.2/8.3.
- It installs `pigz` and `rsync` so the rehydration tests can run.
- The workflow runs `bash tools/run-tests.sh`, which performs PHP syntax checks and runs PHPUnit (via Composer, global install, or PHAR provided by setup-php).

## Design for Testability (Future)

To broaden integration coverage without root:
- Introduce an opt‑in env var (e.g., `MCX_ROOT_PREFIX`) so file‑writing helpers redirect absolute destinations (like `/etc/hosts`) into a temp prefix during tests.
- Centralise all file writes through shared helpers (already true for templates) so the prefix applies consistently.
- Keep defaults unchanged for production; the prefix is for tests/dev only.

## Adding Tests

- Mirror namespaces: `tests/unit/Provisioning/*` for `src/Lib/Provisioning/*`.
- Use `sys_get_temp_dir()` for fixtures; clean up in `tearDown()`.
- Assert on exit codes rather than stdout noise when using `passthru`.
- Avoid adding external package dependencies; if a dev‑only tool is needed, document and gate tests with `markTestSkipped()` when missing.
