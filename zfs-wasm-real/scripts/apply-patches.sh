#!/usr/bin/env bash
# Apply our patch queue to upstream/zfs. Idempotent: uses -N to skip
# already-applied patches.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}/upstream/zfs"

for p in "${ROOT_DIR}"/patches/*.patch; do
    [[ -f "$p" ]] || continue
    echo "Applying $(basename "$p")"
    patch -p1 -N --no-backup-if-mismatch --silent < "$p" || true
done
