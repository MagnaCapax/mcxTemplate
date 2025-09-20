# mcxTemplate

## Project Goals
- Deliver a simple, repeatable template for building and updating virtual machine distributions on Proxmox VE.
- Standardize automation so every distro build follows the same inputs, scripts, and validation steps.
- Keep scripts lightweight and readable by relying on common Linux tools and minimal abstractions.
- Provide an opinionated path for cloning live systems when hardware quirks (for example HP ProDesk UEFI bugs) block traditional PXE installs.

## Repository Layout
- `distros/` – Per-distribution configuration files, kickstarts, cloud-init seeds, and metadata.
- `scripts/` – Reusable shell or Python helpers that drive downloads, customization, cloning, and image uploads.
- `scripts/cloneLiveSystem.sh` – Proof-of-concept workflow for rsync-based cloning with dual USB boot media.
- `assets/` – ISO images, seed files, or other large artifacts that should be cached on the host.
- `docs/` – Detailed runbooks, troubleshooting notes, and task-specific guides.
- `LICENSE`, `README.md` – Repository metadata and this overview.

## Supported Scripting Languages
- **Bash** for glue logic, invoking Proxmox CLI tools, cloning procedures, and simple file templating.
- **Python** for structured data transforms or API calls that are too heavy for shell alone.

Stick to these languages so that every maintainer can read and extend the automation without surprises.

## Host Prerequisites
### General Requirements
- Proxmox VE host with shell access and the `qm`, `pvesh`, and `pct` command-line tools available.
- `wget`, `curl`, `qemu-img`, and `cloud-image-utils` (for cloud-init based distros).
- Sufficient storage under `/opt` for cached assets and working images.

### Live Clone Requirements
- Root access to both the **source** system (over SSH) and the **target** system.
- `mdadm`, `parted`, `rsync`, and `cloud-guest-utils` on the target for assembling software RAID and handling device metadata.
- Dual USB sticks presented as `/dev/sda` and `/dev/sdb` for mirrored boot partitions, plus NVMe bulk storage at `/dev/nvme0n1`.
- Network connectivity that allows passwordless or trusted SSH access to `root@<source_hostname>` (the script disables strict host key checks).

## Repository Deployment
Clone or sync this repository directly to the Proxmox host at `/opt/mcxTemplate`. The automation expects its working directory to match this path so that relative references to `distros/`, `scripts/`, and `assets/` resolve without extra configuration.

```bash
sudo mkdir -p /opt
cd /opt
sudo git clone https://your.git.server/mcxTemplate.git
```

If the repo must live elsewhere, create a symlink back to `/opt/mcxTemplate` so scripts keep functioning.

## Live System Cloning Workflow
The proof-of-concept `scripts/cloneLiveSystem.sh` automates lifting a running Debian-based host onto new hardware that boots from mirrored USB drives. Partition orchestration ultimately belongs to the higher-level **mcxRescue** tooling; the steps below describe how this repository consumes that prepared layout.

1. **Prepare the target.** Swap is disabled, any lingering mounts on `/dev/sda*`, `/dev/sdb*`, and `/dev/nvme0n1*` are detached, and previous mdraid metadata is erased. Expect short pauses (`partprobe` + sleeps) to let the kernel rescan devices.
2. **Create or verify partitions.** The workflow assumes:
   - `/dev/nvme0n1p1` swap (~32 GiB), `/dev/nvme0n1p2` root (~80 GiB), `/dev/nvme0n1p3` home with 1% left unallocated for over-provisioning.
   - `/dev/sda1` and `/dev/sdb1` mirrored in `/dev/md1` for `/boot`, with secondary ext4 partitions on each USB stick for optional storage.
   In production, mcxRescue can pre-stage these partitions so this script simply verifies and formats them.
3. **Assemble filesystems.** The script builds `/dev/md1`, formats swap/ext4 targets, and mounts them under `/mnt/target`, including helper mount points `/mnt/target/mnt/usb1` and `/mnt/target/mnt/usb2`.
4. **Synchronize the live root.** `rsync -aAXv` pulls the running source system to `/mnt/target`, excluding ephemeral directories (`/proc`, `/sys`, `/dev`, `/run`, `/mnt`, `/media`, `/tmp`, `/lost+found`). SSH host key checks are disabled to simplify cloning inside trusted networks.
5. **Chroot cleanup and bootloader install.** Inside the target root the workflow:
   - Regenerates host keys and `machine-id` to avoid collisions.
   - Writes a deterministic `/etc/fstab` referencing the NVMe partitions, mdraid boot device, and the USB data partitions.
   - Captures mdraid metadata (`mdadm --detail --scan`) into `/etc/mdadm/mdadm.conf`.
   - Runs `update-initramfs` and `update-grub`, then installs GRUB to both USB sticks.
6. **Networking and identity.** After leaving the chroot, the helper fills `/etc/hostname`, `/etc/hosts`, and `/etc/network/interfaces` with the live system's values detected from `/proc/cmdline` and `ip addr`.
7. **Operator review.** The script opens the generated network and hostname files in `nano` for final edits, encourages a manual `umount -R /mnt/target` if busy processes linger, and reminds the operator to reboot.

### Running the Clone Workflow
```bash
cd /opt/mcxTemplate/scripts
sudo ./cloneLiveSystem.sh <source_hostname>
```

Recommended practice:
- Run from the mcxRescue environment so disk partitioning is confirmed before cloning.
- Provide the source hostname exactly as resolvable via SSH. The script will connect as `root`.
- Ensure no other mdraid arrays are active; the helper stops everything via `mdadm --stop --scan` before creating `/dev/md1`.

## Template Build Workflow
1. Update or add the distro definition under `distros/`.
2. Run provisioning scripts from `scripts/` to download media, customize the guest, and convert images as needed.
3. Use the same scripts to upload the artifact to Proxmox VE and register it as a template.
4. Validate the template by booting a test VM and adjusting the distro configuration if issues appear.

## Adding a New Distribution
1. Create a directory under `distros/<distro-name>/` with the kickstart or cloud-init inputs required by the guest.
2. Reuse existing helper scripts under `scripts/`; add new functions only when no shared helper exists.
3. Update any manifest or index files that list supported distros so operators can discover the new template.
4. Document distro-specific steps inside `docs/<distro-name>.md` and link it from the quick-start section below.

## Quick Start
### Build a Template
1. Ensure the host prerequisites are installed.
2. Clone the repository into `/opt/mcxTemplate` (or update your local copy).
3. Review the desired distro under `distros/` and adjust variables for your environment.
4. Execute the matching script in `scripts/` to build and upload the template.
5. Launch a test VM from the new template to confirm provisioning succeeds.

### Clone a Live System
1. Confirm the target hardware has the NVMe + dual USB layout described above.
2. Boot the target into the mcxRescue environment or another live OS with `mdadm` and `rsync` installed.
3. Mount this repository at `/opt/mcxTemplate` and run `sudo ./scripts/cloneLiveSystem.sh <source_hostname>`.
4. Review the chrooted configuration files opened in `nano`, then unmount `/mnt/target`.
5. Reboot and validate that the new system boots from the mirrored USB drives and mounts NVMe storage correctly.

## Additional Documentation
- Task-specific guides live under `docs/`. Link to them from here as they are created, for example: `docs/debian.md`, `docs/ubuntu.md`.
- If no additional documents exist yet, track notes in `docs/README.md` and update this section once more guides land.

## Development Guidelines
- Follow the KISS principle: prefer straightforward shell functions and avoid unnecessary abstraction layers.
- Reuse existing helpers instead of copy-pasting new variants of the same logic.
- Apply a layered approach when writing scripts—define settings, gather data, transform it, then emit output.
- Retain consistent commenting (roughly every ten lines) so future operators can reason about the flow quickly.
- Only change battle-tested behaviour when there is a clear defect or requirement driving the update.
