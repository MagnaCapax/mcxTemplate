# ADR-0006: Templates ship without fail2ban, ufw, crowdsec and sshguard

## Metadata
- Author: Väinämöinen
- Date: 2026-11-13
- Status: Accepted

## Context
A template is a captured root filesystem installed on many machines. The first published templates carried `fail2ban` and `ufw`, because the system they were captured from had them installed and nothing in the release path looked at what packages the capture held. The operator's ruling on 2026-11-13: "we do not use fail2ban", with the instruction to find similar regressions, revert them all and "tear it out".

`ufw`, `crowdsec` and `sshguard` are not named in that ruling. They are the maintainers' classification of the same class: packages that ban or filter inbound connections on the machine itself, which the operator's ruling excludes for `fail2ban`. If the classification is wrong for one of them, the fix is to remove it from the list in `BannedPackages::NAMES`.

Today only Debian and Ubuntu templates exist (`distros/debian`, `distros/ubuntu`), so every template has a dpkg database. No other package manager is supported by this repository, and none is checked here.

## Options Considered
- Remove the packages in an install task after extraction: the machine ends up clean, but the published template still carries them and every install repeats the work. Rejected.
- Refuse by file path (`/etc/fail2ban`, `/usr/sbin/ufw`): misses a package whose files moved and flags a leftover config directory of a removed package. Rejected.
- Read the package database of the captured rootfs and refuse at release time and at install time. Chosen.

## Decision
- Templates ship without `fail2ban`, `ufw`, `crowdsec` and `sshguard`. The names are the constant `BannedPackages::NAMES` in `src/Lib/Install/BannedPackages.php`, the only place they are listed.
- A package is present when `var/lib/dpkg/status` of the rootfs has a stanza with that `Package:` name unless its `Status:` field is well formed and its third word is `not-installed` or `config-files`. Every other state (`installed`, `hold`, `unpacked`, `half-installed`, `half-configured`, the trigger states) counts as present because the package files are on disk. A banned stanza with a missing or unreadable `Status:` counts as present. This is stricter than reading only `install ok installed`.
- The release refuses. `ContentScan` reads `var/lib/dpkg/status` while it streams the captured archive and reports one `bannedPackage <name>` finding per package; `templateRelease.php` therefore ends with the existing refusal `contentScanRefused: bannedPackage fail2ban, ...` and writes nothing. A status file larger than `BannedPackages::STATUS_MAX` (32 MiB) is not read and is refused as `dpkgStatusUnreadable`.
- The install-time check refuses. `tools/check-template.php` reads `rootfs/var/lib/dpkg/status` of the tarball or directory, prints a `BANNED` row per package and the line `bannedPackage: <names>`, and exits 3 (2 stays "parts missing"). `ArchiveCheck::checkLayout` turns exit 3 into `InstallRefusal('bannedPackage', ...)` before any disk is touched.
- Both paths call the same `BannedPackages::installed()`, so the list and the rule cannot differ between release and install. The class sits in `src/Lib/Install`, which the engine tarball already carries.
- The one `PrivateKeyBlock::PUBLIC` entry (a fail2ban package test key, ADR-0004) is removed, since a template with that package is refused now. The mechanism of ADR-0004 stays; the list is empty. ADR-0004's reasoning for the other cases is unchanged.

## Consequences
- A capture must have the packages purged (`apt-get purge`) before it is released; a `remove` that leaves `config-files` is accepted, a package that is still installed is not. The refusal names the packages.
- A template with no `var/lib/dpkg/status` is not refused by this rule: nothing is installed by dpkg in it. Only dpkg distributions exist in this repository; a distribution with another package database needs its own reader before it is supported.
- Templates already published that carry these packages are not changed by this ADR. They are replaced by building a new release from a clean capture.
- Adding a name to the list refuses every later release that has it. The list is data, one constant.

## References
- ADR-0003 (capture tools refuse unsafe inputs), ADR-0004 (release scan, `PrivateKeyBlock::PUBLIC`).
- `src/Lib/Install/BannedPackages.php`, `src/Lib/Release/ContentScan.php`, `tools/check-template.php`, `src/Lib/Install/ArchiveCheck.php`.
- `tests/unit/Install/BannedPackagesTest.php`, `tests/unit/Release/ContentScanTest.php`, `tests/unit/Release/ReleaseBuilderTest.php`, `tests/unit/Tools/CheckTemplateBannedPackageTest.php`, `tests/unit/Install/ArchiveCheckTest.php`.
