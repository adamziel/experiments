#!/usr/bin/env bash
# Shut down the integration-test MariaDB and remove the env file.
set -eu

HARNESS_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$HARNESS_DIR/.env"

if [ ! -f "$ENV_FILE" ]; then
    echo "No .env file; nothing to stop."
    exit 0
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

# External-server mode: we didn't start it, so don't stop it.
if [ "${EXTERNAL:-0}" = "1" ]; then
    rm -f "$ENV_FILE"
    echo "External MariaDB not shut down (managed externally)."
    exit 0
fi

if [ -n "${PID:-}" ] && kill -0 "$PID" 2>/dev/null; then
    echo "Stopping MariaDB pid=$PID..."
    mariadb-admin --protocol=tcp -h 127.0.0.1 -P "$PORT" -u root shutdown >/dev/null 2>&1 || true
    for i in $(seq 1 30); do
        if ! kill -0 "$PID" 2>/dev/null; then break; fi
        sleep 0.3
    done
    if kill -0 "$PID" 2>/dev/null; then
        kill "$PID" 2>/dev/null || true
        sleep 1
        kill -9 "$PID" 2>/dev/null || true
    fi
fi

rm -f "$ENV_FILE"
echo "MariaDB stopped."
