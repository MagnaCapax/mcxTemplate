# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Network install mode: `installNode.php --api=URL --mac=MAC` fetches its plan from nodeCore, reports progress events and the generated root password, and reboots only after nodeCore acknowledged. `bootstrap/mcxInstall.sh` is the mcxRescue entry point; `tools/templateRelease.php` builds the checksummed template, engine tarball and filled bootstrap. Tasks `23-render-resolvers.php`, `90-remove-engine.php` and an Ubuntu task set (Debian tasks by symlink, netplan network task) were added.

- Initial structured logging, mount-spec, and task controls implemented (details to be finalized for the first tagged release).

