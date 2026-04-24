#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

(
  cd "${ROOT_DIR}"
  npm run build:js
)

cp "${ROOT_DIR}/demo/index.html" "${ROOT_DIR}/index.html"
cp "${ROOT_DIR}/demo/styles.css" "${ROOT_DIR}/styles.css"
perl -0pe 's#\.\./js/browser/index\.js#./browser-host.js#g' \
  "${ROOT_DIR}/demo/app.js" > "${ROOT_DIR}/app.js"
perl -0pe 's#\.\./\.\./pkg/web/zfs_wasm\.js#./pkg/zfs_wasm.js#g' \
  "${ROOT_DIR}/js/browser/index.js" > "${ROOT_DIR}/browser-host.js"

cp "${ROOT_DIR}/pkg/web/package.json" "${ROOT_DIR}/pkg/package.json"
cp "${ROOT_DIR}/pkg/web/zfs_wasm.d.ts" "${ROOT_DIR}/pkg/zfs_wasm.d.ts"
cp "${ROOT_DIR}/pkg/web/zfs_wasm.js" "${ROOT_DIR}/pkg/zfs_wasm.js"
cp "${ROOT_DIR}/pkg/web/zfs_wasm_bg.wasm" "${ROOT_DIR}/pkg/zfs_wasm_bg.wasm"
cp "${ROOT_DIR}/pkg/web/zfs_wasm_bg.wasm.d.ts" "${ROOT_DIR}/pkg/zfs_wasm_bg.wasm.d.ts"

echo "Refreshed hosted demo assets in ${ROOT_DIR}"
