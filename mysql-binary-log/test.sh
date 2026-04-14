#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

MYSQL="mysql -h 127.0.0.1 -P 13306 -u root -ptest123"

echo "==> Starting MySQL..."
docker compose up -d
echo "==> Waiting for MySQL to be ready..."
until docker compose exec mysql mysqladmin ping -h 127.0.0.1 -u root -ptest123 --silent 2>/dev/null; do
    sleep 1
done
echo "==> MySQL is ready."

echo ""
echo "==> Seeding database..."
$MYSQL < seed.sql

echo ""
echo "==> Starting binlog-json.php in the background (output → binlog-output.jsonl)..."
php binlog-json.php \
    --host=127.0.0.1 --port=13306 \
    --user=root --password=test123 \
    > binlog-output.jsonl 2>binlog-stderr.log &
BINLOG_PID=$!

# Give it a moment to connect and start receiving
sleep 2

echo "==> Running mutations..."
$MYSQL < mutations.sql

# Let the events arrive
sleep 2

echo "==> Stopping binlog listener..."
kill $BINLOG_PID 2>/dev/null || true
wait $BINLOG_PID 2>/dev/null || true

echo ""
echo "==> Results (binlog-output.jsonl):"
echo ""
cat binlog-output.jsonl

ROW_EVENTS=$(grep -c '"row_change"' binlog-output.jsonl || true)
QUERY_EVENTS=$(grep -c '"query"' binlog-output.jsonl || true)
echo ""
echo "==> Summary: $ROW_EVENTS row change events, $QUERY_EVENTS query events captured."
echo ""
echo "==> Cleaning up..."
docker compose down
echo "==> Done."
