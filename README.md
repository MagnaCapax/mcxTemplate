# mcxTemplate

Templating engine for distro installations.

## Repository Layout

* `common/` – Shared templating engine logic used by distro-specific templates.
* `tools/` – Standalone utilities that help capture live systems and package
  assets for new templates. See [`tools/README.md`](tools/README.md) for
  details.

## Tools Overview

The first tool provided is `packageLiveSystem.php`, a PHP helper that connects
to a remote host, copies its file system, includes the templating engine,
bundles optional local assets (ISO, QCOW2, etc.), and produces a pigz-compressed
archive ready for template creation. Full documentation and usage examples live
in the [`tools/README.md`](tools/README.md) file.
