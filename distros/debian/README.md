# Debian Hook Overview

The Debian hooks standardise the final provisioning steps after a filesystem is
cloned into place. The logic mirrors the historical `cloneLiveSystem.sh`
workflow so Bookworm and future releases boot with the expected layout.

## Shared Implementation

- `common/pre_install.sh` resets SSH host keys, clears the machine-id, and wipes
  stale resume configuration before packages are touched.
- `common/post_install.sh` rebuilds `/etc/fstab`, hostname data, static
  networking, and bootloader configuration using the known NVMe-first layout.
  The fstab content itself comes from `../../common/write_fstab.php` so other
  distros can reuse the same PHP helper.
- Shared helpers live in `../../lib/common/` and provide logging plus root
  validation routines.

Each version directory can replace either script when Debian changes defaults,
but should otherwise delegate back to the common implementation to avoid drift.

## Environment Overrides

The post-install hook accepts simple overrides so lab systems can tweak the
layout without editing the script:

| Variable | Purpose |
| --- | --- |
| `HOSTNAME_DOMAIN` | Domain appended to the detected hostname. |
| `ROOT_DEVICE` | Device path mounted as `/`. |
| `HOME_DEVICE` | Device path mounted as `/home`. |
| `BOOT_DEVICE` | Device path mounted as `/boot`. |
| `SWAP_DEVICE` | Device path registered as swap. |
| `GRUB_TARGETS` | Space-separated device list passed to `grub-install`. |

Keep overrides minimal so the shared defaults remain a reliable baseline.

## Version Directories

Directories such as `12/` (Debian Bookworm) wrap the shared scripts. When
Bookworm and a later release diverge, copy only the lines that changed into the
new versioned hook and leave the rest in `common/`.
