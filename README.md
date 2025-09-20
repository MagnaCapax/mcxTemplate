# mcxTemplate

mcxTemplate delivers a simple, repeatable templating engine for installing bare
metal systems from live images. The repository keeps the configuration that runs
inside the target chroot after mcxRescue prepares disks and copies a template
filesystem into place.

## Provisioning Architecture

mcxTemplate assumes the provisioning host follows the mcxRescue workflow:

1. **Boot rescue environment** – Nodes boot into mcxRescue (or another live
   image) that ships the disk preparation logic.
2. **Partition and populate** – The rescue scripts wipe target disks, create
   partitions, format filesystems, mount the target paths, and extract the
   mcxTemplate tarball onto the new root filesystem.
3. **Chroot hand-off** – mcxRescue enters the chroot and runs
   `/opt/mcxTemplate/installTemplate.php`. This entry point invokes the distro
   configurator shipped in this repository.
4. **Distro configuration** – `distros/configure.php` detects the active distro,
   runs shared tasks from `distros/<distro>/common`, then executes any
   version-specific overrides. Hooks live entirely inside the target chroot, so
   every change directly affects the freshly cloned system.
5. **Logging and reboot** – mcxRescue captures stdout/stderr from the scripts,
   unmounts the target filesystems, and reboots the machine into the installed
   OS.

This flow keeps the template build consistent and avoids running destructive
operations inside the final system.

## Repository Layout

- `distros/configure.php` – Main orchestrator invoked by `installTemplate.php`.
- `distros/<distro>/common/tasks/` – Ordered PHP tasks executed for the
  distro regardless of version. Tasks run alphabetically to keep the flow
  predictable.
- `distros/<distro>/<version>/tasks/` – Optional overrides for specific releases
  (for example `distros/debian/12/tasks`). Missing directories simply skip the
  override stage.
- `distros/<distro>/*/user.d/` – Optional user-maintained hooks ignored by Git.
  Place site-specific `.sh` or `.php` scripts here when extra tweaks are
  required.
- `distros/common/` – Shared PHP helpers for rendering config files across all
  distros.
- `lib/common/` – Shared shell helpers (`logging.sh`, `system.sh`).
- `tools/` – Packaging helpers for building template tarballs.

Clone or sync this repository directly to the target system at
`/opt/mcxTemplate`. The automation expects its working directory to match this
path so that relative references resolve without extra configuration. If the
repository must live elsewhere, create a symlink back to `/opt/mcxTemplate` so
scripts keep functioning.
