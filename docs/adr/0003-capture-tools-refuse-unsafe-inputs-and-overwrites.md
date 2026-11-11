# ADR-0003: Capture tools refuse unsafe inputs and silent overwrites

## Metadata
- Author: Väinämöinen
- Date: 2026-11-11
- Status: Accepted

## Context
The template capture tools (`templateAssemble.php`, `templateRehydrate.php`, `templateCloneRemote.php`) feed the archives that the install core (ADR 0002) extracts onto customer disks. Their test suite had not run to completion on CI since 2025-11. Once it did, the failing cases showed tool behaviour that the tests already expected and the tools did not have:
- `realpath('')` resolves to the working directory, so `--template ''` or a bare `--template` bundled the current directory as the engine.
- Host, user and `user@host` values reached ssh and rsync unchecked, so a value starting with `-` (for example `-oProxyCommand=...`) would be read as an option.
- `templateAssemble.php` and `templateRehydrate.php` replaced an existing `--output` file without asking, while `templateCloneRemote.php` already refused unless `--force` was given.
- `templateAssemble.php` found an unwritable output directory only after a full rsync.

## Options Considered
- Keep the old behaviour and change the tests. This leaves the cwd-as-engine and option-injection paths open in tools whose output is installed as root.
- Make the tools refuse, with one consistent `--force` for overwrites across all three. This is a visible change for a script that reruns with a fixed output name.

## Decision
- An empty or missing path value is an error, never the working directory (`resolveLocalPath()` in `tools/lib/packageCommon.php`).
- Host, user and `user@host` values must start with a letter, digit or underscore and contain only name, address and bracket characters (`isSafeSshToken()` in `tools/lib/packageCommon.php`); anything else is refused before any remote work.
- `templateAssemble.php` and `templateRehydrate.php` refuse an existing `--output` file unless `--force` is given, the same rule `templateCloneRemote.php` already had.
- `templateAssemble.php` checks that the output directory can be created and written before it starts the copy, and refuses a `php://` output path.

## Consequences
- A rerun that writes to the same output name now needs `--force`. This is the one change existing callers can notice.
- Hosts addressed by a name that starts with another character (for example a leading dot) are refused; use the plain host name or address.
- Each refusal has a test that fails against the old tools.

## References
- ADR 0001 (architecture), ADR 0002 (install core consumes these archives).
- `tools/README.md` (synopses updated).
