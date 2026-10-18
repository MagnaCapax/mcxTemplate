# Development

Start here if you change this repository, by hand or with a coding agent.

## Read first

1. [`AGENTS.md`](../AGENTS.md): the rules for this repository, short.
2. [Engineering principles](engineering-principles.md): the organisation-wide rules with their reasons and the mistakes that taught them. Adapted copy; the canonical one is in mcxPikkumies.
3. [Decision records](../docs/adr/): accepted ADRs are binding. A significant change ships with a new one ([template](../docs/adr/0000-template.md)). Start with [ADR-0001](../docs/adr/0001-architecture-layout-and-languages.md), the layout and languages of this repository.
4. [README](../README.md): what mcxTemplate does, the `configure.php` options and environment variables, and the release practice.
5. [Distribution hooks](../distros/README.md) and the [Debian tasks](../distros/debian/README.md): how the task pipeline runs inside the chroot.
6. [Tools](../tools/README.md): the template capture, rehydration and audit tools.
7. [Testing methodology](../docs/tests.md) and the [test suite notes](../tests/README.md).
8. [Changelog](../CHANGELOG.md): add an entry to "Unreleased" for every behaviour change.

## Before you commit

- `bash tools/run-tests.sh` passes. It is what CI runs (`.github/workflows/ci.yml`, PHP 8.1, 8.2 and 8.3): a `php -l` syntax check of every PHP file, then the PHPUnit suite in `tests/phpunit.xml.dist`. Read the summary and the exit code; do not pipe it through anything that hides them.
- Have PHPUnit 9 installed (`vendor/bin/phpunit`, a global `phpunit`, or `phpunit-9.phar` in the repository root). Without it the runner prints `PHPUnit not found; skipping unit tests.` and still exits 0, so a green result without that line is the only one that counts.
- Some tool tests need `tar`, `pigz` and `rsync`; they are skipped when those are missing. CI installs `pigz` and `rsync`.
- New behaviour has a test that fails when the behaviour is broken (break it once and see).
- Review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review): correctness, an adversarial pass, weakness classes, and conformance to `AGENTS.md`, the ADRs and the principles. Any agent can follow its `skill.md`.
- No provider, model or vendor name anywhere, and nothing that is not meant for the public: this repository is public.

## Other MagnaCapax repositories

Each has its own `AGENTS.md`, decision records and docs. When your change touches another project, read its records first. mcxTemplate runs inside the chroot that [mcxRescue](https://github.com/MagnaCapax/mcxRescue) prepares, so a new flag or environment variable here is usually a change there too.

| Repository | What it is | Docs | Decisions |
|---|---|---|---|
| [PMSS](https://github.com/MagnaCapax/PMSS) | Pulsed Media Seedbox Software, the platform Pikkumies ships on | [docs](https://github.com/MagnaCapax/PMSS/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/PMSS/tree/main/docs/adr) |
| [mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies) | Lightweight helper agent that runs on the server it manages | [docs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs/adr) |
| [mcxVirsilipas](https://github.com/MagnaCapax/mcxVirsilipas) | Prepaid token-metering proxy for model APIs | [docs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs/adr) |
| [mcxForge](https://github.com/MagnaCapax/mcxForge) | Bare-metal qualification, repair and installation suite | [docs](https://github.com/MagnaCapax/mcxForge/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxForge/tree/main/docs/adr) |
| [mcxRescue](https://github.com/MagnaCapax/mcxRescue) | Rescue live system for bare-metal servers | [docs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs/adr) |
| [mcxTemplate](https://github.com/MagnaCapax/mcxTemplate) | This repository | [docs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs/adr) |
| [nodeAgent](https://github.com/MagnaCapax/nodeAgent) | Server telemetry and hardware health agent | [docs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs/adr) |
| [mcxSerp](https://github.com/MagnaCapax/mcxSerp) | Search results API client for PHP | [docs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs/adr) |
| [mcxSauces](https://github.com/MagnaCapax/mcxSauces) | Scripts and configs for running systems and services | [docs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs/adr) |
| [mcxBMCView](https://github.com/MagnaCapax/mcxBMCView) | BMC console screenshot and keyboard automation | [docs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs/adr) |
