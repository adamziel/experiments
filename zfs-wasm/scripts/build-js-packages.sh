#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WASM_PATH="${ROOT_DIR}/target/wasm32-unknown-unknown/release/zfs_wasm.wasm"

cd "${ROOT_DIR}"

cargo build --release --target wasm32-unknown-unknown

rm -rf pkg/node pkg/web
mkdir -p pkg/node pkg/web

wasm-bindgen \
  --target nodejs \
  --out-dir pkg/node \
  --out-name zfs_wasm \
  "${WASM_PATH}"

wasm-bindgen \
  --target web \
  --out-dir pkg/web \
  --out-name zfs_wasm \
  "${WASM_PATH}"

printf '{\n  "type": "commonjs"\n}\n' > pkg/node/package.json
printf '{\n  "type": "module"\n}\n' > pkg/web/package.json
