#!/usr/bin/env bash
# Runs the extension test suite.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DIR"

if [ ! -f modules/wasmtime.so ]; then
  echo "modules/wasmtime.so not found — run ./scripts/build.sh first." >&2
  exit 1
fi

export LD_LIBRARY_PATH="${DIR}/wasmtime-c-api/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec php -d "extension=${DIR}/modules/wasmtime.so" tests/run_tests.php "$@"
