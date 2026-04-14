#!/usr/bin/env bash
# Convenience script to run PHP scripts with the wasmtime extension loaded.
# Usage: ./run.sh script.php [args...]
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export LD_LIBRARY_PATH="${DIR}/wasmtime-c-api/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

exec php -d "extension=${DIR}/modules/wasmtime.so" "$@"
