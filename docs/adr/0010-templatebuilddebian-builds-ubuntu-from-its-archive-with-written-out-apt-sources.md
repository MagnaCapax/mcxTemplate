# ADR-0010: templateBuildDebian also builds Ubuntu, with written-out apt sources

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-14
- Status: Proposed

## Context
The only Ubuntu 24.04 template was captured from a prepared machine. It carried 498 packages nobody listed (cloud-init, snapd, unattended-upgrades) and lacked the ones the engine runs: php (the install stopped at `configure exited 127: chroot: failed to run command 'php'` after the disk was wiped, [ADR-0009](0009-release-refuses-a-rootfs-without-php-and-smartctl.md)), `smartctl` and cron. [ADR-0007](0007-templates-may-be-built-from-the-debian-archive.md) already builds Debian from a reviewed package list; its builder takes the suite and mirror as arguments and has no captured-host data in it.

Two things in it are Debian-only. mmdebstrap writes apt sources for a Debian suite itself (the suite, its `-updates` and `-security`); for an Ubuntu suite it writes only the release pocket, so an image built that way would never see a security update. And Ubuntu's `universe` component is not in mmdebstrap's default `main`.

## Options Considered
- Add the missing packages to the captured Ubuntu rootfs in a chroot: keeps the capture's unlisted contents and needs a chroot step per fix; the reviewed list would not describe the template.
- A second builder for Ubuntu: duplicates the list parsing, banned-package checks, overlay and publishing of `DebianRootfsBuilder`.
- Extend `DebianRootfsBuilder` with optional apt-source arguments: chosen. The tool keeps its name (Ubuntu is built by the same mmdebstrap call and the same checks); renaming it would break every caller for no gain.

## Decision
- `tools/templateBuildDebian.php` gains three optional arguments: `--components=main,universe`, `--pockets=updates,security` and `--keyring=/path/keyring.gpg`. Without all three nothing changes: mmdebstrap decides the sources, as for Debian.
- With any of them each apt source is written out as one line (`deb [signed-by=<keyring>] <mirror> <suite>[-<pocket>] <components>`) and passed to mmdebstrap after the target. They need `--mirror`, because the line needs a URL. `--keyring` must be a file on the build host (the distribution's keyring package, e.g. `ubuntu-keyring`) and is written as `signed-by`, so the same path in the image must hold that keyring; the package is part of Ubuntu's `minbase`.
- Names are checked against fixed patterns, the keyring path must be absolute without `..`, and all checks run before any command or write.
- `distros/ubuntu/24.04/minimal.packages` is the reviewed list for Ubuntu 24.04 (noble), the Debian list with two differences: `linux-image-generic` for `linux-image-amd64`, and `netplan.io` instead of `ifupdown`, because Ubuntu task 22 writes `/etc/netplan/01-mcx.yaml`, which only netplan's boot-time generator turns into network configuration. It carries php, smartmontools, cron, time sync and dbus for the reasons in [ADR-0009](0009-release-refuses-a-rootfs-without-php-and-smartctl.md) and the Debian list. The banned packages of [ADR-0006](0006-templates-ship-without-intrusion-banning-and-firewall-front-end-packages.md) are refused as before.
- The image overlay (hostname, empty resolv.conf, root login drop-in, `net.ifnames=0`) is the same for Ubuntu: noble's sshd includes `sshd_config.d`, and `update-grub` reads `/etc/default/grub.d`.

## Consequences
- An Ubuntu template is reproducible from the archive and its list, and carries no capture history. The release still runs through `templateRelease.php`, which now refuses a rootfs without php or smartctl.
- `linux-image-generic` depends on `linux-firmware` and CPU microcode, so the image is larger than the Debian minimal one (which has no firmware).
- The build host needs the distribution keyring package for an Ubuntu build.
- Debian builds are unchanged unless the new arguments are given.

## References
- [ADR-0006](0006-templates-ship-without-intrusion-banning-and-firewall-front-end-packages.md), [ADR-0007](0007-templates-may-be-built-from-the-debian-archive.md), [ADR-0009](0009-release-refuses-a-rootfs-without-php-and-smartctl.md)
- `src/Lib/Release/DebianRootfsBuilder.php` (`sourcesArguments`), `tests/unit/Release/DebianRootfsBuilderTest.php`, `distros/ubuntu/README.md`, mmdebstrap(1)
