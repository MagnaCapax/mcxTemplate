# Debian 12 (Bookworm) Notes

Debian 12 currently reuses the shared Debian hooks found in
`../common/pre_install.sh` and `../common/post_install.sh`. The wrapper scripts in
this directory simply `exec` those implementations so the behaviour stays in
sync with other Debian releases.

## Release Checklist

- Confirm the NVMe + md raid boot layout matches the production Bookworm images before
  cutting a release build.
- Regenerate SSH host keys and machine-id during validation to ensure the
  pre-install hook still clears all unique identifiers.
- Verify `update-initramfs`, `update-grub`, and `grub-install` remain available
  in the chroot; adjust `GRUB_TARGETS` when Bookworm images change boot devices.

If Bookworm diverges from newer releases, copy the necessary lines into this
folder and keep the shared hooks untouched for the rest of the Debian family.
