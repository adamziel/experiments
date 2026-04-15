#!/usr/bin/env bash
# Start a MariaDB instance dedicated to integration tests.
# Writes PORT/SOCKET/PID/DATADIR to harness/.env for subsequent reuse.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "$0")" && pwd)"
DATADIR="/tmp/cow-mariadb"
ENV_FILE="$HARNESS_DIR/.env"
LOG_FILE="$DATADIR/mariadbd.log"

# Already running? Reuse.
if [ -f "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    if [ -n "${PID:-}" ] && kill -0 "$PID" 2>/dev/null; then
        echo "MariaDB already running on port $PORT (pid $PID); reusing."
        exit 0
    fi
fi

# External-server mode (e.g. GitHub Actions service container). If
# COW_MARIADB_HOST is set, skip spinning up our own mariadbd and just
# write the .env pointing at the external server.
if [ -n "${COW_MARIADB_HOST:-}" ]; then
    EXT_HOST="${COW_MARIADB_HOST}"
    EXT_PORT="${COW_MARIADB_PORT:-3306}"
    EXT_USER="${COW_MARIADB_USER:-root}"
    EXT_PASSWORD="${COW_MARIADB_PASSWORD:-}"
    EXT_DBNAME="${COW_MARIADB_DBNAME:-wptest}"
    echo "External MariaDB mode: $EXT_USER@$EXT_HOST:$EXT_PORT"
    # Wait up to 60s for it to accept connections.
    for i in $(seq 1 120); do
        if MYSQL_PWD="$EXT_PASSWORD" mariadb --protocol=tcp -h "$EXT_HOST" -P "$EXT_PORT" -u "$EXT_USER" -e "SELECT 1" >/dev/null 2>&1 \
           || MYSQL_PWD="$EXT_PASSWORD" mysql --protocol=tcp -h "$EXT_HOST" -P "$EXT_PORT" -u "$EXT_USER" -e "SELECT 1" >/dev/null 2>&1; then
            break
        fi
        sleep 0.5
    done
    # Create the test database if missing.
    MYSQL_PWD="$EXT_PASSWORD" mariadb --protocol=tcp -h "$EXT_HOST" -P "$EXT_PORT" -u "$EXT_USER" \
        -e "CREATE DATABASE IF NOT EXISTS \`$EXT_DBNAME\`" 2>/dev/null \
        || MYSQL_PWD="$EXT_PASSWORD" mysql --protocol=tcp -h "$EXT_HOST" -P "$EXT_PORT" -u "$EXT_USER" \
            -e "CREATE DATABASE IF NOT EXISTS \`$EXT_DBNAME\`" || {
        echo "FAILED: could not connect to external MariaDB/MySQL at $EXT_HOST:$EXT_PORT" >&2
        exit 1
    }
    cat > "$ENV_FILE" <<EOF
PORT=$EXT_PORT
SOCKET=
PID=
DATADIR=
HOST=$EXT_HOST
USER=$EXT_USER
PASSWORD=$EXT_PASSWORD
DBNAME=$EXT_DBNAME
EXTERNAL=1
EOF
    echo "External MariaDB ready: $EXT_HOST:$EXT_PORT db=$EXT_DBNAME"
    exit 0
fi

# Pick an ephemeral port.
PORT=$(python3 -c "import socket;s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1]);s.close()" 2>/dev/null || \
    php -r '$s=stream_socket_server("tcp://127.0.0.1:0");$n=stream_socket_get_name($s,false);echo (int)substr($n,strrpos($n,":")+1);')

SOCKET="$DATADIR/mariadb.sock"

# Discover the MariaDB basedir (required on Nix since binaries live in
# /run/current-system/sw/bin but share/mysql/*.sql does not).
BASEDIR=""
if [ -f /run/current-system/sw/share/mysql/fill_help_tables.sql ]; then
    BASEDIR=/run/current-system/sw
else
    CAND="$(find /nix/store -maxdepth 4 -name 'fill_help_tables.sql' 2>/dev/null | head -1)"
    if [ -n "$CAND" ]; then
        BASEDIR="$(dirname "$(dirname "$(dirname "$CAND")")")"
    fi
fi

# Initialize datadir if needed.
if [ ! -d "$DATADIR/mysql" ]; then
    echo "Initializing datadir at $DATADIR (basedir=$BASEDIR)..."
    rm -rf "$DATADIR"
    mkdir -p "$DATADIR"
    BASEDIR_ARG=""
    [ -n "$BASEDIR" ] && BASEDIR_ARG="--basedir=$BASEDIR"
    mariadb-install-db \
        $BASEDIR_ARG \
        --datadir="$DATADIR" \
        --auth-root-authentication-method=normal \
        --user="$(id -un)" \
        --skip-test-db >/tmp/cow-mariadb-init.log 2>&1 || {
        echo "mariadb-install-db failed. Log tail:" >&2
        tail -20 /tmp/cow-mariadb-init.log >&2
        exit 1
    }
fi

# Start the server in the background with minimal config.
echo "Starting mariadbd on port $PORT..."
mariadbd \
    --datadir="$DATADIR" \
    --socket="$SOCKET" \
    --port="$PORT" \
    --bind-address=127.0.0.1 \
    --skip-networking=0 \
    --pid-file="$DATADIR/mariadb.pid" \
    --log-error="$LOG_FILE" \
    --skip-name-resolve \
    --innodb-buffer-pool-size=64M \
    --innodb-log-file-size=8M \
    --innodb-flush-log-at-trx-commit=0 \
    --innodb-flush-method=nosync \
    --key-buffer-size=8M \
    --sync-binlog=0 \
    --skip-log-bin \
    --max-connections=50 \
    --performance-schema=0 \
    >/dev/null 2>&1 &

BG_PID=$!

# Wait until the server is accepting connections (up to 30s).
for i in $(seq 1 60); do
    if mariadb --protocol=tcp -h 127.0.0.1 -P "$PORT" -u root -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    sleep 0.5
done

# Resolve the actual pid (mariadbd may fork/exec).
if [ -f "$DATADIR/mariadb.pid" ]; then
    PID="$(cat "$DATADIR/mariadb.pid")"
else
    PID="$BG_PID"
fi

if ! kill -0 "$PID" 2>/dev/null; then
    echo "FAILED to start mariadbd. Log tail:" >&2
    tail -50 "$LOG_FILE" >&2 || true
    exit 1
fi

# Verify final connectivity.
if ! mariadb --protocol=tcp -h 127.0.0.1 -P "$PORT" -u root -e "SELECT 1" >/dev/null 2>&1; then
    echo "FAILED: mariadbd running but not accepting connections." >&2
    tail -50 "$LOG_FILE" >&2 || true
    exit 1
fi

cat > "$ENV_FILE" <<EOF
PORT=$PORT
SOCKET=$SOCKET
PID=$PID
DATADIR=$DATADIR
HOST=127.0.0.1
USER=root
PASSWORD=
DBNAME=wptest
EOF

echo "MariaDB up: port=$PORT pid=$PID"
