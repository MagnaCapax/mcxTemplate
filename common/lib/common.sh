#!/usr/bin/env bash
# Logging helpers for mcxTemplate shell scripts with consistent format.
# Functions stay small and predictable to follow the KISS principle.

# Directory that stores log files; callers can override before sourcing.
: "${MCX_LOG_DIR:=/var/log/mcxTemplate}"
# Default log file name used when automatic selection is requested.
: "${MCX_LOG_FILE_NAME:=mcxTemplate.log}"
# Flag enabling file logging when set to 1; console logging stays on.
: "${MCX_LOG_TO_FILE:=0}"
# Optional explicit log file name provided by the operator or script.
: "${MCX_LOG_FILE:=}"

# Holds the fully-qualified log file path once logging to file is active.
MCX_LOG_FILE_PATH=""

# Create a timestamp string for each log entry to aid troubleshooting.
mcx_log__timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

# Send a line to either stdout or stderr based on severity.
mcx_log__console() {
    local level="$1"
    shift
    local message="$*"

    case "${level}" in
        ERROR|WARN)
            printf '%s\n' "${message}" >&2
            ;;
        *)
            printf '%s\n' "${message}"
            ;;
    esac
}

# Append the line to the active log file and disable file logging on failure.
mcx_log__write_file() {
    local line="$1"

    if [[ -z "${MCX_LOG_FILE_PATH}" ]]; then
        return 0
    fi

    if printf '%s\n' "${line}" >> "${MCX_LOG_FILE_PATH}"; then
        return 0
    fi

    mcx_log__console "WARN" "mcx_log: failed to write ${MCX_LOG_FILE_PATH}; disabling file logging"
    MCX_LOG_FILE_PATH=""
    return 1
}

# Ensure the log directory exists and the file is writable before use.
mcx_log__activate_file() {
    local requested="${1:-${MCX_LOG_FILE_NAME}}"
    local file_name

    file_name="$(basename "${requested}")"
    if [[ -z "${file_name}" ]]; then
        mcx_log__console "ERROR" "mcx_log: empty log file name ignored"
        return 1
    fi

    if ! mkdir -p "${MCX_LOG_DIR}"; then
        mcx_log__console "ERROR" "mcx_log: cannot create log directory ${MCX_LOG_DIR}"
        return 1
    fi

    local path="${MCX_LOG_DIR}/${file_name}"

    if ! touch "${path}"; then
        mcx_log__console "ERROR" "mcx_log: cannot open log file ${path}"
        return 1
    fi

    MCX_LOG_FILE_PATH="${path}"
    return 0
}

# Public API to set the log file; leaves console logging untouched.
mcx_log_set_file() {
    local requested="${1:-${MCX_LOG_FILE_NAME}}"

    if mcx_log__activate_file "${requested}"; then
        return 0
    fi

    MCX_LOG_FILE_PATH=""
    return 1
}

# Format the line with timestamp and severity before dispatching sinks.
mcx_log__emit() {
    local level="$1"
    shift
    local text="$*"
    local stamp
    local line

    stamp="$(mcx_log__timestamp)"
    line="[${stamp}] [${level}] ${text}"

    mcx_log__console "${level}" "${line}"
    mcx_log__write_file "${line}"
}

# Public helper for informational messages that go primarily to stdout.
mcx_log_info() {
    mcx_log__emit "INFO" "$@"
}

# Public helper for warning messages that highlight recoverable issues.
mcx_log_warn() {
    mcx_log__emit "WARN" "$@"
}

# Public helper for errors that typically stop the flow and need attention.
mcx_log_error() {
    mcx_log__emit "ERROR" "$@"
}

# Configure file logging automatically when the library is sourced.
mcx_log_configure() {
    if [[ -n "${MCX_LOG_FILE}" ]]; then
        mcx_log_set_file "${MCX_LOG_FILE}" || return 1
    elif [[ "${MCX_LOG_TO_FILE}" == "1" ]]; then
        mcx_log_set_file "${MCX_LOG_FILE_NAME}" || return 1
    fi

    return 0
}

# Invoke configuration right away so scripts get predictable behavior.
mcx_log_configure
