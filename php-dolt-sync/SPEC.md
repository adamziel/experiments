# Implement a content-addressed sync engine for WordPress on SQLite

You are building a system that versions a WordPress site's database state as a content-addressed DAG (directed acyclic graph) and syncs it between two WordPress+SQLite instances over HTTP. This is modeled on Dolt's storage architecture, not on SQL statement replay or row-by-row diffing.

The system must actually work between two real WordPress sites running the SQLite database integration plugin. "Work" means: Site A commits its state, pushes to Site B, Site B materializes the received state into its live WordPress database, and the content is visible on Site B. Then Site B makes changes, commits, pushes back to Site A, and Site A materializes. Round-trip sync with no data loss, no hash mismatches, no silent corruption.

## Architecture overview

The system has six layers. Build them in order. Do not skip ahead — each layer's correctness depends on the one below it.

### Layer 1: Canonical value encoding

Every PHP value that enters the system must serialize to exactly one byte sequence. The same logical value must always produce the same bytes, regardless of which site produced it, which PHP version ran the code, or what order operations happened in.

Requirements:

- Use CBOR (RFC 8949) as the wire format. Use a pure-PHP CBOR encoder — do not shell out. If no suitable library exists, implement the subset needed: unsigned integers, negative integers, byte strings, text strings, arrays, maps, floats (IEEE 754 double), null, booleans.
- Map keys must be sorted. CBOR's deterministic encoding profile (RFC 8949 §4.2) specifies length-first sorting of the raw key bytes. Follow this exactly.
- PHP arrays with sequential integer keys starting at 0 encode as CBOR arrays. All other PHP arrays encode as CBOR maps.
- Floats: normalize NaN to a single canonical NaN (0x7ff8000000000000). Normalize -0.0 to 0.0. Encode integers that happen to be stored as floats (e.g. 3.0) as integers.
- Strings: all text must be valid UTF-8. Apply NFC normalization (use the `Normalizer` class from the `intl` extension, or implement NFC for ASCII-safe content). Reject invalid UTF-8 rather than silently replacing bytes.
- NULL: encode as CBOR null (0xf6). Do not conflate with empty string, 0, or false.
- Integers: PHP integers are 64-bit signed. CBOR unsigned integers go up to 2^64-1. Encode non-negative PHP integers as CBOR unsigned, negative as CBOR negative.

Write a `CanonicalEncoder::encode(mixed $value): string` function and a corresponding `CanonicalEncoder::decode(string $bytes): mixed`. Round-tripping must be exact: `decode(encode($x)) === $x` for all supported types.

Write a `CanonicalEncoder::hash(mixed $value): string` that returns `hash('sha256', encode($value))`. This is the content address.

### Layer 2: Chunk store

A chunk is an immutable blob of bytes plus a list of hashes it references (its children in the DAG).

```php
final class Chunk {
    public function __construct(
        public readonly string $hash,   // hex-encoded SHA-256
        public readonly string $bytes,  // raw content
        public readonly array  $refs,   // list of child hashes (hex strings)
    ) {}
}
```

The chunk store interface:

```php
interface ChunkStore {
    /** @return array<string, true> hash => true for each present hash */
    public function hasMany(array $hashes): array;
    
    /** @return array<string, Chunk> hash => Chunk */
    public function getMany(array $hashes): array;
    
    /** Writes chunks. MUST verify hash matches content. */
    public function putMany(array $chunks): void;
}
```

Implement two backends:

1. `SQLiteChunkStore` — stores chunks in a SQLite database with schema:
   ```sql
   CREATE TABLE chunks (
       hash TEXT PRIMARY KEY,
       data BLOB NOT NULL,
       refs TEXT NOT NULL DEFAULT '[]'  -- JSON array of hex hashes
   );
   ```
   Use WAL mode. Open the database with `PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;`.

2. `HttpChunkStore` — a client that talks to a remote site's REST API. Implements the same `ChunkStore` interface but sends HTTP requests instead of local SQLite queries.

Correctness invariant: `putMany` must verify that `hash('sha256', $chunk->bytes) === $chunk->hash` for every chunk. Reject any chunk where this fails. This is the foundational integrity check — if you skip it, the entire system is compromised.

### Layer 3: Ref store

```php
interface RefStore {
    public function getRef(string $name): ?string;  // returns hash or null
    public function listRefs(string $prefix = ''): array;  // name => hash
    
    /**
     * Atomic compare-and-swap.
     * Sets $name to $newHash only if current value is $expectedOldHash.
     * $expectedOldHash = null means "ref must not exist".
     * Returns true on success, false if current value doesn't match expected.
     */
    public function casRef(string $name, ?string $expectedOldHash, string $newHash): bool;
}
```

Implement `SQLiteRefStore` with schema:
```sql
CREATE TABLE refs (
    name TEXT PRIMARY KEY,
    hash TEXT NOT NULL
);
```

The `casRef` operation must be atomic. Use a single UPDATE with a WHERE clause that checks the old value, then check `changes()` to determine success. For creation (expectedOldHash = null), use INSERT with conflict detection.

### Layer 4: Table tree (sorted chunked B-tree)

Each WordPress table is stored as a sorted map from primary key to encoded row. The map is stored as a B-tree where every node (internal and leaf) is a content-addressed chunk.

Leaf node structure (encoded as canonical CBOR):
```
{
  "type": "leaf",
  "entries": [
    [<key_bytes>, <value_bytes>],
    [<key_bytes>, <value_bytes>],
    ...
  ]
}
```

Internal node structure:
```
{
  "type": "internal",
  "children": [
    [<separator_key_bytes>, <child_chunk_hash>],
    ...
  ]
}
```

Entries in leaf nodes are sorted by key bytes (lexicographic). Separators in internal nodes are sorted the same way. The tree must produce deterministic structure for the same set of key-value pairs regardless of insertion order. This means: given the same entries, the tree must chunk them the same way and produce the same root hash.

Use a fixed fan-out (e.g., max 64 entries per leaf, max 64 children per internal node). Do NOT implement content-defined chunking (Prolly trees) in the first version — fixed pages are simpler and sufficient. Content-defined chunking is an optimization for later.

Build the tree bottom-up from a sorted list of entries. This is deterministic: sort all entries by key, chunk into leaf pages of at most N entries, build internal nodes from the leaf hashes, repeat until you have a single root.

Implement:
- `TableTree::build(array $sortedEntries, ChunkStore $store): string` — returns root hash
- `TableTree::get(string $rootHash, string $key, ChunkStore $store): ?string` — returns value or null
- `TableTree::iterate(string $rootHash, ChunkStore $store): Generator` — yields all entries in order
- `TableTree::diff(string $rootA, string $rootB, ChunkStore $store): Generator` — yields entries that differ between two trees. This MUST skip subtrees whose hashes match (this is the key performance property).

### Layer 5: Snapshot and materialization

**Snapshot** reads the live WordPress SQLite database and produces a commit.

Database root structure (CBOR-encoded):
```
{
  "tables": {
    "wp_posts": {
      "tree": "<root_hash>",
      "schema": "<sha256 of canonical CREATE TABLE statement>"
    },
    "wp_options": { ... },
    ...
  }
}
```

Commit structure (CBOR-encoded):
```
{
  "root": "<db_root_hash>",
  "parents": ["<parent_commit_hash>", ...],
  "author": "...",
  "message": "...",
  "timestamp": <unix_epoch_int>
}
```

For each tracked table:
1. Determine the primary key column(s) from `PRAGMA table_info(table_name)`.
2. Read all rows (or only dirty rows if dirty tracking is active — see below).
3. For each row, build the key from the PK column(s) and the value from all columns.
4. Normalize the row before encoding (see normalization rules below).
5. Build the table tree from sorted entries.
6. Record the table's tree root hash and schema hash in the database root.

**Dirty tracking via SQL triggers:**

On installation, create triggers on every tracked table:
```sql
CREATE TABLE IF NOT EXISTS _sync_dirty (
    tbl   TEXT    NOT NULL,
    rowid INTEGER NOT NULL,
    op    TEXT    NOT NULL,
    PRIMARY KEY (tbl, rowid)  -- last op wins
);

CREATE TRIGGER _sync_trk_{table}_i AFTER INSERT ON {table}
BEGIN
  INSERT OR REPLACE INTO _sync_dirty(tbl, rowid, op) VALUES('{table}', NEW.rowid, 'I');
END;

CREATE TRIGGER _sync_trk_{table}_u AFTER UPDATE ON {table}
BEGIN
  INSERT OR REPLACE INTO _sync_dirty(tbl, rowid, op) VALUES('{table}', NEW.rowid, 'U');
END;

CREATE TRIGGER _sync_trk_{table}_d AFTER DELETE ON {table}
BEGIN
  INSERT OR REPLACE INTO _sync_dirty(tbl, rowid, op) VALUES('{table}', OLD.rowid, 'D');
END;
```

Note: use `INSERT OR REPLACE` with a composite PK on (tbl, rowid) so that multiple writes to the same row before a commit collapse into one entry.

When dirty tracking is available, the snapshot only reads the dirty rows. For tables with no dirty rows, reuse the tree root hash from the previous commit. For tables with dirty rows, rebuild only the affected pages.

For the first commit (no prior state), do a full scan of all tables.

**Materialization** is the reverse: given a commit hash, read the database root, and for each table, diff the tree against the current live state and apply INSERT/UPDATE/DELETE statements to the live WordPress database.

Materialization must:
- Run inside a transaction (BEGIN / COMMIT).
- Disable triggers during materialization (otherwise the materialized writes would fill `_sync_dirty` with noise). Use `DROP TRIGGER` before and `CREATE TRIGGER` after, or use a flag table that the triggers check.
- Handle schema differences: if the remote commit has a different schema hash for a table, warn and skip that table (schema migration is out of scope for v1).

### Layer 6: Sync engine

```php
final class SyncEngine {
    public function copyReachable(
        ChunkStore $to,
        ChunkStore $from,
        string $rootHash,
        int $batchSize = 256
    ): int;  // returns count of chunks transferred
}
```

Walk the DAG from `rootHash`. For each batch of up to `$batchSize` hashes:
1. Ask `$to->hasMany($batch)` to find which are already present.
2. For missing hashes, call `$from->getMany($missing)` to get the chunks.
3. For each retrieved chunk, add its `$refs` (children) to the pending set.
4. Write chunks to `$to` in dependency order: a chunk's children must be present in `$to` before the chunk itself is written.

Correctness requirements:
- Must not re-fetch a hash that has already been seen in this walk (track seen hashes in a set).
- Must not exceed bounded memory. The `$seen` set grows with the number of unique hashes in the DAG. For very large DAGs this could be a problem — document this limit but don't solve it in v1.
- Must handle the case where `$from->getMany()` returns fewer chunks than requested (network errors, partial response). Retry or fail explicitly.
- Must detect cycles: if the pending set is not empty but no progress can be made (all pending hashes are either seen or present in $to), throw an error.

**Push flow:**
```
1. localRefHash = localRefs.getRef('refs/heads/main')
2. remoteRefHash = remoteRefs.getRef('refs/heads/main')
3. if localRefHash === remoteRefHash: nothing to do
4. verify fast-forward: walk local commit parents to confirm remoteRefHash is an ancestor
5. copyReachable(remoteChunkStore, localChunkStore, localRefHash)
6. remoteRefs.casRef('refs/heads/main', remoteRefHash, localRefHash)
7. if CAS fails: abort, tell user to pull first
```

**Fetch flow:**
```
1. remoteRefHash = remoteRefs.getRef('refs/heads/main')
2. localTrackingHash = localRefs.getRef('refs/remotes/origin/main')
3. copyReachable(localChunkStore, remoteChunkStore, remoteRefHash)
4. localRefs.casRef('refs/remotes/origin/main', localTrackingHash, remoteRefHash)
```

**Pull flow:** fetch + materialize.

### HTTP transport

The remote site exposes five endpoints via a WordPress REST API plugin (mu-plugin):

```
POST /wp-json/sync/v1/chunks/has
  Request:  { "hashes": ["abc123...", ...] }
  Response: { "present": { "abc123...": true } }

POST /wp-json/sync/v1/chunks/get
  Request:  { "hashes": ["abc123...", ...] }
  Response: { "chunks": [{ "hash": "...", "data": "<base64>", "refs": [...] }, ...] }

POST /wp-json/sync/v1/chunks/put
  Request:  { "chunks": [{ "hash": "...", "data": "<base64>", "refs": [...] }, ...] }
  Response: { "stored": 5 }

GET /wp-json/sync/v1/refs
  Response: { "refs/heads/main": "abc123..." }

PUT /wp-json/sync/v1/refs/{name}
  Request:  { "old": "abc123...", "new": "def456..." }
  Response: { "ok": true } or 409 on CAS failure
```

Authentication: use WordPress application passwords. The `HttpChunkStore` must send Basic auth on every request.

## Row normalization rules

Before encoding a row as canonical CBOR, normalize:

1. **Site URL**: replace all occurrences of the site's own URL (from `wp_options.siteurl`) with a placeholder token `{{SITE_URL}}`. On materialization, replace the token with the receiving site's URL. This applies to `post_content`, `post_excerpt`, `guid`, `option_value`, and any text column that might contain URLs.

2. **Serialized PHP**: WordPress stores serialized PHP arrays in `option_value`, `meta_value`, and other columns. These must be deserialized, normalized recursively (sort array keys, apply URL replacement, normalize nested values), and re-serialized in a deterministic way. PHP's `serialize()` is NOT deterministic for associative arrays — it preserves insertion order. You must sort string keys before serializing. Use a custom serializer or sort before `serialize()`.

   Critical: PHP serialized strings include byte-length prefixes (`s:5:"hello"`). If you change a URL inside a serialized string, the length prefix becomes wrong and WordPress will fail to deserialize it. You must recompute all length prefixes after URL replacement. This is the single most common source of WordPress migration bugs.

3. **Auto-increment IDs**: for v1, do NOT attempt to remap IDs across sites. Treat the primary key as part of the row identity. This means the two sites must start from the same base state (e.g., Site B is initialized by pulling from Site A). Document this limitation.

4. **Transient exclusion**: skip rows in `wp_options` where `option_name` starts with `_transient_` or `_site_transient_`. These are ephemeral cache entries.

5. **Timestamp normalization**: store `post_date_gmt`, `post_modified_gmt` and similar GMT columns as the canonical time representation. Drop the non-GMT variants from the encoded value (they're derived from GMT + timezone).

6. **Column ordering**: always encode columns in alphabetical order by column name. Do not rely on the order returned by `SELECT *`.

## What breaks in an incomplete implementation

These are the specific failure modes to test against. An implementation that doesn't handle all of them is not done.

### Encoding failures

1. **Non-deterministic serialized PHP.** Two sites serialize the same associative array with different key insertion orders → different bytes → different hashes → sync sees phantom changes on every commit and transfers the entire table every time. Test: create an option value `['b' => 1, 'a' => 2]` on Site A and `['a' => 2, 'b' => 1]` on Site B. After sync, the hashes must match.

2. **URL in serialized PHP with wrong length prefix.** Replace `https://site-a.test` (20 bytes) with `https://site-b.test` (20 bytes) and it works. Replace it with `https://b.test` (14 bytes) and the length prefix `s:20:` is now wrong. WordPress's `maybe_unserialize()` fails silently and returns the raw string. Test: create an option with a serialized value containing a URL of different length than the other site's URL. After sync + materialize, the option must be correctly deserialized.

3. **Nested serialized PHP.** WordPress sometimes stores serialized data inside serialized data (e.g., widget settings). The URL replacement and length-prefix fix must work recursively. Test: create a widget option with a nested serialized array containing a URL.

4. **Float precision.** `1.0/3.0` produces subtly different string representations across PHP versions and platforms. The canonical encoder must produce the same bytes for the same IEEE 754 double. Test: encode `1.0/3.0` on two different systems (or simulate by encoding the float bytes directly), verify identical CBOR output.

5. **UTF-8 normalization.** The string "café" can be encoded as 5 bytes (é as U+00E9) or 6 bytes (e + combining accent U+0301). Both must normalize to the same CBOR output. Test: insert a post with the combining-character version, commit, verify hash matches the precomposed version.

6. **NULL vs empty string.** `post_content_filtered` is often `''` on one site and `NULL` on another. The encoder must distinguish them. Test: create two rows identical except one has `NULL` and the other has `''` for one column. They must produce different hashes.

### Tree failures

7. **Empty table.** A table with zero rows must produce a valid tree root (a single empty leaf node). Two sites with the same empty table must produce the same root hash. Test: drop all rows from a table, commit, verify root hash.

8. **Tree determinism.** Build a table tree from entries inserted in order `[1, 2, 3, 4, 5]`, then build from `[5, 3, 1, 4, 2]`. The root hashes must be identical because both are sorted before chunking. Test: insert rows in different orders on two sites, commit both, verify root hashes match.

9. **Diff skips unchanged subtrees.** Create a tree with 10,000 rows. Change 1 row. Diff must visit O(log n) nodes, not O(n). Test: instrument the chunk store to count `getMany` calls during a diff, verify the count is proportional to log(n), not n.

10. **Table with no usable primary key.** Some plugin tables lack a PRIMARY KEY. Fall back to `rowid` as the key. Test: create a table without a PK, insert rows, commit, verify the tree is built using rowid.

### Sync failures

11. **Interrupted push.** Push transfers 50% of chunks, then the connection drops. The remote chunk store has orphan chunks (children without parents). On retry, push must resume — not re-transfer already-present chunks. The remote must not have updated its ref (ref update is the last step). Test: mock the HTTP transport to fail after N chunks, verify ref is unchanged, retry and verify only remaining chunks are transferred.

12. **Concurrent pushes.** Two sites push to the same remote simultaneously. One must succeed, the other must get a CAS failure and be told to pull first. Test: set remote ref to hash X. Site A pushes with expected=X. Simulate Site B pushing with expected=X after Site A already updated the ref. Site B's CAS must fail.

13. **Non-fast-forward push.** Site A and Site B both make commits on top of the same parent. Site A pushes first. Site B tries to push — this is not a fast-forward (Site A's commit is not an ancestor of Site B's commit). The push must be rejected. Test: create divergent commits from the same parent, attempt push, verify rejection.

14. **Duplicate chunks in DAG walk.** A commit references a database root, which references table trees, which may share chunks (e.g., if two tables have identical subtrees — unlikely but possible). The sync walker must not request the same chunk twice. Test: create a DAG where the same chunk hash appears in multiple branches, verify `getMany` is called with each hash at most once.

15. **Large batch handling.** A commit with 50,000 new chunks must work without exceeding memory limits. The batch size must be respected. Test: create a large dataset, push it, verify memory usage stays bounded (use `memory_get_peak_usage()`).

### Materialization failures

16. **Trigger suppression during materialize.** If triggers are active during materialization, every INSERT/UPDATE/DELETE will write to `_sync_dirty`, creating a massive dirty log of changes that were already applied. The next commit would then try to re-snapshot all those rows. Test: materialize 1000 rows, immediately check `_sync_dirty`, verify it's empty.

17. **Foreign key / constraint ordering.** WordPress doesn't use SQL foreign keys, but some plugins do. Materialization must handle the case where rows need to be inserted in a specific order. For v1: if a constraint violation occurs, retry the failed batch after all other rows are inserted. Test: create a plugin table with a FK, materialize rows that reference each other.

18. **Post with rewritten URLs.** A post on Site A has `<img src="https://site-a.test/wp-content/uploads/photo.jpg">`. After sync to Site B, the URL must read `https://site-b.test/wp-content/uploads/photo.jpg`. Test: create a post with images, sync, verify the URLs in `post_content` on Site B.

19. **wp_options siteurl/home.** These two options define the site's identity. They must NOT be synced — each site has its own. The snapshot must exclude them (or the materializer must skip them). Test: sync from Site A to Site B, verify Site B's `siteurl` and `home` options are unchanged.

20. **Deleted rows.** Site A deletes a post. The commit reflects the deletion (the row is absent from the tree). After sync, Site B must also delete that row. Test: create a post, sync, delete the post on Site A, commit, sync again, verify the post is gone from Site B.

### Round-trip failures

21. **Full round trip.** Site A → commit → push to Site B → Site B materializes → Site B adds content → commit → push to Site A → Site A materializes. All content from both sites must be present. This is the integration test that proves the system works. Test: automate this full cycle.

22. **Idempotent sync.** Push the same commit twice. The second push should transfer zero chunks and the CAS should be a no-op (old === new). Test: push, then push again, verify zero chunks transferred.

23. **Empty commit.** Commit when nothing has changed since the last commit. The new commit should have the same db root hash as the parent (but a different commit hash because the timestamp differs). Test: commit twice with no changes in between, verify the db root hash is reused.

## Testing strategy

### Unit tests

Test each layer in isolation:

- **CanonicalEncoder**: property-based tests. Generate random PHP values (nested arrays, strings with special characters, edge-case floats, nulls, booleans, large integers), encode, decode, verify round-trip. Test that encoding is deterministic: encode the same value 1000 times, all outputs must be byte-identical. Test cross-platform: if possible, compare output against a reference CBOR implementation in another language.

- **ChunkStore (SQLiteChunkStore)**: put a chunk, get it back, verify hash. Put the same chunk twice, verify idempotent. Put a chunk with wrong hash, verify rejection. hasMany with mix of present and absent hashes. getMany with mix of present and absent hashes (absent ones should be silently omitted from results).

- **RefStore**: getRef on nonexistent ref returns null. setRef + getRef round trip. casRef succeeds when expected matches. casRef fails when expected doesn't match. casRef with null expected creates new ref. casRef with null expected fails if ref already exists.

- **TableTree**: build from empty entries. Build from 1 entry. Build from exactly N entries (one full leaf). Build from N+1 entries (forces a split). Build from 10,000 entries. Verify determinism: same entries → same root hash regardless of input order. Diff two identical trees → zero differences. Diff two trees with one changed entry → yields exactly that entry. Diff with a deleted entry. Diff with an added entry.

- **SyncEngine**: mock ChunkStore implementations. Build a small DAG (5-10 chunks), copyReachable to an empty store, verify all chunks present. Build a DAG, pre-populate the target with some chunks, verify only missing ones are transferred. Test cycle detection. Test interrupted transfer (mock failure after N chunks).

### Integration tests

Use two separate SQLite databases as two "sites":

- **Snapshot round-trip**: create a WordPress-like schema (wp_posts, wp_options, wp_postmeta), insert test data, snapshot to chunk store, materialize to a fresh database, verify row-by-row equality.

- **Sync between two stores**: Site A snapshot → push to Site B's chunk store → Site B materialize. Verify data matches.

- **Incremental sync**: Site A commits, pushes. Site A changes 1 row, commits again, pushes. Verify only the changed chunks are transferred (instrument the mock transport to count).

- **Dirty tracking**: insert a row, verify `_sync_dirty` has the correct entry. Update a row, verify. Delete a row, verify. Commit, verify `_sync_dirty` is cleared.

### End-to-end tests

Requires two actual WordPress+SQLite installations. Use wp-env, wp-now, or Docker with the SQLite integration plugin.

- **Full push/pull cycle**: use WP-CLI to create posts, pages, options on Site A. Run `wp sync commit`. Run `wp sync push`. On Site B, verify the content appears. Create new content on Site B. Commit, push back to Site A. Verify.

- **Conflict detection**: create divergent changes on both sites. Attempt push from one to the other. Verify the non-fast-forward is detected and rejected.

### Existing test suites and references to reuse

1. **Dolt's Go test suite** (github.com/dolthub/dolt): study `go/store/nbs/` for chunk store tests, `go/store/prolly/` for tree tests, `go/libraries/doltcore/merge/` for merge tests. You cannot run Go tests directly, but the test PATTERNS (what scenarios they cover) are directly applicable. Port the scenario descriptions, not the code.

2. **CBOR test vectors** (RFC 8949 Appendix A): the RFC includes a comprehensive set of diagnostic notation → encoded bytes pairs. Use these to verify your encoder. Also use the CBOR test vectors from cbor.me and the cbor-diag tools.

3. **PHP serialization edge cases**: the PHP test suite (`php-src/ext/standard/tests/serialize/`) contains test cases for serialize/unserialize edge cases — objects, references, recursive structures, floats, large strings. Use these to identify edge cases your normalizer must handle.

4. **WordPress PHPUnit test infrastructure**: WordPress core ships a PHPUnit test framework (`wordpress-develop/tests/phpunit/`) with a factory system for creating posts, options, users, etc. Use `WP_UnitTestCase` as the base class for integration tests that need a running WordPress environment.

5. **WP-CLI testing framework**: WP-CLI uses Behat for functional tests. Each command has feature files that describe expected input/output. Write `.feature` files for `wp sync commit`, `wp sync push`, `wp sync pull`, `wp sync status`, `wp sync log`, `wp sync diff`.

6. **SQLite's testing methodology** (not reusable directly, but study the approach): SQLite achieves 100% MC/DC branch coverage with billions of test cases. The relevant principle: test boundary conditions (empty inputs, single-element inputs, exactly-full pages, one-over-full pages), error paths (disk full, corrupt data, interrupted writes), and determinism (same inputs → same outputs across platforms).

7. **Search/replace serialized PHP tests**: the `wp search-replace` WP-CLI command handles serialized PHP string length rewriting. Its test suite (`wp-cli/search-replace-command/features/`) covers nested serialization, edge cases with special characters in serialized strings, and length-prefix rewriting. These test scenarios are directly relevant to your normalizer.

## Definition of done

The implementation is complete when:

1. All unit tests pass with zero warnings.
2. All integration tests pass: snapshot → push → materialize → verify on a second SQLite database.
3. The end-to-end round trip works: Site A → push → Site B → modify → push → Site A, with content integrity verified at each step.
4. The incremental sync test proves that changing 1 row in a 10,000-row table transfers O(log n) chunks, not O(n).
5. All 23 failure scenarios listed in "What breaks" are covered by tests that explicitly assert the correct behavior.
6. The `CanonicalEncoder` passes all applicable CBOR test vectors from RFC 8949.
7. The serialized PHP normalizer correctly handles nested serialization with URL replacement and length-prefix rewriting, verified by round-trip tests with at least 5 different URL length combinations.
8. Concurrent push rejection works (CAS failure returns a clear error).
9. Interrupted push resumes correctly (no duplicate chunks, no orphan refs).
10. Memory usage during sync of 50,000 chunks stays below 256MB.
11. The code has no dependency on MySQL. Everything runs on PHP's built-in SQLite3 extension.
12. The HTTP transport works over real HTTP between two WordPress sites (not just mocked).

## File structure

```
src/
  Encoding/
    CanonicalEncoder.php
    CborEncoder.php
    CborDecoder.php
  Store/
    Chunk.php
    ChunkStore.php
    SQLiteChunkStore.php
    HttpChunkStore.php
    RefStore.php
    SQLiteRefStore.php
  Tree/
    TableTree.php
    LeafNode.php
    InternalNode.php
    TreeDiff.php
  Snapshot/
    Snapshotter.php
    Materializer.php
    RowNormalizer.php
    SchemaInspector.php
    DirtyTracker.php
    SerializedPhpHandler.php
  Sync/
    SyncEngine.php
    PushCommand.php
    FetchCommand.php
  WordPress/
    SyncPlugin.php          (mu-plugin: REST endpoints + trigger management)
    WpCliCommands.php       (WP-CLI integration)
tests/
  Unit/
    CanonicalEncoderTest.php
    CborTestVectorsTest.php
    SQLiteChunkStoreTest.php
    SQLiteRefStoreTest.php
    TableTreeTest.php
    TreeDiffTest.php
    SyncEngineTest.php
    RowNormalizerTest.php
    SerializedPhpHandlerTest.php
  Integration/
    SnapshotRoundTripTest.php
    SyncBetweenStoresTest.php
    IncrementalSyncTest.php
    DirtyTrackingTest.php
  EndToEnd/
    FullPushPullCycleTest.php
    ConflictDetectionTest.php
    features/
      sync-commit.feature
      sync-push.feature
      sync-pull.feature
```

## Constraints

- PHP 8.2+ with the `sqlite3` and `intl` extensions.
- No external services. No MySQL. No Redis. No message queues.
- No Composer dependencies for the core engine. You may use PHPUnit and WP-CLI testing framework for tests.
- The chunk store SQLite database must be a separate file from the WordPress SQLite database.
- All I/O must go through the defined interfaces (ChunkStore, RefStore). No direct file manipulation outside of the SQLite backends.
