# mcxTemplate Tools

The `tools/` directory collects helper scripts that operate outside of a
specific distro template. These utilities automate the workflow required to
capture a running system and prepare an archive that already bundles the mcx
templating engine alongside any additional artefacts you may need when
crafting new templates.

## packageLiveSystem.php

`packageLiveSystem.php` connects to a live Linux system over SSH, copies the
root file system into a temporary staging area, adds the repository templating
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

### Usage

```bash
php tools/packageLiveSystem.php --source-host mcx-node-01.example.com \
    --source-user root \
    --output /srv/templates/mcx-node-01.tar.gz \
    --extra /var/lib/vz/template/iso/debian-12.iso \
    --extra /var/lib/vz/images/900/vm-900-disk-0.qcow2
```

The example command captures the remote host `mcx-node-01.example.com` as root,
saves the archive to `/srv/templates/mcx-node-01.tar.gz`, and embeds two local
artefacts under the `extras/` directory inside the archive. If `--output` is
omitted the tool writes to `./template-<host>-<timestamp>.tar.gz`.

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
* Temporary data is stored in a PHP-created directory under the system temp
  path and automatically removed on exit.
* For large systems ensure the local machine has sufficient disk space to store
  the uncompressed staging directory during packaging.
