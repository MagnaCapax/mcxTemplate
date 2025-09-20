#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Debian pre-install hook for mcxTemplate.
# Runs inside the chroot prior to final configuration steps.
# Shared by all Debian releases unless a version provides an override.
# -----------------------------------------------------------------------------

set -euo pipefail
# Fail fast so provisioning halts on the first unexpected issue.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Resolve the current directory once for repeat use.

COMMON_LIB_DIR="${SCRIPT_DIR}/../../../lib/common"
# Shared helper library location used by distro hooks.

# shellcheck source=../../../lib/common/logging.sh
source "${COMMON_LIB_DIR}/logging.sh"
# shellcheck source=../../../lib/common/system.sh
source "${COMMON_LIB_DIR}/system.sh"

# clear_ssh_host_keys removes any copied host keys before regenerating them.
clear_ssh_host_keys() {
  log_info "Removing stale SSH host keys copied from the source image."
  rm -f /etc/ssh/ssh_host_* || true
  log_info "Regenerating SSH host keys for the cloned system."
  run_if_command_exists ssh-keygen -A || true
}

# reset_machine_identifier ensures the new system has a unique machine-id.
reset_machine_identifier() {
  log_info "Resetting machine-id to avoid duplicate identifiers."
  rm -f /etc/machine-id || true
  if ! run_if_command_exists systemd-machine-id-setup; then
    log_warn "systemd-machine-id-setup missing, creating empty machine-id."
    : > /etc/machine-id
  fi
}

# clean_resume_configuration drops stale resume settings referencing old swap.
clean_resume_configuration() {
  local resume_file="/etc/initramfs-tools/conf.d/resume"
  if [[ -f "${resume_file}" ]]; then
    log_info "Sanitising ${resume_file} to remove stale RESUME entries."
    sed -i '/^RESUME=/d' "${resume_file}"
    if [[ ! -s "${resume_file}" ]]; then
      log_info "Removing empty resume configuration file."
      rm -f "${resume_file}"
    fi
  else
    log_info "Resume configuration not found; nothing to clean."
  fi
}

main() {
  require_root
  log_info "Debian pre-install hook started."
  clear_ssh_host_keys
  reset_machine_identifier
  clean_resume_configuration
  log_info "Debian pre-install hook completed successfully."
}

main "$@"
