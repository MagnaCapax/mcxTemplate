#!/usr/bin/env bash
# Common shell helpers shared by mcxTemplate scripts.

# Default location where log files live when file logging is enabled.
: "${MCX_LOG_DIR:=/var/log/mcxTemplate}"
# Default log file name used when no explicit file is requested.
: "${MCX_LOG_FILE_NAME:=mcxTemplate.log}"
# Flag that toggles file logging through environment configuration.
: "${MCX_LOG_TO_FILE:=0}"
# Optional environment override that names the log file to use.
: "${MCX_LOG_FILE:=}"

# Internal variable holding the active log file path (if any).
MCX_LOG_FILE_PATH=""

# Produce a timestamp once per log entry to keep logs traceable.
mcx__log_timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

# Emit to stdout/stderr based on the severity level for easy consumption.
mcx__log_console() {
    local level="$1"
    local line="$2"

    case "${level}" in
        INFO)
            printf '%s\n' "${line}"
            ;;
        WARN|ERROR)
            printf '%s\n' "${line}" >&2
            ;;
        *)
            printf '%s\n' "${line}"
            ;;
    esac
}

# Append to the active log file when file logging is enabled.
mcx__log_append_file() {
    local line="$1"

    if [[ -z "${MCX_LOG_FILE_PATH}" ]]; then
        return 0
    fi

    if printf '%s\n' "${line}" >> "${MCX_LOG_FILE_PATH}"; then
        return 0
    fi

    # Drop back to console-only logging if the append fails at runtime.
    printf 'mcx_log: failed writing to %s, disabling file logging\n' "${MCX_LOG_FILE_PATH}" >&2
    MCX_LOG_FILE_PATH=""
    return 1
}

# Ensure the log directory exists before touching the log file.
mcx__ensure_log_dir() {
    if ! mkdir -p "${MCX_LOG_DIR}"; then
        printf 'mcx_log: unable to create log directory %s\n' "${MCX_LOG_DIR}" >&2
        return 1
    fi
}

# Select the log file to write to, always rooted in MCX_LOG_DIR.
mcx_log_set_file() {
    local requested_name="${1:-${MCX_LOG_FILE_NAME}}"
    local safe_name

    # Always collapse to a simple file name so logs stay in MCX_LOG_DIR.
    safe_name="$(basename "${requested_name}")"
    if [[ -z "${safe_name}" ]]; then
        printf 'mcx_log: empty log file name rejected\n' >&2
        return 1
    fi

    if ! mcx__ensure_log_dir; then
        return 1
    fi

    MCX_LOG_FILE_PATH="${MCX_LOG_DIR}/${safe_name}"

    # Touch the file so operators get early feedback about permission issues.
    if ! touch "${MCX_LOG_FILE_PATH}"; then
        printf 'mcx_log: unable to touch log file %s\n' "${MCX_LOG_FILE_PATH}" >&2
        MCX_LOG_FILE_PATH=""
        return 1
    fi

    return 0
}

# Format the log line and send it to both console and file sinks.
mcx__log_emit() {
    local level="$1"
    shift
    local message="$*"
    local stamp
    local line

    stamp="$(mcx__log_timestamp)"
    line="[${stamp}] [${level}] ${message}"

    mcx__log_console "${level}" "${line}"
    mcx__log_append_file "${line}"
}

# Public helper to log informational messages.
mcx_log_info() {
    mcx__log_emit "INFO" "$@"
}

# Public helper to log warning messages.
mcx_log_warn() {
    mcx__log_emit "WARN" "$@"
}

# Public helper to log error messages.
mcx_log_error() {
    mcx__log_emit "ERROR" "$@"
}

# Respect configuration knobs once this library is sourced.
mcx__log_auto_configure() {
    if [[ -n "${MCX_LOG_FILE}" ]]; then
        mcx_log_set_file "${MCX_LOG_FILE}"
    elif [[ "${MCX_LOG_TO_FILE}" == "1" ]]; then
        mcx_log_set_file "${MCX_LOG_FILE_NAME}"
    fi
}

mcx__log_auto_configure
