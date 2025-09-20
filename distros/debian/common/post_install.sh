#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Debian post-install hook for mcxTemplate.
# Applies configuration matching the historical cloneLiveSystem process.
# Shared by all Debian releases unless a version provides an override.
# -----------------------------------------------------------------------------

set -euo pipefail
# Abort on any failure to maintain predictable provisioning runs.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Cache script location for relative lookups.

COMMON_LIB_DIR="${SCRIPT_DIR}/../../../lib/common"
# Shared helper library directory used by distro lifecycle scripts.
DISTRO_COMMON_DIR="${SCRIPT_DIR}/../../common"
# Shared distro assets such as PHP helpers for rendering config files.

# shellcheck source=../../../lib/common/logging.sh
source "${COMMON_LIB_DIR}/logging.sh"
# shellcheck source=../../../lib/common/system.sh
source "${COMMON_LIB_DIR}/system.sh"

# Allow overriding default devices and domain through environment variables.
: "${HOSTNAME_DOMAIN:=pulsedmedia.com}"
: "${SWAP_DEVICE:=/dev/nvme0n1p1}"
: "${ROOT_DEVICE:=/dev/nvme0n1p2}"
: "${HOME_DEVICE:=/dev/nvme0n1p3}"
: "${BOOT_DEVICE:=/dev/md1}"
: "${GRUB_TARGETS:=/dev/sda /dev/sdb}"

# derive_hostname extracts the short hostname from the kernel command line.
derive_hostname() {
  local kernel_arg
  kernel_arg="$(sed -n 's/.*hostname=\([^ ]*\).*/\1/p' /proc/cmdline | head -n1)"
  if [[ -z "${kernel_arg}" ]]; then
    kernel_arg="$(hostname | cut -d'.' -f1)"
    log_warn "hostname= kernel parameter missing, falling back to ${kernel_arg}."
  fi
  printf '%s' "${kernel_arg%%.*}"
}

# gather_network_information records IPv4 CIDR and gateway for later templates.
gather_network_information() {
  NETWORK_CIDR="$(ip -o -f inet addr show scope global | awk '{print $4; exit}')"
  if [[ -z "${NETWORK_CIDR}" ]]; then
    log_warn "No global IPv4 address detected; network templates will use defaults."
  fi
  IP_ADDRESS="${NETWORK_CIDR%%/*}"
  GATEWAY="$(ip route | awk '/default/ {print $3; exit}')"
  if [[ -z "${GATEWAY}" ]]; then
    log_warn "Gateway not found in routing table; leaving interfaces without gateway."
  fi
}

# write_fstab reproduces the static layout expected by the clone workflow.
write_fstab() {
  local writer="${DISTRO_COMMON_DIR}/write_fstab.php"
  if [[ ! -x "${writer}" ]]; then
    log_error "Common fstab writer missing at ${writer}."
    exit 1
  fi
  log_info "Writing /etc/fstab with Debian device mappings via PHP helper."
  ROOT_DEVICE="${ROOT_DEVICE}" \
  HOME_DEVICE="${HOME_DEVICE}" \
  BOOT_DEVICE="${BOOT_DEVICE}" \
  SWAP_DEVICE="${SWAP_DEVICE}" \
  "${writer}"
}

# configure_mdadm writes array metadata when mdadm is installed in the chroot.
configure_mdadm() {
  if command_exists mdadm; then
    log_info "Capturing mdadm array metadata into /etc/mdadm/mdadm.conf."
    mdadm --detail --scan > /etc/mdadm/mdadm.conf
  else
    log_warn "mdadm not installed; skipping mdadm.conf generation."
  fi
}

# write_hostname_files delegates to a PHP helper for templating the files.
write_hostname_files() {
  local short_hostname="$1"
  local fqdn="${short_hostname}.${HOSTNAME_DOMAIN}"
  local host_writer="${DISTRO_COMMON_DIR}/write_hostname_files.php"
  # Confirm the helper exists before attempting to execute it.
  if [[ ! -x "${host_writer}" ]]; then
    log_error "Hostname writer missing at ${host_writer}."
    exit 1
  fi
  log_info "Setting hostname to ${fqdn} via PHP helper."
  SHORT_HOSTNAME="${short_hostname}" \
    FQDN="${fqdn}" \
    HOST_IP="${IP_ADDRESS:-127.0.1.1}" \
    "${host_writer}"
}

# write_interfaces hands control to PHP so the template remains consistent.
write_interfaces() {
  local cidr_value="${NETWORK_CIDR:-192.0.2.10/24}"
  local interface_writer="${DISTRO_COMMON_DIR}/write_interfaces.php"
  # Abort if the helper script is missing to avoid partial updates.
  if [[ ! -x "${interface_writer}" ]]; then
    log_error "Interface writer missing at ${interface_writer}."
    exit 1
  fi
  log_info "Writing /etc/network/interfaces with primary address ${cidr_value} via PHP helper."
  NETWORK_CIDR="${cidr_value}" \
    GATEWAY="${GATEWAY:-}" \
    "${interface_writer}"
}

# update_boot_components refreshes initramfs, grub.cfg, and bootloaders.
update_boot_components() {
  log_info "Updating initramfs and GRUB configuration."
  run_if_command_exists update-initramfs -u || true
  run_if_command_exists update-grub || true
  local device
  for device in ${GRUB_TARGETS}; do
    if [[ -b "${device}" ]]; then
      log_info "Installing GRUB to ${device}."
      run_if_command_exists grub-install "${device}" || true
    else
      log_warn "Block device ${device} missing; skipping GRUB installation."
    fi
  done
}

main() {
  require_root
  log_info "Debian post-install hook started."
  local short_hostname
  short_hostname="$(derive_hostname)"
  gather_network_information
  write_hostname_files "${short_hostname}"
  write_interfaces
  write_fstab
  configure_mdadm
  update_boot_components
  log_info "Debian post-install hook completed successfully."
}

main "$@"
