<?php
/* : <<'__END__' */
/**
 * branchctl — manage branchfs branches from the command line.
 *
 * Usage:
 *   branchctl list
 *   branchctl create {name} [--from {parent}]
 *   branchctl commit {name} [-m "message"]
 *   branchctl delete {name}
 *   branchctl show {name}
 *
 * Environment — all optional; defaults match e2e/dev.sh defaults:
 *   BRANCHFS_DB      path to the branchfs SQLite file    [/tmp/branchfs-dev/branchfs.db]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "branchctl: branchfs extension not loaded. Run with `php -d extension=/app/ext/branchfs.so ...` or use bin/branchctl.\n");
    exit(2);
}

function env_or(string $name, string $default): string {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

$DB_PATH = env_or('BRANCHFS_DB', '/tmp/branchfs-dev/branchfs.db');

function die_usage(?string $msg = null, int $code = 1): void {
    if ($msg !== null) fwrite(STDERR, "branchctl: $msg\n\n");
    fwrite(STDERR, <<<USAGE
Usage:
  branchctl list
  branchctl show    <name>
  branchctl create  <name>  [--from <parent>]
  branchctl delete  <name>

  branchctl commit  <name>  [-m "message"]
  branchctl log     <name>  [-n <count>]
  branchctl diff    <a> <b>
  branchctl merge   <from>  --into <target>
  branchctl reset   <name>  <commit-hash>  [--force]
  branchctl rollback <name>  [--force]
  branchctl gc              [--dry-run]

Flags:
  --db <path>   override BRANCHFS_DB (default: /tmp/branchfs-dev/branchfs.db)

Workflow:
  create   forks the branchfs overlay and records an initial snapshot.
  commit   snapshots the branch's current file tree into fs_commits.
  log      shows commit history for <name>.
  diff     per-file add/del/mod counts between two branch overlays.
  merge    runs scripts/merge.php: 3-way file merge only (DB merge not implemented).
  reset    hard-resets <name>'s file overlay to a specific commit hash.
           Refuses if uncommitted file-side changes exist unless --force.
  rollback shortcut: resets to the previous commit on <name>.
  delete   drops the overlay and removes the branch from SQLite.
  gc       deletes blobs not referenced by any live file or fs_commit.
           --dry-run prints what would be freed without deleting.

USAGE);
    exit($code);
}

function parse_args(array $argv): array {
    $pos = [];
    $flags = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $name = substr($a, 2);
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '-')) {
                $flags[$name] = $next;
                $i++;
            } else {
                $flags[$name] = true;
            }
        } elseif ($a === '-m') {
            $flags['message'] = $argv[++$i] ?? '';
        } elseif ($a === '-n') {
            $flags['n'] = $argv[++$i] ?? '';
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $flags];
}

[$pos, $flags] = parse_args($argv);
$cmd = $pos[0] ?? null;
if ($cmd === null || $cmd === 'help' || $cmd === '-h' || $cmd === '--help') die_usage(null, 0);

if (isset($flags['db'])) $DB_PATH = (string)$flags['db'];

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "branchctl: branchfs DB not found: $DB_PATH\n");
    fwrite(STDERR, "           Run `bash e2e/dev.sh` first, or set BRANCHFS_DB.\n");
    exit(2);
}

function sqlite_open(string $path): SQLite3 {
    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
    // 15s busy timeout on top of the retry helper: gives a loser writer
    // a long polling window before the helper escalates to backoff+retry.
    $db->busyTimeout(15000);
    // Keep WAL bounded under branchctl write bursts (create/merge/reset
    // all perform many writes in sequence).
    $db->exec('PRAGMA wal_autocheckpoint = 500');
    fs_migrate($db);
    return $db;
}

function branchfs_list(SQLite3 $db): array {
    $rows = [];
    $r = $db->query(
        "SELECT id, name, parent_branch, created_at "
      . "FROM branches ORDER BY name"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function branchfs_file_count(SQLite3 $db, int $branch_id): int {
    $s = $db->prepare("SELECT COUNT(*) FROM files WHERE branch_id = :bid");
    $s->bindValue(':bid', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

function valid_branch_name(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $name);
}

/**
 * Names reserved for HTTP routing / well-known subdomains. Creating a
 * branch with any of these would collide with the router's host parsing
 * (www.wp.localhost, admin.wp.localhost, etc.). Finding #11.
 */
function reserved_branch_names(): array {
    return ['www', 'admin', 'api', 'mail', 'localhost', 'wp'];
}

function is_reserved_branch_name(string $name): bool {
    return in_array(strtolower($name), reserved_branch_names(), true);
}

/* ================================================================
 * File-side commit graph (fs_commits + fs_commit_files)
 *
 * Each branchctl commit records a full snapshot of the branch's resolved
 * file tree. reset/rollback look up the matching fs_commit and materialize
 * it back into the branch overlay. Blobs are content-addressed, so the
 * only duplication per commit is the row-per-path in fs_commit_files.
 * ================================================================ */

function fs_migrate(SQLite3 $db): void {
    /* Idempotent; runs on every open so existing stores get the new tables
     * without a separate migration step. */
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    commit_hash TEXT NOT NULL DEFAULT (lower(hex(randomblob(16)))),
    parent_id   INTEGER,
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    UNIQUE (branch_id, commit_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_hash ON fs_commits(branch_id, commit_hash);
CREATE TABLE IF NOT EXISTS fs_commit_files (
    commit_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,
    mode        INTEGER,
    mtime       INTEGER,
    is_dir      INTEGER DEFAULT 0,
    PRIMARY KEY (commit_id, path),
    FOREIGN KEY (commit_id) REFERENCES fs_commits(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);
CREATE TABLE IF NOT EXISTS db_snapshots (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_pk     TEXT NOT NULL,
    row_json   TEXT NOT NULL,
    PRIMARY KEY (branch_id, table_name, row_pk)
);
/* COW (copy-on-write) branch fork markers. One row per (branch, table)
 * recorded at branch-create time. The marker captures the parent's
 * physical table name + a fork token (we use parent rowid max as a
 * cheap watermark). The presence of a row here also flags the branch
 * as "COW format" — branches without a row predate this feature and
 * are migrated lazily on first merge. */
CREATE TABLE IF NOT EXISTS db_cow_branches (
    branch_id          INTEGER NOT NULL,
    table_suffix       TEXT NOT NULL,             -- e.g. 'posts', 'options'
    parent_branch_id   INTEGER NOT NULL,
    parent_table_name  TEXT NOT NULL,             -- e.g. 'b1_wp_posts'
    fork_token         TEXT NOT NULL DEFAULT '',  -- opaque marker, opaque to merge
    created_at         TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (branch_id, table_suffix)
);
/* Lazy fork-time ancestor capture for COW branches.
 *
 * On parent-side UPDATE/DELETE, an AFTER-row trigger pushes the OLD row
 * into this table for every descendant branch that has a COW marker for
 * the parent's table. The push is INSERT OR IGNORE so only the FIRST
 * pre-divergence value is preserved — that's the true fork-time ancestor
 * the branch saw via its inheriting view.
 *
 * Storage cost is O(parent UPDATE/DELETE events × descendant branches),
 * not O(total rows). For workflows where parents are mostly read-only
 * and branches are short-lived (the typical preview/PR pattern), this
 * stays small.
 *
 * row_pk_json is JSON-encoded ordered PK column map.
 * row_json is the full pre-change row (for UPDATE: OLD values; for
 * DELETE: the deleted row).
 */
CREATE TABLE IF NOT EXISTS db_ancestor_overlay (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,             -- the BRANCH's logical name, e.g. b2_wp_posts
    row_pk     TEXT NOT NULL,             -- JSON-encoded PK
    row_json   TEXT NOT NULL,             -- full row JSON
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (branch_id, table_name, row_pk)
);
/* Companion to db_ancestor_overlay: PKs the parent INSERTED after the
 * fork. Lets the merge correctly recognize "both inserted same PK
 * independently" as a conflict (ancestor absent), instead of "source
 * adopted target's row" (false ancestor lookup). */
CREATE TABLE IF NOT EXISTS db_post_fork_inserts (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,             -- the BRANCH's logical name
    row_pk     TEXT NOT NULL,             -- JSON-encoded PK
    PRIMARY KEY (branch_id, table_name, row_pk)
);
CREATE TABLE IF NOT EXISTS db_snapshots_schema (
    branch_id    INTEGER NOT NULL,
    table_name   TEXT NOT NULL,
    ddl_sql      TEXT NOT NULL,                  -- the CREATE TABLE statement at fork time
    indexes_json TEXT NOT NULL DEFAULT '[]',     -- JSON array of CREATE INDEX statements
    PRIMARY KEY (branch_id, table_name)
);
CREATE TABLE IF NOT EXISTS users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    mysql_sha1    TEXT,
    role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
    created_at    TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS site_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS blob_chunks (
    blob_hash TEXT NOT NULL,
    chunk_no  INTEGER NOT NULL,
    data      BLOB NOT NULL,
    PRIMARY KEY (blob_hash, chunk_no),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_blob_chunks_hash ON blob_chunks(blob_hash);
SQL);

    /* Legacy .fp files created before chunked storage landed may still have
     * `blobs.data BLOB NOT NULL`. Relax that to allow NULL so future large
     * writes can store payload in blob_chunks. SQLite cannot drop NOT NULL
     * in-place, so we rebuild the table only if the constraint is present. */
    $blobs_sql = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='blobs'"
    );
    if ($blobs_sql && stripos($blobs_sql, 'data        BLOB NOT NULL') !== false) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec("CREATE TABLE blobs_new (hash TEXT PRIMARY KEY, data BLOB, size INTEGER NOT NULL)");
            $db->exec("INSERT INTO blobs_new (hash, data, size) SELECT hash, data, size FROM blobs");
            $db->exec("DROP TABLE blobs");
            $db->exec("ALTER TABLE blobs_new RENAME TO blobs");
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            /* Non-fatal: legacy-NOT-NULL schema still works for small blobs. */
        }
    }

    /* Back-compat: sites created BEFORE this change never had site_config,
     * so the very first migration on them must leave auth_enabled=0.
     * Brand-new sites go through init_db.php which seeds auth_enabled=1
     * before fs_migrate ever runs. */
    $has_any = (int)$db->querySingle("SELECT COUNT(*) FROM site_config");
    if ($has_any === 0) {
        $db->exec("INSERT OR IGNORE INTO site_config (key, value) VALUES ('auth_enabled', '0')");
    }
}

function fs_branch_id(SQLite3 $db, string $name): int {
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

/* Walk the branch chain and return the resolved effective tree.
 * Returns [path => ['blob_hash','mode','mtime','is_dir']]. Tombstones
 * (blob_hash=NULL AND is_dir=0) are excluded from the result. */
function fs_resolve_tree(SQLite3 $db, int $branch_id): array {
    $tree = [];
    $tombstoned = [];
    $bid = $branch_id;
    while ($bid > 0) {
        $r = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = $bid");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $p = $row['path'];
            if (isset($tree[$p]) || isset($tombstoned[$p])) continue;
            if (!$row['blob_hash'] && !$row['is_dir']) {
                $tombstoned[$p] = true;
                continue;
            }
            $tree[$p] = $row;
        }
        $pq = $db->prepare("SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name WHERE b1.id = :b");
        $pq->bindValue(':b', $bid, SQLITE3_INTEGER);
        $pr = $pq->execute();
        $prow = $pr->fetchArray(SQLITE3_NUM);
        $bid = $prow ? (int)$prow[0] : 0;
    }
    return $tree;
}

// Shared COW (copy-on-write) DB branch helpers. merge.php require_once's
// the same file so it can drop/recreate parent triggers around table
// rebuilds without duplicating the trigger-DDL generation logic.
require_once __DIR__ . '/cow_helpers.php';

/* Deterministic hash of a resolved tree, used to detect uncommitted
 * changes against the last fs_commit. Fast enough for O(3000 files). */
function fs_tree_digest(array $tree): string {
    ksort($tree);
    $h = hash_init('sha256');
    foreach ($tree as $p => $e) {
        hash_update($h, $p . "\0" . ($e['blob_hash'] ?? '') . "\0" . (int)$e['is_dir'] . "\n");
    }
    return hash_final($h);
}

function fs_last_commit(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = :b ORDER BY id DESC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/* Without Dolt, the "current" commit is simply the most recent one. */
function fs_current_commit(SQLite3 $db, int $branch_id): ?array {
    return fs_last_commit($db, $branch_id);
}

function fs_find_commit_by_hash(SQLite3 $db, int $branch_id, string $hash): ?array {
    $s = $db->prepare("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = :b AND commit_hash = :h LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $hash, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * Delete orphaned blobs from the `blobs` store.
 *
 * Two modes:
 *   - $candidate_hashes empty  → full GC: scan the whole blobs table, delete
 *                                 any hash not referenced by files or
 *                                 fs_commit_files.
 *   - $candidate_hashes given  → narrow GC: only those hashes are checked.
 *                                 Used by the inline-GC path after a branch
 *                                 delete, so cost scales with the deleted
 *                                 branch's blob set, not the whole store.
 *
 * Chunked blobs (TODO2 #2) store their payload in `blob_chunks`; this helper
 * removes chunk rows in the same transaction as the blobs row so a half-GC'd
 * state is never observable.
 *
 * Returns [deleted_count, bytes_reclaimed].
 */
function fs_gc(SQLite3 $db, array $candidate_hashes = []): array {
    if (empty($candidate_hashes)) {
        /* Full sweep: collect every orphan by anti-joining the reference tables. */
        $to_delete = [];
        $bytes_free = 0;
        $r = $db->query(
            "SELECT hash, size FROM blobs "
          . "WHERE hash NOT IN (SELECT blob_hash FROM files WHERE blob_hash IS NOT NULL) "
          . "  AND hash NOT IN (SELECT blob_hash FROM fs_commit_files WHERE blob_hash IS NOT NULL)"
        );
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $to_delete[] = $row['hash'];
            $bytes_free += (int)$row['size'];
        }
    } else {
        /* Narrow sweep: per-hash existence check against both reference tables.
         * Prepared statements reused across the candidate list. */
        $candidate_hashes = array_values(array_unique(array_filter(
            $candidate_hashes,
            fn($h) => is_string($h) && $h !== ''
        )));
        $chk_files = $db->prepare(
            "SELECT 1 FROM files WHERE blob_hash = :h LIMIT 1"
        );
        $chk_commits = $db->prepare(
            "SELECT 1 FROM fs_commit_files WHERE blob_hash = :h LIMIT 1"
        );
        $size_q = $db->prepare("SELECT size FROM blobs WHERE hash = :h LIMIT 1");

        $to_delete = [];
        $bytes_free = 0;
        foreach ($candidate_hashes as $h) {
            $chk_files->bindValue(':h', $h, SQLITE3_TEXT);
            $r1 = $chk_files->execute();
            $has_file = (bool)$r1->fetchArray(SQLITE3_NUM);
            $r1->finalize();
            $chk_files->reset();
            if ($has_file) continue;

            $chk_commits->bindValue(':h', $h, SQLITE3_TEXT);
            $r2 = $chk_commits->execute();
            $has_commit = (bool)$r2->fetchArray(SQLITE3_NUM);
            $r2->finalize();
            $chk_commits->reset();
            if ($has_commit) continue;

            $size_q->bindValue(':h', $h, SQLITE3_TEXT);
            $sr = $size_q->execute();
            $srow = $sr->fetchArray(SQLITE3_NUM);
            $sr->finalize();
            $size_q->reset();
            if (!$srow) continue; // blob row already gone; nothing to reclaim

            $to_delete[] = $h;
            $bytes_free += (int)$srow[0];
        }
    }

    if (empty($to_delete)) {
        return [0, 0];
    }

    /* One transaction covers both tables so a mid-GC crash either leaves the
     * blob reachable (no change) or removes both chunk rows and metadata. */
    $in_outer_tx = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
    } catch (\Throwable $e) {
        // If we're already inside a caller's transaction (inline-GC path),
        // reuse it rather than nesting — SQLite does not support nested tx.
        $in_outer_tx = true;
    }
    try {
        $del_chunks = $db->prepare("DELETE FROM blob_chunks WHERE blob_hash = :h");
        $del_blob   = $db->prepare("DELETE FROM blobs WHERE hash = :h");
        foreach ($to_delete as $h) {
            $del_chunks->bindValue(':h', $h, SQLITE3_TEXT);
            $del_chunks->execute();
            $del_chunks->reset();
            $del_blob->bindValue(':h', $h, SQLITE3_TEXT);
            $del_blob->execute();
            $del_blob->reset();
        }
        if (!$in_outer_tx) $db->exec('COMMIT');
    } catch (\Throwable $e) {
        if (!$in_outer_tx) $db->exec('ROLLBACK');
        throw $e;
    }

    return [count($to_delete), $bytes_free];
}

function fs_digest_of_commit(SQLite3 $db, int $commit_id): string {
    $tree = [];
    $r = $db->query("SELECT path, blob_hash, is_dir FROM fs_commit_files WHERE commit_id = $commit_id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $tree[$row['path']] = $row;
    }
    return fs_tree_digest($tree);
}

function fs_record_snapshot(SQLite3 $db, int $branch_id, string $message): int {
    $commit_hash = bin2hex(random_bytes(16));
    $tree = fs_resolve_tree($db, $branch_id);
    $parent = fs_last_commit($db, $branch_id);
    $parent_id = $parent['id'] ?? null;

    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = $db->prepare("INSERT INTO fs_commits (branch_id, commit_hash, parent_id, message) VALUES (:b, :h, :p, :m)");
        $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $s->bindValue(':h', $commit_hash, SQLITE3_TEXT);
        $parent_id === null ? $s->bindValue(':p', null, SQLITE3_NULL) : $s->bindValue(':p', $parent_id, SQLITE3_INTEGER);
        $s->bindValue(':m', $message, SQLITE3_TEXT);
        $s->execute();
        $cid = (int)$db->lastInsertRowID();

        $ins = $db->prepare(
            "INSERT INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:c, :p, :bh, :md, :mt, :d)"
        );
        foreach ($tree as $path => $e) {
            $ins->bindValue(':c',  $cid, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $path, SQLITE3_TEXT);
            $ins->bindValue(':bh', $e['blob_hash'] ?? null,
                $e['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($e['mode'] ?? 0),  SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($e['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($e['is_dir'] ?? 0), SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
        }
        $db->exec('COMMIT');
        return $cid;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/* Materialize a commit's file tree back into the branch's overlay.
 * We replace the branch's explicit `files` rows entirely and write each
 * snapshot entry as an explicit row — so the result doesn't depend on
 * parent-branch inheritance. */
function fs_restore_snapshot(SQLite3 $db, int $branch_id, int $commit_id): int {
    require_once __DIR__ . '/opcache.php';

    // Collect current .php paths on this branch so we can invalidate OPcache
    // entries for any that are about to change. Easier to be broad than to
    // diff precisely: invalidating a path whose bytecode we still have is
    // cheap, whereas missing one serves stale code.
    $branch_name = (string)$db->querySingle(
        "SELECT name FROM branches WHERE id = " . (int)$branch_id
    );

    $pre_paths = [];
    $r = $db->query("SELECT path FROM files WHERE branch_id = $branch_id");
    while ($row = $r->fetchArray(SQLITE3_NUM)) {
        $pre_paths[$row[0]] = true;
    }
    $r->finalize();

    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DELETE FROM files WHERE branch_id = $branch_id");
        $ins = $db->prepare(
            "INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :bh, :md, :mt, :d)"
        );
        $count = 0;
        $post_paths = [];
        $r = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM fs_commit_files WHERE commit_id = $commit_id");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $ins->bindValue(':b',  $branch_id, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $row['path'], SQLITE3_TEXT);
            $ins->bindValue(':bh', $row['blob_hash'] ?? null,
                $row['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($row['mode'] ?? 0),  SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($row['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($row['is_dir'] ?? 0), SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
            if (empty($row['is_dir'])) $post_paths[$row['path']] = true;
            $count++;
        }

        // Invalidate OPcache for every .php path in the symmetric difference
        // between pre-reset and post-reset overlays — i.e. any path that
        // was added, removed, or whose content may have changed.
        foreach ($pre_paths  as $p => $_) opcache_queue_invalidate($db, $branch_name, $p);
        foreach ($post_paths as $p => $_) opcache_queue_invalidate($db, $branch_name, $p);

        $db->exec('COMMIT');
        return $count;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

switch ($cmd) {

case 'list': {
    branchfs_set_db($DB_PATH);
    $db = sqlite_open($DB_PATH);
    $branches = branchfs_list($db);

    printf("%-20s  %-20s  %-8s  %s\n", "BRANCH", "PARENT", "FILES", "CREATED");
    printf("%s\n", str_repeat('-', 70));
    foreach ($branches as $b) {
        $files = branchfs_file_count($db, (int)$b['id']);
        printf("%-20s  %-20s  %-8d  %s\n",
            $b['name'],
            $b['parent_branch'] ?? '(root)',
            $files,
            $b['created_at'] ?? ''
        );
    }
    break;
}

case 'create': {
    $name = $pos[1] ?? die_usage("`create` needs a branch name");
    $from = (string)($flags['from'] ?? 'main');
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    if ($name === 'main') die_usage("'main' is reserved");
    if (is_reserved_branch_name($name)) {
        die_usage("'$name' is reserved (collides with HTTP routing); reserved names are: "
            . implode(', ', reserved_branch_names()));
    }

    branchfs_set_db($DB_PATH);
    branchfs_create_branch($name, $from);
    echo "branchfs: forked '$from' -> '$name'\n";

    /* Record an initial snapshot so reset has a landing point even before
     * the user makes any commits. */
    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid > 0) {
        fs_record_snapshot($db, $bid, "branchctl create from '$from'");
        echo "branchfs: initial snapshot recorded\n";
    }

    // ── COW (copy-on-write) DB branch creation ──────────────────────────
    //
    // Old behavior copied every parent row into b{new}_wp_* tables and
    // snapshotted them into db_snapshots — O(N rows) in time and storage.
    // New behavior creates a view + overlay + tombstones trio per table,
    // O(num_tables) and a few KB regardless of row count.
    //
    // The branch's view UNION-ALLs its overlay with the parent's view minus
    // tombstones. Reads transparently inherit; writes get caught by INSTEAD OF
    // triggers that route to overlay/tombstones.
    $parent_id = fs_branch_id($db, $from);
    $new_id    = fs_branch_id($db, $name);
    if ($parent_id > 0 && $new_id > 0) {
        $prefix_parent = "b{$parent_id}_wp_";

        // Collect parent table/view suffixes — anything matching b{parent}_wp_*
        // that ISN'T an internal __overlay / __tombstones artifact. Both real
        // tables (parent is main) and views (parent is itself a branch) qualify.
        $tables_stmt = $db->prepare(
            "SELECT name, type FROM sqlite_master "
          . "WHERE name LIKE :p "
          . "  AND type IN ('table', 'view') "
          . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
          . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
        );
        $tables_stmt->bindValue(':p', $prefix_parent . '%', SQLITE3_TEXT);
        $tables_result = $tables_stmt->execute();
        $suffixes_to_cow = [];
        while ($row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
            $suffixes_to_cow[] = substr($row['name'], strlen($prefix_parent));
        }
        $tables_result->finalize();
        $tables_stmt->close();

        $created = 0;
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($suffixes_to_cow as $suffix) {
                cow_create_branch_table($db, $new_id, $parent_id, $suffix);
                $created++;
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            echo "warning: COW branch table creation failed: " . $e->getMessage() . "\n";
        }
        if ($created > 0) {
            echo "branchfs: COW-forked $created tables from '$from' (no row copy)\n";
        }
    }

    echo "\n";
    $root_host = getenv('BRANCHFS_ROOT_HOST') ?: 'localhost';
    $port = getenv('PORT') ?: '80';
    echo "Visit http://$name.$root_host:$port/ to see this branch.\n";
    break;
}

case 'commit': {
    $name = $pos[1] ?? die_usage("`commit` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    $msg = (string)($flags['message'] ?? ("branchctl commit on " . date('c')));

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    $current_snap   = fs_last_commit($db, $bid);
    $current_digest = fs_tree_digest(fs_resolve_tree($db, $bid));
    $anchor_digest  = $current_snap ? fs_digest_of_commit($db, (int)$current_snap['id']) : '';

    if ($current_snap && $current_digest === $anchor_digest) {
        echo "branchfs: no file-side changes (skipped snapshot).\n";
        break;
    }

    $cid = fs_record_snapshot($db, $bid, $msg);
    $last = fs_last_commit($db, $bid);
    echo "branchfs: snapshot #$cid committed on '$name': $msg\n";
    echo "branchfs: commit " . substr($last['commit_hash'] ?? '', 0, 12) . "\n";
    break;
}

case 'delete': {
    $name = $pos[1] ?? die_usage("`delete` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    if ($name === 'main') die_usage("'main' cannot be deleted");

    branchfs_set_db($DB_PATH);
    $db = sqlite_open($DB_PATH);
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    // Finalize the result and statement before DDL — an open read cursor keeps
    // a read transaction alive, which blocks DROP TABLE ("database table is locked").
    $r->finalize();
    $s->close();
    if (!$row) {
        echo "branchfs: no branch named '$name'\n";
        break;
    }

    $bid = (int)$row[0];

    /* Collect candidate blob hashes BEFORE we drop the rows that reference
     * them. Only hashes referenced by the deleted branch's rows are
     * candidates — this bounds inline-GC work to O(deleted-branch blobs),
     * not O(whole blob store). */
    $candidates = [];
    $cr = $db->query(
        "SELECT DISTINCT blob_hash FROM files "
      . "WHERE branch_id = $bid AND blob_hash IS NOT NULL"
    );
    while ($crow = $cr->fetchArray(SQLITE3_NUM)) {
        $candidates[$crow[0]] = true;
    }
    $cr->finalize();
    $cr2 = $db->query(
        "SELECT DISTINCT fcf.blob_hash FROM fs_commit_files fcf "
      . "JOIN fs_commits fc ON fc.id = fcf.commit_id "
      . "WHERE fc.branch_id = $bid AND fcf.blob_hash IS NOT NULL"
    );
    while ($crow = $cr2->fetchArray(SQLITE3_NUM)) {
        $candidates[$crow[0]] = true;
    }
    $cr2->finalize();
    $candidate_hashes = array_keys($candidates);

    // Collect DROP targets BEFORE the transaction: DROP cannot run while an
    // iterator over sqlite_master is open, and SQLite's DDL rules forbid
    // schema changes mid-transaction on some builds. Both real tables (legacy
    // copy-format branches) AND views/overlays/tombstones (COW branches)
    // need to be dropped.
    $prefix = "b{$bid}_wp_";
    $ts = $db->prepare(
        "SELECT name, type FROM sqlite_master "
      . "WHERE name LIKE :p AND type IN ('table', 'view')"
    );
    $ts->bindValue(':p', $prefix . '%', SQLITE3_TEXT);
    $tr = $ts->execute();
    $views_to_drop  = [];
    $tables_to_drop = [];
    while ($trow = $tr->fetchArray(SQLITE3_ASSOC)) {
        if ($trow['type'] === 'view') {
            $views_to_drop[] = $trow['name'];
        } else {
            $tables_to_drop[] = $trow['name'];
        }
    }
    $tr->finalize();
    $ts->close();

    $reclaimed_n = 0;
    $reclaimed_bytes = 0;
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Views first so triggers go with them; then tables (overlays + tombstones).
        // Triggers ON the views are dropped automatically by DROP VIEW.
        foreach ($views_to_drop as $vname) {
            $db->exec("DROP VIEW IF EXISTS \"$vname\"");
        }
        foreach ($tables_to_drop as $tname) {
            $db->exec("DROP TABLE IF EXISTS \"$tname\"");
        }
        // sqlite_sequence cleanup for the dropped overlays.
        @$db->exec("DELETE FROM sqlite_sequence WHERE name LIKE '"
                 . SQLite3::escapeString($prefix) . "%'");
        // Drop COW marker rows so subsequent helpers don't touch a phantom branch.
        $db->exec("DELETE FROM db_cow_branches WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_snapshots_schema WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_ancestor_overlay WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_post_fork_inserts WHERE branch_id = $bid");
        /* fs_commit_files rows are keyed by commit_id, so they must go
         * before (or together with) the fs_commits rows to avoid orphan
         * rows after delete. Previously, the delete path forgot fs_commits
         * entirely — leaving stale snapshot rows that kept blobs reachable. */
        $db->exec(
            "DELETE FROM fs_commit_files "
          . "WHERE commit_id IN (SELECT id FROM fs_commits WHERE branch_id = $bid)"
        );
        $db->exec("DELETE FROM fs_commits  WHERE branch_id = $bid");
        $db->exec("DELETE FROM files        WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_snapshots WHERE branch_id = $bid");
        $db->exec("DELETE FROM branches     WHERE id        = $bid");

        /* Inline GC on the candidate hashes only. fs_gc is transaction-aware:
         * it reuses our BEGIN IMMEDIATE rather than nesting. */
        if (!empty($candidate_hashes)) {
            [$reclaimed_n, $reclaimed_bytes] = fs_gc($db, $candidate_hashes);
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: delete failed: " . $e->getMessage() . "\n");
        exit(5);
    }

    echo "branchfs: deleted branch '$name'\n";
    if ($reclaimed_n > 0) {
        printf("branchfs: reclaimed %d orphaned blob(s), %d bytes\n",
            $reclaimed_n, $reclaimed_bytes);
    }
    break;
}

case 'show': {
    $name = $pos[1] ?? die_usage("`show` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");

    $db = sqlite_open($DB_PATH);
    $s = $db->prepare("SELECT id, name, parent_branch, created_at FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo "branchfs: no overlay named '$name'\n";
    } else {
        $n = branchfs_file_count($db, (int)$row['id']);
        echo "branchfs: $name\n";
        echo "  parent : " . ($row['parent_branch'] ?? '(root)') . "\n";
        echo "  files  : $n\n";
        echo "  created: " . ($row['created_at'] ?? '') . "\n";
        $last = fs_last_commit($db, (int)$row['id']);
        if ($last) {
            echo "  last commit: " . substr($last['commit_hash'] ?? '', 0, 12)
               . " — " . ($last['message'] ?? '') . "\n";
        }
    }
    break;
}

case 'log': {
    $name = $pos[1] ?? die_usage("`log` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");
    $limit = (int)($flags['n'] ?? $flags['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 500) $limit = 500;

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        echo "branchfs: no branch named '$name'\n";
        break;
    }

    $s = $db->prepare(
        "SELECT id, commit_hash, message, created_at FROM fs_commits "
      . "WHERE branch_id = :b ORDER BY id DESC LIMIT :lim"
    );
    $s->bindValue(':b', $bid, SQLITE3_INTEGER);
    $s->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $r = $s->execute();
    $rows = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    printf("%-34s  %-19s  %s\n", "COMMIT", "WHEN", "MESSAGE");
    printf("%s\n", str_repeat('-', 80));
    foreach ($rows as $row) {
        printf("%-34s  %-19s  %s\n",
            $row['commit_hash'] ?? '',
            substr($row['created_at'] ?? '', 0, 19),
            trim($row['message'] ?? '')
        );
    }
    if (empty($rows)) {
        echo "  (no commits on '$name' yet)\n";
    }
    break;
}

case 'diff': {
    $a = $pos[1] ?? die_usage("`diff` needs two branch names");
    $b = $pos[2] ?? die_usage("`diff` needs two branch names");
    if (!valid_branch_name($a) && $a !== 'main') die_usage("invalid branch: $a");
    if (!valid_branch_name($b) && $b !== 'main') die_usage("invalid branch: $b");

    $db = sqlite_open($DB_PATH);
    $aid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($a) . "'");
    $bid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($b) . "'");

    echo "branchfs: overlay diff '$a' vs '$b'\n";

    $tree_a = $aid > 0 ? fs_resolve_tree($db, $aid) : [];
    $tree_b = $bid > 0 ? fs_resolve_tree($db, $bid) : [];

    $all_paths = array_unique(array_merge(array_keys($tree_a), array_keys($tree_b)));
    sort($all_paths);

    $diffs = [];
    foreach ($all_paths as $path) {
        $ha = $tree_a[$path]['blob_hash'] ?? null;
        $hb = $tree_b[$path]['blob_hash'] ?? null;
        if ($ha !== $hb) {
            $diffs[] = ['path' => $path, 'a' => $ha, 'b' => $hb];
        }
    }

    if (empty($diffs)) {
        printf("  (no file differences between '$a' and '$b')\n");
    } else {
        printf("\n%-4s  %s\n", "OP", "PATH");
        printf("%s\n", str_repeat('-', 60));
        foreach ($diffs as $d) {
            if ($d['a'] === null) $op = '+';
            elseif ($d['b'] === null) $op = '-';
            else $op = 'M';
            printf("%-4s  %s\n", $op, $d['path']);
        }
        printf("\n  %d file(s) differ\n", count($diffs));
    }
    break;
}

case 'merge': {
    $from = $pos[1] ?? die_usage("`merge` needs a source branch");
    $into = $flags['into'] ?? null;
    if (!$into) die_usage("`merge` needs --into <target>");
    if (!valid_branch_name($from)) die_usage("invalid source: $from");
    if (!valid_branch_name($into) && $into !== 'main') die_usage("invalid target: $into");

    // Lazy COW migration: if either branch is in the legacy (full-copy)
    // format, migrate it to view+overlay+tombstone IN PLACE before merge
    // runs. After this, every non-main branch's b{id}_wp_* objects are
    // views, and merge.php's row diff sees the same data through the view
    // layer it would have seen against the real table.
    {
        $db_pre = sqlite_open($DB_PATH);
        $migrated_total = 0;
        foreach ([$from, $into] as $bn) {
            if ($bn === 'main') continue;
            $bid = fs_branch_id($db_pre, $bn);
            if ($bid <= 0) continue;
            // Quick check: any real (non-overlay/tombstone) table under this
            // prefix means legacy format.
            $has_legacy = (int)$db_pre->querySingle(
                "SELECT COUNT(*) FROM sqlite_master "
              . "WHERE type='table' "
              . "  AND name LIKE 'b{$bid}_wp_%' "
              . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
              . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
            );
            if ($has_legacy === 0) continue;
            $db_pre->exec('BEGIN IMMEDIATE');
            try {
                $n = cow_migrate_legacy_branch($db_pre, $bid);
                $db_pre->exec('COMMIT');
                $migrated_total += $n;
                if ($n > 0) {
                    echo "branchctl: lazy-migrated $n legacy table(s) on '$bn' to COW format\n";
                }
            } catch (\Throwable $e) {
                $db_pre->exec('ROLLBACK');
                fwrite(STDERR, "branchctl: lazy COW migration on '$bn' failed: "
                             . $e->getMessage() . "\n");
                exit(5);
            }
        }
        $db_pre->close();
    }

    $merge_script = __DIR__ . '/merge.php';
    if (!file_exists($merge_script)) {
        fwrite(STDERR, "branchctl: scripts/merge.php not found next to branchctl.php\n");
        exit(5);
    }
    echo "branchctl: invoking scripts/merge.php (3-way file + DB merge)...\n";

    $argv_forward = [
        escapeshellarg($from),
        escapeshellarg($into),
        escapeshellarg($DB_PATH),
    ];
    if (isset($flags['strategy'])) {
        $strat = (string)$flags['strategy'];
        if (!in_array($strat, ['abort', 'ours', 'theirs'], true)) {
            die_usage("invalid --strategy '$strat' (must be abort|ours|theirs)");
        }
        $argv_forward[] = '--strategy=' . $strat;
    }
    if (isset($flags['on-id-collision'])) {
        $oic = (string)$flags['on-id-collision'];
        if (!in_array($oic, ['conflict', 'renumber'], true)) {
            die_usage("invalid --on-id-collision '$oic' (must be conflict|renumber)");
        }
        $argv_forward[] = '--on-id-collision=' . $oic;
    }
    $so = realpath(__DIR__ . '/../ext/branchfs.so');
    $php_bin = PHP_BINARY;
    $ext_flag = $so ? '-d extension=' . escapeshellarg($so) : '';
    $cmdline = sprintf(
        '%s %s -d display_errors=Off -d display_startup_errors=Off %s %s',
        escapeshellarg($php_bin),
        $ext_flag,
        escapeshellarg($merge_script),
        implode(' ', $argv_forward)
    );
    passthru($cmdline, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "branchctl: merge exited with status $rc\n");
        exit($rc);
    }
    break;
}

case 'reset': {
    $name   = $pos[1] ?? die_usage("`reset` needs a branch name");
    $commit = $pos[2] ?? die_usage("`reset` needs a commit hash");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $commit)) {
        die_usage("invalid commit hash: $commit (use `branchctl log $name` to list hashes)");
    }
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    if (!$force) {
        $current_snap = fs_current_commit($db, $bid);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the last snapshot.\n"
                  . "           Reset would discard them. Run `branchctl commit $name` first,\n"
                  . "           or pass --force to discard.\n");
                exit(6);
            }
        }
    }

    $fs = fs_find_commit_by_hash($db, $bid, $commit);
    if (!$fs) {
        fwrite(STDERR, "branchctl: no snapshot found for commit '$commit' on branch '$name'.\n");
        fwrite(STDERR, "           Use `branchctl log $name` to see available commits.\n");
        exit(7);
    }
    $n = fs_restore_snapshot($db, $bid, (int)$fs['id']);
    echo "branchfs: '$name' reset to commit " . substr($commit, 0, 12) . "\n";
    echo "branchfs: restored $n files from snapshot #{$fs['id']} ({$fs['message']})\n";
    break;
}

case 'rollback': {
    $name = $pos[1] ?? die_usage("`rollback` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    if (!$force) {
        $current_snap = fs_current_commit($db, $bid);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the last snapshot.\n"
                  . "           Rollback would discard them. Run `branchctl commit $name`\n"
                  . "           first, or pass --force to discard.\n");
                exit(6);
            }
        }
    }

    $s = $db->prepare(
        "SELECT id, commit_hash, message, created_at FROM fs_commits "
      . "WHERE branch_id = :b ORDER BY id DESC LIMIT 2"
    );
    $s->bindValue(':b', $bid, SQLITE3_INTEGER);
    $r = $s->execute();
    $r->fetchArray(SQLITE3_ASSOC); // skip current (HEAD)
    $prev = $r->fetchArray(SQLITE3_ASSOC);
    if (!$prev) {
        fwrite(STDERR, "branchctl: no previous commit to roll back to on '$name'.\n");
        exit(7);
    }
    $n = fs_restore_snapshot($db, $bid, (int)$prev['id']);
    echo "branchfs: '$name' rolled back to " . substr($prev['commit_hash'] ?? '', 0, 12) . "\n";
    echo "branchfs: restored $n files from snapshot #{$prev['id']} ({$prev['message']})\n";
    break;
}

case 'gc': {
    /* Full-store orphan sweep. Same semantics as before the fs_gc refactor:
     * a hash is orphaned when no row in `files` or `fs_commit_files`
     * references it. --dry-run reports without deleting. */
    $dry = !empty($flags['dry-run']);

    $db = sqlite_open($DB_PATH);

    $total_before = (int)$db->querySingle("SELECT COUNT(*) FROM blobs");
    $bytes_before = (int)$db->querySingle("SELECT COALESCE(SUM(size), 0) FROM blobs");

    if ($dry) {
        /* Dry-run shares fs_gc's orphan-detection query but skips the DELETE. */
        $to_delete = [];
        $bytes_free = 0;
        $r = $db->query(
            "SELECT hash, size FROM blobs "
          . "WHERE hash NOT IN (SELECT blob_hash FROM files WHERE blob_hash IS NOT NULL) "
          . "  AND hash NOT IN (SELECT blob_hash FROM fs_commit_files WHERE blob_hash IS NOT NULL)"
        );
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $to_delete[] = $row['hash'];
            $bytes_free += (int)$row['size'];
        }
        printf("branchctl gc (--dry-run): would delete %d blobs, freeing %d bytes\n",
            count($to_delete), $bytes_free);
        printf("  total blobs: %d (%d bytes) -> %d bytes after gc\n",
            $total_before, $bytes_before, $bytes_before - $bytes_free);
        break;
    }

    try {
        [$n, $bytes_free] = fs_gc($db);
    } catch (\Throwable $e) {
        fwrite(STDERR, "branchctl: gc failed: " . $e->getMessage() . "\n");
        exit(4);
    }

    if ($n === 0) {
        printf("branchctl gc: nothing to reclaim (%d blobs, %d bytes)\n",
            $total_before, $bytes_before);
        break;
    }

    printf("branchctl gc: deleted %d blobs, reclaimed %d bytes (before: %d blobs / %d bytes)\n",
        $n, $bytes_free, $total_before, $bytes_before);
    break;
}

case 'alter-add-column': {
    // alter-add-column <branch> <table_suffix> <col_name> <col_type>
    //
    // Wraps ALTER TABLE … ADD COLUMN on the branch's underlying physical
    // table (or main's real table) AND triggers cow_recreate_views_for_table
    // so descendant branches' views expose the new column. Used by tests
    // and by tooling that ALTERs WP schemas (plugin upgrades).
    $bname    = $pos[1] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $suffix   = $pos[2] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $col_name = $pos[3] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $col_type = $pos[4] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    if ($bname !== 'main' && !valid_branch_name($bname)) die_usage("invalid branch: $bname");
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col_name)) die_usage("invalid col name");
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_ \(\),]*$/', $col_type)) die_usage("invalid col type");
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $suffix)) die_usage("invalid suffix");

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $bname);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$bname'\n");
        exit(4);
    }
    $logical = "b{$bid}_wp_{$suffix}";
    // Determine the real table to ALTER. For main this is the logical name
    // itself; for a COW branch we ALTER the overlay (not the view).
    $physical = $logical;
    if (cow_is_view($db, $logical)) {
        $physical = $logical . '__overlay';
    }
    if (!cow_is_table($db, $physical)) {
        fwrite(STDERR, "branchctl: no underlying table '$physical' to alter\n");
        exit(5);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec('ALTER TABLE "' . SQLite3::escapeString($physical) . '" '
                . 'ADD COLUMN "' . $col_name . '" ' . $col_type);
        // Invalidate descendant branches' views — SELECT * was resolved at
        // the original CREATE VIEW time, so the new column would otherwise
        // be invisible.
        cow_recreate_views_for_table($db, $suffix);
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: alter-add-column failed: " . $e->getMessage() . "\n");
        exit(5);
    }
    echo "branchctl: added column '$col_name' $col_type to b{$bid}_wp_{$suffix} "
       . "(updated dependent branch views)\n";
    break;
}

default:
    die_usage("unknown command: $cmd");
}
exit(0);
?>
__END__
