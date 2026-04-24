#!/usr/bin/env bash
# Build the emsdk+autotools image used for every subsequent step.
# All compilation happens inside this image. The host never runs emcc.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

IMAGE_TAG="${IMAGE_TAG:-zfs-wasm-build:local}"

docker build \
    -f docker/Dockerfile \
    -t "${IMAGE_TAG}" \
    docker/

echo "Built ${IMAGE_TAG}"
