# Debian 13 (Trixie) Notes

Debian 13 tracks the shared Debian hooks in
`../common/pre_install.sh` and `../common/post_install.sh`. The wrapper scripts
in this directory delegate to the common implementation so Trixie behaviour
matches Bullseye and Bookworm unless this release requires dedicated overrides.

## Release Checklist

- Validate the NVMe-first plus md raid boot layout remains consistent with the
  shipping Trixie images before tagging a release.
- Confirm the pre-install hook still removes SSH host keys and regenerates the
  machine-id during validation.
- Ensure `update-initramfs`, `update-grub`, and `grub-install` continue to exist
  in the chroot; only adjust `GRUB_TARGETS` if Trixie images switch boot devices.

If Trixie deviates from earlier releases, copy only the required changes into
this directory while leaving the shared Debian hooks untouched.
