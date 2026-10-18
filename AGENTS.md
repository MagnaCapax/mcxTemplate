# Repository Guidelines

Start with [`development/`](development/README.md): the [engineering principles](development/engineering-principles.md) explain the organisation-wide rules below with their reasons and the mistakes that taught them, and [ADR-0001](docs/adr/0001-architecture-layout-and-languages.md) records this repository's layout and languages.

## Project Context
- **Purpose**: mcxTemplate operates as a templating engine for distro installations; align automation choices with provisioning workflows suitable for that environment.
- **Shared Library First**: Prefer reusing the PHP helpers in `src/Common/Lib/Common.php` and `src/Lib/Common/` before writing new logic.

## Core Principles
- **KISS Principle**: Keep implementations simple, readable, and direct. Avoid unnecessary abstractions or over-engineering.
- **Single-Method Consistency**: When a problem has already been solved in this codebase, reuse the established method instead of introducing alternate approaches. Prefer shared helpers/abstractions over duplicating logic.
- **MVC Layering Mindset**: Organize logic so that data access, business rules, and presentation/output responsibilities remain clearly separated. Apply this separation consistently from method structure to overall file organization.
- **Fail-Soft Bias**: Favor recovering and continuing whenever safe instead of terminating execution. Only halt when the outcome would be catastrophic or data-damaging, and document the reason when an exit becomes unavoidable.
- **Readability & Reuse**: Prioritize human readability, comment generously, and reuse existing helpers rather than duplicating logic in new forms.
- **Predictable Provisioning Flow**: Scripts should follow a deliberate sequence—detect environment, gather inputs, prepare resources, execute actions, and report status—mirroring the clone-and-configure workflow in the reference tooling. Make every transition between steps explicit.
- **Change Justification**: Only make modifications when there is a clear, documented reason. Do not alter stable, long-lived behavior without evidence that change is required.
- **Commenting Rule**: Maintain comments such that, on average, at least one line of commentary appears for every ten lines of code (Linux Kernel style guidance).
- **Language Policy**: PHP for anything that parses data, branches, keeps state or calls an API; shell only as thin glue (a cron line, an exec wrapper, a few lines that start the real program). Do **not** introduce Python; if a requirement appears to demand it, escalate instead of adding a Python dependency. The reasons are in `development/engineering-principles.md`, principle 1.
- **Delete First**: Question the requirement, delete the part, simplify, then speed up, then automate. A needed but broken part is debugged, not deleted.
- **Pit of Success**: The safe path is the default; risky, destructive or debug-only paths need an explicit, visible opt-in (see Safety First below).
- **Never Break Old Users**: Keep flags, environment variables, task file names and file formats working. Find every caller before changing one; coordinate new flags with mcxRescue.
- **Tests That Can Fail**: Every change comes with tests, and a test must be able to fail: break the code once and see it caught. Run `bash tools/run-tests.sh` with PHPUnit installed and read its result; without PHPUnit it skips the unit tests and still exits 0.
- **Files Around 150 Lines**: A target, not a hard limit. Split a file that grows past it or starts doing two jobs, when you are changing it anyway.
- **Naming**: camelCase for variables, functions, classes and new code files, read from the broadest context to the most specific (subject, event, unit). Task files keep the `NN-name.php` pattern because the prefix sets the order (ADR-0001).
- **Decision Records**: Accepted ADRs in `docs/adr/` are binding: follow them or supersede them with a new ADR, never work around them. A significant behaviour, interface, security or workflow change ships with an ADR (template `docs/adr/0000-template.md`, one subject per ADR).
- **Review Before Commit**: Review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review) against these rules and the ADRs. This repository is public: no provider, model or vendor names, credentials or internal data in code, docs, tests or commit messages.

## Operational Philosophy
- **Safety First**: Destructive actions (partitioning, formatting, wiping identifiers) must be guarded with clear intent checks, informative logging, and opportunities for dry runs or confirmation steps where practical.
- **Environment Awareness**: Account for dual-boot/RAID boot devices and NVMe storage layouts similar to the sample provisioning script. When adapting logic, confirm device names, RAID membership, and mount points remain consistent across the workflow.
- **Idempotence and Recovery**: Design routines so that rerunning them on partially prepared systems is safe. Prefer explicit cleanup helpers (e.g., stopping arrays, unmounting filesystems) rather than ad-hoc sequences.
- **Observability**: Provide concise status output (`print_step`-style helpers, logging) to make long-running operations traceable when executed on bare-metal targets.

## Formatting & Linting
- **Bash**: Run `shellcheck` on every script and format with `shfmt -w` using the repository's style settings. Address all warnings before submitting changes.
- **PHP**: Execute `php -l` for syntax validation and format code with a PSR-12–compliant tool such as `php-cs-fixer` or `phpcbf` configured for this project.
- **Consistency**: Reuse existing helpers or configuration files in this repository when invoking the tools above. If new configuration is genuinely necessary, document the reason alongside the change.

## Operational Verification
- **Baseline Checks**: Until a formal test suite exists, run lightweight confirmations before committing—`bash -n`, `shellcheck`, and `php -l` as applicable—to ensure syntax correctness.
- **Safe Execution Proof**: When possible, exercise non-destructive entry points such as `--help`, `--dry-run`, or environment-detection routines and note the observed output. If the change affects destructive steps, document reasoning or out-of-band validation that supports the update.
- **Manual Traceability**: Record the commands or scenarios reviewed (including dry runs or log captures) so reviewers can follow the verification story.

## Dependency Policy
- **Default Stance**: Avoid adding new system packages, Composer dependencies, or external binaries unless there is a clear, reviewable justification.
- **Proposal Process**: If a new dependency is required, open a discussion or issue before implementation that explains the benefit, maintenance cost, and security considerations.
- **Implementation**: After approval, document installation steps and configuration updates within the repository so future contributors can reproduce the setup.

## Documentation Updates
- **Keep Docs Current**: Update README files, inline comments, usage examples, and configuration references whenever behavior or interfaces change.
- **Cross-Checks**: Review the diff to confirm new or modified logic has matching narrative documentation and comment coverage aligned with the 1-in-10 guideline.
- **Review Expectation**: Pull requests lacking necessary documentation revisions should be considered incomplete until updates accompany the code changes.

## Workflow Expectations
- **Required Checks**: Run the linting and operational verification steps outlined above before committing. Document the commands and their results in your work notes.
- **Future Instructions**: Check for additional `AGENTS.md` files within subdirectories before modifying files there; follow the most specific applicable instructions.
