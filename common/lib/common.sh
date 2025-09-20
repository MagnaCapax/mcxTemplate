#!/usr/bin/env bash
# shellcheck shell=bash
#
# Common shell helpers for the mcxTemplate project.
#
# Usage in another script:
#   # shellcheck disable=SC1091
#   source "$(dirname "$0")/common/lib/common.sh"
# The disable directive above keeps shellcheck quiet about dynamic paths.
#
# Functions in this file prefer readability and simple flow to match KISS.

# Emit the current timestamp in a consistent format for log lines.
_common_timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

# Print a message with a level tag so readers can scan log output quickly.
log_message() {
    local level="$1"
    shift
    printf '%s [%s] %s\n' "$(_common_timestamp)" "$level" "$*"
}

# Convenience wrapper for informational log output.
log_info() {
    log_message "INFO" "$@"
}

# Convenience wrapper for warnings that deserve attention.
log_warn() {
    log_message "WARN" "$@"
}

# Convenience wrapper for errors that precede an early exit.
log_error() {
    log_message "ERROR" "$@"
}

# Log the error and stop the script with the provided exit code (default 1).
die() {
    local message="$1"
    local code="${2:-1}"
    log_error "$message"
    exit "$code"
}

# Ensure a command exists before relying on it.
require_command() {
    local cmd="$1"
    command -v "$cmd" >/dev/null 2>&1 || die "Required command '$cmd' is not available."
}

# Ensure an expected file exists and is readable.
require_readable_file() {
    local path="$1"
    [[ -r "$path" ]] || die "Required file '$path' is missing or unreadable."
}

# Validate that a variable or argument is non-empty.
require_non_empty() {
    local value="$1"
    local name="$2"
    [[ -n "$value" ]] || die "Required value '${name:-value}' is empty."
}
