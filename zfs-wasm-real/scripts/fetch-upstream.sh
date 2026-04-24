#!/usr/bin/env bash
# Fetch upstream OpenZFS at the tag pinned in VERSION.
# Runs inside the build container so the host has no git-over-https requirement.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

# shellcheck disable=SC1091
source VERSION

UPSTREAM_DIR="upstream/zfs"
if [[ -d "${UPSTREAM_DIR}/.git" ]]; then
    echo "upstream already present at ${UPSTREAM_DIR}; fetching tag ${openzfs_tag}"
    git -C "${UPSTREAM_DIR}" fetch --depth=1 origin "tag" "${openzfs_tag}"
    git -C "${UPSTREAM_DIR}" checkout -q "tags/${openzfs_tag}"
else
    mkdir -p upstream
    git clone --depth=1 --branch "${openzfs_tag}" \
        https://github.com/openzfs/zfs.git "${UPSTREAM_DIR}"
fi

echo "OpenZFS at $(git -C "${UPSTREAM_DIR}" describe --tags --always)"
