# Debian Tasks

Debian provisioning runs through the ordered PHP task scripts stored in
`common/tasks/`. Each script handles a specific responsibility so the flow stays
simple and easy to audit. Tasks all include `distros/common/lib/Common.php` so
logging, privilege checks, and helper routines remain consistent with other
distros.

## Task Breakdown

1. `10-reset-system-identifiers.php` – Removes SSH host keys, resets
   `/etc/machine-id`, and cleans stale resume configuration copied from the
   template image so every clone boots with unique identifiers.
2. `20-configure-identity.php` – Derives the hostname, updates `/etc/hostname`
   and `/etc/hosts`, and renders `/etc/network/interfaces` using the shared PHP
   helpers in `distros/common/`.
3. `30-configure-storage.php` – Regenerates `/etc/fstab` and records mdadm array
   metadata when mdadm is available inside the chroot.
4. `40-update-boot.php` – Refreshes initramfs images, rebuilds `grub.cfg`, and
   reinstalls GRUB onto the configured boot devices.

Tasks execute alphabetically, so adding a new step simply requires choosing an
unused numeric prefix.

## Version Overrides

When a release needs custom behaviour, create `distros/debian/<version>/tasks/`
and drop the altered scripts there. The orchestrator runs the common tasks
first, then executes the version-specific overrides if the directory exists.
For now Debian uses only the shared `common` tasks.

## User Overrides

Place site-specific PHP scripts inside `common/user.d/` (or the
version-specific equivalent). Files in these directories are ignored by Git but
will run after the built-in tasks, allowing last-minute tweaks without forking
upstream code.
