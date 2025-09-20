#!/usr/bin/env bash
# Prepare directories and sanity checks before applying templates.

set -euo pipefail
# Identify script location to keep relative paths deterministic.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Compute repository root from the script location for asset access.
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck disable=SC1091
# Import shared logging helpers so messaging stays consistent.
source "${ROOT_DIR}/common/lib/common.sh"

# State directory where temporary work files would be staged.
STATE_DIR="${ROOT_DIR}/state"

# Ensure that the state directory exists and explain any actions taken.
prepare_state_dir() {
    if [[ -d "${STATE_DIR}" ]]; then
        mcx_log_info "State directory already present at ${STATE_DIR}"
    else
        mcx_log_warn "State directory missing, creating ${STATE_DIR}"
        if ! mkdir -p "${STATE_DIR}"; then
            mcx_log_error "Failed to create state directory ${STATE_DIR}"
            return 1
        fi
        mcx_log_info "State directory ready"
    fi
}

# Basic environment probe to inform the operator about available commands.
check_dependencies() {
    if ! command -v envsubst >/dev/null 2>&1; then
        mcx_log_warn "envsubst command not found; template expansion will be limited"
    else
        mcx_log_info "All dependencies satisfied"
    fi
}

# Entry point calling the pre-flight checks in order.
main() {
    mcx_log_info "Starting bootstrap checks"
    check_dependencies
    prepare_state_dir
    mcx_log_info "Bootstrap checks completed"
}

main "$@"
