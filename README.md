# mcxTemplate
Templating engine for distro installations

## Logging helpers
The shell helpers in `common/lib/common.sh` expose timestamped logging functions
(`mcx_log_info`, `mcx_log_warn`, and `mcx_log_error`). Console output always
remains enabled so operators continue to see status messages even when file
logging is turned on. Persisted logging lives beneath `/var/log/mcxTemplate/`
and can be toggled by setting the `MCX_LOG_TO_FILE=1` flag or by supplying an
explicit `MCX_LOG_FILE` name during startup. The helper automatically creates
the directory and log file and falls back to console-only logging if writes to
the file fail.

Scripts should source the helper file and rely on the logging functions instead
of raw `echo` statements.
