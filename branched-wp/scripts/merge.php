<?php
/**
 * BranchFS Merge — file-side 3-way merge (SQLite overlay only).
 *
 * File-side does a true 3-way merge at the path level:
 *
 *   base (common ancestor) vs source vs target
 *
 *   base==target, source!=target   -> apply source (fast-forward on this path)
 *   base==source, target!=source   -> keep target (target already diverged)
 *   source==target                 -> no-op
 *   all three differ               -> conflict; honor --strategy
 *
 * --strategy=abort  (default) -- refuse merge if any conflict, exit 1
 * --strategy=ours             -- target wins on conflict
 * --strategy=theirs           -- source wins on conflict
 *
 * Note: database merge is not implemented (Dolt has been removed).
 *
 * Usage: php merge.php {source-branch} {target-branch} [db-path] [--strategy=...]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php merge.php {source-branch} {target-branch} [db-path] [--strategy=abort|ours|theirs]\n");
    exit(1);
}

$source  = $argv[1];
$target  = $argv[2];
$db_path = $argv[3] ?? __DIR__ . '/../branchfs.db';
$strategy = 'abort';

for ($i = 3; $i < $argc; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--strategy=') === 0) {
        $strategy = substr($a, strlen('--strategy='));
    } elseif ($a === '--strategy' && isset($argv[$i + 1])) {
        $strategy = $argv[++$i];
    }
}

if (!in_array($strategy, ['abort', 'ours', 'theirs'], true)) {
    fwrite(STDERR, "merge: invalid --strategy '$strategy' (must be abort|ours|theirs)\n");
    exit(1);
}

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "ERROR: branchfs extension not loaded\n");
    exit(1);
}

require_once __DIR__ . '/opcache.php';

branchfs_set_db($db_path);

echo "=== BranchFS Merge ===\n";
echo "Source:    $source\n";
echo "Target:    $target\n";
echo "Strategy:  $strategy\n\n";

$db = new SQLite3($db_path);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');

function merge_branch_id(SQLite3 $db, string $name): int {
    return (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($name) . "'");
}

/** Resolve a branch's full tree by walking parent_branch (COW inheritance). */
function merge_resolve_tree(SQLite3 $db, int $branch_id): array {
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

/**
 * Load the fork-time snapshot for a branch — the first fs_commit recorded
 * on the branch. This is the authoritative "base" for 3-way merge: it
 * captures the branch's starting state before any divergence on either
 * the source or the parent (since the parent may have moved on too).
 */
function merge_load_fork_base(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare("SELECT id FROM fs_commits WHERE branch_id = :b ORDER BY id ASC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $tree = [];
    $r2 = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM fs_commit_files WHERE commit_id = {$row['id']}");
    while ($rr = $r2->fetchArray(SQLITE3_ASSOC)) $tree[$rr['path']] = $rr;
    return $tree;
}

/**
 * Return the SOURCE branch's fork-time parent branch name if we can find
 * a common ancestor via parent_branch chains. Used only for logging.
 */
function merge_find_ancestor(SQLite3 $db, string $src, string $tgt): string {
    $chain = function(string $b) use ($db): array {
        $seen = [$b];
        while (true) {
            $s = $db->prepare("SELECT parent_branch FROM branches WHERE name = :n");
            $s->bindValue(':n', end($seen), SQLITE3_TEXT);
            $r = $s->execute();
            $row = $r->fetchArray(SQLITE3_ASSOC);
            if (!$row || !$row['parent_branch']) break;
            $seen[] = $row['parent_branch'];
        }
        return $seen;
    };
    $sc = $chain($src);
    $tc = $chain($tgt);
    $tcset = array_flip($tc);
    foreach ($sc as $c) {
        if (isset($tcset[$c])) return $c;
    }
    return 'main';
}

// ── DB merge helpers ──────────────────────────────────────────────────────────

/** Get ordered PRIMARY KEY column names for a table (empty if no explicit PK). */
function db_pk_cols(SQLite3 $db, string $table): array {
    $pk = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) $pk[(int)$row['pk']] = $row['name'];
    }
    ksort($pk);
    return array_values($pk);
}

/** Read all rows from a table; returns [pk_json => row_json]. */
function db_table_rows(SQLite3 $db, string $table, array $pk_cols): array {
    $rows = [];
    $r = $db->query('SELECT * FROM "' . SQLite3::escapeString($table) . '"');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (empty($pk_cols)) {
            $pk_map = $row; // use whole row as key when no explicit PK
        } else {
            $pk_map = [];
            foreach ($pk_cols as $col) $pk_map[$col] = $row[$col] ?? null;
        }
        $rows[json_encode($pk_map, JSON_UNESCAPED_UNICODE)] = json_encode($row, JSON_UNESCAPED_UNICODE);
    }
    return $rows;
}

/** Read ancestor rows from db_snapshots for a given (branch_id, table_name). */
function db_ancestor_rows(SQLite3 $db, int $branch_id, string $table_name): array {
    $rows = [];
    $s = $db->prepare('SELECT row_pk, row_json FROM db_snapshots WHERE branch_id=:b AND table_name=:t');
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':t', $table_name, SQLITE3_TEXT);
    $r = $s->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[$row['row_pk']] = $row['row_json'];
    return $rows;
}

/** INSERT OR REPLACE a row into a table from its JSON representation. */
function db_upsert(SQLite3 $db, string $table, string $row_json): void {
    $row  = json_decode($row_json, true);
    $cols = array_keys($row);
    $quoted = array_map(fn($c) => '"' . $c . '"', $cols);
    $params  = array_map(fn($c) => ':' . $c, $cols);
    $sql  = 'INSERT OR REPLACE INTO "' . SQLite3::escapeString($table) . '" ('
          . implode(', ', $quoted) . ') VALUES (' . implode(', ', $params) . ')';
    $stmt = $db->prepare($sql);
    foreach ($row as $col => $val) {
        $type = match (true) {
            $val === null  => SQLITE3_NULL,
            is_int($val)   => SQLITE3_INTEGER,
            is_float($val) => SQLITE3_FLOAT,
            default        => SQLITE3_TEXT,
        };
        $stmt->bindValue(':' . $col, $val, $type);
    }
    $stmt->execute();
}

/** DELETE a row identified by its JSON-encoded PK. */
function db_delete_by_pk(SQLite3 $db, string $table, string $pk_json, array $pk_cols): void {
    $pk    = json_decode($pk_json, true);
    $conds = array_map(fn($c) => '"' . $c . '" = :' . $c, $pk_cols);
    $sql   = 'DELETE FROM "' . SQLite3::escapeString($table) . '" WHERE ' . implode(' AND ', $conds);
    $stmt  = $db->prepare($sql);
    foreach ($pk_cols as $col) {
        $val  = $pk[$col] ?? null;
        $type = match (true) {
            $val === null  => SQLITE3_NULL,
            is_int($val)   => SQLITE3_INTEGER,
            is_float($val) => SQLITE3_FLOAT,
            default        => SQLITE3_TEXT,
        };
        $stmt->bindValue(':' . $col, $val, $type);
    }
    $stmt->execute();
}

/** Copy DDL + rows from $src_table to $tgt_table (renaming table/index names). */
function db_copy_table(SQLite3 $db, string $src_table, string $tgt_table,
                       string $src_prefix, string $tgt_prefix): void {
    $old_ddl = $db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($src_table) . "'"
    );
    if ($old_ddl) {
        $new_ddl = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($src_table, '/') . '"?(\s*\()/is',
            '$1IF NOT EXISTS "' . $tgt_table . '"$2',
            $old_ddl, 1
        );
        $db->exec($new_ddl ?: "CREATE TABLE IF NOT EXISTS \"$tgt_table\" AS SELECT * FROM \"$src_table\"");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS \"$tgt_table\" AS SELECT * FROM \"$src_table\"");
    }
    $db->exec("INSERT INTO \"$tgt_table\" SELECT * FROM \"$src_table\"");

    // Recreate named indexes with renamed table/index references.
    $idx_stmt = $db->prepare("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=:t AND sql IS NOT NULL");
    $idx_stmt->bindValue(':t', $src_table, SQLITE3_TEXT);
    $idx_res  = $idx_stmt->execute();
    $idx_rows = [];
    while ($irow = $idx_res->fetchArray(SQLITE3_ASSOC)) $idx_rows[] = $irow;
    foreach ($idx_rows as $irow) {
        $old_idx = $irow['name'];
        $new_idx = str_replace($src_prefix, $tgt_prefix, $old_idx);
        $idx_sql = preg_replace(
            '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?' . preg_quote($old_idx, '/') . '"?/i',
            '$1IF NOT EXISTS "' . $new_idx . '"',
            $irow['sql'], 1
        );
        $idx_sql = preg_replace(
            '/\bON\s+"?' . preg_quote($src_table, '/') . '"?\s*\(/i',
            'ON "' . $tgt_table . '" (',
            $idx_sql, 1
        );
        @$db->exec($idx_sql);
    }
}

$src_id = merge_branch_id($db, $source);
$tgt_id = merge_branch_id($db, $target);
if (!$src_id) { fwrite(STDERR, "ERROR: source branch '$source' not found\n"); exit(1); }
if (!$tgt_id) { fwrite(STDERR, "ERROR: target branch '$target' not found\n"); exit(1); }

$base = merge_find_ancestor($db, $source, $target);
echo "Phase 1: file 3-way merge (fork parent: $base) ...\n";

$src_tree = merge_resolve_tree($db, $src_id);
$tgt_tree = merge_resolve_tree($db, $tgt_id);

// Base = the fork-time snapshot of the SOURCE branch. Fallback to the
// parent branch's current state only if no snapshot exists (dev / test
// shortcut when branchctl create wasn't used). Using TARGET's current
// state as base would silently turn every target edit since fork into
// "pre-existing base", masking real conflicts.
$base_tree = merge_load_fork_base($db, $src_id);
$base_source = 'source fs_commit';
if ($base_tree === null) {
    $parent_id = (int)$db->querySingle(
        "SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name "
      . "WHERE b1.id = $src_id"
    );
    $base_tree = $parent_id ? merge_resolve_tree($db, $parent_id) : [];
    $base_source = 'parent-branch current state (no fs_commit on source)';
}
echo "  base tree: $base_source (" . count($base_tree) . " paths)\n";

$to_apply  = [];
$keep      = 0;
$noop      = 0;
$conflicts = [];

$all_paths = array_unique(array_merge(
    array_keys($src_tree), array_keys($tgt_tree), array_keys($base_tree)
));

foreach ($all_paths as $p) {
    $b = $base_tree[$p] ?? null;
    $s = $src_tree[$p]  ?? null;
    $t = $tgt_tree[$p]  ?? null;

    $bh = $b ? ($b['blob_hash'] ?? '') : '';
    $sh = $s ? ($s['blob_hash'] ?? '') : '';
    $th = $t ? ($t['blob_hash'] ?? '') : '';

    if ($sh === $th) { $noop++; continue; }
    if ($bh === $th && $sh !== $th) { $to_apply[$p] = $s; continue; }   // target unchanged, source changed
    if ($bh === $sh && $th !== $sh) { $keep++; continue; }              // source unchanged, target changed
    $conflicts[] = $p;                                                   // both diverged
}

echo "  paths considered:  " . count($all_paths) . "\n";
echo "  no-op (same):      $noop\n";
echo "  apply from source: " . count($to_apply) . "\n";
echo "  keep target:       $keep\n";
echo "  conflicts:         " . count($conflicts) . "\n";

if ($conflicts) {
    if ($strategy === 'abort') {
        echo "\nCONFLICTS (merge aborted; pass --strategy=ours or --strategy=theirs to override):\n";
        foreach (array_slice($conflicts, 0, 20) as $cpath) {
            echo "  - $cpath\n";
        }
        if (count($conflicts) > 20) {
            echo "  ... and " . (count($conflicts) - 20) . " more\n";
        }
        exit(2);
    } elseif ($strategy === 'theirs') {
        foreach ($conflicts as $p) $to_apply[$p] = $src_tree[$p] ?? null;
        echo "  resolving conflicts with 'theirs' (source wins on " . count($conflicts) . " paths)\n";
    } elseif ($strategy === 'ours') {
        // Target wins — nothing to apply from source for conflicts.
        echo "  resolving conflicts with 'ours' (target kept on " . count($conflicts) . " paths)\n";
    }
}

$db->exec('BEGIN IMMEDIATE');
try {
    $applied = 0;
    $opcache_paths = [];
    foreach ($to_apply as $path => $entry) {
        if ($entry === null) {
            // Deletion from source (tombstone or missing): write a tombstone
            // on target.
            $ins = $db->prepare(
                "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
              . "VALUES (:b, :p, NULL, 0, :t, 0)"
            );
            $ins->bindValue(':b', $tgt_id, SQLITE3_INTEGER);
            $ins->bindValue(':p', $path, SQLITE3_TEXT);
            $ins->bindValue(':t', time(), SQLITE3_INTEGER);
            $ins->execute();
            $opcache_paths[] = $path;
            $applied++;
            continue;
        }
        $ins = $db->prepare(
            "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :h, :m, :mt, :d)"
        );
        $ins->bindValue(':b',  $tgt_id, SQLITE3_INTEGER);
        $ins->bindValue(':p',  $path, SQLITE3_TEXT);
        $ins->bindValue(':h',  $entry['blob_hash'] ?? null,
            $entry['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':m',  (int)($entry['mode']   ?? 0),   SQLITE3_INTEGER);
        $ins->bindValue(':mt', (int)($entry['mtime']  ?? 0),   SQLITE3_INTEGER);
        $ins->bindValue(':d',  (int)($entry['is_dir'] ?? 0),   SQLITE3_INTEGER);
        $ins->execute();
        if (empty($entry['is_dir'])) $opcache_paths[] = $path;
        $applied++;
    }
    // Queue OPcache invalidations for every .php path written on target.
    // Non-.php paths are filtered out inside opcache_queue_invalidate.
    foreach ($opcache_paths as $p) {
        opcache_queue_invalidate($db, $target, $p);
    }
    $db->exec('COMMIT');
    echo "  applied: $applied rows\n";
} catch (\Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "merge: file-side failed: " . $e->getMessage() . "\n");
    exit(4);
}
// ── Phase 2: DB 3-way merge ───────────────────────────────────────────────────
echo "\nPhase 2: DB 3-way merge ...\n";

// Ensure db_snapshots exists (idempotent; handles DBs created before this schema).
$db->exec("CREATE TABLE IF NOT EXISTS db_snapshots (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_pk     TEXT NOT NULL,
    row_json   TEXT NOT NULL,
    PRIMARY KEY (branch_id, table_name, row_pk)
)");

$src_prefix = "b{$src_id}_wp_";
$tgt_prefix = "b{$tgt_id}_wp_";

// Collect table suffixes present in source, target, and ancestor snapshot.
$src_tables = [];
$r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
    . SQLite3::escapeString($src_prefix) . "%'");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $src_tables[substr($row[0], strlen($src_prefix))] = $row[0];
}

$tgt_tables = [];
$r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
    . SQLite3::escapeString($tgt_prefix) . "%'");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $tgt_tables[substr($row[0], strlen($tgt_prefix))] = $row[0];
}

$anc_tables = [];
$r = $db->query("SELECT DISTINCT table_name FROM db_snapshots WHERE branch_id = $src_id");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $anc_tables[substr($row[0], strlen($src_prefix))] = $row[0];
}

$all_suffixes = array_unique(array_merge(
    array_keys($src_tables), array_keys($tgt_tables), array_keys($anc_tables)
));

$db_clean_ops    = [];
$db_conflict_ops = [];
$db_noop         = 0;
$db_inserted     = 0;
$db_updated      = 0;
$db_deleted      = 0;

foreach ($all_suffixes as $suffix) {
    $src_tname = $src_tables[$suffix] ?? null;
    $tgt_tname = $tgt_tables[$suffix] ?? null;
    $anc_tname = $anc_tables[$suffix] ?? null;

    $in_src = $src_tname !== null;
    $in_tgt = $tgt_tname !== null;
    $in_anc = $anc_tname !== null;

    // Table only in target (target added it independently): no-op.
    if (!$in_src && !$in_anc) { $db_noop++; continue; }

    // New table on source, not in target and not in ancestor: copy to target.
    if ($in_src && !$in_tgt && !$in_anc) {
        $db_clean_ops[] = ['type' => 'new_table', 'src' => $src_tname,
                           'tgt' => $tgt_prefix . $suffix];
        continue;
    }

    // Source deleted the table (was in ancestor, no longer in source).
    if ($in_anc && !$in_src) {
        if (!$in_tgt) { $db_noop++; continue; } // both deleted → noop
        $anc_rows = db_ancestor_rows($db, $src_id, $anc_tname);
        $tgt_pk   = db_pk_cols($db, $tgt_tname);
        $tgt_rows = db_table_rows($db, $tgt_tname, $tgt_pk);
        if ($anc_rows === $tgt_rows) {
            $db_clean_ops[] = ['type' => 'drop_table', 'tgt' => $tgt_tname];
        } else {
            $db_conflict_ops[] = [
                'desc'      => "table $suffix (source deleted, target modified)",
                'ours_op'   => null,
                'theirs_op' => ['type' => 'drop_table', 'tgt' => $tgt_tname],
            ];
        }
        continue;
    }

    // Source has it, target deleted it, was in ancestor.
    if ($in_src && !$in_tgt && $in_anc) {
        $src_pk   = db_pk_cols($db, $src_tname);
        $src_rows = db_table_rows($db, $src_tname, $src_pk);
        $anc_rows = db_ancestor_rows($db, $src_id, $anc_tname);
        if ($src_rows === $anc_rows) {
            $db_noop++; // source unchanged; target's deletion wins
        } else {
            $db_conflict_ops[] = [
                'desc'      => "table $suffix (source modified, target deleted table)",
                'ours_op'   => null,
                'theirs_op' => ['type' => 'new_table', 'src' => $src_tname,
                                'tgt' => $tgt_prefix . $suffix],
            ];
        }
        continue;
    }

    // Row-level 3-way merge for tables present in both source and target.
    if ($in_src && $in_tgt) {
        $src_pk   = db_pk_cols($db, $src_tname);
        $tgt_pk   = db_pk_cols($db, $tgt_tname);
        $src_rows = db_table_rows($db, $src_tname, $src_pk);
        $tgt_rows = db_table_rows($db, $tgt_tname, $tgt_pk);
        $anc_rows = $in_anc ? db_ancestor_rows($db, $src_id, $anc_tname) : [];

        $all_pks = array_unique(array_merge(
            array_keys($src_rows), array_keys($tgt_rows), array_keys($anc_rows)
        ));

        foreach ($all_pks as $pk) {
            $a = $anc_rows[$pk] ?? null;
            $s = $src_rows[$pk] ?? null;
            $t = $tgt_rows[$pk] ?? null;

            if ($s === $t) { $db_noop++; continue; }

            if ($a === null) {
                if ($s !== null && $t === null) {
                    $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s];
                    $db_inserted++;
                } elseif ($s !== null && $t !== null) {
                    $db_conflict_ops[] = [
                        'desc'      => "$tgt_tname pk=$pk (both inserted different rows)",
                        'ours_op'   => null,
                        'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s],
                    ];
                }
                // $s===null && $t!==null: target added it, source doesn't have it → keep (noop)
                continue;
            }

            if ($s === null) {
                if ($t === $a) {
                    $db_clean_ops[] = ['type' => 'delete', 'table' => $tgt_tname,
                                       'pk' => $pk, 'pk_cols' => $tgt_pk];
                    $db_deleted++;
                } else {
                    $db_conflict_ops[] = [
                        'desc'      => "$tgt_tname pk=$pk (source deleted, target modified)",
                        'ours_op'   => null,
                        'theirs_op' => ['type' => 'delete', 'table' => $tgt_tname,
                                        'pk' => $pk, 'pk_cols' => $tgt_pk],
                    ];
                }
                continue;
            }

            // Both present (s != t), ancestor exists.
            if ($s === $a) {
                $db_noop++; // source unchanged, target changed → keep target
            } elseif ($t === $a) {
                $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s];
                $db_updated++;
            } else {
                $db_conflict_ops[] = [
                    'desc'      => "$tgt_tname pk=$pk (both modified)",
                    'ours_op'   => null,
                    'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s],
                ];
            }
        }
    }
}

echo "  tables considered: " . count($all_suffixes) . "\n";
echo "  row no-op:         $db_noop\n";
echo "  row inserts:       $db_inserted\n";
echo "  row updates:       $db_updated\n";
echo "  row deletes:       $db_deleted\n";
echo "  conflicts:         " . count($db_conflict_ops) . "\n";

if ($db_conflict_ops && $strategy === 'abort') {
    echo "\nDB CONFLICTS (merge aborted; pass --strategy=ours or --strategy=theirs to override):\n";
    foreach (array_slice($db_conflict_ops, 0, 20) as $c) {
        echo "  - " . $c['desc'] . "\n";
    }
    if (count($db_conflict_ops) > 20) {
        echo "  ... and " . (count($db_conflict_ops) - 20) . " more\n";
    }
    $db->close();
    exit(2);
}

// Resolve conflicts per strategy.
$final_db_ops = $db_clean_ops;
if ($strategy === 'theirs') {
    foreach ($db_conflict_ops as $c) {
        if ($c['theirs_op'] !== null) $final_db_ops[] = $c['theirs_op'];
    }
} elseif ($strategy === 'ours') {
    foreach ($db_conflict_ops as $c) {
        if ($c['ours_op'] !== null) $final_db_ops[] = $c['ours_op'];
    }
}

// Apply DB ops AND refresh the source branch's ancestor snapshot in a
// single transaction. Refreshing the snapshot is how iterative merges
// stay clean: without it, rows that the last merge already propagated
// would look like "both sides changed vs (stale) ancestor" on the next
// merge, producing spurious conflicts on every previously-merged row.
//
// The refresh captures source's current rows as the new common ancestor.
// Because source branches are never modified by merge itself, this is
// equivalent to "the state target adopted for rows where it took source,
// and source's own state for rows where target's value prevailed" —
// which correctly drives future 3-way diffs for both --strategy=theirs
// (source adopted) and --strategy=ours (target kept its value;
// re-merge will see source unchanged vs ancestor, target changed vs
// ancestor → keep target, no re-conflict).
$db->exec('BEGIN IMMEDIATE');
try {
    $db_applied = 0;
    foreach ($final_db_ops as $op) {
        switch ($op['type']) {
            case 'upsert':
                db_upsert($db, $op['table'], $op['row_json']);
                break;
            case 'delete':
                db_delete_by_pk($db, $op['table'], $op['pk'], $op['pk_cols']);
                break;
            case 'new_table':
                db_copy_table($db, $op['src'], $op['tgt'], $src_prefix, $tgt_prefix);
                break;
            case 'drop_table':
                $db->exec('DROP TABLE IF EXISTS "' . SQLite3::escapeString($op['tgt']) . '"');
                break;
        }
        $db_applied++;
    }

    // Refresh source branch's ancestor snapshot.
    $db->exec('DELETE FROM db_snapshots WHERE branch_id = ' . (int)$src_id);

    $snap_ins = $db->prepare(
        "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
      . "VALUES (:bid, :tname, :rpk, :rjson)"
    );

    $snap_tables = [];
    $tr = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
        . SQLite3::escapeString($src_prefix) . "%'");
    while ($row = $tr->fetchArray(SQLITE3_NUM)) {
        $snap_tables[] = $row[0];
    }

    $snap_total = 0;
    foreach ($snap_tables as $tname) {
        $pk_cols = db_pk_cols($db, $tname);
        $rows = $db->query('SELECT * FROM "' . SQLite3::escapeString($tname) . '"');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            if (empty($pk_cols)) {
                $pk_map = $row;
            } else {
                $pk_map = [];
                foreach ($pk_cols as $col) $pk_map[$col] = $row[$col] ?? null;
            }
            $snap_ins->bindValue(':bid',   $src_id, SQLITE3_INTEGER);
            $snap_ins->bindValue(':tname', $tname,  SQLITE3_TEXT);
            $snap_ins->bindValue(':rpk',   json_encode($pk_map, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
            $snap_ins->bindValue(':rjson', json_encode($row,    JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
            $snap_ins->execute();
            $snap_ins->reset();
            $snap_total++;
        }
    }

    $db->exec('COMMIT');
    echo "  DB ops applied: $db_applied\n";
    echo "  refreshed ancestor snapshot for '$source': $snap_total rows\n";
} catch (\Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "merge: DB phase failed: " . $e->getMessage() . "\n");
    $db->close();
    exit(4);
}

$db->close();
echo "\nMerge complete.\n";
