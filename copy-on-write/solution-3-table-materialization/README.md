# Solution 3: Table-Level Lazy Materialization with Change Journal

Copy-on-write WordPress database cloning using table-level lazy materialization. When WordPress first queries a table, the entire table is fetched from the remote MySQL and imported into a local SQLite database. After materialization, all operations are 100% local SQLite. A change journal records all mutations for diff/replay capabilities.

## Key Insight

WordPress typically touches only 5-15 tables during a page load, and the critical tables (`wp_options`, `wp_users`) are usually small. Large tables (e.g., `wp_posts` with millions of rows) are only fetched if actually accessed. This makes startup nearly instant regardless of database size.

## Architecture

```
Remote MySQL ──────────────────────────────────────┐
  (source of truth, read-only)                     │
                                                   │
CowDatabase (orchestrator)                         │
  ├── SQL Parser ── extracts table names           │
  ├── TableMaterializer ── fetches & imports ──────┘
  │     ├── SchemaTranslator (MySQL DDL → SQLite DDL)
  │     └── MaterializationTracker (_cow_materialized)
  ├── ChangeJournal (_cow_journal)
  └── Local SQLite (all queries execute here)
```

### Flow

1. **Startup**: Only the list of remote table names is fetched. No row data. Startup time is < 1ms even with 500+ tables.
2. **First query**: SQL is parsed to find referenced tables. Each unmaterialized table is fetched from remote (schema + all rows), translated to SQLite, and imported in a transaction.
3. **Subsequent queries**: Tables are already local. Native SQLite performance.
4. **Writes**: All INSERT/UPDATE/DELETE operations happen locally. The change journal records every mutation with old and new values for later diff/replay.

## WordPress Playground Integration

This solution fits naturally into WordPress Playground's architecture:

1. **Remote backend**: The `RemoteTableClient` interface would be implemented as an HTTP client calling the source WordPress site's REST API (e.g., a custom `/wp-json/cow/v1/tables` endpoint that returns `SHOW CREATE TABLE` and row data). No direct MySQL access needed.

2. **Schema translation**: The `SchemaTranslator` handles the MySQL-to-SQLite DDL conversion that WordPress Playground's `sqlite-database-integration` plugin also needs. This solution reuses the same patterns: mapping MySQL types (BIGINT, VARCHAR, LONGTEXT, ENUM) to SQLite equivalents.

3. **Startup flow**: Playground loads, `CowDatabase` is constructed (fetches only the table name list, ~1 API call), and the site is immediately ready. When WordPress issues its first query (typically `SELECT * FROM wp_options WHERE autoload = 'yes'`), only `wp_options` is fetched and imported into local SQLite.

4. **Ideal for**: Sites where you want full SQL compatibility after the first access. Once a table is materialized, JOINs, aggregates, subqueries, and all SQLite features work natively. The trade-off is the per-table materialization cost on first access.

5. **Practical optimization**: WordPress almost always loads `wp_options` first. A warm-up step can prefetch this table during Playground initialization, making the first page load feel instant for typical sites.

## Files

| File | Purpose |
|------|---------|
| `src/SchemaTranslator.php` | Converts MySQL CREATE TABLE to SQLite-compatible DDL |
| `src/RemoteTableClient.php` | Interface for remote MySQL access + mock implementation |
| `src/MaterializationTracker.php` | Tracks which tables have been materialized |
| `src/ChangeJournal.php` | Write-ahead log for all post-materialization mutations |
| `src/TableMaterializer.php` | Fetches and imports a table from remote to local SQLite |
| `src/CowDatabase.php` | Main orchestrator: parses SQL, materializes on demand, executes locally |
| `tests/SchemaTranslatorTest.php` | Tests MySQL-to-SQLite schema translation |
| `tests/ChangeJournalTest.php` | Tests change journal recording and querying |
| `tests/IntegrationTest.php` | End-to-end tests for the full materialization flow |
| `tests/StartupTimeTest.php` | Performance tests verifying fast startup |
| `tests/TestRunner.php` | Simple test runner |

## Running Tests

```bash
php tests/TestRunner.php
```

All tests use `:memory:` SQLite databases and the `MockRemoteTableClient`, so no external dependencies are needed.

## Trade-offs

### Advantages

- **Simple mental model**: Each table is either fully remote or fully local. No partial states or row-level tracking.
- **Native SQLite performance**: After materialization, queries run at full SQLite speed with no interception overhead.
- **Fast startup**: Only table names are fetched at startup. Even with 500 tables and 500M+ virtual rows, startup takes < 1ms.
- **Complete change tracking**: The journal records every mutation with old/new values, enabling diff generation and replay.
- **Cross-table queries work naturally**: JOINs trigger materialization of all involved tables, then execute natively.

### Disadvantages

- **All-or-nothing per table**: If a query touches `wp_posts` with 1M rows, the entire table must be fetched before any results are returned. For a 500MB table over a 10Mbps connection, this means ~7 minutes of blocking on first access. There's no way to return partial results during materialization.
- **Memory/bandwidth proportional to table size**: A 1M-row table at 1KB/row needs ~1GB of memory during materialization (raw data + SQLite overhead). This may exceed browser/WASM memory limits in Playground.
- **No incremental sync**: Once materialized, the local copy is frozen. If the remote changes after materialization, there's no mechanism to pull updates. The clone is a point-in-time snapshot.
- **No write-back**: Changes are local only. The change journal enables generating a diff, but there's no built-in mechanism to apply it back to the remote.
- **Schema translation gaps**: The `SchemaTranslator` handles common MySQL types but doesn't support: spatial types, generated columns, MySQL-specific functions in DEFAULT clauses, FULLTEXT indexes, or foreign key constraints. These are silently skipped, which may cause subtle behavioral differences.
- **No transaction isolation during materialization**: If a query triggers materialization of two tables and the remote data changes between the two fetches, the local copy may contain an inconsistent snapshot.

## Possible Optimizations

- **Table prefetching**: Predict which tables WordPress will need (e.g., `wp_options` is almost always accessed first) and prefetch them in the background during startup.
- **Streaming materialization**: Instead of fetching all rows at once, stream them in batches to reduce peak memory usage.
- **Partial materialization**: For very large tables, only materialize rows matching certain criteria (e.g., recent posts only). This breaks the simple mental model but could be valuable for specific use cases.
- **Parallel fetching**: When a JOIN references multiple unmaterialized tables, fetch them in parallel.
- **Schema caching**: Cache translated schemas to avoid re-translating on repeated materializations across sessions.
- **Compression**: Compress row data during transfer from remote to reduce bandwidth.
