# Distribution Hooks

The `distros/` tree stores provisioning hooks that run inside the mcxTemplate
chroot. Each distro keeps its task scripts alongside documentation so behaviour
stays predictable across releases.

## Layout Overview

- `configure.php` detects the active distro/version and executes tasks in order.
- `<distro>/common/tasks/` holds the default task list for the distro.
- `<distro>/<version>/tasks/` contains overrides when a release diverges.
- `user.d/` directories at each level allow site-specific PHP hooks that are
  ignored by Git but executed after the built-in tasks.
- `../common/` keeps reusable helpers (for example the PHP hostname writers).

Tasks are named with a numeric prefix (`10-`, `20-`, …) so alphabetical sorting
matches the desired execution order. Tasks are implemented in PHP so they can
reuse the shared helper library shipped in `distros/common/lib` without
introducing additional interpreters.

## Adding a New Distro

1. Create `distros/<distro>/common/tasks/` and populate ordered scripts.
2. Add optional `distros/<distro>/<version>/tasks/` when a release diverges from
   the shared defaults.
3. Drop any local overrides into `user.d/` while keeping them out of version
   control.
4. Document the flow in a `README.md` inside the distro directory so future
   contributors understand the expectations.

This layered layout keeps common logic in one place and only forks code when a
release genuinely needs different behaviour.
