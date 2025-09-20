# Debian 11 (Bullseye) Notes

Debian 11 consumes the shared Debian hooks stored in
`../common/pre_install.sh` and `../common/post_install.sh`. These wrapper
scripts simply delegate to the common implementation so every release stays
aligned unless Bullseye requires specific overrides in the future.

## Release Checklist

- Confirm the Bullseye images still ship with the NVMe plus md raid boot layout
  prior to creating a release artifact.
- Verify the pre-install hook clears SSH host keys and the machine-id during
  validation.
- Ensure `update-initramfs`, `update-grub`, and `grub-install` remain available
  in the chroot environment. Update `GRUB_TARGETS` only when Bullseye images
  change their boot device expectations.

When Bullseye diverges from newer releases, copy only the changed lines into
this directory and keep the shared hooks untouched for the rest of Debian.
