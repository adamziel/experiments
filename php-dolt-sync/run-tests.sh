#!/usr/bin/env bash
# Runs the wp-sync test suite. Needs php 8.2+ with sqlite3, intl, mbstring.
set -euo pipefail
cd "$(dirname "$0")"
exec php tests/run.php "$@"
