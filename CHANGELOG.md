# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Network install mode: `installNode.php --api=URL --mac=MAC` fetches its plan from nodeCore, reports progress events and the generated root password, and reboots only after nodeCore acknowledged. `bootstrap/mcxInstall.sh` is the mcxRescue entry point; `tools/templateRelease.php` builds the checksummed template, engine tarball and filled bootstrap. Tasks `23-render-resolvers.php`, `90-remove-engine.php` and an Ubuntu task set (Debian tasks by symlink, netplan network task) were added.
- Release scan (ADR-0004): only a complete private key block (BEGIN line, body, END line of the same type) refuses a release, so file-type signatures and keys split across source string literals no longer do. A known-public package fixture, listed by hash in `PrivateKeyBlock::PUBLIC`, is reported as `NOTICE publicFixture <path>` instead of refusing. No path is ever exempt except the SSH host keys.
- Templates ship without `fail2ban`, `ufw`, `crowdsec` and `sshguard` (ADR-0006). `tools/templateRelease.php` refuses a rootfs whose `var/lib/dpkg/status` has one installed (`contentScanRefused: bannedPackage <name>`), and `tools/check-template.php` exits 3 on it, which the installer reports as `bannedPackage` before any disk is touched. A package removed with its config files left (`config-files`) is allowed. The one fail2ban entry of `PrivateKeyBlock::PUBLIC` is removed.
- `biosNvmeUsbBoot` creates the `/boot` mirror with md metadata 0.90 instead of 1.2 (ADR-0005). A rescue kernel newer than 6.18 writes a field into the old padding of every v1.x superblock it creates, and an older installed kernel then refuses the members and stops in emergency mode. Boot verification adds `bootArray`, which requires metadata 0.90.
- Task 32 replaces only the `ARRAY` lines of `/etc/mdadm/mdadm.conf` and keeps the rest of the file (MAILADDR, PROGRAM, DEVICE, HOMEHOST). When neither MAILADDR nor PROGRAM is set it adds the Debian default `MAILADDR root`, so `mdmonitor` starts instead of failing with "No mail address or alert command".
- `tools/templateBuildDebian.php` builds a minimal Debian root filesystem from the Debian archive with mmdebstrap (variant minbase plus the reviewed list `distros/debian/12/minimal.packages`, no Recommends) as the `--rootfs` input of `templateRelease.php` (ADR-0007). The build refuses a package of `Lib\Install\BannedPackages` (ADR-0006) in the list and in the built image, and writes `net.ifnames=0` into the image's grub defaults.
- The Debian 12 minimal list adds `smartmontools`, so an installed server can read its SATA and NVMe health with `smartctl` (a post-install hardware check failed without it).
- The network install reports the installed system's SSH host public keys (ed25519, ecdsa) as `facts.hostKeys` in `/install/result` (ADR-0008). A read-only `hostKeys` step reads them after `verifyBoot`. A symlinked key file or directory is refused, and an empty list means there is nothing to pin. The first login to the installed node can then check the host key instead of trusting whatever answers on port 22.

- Initial structured logging, mount-spec, and task controls implemented (details to be finalized for the first tagged release).

