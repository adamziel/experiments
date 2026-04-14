# MySQL Binary Log → JSON

A PHP program that connects to a MySQL server as a replication replica, receives the raw binary log event stream over the wire, and outputs one JSON line per event to stdout.

It implements the MySQL replication protocol from scratch — no extensions or libraries needed beyond a plain PHP install with sockets.

## What it captures

Every change that MySQL writes to its binary log:

- **Row changes** (INSERT / UPDATE / DELETE) with full column values. UPDATEs include both the before and after image.
- **DDL queries** (CREATE TABLE, ALTER TABLE, etc.)
- **Transaction boundaries** (GTID, XID/commit markers)
- **Binlog rotation** (when the server switches to a new log file)

Column values are decoded from MySQL's binary format into native JSON types: integers, floats, strings, DECIMAL, DATE/TIME/DATETIME/TIMESTAMP (including fractional seconds), JSON, BIT, ENUM, SET, and BLOBs.

## Quick start

```bash
# Start a MySQL 8.0 instance with binary logging enabled
docker compose up -d

# Wait for it to initialize (~15 seconds), then seed test data
mysql -h 127.0.0.1 -P 13306 -u root -ptest123 < seed.sql

# Start streaming — prints JSON to stdout, progress to stderr
php binlog-json.php \
    --host=127.0.0.1 --port=13306 \
    --user=root --password=test123 \
    | jq .

# In another terminal, make changes and watch them appear
mysql -h 127.0.0.1 -P 13306 -u root -ptest123 < mutations.sql
```

Or run everything automatically:

```bash
./test.sh
```

## Output format

One JSON object per line (NDJSON). Pipe through `jq` for pretty-printing.

**Row change (INSERT):**
```json
{"event":"row_change","timestamp":"2026-04-14T18:57:33+00:00","table":"testdb.users","operation":"INSERT","rows":[{"id":4,"name":"Diana","email":"diana@example.com","balance":"500.00"}]}
```

**Row change (UPDATE) — before and after images:**
```json
{"event":"row_change","timestamp":"2026-04-14T18:57:33+00:00","table":"testdb.users","operation":"UPDATE","rows":[{"before":{"id":2,"name":"Bob","balance":"99.99"},"after":{"id":2,"name":"Bob","balance":"199.99"}}]}
```

**Row change (DELETE):**
```json
{"event":"row_change","timestamp":"2026-04-14T18:57:33+00:00","table":"testdb.users","operation":"DELETE","rows":[{"id":4,"name":"Diana"}]}
```

**DDL query:**
```json
{"event":"query","timestamp":"2026-04-14T18:57:33+00:00","database":"testdb","sql":"ALTER TABLE users ADD COLUMN last_login DATETIME NULL","execution_time_sec":0}
```

**Transaction commit:**
```json
{"event":"gtid","timestamp":"2026-04-14T18:57:33+00:00","gtid":"aabbccdd-1234-5678-9abc-def012345678:42","commit":true}
{"event":"xid","timestamp":"2026-04-14T18:57:33+00:00","xid":128}
```

## CLI options

```
--host=HOST          MySQL host (default: 127.0.0.1)
--port=PORT          MySQL port (default: 3306)
--user=USER          MySQL user (default: root)
--password=PASS      MySQL password (default: empty)
--binlog-file=FILE   Start from this binlog file (default: auto-detect via SHOW MASTER STATUS)
--binlog-pos=POS     Start from this position (default: 4, i.e. beginning of file)
--server-id=ID       Replica server ID (default: 999)
```

## Requirements

- PHP 8.0+ (no extensions beyond sockets, which is built-in)
- MySQL 5.6+ with binary logging enabled and `binlog_format=ROW`
- For column names in output: MySQL 8.0+ with `binlog_row_metadata=FULL`

## Files

```
binlog-json.php      The replication client
docker-compose.yml   MySQL 8.0 with binlog enabled (port 13306)
seed.sql             Sample schema and data
mutations.sql        Sample INSERT/UPDATE/DELETE/DDL to run while streaming
test.sh              Automated end-to-end test
```
