#!/usr/bin/env bash
# Thin wrapper kept so `bash tools/run-tests.sh` (CI, docs, contributors) keeps working.
# All logic lives in tools/runTests.php; its exit code is ours.
exec php "$(dirname "${BASH_SOURCE[0]}")/runTests.php" "$@"
