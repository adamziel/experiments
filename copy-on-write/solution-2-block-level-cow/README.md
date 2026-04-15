# Solution 2: SQLite Binary Format Page-Level COW

Reads the SQLite file format directly at the binary level, navigating B-tree structures and fetching only the pages needed. Writes go to a local SQLite overlay. The remote file is never modified.

## Architecture

```
                       CowDatabase
                      /           \
              [READS]               [WRITES]
                |                      |
          BTreeReader            Local SQLite (PDO)
                |                 (materialized tables)
        CowPageProvider
           /       \
     [overlay]   [backend]
                     |
             FilePageProvider
              (remote file)
```

### How It Works

1. **Startup (0 pages read):** `CowDatabase` creates a `FilePageProvider` pointing at the remote SQLite file. Nothing is read — not even the header. Cost: ~0.1ms.

2. **Schema discovery (1 page):** When `getTableNames()` is called, the B-tree reader reads page 1 (the `sqlite_master` root). This single 4KB page contains all table definitions for typical WordPress databases.

3. **Lazy reads (only needed pages):** `readTable()` and `findRow()` use the B-tree reader to navigate the file's B-tree structure. Only the pages along the access path are fetched. For `findRow()`, this is O(log N) pages — a binary search through the B-tree.

4. **Writes (table materialization on demand):** The first write to a table triggers materialization: the table's rows are read via the B-tree reader and inserted into a local in-memory SQLite database. Subsequent reads and writes to that table use local SQLite.

5. **Source protection:** The `FilePageProvider` only reads from the remote file. All writes go to the local `PDO(:memory:)` overlay. The remote file is never opened for writing.

### Components

- **`PageProvider`** — Interface for page-level access (1-based page numbers, matching SQLite convention)
- **`FilePageProvider`** — Reads pages from a file; tracks which pages were accessed (for verifying laziness)
- **`CowPageProvider`** — COW overlay: reads check local first, writes always local
- **`BTreeReader`** — Parses SQLite's on-disk B-tree format: navigates interior/leaf pages, decodes varints, reads record payloads, handles overflow pages
- **`CowDatabase`** — High-level API: lazy reads via B-tree, writes via local SQLite materialization

### What Makes This Different

| Aspect | Solution 1 (Query Proxy) | **Solution 2 (Page-Level)** | Solution 3 (Table Materialization) |
|--------|-------------------------|---------------------------|-----------------------------------|
| Unit of laziness | Row (via SQL) | **4KB page (binary)** | Entire table (via SQL) |
| Reads remote via | SQL queries | **Binary B-tree traversal** | SQL queries |
| Understands | SQL syntax | **SQLite file format** | SQL + MySQL DDL |
| First read cost | 1 SQL round-trip per query | **O(log N) page reads** | Full table download |

This is the only solution that understands SQLite's on-disk binary format. It doesn't parse SQL for reads — it walks B-tree pointers directly.

## WordPress Playground Integration

This solution targets the scenario where the source WordPress database has been converted to SQLite format (or the site already uses the `sqlite-database-integration` plugin):

1. **Remote backend**: In production, `FilePageProvider` would be replaced with an `HttpRangePageProvider` that fetches 4KB pages from a remote URL using HTTP Range requests (`Range: bytes=0-4095`). This works with any static file host (S3, CDN, etc.) and requires no server-side code.

2. **Integration with Playground**: WordPress Playground already uses SQLite. The COW database acts as the SQLite file for Playground's PHP runtime. Reads navigate the remote file's B-tree structure; writes go to local memory. The `sqlite-database-integration` plugin sees a normal SQLite database.

3. **Startup flow**: Playground loads, the COW layer is initialized (0 pages read, ~0.1ms), and the site is ready. Page 1 (sqlite_master) is fetched on the first query to discover table schemas. Subsequent reads fetch only needed pages.

4. **Ideal for**: Sites with large databases where reads are concentrated in a few tables/rows. Point lookups by rowid are O(log N) pages, making this highly efficient for `wp_options` and `wp_posts` lookups by ID.

5. **Conversion pipeline**: For MySQL source sites, a one-time server-side conversion to SQLite is needed. This can be done with `wp-cli` + `sqlite-database-integration` export, or a custom dump tool. The resulting SQLite file is hosted statically.

## Performance

Measured on test databases:

| Operation | Pages Read | Notes |
|-----------|-----------|-------|
| Startup | 0 | Only creates PHP objects |
| Schema discovery | 1 | Reads sqlite_master root page |
| Find row by ID (500-row table) | 2-3 | B-tree binary search |
| Full table scan (500 rows) | ~8 | All leaf pages + interior pages |
| Full table scan (2000 rows) | ~127 | Proportional to data size |

## Trade-offs

### Advantages
- True page-level lazy loading — reads exactly the pages needed, nothing more
- O(log N) page reads for point lookups via B-tree binary search
- Zero startup cost — no pages read until first query
- Source file guaranteed unmodified (read-only access)
- Multiple independent COW sessions from the same source
- No SQL parsing needed for reads — operates at binary level

### Limitations
- **Read-only B-tree**: The B-tree reader is read-only. Writes require materializing the table into local SQLite first. The first write to a large table incurs a one-time cost of reading all its pages. For a 1GB table, this means downloading 1GB on first write.
- **No index B-tree support**: The reader only traverses table B-trees (by rowid), not index B-trees. `findRow()` is efficient for rowid lookups, but `WHERE user_login = 'admin'` requires a full table scan. Adding index B-tree traversal would dramatically improve query performance but doubles the implementation complexity.
- **Materialization is all-or-nothing per table**: When a write triggers materialization, the entire table is fetched. There's no partial materialization or row-level COW for writes.
- **No WAL support**: The reader assumes rollback journal mode. WAL-mode databases (the SQLite default since 3.7.0) store recent changes in a separate `-wal` file that this reader doesn't handle. The source database must be checkpointed before use.
- **Torn reads possible**: If the remote file changes between page reads, the B-tree structure may become inconsistent. In production, the remote must be a static snapshot (e.g., an immutable S3 object).
- **SQLite-specific**: Only works with SQLite source databases. MySQL sources require server-side conversion first.
- **No column-level filtering before fetch**: The B-tree reader always returns full rows. `SELECT title FROM wp_posts` still reads all columns from the page data, just discards unwanted ones in PHP.
- **Memory**: Materialized tables live in `:memory:` SQLite. A 500MB table materialization requires ~500MB of PHP process memory.

## Running Tests

```bash
php tests/TestRunner.php
```

### Test Suites

- **CowPageProviderTest** (12 tests) — Read-through, write isolation, page tracking, source immutability
- **BTreeReaderTest** (26 tests) — sqlite_master parsing, table scans, column mapping, findRow, page collection, large tables (500 rows with interior pages), overflow payloads
- **IntegrationTest** (31 tests) — End-to-end: lazy reads, page efficiency, writes, source protection, independent sessions, materialization on demand
- **StartupTimeTest** (5 tests) — Startup < 50ms, zero pages at startup, lazy schema discovery

## Requirements

- PHP 8.1+ with PDO SQLite extension
- No external dependencies
