#!/usr/bin/env bash
# Downloads the wasmtime C API into ./wasmtime-c-api/.
set -euo pipefail

VERSION="${WASMTIME_VERSION:-v29.0.1}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$DIR/wasmtime-c-api"

if [ -d "$TARGET/include" ] && [ -d "$TARGET/lib" ]; then
  echo "wasmtime C API already present at $TARGET — skipping download."
  exit 0
fi

UNAME_S="$(uname -s | tr '[:upper:]' '[:lower:]')"
UNAME_M="$(uname -m)"
case "$UNAME_S-$UNAME_M" in
  linux-x86_64)   PLATFORM="x86_64-linux" ;;
  linux-aarch64)  PLATFORM="aarch64-linux" ;;
  darwin-x86_64)  PLATFORM="x86_64-macos" ;;
  darwin-arm64)   PLATFORM="aarch64-macos" ;;
  *) echo "Unsupported platform: $UNAME_S-$UNAME_M" >&2; exit 1 ;;
esac

ARCHIVE="wasmtime-${VERSION}-${PLATFORM}-c-api.tar.xz"
URL="https://github.com/bytecodealliance/wasmtime/releases/download/${VERSION}/${ARCHIVE}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading $URL"
curl -fL -o "$TMP/$ARCHIVE" "$URL"
tar -xf "$TMP/$ARCHIVE" -C "$TMP"

EXTRACTED="$TMP/wasmtime-${VERSION}-${PLATFORM}-c-api"
mkdir -p "$TARGET"
cp -r "$EXTRACTED/include" "$TARGET/"
cp -r "$EXTRACTED/lib"     "$TARGET/"

echo "Installed wasmtime C API ${VERSION} to $TARGET"
