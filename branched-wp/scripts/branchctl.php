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
    $db->busyTimeout(5000);
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
SQL);
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
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DELETE FROM files WHERE branch_id = $branch_id");
        $ins = $db->prepare(
            "INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :bh, :md, :mt, :d)"
        );
        $count = 0;
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
            $count++;
        }
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

    // Copy parent's WordPress database tables to new branch
    $parent_id = fs_branch_id($db, $from);
    $new_id    = fs_branch_id($db, $name);
    if ($parent_id > 0 && $new_id > 0) {
        $prefix_old = "b{$parent_id}_wp_";
        $prefix_new = "b{$new_id}_wp_";

        // Collect table names first (iterating sqlite_master while modifying it
        // via CREATE TABLE is unreliable in SQLite).
        $tables_stmt = $db->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE :p"
        );
        $tables_stmt->bindValue(':p', $prefix_old . '%', SQLITE3_TEXT);
        $tables_result = $tables_stmt->execute();
        $tables_to_copy = [];
        while ($row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
            $tables_to_copy[] = $row['name'];
        }

        $copied = 0;
        foreach ($tables_to_copy as $old_table) {
            $new_table = $prefix_new . substr($old_table, strlen($prefix_old));

            // Use the original DDL so PRIMARY KEY, UNIQUE, NOT NULL and other
            // constraints are preserved. CREATE TABLE … AS SELECT strips them.
            $old_ddl = $db->querySingle(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                . SQLite3::escapeString($old_table) . "'"
            );
            if ($old_ddl) {
                $new_ddl = preg_replace(
                    '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?' . preg_quote($old_table, '/') . '"?(\s*\()/is',
                    '$1IF NOT EXISTS "' . $new_table . '"$2',
                    $old_ddl, 1
                );
                if ($new_ddl) {
                    $db->exec($new_ddl);
                    $db->exec("INSERT INTO \"$new_table\" SELECT * FROM \"$old_table\"");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS \"$new_table\" AS SELECT * FROM \"$old_table\"");
                }
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS \"$new_table\" AS SELECT * FROM \"$old_table\"");
            }

            // Recreate named indexes (inline UNIQUE in DDL is already preserved above;
            // this covers separately-created CREATE INDEX statements).
            $idx_stmt = $db->prepare(
                "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=:t AND sql IS NOT NULL"
            );
            $idx_stmt->bindValue(':t', $old_table, SQLITE3_TEXT);
            $idx_res   = $idx_stmt->execute();
            $idx_rows  = [];
            while ($irow = $idx_res->fetchArray(SQLITE3_ASSOC)) {
                $idx_rows[] = $irow;
            }
            foreach ($idx_rows as $irow) {
                $old_idx = $irow['name'];
                $new_idx = str_replace($prefix_old, $prefix_new, $old_idx);
                $idx_sql = preg_replace(
                    '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?' . preg_quote($old_idx, '/') . '"?/i',
                    '$1IF NOT EXISTS "' . $new_idx . '"',
                    $irow['sql'], 1
                );
                $idx_sql = preg_replace(
                    '/\bON\s+"?' . preg_quote($old_table, '/') . '"?\s*\(/i',
                    'ON "' . $new_table . '" (',
                    $idx_sql, 1
                );
                @$db->exec($idx_sql);
            }

            $copied++;
        }
        if ($copied > 0) {
            echo "branchfs: copied $copied WordPress DB tables (b{$parent_id} -> b{$new_id})\n";
        }

        // Snapshot the newly-copied rows so merge.php has a common ancestor for 3-way DB merge.
        $snap_total = 0;
        $snap_ins = $db->prepare(
            "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
          . "VALUES (:bid, :tname, :rpk, :rjson)"
        );
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($tables_to_copy as $old_table) {
                $new_table = $prefix_new . substr($old_table, strlen($prefix_old));

                $pk_cols = [];
                $pi = $db->query("PRAGMA table_info(\"" . SQLite3::escapeString($new_table) . "\")");
                while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
                    if ((int)$prow['pk'] > 0) $pk_cols[(int)$prow['pk']] = $prow['name'];
                }
                ksort($pk_cols);
                $pk_cols = array_values($pk_cols);

                $rows = $db->query("SELECT * FROM \"" . SQLite3::escapeString($new_table) . "\"");
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    if (empty($pk_cols)) {
                        $pk_map = ['_rowid_' => $row['rowid'] ?? null];
                    } else {
                        $pk_map = [];
                        foreach ($pk_cols as $col) $pk_map[$col] = $row[$col] ?? null;
                    }
                    $snap_ins->bindValue(':bid',   $new_id,    SQLITE3_INTEGER);
                    $snap_ins->bindValue(':tname', $new_table, SQLITE3_TEXT);
                    $snap_ins->bindValue(':rpk',   json_encode($pk_map,  JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                    $snap_ins->bindValue(':rjson', json_encode($row,     JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                    $snap_ins->execute();
                    $snap_ins->reset();
                    $snap_total++;
                }
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            echo "warning: DB ancestor snapshot failed: " . $e->getMessage() . "\n";
        }
        if ($snap_total > 0) {
            echo "branchfs: recorded $snap_total ancestor DB rows in db_snapshots\n";
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
    if ($row) {
        $bid = (int)$row[0];
        // Drop all WordPress DB tables for this branch.
        // Collect names first: DROPping modifies sqlite_master, which would
        // invalidate an open cursor and cause rows to be silently skipped.
        $prefix = "b{$bid}_wp_";
        $ts = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE :p");
        $ts->bindValue(':p', $prefix . '%', SQLITE3_TEXT);
        $tr = $ts->execute();
        $tables_to_drop = [];
        while ($trow = $tr->fetchArray(SQLITE3_ASSOC)) {
            $tables_to_drop[] = $trow['name'];
        }
        $tr->finalize();
        $ts->close();
        foreach ($tables_to_drop as $tname) {
            $db->exec("DROP TABLE IF EXISTS \"$tname\"");
        }
        $db->exec("DELETE FROM files    WHERE branch_id = $bid");
        $db->exec("DELETE FROM branches WHERE id        = $bid");
        echo "branchfs: deleted branch '$name'\n";
    } else {
        echo "branchfs: no branch named '$name'\n";
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
    /* Collect the set of blob hashes still referenced by either the live
     * per-branch `files` table or any historical fs_commit's snapshot.
     * Anything outside that set is unreachable and safe to delete. */
    $dry = !empty($flags['dry-run']);

    $db = sqlite_open($DB_PATH);

    $live = [];
    $r = $db->query("SELECT DISTINCT blob_hash FROM files WHERE blob_hash IS NOT NULL");
    while ($row = $r->fetchArray(SQLITE3_NUM)) $live[$row[0]] = true;
    $r2 = $db->query("SELECT DISTINCT blob_hash FROM fs_commit_files WHERE blob_hash IS NOT NULL");
    while ($row = $r2->fetchArray(SQLITE3_NUM)) $live[$row[0]] = true;

    $total_before = (int)$db->querySingle("SELECT COUNT(*) FROM blobs");
    $bytes_before = (int)$db->querySingle("SELECT COALESCE(SUM(size), 0) FROM blobs");

    $to_delete = [];
    $bytes_free = 0;
    $r3 = $db->query("SELECT hash, size FROM blobs");
    while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
        if (!isset($live[$row['hash']])) {
            $to_delete[] = $row['hash'];
            $bytes_free += (int)$row['size'];
        }
    }

    if ($dry) {
        printf("branchctl gc (--dry-run): would delete %d blobs, freeing %d bytes\n",
            count($to_delete), $bytes_free);
        printf("  total blobs: %d (%d bytes) -> %d bytes after gc\n",
            $total_before, $bytes_before, $bytes_before - $bytes_free);
        break;
    }

    if (empty($to_delete)) {
        printf("branchctl gc: nothing to reclaim (%d blobs, %d bytes)\n",
            $total_before, $bytes_before);
        break;
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $del = $db->prepare("DELETE FROM blobs WHERE hash = :h");
        foreach ($to_delete as $h) {
            $del->bindValue(':h', $h, SQLITE3_TEXT);
            $del->execute();
            $del->reset();
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: gc failed: " . $e->getMessage() . "\n");
        exit(4);
    }

    printf("branchctl gc: deleted %d blobs, reclaimed %d bytes (before: %d blobs / %d bytes)\n",
        count($to_delete), $bytes_free, $total_before, $bytes_before);
    break;
}

default:
    die_usage("unknown command: $cmd");
}
exit(0);
?>
__END__
