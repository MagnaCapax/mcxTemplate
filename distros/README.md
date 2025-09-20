# Distribution Hooks

The `distros/` tree stores provisioning hooks that run inside the
`mcxTemplate` chroot. Each distro keeps its lifecycle scripts alongside any
supporting notes so the behaviour stays predictable across releases.

## Layout Overview

- `distros/common/` keeps cross-distro assets such as the PHP fstab writer.
- `lib/common/` holds shared shell helpers such as logging and root checks.
- `<distro>/common/` contains the default hook implementations for a distro.
- `<distro>/<version>/` overrides any hook that changed for a specific release
  while keeping documentation that is unique to that version.

This layered layout keeps common logic in one place and only forks code when a
release genuinely needs different behaviour. New distros should follow the same
pattern: place reusable code in `common/` and let versioned directories provide
small wrappers or overrides.

## Naming Convention

Version directories use the numeric major version (for example `12`) to reduce
renames between testing and release builds. READMEs should mention the matching
codename (such as “bookworm”) so both references stay clear to operators.
