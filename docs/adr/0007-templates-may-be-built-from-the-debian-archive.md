# ADR-0007: Templates may be built from the Debian archive

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-13
- Status: Proposed

## Context
Every template so far came from a capture: `templateCloneRemote.php`, `templateCloneImage.php` or `templateAssemble.php` copy the root filesystem of a prepared machine, and `templateRelease.php` turns that archive into a release. A capture carries whatever the source machine had installed. Templates have shipped packages nobody chose for them (a brute-force blocker and a host firewall among them), and missing ones the install needs (a template without `mdadm` could not boot the md `/boot` layout). Each fix meant preparing the golden machine again and capturing it again.

`templateRelease.php` does not care where its `--rootfs` archive came from, as long as it is a root filesystem tar.gz without a `rootfs/` prefix.

## Options Considered
- Keep capturing and audit each capture: the contents still depend on a machine's history, and the audit finds problems after the fact.
- Build with `debootstrap`: needs extra steps for packages beyond its variants and writes a directory, not an archive.
- Build with `mmdebstrap` from the Debian archive, variant `minbase` plus a reviewed package list, straight to a tar: chosen. It is in Debian, installs the hard dependencies of `--include` packages without Recommends by default, and writes the tarball directly.

## Decision
- `tools/templateBuildDebian.php` (logic in `src/Lib/Release/DebianRootfsBuilder.php`) runs `mmdebstrap --variant=minbase --format=tar --include=<list>` and gzips the result to `--out`. Commands are argv arrays.
- The package list is a file under `distros/<distro>/<version>/` (first: `distros/debian/12/minimal.packages`). Each line names one package and, in a `# needed by` comment, the code that needs it. A line without that comment refuses the build, so every package in a template has a recorded reason.
- `src/Lib/Install/BannedPackages.php` ([ADR-0006](0006-templates-ship-without-intrusion-banning-and-firewall-front-end-packages.md)) is the single list of packages a template must not carry. The build refuses a banned package in the list before it starts and in the built image's dpkg status before the archive is written.
- The build writes four files into the image: `/etc/hostname` (`localhost`) and an empty `/etc/resolv.conf` replace the build host's copies that mmdebstrap brings in (the install rewrites both, tasks 21 and 23); an sshd drop-in sets `PermitRootLogin yes`, because Debian's default (`prohibit-password`) would refuse the root password the install sets; and `/etc/default/grub.d/50-mcxtemplate-net.cfg` adds `net.ifnames=0`, so the kernel names NICs `eth0`.. as on captured templates and task 22 names the interface from probe order.
- It refuses a non-root run, an existing `--out` and a missing `mmdebstrap`. Releases still go through `templateRelease.php` unchanged. Captures remain supported.

## Consequences
- A template's contents are the list plus `minbase`, reviewable in one file and reproducible from the Debian archive.
- The list is maintained by hand: a task that starts calling a new binary must add its package with a reason, or the built template lacks it. The boot verification and the strict configure run catch what the boot needs; they do not catch every optional tool.
- Without Recommends some tools that captured systems happened to have are absent (no `firmware-*` packages, no `dbus`). Hardware that needs non-free firmware for its network card is not covered by this list.
- The build needs root and network access to a Debian mirror; the mirror URL is written into the image's apt sources.

## References
- [ADR-0001](0001-architecture-layout-and-languages.md), [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md), [ADR-0006](0006-templates-ship-without-intrusion-banning-and-firewall-front-end-packages.md)
- `tools/README.md` (templateBuildDebian.php), mmdebstrap(1)
