#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="${1:-../zfs-wasm}"

if [[ ! -d "${SOURCE_DIR}" ]]; then
  echo "Source checkout not found: ${SOURCE_DIR}" >&2
  echo "Usage: $0 /path/to/zfs-wasm" >&2
  exit 1
fi

if [[ ! -f "${SOURCE_DIR}/package.json" || ! -f "${SOURCE_DIR}/scripts/build-js-packages.sh" ]]; then
  echo "Source checkout does not look like the standalone zfs-wasm project: ${SOURCE_DIR}" >&2
  exit 1
fi

(
  cd "${SOURCE_DIR}"
  npm run build:js
)

cp "${SOURCE_DIR}/demo/index.html" "${TARGET_DIR}/index.html"
cp "${SOURCE_DIR}/demo/styles.css" "${TARGET_DIR}/styles.css"
perl -0pe 's#\.\./js/browser/index\.js#./browser-host.js#g' \
  "${SOURCE_DIR}/demo/app.js" > "${TARGET_DIR}/app.js"
perl -0pe 's#\.\./\.\./pkg/web/zfs_wasm\.js#./pkg/zfs_wasm.js#g' \
  "${SOURCE_DIR}/js/browser/index.js" > "${TARGET_DIR}/browser-host.js"

rm -rf "${TARGET_DIR}/pkg"
mkdir -p "${TARGET_DIR}/pkg"
cp -R "${SOURCE_DIR}/pkg/web/." "${TARGET_DIR}/pkg/"

echo "Refreshed ${TARGET_DIR} from ${SOURCE_DIR}"
