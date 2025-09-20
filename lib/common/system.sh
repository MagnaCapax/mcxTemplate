#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# system.sh - Shared system helpers reused by provisioning scripts.
# Keeps small utility helpers in one place to reduce duplication.
# -----------------------------------------------------------------------------

# Avoid redefining helpers when sourced repeatedly by nested scripts.
if [[ -n "${MCX_SYSTEM_SH:-}" ]]; then
  return 0
fi
MCX_SYSTEM_SH=1

# command_exists checks whether the given executable is present in PATH.
command_exists() {
  command -v "$1" >/dev/null 2>&1
}

# require_root stops execution unless the current user is root.
require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    if command_exists log_error; then
      log_error "Root privileges are required for provisioning steps."
    else
      echo "ERROR: Root privileges are required for provisioning steps." >&2
    fi
    exit 1
  fi
}

# run_if_command_exists executes a command when available and logs otherwise.
run_if_command_exists() {
  local executable="$1"
  shift
  if command_exists "${executable}"; then
    "${executable}" "$@"
    return 0
  fi
  if command_exists log_warn; then
    log_warn "Skipped ${executable} because it is not installed."
  else
    echo "WARN: Skipped ${executable} because it is not installed." >&2
  fi
  return 1
}
