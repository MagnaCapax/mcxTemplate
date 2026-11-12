# ADR-0005: The /boot mirror uses md metadata 0.90

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-12
- Status: Proposed

## Context
[ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md) puts `/boot` for `biosNvmeUsbBoot` on an md RAID1 across two USB sticks with metadata 1.2, the format of the boot arrays already in service. Those arrays were created by older kernels.

The install creates the array inside the rescue system, so the rescue's kernel writes the superblock. Kernels newer than 6.18 write a `logical_block_size` value into what used to be the `pad3` padding of every v1.x superblock they create (Linux commits 62ed1b582246 "md: allow configuring logical block size" and a4166f1c4893 "md: Fix forward incompatibility from configurable logical block size"). Kernels up to 6.18 refuse a v1.x superblock whose padding is not zero; the log line is `md: sdX1 does not have a valid v1.2 superblock, not importing!`. Debian 12, Debian 13 and Ubuntu 24.04 all ship such kernels.

On 2026-11-12 an install on hardware passed every boot check, then the first boot of the installed Debian 12 (kernel 6.1) refused both members. `/boot` timed out, `local-fs.target` failed and the system stopped in emergency mode before sshd started. The rescue kernel assembled the same array without complaint, so no check run in the rescue could see the problem.

## Options Considered
- Keep 1.2 and zero the new field after creation: rejected. It means writing raw superblock bytes and recomputing the checksum by hand, on a format the kernel keeps extending.
- Metadata 1.0 (superblock at the end): rejected. It is a v1.x superblock and gets the same field.
- Turn off the check on the target (`md_mod.check_new_feature=0`): rejected. Only kernels newer than 6.18 have that parameter, and its own documentation warns of data loss.
- Rescue with an older kernel: rejected. It ties the rescue image to the oldest template forever.
- Metadata 0.90: chosen. Its superblock has no such field, every kernel version in use reads it, and GRUB reads it (`mdraid09`).

## Decision
- `biosNvmeUsbBoot` creates the `/boot` mirror with `mdadm --create /dev/md/mcxboot --metadata=0.90`. 0.90 stores no array name, so `--name` is not passed; the `/dev/md/mcxboot` path names the array.
- Boot verification adds `bootArray`: `mdadm --detail --export /dev/md/mcxboot` must report `MD_METADATA=0.90`. A rescue-side assemble test cannot stand in for the target kernel, so the check verifies the format instead.
- This supersedes only the "metadata 1.2" detail of ADR-0002; the rest of ADR-0002 stands.

## Consequences
- An installed system with an older kernel than the rescue's assembles `/boot` again.
- A 0.90 member is limited to 2 TiB and 28 devices; a 2 GiB partition on a USB stick is far inside both.
- The superblock sits at the end of the partition, so each member also reads as a plain ext4 filesystem. Tools that respect `linux_raid_member` (udev, blkid, systemd) mount the md device, not a member.
- New boot arrays differ in format from the 1.2 arrays already in service; the old arrays are not touched.
- Other md arrays this engine may create later need the same choice, or a check that the target kernel is newer than 6.18.

## References
- [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md)
- Linux commits 62ed1b582246, 9c47127a807d ("md: add check_new_feature module parameter"), a4166f1c4893
- nix-community/disko issue 1276: the same refusal after a newer rescue kernel created the arrays
