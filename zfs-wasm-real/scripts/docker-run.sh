#!/usr/bin/env bash
# Run a command inside the build image with the repo mounted.
# Usage: scripts/docker-run.sh <command...>
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_TAG="${IMAGE_TAG:-zfs-wasm-build:local}"

exec docker run --rm -i \
    -v "${ROOT_DIR}:/work" \
    -w /work \
    "${IMAGE_TAG}" \
    bash -lc "$*"
