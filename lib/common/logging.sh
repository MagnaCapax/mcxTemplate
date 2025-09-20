#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# logging.sh - Shared logging helpers for mcxTemplate provisioning scripts.
# Provides small wrappers so every script prints in a consistent format.
# -----------------------------------------------------------------------------

# Prevent duplicate definitions when sourced multiple times.
if [[ -n "${MCX_LOGGING_SH:-}" ]]; then
  return 0
fi
MCX_LOGGING_SH=1

# format_time outputs an ISO-8601 timestamp for log lines.
format_time() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

# log_with_level prints a message prefixed with a timestamp and severity tag.
log_with_level() {
  local level="$1"
  shift
  printf '%s [%s] %s\n' "$(format_time)" "${level}" "$*"
}

# Informational logging for normal progress updates.
log_info() {
  log_with_level INFO "$@"
}

# Warning logging for recoverable issues that need attention.
log_warn() {
  log_with_level WARN "$@"
}

# Error logging for unrecoverable problems before exiting.
log_error() {
  log_with_level ERROR "$@"
}
