#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Debian 13 post-install hook wrapper.
# Keeps Trixie aligned with the shared Debian implementation.
# -----------------------------------------------------------------------------

set -euo pipefail
# Maintain consistent behaviour and immediate failure semantics.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Resolve directory once to keep path logic simple.

exec "${SCRIPT_DIR}/../common/post_install.sh" "$@"
# Run the shared implementation, allowing future overrides when needed.
