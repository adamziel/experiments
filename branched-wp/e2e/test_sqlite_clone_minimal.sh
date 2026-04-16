#!/bin/bash
# ============================================================
# Minimal SQLite-clone smoke test — used by release CI to
# validate per-platform release artifacts.
#
# Covers only the three assertions that prove the C extension +
# bundled PHP + bundled Dolt actually work on this platform:
#
#   A. git clone against the running dev stack succeeds
#   B. the cloned .ht.sqlite opens and has wp_* tables
#   G. `php -S` on the cloned wordpress/ directory returns HTTP
#      200 with a <title> — i.e. WP boots against the SQLite
#      drop-in via the bundled PHP.
#
# Intentionally skips push/round-trip/wp-cli/idempotency — those
# are covered by test_sqlite_clone.sh on linux-x64, which is the
# one platform where we run the full suite. The port validation
# only needs to prove the binary works, not re-test userland.
#
# Requires the dev stack to be running (e2e/dev.sh in another
# shell, or equivalent). Honors PHP_BIN, PHP_PORT, BOOT_PORT.
# Exit 0 on all three PASS, non-zero otherwise.
# ============================================================
set -u
set -o pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_PORT="${PHP_PORT:-18080}"
BOOT_PORT="${BOOT_PORT:-9091}"
CLONE_DIR="/tmp/wp-sqlite-clone-minimal-$$"
PHP_BIN="${PHP_BIN:-php}"
PASS=0
FAIL=0
BOOT_PID=""

# Prefer a PHP with pdo_sqlite + sqlite3 — without either the
# SQLite integration drop-in refuses to serve the clone.
for cand in "$PHP_BIN" "php" "/usr/bin/php" "/usr/local/bin/php"; do
    if [ -x "$(command -v "$cand" 2>/dev/null)" ] \
       && "$cand" -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("sqlite3") ? 0 : 1);' 2>/dev/null; then
        PHP_BIN="$cand"; break
    fi
done

cleanup() {
    if [ -n "$BOOT_PID" ]; then
        kill "$BOOT_PID" 2>/dev/null || true
        for _ in 1 2 3 4 5; do
            kill -0 "$BOOT_PID" 2>/dev/null || break
            sleep 0.5
        done
        kill -9 "$BOOT_PID" 2>/dev/null || true
        wait "$BOOT_PID" 2>/dev/null || true
    fi
    pkill -f "php -S 127.0.0.1:$BOOT_PORT" 2>/dev/null || true
    rm -rf "$CLONE_DIR"
}
trap cleanup EXIT

step() { echo ""; echo "=== $1: $2 ==="; }
pass() { echo "  PASS"; PASS=$((PASS+1)); }
fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }

# Confirm the remote is reachable before we test against it.
echo "Waiting for dev server on 127.0.0.1:$PHP_PORT..."
for i in $(seq 1 30); do
    if curl -s -o /dev/null "http://127.0.0.1:$PHP_PORT/" 2>/dev/null; then break; fi
    if [ "$i" -eq 30 ]; then echo "FATAL: dev server not responding on :$PHP_PORT"; exit 1; fi
    sleep 1
done

# -------------------------------------------------------------
# A. git clone
# -------------------------------------------------------------
step A "git clone http://127.0.0.1:$PHP_PORT/site.git $CLONE_DIR"
rm -rf "$CLONE_DIR"
if git clone "http://127.0.0.1:$PHP_PORT/site.git" "$CLONE_DIR" 2>&1 | tail -3; then
    pass
else
    fail "git clone exited non-zero"
fi

# -------------------------------------------------------------
# B. .ht.sqlite exists + wp_* tables have rows
# -------------------------------------------------------------
step B ".ht.sqlite exists and has wp_* tables"
SQLITE_FILE="$CLONE_DIR/wordpress/wp-content/database/.ht.sqlite"
if [ ! -f "$SQLITE_FILE" ]; then
    fail "$SQLITE_FILE missing"
else
    # Assert: at least wp_options, wp_posts, wp_users exist and
    # wp_options has the canonical blogname row.
    TABLE_OK=$("$PHP_BIN" -r '
        $s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
        $want = ["wp_options","wp_posts","wp_users"];
        $have = [];
        $r = $s->query("SELECT name FROM sqlite_master WHERE type=\"table\"");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $have[] = $row["name"];
        foreach ($want as $t) {
            if (!in_array($t, $have)) { echo "missing-table: $t"; exit(1); }
        }
        $blogname = $s->querySingle("SELECT option_value FROM wp_options WHERE option_name=\"blogname\"");
        if (!$blogname) { echo "missing-blogname"; exit(1); }
        echo "blogname=" . $blogname;
    ' "$SQLITE_FILE" 2>&1)
    if [ $? -eq 0 ]; then
        echo "  $TABLE_OK"
        pass
    else
        fail "sqlite check failed: $TABLE_OK"
    fi
fi

# -------------------------------------------------------------
# G. php -S on the clone serves HTTP 200 with <title>
# -------------------------------------------------------------
step G "php -S on $CLONE_DIR/wordpress serves HTTP 200 + <title>"

# Auto-prepend WP-cron disable + external HTTP block so boot
# doesn't loopback to the remote dev stack.
PREPEND="/tmp/sqlite-clone-minimal-prepend-$$.php"
cat > "$PREPEND" <<'EOF'
<?php
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
if (!defined('WP_HTTP_BLOCK_EXTERNAL')) define('WP_HTTP_BLOCK_EXTERNAL', true);
EOF

"$PHP_BIN" -d "auto_prepend_file=$PREPEND" -S "127.0.0.1:$BOOT_PORT" \
    -t "$CLONE_DIR/wordpress" \
    > /tmp/sqlite-clone-minimal-boot.log 2>&1 &
BOOT_PID=$!

# Wait for port.
PORT_UP=0
for i in $(seq 1 30); do
    if "$PHP_BIN" -r "\$s=@fsockopen('127.0.0.1',$BOOT_PORT,\$e,\$m,0.5); if(\$s){fclose(\$s);exit(0);} exit(1);" 2>/dev/null; then
        PORT_UP=1; break
    fi
    if ! kill -0 "$BOOT_PID" 2>/dev/null; then
        echo "  php -S died early:"
        tail -10 /tmp/sqlite-clone-minimal-boot.log
        break
    fi
    sleep 1
done

if [ "$PORT_UP" -ne 1 ]; then
    fail "php -S never opened port $BOOT_PORT"
else
    BODY_FILE="/tmp/sqlite-clone-minimal-body-$$.out"
    STATUS=$(curl -s --max-time 30 -o "$BODY_FILE" \
                  -w "%{http_code}" "http://127.0.0.1:$BOOT_PORT/" \
             || echo ERR)
    TITLE=$(grep -oE '<title>[^<]+</title>' "$BODY_FILE" 2>/dev/null | head -1 || true)
    if [ "$STATUS" = "200" ] && [ -n "$TITLE" ]; then
        echo "  status=$STATUS title=$TITLE"
        pass
    else
        fail "status=$STATUS title='$TITLE'"
        echo "  --- boot log tail ---"
        tail -15 /tmp/sqlite-clone-minimal-boot.log | sed 's/^/    /'
        echo "  --- body head ---"
        head -c 400 "$BODY_FILE" 2>/dev/null | sed 's/^/    /'
    fi
    rm -f "$BODY_FILE"
fi
rm -f "$PREPEND"

echo ""
echo "============================================================"
echo "  RESULTS: $PASS passed, $FAIL failed (out of 3)"
echo "============================================================"
[ "$FAIL" -eq 0 ] || exit 1
echo "ALL MINIMAL SMOKE CHECKS PASSED"
exit 0
