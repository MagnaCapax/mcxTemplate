#!/usr/bin/env bash
# Apply mcxTemplate templates using the shared helper library.

set -euo pipefail
# Grab the directory holding this script for relative path handling.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Resolve repository root so that shared assets can be loaded consistently.
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck disable=SC1091
# Import logging helpers that wrap timestamped output and file routing.
source "${ROOT_DIR}/common/lib/common.sh"

# Directory where rendered templates would live in a real deployment.
TEMPLATE_OUTPUT_DIR="${ROOT_DIR}/output"

# Render a placeholder template directory to keep the workflow observable.
render_templates() {
    mcx_log_info "Validating output directory at ${TEMPLATE_OUTPUT_DIR}"

    if [[ ! -d "${TEMPLATE_OUTPUT_DIR}" ]]; then
        mcx_log_warn "Creating missing output directory ${TEMPLATE_OUTPUT_DIR}"
        if ! mkdir -p "${TEMPLATE_OUTPUT_DIR}"; then
            mcx_log_error "Failed to create output directory ${TEMPLATE_OUTPUT_DIR}"
            return 1
        fi
    fi

    mcx_log_info "Templates processed successfully"
}

# Entry point that orchestrates the high-level steps.
main() {
    mcx_log_info "Starting template apply run"
    render_templates
    mcx_log_info "Finished template apply run"
}

main "$@"
