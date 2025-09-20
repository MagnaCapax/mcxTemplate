# mcxTemplate Tools

The `tools/` directory collects helper scripts that operate outside of a
specific distro template. These utilities automate the workflow required to
capture a running system and prepare an archive that already bundles the mcx
templating engine alongside any additional artefacts you may need when
crafting new templates.

## Quick Reference

* `templateCloneRemote.php` – Capture a live system over SSH and write the
  archive locally or to STDOUT.
* `templateCloneImage.php` – Mount a disk image read-only, copy its root file
  system, and compress the result on the local machine.
* `templateAssemble.php` – Stage a remote system into a local workspace,
  bundle the templating engine, and package the combination as a template.
* `packageLiveSystem.php` – Compatibility wrapper that forwards to
  `templateAssemble.php` so long-standing automation keeps working.
* `lib/packageCommon.php` – Internal helper library shared by the tooling. You
  rarely invoke it directly, but all scripts depend on its checks and
  path-handling helpers.

## Getting Started

1. Ensure the host running these scripts has `php` available. Root privileges
   are required for operations that attach loop devices or mount images.
2. Review the synopsis for the tool you plan to use and confirm the required
   dependencies (remote SSH access, local `rsync`, etc.).
3. Run `php tools/<script>.php --help` to view inline usage details at any
   time. Each script prints a concise summary of its arguments and options.
4. Execute the command examples below, adjusting hosts, paths, and options to
   match your environment.

The sections that follow list dependencies, usage examples, and workflow notes
for each script.

## templateCloneRemote.php

`templateCloneRemote.php` mirrors the `cloneLiveSystem.sh` reference flow by
streaming a remote root file system over SSH. The PHP implementation asks the
remote host to create a tarball, compresses it with `pigz` when available
(falling back to `gzip`), and writes the archive locally without staging a full
copy.

### Dependencies

* `php`
* `ssh`
* `tar`
* `pigz` or `gzip` on the remote host

### Synopsis

```bash
php tools/templateCloneRemote.php <user@host> <output|-> [--ssh-option <option>]... [--force]
```

### Usage

```bash
php tools/templateCloneRemote.php root@mcx-node-01.example.com /srv/templates/mcx-node-01.tar.gz
```

* Pass `-` (or `stdout`) as the second argument to stream the archive to
  standard output for further piping.
* Forward additional SSH parameters through repeated `--ssh-option` flags. The
  value accepts multi-token strings, so both `--ssh-option "-p 2222"` and
  `--ssh-option "-o StrictHostKeyChecking=no"` are handled safely.
* Add `--force` to overwrite an existing archive file on the local system.

## templateCloneImage.php

`templateCloneImage.php` targets offline images. It detects whether the image is
raw or QCOW2, attaches it read-only via `losetup` or `qemu-nbd`, waits for
partitions to appear, auto-selects a mountable partition (including filesystem
images without a partition table), and then compresses the mounted root file
system locally with `pigz` (falling back to `gzip`).

### Dependencies

* `php`
* `tar`
* `pigz`
* `qemu-img`
* `losetup`
* `lsblk`
* `mount`
* `qemu-nbd` (only when handling QCOW2 images)

The tool must run with root privileges to attach loop devices and mount the
image. Provide `--partition <num>` when automatic detection cannot identify the
desired partition.

### Synopsis

```bash
sudo php tools/templateCloneImage.php --image <path> --output <path> [--partition <num>]
```

### Usage

```bash
sudo php tools/templateCloneImage.php --image /srv/images/base.img \
    --output /srv/templates/base.tar.gz
```

## templateAssemble.php

`templateAssemble.php` connects to a live Linux system over SSH, copies the root
file system into a temporary staging area, adds the repository templating
engine, optionally includes extra local files (ISO images, QCOW2 disks, custom
scripts, etc.), and finally produces a `pigz`-compressed tarball. The PHP
implementation keeps the workflow layered:

1. **Source acquisition** – fetches the distro via rsync/SSH.
2. **Copy preparation** – assembles the rsync statement with consistent
   exclusions for runtime mounts.
3. **Packaging** – builds a pigz-compressed archive ready for template reuse.

### Dependencies

* `php`
* `ssh`
* `rsync`
* `tar`
* `pigz`

All dependencies are available in standard Debian and Ubuntu repositories. The
remote host must allow SSH access for the provided user and that user must have
permission to read the entire file system tree.

### Synopsis

```bash
php tools/templateAssemble.php --source-host <host> --source-user <user> [options]
```

### Usage

```bash
php tools/templateAssemble.php --source-host mcx-node-01.example.com \
    --source-user root \
    --output /srv/templates/mcx-node-01.tar.gz \
    --extra /var/lib/vz/template/iso/debian-12.iso \
    --extra /var/lib/vz/images/900/vm-900-disk-0.qcow2 \
    --ssh-option "-p 2222"
```

The example command captures the remote host `mcx-node-01.example.com` as root,
saves the archive to `/srv/templates/mcx-node-01.tar.gz`, and embeds two local
artefacts under the `extras/` directory inside the archive. If `--output` is
omitted the tool writes to a timestamped `template-<host>-<timestamp>.tar.gz`
file with the host name sanitised to filesystem-friendly characters.

### Archive Contents

The resulting tarball contains three top-level directories:

* `rootfs/` – A faithful copy of the remote file system (excluding runtime
  mounts such as `/proc` or `/sys`).
* `template/` – A copy of the repository `common/` templating engine.
* `extras/` – Optional files supplied through `--extra`. The directory is
  omitted when no additional assets are provided.

### Notes

* The script honours the KISS principle: each stage has a dedicated PHP
  function and the orchestration mirrors the A/B/C layering described above.
* Compression is handled via `pigz` to speed up packaging on multi-core
  systems.
* Additional SSH parameters can be forwarded to `rsync` through `--ssh-option`.
* Temporary data is stored in a PHP-created directory under the system temp
  path and automatically removed on exit.
* For large systems ensure the local machine has sufficient disk space to store
  the uncompressed staging directory during packaging.
