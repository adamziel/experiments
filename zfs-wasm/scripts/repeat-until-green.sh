#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -eq 0 ]; then
  echo "usage: $0 <command> [args...]" >&2
  exit 64
fi

attempt=1

while true; do
  echo "== attempt ${attempt} =="
  if "$@"; then
    echo "command passed on attempt ${attempt}"
    exit 0
  fi

  attempt=$((attempt + 1))
  sleep 1
done
