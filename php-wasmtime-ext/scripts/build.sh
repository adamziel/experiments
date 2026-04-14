#!/usr/bin/env bash
# Builds the wasmtime PHP extension. Downloads the wasmtime C API first if missing.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DIR"

./scripts/download-wasmtime.sh

phpize --clean >/dev/null 2>&1 || true
phpize
./configure --enable-wasmtime
make -j"$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 2)"

echo
echo "Built: $DIR/modules/wasmtime.so"
echo "Run tests with: ./scripts/test.sh"
