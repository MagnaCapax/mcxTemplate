# ADR-0009: The release refuses a rootfs without php and smartctl

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-14
- Status: Proposed

## Context
The install engine partitions the disk, extracts the template, and only then runs `chroot <target> php /opt/mcxTemplate/installTemplate.php` ([ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md)). A template whose rootfs has no php therefore ends the install after the disk is already wiped: the first live attempt with a captured Ubuntu 24.04 rootfs stopped with `configure exited 127: chroot: failed to run command 'php'` and left the machine with no system. The hardware check after the install calls `smartctl`, and a minimal Debian template without `smartmontools` failed it. `distros/debian/12/minimal.packages` lists `php-cli` and `smartmontools` for this reason, but nothing checked a captured rootfs, or any other build, at release time.

## Options Considered
- Check at install time, before wiping: the engine cannot see inside the archive cheaply before extraction, and the template is already published by then. Kept as a possible later layer.
- Check in `check-template.php`: that tool audits the layout of the template, not the programs of the rootfs, and it also runs on the node.
- Check in the release, while `ContentScan` already streams the archive once: chosen. A bad template is never published.

## Decision
- `tools/templateRelease.php` refuses a rootfs that lacks an executable `usr/bin/php` or `usr/sbin/smartctl` with `templateMissingRuntime`, naming the missing path(s), before anything is built or written.
- The path is followed inside the archive (`src/Lib/Release/RuntimeCheck.php`): Debian and Ubuntu ship `usr/bin/php -> /etc/alternatives/php -> /usr/bin/php8.x`. Absolute link targets start at the rootfs root, never at the build host; directory links (merged `/usr`) are followed; hard links count; at most 40 links are followed per path, so a loop or a dangling link is a refusal, not a hang.
- A regular file counts only with an execute bit. `ContentScan` hands the check every link and executable file in its single pass over the archive; nothing is extracted.
- Content findings (keys, passwords, banned packages) are reported first; the runtime refusal follows when the content is clean.

## Consequences
- A rootfs that provides php or smartctl under another path (not `usr/bin/php`, `usr/sbin/smartctl`) is refused until the path exists; the refusal names it.
- A captured template must carry php-cli and smartmontools. The cost is one release refusal instead of a wiped node.
- Further programs the engine needs can be added to `RuntimeCheck::REQUIRED`.

## References
- [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md), [ADR-0007](0007-templates-may-be-built-from-the-debian-archive.md)
- `src/Lib/Release/RuntimeCheck.php`, `src/Lib/Release/ContentScan.php`, `src/Lib/Release/ReleaseBuilder.php`, `src/Lib/Install/LayoutPlanner.php`
- `tests/unit/Release/RuntimeCheckTest.php`, `tests/unit/Release/ReleaseBuilderTest.php`
