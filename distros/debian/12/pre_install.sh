#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Debian 12 pre-install hook wrapper.
# Delegates to the shared Debian implementation in distros/debian/common/.
# -----------------------------------------------------------------------------

set -euo pipefail
# Maintain consistent behaviour and immediate failure semantics.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Resolve directory once to keep path logic simple.

exec "${SCRIPT_DIR}/../common/pre_install.sh" "$@"
# Run the shared implementation, allowing future overrides when needed.
