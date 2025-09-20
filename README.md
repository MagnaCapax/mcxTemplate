# mcxTemplate

## Project Goals
- Deliver a simple, repeatable templating engine for installing bare metal systems from common distro tarballs.
- Standardize automation so every distro build follows the same inputs, scripts, and validation steps.
- Keep scripts lightweight and readable by relying on common Linux tools and minimal abstractions.
- Provide an opinionated path for cloning live systems when hardware quirks (for example HP ProDesk UEFI bugs) block traditional PXE installs.

## Repository Layout
- `distros/` – Per-distribution configuration files, kickstarts, cloud-init seeds, and metadata.
- `scripts/` – Scripts/Tools for making the template tar balls, executing the per template scripts etc.

## Supported Scripting Languages
- **Bash** for basic operations and execution.
- **PHP** for more complex logic and operation.

## Repository Deployment
Clone or sync this repository directly to the target system at `/opt/mcxTemplate`. The automation expects its working directory to match this path so that relative references to `distros/`, `scripts/` resolve without extra configuration.

```bash
sudo mkdir -p /opt
cd /opt
sudo git clone https://github.com/MagnaCapax/mcxTemplate/
```

If the repo must live elsewhere, create a symlink back to `/opt/mcxTemplate` so scripts keep functioning.
