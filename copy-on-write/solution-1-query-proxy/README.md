# Solution 1: Query-Level Proxy with Row-Level Overlay

A copy-on-write (COW) database layer for WordPress that intercepts SQL queries at the `$wpdb` level. Reads are forwarded to a remote MySQL server; writes are captured in a local SQLite overlay. On reads, results from remote and local overlay are merged, with local changes taking precedence.

## How it works

1. **QueryClassifier** parses each SQL query to determine its type (SELECT, INSERT, UPDATE, DELETE, DDL) and extracts table names using regex.

2. **CowDatabase** (the orchestrator) routes queries:
   - **READ** queries go to the remote connection, then results are merged with local overlay state.
   - **WRITE** queries are intercepted and stored only in the local SQLite overlay. The remote database is never modified.
   - **DDL** queries (CREATE TABLE, etc.) create local-only tables in the overlay.

3. **LocalOverlayStore** uses SQLite to track:
   - Inserted rows (with negative auto-increment IDs to avoid collisions with remote)
   - Updated rows (keyed by primary key, storing only changed columns)
   - Deleted row keys

4. **ResultMerger** combines remote results with local state:
   - Filters out locally-deleted rows
   - Replaces locally-updated rows (merging changed columns)
   - Appends locally-inserted rows

5. **SchemaCache** lazily fetches and caches table schemas (columns, primary keys) from the remote, avoiding repeated lookups.

## Running tests

```bash
php tests/TestRunner.php
```

All tests use in-memory SQLite and a mock remote connection -- no external dependencies required.

## Architecture diagram

```
  SQL Query
      |
      v
  CowDatabase (orchestrator)
      |
      +-- QueryClassifier --> classify as READ / WRITE / DDL
      |
      +-- READ path:
      |     |
      |     +-- RemoteConnection.query(sql) --> remote rows
      |     +-- ResultMerger.merge(remote, overlay) --> final rows
      |
      +-- WRITE path:
      |     |
      |     +-- LocalOverlayStore.record{Insert,Update,Delete}()
      |     (remote is NEVER touched)
      |
      +-- SchemaCache (lazy, per-table)
```

## Trade-offs

**Advantages:**
- Zero startup cost -- no data is copied on clone
- Remote database is never modified (true copy-on-write)
- Works with any remote MySQL without special server configuration
- Local changes are isolated and can be discarded by deleting the SQLite file

**Limitations:**
- **Aggregates are inaccurate**: `SELECT COUNT(*) FROM posts` on the remote won't account for locally inserted/deleted rows. SUM, AVG, GROUP BY are all affected. Workaround: rewrite aggregate queries to fetch raw rows and compute locally, which defeats the purpose for large tables.
- **JOINs across local/remote are incomplete**: If table A has local changes and table B doesn't, a JOIN may produce wrong results since the remote query runs against unmodified data. This is a fundamental limitation of the query-proxy approach.
- **SQL parsing is regex-based**: Exotic query forms (nested subqueries, CTEs, UNION, complex expressions in WHERE) may not be correctly classified. WordPress core queries are generally handled, but plugins with complex SQL may break.
- **Single primary key assumption**: Composite keys are not supported. Tables must have a single PK column (configurable, defaults to `id`).
- **Negative IDs for local inserts**: Locally inserted rows get negative auto-increment IDs to avoid collision with remote. WordPress plugins that assume positive IDs (e.g., for URL generation) will see unexpected behavior.
- **No transaction support**: There's no transaction isolation between reads and writes. A long-running page load may see inconsistent data if the remote changes between queries.
- **Network latency**: Every SELECT causes a remote round-trip. For pages that issue 50+ queries, the latency adds up. Consider connection pooling or query batching for production use.

## WordPress Playground Integration

This solution integrates with WordPress Playground's `sqlite-database-integration` plugin architecture:

1. **Drop-in replacement for `$wpdb`**: The `CowDatabase` class can be wrapped as a WordPress `db.php` drop-in. When WordPress calls `$wpdb->query()`, the COW layer intercepts it, classifying the query and routing reads to the remote MySQL (via REST API or direct connection) and writes to the local SQLite overlay.

2. **Remote backend options**:
   - **REST API proxy**: The `RemoteMySQLConnectionInterface` can be implemented as an HTTP client that sends SELECT queries to a WordPress REST endpoint on the source site. This is the most practical approach for Playground since it requires no direct MySQL access.
   - **MySQL wire protocol**: For local development, the remote connection can be a real PDO MySQL connection.

3. **Startup flow**: WordPress Playground loads, the COW `db.php` initializes (< 1ms), and the site is immediately usable. Table schemas are fetched lazily on first query. No data is pre-downloaded.

4. **Ideal for**: Sites where most page loads touch a small subset of tables, and writes are infrequent. The per-query overhead (one remote round-trip for reads) is acceptable when the alternative is downloading a 100GB database.

## Key design decisions

- **Negative IDs for local inserts**: Locally inserted rows get negative auto-increment IDs (`-1, -2, ...`) to guarantee no collision with remote positive IDs.
- **Overlay, not replay**: Writes are stored declaratively (inserted rows, updated columns, deleted keys) rather than as a SQL replay log. This makes merging deterministic.
- **Lazy schema fetching**: Table schemas are fetched from the remote only when first needed, so startup is O(1) regardless of database size.
- **SQLite for overlay**: Provides ACID guarantees for local state with zero configuration. Uses `:memory:` in tests for speed.

## File structure

```
src/
  QueryClassifier.php      - SQL query classification and table extraction
  LocalOverlayStore.php    - SQLite-backed local change tracking
  RemoteMySQLConnection.php - Remote MySQL interface + mock implementation
  SchemaCache.php          - Lazy schema caching
  ResultMerger.php         - Merges remote results with local overlay
  CowDatabase.php          - Main orchestrator
tests/
  QueryClassifierTest.php  - Query classification tests
  ResultMergerTest.php     - Result merging tests
  IntegrationTest.php      - End-to-end tests
  StartupTimeTest.php      - Performance / startup time tests
  TestRunner.php           - Test discovery and execution
```
